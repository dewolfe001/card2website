<?php
require_once 'config.php';
require_once 'i18n.php';

$adminEmail = getenv('ADMIN_EMAIL') ?: 'support@example.com';
$siteKey = getenv('RECAPTCHA_SITE_KEY') ?: '';
$secretKey = getenv('RECAPTCHA_SECRET_KEY') ?: '';

$messageSent = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $token = $_POST['g-recaptcha-response'] ?? '';

    if (!$name || !$email || !$message) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        $verify = file_get_contents(
            'https://www.google.com/recaptcha/api/siteverify?secret=' . urlencode($secretKey) .
            '&response=' . urlencode($token)
        );
        $captchaSuccess = json_decode($verify, true);
        if (empty($captchaSuccess['success'])) {
            $error = 'Recaptcha verification failed.';
        } else {
            $subject = 'Contact Form Submission';
            $body = "Name: $name\nEmail: $email\n\n$message";
            @mail($adminEmail, $subject, $body);
            $messageSent = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?=getAppLanguage()?>">
<head>
    <meta charset="UTF-8">
    <title><?=__('contact')?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include 'header.php'; ?>
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-4"><?=__('contact')?></h1>
        <p class="mb-4">Reach out to us at <a href="mailto:<?=htmlspecialchars($adminEmail)?>" class="text-blue-600"><?=htmlspecialchars($adminEmail)?></a> or use the form below.</p>
        <?php if($messageSent): ?>
            <p class="text-green-600 mb-4">Thank you for contacting us. We'll be in touch soon.</p>
        <?php endif; ?>
        <?php if($error): ?>
            <p class="text-red-600 mb-4"><?=htmlspecialchars($error)?></p>
        <?php endif; ?>
        <form method="post" class="max-w-md bg-white p-6 rounded shadow">
            <input type="text" name="name" placeholder="Name" required class="border p-2 w-full mb-4" value="<?=htmlspecialchars($_POST['name'] ?? '')?>">
            <input type="email" name="email" placeholder="Email" required class="border p-2 w-full mb-4" value="<?=htmlspecialchars($_POST['email'] ?? '')?>">
            <textarea name="message" placeholder="Message" required class="border p-2 w-full mb-4" rows="5"><?=htmlspecialchars($_POST['message'] ?? '')?></textarea>
            <div class="mb-4">
                <div class="g-recaptcha" data-sitekey="<?=htmlspecialchars($siteKey)?>"></div>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Send</button>
        </form>
    </main>
    <?php include 'footer.php'; ?>
</body>
</html>
