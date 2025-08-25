<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid id'], JSON_UNESCAPED_SLASHES);
    exit;
}

$root    = __DIR__;
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

$htmlRel  = 'generated_sites/' . $id . '.html';
$htmlPath = $root . '/' . $htmlRel;
if (!file_exists($htmlPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Project not found'], JSON_UNESCAPED_SLASHES);
    exit;
}

$files = [$htmlRel];

$searchDirs = [
    'generated_sites/' . $id,
    'uploads/site_images/' . $id,
];

foreach ($searchDirs as $relDir) {
    $dirPath = $root . '/' . $relDir;
    if (is_dir($dirPath)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isFile()) {
                $files[] = str_replace($root . '/', '', $fileInfo->getPathname());
            }
        }
    }
}

$dirSet = [];
foreach ($files as $relFile) {
    $dir = dirname($relFile);
    while ($dir !== '.' && $dir !== '') {
        $dirSet[$dir] = true;
        $dir = dirname($dir);
    }
}

$directories = array_keys($dirSet);
sort($directories);

function buildUrl(string $base, string $path): string
{
    $parts = array_map('rawurlencode', explode('/', $path));
    return $base . '/' . implode('/', $parts);
}

$htmlUrl = buildUrl($baseUrl, $htmlRel);
$assetUrls = [];
foreach ($files as $relFile) {
    if ($relFile === $htmlRel) {
        continue;
    }
    $assetUrls[] = buildUrl($baseUrl, $relFile);
}

$result = [
    'directories' => $directories,
    'urls' => [
        'html'  => $htmlUrl,
        'files' => $assetUrls,
    ],
];

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
