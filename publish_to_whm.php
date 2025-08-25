<?php
require 'config.php';
require_once 'whm_helper.php';

$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
if ($domain === '' || $uploadId === 0) {
    die('Domain or upload ID missing');
}

// create username and password
$username = substr(preg_replace('/[^a-z0-9]/i', '', explode('.', $domain)[0]), 0, 8);
$password = bin2hex(random_bytes(8));

$create  = createWhmAccount($username, $domain, $password);
$created = $create['ok'] && (($create['data']['metadata']['result'] ?? 0) == 1);

error_log(__LINE__." - ".print_r($create, TRUE));
error_log(__LINE__." - ".print_r($created, TRUE));

if (!$created) {
    error_log('WHM account creation failed: ' . ($create['error'] ?? 'unknown error'));
}

$uploadSuccess = false;
if ($created) {
    $file = __DIR__ . '/generated_sites/' . $uploadId . '.html';
    if (file_exists($file)) {
        sleep(2);
        $uploadSuccess = uploadToCpanel($username, $password, $file, 'public_html/index.html', '', $domain);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Publish Status</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="p-8">
<h1 class="text-2xl font-bold mb-4">Publish Status</h1>
<ul class="list-disc ml-6">
<li>WHM account creation: <?php echo $created ? 'Success' : 'Failed'; ?></li>
<li>File upload to cPanel: <?php echo $uploadSuccess ? 'Success' : 'Failed'; ?></li>
</ul>
</body>
</html>
