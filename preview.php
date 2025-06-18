<?php
require 'config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();

if (!$upload) {
    die('Upload not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview - BusinessCard2Website</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Preview</h1>
        <div class="text-center mb-6">
            <img src="uploads/<?php echo htmlspecialchars($upload['filename']); ?>" class="mx-auto max-w-xs" alt="Uploaded Card">
        </div>
        <p class="text-center">OCR and AI generation coming soon...</p>
        <div class="text-center mt-6">
            <a href="index.php" class="text-blue-600">Upload another card</a>
        </div>
    </div>
</body>
</html>
