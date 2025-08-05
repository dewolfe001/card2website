<?php
require 'config.php';
require 'auth.php';

$next = $_GET['next'] ?? 'account.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $next = $_POST['next'] ?? $next;
    $stmt = $pdo->prepare('SELECT id, password_hash, is_admin FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_admin'] = $user['is_admin'];
        header('Location: ' . $next);
        exit;
    } else {
        $error = 'Invalid credentials';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Login</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
<div class="max-w-md mx-auto bg-white p-6 rounded shadow">
<h2 class="text-xl mb-4">Login</h2>
<?php if (!empty($error)) echo '<p class="text-red-600">' . htmlspecialchars($error) . '</p>'; ?>
<form method="post">
<input class="border p-2 w-full mb-2" type="email" name="email" placeholder="Email" required />
<input class="border p-2 w-full mb-4" type="password" name="password" placeholder="Password" required />
<input type="hidden" name="next" value="<?= htmlspecialchars($next) ?>" />
<button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Login</button>
</form>
<p class="mt-2">No account? <a class="text-blue-600" href="register.php">Register</a></p>
</div>
</body>
</html>
