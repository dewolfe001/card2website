<?php
require 'config.php';
require 'auth.php';

$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;
if ($domain === '') {
    die('Domain not specified');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $plan = $_POST['plan'] ?? 'monthly';
    if (!in_array($plan, ['monthly', 'yearly'])) {
        $plan = 'monthly';
    }
    if ($email && $password) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email already registered. Please login.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
            $insert->execute([$email, $hash]);
            $_SESSION['user_id'] = $pdo->lastInsertId();
            $_SESSION['is_admin'] = 0;
            header('Location: subscribe.php?plan=' . urlencode($plan) . '&domain=' . urlencode($domain) . '&upload_id=' . $uploadId);
            exit;
        }
    } else {
        $error = 'Email and password required';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Plan</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8 max-w-md">
        <h1 class="text-2xl font-bold mb-4 text-center">Register <?= htmlspecialchars($domain) ?></h1>
        <?php if (!empty($error)) echo '<p class="text-red-600 mb-4">' . htmlspecialchars($error) . '</p>'; ?>
        <form method="post" class="bg-white p-6 rounded shadow">
            <input id="email" class="border p-2 w-full mb-2" type="email" name="email" placeholder="Email" required />
            <p id="email-msg"></p>
            <input class="border p-2 w-full mb-4" type="password" name="password" placeholder="Password" required />
            <div class="mb-4">
                <label class="mr-4"><input type="radio" name="plan" value="monthly" checked /> $24.99 / Month</label>
                <label><input type="radio" name="plan" value="yearly" /> $199 / Year</label>
            </div>
            <button class="bg-blue-600 text-white px-4 py-2 rounded w-full" type="submit">Checkout</button>
        </form>
        <?php $next = urlencode('payment.php?domain=' . $domain . '&upload_id=' . $uploadId); ?>
        <div class="text-center mt-4">
            <a href="login.php?next=<?= $next ?>" class="text-blue-600">Returning User? Login</a>
        </div>
    </div>
    <script>
    document.getElementById('email').addEventListener('blur', function() {
        fetch('check_email.php?email=' + encodeURIComponent(this.value))
            .then(r => r.json())
            .then(data => {
                const msg = document.getElementById('email-msg');
                if (data.exists) {
                    msg.textContent = 'Email already registered. Please use Returning User to login.';
                    msg.className = 'text-red-600 mb-2';
                } else {
                    msg.textContent = '';
                    msg.className = '';
                }
            });
    });
    </script>
</body>
</html>

