<?php
require_once 'i18n.php';
$supported = getSupportedLanguages();
$appLang = getAppLanguage();
$outputLang = getOutputLanguage();
?>
<header class="bg-blue-700 text-white p-4">
    <div class="container mx-auto flex justify-between items-center">
        <div class="flex items-center">
            <img src="cardbot.png" alt="Card2Website" class="h-8 mr-2">
            <span class="font-bold">Business Card to Website</span>
        </div>
        <nav class="flex items-center space-x-4">
            <a href="index.php" class="hover:underline"><?=__('home')?></a>
            <a href="dashboard.php" class="hover:underline"><?=__('dashboard')?></a>
            <a href="account.php" class="hover:underline"><?=__('account')?></a>
            <a href="contact.php" class="hover:underline"><?=__('contact')?></a>
            <form action="set_language.php" method="post" class="ml-4">
                <input type="hidden" name="output_lang" value="<?=$outputLang?>">
                <select name="app_lang" onchange="this.form.submit()" class="text-black px-1">
                    <?php foreach ($supported as $code => $name): ?>
                        <option value="<?=$code?>" <?=$appLang===$code?'selected':''?>><?=$name?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </nav>
    </div>
</header>
