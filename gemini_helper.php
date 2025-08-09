<?php

/**
 * Generate a photorealistic image via Google’s Gemini API,
 * save it to disk and return its path & URL.
 *
 * @param  string      $prompt  Your natural‑language description.
 * @param  string|null &$error  If anything goes wrong, this will be populated.
 * @return array|null          ['file_path'=>string, 'url'=>string] or null on failure.
 */
function generateBusinessCardImageGemini(string $prompt, ?string &$error = null): ?array
{
    // 1) build the image prompt
    $fullPrompt = sprintf(
        'Make an image to match the following inspiration (see below). Make it photo‑realistic with great lighting, like it is the output of a professional photographer. The image should have no text. **Strictly no text of any kind** (no words, letters, numbers, signage, logos, labels or typography). Avoid any business‑card shapes or printed elements. If you can’t meet these constraints, generate a different image that fits. Keep to the context and theme of this prompt as an inspiration: '."\n".' Inspiration: '."\n".' "%s"',
        trim($prompt)
    );

    // 2) prepare request data
    $postData = [
        'contents' => [
            [
                'parts' => [
                    ['text' => $fullPrompt]
                ]
            ]
        ],
        'generationConfig' => [
            'responseModalities' => ['TEXT', 'IMAGE']
        ]
    ];

    // 3) call Gemini REST endpoint
    $apiKey = getenv('GEMINI_API_KEY');
    if (!$apiKey) {
        $error = 'Gemini API key not configured.';
        return null;
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . 'gemini-2.0-flash-preview-image-generation:generateContent'
         . '?key=' . urlencode($apiKey);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || ! $response) {
        $error = 'HTTP error from Gemini API: ' . $status;
        return null;
    }

    // 4) parse & validate JSON
    $json = json_decode($response, true);
    if (
        ! $json ||
        ! isset($json['candidates'][0]['content']['parts']) ||
        ! is_array($json['candidates'][0]['content']['parts'])
    ) {
        $error = 'Unexpected response format from Gemini API.';
        return null;
    }

    // 5) extract the base64 image
    $b64 = null;
    foreach ($json['candidates'][0]['content']['parts'] as $part) {
        if (
            isset($part['inlineData']['data']) &&
            is_string($part['inlineData']['data'])
        ) {
            $b64 = $part['inlineData']['data'];
            break;
        }
    }
    if (! $b64) {
        $error = 'No image data returned from Gemini API.';
        return null;
    }

    // 6) decode and save
    $imageData = base64_decode($b64);
    if ($imageData === false) {
        $error = 'Failed to decode base64 image data.';
        return null;
    }

    $uploadDir = __DIR__ . '/generated_images';
    if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true)) {
        $error = 'Could not create directory for saving images.';
        return null;
    }

    $fileName = uniqid('bc_img_gemini_') . '.png';
    $filePath = $uploadDir . '/' . $fileName;
    if (file_put_contents($filePath, $imageData) === false) {
        $error = 'Failed to write image file to disk.';
        return null;
    }

    // 7) public URL
    $publicBaseUrl = 'https://businesscard2website.com/generated_images';

    return [
        'file_path' => $filePath,
        'url'       => $publicBaseUrl . '/' . $fileName,
    ];
}


// if (!function_exists('geminiGenerateImage')) {
    /**
     * Generate an image via Gemini with negatives and size options.
     * Return shape:
     *  ['ok'=>bool, 'url'=>string|null, 'raw'=>mixed, 'error'=>string|null]
     */
    function geminiGenerateImage(string $prompt, array $opts = []): array {
        // Options
        $size     = $opts['size']     ?? '1024x1024';
        $seed     = $opts['seed']     ?? random_int(1, PHP_INT_MAX);
        $negHints = $opts['negative_hints'] ?? []; // e.g. ["no text", "no letters", "no watermark", "no captions"]

        // Strengthen prompt with negatives
        $negText = '';
        if (!empty($negHints)) {
            $negText = "\n\nSTRICT NEGATIVE CONSTRAINTS:\n- " . implode("\n- ", array_map('strval', $negHints));
        }

        $finalPrompt = trim($prompt) . $negText . "\n\nHard rule: Do NOT include any text, letters, numbers, logos-as-text, watermarks, UI elements, or captions.";

        try {
            // TODO: Replace with your actual Gemini client call
            // Example placeholder:
            // $res = $geminiClient->images->generate([
            //     'model' => 'gemini-2.0-pro-vision', // or relevant image model
            //     'prompt' => $finalPrompt,
            //     'size' => $size,
            //     'seed' => $seed,
            //     'safety_settings' => [ ... ],
            // ]);
            // Assume it returns an accessible URL:
            $res = null;
            throw new RuntimeException('Implement Gemini API call here.');

            // $url = extract URL from $res;
            // return ['ok' => true, 'url' => $url, 'raw' => $res, 'error' => null];

        } catch (Throwable $t) {
            return ['ok' => false, 'url' => null, 'raw' => null, 'error' => $t->getMessage()];
        }
    }
// }

?>