<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requireAuth();

AppLogger::debug('api', 'Fetching guest list');

try {
    $api = Helpers::createAPI();

    $guests = $api->getGuests();

    $nodeFilter = Request::get('node');
    if ($nodeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['node'] === $nodeFilter));
    }

    $typeFilter = Request::get('type');
    if ($typeFilter) {
        $guests = array_values(array_filter($guests, fn($g) => $g['type'] === $typeFilter));
    }

    // Attach cached IPs and ostype from database
    $db = \App\Database::connection();
    $ipMap = [];
    $osCache = [];
    $hasOstype = true;
    try {
        $ipRows = $db->query('SELECT vmid, node, ips, ostype FROM guest_ips')->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        // ostype column may not exist yet (migration 036)
        $hasOstype = false;
        $ipRows = $db->query('SELECT vmid, node, ips FROM guest_ips')->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($ipRows as $row) {
        $key = $row['vmid'] . '-' . $row['node'];
        $ipMap[$key] = json_decode($row['ips'], true) ?: [];
        if (!empty($row['ostype'] ?? null)) $osCache[$key] = $row['ostype'];
    }
    foreach ($guests as &$guest) {
        $key = $guest['vmid'] . '-' . $guest['node'];
        $guest['ips'] = $ipMap[$key] ?? [];
        $guest['ostype'] = $osCache[$key] ?? null;
    }
    unset($guest);

    // Enrich OS type from Proxmox API in background (not in quick mode)
    $quick = Request::get('quick');
    if (!$quick) {
        $onlineNodes = [];
        try {
            $nodesResult = $api->getNodes();
            foreach ($nodesResult['data'] ?? [] as $n) {
                if (($n['status'] ?? '') === 'online') {
                    $onlineNodes[$n['node']] = true;
                }
            }
        } catch (\Exception $e) {}

        $quickOpts = ['connect_timeout' => 1, 'timeout' => 2];
        $enrichStart = microtime(true);
        $enrichMaxSeconds = 10;
        $ostypeUpdates = [];
        foreach ($guests as &$guest) {
            if (!isset($onlineNodes[$guest['node']])) continue;
            // Skip if already cached or time budget exceeded
            if ($guest['ostype']) continue;
            if (microtime(true) - $enrichStart > $enrichMaxSeconds) break;
            try {
                $config = $api->getGuestConfig($guest['node'], $guest['type'], (int)$guest['vmid'], $quickOpts);
                $ostype = $config['data']['ostype'] ?? null;
                if ($ostype) {
                    $guest['ostype'] = $ostype;
                    $ostypeUpdates[] = [(int)$guest['vmid'], $guest['node'], $ostype];
                }
            } catch (\Exception $e) {}
        }
        unset($guest);

        // Persist ostype cache to DB (if column exists)
        if ($hasOstype && !empty($ostypeUpdates)) {
            $stmt = $db->prepare('UPDATE guest_ips SET ostype = ? WHERE vmid = ? AND node = ?');
            foreach ($ostypeUpdates as [$vmid, $node, $os]) {
                $stmt->execute([$os, $vmid, $node]);
            }
        }
    }

    Response::success($guests);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
