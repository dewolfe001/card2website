<?php
// save_html.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

function fail(string $msg, int $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) fail('No payload');
$input = json_decode($raw, true);
if (!$input) fail('Invalid JSON');

if (empty($input['nonce']) || empty($_SESSION['editor_nonce']) || !hash_equals($_SESSION['editor_nonce'], (string)$input['nonce'])) {
    fail('Invalid nonce', 403);
}

$id = isset($input['id']) ? (int)$input['id'] : 0;
$html = $input['html'] ?? '';
if ($id <= 0 || !$html) fail('Missing id or html');

// Basic sanitation idea: allow full doc but you may want to sanitize/strip scripts if needed.
if (stripos($html, '<script') !== false) {
    // Optionally reject or sanitize
    // fail('Scripts not allowed');
}

// Save using PDO
try {
    // Adjust DSN/credentials
    $pdo = new PDO('mysql:host=localhost;dbname=businesscard2web_app;charset=utf8mb4', 'DB_USER', 'DB_PASS', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Example table: generated_sites(id INT PK, html_code LONGTEXT, updated_at TIMESTAMP)
    $stmt = $pdo->prepare('UPDATE generated_sites SET html_code = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$html, $id]);

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    fail('DB error: ' . $e->getMessage(), 500);
}
