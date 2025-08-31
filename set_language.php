<?php
require_once 'i18n.php';

$app = $_POST['app_lang'] ?? $_GET['app_lang'] ?? getAppLanguage();
$output = $_POST['output_lang'] ?? $_GET['output_lang'] ?? getOutputLanguage();
$swap = isset($_POST['swap']) || isset($_GET['swap']);

if ($swap) {
    $tmp = $app;
    $app = $output;
    $output = $tmp;
}

setAppLanguage($app);
setOutputLanguage($output);

$redirect = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header('Location: ' . $redirect);
exit;
