<?php

set_time_limit(0);
ignore_user_abort(true);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Config;
use App\SSH;
use phpseclib3\Exception\ConnectionClosedException;

Bootstrap::init();

$user = Auth::check();
if (!$user) { http_response_code(401); exit; }

$token = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
if (!$token) { http_response_code(400); exit; }

$dataFile = sys_get_temp_dir() . '/term_sess_' . $token . '.json';
if (!file_exists($dataFile)) { http_response_code(404); exit; }

$sess = json_decode(file_get_contents($dataFile), true);
unlink($dataFile);

if (!$sess || $sess['expires'] < time() || $sess['user_id'] !== $user['id']) {
    http_response_code(403); exit;
}

// Release the PHP session lock so that terminal-input.php (and any other
// concurrent requests) are not blocked by this long-running SSE stream.
session_write_close();

$sshHost       = $sess['ssh_host'];
$scriptPath    = $sess['script_path'] ?? '';
$directCommand = $sess['direct_command'] ?? '';
$port      = (int) Config::get('SSH_PORT', 22);
$sshUser   = Config::get('SSH_USER', 'root');
$keyPath   = Config::get('SSH_KEY_PATH', '');
$password  = Config::get('SSH_PASSWORD', '');
$inputFile = sys_get_temp_dir() . '/term_in_' . $token;

// SSE setup
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
while (ob_get_level()) ob_end_flush();
ob_implicit_flush(true);

function sse(string $event, string $data): void
{
    echo "event: {$event}\ndata: {$data}\n\n";
    flush();
}

function drainInput(string $inputFile): string
{
    if (!file_exists($inputFile)) return '';
    $fp = fopen($inputFile, 'c+');
    if (!$fp) return '';
    $out = '';
    if (flock($fp, LOCK_EX | LOCK_NB)) {
        rewind($fp);
        $raw = stream_get_contents($fp);
        ftruncate($fp, 0);
        flock($fp, LOCK_UN);
        foreach (explode("\n", trim($raw)) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $decoded = base64_decode($line, true);
                if ($decoded !== false) $out .= $decoded;
            }
        }
    }
    fclose($fp);
    return $out;
}

if ($directCommand) {
    $remoteCmd = $directCommand;
} else {
    $tmpScript = '/tmp/cs_' . bin2hex(random_bytes(6)) . '.sh';
    $scriptUrl = 'https://github.com/community-scripts/ProxmoxVE/raw/main/' . $scriptPath;
    $remoteCmd = 'export TERM=xterm COLUMNS=200 LINES=50 DEBIAN_FRONTEND=noninteractive; '
        . 'wget -qLO ' . $tmpScript . ' ' . escapeshellarg($scriptUrl)
        . ' && bash ' . $tmpScript
        . '; rm -f ' . $tmpScript;
}

$exitCode = -1;

// Emit an initial status line so we can verify the SSE stream is alive
$authMethod = ($keyPath && file_exists($keyPath)) ? 'key' : ($password ? 'password' : 'none');
sse('data', base64_encode("\r\nConnecting to {$sshHost} (auth: {$authMethod})...\r\n"));

if ($keyPath && file_exists($keyPath)) {
    // ── Path A: system ssh + proc_open with pipes (key auth) ───────────────
    // We use regular pipes (not pty) for proc_open.  SSH's -tt flag allocates
    // a remote PTY regardless of the local descriptor type, so whiptail on the
    // remote side still gets a proper TTY.  Using local pipes is simpler and
    // avoids the write-to-PTY-master reliability issues that plague Path A.
    $sshCmd = 'ssh'
        . ' -tt'
        . ' -o StrictHostKeyChecking=no'
        . ' -o UserKnownHostsFile=/dev/null'
        . ' -o LogLevel=ERROR'
        . ' -o BatchMode=yes'
        . ' -p ' . $port
        . ' -i ' . escapeshellarg($keyPath)
        . ' ' . escapeshellarg($sshUser . '@' . $sshHost)
        . ' ' . escapeshellarg($remoteCmd);

    $process = proc_open($sshCmd, [
        0 => ['pipe', 'r'],   // we write keyboard input here
        1 => ['pipe', 'w'],   // we read SSH output from here
        2 => ['pipe', 'w'],   // stderr (ignored but must be drained to avoid blocking)
    ], $pipes);

    if (!is_resource($process)) {
        sse('error', json_encode(['message' => 'Failed to start SSH process']));
        exit;
    }

    $stdin  = $pipes[0];
    $stdout = $pipes[1];
    $stderr = $pipes[2];
    stream_set_blocking($stdout, false);
    stream_set_blocking($stderr, false);
    stream_set_write_buffer($stdin, 0); // disable PHP write buffering → bytes reach SSH immediately

    while (true) {
        if (connection_aborted()) break;

        $read = [$stdout, $stderr]; $w = $e = null;
        $n = stream_select($read, $w, $e, 0, 100000); // 100 ms poll

        if ($n > 0) {
            foreach ($read as $r) {
                $data = fread($r, 4096);
                if ($data !== false && $data !== '') {
                    if ($r === $stdout) {
                        sse('data', base64_encode($data));
                    }
                    // stderr is silently discarded (SSH connection notices etc.)
                }
            }
        }

        if (feof($stdout)) {
            break; // SSH process closed stdout → done
        }

        // Forward queued keyboard input to SSH stdin
        $input = drainInput($inputFile);
        if ($input !== '') {
            fwrite($stdin, $input);
            fflush($stdin); // ensure bytes reach the OS pipe immediately
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            // Drain any remaining output
            usleep(50000);
            $read = [$stdout]; $w = $e = null;
            if (stream_select($read, $w, $e, 0, 100000) > 0) {
                $data = fread($stdout, 65536);
                if ($data !== false && $data !== '') sse('data', base64_encode($data));
            }
            $exitCode = $status['exitcode'] ?? -1;
            break;
        }
    }

    foreach ($pipes as $p) @fclose($p);
    $rc = proc_close($process);
    if ($exitCode === -1) $exitCode = $rc;

} elseif ($password) {
    // ── Path B: phpseclib3 PTY (password auth) ─────────────────────────────
    try {
        $ssh = SSH::openInteractiveSession($sshHost);
        $ssh->exec($remoteCmd);

        while (true) {
            if (connection_aborted()) break;
            try {
                $chunk = $ssh->read();
                if ($chunk !== '' && $chunk !== false) {
                    sse('data', base64_encode($chunk));
                }
            } catch (ConnectionClosedException $e) {
                if (!$ssh->isTimeout()) {
                    // Channel closed — drain any remaining buffered output
                    try {
                        $ssh->setTimeout(0.1);
                        $last = $ssh->read();
                        if ($last !== '' && $last !== false) {
                            sse('data', base64_encode($last));
                        }
                    } catch (\Exception $e2) {}
                    break;
                }
            }
            $input = drainInput($inputFile);
            if ($input !== '') {
                try { $ssh->write($input); } catch (\Exception $e) {
                    if (!$ssh->isConnected()) break;
                }
            }
        }
        $exitCode = $ssh->getExitStatus() ?? -1;
    } catch (\Exception $e) {
        sse('error', json_encode(['message' => $e->getMessage()]));
        @unlink($inputFile);
        exit;
    }

} else {
    sse('error', json_encode(['message' => 'No SSH credentials configured (SSH_KEY_PATH or SSH_PASSWORD).']));
    @unlink($inputFile);
    exit;
}

@unlink($inputFile);
sse('done', json_encode(['exit_code' => $exitCode, 'success' => $exitCode === 0]));
