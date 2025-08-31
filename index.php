<?php
require_once 'i18n.php';
// Landing page with upload form styled to match the demo design
?>
<!DOCTYPE html>
<html lang="<?=getAppLanguage()?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?=__('business_card_to_website')?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Poppins:wght@600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Poppins', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include 'header.php'; ?>
    <header class="bg-[#1a365d] text-white py-8 mb-12">
        <h1 class="text-3xl font-display font-bold text-center"><?=__('business_card_to_website')?></h1>
        <p class="mt-2 text-center text-[#bcccdc]"><?=__('tagline')?></p>
    </header>
    <main class="container mx-auto px-4">
        <section class="flex flex-col items-center gap-8">
            <div class="flex items-center justify-center gap-6">
                <img src="demo/images/placeholder-card.svg" alt="Business card example" class="w-64 h-auto shadow-lg rounded" />
                <svg class="w-10 h-10 text-[#10b981]" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                <img src="demo/images/placeholder-website.svg" alt="Generated website example" class="w-72 h-auto rounded border" />
            </div>
            <form action="upload.php" method="post" enctype="multipart/form-data" class="w-full max-w-md bg-white p-6 rounded shadow">
                <h2 class="text-xl font-display font-semibold mb-4 text-center"><?=__('upload_card')?></h2>
                <input type="file" name="card_image" accept="image/*,application/pdf" required class="w-full border p-2 mb-4" />
                <button type="submit" class="w-full bg-[#10b981] hover:bg-[#059669] text-white py-2 rounded"><?=__('generate_website')?></button>
            </form>
        </section>
    </main>
</body>
</html>
