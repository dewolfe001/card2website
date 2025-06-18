<?php
require 'config.php';
require 'auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    $id = (int)$_POST['user_id'];
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    // Optionally delete subscriptions
    $stmt = $pdo->prepare('DELETE FROM billing_subscriptions WHERE user_id = ?');
    $stmt->execute([$id]);
}
header('Location: admin_dashboard.php');
exit;
?>
