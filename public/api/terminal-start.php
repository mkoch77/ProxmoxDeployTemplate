<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();
$user = Auth::requirePermission('template.deploy');

$body = Request::jsonBody();

$scriptPath = $body['script_path'] ?? '';
$vmIp       = $body['vm_ip'] ?? '';

if (!$scriptPath && !$vmIp) {
    Response::error('script_path or vm_ip required', 400);
}

if ($scriptPath && !preg_match('#^(ct|vm|misc|addon|turnkey|pve)/[a-zA-Z0-9_\-]+\.sh$#', $scriptPath)) {
    Response::error('Invalid script path', 400);
}

if ($vmIp && !filter_var($vmIp, FILTER_VALIDATE_IP)) {
    Response::error('Invalid VM IP address', 400);
}

$nodeName = $body['node'] ?? '';
if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
    Response::error('Invalid or missing node name', 400);
}

// Resolve SSH host
$envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
$sshHost = Config::get($envKey, '');

if (!$sshHost) {
    $sshHost = $nodeName;
    try {
        $api    = Helpers::createAPI();
        $status = $api->getClusterStatus();
        foreach ($status['data'] ?? [] as $entry) {
            if (($entry['type'] ?? '') === 'node' && strtolower($entry['name'] ?? '') === strtolower($nodeName)) {
                if (!empty($entry['ip'])) {
                    $sshHost = $entry['ip'];
                }
                break;
            }
        }
    } catch (\Exception $e) {
        // fall back to node name
    }
}

// Build session data
$sessData = [
    'ssh_host' => $sshHost,
    'user_id'  => $user['id'],
    'expires'  => time() + 600, // 10 min to start the stream
];

if ($vmIp) {
    // Auto-install QEMU guest agent: run command from Proxmox node → VM
    $installCmd = 'if command -v apt-get >/dev/null 2>&1; then'
        . ' DEBIAN_FRONTEND=noninteractive apt-get install -y qemu-guest-agent'
        . ' && systemctl enable --now qemu-guest-agent;'
        . ' elif command -v dnf >/dev/null 2>&1; then'
        . ' dnf install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent;'
        . ' elif command -v yum >/dev/null 2>&1; then'
        . ' yum install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent;'
        . ' elif command -v zypper >/dev/null 2>&1; then'
        . ' zypper install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent;'
        . ' else echo "ERROR: No supported package manager found" >&2; exit 1; fi';
    $sessData['direct_command'] = 'export TERM=xterm COLUMNS=200 LINES=50;'
        . ' ssh -o StrictHostKeyChecking=no -o ConnectTimeout=15 root@' . $vmIp
        . ' ' . escapeshellarg($installCmd);
} else {
    $sessData['script_path'] = $scriptPath;
}

// Create one-time session token and store params in server-side temp file
$token    = bin2hex(random_bytes(16));
$dataFile = sys_get_temp_dir() . '/term_sess_' . $token . '.json';
file_put_contents($dataFile, json_encode($sessData), LOCK_EX);

Response::success(['token' => $token]);
