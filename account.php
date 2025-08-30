<?php
require 'config.php';
require 'auth.php';
require_login();

$userId = current_user_id();
$stmt = $pdo->prepare('SELECT * FROM billing_subscriptions WHERE user_id = ? ORDER BY id DESC');
$stmt->execute([$userId]);
$subs = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Account</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
<?php include 'header.php'; ?>
<div class="max-w-4xl mx-auto p-8">
    <div class="bg-white p-6 rounded shadow">
        <h2 class="text-xl mb-4">Account</h2>
        <p><a class="text-blue-600" href="logout.php">Logout</a></p>
        <?php if (current_user_is_admin()): ?>
        <p class="mt-2"><a class="text-blue-600" href="admin_dashboard.php">Admin Dashboard</a></p>
        <?php endif; ?>
        <?php if ($subs): ?>
        <table class="w-full mt-4 text-left">
            <thead>
                <tr><th class="p-2">Domain</th><th class="p-2">Status</th><th class="p-2">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach ($subs as $sub): ?>
                <tr class="border-t">
                    <td class="p-2"><?= htmlspecialchars($sub['domain']) ?></td>
                    <td class="p-2"><?= htmlspecialchars($sub['status']) ?></td>
                    <td class="p-2">
                        <form action="portal.php" method="post" class="inline">
                            <input type="hidden" name="customer_id" value="<?= htmlspecialchars($sub['stripe_customer_id']) ?>" />
                            <button class="text-blue-600 mr-2" type="submit">Manage Billing</button>
                        </form>
                        <?php if (!empty($sub['domain'])): ?>
                        <a class="text-blue-600 mr-2" href="http://<?= htmlspecialchars($sub['domain']) ?>" target="_blank">Visit</a>
                        <?php endif; ?>
                        <a class="text-red-600" href="cancel_subscription.php?id=<?= $sub['id'] ?>">Cancel</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p>You do not have any subscriptions.</p>
        <form action="subscribe.php" method="post" class="mt-4">
            <button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Subscribe</button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
