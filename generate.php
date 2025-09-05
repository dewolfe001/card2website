<?php

// Debug logging
error_log("GET - ".print_r($_GET, TRUE));
error_log("POST - ".print_r($_POST, TRUE));
error_log("REQUEST - ".print_r($_REQUEST, TRUE));
error_log("FILES - ".print_r($_FILES, TRUE));

require 'config.php';
require_once 'openai_helper.php';
require_once 'gemini_helper.php';
require_once 'auth.php';
require_once 'i18n.php';

if (isset($_POST['output_lang'])) {
    setOutputLanguage($_POST['output_lang']);
}

$baseUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];

define('BASEURL', $baseUrl);

$additional_incr = 1;
$inputLang = getAppLanguage();
$outputLang = getOutputLanguage();

$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);

if ($_POST['additional_details']) {
    $additional = $additional_incr++. ' '.$_POST['additional_details'] ?? ''.
    "\n";
}

$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();
if (!$upload) {
    die('Upload not found');
}

$cardImagePath = null;
if (!empty($upload['filename'])) {
    $cardImagePath = __DIR__ . '/uploads/' . $upload['filename'];
    if (!file_exists($cardImagePath)) {
        $remoteUrl = 'https://businesscard2website.com/uploads/' . $upload['filename'];
        $imgData = @file_get_contents($remoteUrl);
        if ($imgData !== false) {
            if (!is_dir(__DIR__ . '/uploads')) {
                mkdir(__DIR__ . '/uploads', 0777, true);
            }
            file_put_contents($cardImagePath, $imgData);
        } else {
            $cardImagePath = null;
        }
    }
}

if ($cardImagePath === null) {
    error_log('Card image not found locally or remotely for upload ID ' . $id);
}

$stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || !isset($row['json_data'])) {
    die('No OCR data available');
}

// Build business data from form fields
$businessData = [];

// Check if we have structured form fields or raw text
if (isset($_POST['raw_analysis_text'])) {
    // Raw text submission - use as is
    $businessData['raw_text'] = $_POST['raw_analysis_text'];
} else {
    // Structured form fields - rebuild the data structure
    foreach ($_POST as $key => $value) {
        if ($key === 'id' || $key === 'additional_details') {
            continue; // Skip these special fields
        }
        
        // Handle nested array notation like business_info[company_name]
        if (strpos($key, '[') !== false) {
            $parts = [];
            preg_match_all('/([^\[\]]+)/', $key, $matches);
            if ($matches[1]) {
                $current = &$businessData;
                foreach ($matches[1] as $part) {
                    if (!isset($current[$part])) {
                        $current[$part] = [];
                    }
                    $current = &$current[$part];
                }
                $current = $value;
            }
        } else {
            // Simple field

            $businessData[$key] = $value;
            $businessInfo[$key] = getInputTypeAndAttributes($value, $key);      
        }
    }
}

// Add additional details if provided
if (!empty($additional)) {
    $businessData['additional_details'] = $additional;
    $additional .= $additional_incr++. ' '.$additional." \n";
}

if ($_POST['personal_corp'] == 'corp') {
        $additional .= $additional_incr++. " Strip out personal references in favor of highlighting the company and what it does. Downplay the name from the business card.  \n";
}

$additional .= $additional_incr++. " If you include a Copyright notice make sure it's for ".date("Y")."  \n";

$additional .= $additional_incr++. " If you can't confirm the business hours leave them out of the site generation.  \n";

// Log the reconstructed business data
error_log("Reconstructed business data: " . print_r($businessData, true));

// Save the edited data to database
$jsonString = json_encode($businessData, JSON_PRETTY_PRINT);
$stmt = $pdo->prepare('INSERT INTO ocr_edits (upload_id, edited_text, created_at) VALUES (?, ?, NOW())');
$stmt->execute([$id, $jsonString]);

$layoutImageUrl = null;
if (!empty($_POST['layout_choice'])) {
    $previewFile = basename($_POST['layout_choice']);
    $fullFile = str_replace('_preview', '_fullsize', $previewFile);
    $layoutImageUrl = BASEURL . '/site_layouts/' . $fullFile;
}

$uploadedFiles = [];
// Handle additional website images
if (!empty($_FILES['website_images']['name'][0])) {
    $imgDir = __DIR__ . '/uploads/site_images/' . $id . '/';
    if (!is_dir($imgDir)) {
        mkdir($imgDir, 0777, true);
    }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    
    foreach ($_FILES['website_images']['tmp_name'] as $idx => $tmp) {
        if ($_FILES['website_images']['error'][$idx] === UPLOAD_ERR_OK) {
            // Use a more reliable method to get MIME type
            $type = getMimeType($tmp, $_FILES['website_images']['name'][$idx]);
            
            if (in_array($type, $allowed)) {
                $name = basename($_FILES['website_images']['name'][$idx]);
                $name = time() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
                $dest = $imgDir . $name;
                if (move_uploaded_file($tmp, $dest)) {
                    $stmt = $pdo->prepare('INSERT INTO website_images (upload_id, user_id, filename, file_url, created_at) VALUES (?, ?, ?, ?, NOW())');
                    $stmt->execute([
                        $id,
                        current_user_id(),
                        $name,
                        BASEURL . '/uploads/site_images/' . $id . '/' . $name
                    ]);
                    $uploadedFiles[] = $name;
                }
            }
        }
    }
    
    error_log("Uploaded files: " . print_r($uploadedFiles, true));
}


// additional additionals


// uploaded images and how they influence things

$img_info = array();

$stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || !isset($row['json_data'])) {
    error_log('No OCR data available');
}
else {
    $businessData = $row['json_data'];
}

$img_info = [];

// ensure async working directory and context file exist
$asyncDir = __DIR__ . '/async_tasks/';
if (!is_dir($asyncDir)) {
    mkdir($asyncDir, 0777, true);
}
$contextPath = $asyncDir . $id . '_context.json';
file_put_contents($contextPath, json_encode([
    'business_data' => $businessData,
    'additional' => $additional
]));

// dispatch image analysis tasks concurrently
if (sizeof($uploadedFiles) > 0) {
    $analysisJobs = [];
    foreach ($uploadedFiles as $idx => $uploadFile) {
        $imageUrl = 'https://businesscard2website.com/uploads/site_images/'.$id.'/'.$uploadFile;
        $jobFile = $asyncDir . "{$id}_imginfo_{$idx}.json";
        $analysisJobs[$jobFile] = $imageUrl;
        $cmd = "curl -s '".BASEURL."/async_task.php?action=analyze_image&id={$id}&img=" . urlencode($imageUrl) . "&idx={$idx}' > /dev/null 2>&1 &";
        exec($cmd);
    }

    // poll for completion of analysis tasks
    $start = time();
    $timeout = 120; // seconds
    while (time() - $start < $timeout && count($analysisJobs) > 0) {
        foreach ($analysisJobs as $file => $url) {
            if (file_exists($file)) {
                $img_info[$url] = file_get_contents($file);
                unset($analysisJobs[$file]);
            }
        }
        if (count($analysisJobs) > 0) {
            usleep(500000); // wait 0.5 sec before next poll
        }
    }
}

$businessArray = json_decode($businessData);

// SEARCHAPI KEY USE

$searchapi = getenv('SEARCHAPI_KEY');
$reviewsFetcher = new GoogleMapsReviewsFetcherCurl($searchapi);

// Reviews will be fetched asynchronously
$reviews = [];
$searchapi_text = [];

try {
    error_log("154 - ".print_r($businessArray, TRUE));
    error_log("155 - ".print_r($businessData, TRUE));
    error_log("156 - ".print_r($businessInfo, TRUE));

    // build list of review queries
    $reviewQueries = [];
    foreach ($businessInfo as $key => $info) {
        if ($info['type'] == 'tel') {
            if ($info['attributes'] == ' placeholder="Phone numbers"') {
                $raw = $businessArray->openai_text;
                $data = json_decode($raw);
                if (json_last_error() === JSON_ERROR_NONE && isset($data->phone) && is_object($data->phone)) {
                    foreach (get_object_vars($data->phone) as $label => $number) {
                        $reviewQueries[] = $number;
                    }
                }
            } else {
                if (is_array($businessArray)) {
                    $phone = $businessArray[$key];
                } else {
                    $decodedArr = json_decode($businessArray, true);
                    $phone = ($decodedArr && isset($decodedArr['phone'])) ? $decodedArr['phone'] : null;
                }
                if ($phone) {
                    $reviewQueries[] = $phone;
                }
            }
        }
    }

    if (empty($reviewQueries)) {
        if (is_object($businessArray)) {
            $businessTemp = (array)$businessArray->openai_text;
        } else {
            $businessTemp = $businessArray;
        }
        $addr_ele = array();
        foreach ($businessInfo as $key => $info) {
            if ($info['attributes'] == ' placeholder="Address"') {
                $addr_ele[0] = $businessTemp[$key];
            }
            if ($info['attributes'] == ' placeholder="City"') {
                $addr_ele[1] = $businessTemp[$key];
            }
            if ($info['attributes'] == ' placeholder="State/Province"') {
                $addr_ele[2] = $businessTemp[$key];
            }
            if ($info['attributes'] == ' placeholder="Country"') {
                $addr_ele[3] = $businessTemp[$key];
            }
            if ($info['attributes'] == ' pattern="[0-9A-Za-z\\s\\-]+" placeholder="Postal/ZIP code"') {
                $addr_ele[4] = $businessTemp[$key];
            }
        }
        if (sizeof($addr_ele) > 2) {
            $reviewQueries[] = implode(' ', $addr_ele);
        }
    }

    // fire off review requests concurrently
    $reviewJobs = [];
    foreach ($reviewQueries as $idx => $query) {
        $file = $asyncDir . "{$id}_reviews_{$idx}.json";
        $reviewJobs[$file] = $query;
        $cmd = "curl -s '".BASEURL."/async_task.php?action=fetch_reviews&id={$id}&query=" . urlencode($query) . "&idx={$idx}' > /dev/null 2>&1 &";
        exec($cmd);
    }

    // poll for results
    if (count($reviewJobs) > 0) {
        $start = time();
        $timeout = 120;
        while (time() - $start < $timeout && count($reviewJobs) > 0) {
            foreach ($reviewJobs as $file => $query) {
                if (file_exists($file)) {
                    $data = json_decode(file_get_contents($file), true);
                    if (is_array($data)) {
                        $reviews = array_merge($reviews, $data);
                    }
                    unset($reviewJobs[$file]);
                }
            }
            if (count($reviewJobs) > 0) {
                usleep(500000);
            }
        }
    }

    foreach ($reviews as $review) {
        $rating = floatval($review['rating'] ?? 0);
        if ($rating > 3.75) {
            $searchapi_text[] = $review['snippet'];
        }
    }

    if (sizeof($searchapi_text) > 0) {
        $searchapi_prompt = 'Use the information from these reviews to create informative and positive text about the business and what it does. Use this informative text as part of the website build, to create creative copy that would let the website visitor know what the business does. Here is the review text in JSON format: '.json_encode($searchapi_text);
        $additional .= $additional_incr++.". - ".$searchapi_prompt;
    }

} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
}

// image information
foreach ( $img_info as $img_u => $img_t ) {
    $additional .= $additional_incr++.". - Take this image url ".$img_u.". Can you find a way to work the image into the design, or infer from it suggested supporting images and themes. \n";
    $additional .= $additional_incr++.". - One of the supplied images should be factoed into the web design as either an addition or a contextual influence. Here's what we got from the image where we analyzed it - ".$img_t." \n";
}

$additional .= $additional_incr++.". - Any imagery generated should highlight the business's products, services, or environment and must not depict people or the business owner. Focus on tools, locations, or symbols that represent the business type. \n";

// Generate website from the business data
error_log("Prompt - businessData ".print_r($businessData, TRUE)."\n --- \n".print_r($additional, TRUE));

// get the images

$imageBusinessData = json_decode($businessData, true);
if (is_array($imageBusinessData)) {
    stripPersonalInfo($imageBusinessData);
    $businessDataForImages = json_encode($imageBusinessData);
} else {
    $businessDataForImages = $businessData;
}

$img_prompt = generateMarketingOpenAI($businessDataForImages, $additional, $outputLang);
$context = json_decode(file_get_contents($contextPath), true);
$context['img_prompt'] = $img_prompt;
file_put_contents($contextPath, json_encode($context));

// fire image generation requests concurrently
$imageTasks = [
    'main'   => '1024x1024',
    'side'   => '1024x1536',
    'square' => '1024x1024'
];
$imageJobs = [];
foreach ($imageTasks as $type => $size) {
    $file = $asyncDir . "{$id}_{$type}.json";
    $imageJobs[$file] = $type;
    $cmd = "curl -s '".BASEURL."/async_task.php?action=generate_image&id={$id}&type={$type}&size={$size}' > /dev/null 2>&1 &";
    exec($cmd);
}

// poll for images
$images = [];
$start = time();
$timeout = 300;
while (time() - $start < $timeout && count($imageJobs) > 0) {
    foreach ($imageJobs as $file => $type) {
        if (file_exists($file)) {
            $images[$type] = json_decode(file_get_contents($file), true);
            unset($imageJobs[$file]);
        }
    }
    if (count($imageJobs) > 0) {
        usleep(500000);
    }
}

$main_image = $images['main'] ?? null;
$side_image = $images['side'] ?? null;
$square_image = $images['square'] ?? null;

if ($main_image) {
    $additional .= $additional_incr++.". - This supplied images should be added into the web design and put in an appropriate spot in the page near the top. Here's the JSON encoded information about this image's file_path and its url- ".json_encode($main_image)." \n";
}
if ($side_image) {
    $additional .= $additional_incr++.". - This supplied images should be added into the web design and put in an appropriate spot in the page on the right hand side with text to the left of the image in a two-column set up that stacks vertically on tablet portrait views and smaller viewports. When building the HTML code, place the image as a background image for the right hand side. Use the 'background: cover; height: 100%; background-position: top' CSS for the display that image but limit how much white space there is on the page. Here's the JSON encoded information about this image's file_path and its url- ".json_encode($side_image)." \n";
}
if ($square_image) {
    $additional .= $additional_incr++.". - This supplied images should be added into the web design and put low on the page, beside the contact information at the bottom. It should be added as 'background: cover; height: 100%; background-position: top' CSS styling, to limit how much white space ends up in the HTML display. Here's the JSON encoded information about this image's file_path and its url - ".json_encode($square_image)." \n";
}

$result = generateWebsiteFromData($businessData, $additional, $layoutImageUrl, $inputLang, $outputLang);
error_log("Generated - ".print_r($result, TRUE));
$html = $result['html_code'] ?? null;

if (!$html) {
    // Fallback HTML if generation fails
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title><style>body{font-family:sans-serif;padding:2rem;}</style></head><body><h1>Business Information</h1><pre>" . htmlspecialchars($jsonString) . "</pre></body></html>";
}

// put in our branding
$new_footer = '<span class="credits" style="float: right; text-align: right;"><a href="https://businesscard2website.com/" target="_blank" title="Turn your business card into a website.">Get Your Own Website</a></span></footer>';
$html = str_replace('</footer>', $new_footer, $html);

$html = str_replace('\n', "\n", $html);
$html = stripslashes($html);
// $html = str_replace('\\n', "\n", $html);

// Save generated site
$dir = __DIR__ . '/generated_sites/';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$file = $dir . $id . '.html';
file_put_contents($file, $html);

$stmt = $pdo->prepare('INSERT INTO generated_sites (upload_id, html_code, public_url, created_at) VALUES (?, ?, ?, NOW())');
$stmt->execute([$id, $html, $file]);

header('Location: view_site.php?id=' . $id);
exit;



// helper code

function getInputTypeAndAttributes($value, $fieldName) {
    // Trim whitespace
    
    if (is_array($value)) {
        return ['type' => 'array', 'attributes' => 'n/a'];            
    }
    
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
    // Phone group patterns
    elseif (is_array($value)) {
        foreach ($value as $val) {
            if (preg_match('/^(\+?1[-.\s]?)?\(?[0-9]{3}\)?[-.\s]?[0-9]{3}[-.\s]?[0-9]{4}$/', $val)) {
                $inputType = 'tel';
                $attributes = ' placeholder="Phone numbers"';
            }
        }

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

// Add this helper function at the top of your generate.php file (after the requires)
function getMimeType($filePath, $fileName = '') {
    // Method 1: Try fileinfo extension (most reliable)
    if (extension_loaded('fileinfo')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mimeType = finfo_file($finfo, $filePath);
            finfo_close($finfo);
            if ($mimeType) {
                return $mimeType;
            }
        }
    }
    
    // Method 2: Try mime_content_type if available
    if (function_exists('mime_content_type')) {
        $mimeType = mime_content_type($filePath);
        if ($mimeType) {
            return $mimeType;
        }
    }
    
    // Method 3: Fallback to file extension
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp'
    ];
    
    return $mimeTypes[$extension] ?? 'application/octet-stream';
}

function stripPersonalInfo(array &$data) {
    foreach ($data as $key => &$value) {
        if (in_array(strtolower($key), ['name','title','first_name','last_name','owner','person_name'])) {
            unset($data[$key]);
            continue;
        }
        if (is_array($value)) {
            stripPersonalInfo($value);
        }
    }
}


/**
 * Generate an image via Gemini, then have GPT-5-mini check for violations (esp. text).
 * Auto-retries Gemini with stricter negatives/new seed up to $maxTries.
 *
 * @param string $basePrompt  The positive prompt for the image
 * @param array  $options     ['size'=>'1024x1024','max_tries'=>3,'rules'=>[...],'fallback_openai'=>false,'openai_model'=>'gpt-image-1']
 * @param string|null &$error Error message (if any)
 * @return array|null ['url'=>..., 'attempts'=>int, 'audit'=>array] or null on failure
 */
function generatePolicedImage(string $basePrompt, array $options = [], ?string &$error = null): ?array
{
    $size         = $options['size']        ?? '1024x1024';
    $maxTries     = max(1, (int)($options['max_tries'] ?? 3));
    $rules        = $options['rules']       ?? ['no_text' => true, 'no_ui' => true, 'no_charts' => true];
    $negatives    = $options['negatives']   ?? ["no text", "no letters", "no words", "no numbers", "no watermark", "no captions", "no signage", "no labels", "no UI elements", "no icons with letters"];
    $fallbackOI   = (bool)($options['fallback_openai'] ?? false);
    $openaiImg    = $options['openai_model'] ?? 'gpt-image-1'; // if you want an OpenAI fallback

    $auditLog = [];
    $lastErr  = null;

    for ($attempt = 1; $attempt <= $maxTries; $attempt++) {
        $opts = [
            'size' => $size,
            'seed' => random_int(1, PHP_INT_MAX),
            'negative_hints' => $negatives,
        ];
        $g = geminiGenerateImage($basePrompt, $opts);
        if (!$g['ok'] || empty($g['url'])) {
            $lastErr = $g['error'] ?? 'Gemini image generation failed.';
            $auditLog[] = ['attempt' => $attempt, 'stage' => 'gemini', 'ok' => false, 'error' => $lastErr];
            continue;
        }

        // Audit with GPT-5 mini (vision)
        $audit = openaiCheckImageCompliance($g['url'], $rules, $e);
        $auditLog[] = ['attempt' => $attempt, 'stage' => 'audit', 'ok' => $audit['ok'], 'audit' => $audit, 'error' => $e];

        if ($audit['ok']) {
            return ['url' => $g['url'], 'attempts' => $attempt, 'audit' => $auditLog];
        }

        // Strengthen negatives for next try, depending on what was found
        if (!empty($audit['detected_text'])) {
            // Add a precise negative matching a common failure mode
            $negatives[] = "avoid any visible letters or numbers in the scene";
            $negatives[] = "no signage or text-bearing surfaces";
            $negatives = array_values(array_unique($negatives));
        }
    }

    // Optional: fall back to OpenAI image model if Gemini keeps ignoring negatives
    if ($fallbackOI) {
        $img = openaiGenerateImageNoText($basePrompt, ['size' => $size], $error);
        if ($img && !empty($img['url'])) {
            // Double-check with GPT-5 as well
            $audit = openaiCheckImageCompliance($img['url'], $rules, $e2);
            $auditLog[] = ['attempt' => 'fallback_openai', 'stage' => 'audit', 'ok' => $audit['ok'], 'audit' => $audit, 'error' => $e2];
            if ($audit['ok']) {
                return ['url' => $img['url'], 'attempts' => $maxTries + 1, 'audit' => $auditLog];
            }
            $lastErr = 'OpenAI fallback image still failed audit.';
        } else {
            $lastErr = $error ?: 'OpenAI fallback image generation failed.';
        }
    }

    error_log("generatePolicedImage - " . print_r($auditLog, TRUE));
    error_log("generatePolicedImage - " . print_r($lastErr, TRUE));

    $error = $lastErr ?: 'All attempts failed to produce a compliant image.';
    return null;
}
    
/**
 * Save one or more images returned as base64 (OpenAI/Gemini "b64_json") and return URLs.
 *
 * @param array  $apiResponse JSON-decoded response that contains ['data'][i]['b64_json']
 * @param string $saveDirAbsolute Absolute directory path to save files (e.g. __DIR__.'/uploads/images')
 * @param string $publicUrlBase  Public base URL that maps to $saveDirAbsolute (e.g. 'https://example.com/uploads/images')
 * @param string $prefix         Optional filename prefix
 * @return array                 List of saved images with url/path/meta
 */
function saveImagesFromB64JsonResponse(array $apiResponse, string $saveDirAbsolute, string $publicUrlBase, string $prefix = 'img')
{
    if (!isset($apiResponse['data']) || !is_array($apiResponse['data'])) {
        return [];
    }

    // Ensure save directory exists
    if (!is_dir($saveDirAbsolute)) {
        if (!mkdir($saveDirAbsolute, 0755, true) && !is_dir($saveDirAbsolute)) {
            throw new RuntimeException("Failed to create directory: $saveDirAbsolute");
        }
    }

    // Normalize base URL (no trailing slash)
    $publicUrlBase = rtrim($publicUrlBase, '/');

    $results = [];
    foreach ($apiResponse['data'] as $i => $item) {
        if (empty($item['b64_json'])) {
            continue;
        }

        // 1) Decode base64
        $raw = base64_decode($item['b64_json'], true);
        if ($raw === false) {
            // Some APIs might return url-safe base64; try a repair pass
            $raw = base64_decode(strtr($item['b64_json'], '-_', '+/'), true);
            if ($raw === false) {
                // skip this one
                continue;
            }
        }

        // 2) Detect mime/type & dimensions
        $imgInfo = @getimagesizefromstring($raw); // returns [width, height, type, attr, 'mime'=>...]
        $mime = $imgInfo['mime'] ?? 'image/png'; // OpenAI often returns PNG for b64_json
        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/jpeg' => 'jpg',
            default      => 'png',  // fallback
        };

        // 3) Create unique filename
        $rand  = bin2hex(random_bytes(6));
        $fname = sprintf('%s_%s_%02d.%s', $prefix, date('Ymd_His'), $i, $ext);
        // If you prefer shorter names: $fname = sprintf('%s_%s.%s', $prefix, $rand, $ext);

        $absPath = rtrim($saveDirAbsolute, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fname;
        $ok = file_put_contents($absPath, $raw);
        if ($ok === false) {
            // Couldn’t write—skip
            continue;
        }

        // 4) Build public URL
        $url = $publicUrlBase . '/' . rawurlencode($fname);

        $results[] = [
            'index'     => $i,
            'url'       => $url,
            'path'      => $absPath,
            'filename'  => $fname,
            'mime'      => $mime,
            'width'     => $imgInfo[0] ?? null,
            'height'    => $imgInfo[1] ?? null,
            'background'=> $apiResponse['background'] ?? null,
            'created'   => $apiResponse['created'] ?? null,
        ];
    }

    return $results;
}
