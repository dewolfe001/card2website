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

function extractBgImageUrls(string $css): array
{
    $urls = [];
    if (preg_match_all('/background(?:-image)?\s*:[^;]*;/i', $css, $props)) {
        foreach ($props[0] as $prop) {
            if (preg_match_all('/url\(([^)]+)\)/i', $prop, $matches)) {
                foreach ($matches[1] as $url) {
                    $url = trim($url, "'\" \t\n\r");
                    if ($url !== '') {
                        $urls[] = $url;
                    }
                }
            }
        }
    }
    return $urls;
}

function processAsset(string $path, string $baseDir, array &$files, array &$fileEntries, string $baseUrl, string $siteRel, array &$cssFiles): void
{
    $path = trim((string) $path);
    if ($path === '') {
        return;
    }
    $path = parse_url($path, PHP_URL_PATH);
    if (!$path || isExternal($path)) {
        return;
    }

    if ($path[0] === '/') {
        $rel = normalizePath(ltrim($path, '/'));
        $url = buildUrl($baseUrl, $rel);
    } else {
        $baseDir = $baseDir === '' ? '' : $baseDir . '/';
        $rel = normalizePath($baseDir . $path);
        $url = buildUrl($baseUrl, $siteRel . '/' . $rel);
    }

    if (!in_array($rel, $files, true)) {
        $files[] = $rel;
        $fileEntries[] = ['url' => $url, 'save_as' => $rel];
        if (strtolower(pathinfo($rel, PATHINFO_EXTENSION)) === 'css') {
            $cssFiles[] = $rel;
        }
    }
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
$cssFiles = [];

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

foreach ($dom->getElementsByTagName('*') as $el) {
    if ($el->hasAttribute('style')) {
        foreach (extractBgImageUrls($el->getAttribute('style')) as $url) {
            $assetPaths[] = $url;
        }
    }
}
foreach ($dom->getElementsByTagName('style') as $el) {
    foreach (extractBgImageUrls($el->textContent) as $url) {
        $assetPaths[] = $url;
    }
}

foreach ($assetPaths as $path) {
    processAsset($path, '', $files, $fileEntries, $baseUrl, $siteRel, $cssFiles);
}

$processedCss = [];
while ($cssFiles) {
    $cssRel = array_shift($cssFiles);
    if (isset($processedCss[$cssRel])) {
        continue;
    }
    $processedCss[$cssRel] = true;

    $cssFullPath = $sitePath . '/' . $cssRel;
    if (!file_exists($cssFullPath)) {
        $cssFullPath = $root . '/' . $cssRel;
        if (!file_exists($cssFullPath)) {
            continue;
        }
    }

    $cssContent = file_get_contents($cssFullPath);
    $baseDir = dirname($cssRel);
    if ($baseDir === '.') {
        $baseDir = '';
    }
    foreach (extractBgImageUrls($cssContent) as $url) {
        processAsset($url, $baseDir, $files, $fileEntries, $baseUrl, $siteRel, $cssFiles);
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
