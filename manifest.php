<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid id'], JSON_UNESCAPED_SLASHES);
    exit;
}

$root = __DIR__;
$siteRel = 'generated_sites/' . $id;
$sitePath = $root . '/' . $siteRel;
$htmlRel = $siteRel . '/index.html';
$htmlPath = $root . '/' . $htmlRel;
$legacyHtml = $root . '/generated_sites/' . $id . '.html';

if (!file_exists($htmlPath) && file_exists($legacyHtml)) {
    if (!is_dir($sitePath)) {
        mkdir($sitePath, 0777, true);
    }
    rename($legacyHtml, $htmlPath);
}

if (!file_exists($htmlPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found'], JSON_UNESCAPED_SLASHES);
    exit;
}

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

function buildUrl(string $base, string $path): string
{
    $parts = array_map('rawurlencode', explode('/', $path));
    return $base . '/' . implode('/', $parts);
}

function normalizePath(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $segments = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segments);
        } else {
            $segments[] = $seg;
        }
    }
    return implode('/', $segments);
}

function isExternal(string $url): bool
{
    return (bool) preg_match('#^(?:[a-z][a-z0-9+.-]*:|//)#i', $url);
}

$files = [];
$fileEntries = [];

// include index.html
$files[] = 'index.html';
$fileEntries[] = [
    'url' => buildUrl($baseUrl, $htmlRel),
    'save_as' => 'index.html',
];

$dom = new DOMDocument();
libxml_use_internal_errors(true);
$htmlContent = file_get_contents($htmlPath);
$dom->loadHTML($htmlContent);
libxml_clear_errors();

$assetPaths = [];

foreach ($dom->getElementsByTagName('img') as $el) {
    $assetPaths[] = $el->getAttribute('src');
}
foreach ($dom->getElementsByTagName('script') as $el) {
    $assetPaths[] = $el->getAttribute('src');
}
foreach ($dom->getElementsByTagName('link') as $el) {
    $relAttr = strtolower($el->getAttribute('rel'));
    if (strpos($relAttr, 'stylesheet') !== false || strpos($relAttr, 'icon') !== false) {
        $assetPaths[] = $el->getAttribute('href');
    }
}

foreach ($assetPaths as $path) {
    $path = trim((string) $path);
    if ($path === '') {
        continue;
    }
    $path = parse_url($path, PHP_URL_PATH);
    if (!$path || isExternal($path)) {
        continue;
    }

    if ($path[0] === '/') {
        $rel = normalizePath(ltrim($path, '/'));
        $url = buildUrl($baseUrl, $rel);
    } else {
        $rel = normalizePath($path);
        $url = buildUrl($baseUrl, $siteRel . '/' . $rel);
    }

    if (!in_array($rel, $files, true)) {
        $files[] = $rel;
        $fileEntries[] = ['url' => $url, 'save_as' => $rel];
    }
}

$dirSet = [];
foreach ($files as $file) {
    $dir = dirname($file);
    while ($dir !== '.' && $dir !== '') {
        $dirSet[$dir] = true;
        $dir = dirname($dir);
    }
}

$directories = array_keys($dirSet);
sort($directories);

$result = [
    'directories' => $directories,
    'files' => $fileEntries,
];

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
