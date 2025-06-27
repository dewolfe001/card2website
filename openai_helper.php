<?php
function sendImageToOpenAI(string $imagePath) {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey || !file_exists($imagePath)) {
        error_log("LINE 5 - null");
        return null;
    }

    $imageData = base64_encode(file_get_contents($imagePath));
    if ($imageData === false) {
        error_log("LINE 11 - null");
        return null;
    }

    $postData = [
        'model' => 'gpt-4-vision-preview',
        'messages' => [[
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Extract the text from this business card and return it as plain text.'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $imageData]]
            ]
        ]],
        'max_tokens' => 500
    ];

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
        curl_close($ch);
        error_log("LINE 39 - null");        
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        error_log("LINE 46 - null - $status");        
        return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        error_log("LINE 52 - null");
        return null;
    }

    return trim($json['choices'][0]['message']['content']);
}

function analyzeBusinessCardStructured(string $imagePath): ?array {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey || !file_exists($imagePath)) {
        error_log("LINE 62 - null");
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
        'model' => 'gpt-4o',
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
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
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

function generateHtmlWithOpenAI(string $prompt) {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }

    $postData = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'max_tokens' => 1500
    ];

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
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
        return null;
    }

    $json = json_decode($response, true);
    if (!$json || !isset($json['choices'][0]['message']['content'])) {
        return null;
    }

    return trim($json['choices'][0]['message']['content']);
}

function classifyNaics(string $text): ?array {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }
    $prompt = "Identify the best matching 6-digit NAICS code for this business information and respond in JSON with keys code, title, description only.";
    $postData = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $prompt],
            ['role' => 'user', 'content' => $text]
        ],
        'max_tokens' => 200
    ];
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
        curl_close($ch);
        return null;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
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

function generateWebsiteFromData(array $businessData, string $additional = ''): ?array {
    $apiKey = getenv('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }
    $system = 'You are an expert web developer and SEO specialist. You create high-quality, SEO-optimized one-page websites using modern web standards and best practices.';
    $userContent = "Using the following business card data, create a professional one-page website with optimal technical SEO:\n\nBUSINESS DATA:\n" . json_encode($businessData) . "\n\nADDITIONAL USER REQUIREMENTS:\n" . $additional . "\n\nREQUIREMENTS:\n1. Use Tailwind CSS framework for styling\n2. Incorporate the exact colors from the business card\n3. Use web-safe fonts that match the business card font characteristics\n4. Include the logo description in appropriate placement\n5. Research and include the likely NAICS code for this business type\n6. Add 300-500 words of relevant, SEO-optimized content about the business/industry\n7. Implement technical SEO best practices:\n   - Proper HTML5 semantic structure\n   - Meta tags (title, description, keywords)\n   - Open Graph tags\n   - Schema.org markup for business info\n   - LD-JSON structured data\n8. Include contact information, business hours (estimated if not provided)\n9. Ensure mobile responsiveness\n10. Add appropriate alt text for images\n11. Include relevant internal anchor links\n12. Optimize for Core Web Vitals\n\nRespond ONLY with valid JSON in this format:\n{\n  \"html_code\": \"complete HTML source code as escaped string\",\n  \"seo_elements\": {\n    \"title_tag\": \"string\",\n    \"meta_description\": \"string\",\n    \"keywords\": [\"string\"],\n    \"naics_code\": \"string\",\n    \"naics_description\": \"string\"\n  },\n  \"content_summary\": {\n    \"word_count\": 0,\n    \"key_topics\": [\"string\"],\n    \"schema_types\": [\"string\"]\n  },\n  \"technical_features\": {\n    \"responsive\": true,\n    \"semantic_html\": true,\n    \"structured_data\": true,\n    \"accessibility\": true\n  }\n}";

    $postData = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $userContent]
        ],
        'max_tokens' => 4000,
        'temperature' => 0.2
    ];

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
        curl_close($ch);
        return null;
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status !== 200) {
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
?>
