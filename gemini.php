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

?>
