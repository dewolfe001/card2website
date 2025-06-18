<?php
require 'config.php';
require_once 'openai_helper.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['card_image']) || $_FILES['card_image']['error'] !== UPLOAD_ERR_OK) {
        die('Upload error.');
    }

    $allowed = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($_FILES['card_image']['type'], $allowed)) {
        die('Invalid file type.');
    }

    $targetDir = __DIR__ . '/uploads/';
    $filename = time() . '_' . basename($_FILES['card_image']['name']);
    $targetFile = $targetDir . $filename;

    if (!move_uploaded_file($_FILES['card_image']['tmp_name'], $targetFile)) {
        die('Failed to move uploaded file.');
    }

    // Insert into database
    $stmt = $pdo->prepare('INSERT INTO uploads (filename, created_at) VALUES (?, NOW())');
    $stmt->execute([$filename]);
    $uploadId = $pdo->lastInsertId();

    // Run OCR on the uploaded image using Tesseract
    $ocrText = null;
    $cmd = 'tesseract ' . escapeshellarg($targetFile) . ' stdout 2>/dev/null';
    $output = shell_exec($cmd);
    if ($output !== null) {
        $ocrText = trim($output);
    }

    // Optionally send the image to OpenAI for additional interpretation
    $openaiText = sendImageToOpenAI($targetFile);

    // Store OCR result as JSON
    if ($ocrText !== null || $openaiText !== null) {
        $json = [];
        if ($ocrText !== null && $ocrText !== '') {
            $json['raw_text'] = $ocrText;
        }
        if ($openaiText !== null && $openaiText !== '') {
            $json['openai_text'] = $openaiText;
        }
        $stmt = $pdo->prepare('INSERT INTO ocr_data (upload_id, json_data, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$uploadId, json_encode($json)]);
    }

    header('Location: preview.php?id=' . $uploadId);
    exit;
}
?>
