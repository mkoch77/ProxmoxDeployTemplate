<?php

namespace App;

use PDO;

class MaintenanceManager
{
    /**
     * Select best target node for a VM migration.
     * Considers: online status, maintenance mode, memory capacity, affinity rules.
     *
     * @param int|null $vmid If provided, affinity rules are checked for this VM
     */
    public static function selectTargetNode(ProxmoxAPI $api, string $excludeNode, ?int $vmid = null): ?string
    {
        $nodes = $api->getNodes()['data'] ?? [];
        $db = Database::connection();
        $maintNodes = $db->query('SELECT node_name FROM maintenance_nodes')
            ->fetchAll(PDO::FETCH_COLUMN);

        $candidates = [];
        foreach ($nodes as $node) {
            if ($node['node'] === $excludeNode) continue;
            if (in_array($node['node'], $maintNodes, true)) continue;
            if (($node['status'] ?? '') !== 'online') continue;
            $candidates[] = $node;
        }

        if (empty($candidates)) return null;

        // Sort by available memory descending
        usort($candidates, fn($a, $b) =>
            (($b['maxmem'] ?? 0) - ($b['mem'] ?? 0)) <=> (($a['maxmem'] ?? 0) - ($a['mem'] ?? 0))
        );

        // If VMID provided, filter by affinity rules
        if ($vmid !== null) {
            $zones = AffinityHelper::getNodeZones();
            $rules = AffinityHelper::getRules();
            if (!empty($zones) && !empty($rules)) {
                $vmNodeMap = AffinityHelper::getVmNodeMap($api);
                foreach ($candidates as $c) {
                    if (AffinityHelper::isTargetAllowed($vmid, $c['node'], $zones, $rules, $vmNodeMap)) {
                        return $c['node'];
                    }
                }
                // No candidate satisfies affinity rules — fall back to best memory candidate
                AppLogger::warning('maintenance', "No affinity-compatible target for VM {$vmid}, using best-memory fallback");
            }
        }

        return $candidates[0]['node'];
    }

    /**
     * Detach local CD/DVD ISOs from a VM that would block live migration.
     * Returns an array of detached drives (bus => original value) for later re-attach.
     * Only detaches drives using local/non-shared storage with media=cdrom.
     */
    public static function detachLocalCdRoms(ProxmoxAPI $api, string $node, string $type, int $vmid): array
    {
        if ($type !== 'qemu') return []; // LXC has no CD drives

        $config = $api->getGuestConfig($node, $type, $vmid)['data'] ?? [];
        $detached = [];

        // CD-ROM buses: ide0-3, sata0-5, scsi0-30
        foreach ($config as $key => $value) {
            if (!is_string($value)) continue;
            if (!preg_match('/^(ide|sata|scsi)\d+$/', $key)) continue;
            if (strpos($value, 'media=cdrom') === false) continue;

            // Skip already empty drives (e.g. "none,media=cdrom")
            if (str_starts_with($value, 'none,')) continue;

            // Only detach local (non-shared) storage ISOs
            // Shared storage ISOs don't block migration
            $storageName = explode(':', $value)[0] ?? '';
            if ($storageName === '' || $storageName === 'none') continue;

            // Check if the storage is shared
            try {
                $storages = $api->getStorages($node)['data'] ?? [];
                $isShared = false;
                foreach ($storages as $s) {
                    if (($s['storage'] ?? '') === $storageName) {
                        $isShared = !empty($s['shared']);
                        break;
                    }
                }
                if ($isShared) continue;
            } catch (\Exception $e) {
                // If we can't determine, try detaching anyway
            }

            // Detach: set to "none,media=cdrom"
            try {
                $api->setGuestConfig($node, $type, $vmid, [$key => 'none,media=cdrom']);
                $detached[$key] = $value;
                AppLogger::info('maintenance', "Detached local CD-ROM for migration", [
                    'vmid' => $vmid, 'drive' => $key, 'was' => $value,
                ]);
            } catch (\Exception $e) {
                AppLogger::warning('maintenance', "Failed to detach CD-ROM", [
                    'vmid' => $vmid, 'drive' => $key, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $detached;
    }

    /**
     * Re-attach previously detached CD-ROM drives after migration.
     */
    public static function reattachCdRoms(ProxmoxAPI $api, string $node, string $type, int $vmid, array $detached): void
    {
        foreach ($detached as $key => $value) {
            try {
                $api->setGuestConfig($node, $type, $vmid, [$key => $value]);
                AppLogger::info('maintenance', "Re-attached CD-ROM after migration", [
                    'vmid' => $vmid, 'drive' => $key, 'value' => $value,
                ]);
            } catch (\Exception $e) {
                AppLogger::warning('maintenance', "Failed to re-attach CD-ROM", [
                    'vmid' => $vmid, 'drive' => $key, 'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function getNodeGuests(ProxmoxAPI $api, string $node): array
    {
        $guests = [];

        $vms = $api->getVMs($node)['data'] ?? [];
        foreach ($vms as $vm) {
            if (($vm['status'] ?? '') === 'running' && empty($vm['template'])) {
                $vm['type'] = 'qemu';
                $guests[] = $vm;
            }
        }

        $cts = $api->getCTs($node)['data'] ?? [];
        foreach ($cts as $ct) {
            if (($ct['status'] ?? '') === 'running' && empty($ct['template'])) {
                $ct['type'] = 'lxc';
                $guests[] = $ct;
            }
        }

        return $guests;
    }
}
