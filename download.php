<?php
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$file = __DIR__ . '/generated_sites/' . $id . '.html';

if (!file_exists($file)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// If display flag is set, show the HTML in the browser instead of forcing download
if (isset($_GET['display']) && (int)$_GET['display'] === 1) {
    header('Content-Type: text/html');
    readfile($file);
    exit;
}

// Default behaviour: force download of the generated site
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="site_' . $id . '.html"');
readfile($file);
exit;
?>
