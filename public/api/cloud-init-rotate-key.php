<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Database;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
$user = Auth::requireAuth();
\App\Config::requireSsh();

// ── GET: Preview which VMs have the user's current SSH key ───────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $db = Database::connection();
    $stmt = $db->prepare('SELECT ssh_public_keys FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $currentKeys = trim($stmt->fetchColumn() ?: '');

    if (!$currentKeys) {
        Response::success(['vms' => [], 'current_key' => '', 'message' => 'No SSH key found. Generate one first.']);
    }

    // Get the most recent key (last line)
    $keyLines = array_filter(array_map('trim', explode("\n", $currentKeys)));
    $activeKey = end($keyLines);

    $api = Helpers::createAPI();
    $resources = $api->getClusterResources('vm');
    $matchedVms = [];

    foreach ($resources['data'] ?? [] as $res) {
        if (($res['type'] ?? '') !== 'qemu') continue;
        $vmid = $res['vmid'] ?? 0;
        $node = $res['node'] ?? '';
        if (!$vmid || !$node) continue;

        try {
            $config = $api->getGuestConfig($node, 'qemu', $vmid);
            $sshkeys = $config['data']['sshkeys'] ?? '';
            if (!$sshkeys) continue;

            // Proxmox stores sshkeys URL-encoded
            $decoded = urldecode($sshkeys);
            if (strpos($decoded, $activeKey) !== false) {
                $matchedVms[] = [
                    'vmid' => $vmid,
                    'name' => $res['name'] ?? "VM {$vmid}",
                    'node' => $node,
                    'status' => $res['status'] ?? 'unknown',
                    'has_agent' => ($res['status'] ?? '') === 'running',
                ];
            }
        } catch (\Exception $e) {
            // Skip VMs we can't read config for
        }
    }

    Response::success([
        'vms' => $matchedVms,
        'current_key' => $activeKey,
        'vm_count' => count($matchedVms),
    ]);
}

// ── POST: Rotate the cloud-init SSH key ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Request::validateCsrf();

    $db = Database::connection();
    $stmt = $db->prepare('SELECT ssh_public_keys FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $currentKeys = trim($stmt->fetchColumn() ?: '');

    if (!$currentKeys) {
        Response::error('No SSH key found. Generate one first via the deploy form.', 400);
    }

    $keyLines = array_filter(array_map('trim', explode("\n", $currentKeys)));
    $oldKey = end($keyLines);

    // Generate new Ed25519 keypair
    $tmpDir = sys_get_temp_dir() . '/ci_rotate_' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0700, true);
    $keyFile = $tmpDir . '/id_ed25519';
    $comment = ($user['username'] ?? 'user') . '@proxmox-deploy';

    $cmd = sprintf(
        'ssh-keygen -t ed25519 -f %s -N "" -C %s -q 2>&1',
        escapeshellarg($keyFile),
        escapeshellarg($comment)
    );
    exec($cmd, $output, $exitCode);

    if ($exitCode !== 0 || !file_exists($keyFile) || !file_exists($keyFile . '.pub')) {
        array_map('unlink', glob($tmpDir . '/*'));
        @rmdir($tmpDir);
        Response::error('Failed to generate new key pair', 500);
    }

    $newPrivateKey = file_get_contents($keyFile);
    $newPublicKey = trim(file_get_contents($keyFile . '.pub'));
    unlink($keyFile);
    unlink($keyFile . '.pub');
    @rmdir($tmpDir);

    // Find VMs with the old key and rotate
    $api = Helpers::createAPI();
    $resources = $api->getClusterResources('vm');
    $results = [];
    $successCount = 0;
    $failCount = 0;

    foreach ($resources['data'] ?? [] as $res) {
        if (($res['type'] ?? '') !== 'qemu') continue;
        $vmid = $res['vmid'] ?? 0;
        $node = $res['node'] ?? '';
        if (!$vmid || !$node) continue;

        try {
            $config = $api->getGuestConfig($node, 'qemu', $vmid);
            $sshkeys = $config['data']['sshkeys'] ?? '';
            if (!$sshkeys) continue;

            $decoded = urldecode($sshkeys);
            if (strpos($decoded, $oldKey) === false) continue;

            $vmName = $res['name'] ?? "VM {$vmid}";
            $vmStatus = $res['status'] ?? 'unknown';
            $ciUser = $config['data']['ciuser'] ?? '';

            // Update cloud-init config with new key (for future boots)
            // Proxmox API expects the sshkeys value to be URL-encoded
            $newDecoded = str_replace($oldKey, $newPublicKey, $decoded);
            $newEncoded = rawurlencode(trim($newDecoded) . "\n");
            $api->setGuestConfig($node, 'qemu', $vmid, [
                'sshkeys' => $newEncoded,
            ]);

            $agentOk = false;

            // For running VMs: update authorized_keys via guest agent
            if ($vmStatus === 'running' && $ciUser) {
                try {
                    // Determine home directory based on user
                    $homeDir = $ciUser === 'root' ? '/root' : "/home/{$ciUser}";
                    $authKeysFile = "{$homeDir}/.ssh/authorized_keys";

                    // Build sed command to replace old key with new key
                    // Use the key type+data portion (without comment) for matching
                    $oldKeyParts = explode(' ', $oldKey);
                    $oldKeyMatch = ($oldKeyParts[0] ?? '') . ' ' . ($oldKeyParts[1] ?? '');
                    $newKeyEscaped = str_replace('/', '\\/', $newPublicKey);
                    $oldKeyEscaped = str_replace('/', '\\/', $oldKeyMatch);

                    $sedCmd = "sed -i 's|.*" . $oldKeyEscaped . ".*|" . $newKeyEscaped . "|' " . $authKeysFile;

                    $execResult = $api->agentExec($node, $vmid, '/bin/bash');
                    $pid = $execResult['data']['pid'] ?? 0;

                    if ($pid) {
                        // Use input-data approach: run via bash with the sed command
                        $execResult = $api->agentExec($node, $vmid, '/bin/bash', [$sedCmd]);
                        $pid = $execResult['data']['pid'] ?? 0;

                        if ($pid) {
                            // Wait briefly for completion
                            usleep(500000);
                            try {
                                $status = $api->agentExecStatus($node, $vmid, $pid);
                                $agentOk = ($status['data']['exited'] ?? false) && ($status['data']['exitcode'] ?? -1) === 0;
                            } catch (\Exception $e) {
                                // Status check failed, but command may have succeeded
                                $agentOk = false;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    // Guest agent not available or failed — key updated in config only
                    $agentOk = false;
                }
            }

            $results[] = [
                'vmid' => $vmid,
                'name' => $vmName,
                'node' => $node,
                'status' => $vmStatus,
                'config_updated' => true,
                'agent_updated' => $agentOk,
                'needs_restart' => $vmStatus === 'running' && !$agentOk,
            ];
            $successCount++;
        } catch (\Exception $e) {
            $results[] = [
                'vmid' => $vmid,
                'name' => $res['name'] ?? "VM {$vmid}",
                'node' => $node,
                'status' => $res['status'] ?? 'unknown',
                'config_updated' => false,
                'agent_updated' => false,
                'error' => $e->getMessage(),
            ];
            $failCount++;
        }
    }

    // Update user's SSH public key in database
    $newKeys = str_replace($oldKey, $newPublicKey, $currentKeys);
    $stmt = $db->prepare('UPDATE users SET ssh_public_keys = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
    $stmt->execute([$newKeys, $user['id']]);

    AppLogger::info('security', 'Cloud-Init SSH key rotated', [
        'vms_updated' => $successCount,
        'vms_failed' => $failCount,
        'vm_count' => count($results),
    ], $user['id']);

    Response::success([
        'private_key' => $newPrivateKey,
        'public_key' => $newPublicKey,
        'results' => $results,
        'updated' => $successCount,
        'failed' => $failCount,
    ]);
}

Response::error('Method not allowed', 405);
