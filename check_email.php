<?php
require 'config.php';

header('Content-Type: application/json');
$email = trim($_GET['email'] ?? '');
$exists = false;
if ($email !== '') {
    $stmt = $pdo->prepare('SELECT 1 FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $exists = $stmt->fetchColumn() ? true : false;
}
echo json_encode(['exists' => $exists]);
?>

