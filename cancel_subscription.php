<?php
require 'config.php';
require 'auth.php';
require 'stripe_helper.php';
require_once 'whm_helper.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT stripe_subscription_id, domain FROM billing_subscriptions WHERE id = ? AND user_id = ?');
    $stmt->execute([$id, current_user_id()]);
    $sub = $stmt->fetch();
    if ($sub) {
        stripeRequest('POST', 'subscriptions/' . $sub['stripe_subscription_id'] . '/cancel');
        $upd = $pdo->prepare('UPDATE billing_subscriptions SET status = ? WHERE id = ?');
        $upd->execute(['canceled', $id]);
        if (!empty($sub['domain'])) {
            whmSuspendAccount($sub['domain']);
        }
    }
}
header('Location: account.php');
exit;
?>
