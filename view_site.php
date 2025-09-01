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
$html = str_replace('\n', "\n", $html);
$html = stripslashes($html);
// $html = str_replace('\\n', "\n", $html);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Generated Site</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .w-full {
            width: 100%;
        }
    
        .h-96 {
            min-height: 76vh;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include 'header.php'; ?>
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Your Generated Site</h1>
        <div class="bg-white p-4 rounded shadow mb-4">
            <button id="toggleEditor" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded" style="float: right; margin-bottom: 8px;">Edit Web Page</button>
            <iframe id="siteFrame" src="download.php?id=<?php echo $id; ?>&display=1" class="w-full h-96"></iframe>
        </div>
        <div class="text-center mt-4">
            <a href="download.php?id=<?= $id ?>" class="bg-green-600 text-white px-4 py-2 rounded">Download HTML</a>
            <a href="domain_search.php?id=<?= $id ?>" class="ml-4 bg-blue-600 text-white px-4 py-2 rounded">Find Domain</a>
        </div>
    </div>
    <?php include 'footer.php'; ?>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const iframe = document.getElementById('siteFrame');
            const btn = document.getElementById('toggleEditor');
            let editing = false;

            btn.addEventListener('click', function () {
                if (!editing) {
                    iframe.src = 'edit_site.php?id=<?php echo $id; ?>';
                    btn.textContent = 'View Web Page';
                } else {
                    iframe.src = 'download.php?id=<?php echo $id; ?>&display=1';
                    btn.textContent = 'Edit Web Page';
                }
                editing = !editing;
            });
        });
    </script>
</body>
</html>
