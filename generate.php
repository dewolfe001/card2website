<?php
require 'config.php';
require_once 'openai_helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$editedText = $_POST['edited_text'] ?? null;

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

$text = '';
if ($editedText !== null && trim($editedText) !== '') {
    $text = trim($editedText);
    $stmt = $pdo->prepare('INSERT INTO ocr_edits (upload_id, edited_text, created_at) VALUES (?, ?, NOW())');
    $stmt->execute([$id, $text]);
} else {
    $text = $data['openai_text'] ?? $data['raw_text'] ?? '';
}
if ($text === '') {
    die('No text found');
}

// Determine NAICS classification using OpenAI
$naics = classifyNaics($text);
if (is_array($naics)) {
    $stmt = $pdo->prepare('INSERT INTO naics_classifications (upload_id, naics_code, title, description, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$id, $naics['code'] ?? null, $naics['title'] ?? null, $naics['description'] ?? null]);
    $naicsContext = $naics['title'] . ' - ' . $naics['description'];
} else {
    $naicsContext = '';
}

$prompt = "Create a simple responsive HTML page for this business information:\n" . $text;
if ($naicsContext !== '') {
    $prompt .= "\nBusiness classification: " . $naicsContext;
}
$prompt .= "\nReturn only the HTML.";
$html = generateHtmlWithOpenAI($prompt);
if (!$html) {
    // Fallback simple template
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title><style>body{font-family:sans-serif;padding:2rem;}</style></head><body><pre>" . htmlspecialchars($text) . "</pre></body></html>";
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
