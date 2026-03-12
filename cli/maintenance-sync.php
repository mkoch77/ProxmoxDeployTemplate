#!/usr/bin/env php
<?php
/**
 * Syncs maintenance state on container startup.
 * Detects nodes that have Proxmox HA maintenance mode enabled
 * and ensures the app's maintenance_nodes table reflects this.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Migrator;
use App\Helpers;
use App\Database;
use App\SSH;
use App\Config;
use App\AppLogger;

Migrator::run();

try {
    $api = Helpers::createAPI();
    $db  = Database::connection();

    // Get all nodes from Proxmox
    $nodes = $api->getNodes()['data'] ?? [];

    // Get current maintenance records from DB
    $dbMaint = $db->query('SELECT node_name, status FROM maintenance_nodes')->fetchAll(\PDO::FETCH_KEY_PAIR);

    // Check HA maintenance status via SSH on each online node
    $sshHost = Config::get('PROXMOX_HOST');
    $haStatusRaw = '';
    try {
        $haStatusRaw = SSH::exec($sshHost, 'ha-manager crm-command node-maintenance status 2>/dev/null || true');
    } catch (\Exception $e) {
        // SSH not available — try to detect via Proxmox API cluster status
        AppLogger::debug('maintenance', 'Cannot check HA maintenance via SSH: ' . $e->getMessage());
    }

    // Parse maintenance status output
    // Format is typically: "node: <nodename> maintenance: <enabled|disabled>"
    $maintEnabled = [];
    if ($haStatusRaw) {
        foreach (explode("\n", trim($haStatusRaw)) as $line) {
            $line = trim($line);
            if (preg_match('/(\S+)\s*:\s*enabled/i', $line, $m)) {
                $maintEnabled[] = $m[1];
            }
            // Also handle "nodename maintenance-mode: true/active/enabled" variants
            if (preg_match('/^(\S+)\s+.*(?:maintenance|maint).*(?:enabled|active|true)/i', $line, $m)) {
                if (!in_array($m[1], $maintEnabled, true)) {
                    $maintEnabled[] = $m[1];
                }
            }
        }
    }

    // If SSH didn't give us results, check if any nodes in the HA status have maintenance flags
    if (empty($maintEnabled)) {
        try {
            $haStatus = $api->getHAStatus();
            foreach ($haStatus['data'] ?? [] as $entry) {
                if (($entry['type'] ?? '') === 'node' &&
                    !empty($entry['maintenance']) &&
                    $entry['maintenance'] !== 'false' &&
                    $entry['maintenance'] !== '0') {
                    $maintEnabled[] = $entry['id'] ?? $entry['node'] ?? '';
                }
            }
        } catch (\Exception $e) {
            // HA might not be configured
        }
    }

    $maintEnabled = array_filter($maintEnabled);

    // Sync: add missing maintenance records for nodes that are in maintenance
    foreach ($maintEnabled as $nodeName) {
        if (!isset($dbMaint[$nodeName])) {
            $stmt = $db->prepare('INSERT INTO maintenance_nodes (node_name, status, started_by, migration_tasks) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nodeName, 'maintenance', null, '[]']);
            echo "Detected maintenance mode on '$nodeName' — added to DB.\n";
            AppLogger::info('maintenance', 'Container startup: detected active maintenance mode', ['node' => $nodeName]);
        }
    }

    // Clean up: remove stale DB records for nodes no longer in maintenance
    foreach ($dbMaint as $nodeName => $status) {
        if (!in_array($nodeName, $maintEnabled, true) && $status === 'maintenance') {
            // Node is marked as maintenance in DB but not in Proxmox — check if it's really not
            // Only clean up fully settled 'maintenance' records, not transitional states
            $stmt = $db->prepare('DELETE FROM maintenance_nodes WHERE node_name = ? AND status = ?');
            $stmt->execute([$nodeName, 'maintenance']);
            if ($stmt->rowCount() > 0) {
                echo "Node '$nodeName' no longer in maintenance — removed stale record.\n";
                AppLogger::info('maintenance', 'Container startup: cleared stale maintenance record', ['node' => $nodeName]);
            }
        }
    }

    echo "Maintenance sync completed.\n";
} catch (\Exception $e) {
    echo "Maintenance sync failed: " . $e->getMessage() . "\n";
    // Non-fatal — app can still start
}
