<?php
require 'config.php';
require_once 'domain_helper.php';

$domain = $_GET['domain'] ?? '';
$id = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
if ($domain === '') {
    die('Domain not specified');
}
if (!preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $domain)) {
    die('Invalid domain');
}

$result = registerDomain($domain);
if ($result) {
    $stmt = $pdo->prepare('INSERT INTO domain_registrations (domain, registrar_id, purchase_date, user_id) VALUES (?, ?, NOW(), NULL)');
    $stmt->execute([$domain, $result]);
    $success = true;
} else {
    $success = false;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8 text-center">
        <?php if ($success): ?>
            <h1 class="text-2xl font-bold mb-4">Domain Registered!</h1>
            <p class="mb-4">Your domain <?= htmlspecialchars($domain) ?> has been registered.</p>
        <?php else: ?>
            <h1 class="text-2xl font-bold mb-4">Registration Failed</h1>
            <p class="mb-4">Could not register <?= htmlspecialchars($domain) ?>.</p>
        <?php endif; ?>
        <a href="view_site.php?id=<?= $id ?>" class="text-blue-600">Return to site</a>
    </div>
</body>
</html>

