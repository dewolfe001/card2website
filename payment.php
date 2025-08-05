<?php
require 'auth.php';
require_login();

$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
if ($domain === '') {
    die('Domain not specified');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Plan</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8 text-center">
        <h1 class="text-2xl font-bold mb-4">Register <?= htmlspecialchars($domain) ?></h1>
        <p class="mb-6">Select a hosting plan to continue to checkout.</p>
        <div class="flex justify-center space-x-4">
            <a href="subscribe.php?plan=monthly&domain=<?= urlencode($domain) ?>&upload_id=<?= $uploadId ?>" class="bg-blue-600 text-white px-4 py-2 rounded">$24.99 / Month</a>
            <a href="subscribe.php?plan=yearly&domain=<?= urlencode($domain) ?>&upload_id=<?= $uploadId ?>" class="bg-green-600 text-white px-4 py-2 rounded">$199 / Year</a>
        </div>
    </div>
</body>
</html>
