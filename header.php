<?php
require_once 'i18n.php';
$supported = getSupportedLanguages();
$appLang = getAppLanguage();
$outputLang = getOutputLanguage();
?>
<header class="bg-white border-b">
    <div class="container mx-auto flex justify-between items-center p-4">
        <div class="flex items-center">
            <img src="cardbot.png" alt="<?=__('business_card_to_website')?>" class="h-8 mr-2">
            <span class="font-bold text-xl text-gray-900"><?=__('business_card_to_website')?></span>
        </div>
        <nav class="flex items-center space-x-6 text-gray-700">
            <a href="/faq.php" class="hover:text-blue-600"><?=__('faq')?></a>
            <a href="/pricing.php" class="hover:text-blue-600"><?=__('pricing')?></a>
            <a href="/contact.php" class="hover:text-blue-600"><?=__('contact')?></a>
            <form action="set_language.php" method="post" class="ml-4">
                <input type="hidden" name="output_lang" value="<?=$outputLang?>">
                <select name="app_lang" onchange="this.form.submit()" class="border rounded px-2 py-1 text-sm">
                    <?php foreach ($supported as $code => $name): ?>
                        <option value="<?=$code?>" <?=$appLang===$code?'selected':''?>><?=$name?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </nav>
    </div>
</header>
