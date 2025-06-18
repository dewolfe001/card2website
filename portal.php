<?php
require 'config.php';
require 'auth.php';
require 'stripe_helper.php';
require_login();

$userId = current_user_id();
$stmt = $pdo->prepare('SELECT stripe_customer_id FROM billing_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$sub = $stmt->fetch();
if (!$sub) {
    header('Location: account.php');
    exit;
}

$returnUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/account.php';
$portal = createBillingPortalSession($sub['stripe_customer_id'], $returnUrl);
if ($portal && isset($portal['url'])) {
    header('Location: ' . $portal['url']);
    exit;
}

die('Unable to create portal session');
?>
