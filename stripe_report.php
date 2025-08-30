<?php
require 'config.php';
require 'stripe_helper.php';
require_once 'whm_helper.php';

header('Content-Type: application/json');

$report = [];
$stmt = $pdo->query('SELECT id, domain, stripe_subscription_id, stripe_customer_id, status FROM billing_subscriptions');
$subs = $stmt->fetchAll();

foreach ($subs as $sub) {
    $stripeSub = stripeRequest('GET', 'subscriptions/' . $sub['stripe_subscription_id']);
    if (!$stripeSub || !isset($stripeSub['status'])) {
        continue;
    }
    $newStatus = $stripeSub['status'];
    if ($newStatus !== $sub['status']) {
        $upd = $pdo->prepare('UPDATE billing_subscriptions SET status = ?, updated_at = NOW() WHERE id = ?');
        $upd->execute([$newStatus, $sub['id']]);
        if ($newStatus !== 'active' && !empty($sub['domain'])) {
            // Suspend hosting when subscription is not active
            whmSuspendAccount($sub['domain']);
        }
    }
    $report[] = [
        'subscription_id' => $sub['stripe_subscription_id'],
        'status' => $newStatus,
        'domain' => $sub['domain']
    ];
}

echo json_encode($report);
?>
