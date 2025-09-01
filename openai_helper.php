<?php

/**
 * Perform an OpenAI chat request with basic retry support.
 *
 * @param array $postData JSON payload for the API
 * @param string|null $error Receives any error message
 * @return string|null API response on success, null on failure
 */
function openaiChatRequest(array $postData, ?string &$error = null): ?string {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        $error = 'Missing OpenAI API key';
        return null;
    }
    
    error_log("LINE 17 - ". print_r($postData, TRUE));

    $limit = getenv('OPENAI_RETRY_LIMIT');
    $limit = is_numeric($limit) ? (int)$limit : 3;
    $limit = $limit > 0 ? $limit : 1;

    for ($attempt = 1; $attempt <= $limit; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));

        $response = curl_exec($ch);

        error_log('RAW Response -- '.print_r($response, TRUE));

        if ($response === false) {
            $error = 'cURL error: ' . curl_error($ch);
        } else {
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($status === 200) {
                curl_close($ch);
                return $response;
            }
            $error = "HTTP $status: $response";
        }
        curl_close($ch);
    }

    return null;
}

function sendImageToOpenAI(string $imagePath, string $inputLanguage = 'en', ?string &$error = null) {
    if (!file_exists($imagePath)) {
        $error = 'File not found';
        return null;
    }
    
    $imageData = base64_encode(file_get_contents($imagePath));
    if ($imageData === false) {
        return null;
    }
    
    // Replace mime_content_type() with finfo_file()
    $mimeType = null;
    if (function_exists('finfo_file')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $imagePath);
        finfo_close($finfo);
    }
    
    // Fallback if finfo is not available
    if (!$mimeType) {
        $imageInfo = getimagesize($imagePath);
        $mimeType = $imageInfo ? $imageInfo['mime'] : 'image/jpeg'; // default fallback
    }
    
    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
                [
                "role" => "system",
                "content" => "You are an assistant that extracts structured data from fictional business cards."
                ],
                [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Please extract the business card text into structured contact details (name, title, email, phone, company, address). The card text is primarily in ' . $inputLanguage . '.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $imageData]]
                ]
                ]
            ],
        'max_tokens'  => 2500,
        // 'temperature' => 0.1,            
        ];

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
/**
 * Convert PDF to image using Imagick
 * Returns image data as string or null on failure
 */
function convertPdfToImage(string $pdfPath) {
    if (!extension_loaded('imagick')) {
        error_log("Imagick extension is required for PDF processing");
        return null;
    }

    try {
        $imagick = new Imagick();
        
        // Set resolution for better quality (adjust as needed)
        $imagick->setResolution(300, 300);
        
        // Read the first page of the PDF
        $imagick->readImage($pdfPath . '[0]');
        
        // Convert to JPEG format
        $imagick->setImageFormat('jpeg');
        $imagick->setImageCompressionQuality(90);
        
        // Get the image data
        $imageData = $imagick->getImageBlob();
        $imagick->clear();
        
        return $imageData;
        
    } catch (Exception $e) {
        error_log("Error converting PDF to image: " . $e->getMessage());
        return null;
    }
}

/**
 * Alternative PDF conversion using Ghostscript (if Imagick is not available)
 * Requires Ghostscript to be installed on the system
 */
function convertPdfToImageWithGhostscript(string $pdfPath) {
    $tempImagePath = tempnam(sys_get_temp_dir(), 'pdf_converted_') . '.jpg';
    
    $command = sprintf(
        'gs -dNOPAUSE -dBATCH -dSAFER -sDEVICE=jpeg -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -r300 -dFirstPage=1 -dLastPage=1 -sOutputFile=%s %s 2>&1',
        escapeshellarg($tempImagePath),
        escapeshellarg($pdfPath)
    );
    
    $output = [];
    $returnCode = 0;
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0 || !file_exists($tempImagePath)) {
        error_log("Ghostscript conversion failed. Command: $command, Output: " . implode("\n", $output));
        return null;
    }
    
    $imageData = file_get_contents($tempImagePath);
    unlink($tempImagePath); // Clean up temp file
    
    return $imageData;
}

/**
 * Validate file size and dimensions before processing
 */
function validateFile(string $filePath) {
    $maxFileSize = 20 * 1024 * 1024; // 20MB limit (OpenAI's limit)
    $fileSize = filesize($filePath);
    
    if ($fileSize > $maxFileSize) {
        error_log("File too large: {$fileSize} bytes (max: {$maxFileSize})");
        return false;
    }
    
    return true;
}

// Usage example:
/*
$result = sendImageToOpenAI('/path/to/your/business-card.pdf');
if ($result) {
    echo "Extracted text: " . $result;
} else {
    echo "Failed to extract text from the file.";
}
*/

function analyzeBusinessCardStructured(string $imagePath, string $inputLanguage = 'en', ?string &$error = null): ?array {
    if (!file_exists($imagePath)) {
        $error = 'File not found';
        return null;
    }

    $imageData = base64_encode(file_get_contents($imagePath));
    if ($imageData === false) {
        error_log("LINE 68 - null");
        return null;
    }

    $system = 'You are an expert at analyzing business cards and extracting structured data. The card text is primarily in ' . $inputLanguage . '. You will receive a business card image and need to extract all relevant information in a specific JSON format.';
    $userPrompt = "Please analyze this business card image and extract all relevant information. Parse the image to identify:\n\n1. All text content (names, titles, company, contact info)\n2. Color palette (primary and secondary colors in hex format)\n3. Font characteristics (serif/sans-serif, weight, style observations)\n4. Logo details (description, position, colors). Extrapolate the logo from the business card and add a logo detail: base64 encoded cropped version of the logo availale for use by this application. \n5. Layout and design elements\n6. Industry/business type based on content\n\nRespond ONLY with valid JSON in this exact format:\n{\n  \"business_info\": {\n    \"company_name\": \"string\",\n    \"person_name\": \"string\",\n    \"title\": \"string\",\n    \"phone\": \"string\",\n    \"email\": \"string\",\n    \"website\": \"string\",\n    \"address\": {\n      \"street\": \"string\",\n      \"city\": \"string\",\n      \"state\": \"string\",\n      \"zip\": \"string\",\n      \"country\": \"string\"\n    },\n    \"industry\": \"string\",\n    \"services\": [\"string\"]\n  },\n  \"design_elements\": {\n    \"colors\": {\n      \"primary\": \"#hexcode\",\n      \"secondary\": \"#hexcode\",\n      \"accent\": \"#hexcode\",\n      \"text\": \"#hexcode\"\n    },\n    \"fonts\": {\n      \"primary_font_style\": \"serif|sans-serif|script|display\",\n      \"font_weight\": \"light|normal|bold\",\n      \"characteristics\": \"string description\"\n    },\n    \"logo\": {\n      \"present\": true|false,\n      \"description\": \"string\",\n      \"position\": \"string\",\n      \"style\": \"string\"\n      \"base64\": \"string\"\n     },\n    \"layout\": {\n      \"style\": \"modern|traditional|creative|minimal\",\n      \"orientation\": \"horizontal|vertical\"\n    }\n  }\n}";

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $userPrompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageData]]
            ]]
        ],
        'max_tokens' => 1600,
        // 'temperature' => 0.1
    ];

    $response = openaiChatRequest($postData, $error);
    if (!$response) {
        return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        return null;
    }

    $content = trim($json['choices'][0]['message']['content']);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

// the preamble
function generateMarketingOpenAI(string $prompt, string $additional = '', string $outputLanguage = 'en', ?string &$error = null) {

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            [
            "role" => "system",
            "content" => "You are an expert marketer who knows how to deliver excellent communications that rank well in search engines; and attract users to engage with the subject matter. Respond in " . $outputLanguage . ". Strip out the markdown code to make the text more clear. Strip the questions out of the user prompt to have a solid body of information to work with."
            ],

            ['role' => 'user', 'content' => $prompt . "\n" . $additional]
        ],
        'max_tokens' => 1200,
        // 'temperature' => 0.2
    ];

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


function generateBusinessCardImage(string $prompt, string $size = '1024x1024', ?string &$error = null): ?array
{
    // 1) Build a strong, unambiguous prompt (no text whatsoever)
    $fullPrompt = trim(sprintf(
        "Create a photo-realistic image that matches this theme: %s\n\n".
        "Creative direction:\n".
        "- Natural, professional lighting; cinematic but believable.\n".
        "- Composition suited for a website hero image.\n".
        "- West Coast vibe if applicable (subtle, not kitschy).\n".
        "HARD CONSTRAINTS (must follow):\n".
        "- Do NOT include any text, letters, words, or numbers anywhere in the image.\n".
        "- No signage, no labels, no watermarks, no captions, no logos containing letters.\n".
        "- Do NOT depict a business card.\n",
        $prompt
    ));

    // 2) Call the Images API using gpt-image-1
    //    NOTE: openaiImageRequest($postData, &$error) should POST to /v1/images/generations (or your wrapper’s equivalent)
    //    and return the raw JSON response as a string.

    /*
    $postData = [
        'model'           => 'gpt-image-1',
        'prompt'          => $fullPrompt,
        'n'               => 1,
        'size'            => $size,         // e.g. 1024x1024, 1024x1792, 1792x1024
        'quality'         => 'high',        // optional: 'high' | 'standard'
        'background'      => 'opaque',      // optional: 'transparent' | 'opaque'
        'response_format' => 'b64_json',    // get base64 back so we can save locally
        // 'user'         => 'bc2w-image-gen', // optional: pass a user identifier for auditability
    ];
    */
    
    $postData = [
        'model'    => 'gpt-image-1',
        'prompt'   => $fullPrompt,
        'size'     => $size,         // '1024x1024', '1024x1792', or '1792x1024'
        'quality'  => 'medium',        // optional
        'n'        => 1
    ];

    $response = openaiImageRequest($postData, $error);

    error_log("LINE 303 - ".print_r($response, TRUE));


    if (!$response) {
        // $error should be set by your wrapper
        return null;
    }

    // 3) Parse & validate
    $json = json_decode($response, true);
    if (
        !is_array($json) ||
        empty($json['data']) ||
        empty($json['data'][0]['b64_json'])
    ) {
        $error = 'Unexpected response format from OpenAI Images API.';
        error_log("Images API raw response:\n" . $response);
        return null;
    }

    $b64 = $json['data'][0]['b64_json'];

    // 4) Decode and detect mime/extension
    $imageData = base64_decode($b64, true);
    if ($imageData === false) {
        // Try URL-safe repair in case the response uses -_ variant (rare)
        $imageData = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($imageData === false) {
            $error = 'Failed to decode base64 image data.';
            return null;
        }
    }

    $info = @getimagesizefromstring($imageData);
    $mime = $info['mime'] ?? 'image/png';
    switch ($mime) {
        case 'image/png':
            $ext = 'png';
            break;
        case 'image/jpeg':
            $ext = 'jpg';
            break;
        case 'image/webp':
            $ext = 'webp';
            break;
        default:
            $ext = 'png';
            break;
    }

    // 5) Save to disk (ensure the directory is web-accessible)
    $uploadDir = __DIR__ . '/generated_images';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $error = 'Could not create directory for saving images.';
        return null;
    }

    $fileName = sprintf('bc_img_%s.%s', date('Ymd_His') . '_' . bin2hex(random_bytes(4)), $ext);
    $filePath = $uploadDir . '/' . $fileName;

    if (file_put_contents($filePath, $imageData) === false) {
        $error = 'Failed to write image file to disk.';
        return null;
    }

    // 6) Build a public URL (adjust this to your environment)
    // If you want to build it dynamically:
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $publicBaseUrl = $scheme . $host . '/generated_images';

    // If you prefer a fixed base:
    // $publicBaseUrl = 'https://businesscard2website.com/generated_images';

    return [
        'file_path' => $filePath,
        'url'       => $publicBaseUrl . '/' . rawurlencode($fileName),
        'mime'      => $mime,
        'width'     => $info[0] ?? null,
        'height'    => $info[1] ?? null,
        'model'     => 'gpt-image-1',
        'size'      => $size,
        'quality'   => $postData['quality'],
        'background'=> $postData['background'],
    ];
}


/**
 * Generate a photorealistic image via OpenAI’s Images API,
 * save it to disk and return its path & URL.
 *
 * @param  string      $prompt  Your natural‑language description.
 * @param  string      $size    Desired image size (e.g. "256x256", "512x512", "1024x1024").
 * @param  string|null &$error  If anything goes wrong, this will be populated.
 * @return array|null          ['file_path'=>string, 'url'=>string] or null on failure.
 */
function OldgenerateBusinessCardImage(string $prompt, string $size = '1024x1024', ?string &$error = null): ?array
{
    // 1) build the image prompt
    $fullPrompt = sprintf(
        'Make an image to match the following description. Make it photo‑realistic with great lighting—like it’s the output of a professional photographer. Do not display any text in the image. Avoid displaying a business card in the image. Keep to the theme of this prompt: "%s"',
        trim($prompt)
    );

    // 2) call the Images API (expects you have a wrapper like openaiImageRequest)
    $postData = [
        // Use the DALL·E model you have access to
        'model'           => 'dall-e-3',
        'prompt'          => $fullPrompt,
        'n'               => 1,
        'size'            => $size,
        'response_format' => 'b64_json',
    ];
    
    error_log("Working with ".print_r($postData, TRUE));
    
    
    $response = openaiImageRequest($postData, $error);
    if (!$response) {
        return null;
    }

    // 3) parse & validate
    $json = json_decode($response, true);
    if (
        ! $json ||
        ! isset($json['data'][0]['b64_json'])
    ) {
        error_log(print_r($response, TRUE));
        $error = 'Unexpected response format from OpenAI Images API.';
        return null;
    }
    $b64 = $json['data'][0]['b64_json'];

    // 4) decode and save
    $imageData = base64_decode($b64);
    if ($imageData === false) {
        $error = 'Failed to decode base64 image data.';
        return null;
    }

    // choose your storage directory
    $uploadDir = __DIR__ . '/generated_images';
    if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true)) {
        $error = 'Could not create directory for saving images.';
        return null;
    }

    $fileName = uniqid('bc_img_') . '.png';
    $filePath = $uploadDir . '/' . $fileName;

    if (file_put_contents($filePath, $imageData) === false) {
        $error = 'Failed to write image file to disk.';
        return null;
    }

    // 5) expose a public URL for your app to include in its HTML output
    // adjust this base URL to match your server setup!
    $publicBaseUrl = 'https://businesscard2website.com/generated_images';

    return [
        'file_path' => $filePath,
        'url'       => $publicBaseUrl . '/' . $fileName,
    ];
}

/**
 * Send a JSON request to OpenAI’s Images endpoint and return the raw response.
 */
function openaiImageRequest(array $postData, ?string &$error = null): ?string
{
    $apiKey = getenv('OPENAI_API_KEY');
    if (empty($apiKey)) {
        $error = 'Missing OPENAI_API_KEY';
        return null;
    }

    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
    ]);

    $resp = curl_exec($ch);
    if (curl_errno($ch)) {
        $error = 'cURL error: ' . curl_error($ch);
        
        error_log("Image error - ".print_r($error, TRUE));
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    error_log("LINE 380 - Resp - ".print_r($resp, TRUE));
    
    if ($status !== 200) {
        $data = json_decode($resp, true);
        $error = $data['error']['message'] ?? "Unexpected HTTP status $status";
        return null;
    }

    return $resp;
}

function generateHtmlWithOpenAI(string $prompt, ?string &$error = null) {

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            [
            "role" => "system",
            "content" => "You are an expert HTML designer who specialized in Tailwind design. You are are also an expert marketer who knows how to deliver excellent designs that rank well in search engines; and attract users to engage with the subject of the web design."
            ],            
            
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 1500,
        // 'temperature' => 1
    ];

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

function classifyNaics(string $text, ?string &$error = null): ?array {
    $prompt = "Identify the best matching 6-digit NAICS code for this business information and respond in JSON with keys code, title, description only.";
    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $text]
        ],
        'max_tokens' => 200
    ];
    $response = openaiChatRequest($postData, $error);
    if (!$response) {
        return null;
    }
    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        return null;
    }
    $content = trim($json['choices'][0]['message']['content']);
    $data = json_decode($content, true);
    if (!is_array($data) || !isset($data['code'])) {
        return null;
    }
    return $data;
}

// The Big Kahuna...

function generateWebsiteFromData($businessData, string $additional = '', ?string $layoutImageUrl = null, string $inputLanguage = 'en', string $outputLanguage = 'en', ?string &$error = null) {
    // Normalize $businessData to a JSON-ish string if an array/object is passed
    if (is_array($businessData) || is_object($businessData)) {
        $businessData = json_encode($businessData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } else {
        $businessData = (string) $businessData;
    }

    // --- System message: stronger guidance for A11y, CWV, schema ---
    $system = <<<SYS
You are a senior web developer, UX designer, and technical SEO specialist.
You produce production-grade, responsive one-page websites using semantic HTML5, accessibility best practices (WCAG 2.2 AA), and Tailwind CSS.
You rigorously apply structured data with multiple JSON-LD graphs to maximize rich results, especially for local businesses.
You consider Core Web Vitals (LCP, CLS, INP), responsive images, and minimal blocking resources. Your code is clean, portable, and standards-compliant.
All textual output must be in {$outputLanguage}.
Do not include explanations. Only follow the schema and produce valid JSON per the provided response schema.
SYS;

    // --- User prompt: mobile-first, large-screen polish, and HEAVY schema ---
    $userContent = <<<USR
Using the following business card data (written in {$inputLanguage}), create a professional one-page website that is excellent on small viewports and scales beautifully to large viewports.

BUSINESS DATA:
{$businessData}

ADDITIONAL USER REQUIREMENTS AND SUPPLEMENTAL PROMPTS:
{$additional}

HARD REQUIREMENTS:
1) Tailwind CSS for styling (use CDN). Use fluid, mobile-first layout, grid/flex utilities, fluid typography (clamp), and good color contrast.
2) Use the exact brand colors from the business card (approximate if only descriptive, include CSS vars).
3) Web-safe font stack approximating the business card’s type vibe; include proper line-height and readable sizes.
4) Place logo or logo-description appropriately (top-left or centered on small screens; refined on large).
5) Research and include the likely NAICS code and its description for this business type.
6) Add 300–500 words of unique, relevant, SEO-optimized content covering services, value props, and local context.
7) Technical SEO:
   - Proper semantic structure: <header> (with skip link), <nav> (ARIA), <main>, <section>, <footer>, landmarks & headings.
   - <title>, meta description, robots, canonical, Open Graph, Twitter Card.
   - Internal anchor links (skip to sections).
   - Preconnect to critical origins; preload hero image (if any) responsibly.
8) Contact details, map link (if address), business hours (estimate if missing, note “Hours may vary” if inferred).
9) Responsive images with width/height, alt text, and loading=lazy where appropriate (avoid lazy on LCP hero).
10) Optimize for CWV: minimal inline critical CSS where appropriate, defer non-critical scripts.
11) Accessibility: focus states, sufficient color contrast, aria-labels where needed.
12) HEAVY SCHEMA. Include multiple JSON-LD graphs in a single <script type="application/ld+json"> using @graph:
    - Use the most specific LocalBusiness subtype (e.g., Restaurant, ProfessionalService, MedicalBusiness, etc.) if applicable; else Organization.
    - WebSite (with potentialAction SearchAction for sitelinks if a site search is plausible).
    - Organization (or the LocalBusiness entity itself) with logo (ImageObject), sameAs links, address, phone, email.
    - BreadcrumbList matching on-page anchors.
    - FAQPage if you add 3–6 FAQs (encouraged if data supports).
    - If photos exist, include ImageObject(s).
    - If pricing or offers exist, include Offer where relevant.
    - If you include a map URL, ensure it’s properly referenced.
13) Return ONLY valid JSON. Do not wrap in backticks. HTML must be a single escaped string.

OUTPUT FORMAT (STRICT):
{
  "html_code": "complete HTML source code as escaped string",
  "seo_elements": {
    "title_tag": "string",
    "meta_description": "string",
    "keywords": ["string"],
    "naics_code": "string",
    "naics_description": "string"
  },
  "content_summary": {
    "word_count": 0,
    "key_topics": ["string"],
    "schema_types": ["string"]
  },
  "technical_features": {
    "responsive": true,
    "semantic_html": true,
    "structured_data": true,
    "accessibility": true
  }
}
USR;

    // --- Response JSON schema to force shape & validity ---
    $responseSchema = [
        "name" => "OnePageSiteResponse",
        "strict" => true, // Enforce exact structure
        "schema" => [
            "type" => "object",
            "additionalProperties" => false,
            "required" => ["html_code","seo_elements","content_summary","technical_features"],
            "properties" => [
                "html_code" => [
                    "type" => "string",
                    "minLength" => 200
                ],
                "seo_elements" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "required" => ["title_tag","meta_description","keywords","naics_code","naics_description"],
                    "properties" => [
                        "title_tag" => ["type" => "string", "minLength" => 10],
                        "meta_description" => ["type" => "string", "minLength" => 50],
                        "keywords" => [
                            "type" => "array",
                            "items" => ["type" => "string"],
                            "minItems" => 3
                        ],
                        "naics_code" => ["type" => "string"],
                        "naics_description" => ["type" => "string"]
                    ]
                ],
                "content_summary" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "required" => ["word_count","key_topics","schema_types"],
                    "properties" => [
                        "word_count" => ["type" => "integer", "minimum" => 200, "maximum" => 1000],
                        "key_topics" => [
                            "type" => "array",
                            "items" => ["type" => "string"],
                            "minItems" => 3
                        ],
                        "schema_types" => [
                            "type" => "array",
                            "items" => ["type" => "string"],
                            "minItems" => 2
                        ]
                    ]
                ],
                "technical_features" => [
                    "type" => "object",
                    "additionalProperties" => false,
                    "required" => ["responsive","semantic_html","structured_data","accessibility"],
                    "properties" => [
                        "responsive" => ["type" => "boolean"],
                        "semantic_html" => ["type" => "boolean"],
                        "structured_data" => ["type" => "boolean"],
                        "accessibility" => ["type" => "boolean"]
                    ]
                ]
            ]
        ]
    ];

    // --- Build payload for your existing openaiChatRequest() wrapper ---

    $messages = [
        ['role' => 'system', 'content' => $system]
    ];
    if ($layoutImageUrl) {
        $messages[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $userContent],
                ['type' => 'image_url', 'image_url' => $layoutImageUrl]
            ]
        ];
    } else {
        $messages[] = ['role' => 'user', 'content' => $userContent];
    }
    
    $postData = [
        'model' => 'gpt-5-mini',
        'messages' => $messages,
        // 'temperature' => 0.2,
        'top_p' => 1.0,
        'presence_penalty' => 0.0,
        'frequency_penalty' => 0.0,
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => $responseSchema
        ],
        // Leave room for full HTML + JSON
        'max_completion_tokens' => 25000
    ];

    $response = openaiChatRequest($postData, $error);
    if (!$response) {
        // $error should already be set by openaiChatRequest
        return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        $error = 'Unexpected API response format.';
        return null;
    }

    $content = trim($json['choices'][0]['message']['content']);

    // Validate the returned JSON strictly; surface helpful error
    try {
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        $error = 'Model returned invalid JSON: ' . $e->getMessage();
        // Optional: log $content for debugging
        return null;
    }

    // Optional convenience: also include an unescaped HTML copy for direct writing
    // (Uncomment if helpful in your pipeline)
    // if (isset($data['html_code'])) {
    //     $data['html_code_unescaped'] = stripcslashes($data['html_code']);
    // }

    return is_array($data) ? $data : null;
}



// The Old Kahuna...
function GPT4generateWebsiteFromData( $businessData, string $additional = '', ?string &$error = null) {
    
    if (is_array($businessData)) {
        $businessData = json_encode($businessData);
    }    
    
    
    $system = 'You are an expert web developer and SEO specialist. You create high-quality, SEO-optimized one-page websites using modern web standards and best practices.';
    $userContent = "Using the following business card data, create a professional one-page website with optimal technical SEO:\n\nBUSINESS DATA:\n" . $businessData . "\n\nADDITIONAL USER REQUIREMENTS AND SUPPLEMENTAL PROMPTS:\n" . $additional . "\n\nREQUIREMENTS:\n1. Use Tailwind CSS framework for styling\n2. Incorporate the exact colors from the business card\n3. Use web-safe fonts that match the business card font characteristics\n4. Include the logo description in appropriate placement\n5. Research and include the likely NAICS code for this business type\n6. Add 300-500 words of relevant, SEO-optimized content about the business/industry\n7. Implement technical SEO best practices:\n   - Proper HTML5 semantic structure\n   - Meta tags (title, description, keywords)\n   - Open Graph tags\n   - Schema.org markup for business info\n   - LD-JSON structured data\n8. Include contact information, business hours (estimated if not provided)\n9. Ensure mobile responsiveness\n10. Add appropriate alt text for images\n11. Include relevant internal anchor links\n12. Optimize for Core Web Vitals\n\nRespond ONLY with valid JSON in this format:\n{\n  \"html_code\": \"complete HTML source code as escaped string\",\n  \"seo_elements\": {\n    \"title_tag\": \"string\",\n    \"meta_description\": \"string\",\n    \"keywords\": [\"string\"],\n    \"naics_code\": \"string\",\n    \"naics_description\": \"string\"\n  },\n  \"content_summary\": {\n    \"word_count\": 0,\n    \"key_topics\": [\"string\"],\n    \"schema_types\": [\"string\"]\n  },\n  \"technical_features\": {\n    \"responsive\": true,\n    \"semantic_html\": true,\n    \"structured_data\": true,\n    \"accessibility\": true\n  }\n}";

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent]
        ],
        'max_tokens' => 4000,
        // 'temperature' => 0.2
    ];

    $response = openaiChatRequest($postData, $error);
    if (!$response) {
        return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        return null;
    }

    $content = trim($json['choices'][0]['message']['content']);
    $data = json_decode($content, true);
    return is_array($data) ? $data : null;
}

function generateFromImages( $businessData, $imageUrl, $id ) {

    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        $error = 'Missing OpenAI API key';
        return null;
    }

    if (is_array($businessData)) {
        $businessData = json_encode($businessData);
    }


    $prompt = 'Can you factor in this information as context for how to interpret this image and how it informs the design of the website. Here is supplemental information (please auto detect its format) - '.$businessData;
    
    $data = [
        'model' => 'gpt-4.1-mini',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => $prompt],
                ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]]
            ]
        ]],
        'max_tokens' => 1500
    ];
    
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return $response;
}


if (!function_exists('openaiCheckImageCompliance')) {
    /**
     * Ask GPT-5-mini (vision) to check an image for prompt violations (e.g., text).
     * Returns:
     *  [
     *    'ok' => bool,            // true if pass, false if fail or error
     *    'verdict' => 'pass'|'fail'|'error',
     *    'has_text' => bool,
     *    'detected_text' => [ ... ],
     *    'reasons' => [ ... ],
     *    'confidence' => float,
     *    'raw' => array,          // raw model JSON (for logging)
     *    'error' => string|null
     *  ]
     */
    function openaiCheckImageCompliance(string $imageUrl, array $rules = [], ?string &$error = null): array
    {
        $rules = $rules ?: [
            'no_text' => true,          // no words, letters, numbers, watermarks, logos-as-text, captions
            'no_ui'   => true,          // no UI chrome, app windows, buttons, etc.
            'no_charts'=> true,         // no labeled charts/graphs (often rendered as text)
        ];

        $system = <<<SYS
You are an expert computer vision QA auditor. Your job is to verify whether an image
violates generation rules like "no text" overlays, watermarks, obvious typography,
UI chrome, or labeled diagrams. Be strict.
Return ONLY valid JSON according to the given schema—no extra commentary.
SYS;

        $ruleText = json_encode($rules, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $user = [
            [
                'type' => 'text',
                'text' => "Audit this image for rule violations. Rules JSON:\n{$ruleText}\n\nIf any text (letters, numbers, words) appears—including watermarks, captions, logos rendered as type, on signs, or as overlaid typography—mark has_text=true and verdict=fail. If clean of text and UI chrome, verdict=pass."
            ],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => $imageUrl
                ]
            ]
        ];

        $responseSchema = [
            "name" => "ImageCompliance",
            "strict" => true,
            "schema" => [
                "type" => "object",
                "additionalProperties" => false,
                "required" => ["verdict","has_text","detected_text","reasons","confidence"],
                "properties" => [
                    "verdict" => ["type" => "string", "enum" => ["pass","fail"]],
                    "has_text" => ["type" => "boolean"],
                    "detected_text" => [
                        "type" => "array",
                        "items" => ["type" => "string"]
                    ],
                    "reasons" => [
                        "type" => "array",
                        "items" => ["type" => "string"]
                    ],
                    "confidence" => ["type" => "number", "minimum" => 0, "maximum" => 1]
                ]
            ]
        ];

        $postData = [
            'model' => 'gpt-5-mini',
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role'  => 'user',  'content' => $user],
            ],
            'temperature' => 0.0,
            'stream' => false,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => $responseSchema
            ],
            'max_tokens' => 600
        ];

        $resp = openaiChatRequest($postData, $error);
        if (!$resp) {
            return ['ok' => false, 'verdict' => 'error', 'has_text' => false, 'detected_text' => [], 'reasons' => ['no_response'], 'confidence' => 0.0, 'raw' => null, 'error' => $error ?: 'No response'];
        }

        $json = json_decode($resp, true);
        $content = $json['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            $finish = $json['choices'][0]['finish_reason'] ?? 'unknown';
            $e = "Empty content from OpenAI (finish_reason={$finish})";
            return ['ok' => false, 'verdict' => 'error', 'has_text' => false, 'detected_text' => [], 'reasons' => [$e], 'confidence' => 0.0, 'raw' => $json, 'error' => $e];
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $t) {
            $e = 'Invalid JSON from OpenAI: ' . $t->getMessage();
            return ['ok' => false, 'verdict' => 'error', 'has_text' => false, 'detected_text' => [], 'reasons' => [$e], 'confidence' => 0.0, 'raw' => $json, 'error' => $e];
        }

        $pass = isset($data['verdict']) && $data['verdict'] === 'pass';
        return [
            'ok' => $pass,
            'verdict' => $data['verdict'] ?? 'error',
            'has_text' => (bool)($data['has_text'] ?? false),
            'detected_text' => $data['detected_text'] ?? [],
            'reasons' => $data['reasons'] ?? [],
            'confidence' => (float)($data['confidence'] ?? 0),
            'raw' => $json,
            'error' => null
        ];
    }
}

if (!function_exists('openaiGenerateImageNoText')) {
    /**
     * Generate an image with OpenAI image model with strict "no text" negatives.
     * Return ['url'=>..., 'raw'=>..., 'error'=>...] or null on failure.
     */
    function openaiGenerateImageNoText(string $prompt, array $opts = [], ?string &$error = null): ?array
    {
        $size = $opts['size'] ?? '1024x1024';

        $negatives = [
            "no text", "no letters", "no words", "no numbers",
            "no watermarks", "no captions", "no signage",
            "no UI elements", "no labeled charts", "no logos-as-text"
        ];

        $fullPrompt = trim($prompt) . "\n\nSTRICT NEGATIVE CONSTRAINTS:\n- " . implode("\n- ", $negatives) . "\n\nHard rule: Do NOT include any text of any kind.";

        // If you already have openaiImageRequest(), use that. Otherwise, call /images.
        // Example using Images API (adjust to your helper):
        $postData = [
            'model' => 'gpt-image-1',  // Or your available OpenAI image model
            'prompt' => $fullPrompt,
            'size' => $size,
            'n' => 1,
            // 'response_format' => 'url'
        ];

        $resp = openaiImageRequest($postData, $error); // your existing wrapper, returns raw JSON
        if (!$resp) return null;

        $json = json_decode($response, true);
        if (
            ! $json ||
            ! isset($json['data'][0]['b64_json'])
        ) {
            error_log(print_r($response, TRUE));
            $error = 'Unexpected response format from OpenAI Images API.';
            return null;
        }
        $b64 = $json['data'][0]['b64_json'];

        $imageData = base64_decode($b64);
        if ($imageData === false) {
            $error = 'Failed to decode base64 image data.';
            return null;
        }
    
        // choose your storage directory
        $gen_img = '/generated_images';
        $uploadDir = __DIR__ .$gen_img;
        if (! is_dir($uploadDir) && ! mkdir($uploadDir, 0755, true)) {
            $error = 'Could not create directory for saving images.';
            return null;
        }
    
        $fileName = uniqid('bc_img_') . '.png';
        $filePath = $uploadDir . '/' . $fileName;
    
        if (file_put_contents($filePath, $imageData) === false) {
            $error = 'Failed to write image file to disk.';
            return null;
        }

        $site = getenv('SITE_URL');
        $url = $site.$gen_img.$fileName;

        if (!$url) {
            $error = 'OpenAI image response missing URL.';
            return null;
        }
        return ['url' => $url, 'raw' => $json, 'error' => null];
    }
}


// Search API Function

class GoogleMapsReviewsFetcherCurl
{
    private $searchApiKey;

    public function __construct($searchApiKey)
    {
        $this->searchApiKey = $searchApiKey;
    }

    private function makeRequest($url, $params = [])
    {
        $query = http_build_query($params);
        $fullUrl = "$url?$query";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->searchApiKey,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("API request failed with HTTP $httpCode: $response");
        }

        return json_decode($response, true);
    }

    public function getPlaceId($query)
    {
        $data = $this->makeRequest('https://www.searchapi.io/api/v1/search', [
            'engine' => 'google_maps',
            'q' => $query,
            'type' => 'search',
        ]);

        if (isset($data['local_results'][0]['place_id'])) {
            return $data['local_results'][0]['place_id'];
        }

        throw new Exception("No place found.");
    }

    public function getReviews($placeId)
    {
        $data = $this->makeRequest('https://www.searchapi.io/api/v1/search', [
            'engine' => 'google_maps_reviews',
            'place_id' => $placeId,
            'sort' => 'newest',
        ]);

        return $data['reviews'] ?? [];
    }

    public function getReviewsByQuery($query)
    {
        error_log( "Searching for: $query" );
        $placeId = $this->getPlaceId($query);
        error_log( "Place ID: $placeId" );

        $reviews = $this->getReviews($placeId);
        return $reviews;
        
    }
}


// Usage


?>
