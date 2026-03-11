<?php

namespace App;

class Helpers
{
    public static function sanitizeString(string $input): string
    {
        return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
    }

    public static function validateVmid(mixed $vmid): bool
    {
        return is_numeric($vmid) && (int)$vmid >= 100 && (int)$vmid <= 999999999;
    }

    public static function validateNodeName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9\-]+$/', $name);
    }

    public static function validateVmName(string $name): bool
    {
        return (bool)preg_match('/^[a-zA-Z0-9\-\.]+$/', $name);
    }

    public static function validateType(string $type): bool
    {
        return in_array($type, ['qemu', 'lxc'], true);
    }

    public static function validatePowerAction(string $action): bool
    {
        return in_array($action, ['start', 'stop', 'shutdown', 'reboot', 'reset'], true);
    }

    /**
     * Check if a node has enough physical CPU cores for the requested vCPU count.
     * Throws if requested cores exceed the node's maxcpu.
     */
    public static function checkNodeCpuCapacity(ProxmoxAPI $api, string $node, int $requestedCores): void
    {
        $nodesResult = $api->getNodes();
        foreach ($nodesResult['data'] ?? [] as $n) {
            if ($n['node'] === $node) {
                $maxCpu = (int)($n['maxcpu'] ?? 0);
                if ($maxCpu > 0 && $requestedCores > $maxCpu) {
                    Response::error(
                        "Requested {$requestedCores} vCPUs exceeds node '{$node}' capacity of {$maxCpu} physical cores",
                        400
                    );
                }
                return;
            }
        }
    }

    /**
     * Resolve the SSH host for a Proxmox node: env override → cluster status IP → node name fallback.
     */
    public static function resolveNodeSshHost(ProxmoxAPI $api, string $nodeName): string
    {
        $envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
        $sshHost = Config::get($envKey, '');
        if ($sshHost) {
            return $sshHost;
        }
        try {
            $status = $api->getClusterStatus();
            foreach ($status['data'] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'node' &&
                    strtolower($entry['name'] ?? '') === strtolower($nodeName) &&
                    !empty($entry['ip'])) {
                    return $entry['ip'];
                }
            }
        } catch (\Exception $e) { /* fall back to node name */ }
        return $nodeName;
    }

    public static function createAPI(): ProxmoxAPI
    {
        $primary = Config::get('PROXMOX_HOST');
        $fallbacks = array_filter(array_map(
            'trim',
            explode(',', Config::get('PROXMOX_FALLBACK_HOSTS', ''))
        ));
        $hosts = array_values(array_unique(array_merge([$primary], $fallbacks)));

        return new ProxmoxAPI(
            $hosts,
            (int) Config::get('PROXMOX_PORT', 8006),
            Config::get('PROXMOX_TOKEN_ID'),
            Config::get('PROXMOX_TOKEN_SECRET'),
            filter_var(Config::get('PROXMOX_VERIFY_SSL', false), FILTER_VALIDATE_BOOLEAN)
        );
    }
}
