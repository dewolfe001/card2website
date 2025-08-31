<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function getSupportedLanguages(): array {
    return [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'yue' => '廣東話',
        'zh' => '中文',
        'tl' => 'Tagalog',
        'ko' => '한국어',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'ar' => 'العربية',
        'fa' => 'فارسی'
    ];
}

function detectBrowserLanguage(): string {
    $supported = getSupportedLanguages();
    if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $code = strtolower(substr($lang, 0, 2));
            if (isset($supported[$code])) {
                return $code;
            }
        }
    }
    return 'en';
}

function getAppLanguage(): string {
    if (!isset($_SESSION['app_lang'])) {
        $_SESSION['browser_lang'] = detectBrowserLanguage();
        $_SESSION['app_lang'] = $_SESSION['browser_lang'];
    }
    return $_SESSION['app_lang'];
}

function setAppLanguage(string $lang): void {
    $supported = getSupportedLanguages();
    if (isset($supported[$lang])) {
        $_SESSION['app_lang'] = $lang;
    }
}

function getOutputLanguage(): string {
    if (!isset($_SESSION['output_lang'])) {
        $_SESSION['output_lang'] = getAppLanguage();
    }
    return $_SESSION['output_lang'];
}

function setOutputLanguage(string $lang): void {
    $supported = getSupportedLanguages();
    if (isset($supported[$lang])) {
        $_SESSION['output_lang'] = $lang;
    }
}

function loadTranslations(string $lang): array {
    $file = __DIR__ . '/lang/' . $lang . '.php';
    if (!file_exists($file)) {
        $file = __DIR__ . '/lang/en.php';
    }
    return include $file;
}

function __(string $key): string {
    $translations = loadTranslations(getAppLanguage());
    return $translations[$key] ?? $key;
}
