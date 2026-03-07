<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Auth::requirePermission('template.deploy');

$method = $_SERVER['REQUEST_METHOD'];

// Parse Proxmox tag-style color-map string into an associative array.
// Format: "color-map=tag1:rrggbb:rrggbb;tag2:rrggbb [ordering=... shape=...]"
function parseTagColorMap(string $tagStyle): array
{
    $colorMap = [];
    if (!preg_match('/color-map=(\S+)/', $tagStyle, $m)) {
        return $colorMap;
    }
    foreach (explode(';', $m[1]) as $entry) {
        $parts = explode(':', trim($entry));
        $tag = trim($parts[0]);
        if ($tag === '') continue;
        $colorMap[$tag] = [
            'bg' => strtolower($parts[1] ?? '0088cc'),
            'fg' => strtolower($parts[2] ?? 'ffffff'),
        ];
    }
    return $colorMap;
}

// Serialize color map back into a tag-style string, preserving other options.
function serializeTagColorMap(array $colorMap, string $existingTagStyle): string
{
    $entries = [];
    foreach ($colorMap as $tag => $colors) {
        $entries[] = $tag . ':' . $colors['bg'] . ':' . $colors['fg'];
    }
    $colorMapStr = 'color-map=' . implode(';', $entries);

    if (preg_match('/color-map=\S+/', $existingTagStyle)) {
        return preg_replace('/color-map=\S+/', $colorMapStr, $existingTagStyle);
    }
    return ($existingTagStyle !== '' ? $existingTagStyle . ' ' : '') . $colorMapStr;
}

if ($method === 'GET') {
    try {
        $api = Helpers::createAPI();

        // Collect all unique tags from cluster VMs/CTs
        $resources = $api->getClusterResources('vm');
        $tags = [];
        foreach ($resources['data'] ?? [] as $item) {
            if (!empty($item['tags'])) {
                foreach (preg_split('/[;,\s]+/', (string)$item['tags']) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') $tags[$tag] = true;
                }
            }
        }

        // Get tag colors from datacenter options
        $colorMap = [];
        try {
            $options = $api->getClusterOptions();
            $tagStyle = (string)($options['data']['tag-style'] ?? '');
            $colorMap = parseTagColorMap($tagStyle);
        } catch (\Exception $e) {
            // Not fatal — some PVE versions may not support tag-style
        }

        Response::success([
            'tags'   => array_keys($tags),
            'colors' => $colorMap, // tagname → {bg: 'rrggbb', fg: 'rrggbb'}
        ]);
    } catch (\Exception $e) {
        Response::error($e->getMessage(), 500);
    }
}

if ($method === 'POST') {
    Request::validateCsrf();
    $body = Request::jsonBody();

    $tag = trim($body['tag'] ?? '');
    $bg  = preg_replace('/[^0-9a-fA-F]/', '', $body['color'] ?? '');
    $fg  = preg_replace('/[^0-9a-fA-F]/', '', $body['text_color'] ?? 'ffffff');

    if (!$tag || !preg_match('/^[a-zA-Z0-9\-_]+$/', $tag)) {
        Response::error('Invalid tag name', 400);
    }
    if (strlen($bg) !== 6) {
        Response::error('Invalid background color (6-char hex required)', 400);
    }
    if (strlen($fg) !== 6) {
        $fg = 'ffffff';
    }

    try {
        $api = Helpers::createAPI();
        $options = $api->getClusterOptions();
        $tagStyle = (string)($options['data']['tag-style'] ?? '');
        $colorMap = parseTagColorMap($tagStyle);

        $colorMap[$tag] = ['bg' => strtolower($bg), 'fg' => strtolower($fg)];

        $newTagStyle = serializeTagColorMap($colorMap, $tagStyle);
        $api->setClusterOptions(['tag-style' => $newTagStyle]);

        AppLogger::info('config', 'Tag color updated', ['tag' => $tag, 'bg' => $bg, 'fg' => $fg], Auth::check()['id'] ?? null);
        Response::success(['tag' => $tag, 'bg' => $bg, 'fg' => $fg]);
    } catch (\Exception $e) {
        AppLogger::error('config', 'Tag color update failed', ['tag' => $tag, 'error' => $e->getMessage()], Auth::check()['id'] ?? null);
        Response::error($e->getMessage(), 500);
    }
}

Response::error('Method not allowed', 405);
