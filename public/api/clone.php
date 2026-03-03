<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Session;
use App\Request;
use App\Response;
use App\Helpers;

Session::start();
Request::requireMethod('POST');
Request::validateCsrf();

$body = Request::jsonBody();
Request::requireParams(['source_node', 'source_type', 'source_vmid', 'newid', 'name'], $body);

// Validate inputs
if (!Helpers::validateNodeName($body['source_node'])) {
    Response::error('Invalid source node name', 400);
}
if (!Helpers::validateType($body['source_type'])) {
    Response::error('Invalid source type (must be qemu or lxc)', 400);
}
if (!Helpers::validateVmid($body['source_vmid'])) {
    Response::error('Invalid source VMID', 400);
}
if (!Helpers::validateVmid($body['newid'])) {
    Response::error('Invalid new VMID', 400);
}
if (!Helpers::validateVmName($body['name'])) {
    Response::error('Invalid VM name (alphanumeric, hyphens, dots only)', 400);
}

try {
    $api = Helpers::createAPI();

    // Step 1: Clone
    $cloneParams = [
        'newid' => (int) $body['newid'],
        'name'  => $body['name'],
        'full'  => !empty($body['full']) ? 1 : 0,
    ];

    if (!empty($body['target_node'])) {
        $cloneParams['target'] = $body['target_node'];
    }
    if (!empty($body['storage'])) {
        $cloneParams['storage'] = $body['storage'];
    }
    if (!empty($body['pool'])) {
        $cloneParams['pool'] = $body['pool'];
    }
    if (!empty($body['description'])) {
        $cloneParams['description'] = $body['description'];
    }

    $result = $api->cloneGuest(
        $body['source_node'],
        $body['source_type'],
        (int) $body['source_vmid'],
        $cloneParams
    );

    $upid = $result['data'];
    $targetNode = $body['target_node'] ?? $body['source_node'];

    // Step 2: Check if post-clone config is needed
    $configNeeded = !empty($body['cores']) || !empty($body['memory'])
        || !empty($body['cloudinit']) || !empty($body['net_bridge']);

    if ($configNeeded || !empty($body['disk_resize'])) {
        // Wait for clone task to finish (poll every 2s, max 120s)
        $maxWait = 120;
        $waited = 0;
        $cloneSuccess = false;

        while ($waited < $maxWait) {
            sleep(2);
            $waited += 2;
            $status = $api->getTaskStatus($body['source_node'], $upid);
            if (($status['data']['status'] ?? '') === 'stopped') {
                if (($status['data']['exitstatus'] ?? '') === 'OK') {
                    $cloneSuccess = true;
                }
                break;
            }
        }

        if (!$cloneSuccess) {
            Response::success([
                'upid'    => $upid,
                'vmid'    => (int) $body['newid'],
                'warning' => 'Clone task did not finish in time. Config changes were not applied.',
            ]);
        }

        // Step 3: Apply config changes
        $config = [];
        if (!empty($body['cores'])) {
            $config['cores'] = (int) $body['cores'];
        }
        if (!empty($body['memory'])) {
            $config['memory'] = (int) $body['memory'];
        }

        // Network
        if (!empty($body['net_bridge'])) {
            if ($body['source_type'] === 'qemu') {
                $netStr = 'virtio,bridge=' . $body['net_bridge'];
                if (!empty($body['net_vlan'])) {
                    $netStr .= ',tag=' . (int) $body['net_vlan'];
                }
            } else {
                // LXC
                $netStr = 'name=eth0,bridge=' . $body['net_bridge'];
                if (!empty($body['net_vlan'])) {
                    $netStr .= ',tag=' . (int) $body['net_vlan'];
                }
                $netStr .= ',ip=dhcp';
            }
            $config['net0'] = $netStr;
        }

        // Cloud-Init
        if (!empty($body['cloudinit'])) {
            $ci = $body['cloudinit'];
            if (!empty($ci['ciuser'])) {
                $config['ciuser'] = $ci['ciuser'];
            }
            if (!empty($ci['cipassword'])) {
                $config['cipassword'] = $ci['cipassword'];
            }
            if (!empty($ci['sshkeys'])) {
                $config['sshkeys'] = urlencode($ci['sshkeys']);
            }
            if (!empty($ci['ipconfig0'])) {
                $config['ipconfig0'] = $ci['ipconfig0'];
            }
            if (!empty($ci['nameserver'])) {
                $config['nameserver'] = $ci['nameserver'];
            }
            if (!empty($ci['searchdomain'])) {
                $config['searchdomain'] = $ci['searchdomain'];
            }
            // For LXC, also apply IP to net0 if static
            if ($body['source_type'] === 'lxc' && !empty($ci['ipconfig0'])) {
                // Parse ipconfig0 format: ip=x.x.x.x/xx,gw=x.x.x.x
                $ipMatch = [];
                if (preg_match('/ip=([^,]+)/', $ci['ipconfig0'], $ipMatch)) {
                    $bridge = $body['net_bridge'] ?? 'vmbr0';
                    $netStr = "name=eth0,bridge={$bridge},ip={$ipMatch[1]}";
                    if (preg_match('/gw=([^,]+)/', $ci['ipconfig0'], $gwMatch)) {
                        $netStr .= ",gw={$gwMatch[1]}";
                    }
                    if (!empty($body['net_vlan'])) {
                        $netStr .= ',tag=' . (int) $body['net_vlan'];
                    }
                    $config['net0'] = $netStr;
                }
            }
        }

        if (!empty($config)) {
            $api->setGuestConfig($targetNode, $body['source_type'], (int) $body['newid'], $config);
        }

        // Step 4: Resize disk if requested
        if (!empty($body['disk_resize'])) {
            $disk = ($body['source_type'] === 'qemu') ? 'scsi0' : 'rootfs';
            $api->resizeDisk($targetNode, $body['source_type'], (int) $body['newid'], $disk, $body['disk_resize']);
        }
    }

    Response::success([
        'upid' => $upid,
        'vmid' => (int) $body['newid'],
    ]);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
