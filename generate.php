<?php

// Debug logging
error_log("GET - ".print_r($_GET, TRUE));
error_log("POST - ".print_r($_POST, TRUE));
error_log("REQUEST - ".print_r($_REQUEST, TRUE));
error_log("FILES - ".print_r($_FILES, TRUE));

require 'config.php';
require_once 'openai_helper.php';


$id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['id']) ? (int)$_POST['id'] : 0);
$additional = $_POST['additional_details'] ?? '';

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
        }
    }
}

// Add additional details if provided
if (!empty($additional)) {
    $businessData['additional_details'] = $additional;
}

// Log the reconstructed business data
error_log("Reconstructed business data: " . print_r($businessData, true));

// Save the edited data to database
$jsonString = json_encode($businessData, JSON_PRETTY_PRINT);
$stmt = $pdo->prepare('INSERT INTO ocr_edits (upload_id, edited_text, created_at) VALUES (?, ?, NOW())');
$stmt->execute([$id, $jsonString]);


// Handle additional website images
if (!empty($_FILES['website_images']['name'][0])) {
    $imgDir = __DIR__ . '/uploads/site_images/' . $id . '/';
    if (!is_dir($imgDir)) {
        mkdir($imgDir, 0777, true);
    }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $uploadedFiles = [];
    
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

// Generate website from the business data
$result = generateWebsiteFromData($businessData, $additional);
$html = $result['html_code'] ?? null;

if (!$html) {
    // Fallback HTML if generation fails
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title><style>body{font-family:sans-serif;padding:2rem;}</style></head><body><h1>Business Information</h1><pre>" . htmlspecialchars($jsonString) . "</pre></body></html>";
}

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
?>
