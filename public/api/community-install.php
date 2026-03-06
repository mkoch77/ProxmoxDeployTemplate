<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;
use App\SSH;

Bootstrap::init();
set_time_limit(360); // community script installs can take several minutes
Request::requireMethod('POST');
Request::validateCsrf();
Auth::requirePermission('community.install');

$body = Request::jsonBody();

// Validate script_path: must be a known community-scripts path like ct/foo.sh, vm/bar.sh, etc.
$scriptPath = $body['script_path'] ?? '';
if (!preg_match('#^(ct|vm|misc|addon|turnkey|pve)/[a-zA-Z0-9_\-]+\.sh$#', $scriptPath)) {
    Response::error('Invalid script path. Must be a valid community-scripts path (e.g. ct/myapp.sh)', 400);
}

// Validate node name
$nodeName = $body['node'] ?? '';
if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
    Response::error('Invalid or missing node name', 400);
}

// Resolve SSH host: prefer SSH_HOST_{NODE} env override, then cluster status IP
$envKey = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
$customHost = Config::get($envKey, '');

if ($customHost) {
    $sshHost = $customHost;
} else {
    // Look up node IP from Proxmox cluster status
    $sshHost = $nodeName; // fallback to node name
    try {
        $api = Helpers::createAPI();
        $clusterStatus = $api->getClusterStatus();
        foreach ($clusterStatus['data'] ?? [] as $entry) {
            if (($entry['type'] ?? '') === 'node' && strtolower($entry['name'] ?? '') === strtolower($nodeName)) {
                if (!empty($entry['ip'])) {
                    $sshHost = $entry['ip'];
                }
                break;
            }
        }
    } catch (\Exception $e) {
        // Ignore — fall back to node name
    }
}

// Download script to a temp file, then run it inside a PTY via `script -c`.
// Community scripts use whiptail/dialog which read from /dev/tty (not stdin).
// `script` allocates a real PTY so whiptail works, and we pipe printf newlines
// through it so every dialog auto-accepts its default selection.
$scriptUrl = 'https://github.com/community-scripts/ProxmoxVE/raw/main/' . $scriptPath;
$tmpFile   = '/tmp/cs_' . bin2hex(random_bytes(6)) . '.sh';

$installCmd =
    'wget -qLO ' . $tmpFile . ' ' . escapeshellarg($scriptUrl) . ' && ' .
    "yes '' | TERM=xterm DEBIAN_FRONTEND=noninteractive script -q -c 'bash " . $tmpFile . "' /dev/null; " .
    'rm -f ' . $tmpFile;

try {
    $result = SSH::execInstall($sshHost, $installCmd, 300);

    // Strip ANSI/VT100 escape sequences produced by whiptail running in the PTY
    $output = preg_replace(
        '/\x1B(?:[@-Z\\\\-_]|\[[0-9;]*[ -\/]*[@-~]|\][^\x07]*\x07)/u',
        '',
        $result['output'] ?? ''
    );
    $output = str_replace("\r", '', $output);

    Response::success([
        'output'    => $output,
        'exit_code' => $result['exit_code'],
        'success'   => $result['success'],
        'node'      => $nodeName,
        'script'    => $scriptPath,
    ]);
} catch (\Exception $e) {
    Response::error('SSH execution failed: ' . $e->getMessage(), 500);
}
