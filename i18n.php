<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/openai_helper.php';

function getSupportedLanguages(): array {
    return [
        'en' => 'English',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'yue' => '廣東話',
        'zh' => '中文',
        'fil' => 'Filipino',
        'ko' => '한국어',
        'hi' => 'हिन्दी',
        'bn' => 'বাংলা',
        'ar' => 'العربية',
        'fa' => 'فارسی'
    ];
}

function getAppLanguage(): string {
    if (!isset($_SESSION['app_lang'])) {
        $_SESSION['app_lang'] = 'en';
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

function getEnglishPhrase(string $key): string {
    static $english = null;
    if ($english === null) {
        $english = include __DIR__ . '/lang/en.php';
    }
    return $english[$key] ?? $key;
}

function getDbTranslation(string $english, string $lang): ?string {
    global $pdo;
    $stmt = $pdo->prepare('SELECT translated_phrase FROM translations WHERE english_phrase = ? AND language = ?');
    $stmt->execute([$english, $lang]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['translated_phrase'] ?? null;
}

function saveDbTranslation(string $english, string $lang, string $translated): void {
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO translations (english_phrase, language, translated_phrase) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE translated_phrase = VALUES(translated_phrase)');
    $stmt->execute([$english, $lang, $translated]);
}

function fetchTranslation(string $english, string $lang): ?string {
    $prompt = "Translate the following phrase to {$lang}. Keep text inside curly braces unchanged. Respond with only the translation: {$english}";
    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'You are a helpful translator.'],
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 60,
        'temperature' => 0.3
    ];
    $error = null;
    $response = openaiChatRequest($postData, $error);
    if (!$response) {
        return null;
    }
    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        return null;
    }
    return trim($json['choices'][0]['message']['content']);
}

function __(string $key): string {
    $lang = getAppLanguage();
    $english = getEnglishPhrase($key);
    if ($lang === 'en') {
        return $english;
    }
    $translation = getDbTranslation($english, $lang);
    if ($translation) {
        return $translation;
    }
    $translation = fetchTranslation($english, $lang);
    if ($translation) {
        saveDbTranslation($english, $lang, $translation);
        return $translation;
    }
    return $english;
}
