<?php
require 'config.php';
require 'stripe_helper.php';

$payload = @file_get_contents('php://input');
$event = json_decode($payload, true);
if (!$event || !isset($event['type'])) {
    http_response_code(400);
    exit;
}

switch ($event['type']) {
    case 'customer.subscription.deleted':
    case 'customer.subscription.updated':
        $sub = $event['data']['object'];
        $status = $sub['status'];
        $subscriptionId = $sub['id'];
        $customerId = $sub['customer'];
        $stmt = $pdo->prepare('UPDATE billing_subscriptions SET status = ?, updated_at = NOW() WHERE stripe_subscription_id = ? AND stripe_customer_id = ?');
        $stmt->execute([$status, $subscriptionId, $customerId]);
        break;
}

echo 'ok';
?>
