<?php
require 'config.php';

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

    header('Location: preview.php?id=' . $uploadId);
    exit;
}
?>
