<?php
require 'config.php';
require 'auth.php';
require 'stripe_helper.php';

$userId = current_user_id();
$sessionId = $_GET['session_id'] ?? null;
$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
if ($sessionId) {
    $session = stripeRequest('GET', 'checkout/sessions/' . $sessionId);
    if ($session && isset($session['subscription'], $session['customer'])) {
        if (!$userId && isset($session['client_reference_id'])) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([$session['client_reference_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['user_id'] = $user['id'];
                $userId = $user['id'];
            }
        }
        if ($userId) {
            $stmt = $pdo->prepare('INSERT INTO billing_subscriptions (user_id, stripe_customer_id, stripe_subscription_id, plan_type, domain, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$userId, $session['customer'], $session['subscription'], 'hosting', $domain, 'active']);
        }
    }
}
if ($domain !== '' && $uploadId > 0) {
    header('Location: register_domain.php?domain=' . urlencode($domain) . '&upload_id=' . $uploadId);
} else {
    header('Location: account.php');
}
exit;
?>
