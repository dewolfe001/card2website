<?php
require 'config.php';
require_once 'domain_helper.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT edited_text FROM ocr_edits WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
$text = $row['edited_text'] ?? '';
if ($text === '') {
    $stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row && isset($row['json_data'])) {
        $data = json_decode($row['json_data'], true);
        $text = $data['openai_text'] ?? $data['raw_text'] ?? '';
    }
}
if ($text === '') {
    die('No business info found');
}

$suggestions = suggestDomainNames($text, 5);
$availability = checkDomainAvailability($suggestions);

$stmt = $pdo->prepare('INSERT INTO domain_suggestions (upload_id, suggestion, availability, checked_at) VALUES (?, ?, ?, NOW())');
foreach ($availability as $dom => $avail) {
    $stmt->execute([$id, $dom, is_null($avail) ? null : ($avail ? 1 : 0)]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Suggestions</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Domain Suggestions</h1>
        <table class="min-w-full bg-white rounded shadow">
            <thead>
                <tr><th class="p-2 text-left">Domain</th><th class="p-2">Status</th><th class="p-2"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($availability as $dom => $avail): ?>
                <tr class="border-t">
                    <td class="p-2"><?= htmlspecialchars($dom) ?></td>
                    <td class="p-2 text-center">
                        <?php if ($avail === null): ?>Unknown<?php elseif ($avail): ?>Available<?php else: ?>Taken<?php endif; ?>
                    </td>
                    <td class="p-2 text-center">
                        <?php if ($avail): ?>
                        <a href="register_domain.php?domain=<?= urlencode($dom) ?>&upload_id=<?= $id ?>" class="bg-green-600 text-white px-2 py-1 rounded">Register</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="text-center mt-6">
            <a href="view_site.php?id=<?= $id ?>" class="text-blue-600">Back to site</a>
        </div>
    </div>
</body>
</html>

