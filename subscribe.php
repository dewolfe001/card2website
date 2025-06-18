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

$priceId = getenv('STRIPE_PRICE_ID');
$successUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/subscribe_success.php';
$cancelUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/account.php';
$session = createCheckoutSession($user['email'], $priceId, $successUrl, $cancelUrl);
if ($session && isset($session['url'])) {
    header('Location: ' . $session['url']);
    exit;
}

die('Unable to create checkout session');
?>
