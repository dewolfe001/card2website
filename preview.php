<?php
require 'config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();

$ocr = null;
if ($upload) {
    $stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $ocrRow = $stmt->fetch();
    if ($ocrRow && isset($ocrRow['json_data'])) {
        $data = json_decode($ocrRow['json_data'], true);
        if ($data) {
            if (!empty($data['openai_text'])) {
                $ocr = $data['openai_text'];
            } elseif (!empty($data['raw_text'])) {
                $ocr = $data['raw_text'];
            }
        }
    }
}

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
        <?php if ($ocr): ?>
        <form action="generate.php" method="post" class="bg-white p-4 rounded shadow mb-4">
            <h2 class="text-lg font-semibold mb-2">Review &amp; Edit Text</h2>
            <input type="hidden" name="id" value="<?php echo $id; ?>" />
            <textarea name="edited_text" rows="10" class="w-full border p-2 text-sm"><?php echo htmlspecialchars($ocr); ?></textarea>
            <div class="text-center mt-4">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Generate Site</button>
            </div>
        </form>
        <?php else: ?>
        <p class="text-center">OCR and AI generation coming soon...</p>
        <?php endif; ?>
        <div class="text-center mt-6">
            <a href="index.php" class="text-blue-600">Upload another card</a>
        </div>
    </div>
</body>
</html>
