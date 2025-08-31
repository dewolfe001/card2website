<?php
require 'config.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if ($token) {
    $stmt = $pdo->prepare('SELECT user_id, expires_at FROM password_resets WHERE token = ?');
    $stmt->execute([$token]);
    $reset = $stmt->fetch();
    if (!$reset || strtotime($reset['expires_at']) < time()) {
        $error = 'Invalid or expired token';
    }
} else {
    $error = 'Invalid token';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $password = $_POST['password'] ?? '';
    if ($password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([$hash, $reset['user_id']]);
        $pdo->prepare('DELETE FROM password_resets WHERE token = ?')
            ->execute([$token]);
        $success = true;
    } else {
        $error = 'Password required';
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<title>Set New Password</title>
<script src='https://cdn.tailwindcss.com'></script>
</head>
<body class='bg-gray-100'>
<?php include 'header.php'; ?>
<div class='max-w-md mx-auto bg-white p-6 rounded shadow mt-8'>
<h2 class='text-xl mb-4'>Set New Password</h2>
<?php if (!empty($success)) { echo '<p>Password updated. <a class="text-blue-600" href="login.php">Login</a></p>'; } elseif (!empty($error)) { echo '<p class="text-red-600">' . htmlspecialchars($error) . '</p>'; } ?>
<?php if (empty($success) && empty($error)) { ?>
<form method='post'>
<input class='border p-2 w-full mb-4' type='password' name='password' placeholder='New Password' required />
<input type='hidden' name='token' value='<?= htmlspecialchars($token) ?>' />
<button class='bg-blue-600 text-white px-4 py-2 rounded' type='submit'>Reset Password</button>
</form>
<?php } ?>
</div>
<?php include 'footer.php'; ?>
</body>
</html>

