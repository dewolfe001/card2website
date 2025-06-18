<?php
require 'config.php';
require 'auth.php';
require_login();

$userId = current_user_id();

$stmt = $pdo->prepare('SELECT * FROM billing_subscriptions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$userId]);
$sub = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-xl mx-auto bg-white p-6 rounded shadow">
<h2 class="text-xl mb-4">Account</h2>
<p><a class="text-blue-600" href="logout.php">Logout</a></p>
<?php if ($sub): ?>
<p class="mt-4">Subscription status: <strong><?= htmlspecialchars($sub['status']) ?></strong></p>
<?php if ($sub['status'] === 'active'): ?>
<form action="portal.php" method="post" class="mt-4">
<button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Manage Billing</button>
</form>
<?php else: ?>
<form action="subscribe.php" method="post" class="mt-4">
<button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Subscribe</button>
</form>
<?php endif; ?>
<?php else: ?>
<p>You do not have an active subscription.</p>
<form action="subscribe.php" method="post" class="mt-4">
<button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Subscribe</button>
</form>
<?php endif; ?>
</div>
</body>
</html>
