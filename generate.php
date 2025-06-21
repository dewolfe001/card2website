<?php
require 'config.php';
require_once 'openai_helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$editedData = $_POST['edited_data'] ?? null;
$additional = $_POST['additional_details'] ?? '';

$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();
if (!$upload) {
    die('Upload not found');
}

$stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || !isset($row['json_data'])) {
    die('No OCR data available');
}
$data = json_decode($row['json_data'], true);

$jsonString = '';
if ($editedData !== null && trim($editedData) !== '') {
    $jsonString = trim($editedData);
    $stmt = $pdo->prepare('INSERT INTO ocr_edits (upload_id, edited_text, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$id, $jsonString]);
} else {
    $jsonString = $row['json_data'];
}

$businessData = json_decode($jsonString, true);
if (!$businessData) {
    die('Invalid business data');
}

$result = generateWebsiteFromData($businessData, $additional);
$html = $result['html_code'] ?? null;
if (!$html) {
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title><style>body{font-family:sans-serif;padding:2rem;}</style></head><body><pre>" . htmlspecialchars($jsonString) . "</pre></body></html>";
}

$dir = __DIR__ . '/generated_sites/';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$file = $dir . $id . '.html';
file_put_contents($file, $html);

$stmt = $pdo->prepare('INSERT INTO generated_sites (upload_id, html_code, public_url, created_at) VALUES (?, ?, ?, NOW())');
$stmt->execute([$id, $html, $file]);

header('Location: view_site.php?id=' . $id);
exit;
?>
