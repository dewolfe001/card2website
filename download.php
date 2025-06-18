<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$file = __DIR__ . '/generated_sites/' . $id . '.html';
if (!file_exists($file)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="site_' . $id . '.html"');
readfile($file);
exit;
?>
