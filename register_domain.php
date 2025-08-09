<?php
require 'config.php';
require_once 'domain_helper.php';
require_once 'auth.php';

$domain = $_GET['domain'] ?? $_POST['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : (int)($_POST['upload_id'] ?? 0);
if ($domain === '') {
    die('Domain not specified');
}
if (!preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $domain)) {
    die('Invalid domain');
}

$success = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $registrant = [
        'first_name'  => trim($_POST['first_name'] ?? ''),
        'last_name'   => trim($_POST['last_name'] ?? ''),
        'address1'    => trim($_POST['address1'] ?? ''),
        'city'        => trim($_POST['city'] ?? ''),
        'state'       => trim($_POST['state'] ?? ''),
        'postal_code' => trim($_POST['postal_code'] ?? ''),
        'country'     => trim($_POST['country'] ?? ''),
        'phone'       => trim($_POST['phone'] ?? ''),
        'email'       => trim($_POST['email'] ?? '')
    ];

    $result = registerDomain($domain, $registrant);
    if ($result) {
        $stmt = $pdo->prepare('INSERT INTO domain_registrations (domain, registrar_id, purchase_date, user_id, registrant_first_name, registrant_last_name, registrant_address1, registrant_city, registrant_state, registrant_postal_code, registrant_country, registrant_phone, registrant_email, domain_id, order_id, transaction_id, charged_amount) VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $domain,
            $result['DomainID'] ?? null,
            current_user_id(),
            $registrant['first_name'],
            $registrant['last_name'],
            $registrant['address1'],
            $registrant['city'],
            $registrant['state'],
            $registrant['postal_code'],
            $registrant['country'],
            $registrant['phone'],
            $registrant['email'],
            $result['DomainID'] ?? null,
            $result['OrderID'] ?? null,
            $result['TransactionID'] ?? null,
            $result['ChargedAmount'] ?? null
        ]);
        $success = true;
    } else {
        $success = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Domain Registration</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
<div class="container mx-auto p-8">
    <?php if ($success === true): ?>
        <div class="text-center">
            <h1 class="text-2xl font-bold mb-4">Domain Registered!</h1>
            <p class="mb-4">Your domain <?= htmlspecialchars($domain) ?> has been registered.</p>
            <a href="view_site.php?id=<?= $uploadId ?>" class="text-blue-600">Return to site</a>
        </div>
    <?php elseif ($success === false): ?>
        <div class="text-center">
            <h1 class="text-2xl font-bold mb-4">Registration Failed</h1>
            <p class="mb-4">Could not register <?= htmlspecialchars($domain) ?>.</p>
            <a href="register_domain.php?domain=<?= urlencode($domain) ?>&upload_id=<?= $uploadId ?>" class="text-blue-600">Try Again</a>
        </div>
    <?php else: ?>
        <h1 class="text-2xl font-bold mb-4 text-center">Register <?= htmlspecialchars($domain) ?></h1>
        <form method="post" class="max-w-lg mx-auto bg-white p-6 rounded shadow">
            <input type="hidden" name="domain" value="<?= htmlspecialchars($domain) ?>">
            <input type="hidden" name="upload_id" value="<?= $uploadId ?>">
            <input class="border p-2 w-full mb-2" type="text" name="first_name" placeholder="First Name" required>
            <input class="border p-2 w-full mb-2" type="text" name="last_name" placeholder="Last Name" required>
            <input class="border p-2 w-full mb-2" type="text" name="address1" placeholder="Address" required>
            <input class="border p-2 w-full mb-2" type="text" name="city" placeholder="City" required>
            <input class="border p-2 w-full mb-2" type="text" name="state" placeholder="State/Province" required>
            <input class="border p-2 w-full mb-2" type="text" name="postal_code" placeholder="Postal Code" required>
            <input class="border p-2 w-full mb-2" type="text" name="country" placeholder="Country" required>
            <input class="border p-2 w-full mb-2" type="text" name="phone" placeholder="Phone" required>
            <input class="border p-2 w-full mb-4" type="email" name="email" placeholder="Email" required>
            <button class="bg-blue-600 text-white px-4 py-2 rounded" type="submit">Register Domain</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>