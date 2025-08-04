<?php

// Debug logging
error_log("GET - ".print_r($_GET, TRUE));
error_log("POST - ".print_r($_POST, TRUE));
error_log("REQUEST - ".print_r($_REQUEST, TRUE));
error_log("FILES - ".print_r($_FILES, TRUE));

require 'config.php';
require_once 'openai_helper.php';
require_once 'gemini_helper.php';


$additional_incr = 1;

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
                    $stmt = $pdo->prepare('INSERT INTO website_images (upload_id, filename, created_at) VALUES (?, ?, NOW())');
                    $stmt->execute([$id, $name]);
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

if (sizeof($uploadedFiles) > 0) {
    foreach ($uploadedFiles as $uploadFile) {
        $imageUrl = 'https://businesscard2website.com/uploads/site_images/'.$id.'/'.$uploadFile;
        $img_info[$imageUrl] = generateFromImages( $businessData, $imageUrl, $id );
    }
}

$businessArray = json_decode($businessData);

// SEARCHAPI KEY USE

$searchapi = getenv('SEARCHAPI_KEY');
$reviewsFetcher = new GoogleMapsReviewsFetcherCurl($searchapi);

try {
    error_log("154 - ".print_r($businessArray, TRUE));
    error_log("155 - ".print_r($businessData, TRUE));
    error_log("156 - ".print_r($businessInfo, TRUE));    

    $reviews = array();
    foreach ($businessInfo as $key => $info) {
        if ($info['type'] == 'tel') {
            if ($info['attributes'] == ' placeholder="Phone numbers"') {

                // Suppose $businessArray is your stdClass from the database:
                $raw = $businessArray->openai_text;              // a string of JSON
                $data = json_decode($raw);                       // now a stdClass
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new RuntimeException('Invalid JSON in openai_text: ' . json_last_error_msg());
                }
                
                // Make sure we actually have a phone object
                if (isset($data->phone) && is_object($data->phone)) {
                    foreach (get_object_vars($data->phone) as $label => $number) {
                        $new_reviews = $reviewsFetcher->getReviewsByQuery($number);
                    
                        if (is_array($new_reviews)) {
                            // merge the new reviews onto the end of $allReviews
                            $reviews = array_merge($new_reviews, $reviews);
                        }  
                    }
                } else {
                    echo "No phone data to iterate.\n";
                }

                foreach ($businessArray['openai_text']->phone as $num) {
                    $phone = $businessArray[$key];
                    $reviews = $reviewsFetcher->getReviewsByQuery($phone);
                }
            }
            else {
                error_log("199 - ".print_r($businessArray ,TRUE));
 
                if (is_array($businessArray)) {
                    $phone = $businessArray[$key];                    
                }   
                else {

                    $decodedArr = json_decode($businessArray, true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($decodedArr['phone'])) {
                        $phone = $decodedArr['phone'];
                    } else {
                        $phone = null;  // or handle missing/invalid JSON
                    }
                    
                }
                
                $reviews = $reviewsFetcher->getReviewsByQuery($phone);
                break;                
            }
        }
    }
    
    if ((sizeof($reviews)) < 1) {
        // try with the address
        
        if (is_object($businessArray)) {
            // turn it into an array
            $businessTemp = (array) $businessArray->openai_text;
        }
        else {
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
            if ($info['attributes'] == ' pattern="[0-9A-Za-z\s\-]+" placeholder="Postal/ZIP code"') {
                $addr_ele[4] = $businessTemp[$key];
            }            
        }    

        if (sizeof($addr_ele) > 2) {
            $address = implode(' ', $addr_ele);
            $reviews = $reviewsFetcher->getReviewsByQuery($address);
        }
        
    }
    
    error_log("Reviews for ".print_r($reviews, TRUE));
    
    $searchapi_text = array();
    foreach ($reviews as $review) {
        $rating = floatval($review['rating']);
        if ($rating > 3.75) {
            $searchapi_text[] = $review['snippet'];
        }
    }
    
    // form this into part of the prompt

    if (sizeof($searchapi_text) > 0) {
        $searchapi_prompt = 'Use the information from these reviews to create informative and positive text about the business and what it does. Use this informative text as part of the website build, to create creative copy that would let the website visistor know what the business does. Here is the review text in JSON format: '.json_encode($searchapi_text);

        // searchapi information
        $additional .= $additional_incr++.". - ".$searchapi_prompt;
    }
    
} catch (Exception $e) {
    error_log( "Error: " . $e->getMessage() );
}



// image information
foreach ( $img_info as $img_u => $img_t ) {
    $additional .= $additional_incr++.". - Take this image url ".$img_u.". Can you find a way to work the image into the design, or infer from it suggested supporting images and themes. \n";    
    $additional .= $additional_incr++.". - One of the supplied images should be factoed into the web design as either an addition or a contextual influence. Here's what we got from the image where we analyzed it - ".$img_t." \n";    
}

// Generate website from the business data
error_log("Prompt - businessData ".print_r($businessData, TRUE)."\n --- \n".print_r($additional, TRUE));

// get the images 

$img_prompt = generateMarketingOpenAI($businessData, $additional);

// fetch three supporting images - right side, tall and square 


/**
 * Generate a photorealistic image via OpenAI’s Images API,
 * save it to disk and return its path & URL.
 *
 * @param  string      $prompt  Your natural‑language description.
 * @param  string      $size    Desired image size (e.g. "256x256", "512x512", "1024x1024").
 * @param  string|null &$error  If anything goes wrong, this will be populated.
 * @return array|null          ['file_path'=>string, 'url'=>string] or null on failure.
 */
 
 
$main_img_prompt = "Make an image that will work great above the fold. Size it  1024x1024 ".$img_prompt;
$main_image = generateBusinessCardImageGemini($main_img_prompt);
$additional .= $additional_incr++.". - This supplied images should be added into the web design and put in an appropriate spot in the page near the top. Here's the JSON encoded information about this image's file_path and its url- ".json_encode($main_image)." \n";    

$side_img_prompt = "Make an image that fit to the side of the website content. Size it 1024x1792 ".$img_prompt;
$side_image = generateBusinessCardImageGemini($side_img_prompt);
$additional .= $additional_incr++.". - This supplied images should be added into the web design and put in an appropriate spot in the page on the right hand side with text to the left of the image in a two-column set up that stacks vertically on tablet portrait views and smaller viewports. When building the HTML code, place the image as a background image for the right hand side so that the 'background: cover;' CSS is used the display that image but limit how much white space there is on the page. Here's the JSON encoded information about this image's file_path and its url- ".json_encode($side_image)." \n";    

$square_img_prompt = "Make an image that will work great low on the web page. Size it  1024x1024 ".$img_prompt;
$square_image = generateBusinessCardImageGemini($square_img_prompt);
$additional .= $additional_incr++.". - This supplied images should be added into the web design and put low on the page, beside the contact information at the bottom. It should be added as a 'background: contain' CSS element, to limit how much white space ends up in the HTML display. Here's the JSON encoded information about this image's file_path and its url - ".json_encode($square_image)." \n";    


$result = generateWebsiteFromData($businessData, $additional);
error_log("Generated - ".print_r($result, TRUE));
$html = $result['html_code'] ?? null;

if (!$html) {
    // Fallback HTML if generation fails
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title><style>body{font-family:sans-serif;padding:2rem;}</style></head><body><h1>Business Information</h1><pre>" . htmlspecialchars($jsonString) . "</pre></body></html>";
}

// put in our branding

$new_footer = '<span class="credits"><a href="https://businesscard2website.com/" target="_blank" title="Turn your business card into a website.">Get Your Own Website</a></span></footer>';

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

?>
