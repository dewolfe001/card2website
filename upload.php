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

    // Extract text using OpenAI's vision capabilities
    $ocrError = null;
    $ocrText = sendImageToOpenAI($targetFile, $ocrError);

    // Analyze the business card with OpenAI to get structured data
    $analysisError = null;
    $analysis = analyzeBusinessCardStructured($targetFile, $analysisError);

    $json = [];
    if ($analysis) {
        $json = $analysis;
    }
    if ($ocrText !== null && $ocrText !== '') {
        $json['openai_text'] = $ocrText;
    }

    if (!$analysis && !$ocrText) {
        $errorMsg = 'OpenAI errors: ' . ($ocrError ?? 'none') . ' | ' . ($analysisError ?? 'none');
        error_log($errorMsg);
    }

    if (!empty($json)) {
        $stmt = $pdo->prepare('INSERT INTO ocr_data (upload_id, json_data, created_at) VALUES (?, ?, NOW())');
        $stmt->execute([$uploadId, json_encode($json)]);
    }

    $hasError = !$analysis && !$ocrText;
    $redirect = 'preview.php?id=' . $uploadId;
    if ($hasError) {
        $redirect .= '&error=1';
    }
    header('Location: ' . $redirect);
    exit;
}
?>
