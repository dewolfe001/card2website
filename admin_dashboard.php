<?php
require 'config.php';
require 'auth.php';
require_admin();

// Fetch all users
$stmt = $pdo->query('SELECT id, email, name, created_at, is_admin FROM users ORDER BY created_at DESC');
$users = $stmt->fetchAll();

// Fetch subscription status for each user
$subs = [];
if ($users) {
    $ids = array_column($users, 'id');
    $in  = str_repeat('?,', count($ids) - 1) . '?';
    $subStmt = $pdo->prepare("SELECT user_id, status FROM billing_subscriptions WHERE id IN (SELECT MAX(id) FROM billing_subscriptions WHERE user_id IN ($in) GROUP BY user_id)");
    $subStmt->execute($ids);
    foreach ($subStmt->fetchAll() as $row) {
        $subs[$row['user_id']] = $row['status'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
<?php include 'header.php'; ?>
<div class="max-w-4xl mx-auto bg-white p-6 rounded shadow mt-8">
<h2 class="text-2xl mb-4">Admin Dashboard</h2>
<p class="mb-4"><a class="text-blue-600" href="account.php">Back to account</a></p>
<table class="min-w-full bg-white border">
<thead>
<tr>
<th class="border px-2 py-1 text-left">ID</th>
<th class="border px-2 py-1 text-left">Email</th>
<th class="border px-2 py-1 text-left">Name</th>
<th class="border px-2 py-1 text-left">Admin</th>
<th class="border px-2 py-1 text-left">Registered</th>
<th class="border px-2 py-1 text-left">Subscription</th>
<th class="border px-2 py-1 text-left">Actions</th>
</tr>
</thead>
<tbody>
<?php foreach ($users as $u): ?>
<tr>
<td class="border px-2 py-1"><?php echo $u['id']; ?></td>
<td class="border px-2 py-1"><?php echo htmlspecialchars($u['email']); ?></td>
<td class="border px-2 py-1"><?php echo htmlspecialchars($u['name']); ?></td>
<td class="border px-2 py-1 text-center"><?php echo $u['is_admin'] ? 'yes' : 'no'; ?></td>
<td class="border px-2 py-1"><?php echo $u['created_at']; ?></td>
<td class="border px-2 py-1"><?php echo htmlspecialchars($subs[$u['id']] ?? 'none'); ?></td>
<td class="border px-2 py-1">
<form method="post" action="admin_delete_user.php" onsubmit="return confirm('Delete this user?');">
<input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
<button class="bg-red-600 text-white px-2 py-1 rounded" type="submit">Delete</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
