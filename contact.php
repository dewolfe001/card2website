<?php
require_once 'i18n.php';
?>
<!DOCTYPE html>
<html lang="<?=getAppLanguage()?>">
<head>
    <meta charset="UTF-8">
    <title><?=__('contact')?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include 'header.php'; ?>
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-4"><?=__('contact')?></h1>
        <p>Reach out to us at <a href="mailto:support@example.com" class="text-blue-600">support@example.com</a>.</p>
    </main>
    <?php include 'footer.php'; ?>
</body>
</html>
