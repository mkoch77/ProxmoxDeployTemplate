<?php

/**
 * Caching proxy for community-scripts catalog.
 *
 * The upstream site (community-scripts.org) no longer exposes a public JSON API.
 * Individual script metadata lives in ~486 separate JSON files on GitHub.
 * This endpoint fetches them, combines into the categories-with-nested-scripts
 * format the frontend expects, and caches the result for 6 hours.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('GET');
Auth::requireAuth();

$cacheDir  = __DIR__ . '/../../data';
$cacheFile = $cacheDir . '/community-scripts-cache.json';
$cacheTtl  = 6 * 3600; // 6 hours

// Return cached data if fresh
if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
    $cached = json_decode(file_get_contents($cacheFile), true);
    if ($cached) {
        Response::success(['categories' => $cached]);
    }
}

set_time_limit(120);

$ghRawBase = 'https://raw.githubusercontent.com/community-scripts/ProxmoxVE-Frontend-Archive/main/public/json/';

// 1. Fetch metadata (categories)
$metadataJson = @file_get_contents($ghRawBase . 'metadata.json');
if (!$metadataJson) {
    AppLogger::error('http', 'Failed to fetch community-scripts metadata.json');
    Response::error('Failed to fetch community scripts catalog', 502);
}

$metadata = json_decode($metadataJson, true);
$categories = $metadata['categories'] ?? $metadata;
if (!is_array($categories)) {
    Response::error('Invalid metadata format', 502);
}

// Build category lookup: id => category (with empty scripts array)
$catMap = [];
foreach ($categories as $cat) {
    $catMap[(int)$cat['id']] = [
        'id'          => (int)$cat['id'],
        'name'        => $cat['name'] ?? '',
        'icon'        => $cat['icon'] ?? '',
        'sort_order'  => $cat['sort_order'] ?? 99,
        'description' => $cat['description'] ?? '',
        'scripts'     => [],
    ];
}

// 2. Get list of JSON files from GitHub API
$ctx = stream_context_create(['http' => [
    'header'  => "User-Agent: ProxmoxDeploy\r\n",
    'timeout' => 30,
]]);

$dirJson = @file_get_contents(
    'https://api.github.com/repos/community-scripts/ProxmoxVE-Frontend-Archive/contents/public/json',
    false, $ctx
);
if (!$dirJson) {
    AppLogger::error('http', 'Failed to list community-scripts JSON directory from GitHub API');
    Response::error('Failed to fetch community scripts file list', 502);
}

$dirEntries = json_decode($dirJson, true);
if (!is_array($dirEntries)) {
    Response::error('Invalid directory listing', 502);
}

// Filter to script JSON files (exclude metadata.json, version.json)
$scriptFiles = [];
foreach ($dirEntries as $entry) {
    $name = $entry['name'] ?? '';
    if ($name && str_ends_with($name, '.json') && $name !== 'metadata.json' && $name !== 'version.json') {
        $scriptFiles[] = $name;
    }
}

AppLogger::debug('http', 'Fetching community scripts catalog', ['script_count' => count($scriptFiles)]);

// 3. Fetch all script JSONs in parallel using multi-curl
$batchSize = 50;
$allScripts = [];
$batches = array_chunk($scriptFiles, $batchSize);

foreach ($batches as $batch) {
    $mh = curl_multi_init();
    $handles = [];

    foreach ($batch as $fileName) {
        $ch = curl_init($ghRawBase . $fileName);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ProxmoxDeploy',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[] = ['handle' => $ch, 'file' => $fileName];
    }

    // Execute all requests
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1);
        }
    } while ($active && $status === CURLM_OK);

    // Collect results
    foreach ($handles as $h) {
        $content = curl_multi_getcontent($h['handle']);
        $httpCode = curl_getinfo($h['handle'], CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($mh, $h['handle']);
        curl_close($h['handle']);

        if ($httpCode === 200 && $content) {
            $script = json_decode($content, true);
            if (is_array($script) && !empty($script['slug'])) {
                $allScripts[] = $script;
            }
        }
    }

    curl_multi_close($mh);
}

// 4. Assign scripts to categories
foreach ($allScripts as $script) {
    $scriptCats = $script['categories'] ?? [0];
    foreach ($scriptCats as $catId) {
        $catId = (int)$catId;
        if (!isset($catMap[$catId])) {
            // Unknown category — put in Miscellaneous (0)
            $catId = 0;
            if (!isset($catMap[0])) {
                $catMap[0] = ['id' => 0, 'name' => 'Miscellaneous', 'icon' => 'more-horizontal', 'sort_order' => 99, 'description' => '', 'scripts' => []];
            }
        }
        $catMap[$catId]['scripts'][] = $script;
    }
}

// 5. Sort categories by sort_order, return as indexed array
usort($catMap, fn($a, $b) => ($a['sort_order'] ?? 99) <=> ($b['sort_order'] ?? 99));

// Remove empty categories
$catMap = array_values(array_filter($catMap, fn($c) => !empty($c['scripts'])));

// 6. Cache result
if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0755, true);
}
file_put_contents($cacheFile, json_encode($catMap, JSON_UNESCAPED_UNICODE));

AppLogger::debug('http', 'Community scripts catalog cached', ['categories' => count($catMap), 'scripts' => count($allScripts)]);

Response::success(['categories' => $catMap]);
