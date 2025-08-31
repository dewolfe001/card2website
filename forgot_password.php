<?php
require 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if ($email) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if ($user) {
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare('INSERT INTO password_resets (user_id, token, expires_at) VALUES (?, ?, ?)')
                ->execute([$user['id'], $token, $expires]);
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'];
            $resetLink = $baseUrl . '/reset_password.php?token=' . $token;
            @mail($email, 'Password Reset', 'To reset your password, click the following link: ' . $resetLink);
        }
        $sent = true;
    } else {
        $error = 'Email required';
    }
}
?>
<!DOCTYPE html>
<html lang='en'>
<head>
<meta charset='UTF-8'>
<title>Forgot Password</title>
<script src='https://cdn.tailwindcss.com'></script>
</head>
<body class='bg-gray-100'>
<?php include 'header.php'; ?>
<div class='max-w-md mx-auto bg-white p-6 rounded shadow mt-8'>
<h2 class='text-xl mb-4'>Reset Password</h2>
<?php if (!empty($sent)) { echo '<p>If an account with that email exists, a reset link has been sent.</p>'; } else { ?>
<?php if (!empty($error)) echo '<p class="text-red-600">' . htmlspecialchars($error) . '</p>'; ?>
<form method='post'>
<input class='border p-2 w-full mb-4' type='email' name='email' placeholder='Email' required />
<button class='bg-blue-600 text-white px-4 py-2 rounded' type='submit'>Send Reset Link</button>
</form>
<?php } ?>
</div>
<?php include 'footer.php'; ?>
</body>
</html>

