<?php
// save_html.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    fail('No payload');
}
$input = json_decode($raw, true);
if (!$input) {
    fail('Invalid JSON');
}

if (empty($input['nonce']) || empty($_SESSION['editor_nonce']) || !hash_equals($_SESSION['editor_nonce'], (string)$input['nonce'])) {
    fail('Invalid nonce', 403);
}

$id   = isset($input['id']) ? (int) $input['id'] : 0; // upload_id
$html = $input['html'] ?? '';
if ($id <= 0 || !$html) {
    fail('Missing id or html');
}

// Basic sanitation idea: allow full doc but you may want to sanitize/strip scripts if needed.
// Currently we simply accept the HTML provided by the editor.

try {
    // Ensure generated_sites directory exists
    $dir = __DIR__ . '/generated_sites/';
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
        fail('Cannot create generated_sites directory', 500);
    }

    // Write HTML file that is served to the user
    $file = $dir . $id . '.html';
    if (file_put_contents($file, $html) === false) {
        fail('Failed to write HTML file', 500);
    }

    // Update database record for this upload
    $publicUrl = '/generated_sites/' . $id . '.html';
    $stmt = $pdo->prepare('UPDATE generated_sites SET html_code = ?, public_url = ?, created_at = NOW() WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$html, $publicUrl, $id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    fail('DB error: ' . $e->getMessage(), 500);
}
