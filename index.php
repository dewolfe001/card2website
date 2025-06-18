<?php
// Landing page with upload form
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BusinessCard2Website</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8">
        <h1 class="text-3xl font-bold mb-4 text-center">BusinessCard2Website</h1>
        <p class="mb-6 text-center">Upload a photo of your business card to generate a one-page website.</p>
        <form action="upload.php" method="post" enctype="multipart/form-data" class="max-w-xl mx-auto bg-white p-6 rounded shadow">
            <div class="mb-4">
                <input type="file" name="card_image" accept="image/*,application/pdf" required class="w-full border p-2" />
            </div>
            <div class="text-center">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Upload</button>
            </div>
        </form>
    </div>
</body>
</html>
