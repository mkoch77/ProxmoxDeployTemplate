<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\SSH;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('cluster.update');
\App\Config::requireSsh();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Check for available updates via SSH + Proxmox API fallback
    Request::requireMethod('GET');
    $node = Request::get('node');
    if (!$node || !Helpers::validateNodeName($node)) {
        Response::error('Invalid node name', 400);
    }

    set_time_limit(120);

    try {
        $api     = Helpers::createAPI();
        $sshHost = Helpers::resolveNodeSshHost($api, $node);

        // ── Check subscription + repo status ────────────────────────────
        $warnings = [];
        try {
            $subOutput = SSH::exec($sshHost, 'pvesubscription get 2>/dev/null || echo "status:notfound"', 15);
            $hasSubscription = false;
            if (preg_match('/status:\s*(\S+)/i', $subOutput, $sm)) {
                $hasSubscription = strtolower($sm[1]) === 'active';
            }

            // Check which repos are configured
            $repoCheck = SSH::exec($sshHost, 'grep -rh "^deb " /etc/apt/sources.list /etc/apt/sources.list.d/ 2>/dev/null || true', 15);
            $hasEnterpriseRepo    = (bool)preg_match('/enterprise\.proxmox\.com/', $repoCheck);
            $hasNoSubRepo         = (bool)preg_match('/download\.proxmox\.com\/debian\/pve.*pve-no-subscription/', $repoCheck);
            $hasCephEnterpriseRepo = (bool)preg_match('/enterprise\.proxmox\.com\/debian\/ceph/', $repoCheck);

            if (!$hasSubscription) {
                if ($hasEnterpriseRepo && !$hasNoSubRepo) {
                    $warnings[] = 'No valid Proxmox subscription. The enterprise repository is configured but inaccessible — updates may be incomplete. Consider switching to the pve-no-subscription repository.';
                } elseif ($hasEnterpriseRepo && $hasNoSubRepo) {
                    $warnings[] = 'No valid Proxmox subscription. Updates are coming from the no-subscription (community) repository. The enterprise repository is still configured and will produce apt errors.';
                } elseif ($hasNoSubRepo) {
                    $warnings[] = 'No valid Proxmox subscription. Updates are coming from the no-subscription (community) repository — not recommended for production use.';
                } else {
                    $warnings[] = 'No valid Proxmox subscription and no Proxmox repository configured. Updates may not include Proxmox packages.';
                }
            }
            if ($hasCephEnterpriseRepo && !$hasSubscription) {
                $warnings[] = 'Ceph enterprise repository is configured without a valid subscription.';
            }
        } catch (\Exception $e) {
            AppLogger::debug('system', 'Subscription check failed on ' . $node, ['error' => $e->getMessage()]);
        }

        // Refresh package index via SSH (handles enterprise repo errors gracefully)
        $aptUpdateOutput = '';
        try {
            $aptUpdateOutput = SSH::exec($sshHost, 'apt-get update -qq 2>&1', 90);
        } catch (\Exception $e) {
            AppLogger::debug('system', 'apt refresh via SSH failed on ' . $node, ['error' => $e->getMessage()]);
        }

        // Detect apt 401/403 errors (enterprise repo without subscription)
        if (preg_match('/401\s+Unauthorized|403\s+Forbidden/i', $aptUpdateOutput)) {
            $alreadyWarned = false;
            foreach ($warnings as $w) {
                if (stripos($w, 'enterprise') !== false) { $alreadyWarned = true; break; }
            }
            if (!$alreadyWarned) {
                $warnings[] = 'Enterprise repository returned authentication errors during apt update — no valid subscription.';
            }
        }

        // Try Proxmox API first for structured package data
        $packages = [];
        try {
            $result   = $api->getAptUpdates($node);
            $packages = array_map(fn($p) => [
                'name'        => $p['Package'] ?? $p['name'] ?? '',
                'new_version' => $p['Version'] ?? $p['new_version'] ?? '',
                'old_version' => $p['OldVersion'] ?? $p['old_version'] ?? '',
            ], $result['data'] ?? []);
        } catch (\Exception $e) {
            // API returns 501 when enterprise repo has no subscription — fall back to SSH
            AppLogger::debug('system', 'apt/updates API failed on ' . $node . ', falling back to SSH', ['error' => $e->getMessage()]);
            $output = SSH::exec($sshHost, 'apt list --upgradable 2>/dev/null | tail -n +2');
            foreach (explode("\n", trim($output)) as $line) {
                if (!$line) continue;
                // Format: "package/source version arch [upgradable from: old_version]"
                if (preg_match('/^(\S+)\/\S+\s+(\S+)\s+\S+\s+\[upgradable from:\s+(\S+)\]/', $line, $m)) {
                    $packages[] = [
                        'name'        => $m[1],
                        'new_version' => $m[2],
                        'old_version' => $m[3],
                    ];
                }
            }
        }

        Response::success([
            'node'     => $node,
            'count'    => count($packages),
            'packages' => $packages,
            'warnings' => $warnings,
        ]);
    } catch (\Exception $e) {
        Response::error($e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    Request::validateCsrf();
    $body = Request::jsonBody();
    $node = $body['node'] ?? '';

    if (!$node || !Helpers::validateNodeName($node)) {
        Response::error('Invalid node name', 400);
    }

    // Safety check: refuse to update if VMs/CTs are still running on this node
    $api = Helpers::createAPI();
    try {
        $guests = \App\MaintenanceManager::getNodeGuests($api, $node);
        if (!empty($guests)) {
            $vmids = array_map(fn($g) => $g['vmid'] ?? '?', $guests);
            AppLogger::warning('system', 'Node update blocked: VMs still running', [
                'node' => $node, 'running_guests' => $vmids,
            ]);
            Response::error('Cannot update: ' . count($guests) . ' VM(s)/CT(s) still running on this node (' . implode(', ', $vmids) . '). Migrate them first.', 409);
        }
    } catch (\Exception $e) {
        AppLogger::warning('system', 'Could not verify guest status before update', [
            'node' => $node, 'error' => $e->getMessage(),
        ]);
    }

    // Resolve SSH host (reuse existing API instance)
    $sshHost = Helpers::resolveNodeSshHost($api, $node);

    $userId = Auth::check()['id'] ?? null;
    AppLogger::info('system', 'Node update started', ['node' => $node], $userId);

    // Long-running: allow up to 10 minutes
    set_time_limit(600);

    $cmd = 'DEBIAN_FRONTEND=noninteractive apt-get update -qq 2>&1 || true; '
         . 'DEBIAN_FRONTEND=noninteractive apt-get dist-upgrade -y -q 2>&1';

    try {
        $result = SSH::execInstall($sshHost, $cmd, 600);
    } catch (\Exception $e) {
        AppLogger::error('system', 'Node update SSH connection failed', ['node' => $node, 'error' => $e->getMessage()], $userId);
        Response::error('SSH connection to node "' . $node . '" failed: ' . $e->getMessage(), 500);
    }

    $log     = $result['output'] ?? '';
    $success = $result['success'] ?? false;

    // Try to detect number of upgraded packages from apt output
    $upgraded = 0;
    if (preg_match('/(\d+) upgraded/', $log, $m)) {
        $upgraded = (int) $m[1];
    }

    // Detect subscription/repo warnings from apt output
    $warnings = [];
    if (preg_match('/401\s+Unauthorized|403\s+Forbidden/i', $log)) {
        $warnings[] = 'Enterprise repository returned authentication errors — no valid Proxmox subscription. Packages were installed from other configured repositories only.';
    }
    if (preg_match('/does not have a Release file|is not signed/i', $log)) {
        $warnings[] = 'One or more repositories are missing a Release file or signature. Check your apt sources configuration.';
    }

    if ($success) {
        AppLogger::info('system', 'Node update completed', ['node' => $node, 'upgraded_packages' => $upgraded, 'warnings' => $warnings], $userId);
    } else {
        AppLogger::warning('system', 'Node update completed with issues', ['node' => $node, 'upgraded_packages' => $upgraded, 'warnings' => $warnings], $userId);
    }

    Response::success([
        'node'     => $node,
        'success'  => $success,
        'upgraded' => $upgraded,
        'log'      => $log,
        'warnings' => $warnings,
    ]);
}

Response::error('Method not allowed', 405);
