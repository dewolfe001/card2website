<?php
require_once 'i18n.php';
?>
<!DOCTYPE html>
<html lang="<?=getAppLanguage()?>">
<head>
    <meta charset="UTF-8">
    <title><?=_('Frequently Asked Questions')?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800">
    <?php include 'header.php'; ?>
    <main class="container mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold mb-4"><?=_('Frequently Asked Questions')?></h1>
    <!-- Introduction -->
        <section class="mb-12">
            <div class="bg-white rounded-lg shadow-sm p-8 border border-gray-200">
                <p class="text-lg text-gray-700 leading-relaxed">
                    <?=_('BusinessCard2Website.com offers a turnkey service that converts a photo of a business card into a fully hosted website, domain, and ongoing management for a straightforward monthly[...]')?>
                </p>
            </div>
        </section>

        <!-- Key Virtues -->
        <section class="mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-6"><?=_('Key Virtues of Your Concept')?></h2>
            
            <div class="grid gap-6">
                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Ultra Simplicity')?></h3>
                    <p class="text-gray-700"><?=_('Just upload a business card photo—no confusing forms, design software, or technical know-how needed. Traditional site builders still require users to nav[...]')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Fast, Professional Launch')?></h3>
                    <p class="text-gray-700"><?=_('Instantly go from business card to live website with a professional look, suitable for small businesses, freelancers, and trades.')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('All-Inclusive Price')?></h3>
                    <p class="text-gray-700"><?=_('The $24.99/mo or $199/yr plan covers website, custom domain, hosting, and future updates—no hidden upsells or separate invoices for each service.')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Mobile and SEO Ready')?></h3>
                    <p class="text-gray-700"><?=_('Every site is responsive and search-friendly out of the box, meeting the essential needs of today\'s customers.')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Personal Support')?></h3>
                    <p class="text-gray-700"><?=_('Unlike ultra-low-cost DIY builders, the model promises hands-on help and after-launch support, making it truly "done for you".')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Ownership and Control')?></h3>
                    <p class="text-gray-700"><?=_('Users keep their domain and can update card/site content as their business changes, offering flexibility for growth.')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Brand Continuity')?></h3>
                    <p class="text-gray-700"><?=_('The website is visually matched to the business card, ensuring a unified identity online and offline.')?></p>
                </div>

                <div class="bg-white rounded-lg shadow-sm p-6 border border-gray-200 hover:shadow-md transition-shadow">
                    <h3 class="text-xl font-semibold text-blue-600 mb-3"><?=_('Hassle-Free Maintenance')?></h3>
                    <p class="text-gray-700"><?=_('Ongoing hosting and security updates are managed, so business owners never need to "touch" the website if they don\'t want to.')?></p>
                </div>
            </div>
        </section>

        <!-- FAQ Topics -->
        <section class="mb-12">
            <h2 class="text-3xl font-bold text-gray-900 mb-6"><?=_('Example FAQ Topics and Questions')?></h2>
            
            <div class="bg-white rounded-lg shadow-sm border border-gray-200">
                <!-- Getting Started -->
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-green-600 mb-4"><?=_('Getting Started')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">•</span>
                            <?=_('How does BusinessCard2Website.com work?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">•</span>
                            <?=_('What do I need to provide to get my website?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">•</span>
                            <?=_('How long does it take to launch my site after uploading my card?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-green-500 mr-2">•</span>
                            <?=_('Can I use my existing domain?')?>
                        </li>
                    </ul>
                </div>

                <!-- Features and Customization -->
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-purple-600 mb-4"><?=_('Features and Customization')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-purple-500 mr-2">•</span>
                            <?=_('Will my website match the style and branding of my business card?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-purple-500 mr-2">•</span>
                            <?=_('Is my site mobile responsive?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-purple-500 mr-2">•</span>
                            <?=_('Can I update or change my website content after it\'s created?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-purple-500 mr-2">•</span>
                            <?=_('What if I have more information than fits on my card?')?>
                        </li>
                    </ul>
                </div>

                <!-- Pricing and Payments -->
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-orange-600 mb-4"><?=_('Pricing and Payments')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-orange-500 mr-2">•</span>
                            <?=_('What\'s included in the subscription price?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-orange-500 mr-2">•</span>
                            <?=_('Are there any setup fees or hidden costs?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-orange-500 mr-2">•</span>
                            <?=_('Do I have to pay separately for domain registration or hosting?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-orange-500 mr-2">•</span>
                            <?=_('Is there a discount for annual payment?')?>
                        </li>
                    </ul>
                </div>

                <!-- Technical and Support -->
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-red-600 mb-4"><?=_('Technical and Support')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2">•</span>
                            <?=_('Will my website show up in Google search results?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2">•</span>
                            <?=_('Is SSL (secure HTTPS) included?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2">•</span>
                            <?=_('What kind of customer support do you provide?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-red-500 mr-2">•</span>
                            <?=_('Who owns my website and domain?')?>
                        </li>
                    </ul>
                </div>

                <!-- Policies and Guarantee -->
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-xl font-semibold text-indigo-600 mb-4"><?=_('Policies and Guarantee')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-indigo-500 mr-2">•</span>
                            <?=_('Can I cancel at any time?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-indigo-500 mr-2">•</span>
                            <?=_('What happens if I want to move my site elsewhere later?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-indigo-500 mr-2">•</span>
                            <?=_('Is there a satisfaction guarantee or trial period?')?>
                        </li>
                    </ul>
                </div>

                <!-- Advanced Topics -->
                <div class="p-6">
                    <h3 class="text-xl font-semibold text-teal-600 mb-4"><?=_('Advanced Topics')?></h3>
                    <ul class="space-y-2 text-gray-700">
                        <li class="flex items-start">
                            <span class="text-teal-500 mr-2">•</span>
                            <?=_('Can you add features like contact forms or maps?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-teal-500 mr-2">•</span>
                            <?=_('What if my business card is not in English?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-teal-500 mr-2">•</span>
                            <?=_('Can you connect social media or other links?')?>
                        </li>
                        <li class="flex items-start">
                            <span class="text-teal-500 mr-2">•</span>
                            <?=_('How do you handle privacy and data security?')?>
                        </li>
                    </ul>
                </div>
            </div>
        </section>        
    </main>
    <?php include 'footer.php'; ?>
</body>
</html>
