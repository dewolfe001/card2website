<?php
// upload_image.php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

function fail(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Detect the MIME type of a file in a robust way.
 *
 * Some hosting environments may not have the Fileinfo extension
 * enabled which provides the `finfo` class.  Attempt to use it when
 * available and gracefully fall back to other PHP functions so that
 * uploads don't trigger a fatal error.
 */
function detectMimeType(string $tmpPath): string
{
    // Prefer finfo when available
    if (class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmpPath);
        if ($mime !== false) {
            return $mime;
        }
    }

    // Fall back to mime_content_type if provided
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
        if ($mime !== false) {
            return $mime;
        }
    }

    // Last resort: use getimagesize which returns MIME for images
    if (function_exists('getimagesize')) {
        $info = getimagesize($tmpPath);
        if ($info !== false && isset($info['mime'])) {
            return $info['mime'];
        }
    }

    return '';
}

if (empty($_POST['nonce']) || empty($_SESSION['editor_nonce']) || !hash_equals($_SESSION['editor_nonce'], (string) $_POST['nonce'])) {
    fail('Invalid nonce', 403);
}

// (Optional) check auth here
$siteId = isset($_POST['site_id']) ? (int) $_POST['site_id'] : 0;
if ($siteId <= 0) {
    fail('Invalid site id');
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    fail('No image uploaded');
}

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
    'image/avif' => 'avif'
];
$mime  = detectMimeType($_FILES['image']['tmp_name']);
if (!isset($allowed[$mime])) {
    fail('Unsupported image type');
}

$ext      = $allowed[$mime];
$maxBytes = 8 * 1024 * 1024; // 8MB
if (filesize($_FILES['image']['tmp_name']) > $maxBytes) {
    fail('Image too large (max 8MB)');
}

// Destination path (adjust as needed)
$baseDir = __DIR__ . '/uploads/site_images/' . $siteId;
if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true)) {
    fail('Cannot create upload directory', 500);
}

$basename = bin2hex(random_bytes(8)) . '.' . $ext;
$destPath = $baseDir . '/' . $basename;

// Move
if (!move_uploaded_file($_FILES['image']['tmp_name'], $destPath)) {
    fail('Failed to store file', 500);
}

// Build public URL for image
$publicUrl = '/uploads/site_images/' . $siteId . '/' . $basename;
$fileUrl = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $publicUrl;

// Record uploaded image
try {
    $stmt = $pdo->prepare('INSERT INTO website_images (upload_id, user_id, filename, file_url, created_at) VALUES (?, ?, ?, ?, NOW())');
    $stmt->execute([$siteId, current_user_id(), $basename, $fileUrl]);
} catch (Throwable $e) {
    // If DB insert fails we still return the image URL, but log the error
    error_log('Image insert failed: ' . $e->getMessage());
}

// Return public URL (adjust domain/path mapping for your hosting)
echo json_encode([
    'success' => true,
    'url'     => $publicUrl
], JSON_UNESCAPED_SLASHES);
