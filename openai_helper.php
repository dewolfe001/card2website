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

function sendImageToOpenAI(string $imagePath, ?string &$error = null) {
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
                    ['type' => 'text', 'text' => 'Please extract the business card text into structured contact details (name, title, email, phone, company, address). Extract the business card information from this image.'],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . $imageData]]
                ]
                ]
            ]
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

function analyzeBusinessCardStructured(string $imagePath, ?string &$error = null): ?array {
    if (!file_exists($imagePath)) {
        $error = 'File not found';
        return null;
    }

    $imageData = base64_encode(file_get_contents($imagePath));
    if ($imageData === false) {
        error_log("LINE 68 - null");
        return null;
    }

    $system = 'You are an expert at analyzing business cards and extracting structured data. You will receive a business card image and need to extract all relevant information in a specific JSON format.';
    $userPrompt = "Please analyze this business card image and extract all relevant information. Parse the image to identify:\n\n1. All text content (names, titles, company, contact info)\n2. Color palette (primary and secondary colors in hex format)\n3. Font characteristics (serif/sans-serif, weight, style observations)\n4. Logo details (description, position, colors)\n5. Layout and design elements\n6. Industry/business type based on content\n\nRespond ONLY with valid JSON in this exact format:\n{\n  \"business_info\": {\n    \"company_name\": \"string\",\n    \"person_name\": \"string\",\n    \"title\": \"string\",\n    \"phone\": \"string\",\n    \"email\": \"string\",\n    \"website\": \"string\",\n    \"address\": {\n      \"street\": \"string\",\n      \"city\": \"string\",\n      \"state\": \"string\",\n      \"zip\": \"string\",\n      \"country\": \"string\"\n    },\n    \"industry\": \"string\",\n    \"services\": [\"string\"]\n  },\n  \"design_elements\": {\n    \"colors\": {\n      \"primary\": \"#hexcode\",\n      \"secondary\": \"#hexcode\",\n      \"accent\": \"#hexcode\",\n      \"text\": \"#hexcode\"\n    },\n    \"fonts\": {\n      \"primary_font_style\": \"serif|sans-serif|script|display\",\n      \"font_weight\": \"light|normal|bold\",\n      \"characteristics\": \"string description\"\n    },\n    \"logo\": {\n      \"present\": true|false,\n      \"description\": \"string\",\n      \"position\": \"string\",\n      \"style\": \"string\"\n    },\n    \"layout\": {\n      \"style\": \"modern|traditional|creative|minimal\",\n      \"orientation\": \"horizontal|vertical\"\n    }\n  }\n}";

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => $userPrompt],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageData]]
            ]]
        ],
        'max_tokens' => 1500,
        'temperature' => 0.1
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

function generateHtmlWithOpenAI(string $prompt, ?string &$error = null) {

    $postData = [
        'model' => 'gpt-4.1-mini',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 1500
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

function generateWebsiteFromData( $businessData, string $additional = '', ?string &$error = null) {
    
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
        'temperature' => 0.2
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


// helper code

function getInputTypeAndAttributes($value, $fieldName) {
    // Trim whitespace
    $value = trim($value);
    
    // Default return values
    $inputType = 'text';
    $attributes = '';
    
    // Hex color pattern (#RRGGBB)
    if (preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
        $inputType = 'color';
    }
    // Phone number patterns
    elseif (preg_match('/^(\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}$/', $value)) {
        $inputType = 'tel';
        $attributes = ' pattern="[0-9\+\-\.\s\(\)]+" placeholder="Phone number"';
    }
    // Email pattern
    elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $inputType = 'email';
        $attributes = ' placeholder="Email address"';
    }
    // Website/URL pattern
    elseif (preg_match('/^(https?:\/\/)?(www\.)?[a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+([\/\w\-\.]*)*\/?$/', $value)) {
        $inputType = 'url';
        $attributes = ' placeholder="Website URL"';
    }
    // Address-like pattern (contains common address keywords and numbers)
    elseif (preg_match('/\d+.*(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|boulevard|blvd|way|court|ct|place|pl)\b/i', $value)) {
        $inputType = 'text';
        $attributes = ' placeholder="Street address"';
    }
    // Field name-based detection for common address fields
    elseif (preg_match('/^(address|street|city|state|zip|zipcode|postal|country)$/i', $fieldName)) {
        if (preg_match('/^(zip|zipcode|postal)$/i', $fieldName)) {
            $attributes = ' pattern="[0-9A-Za-z\s\-]+" placeholder="Postal/ZIP code"';
        } elseif (preg_match('/^(state)$/i', $fieldName)) {
            $attributes = ' placeholder="State/Province"';
        } elseif (preg_match('/^(country)$/i', $fieldName)) {
            $attributes = ' placeholder="Country"';
        } elseif (preg_match('/^(city)$/i', $fieldName)) {
            $attributes = ' placeholder="City"';
        } else {
            $attributes = ' placeholder="Address"';
        }
    }
    
    return ['type' => $inputType, 'attributes' => $attributes];
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
