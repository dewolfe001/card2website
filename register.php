<?php
require 'config.php';
require 'auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $name = trim($_POST['name'] ?? '');
    if ($email && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)');
        try {
            $stmt->execute([$email, $hash, $name]);
            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            $error = 'Registration failed: ' . $e->getMessage();
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
<title>Register</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 p-8">
<?php include 'header.php'; ?>
<div class="max-w-md mx-auto bg-white p-6 rounded shadow mt-8">
    <h2 class="text-xl mb-4">Register</h2>
    <?php if (!empty($error)) echo '<p class="text-red-600">' . htmlspecialchars($error) . '</p>'; ?>
    <form method="post">
        <input class="border p-2 w-full mb-2" type="text" name="name" placeholder="Name" />
        <input class="border p-2 w-full mb-2" type="email" name="email" placeholder="Email" required />
        <input class="border p-2 w-full mb-4" type="password" name="password" placeholder="Password" required />
        <button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Register</button>
    </form>
    <p class="mt-2">Already have an account? <a class="text-blue-600" href="login.php">Login</a></p>
</div>
<?php include 'footer.php'; ?>
</body>
</html>
