<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;
use PDO;

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

    // Build set of online nodes to avoid calling getGuestConfig on unreachable nodes
    $onlineNodes = [];
    try {
        $nodesResult = $api->getNodes();
        foreach ($nodesResult['data'] ?? [] as $n) {
            if (($n['status'] ?? '') === 'online') {
                $onlineNodes[$n['node']] = true;
            }
        }
    } catch (\Exception $e) { /* fall through — will skip config enrichment */ }

    // Enrich with OS type from guest config (online nodes only)
    foreach ($guests as &$guest) {
        if (!isset($onlineNodes[$guest['node']])) {
            $guest['ostype'] = null;
            continue;
        }
        try {
            $config = $api->getGuestConfig($guest['node'], $guest['type'], (int)$guest['vmid']);
            $guest['ostype'] = $config['data']['ostype'] ?? null;
        } catch (\Exception $e) {
            $guest['ostype'] = null;
        }
    }
    unset($guest);

    // Attach cached IPs from database
    $db = \App\Database::connection();
    $ipRows = $db->query('SELECT vmid, node, ips FROM guest_ips')->fetchAll(PDO::FETCH_ASSOC);
    $ipMap = [];
    foreach ($ipRows as $row) {
        $ipMap[$row['vmid'] . '-' . $row['node']] = json_decode($row['ips'], true) ?: [];
    }
    foreach ($guests as &$guest) {
        $key = $guest['vmid'] . '-' . $guest['node'];
        $guest['ips'] = $ipMap[$key] ?? [];
    }
    unset($guest);

    Response::success($guests);
} catch (\Exception $e) {
    Response::error($e->getMessage(), 500);
}
