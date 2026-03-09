<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;
use App\Database;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();
Auth::requirePermission('vm.delete');

$body = Request::jsonBody();
Request::requireParams(['node', 'type', 'vmid'], $body);

if (!Helpers::validateNodeName($body['node'])) {
    Response::error('Invalid node name', 400);
}
if (!Helpers::validateType($body['type'])) {
    Response::error('Invalid type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($body['vmid'])) {
    Response::error('Invalid VMID', 400);
}

try {
    $api = Helpers::createAPI();
    $vmid = (int) $body['vmid'];
    $result = $api->deleteGuest($body['node'], $body['type'], $vmid);

    // Clean up cloud-init vendor snippet on the node (if it exists)
    try {
        $node = $body['node'];
        $envKey = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $node));
        $sshHost = \App\Config::get($envKey, '');
        if (!$sshHost) {
            $sshHost = $node;
            $status = $api->getClusterStatus();
            foreach ($status['data'] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'node' && strtolower($entry['name'] ?? '') === strtolower($node) && !empty($entry['ip'])) {
                    $sshHost = $entry['ip'];
                    break;
                }
            }
        }
        $snippetFile = '/var/lib/vz/snippets/ci_vendor_' . $vmid . '.yaml';
        \App\SSH::exec($sshHost, 'rm -f ' . escapeshellarg($snippetFile));
    } catch (\Exception $e) {
        // Non-critical — snippet may not exist or node unreachable
    }

    // Clean up monitoring metrics for deleted VM
    $db = Database::connection();
    $db->prepare('DELETE FROM vm_metrics WHERE vmid = ?')->execute([$vmid]);

    AppLogger::warning('delete', "Deleted VM {$body['vmid']} on {$body['node']}", ['type' => $body['type']], Auth::check()['id'] ?? null);
    Response::success(['upid' => $result['data'] ?? null]);
} catch (\Exception $e) {
    AppLogger::error('delete', "Failed to delete VM {$body['vmid']}: {$e->getMessage()}", null, Auth::check()['id'] ?? null);
    Response::error($e->getMessage(), 500);
}
