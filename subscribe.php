<?php
require 'config.php';
require 'auth.php';
require 'stripe_helper.php';
require_login();

$userId = current_user_id();

$stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
$stmt->execute([$userId]);
$user = $stmt->fetch();
if (!$user) {
    die('User not found');
}

$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
$plan = $_GET['plan'] ?? 'monthly';
if (!in_array($plan, ['monthly', 'yearly'])) {
    $plan = 'monthly';
}

$priceId = getenv($plan === 'yearly' ? 'STRIPE_PRICE_ID_YEARLY' : 'STRIPE_PRICE_ID_MONTHLY');
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
$successUrl = $baseUrl . '/subscribe_success.php?domain=' . urlencode($domain) . '&upload_id=' . $uploadId;
$cancelUrl = $baseUrl . '/payment.php?domain=' . urlencode($domain) . '&upload_id=' . $uploadId;
$session = createCheckoutSession($user['email'], $priceId, $successUrl, $cancelUrl, (string)$userId);
if ($session && isset($session['url'])) {
    header('Location: ' . $session['url']);
    exit;
}

die('Unable to create checkout session');
?>
