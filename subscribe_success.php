<?php
require 'config.php';
require 'auth.php';
require 'stripe_helper.php';
require_login();

$userId = current_user_id();
$sessionId = $_GET['session_id'] ?? null;
if ($sessionId) {
    $session = stripeRequest('GET', 'checkout/sessions/' . $sessionId);
    if ($session && isset($session['subscription'], $session['customer'])) {
        $stmt = $pdo->prepare('INSERT INTO billing_subscriptions (user_id, stripe_customer_id, stripe_subscription_id, plan_type, status) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $session['customer'], $session['subscription'], 'hosting', 'active']);
    }
}
header('Location: account.php');
exit;
?>
