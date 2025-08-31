<?php
require_once 'i18n.php';
$supported = getSupportedLanguages();
$appLang = getAppLanguage();
$outputLang = getOutputLanguage();
$browserLang = $_SESSION['browser_lang'] ?? $appLang;
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
            <form action="set_language.php" method="post" class="flex items-center space-x-2 ml-4">
                <select name="app_lang" onchange="this.form.submit()" class="text-black px-1">
                    <?php foreach ($supported as $code => $name): ?>
                        <option value="<?=$code?>" <?=$appLang===$code?'selected':''?>><?=$name?></option>
                    <?php endforeach; ?>
                </select>
                <select name="output_lang" onchange="this.form.submit()" class="text-black px-1">
                    <?php foreach ($supported as $code => $name): ?>
                        <option value="<?=$code?>" <?=$outputLang===$code?'selected':''?>><?=$name?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="swap" value="1" class="text-sm underline"><?=__('swap_languages')?></button>
            </form>
            <?php if ($browserLang !== $appLang): ?>
                <a href="set_language.php?app_lang=<?=$browserLang?>&output_lang=<?=$outputLang?>" class="text-sm underline ml-2">
                    <?=str_replace('{language}', $supported[$browserLang], __('switch_language'))?>
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>
