<?php
require 'config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT html_code FROM generated_sites WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$site = $stmt->fetch();
if (!$site) {
    die('Generated site not found');
}
$html = $site['html_code'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Generated Site</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Your Generated Site</h1>
        <div class="bg-white p-4 rounded shadow mb-4">
            <iframe srcdoc="<?= htmlspecialchars($html, ENT_QUOTES) ?>" class="w-full h-96"></iframe>
        </div>
        <div class="text-center mt-4">
            <a href="download.php?id=<?= $id ?>" class="bg-green-600 text-white px-4 py-2 rounded">Download HTML</a>
            <a href="domain_search.php?id=<?= $id ?>" class="ml-4 bg-blue-600 text-white px-4 py-2 rounded">Find Domain</a>
        </div>
    </div>
</body>
</html>
