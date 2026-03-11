#!/usr/bin/env php
<?php
/**
 * Clean up stale cicustom references from VMs.
 *
 * After a cloud-init VM's first boot, the vendor snippet is no longer needed.
 * Leaving the cicustom reference causes "volume does not exist" errors when
 * the VM is migrated to another node (local:snippets/ is per-node storage).
 *
 * This script finds all VMs with a cicustom config referencing local:snippets/ci_vendor_*
 * and removes the reference (+ deletes the snippet file if it exists).
 *
 * Safe to run repeatedly. Can be added to cron or run manually.
 *
 * Usage: php cli/cloudinit-cleanup.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Helpers;
use App\AppLogger;
use App\Config;

$api = Helpers::createAPI();

// Get all nodes
$nodes = $api->getNodes();
$cleaned = 0;

foreach ($nodes['data'] ?? [] as $nodeInfo) {
    $nodeName = $nodeInfo['node'] ?? '';
    if (!$nodeName || ($nodeInfo['status'] ?? '') !== 'online') {
        continue;
    }

    // Get all qemu VMs on this node
    try {
        $vms = $api->getNodeQemu($nodeName);
    } catch (\Exception $e) {
        fprintf(STDERR, "Warning: cannot list VMs on %s: %s\n", $nodeName, $e->getMessage());
        continue;
    }

    foreach ($vms['data'] ?? [] as $vm) {
        $vmid = $vm['vmid'] ?? 0;
        if (!$vmid) continue;

        // Get VM config to check for cicustom
        try {
            $config = $api->getGuestConfig($nodeName, 'qemu', $vmid);
        } catch (\Exception $e) {
            continue;
        }

        $cicustom = $config['data']['cicustom'] ?? '';
        if (!$cicustom || !str_contains($cicustom, 'local:snippets/ci_vendor_')) {
            continue;
        }

        // VM has a stale cicustom reference — remove it
        printf("VM %d on %s: removing cicustom '%s'\n", $vmid, $nodeName, $cicustom);

        try {
            $api->setGuestConfig($nodeName, 'qemu', $vmid, ['delete' => 'cicustom']);
            $cleaned++;

            // Also clean up the snippet file via SSH
            try {
                $sshHost = Helpers::resolveNodeSshHost($api, $nodeName);
                $snippetFile = '/var/lib/vz/snippets/ci_vendor_' . $vmid . '.yaml';
                \App\SSH::exec($sshHost, 'rm -f ' . escapeshellarg($snippetFile));
            } catch (\Exception $e) {
                // Non-critical
            }

            AppLogger::info('deploy', "Cleaned up stale cicustom for VM {$vmid} on {$nodeName}");
        } catch (\Exception $e) {
            fprintf(STDERR, "Error cleaning VM %d: %s\n", $vmid, $e->getMessage());
        }
    }
}

if ($cleaned > 0) {
    printf("Done — cleaned %d VM(s)\n", $cleaned);
} else {
    // Silent when nothing to do (cron-friendly)
}
