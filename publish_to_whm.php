<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'whm_helper.php';

// Add the deployment functions directly in this file for now
if (!function_exists('removeDirectory')) {
    function removeDirectory(string $dir): bool {
        if (!is_dir($dir)) {
            return false;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        
        return rmdir($dir);
    }
}

if (!function_exists('testConnectivity')) {
    function testConnectivity(string $host, array $ports): array {
        $results = [];
        
        foreach ($ports as $port) {
            $startTime = microtime(true);
            $connection = @fsockopen($host, $port, $errno, $errstr, 5);
            $endTime = microtime(true);
            
            if ($connection) {
                fclose($connection);
                $results[$port] = [
                    'status' => 'open',
                    'time' => round(($endTime - $startTime) * 1000, 2) . 'ms'
                ];
            } else {
                $results[$port] = [
                    'status' => 'blocked/closed',
                    'error' => "$errno: $errstr"
                ];
            }
        }
        
        return $results;
    }
}

function createZipFromDirectory(string $sourceDir): ?string {
    if (!is_dir($sourceDir)) {
        return null;
    }
    
    if (!class_exists('ZipArchive')) {
        error_log('ZipArchive extension required for deployment');
        return null;
    }
    
    $zipPath = sys_get_temp_dir() . '/deploy_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }
    
    $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getPathname();
            $relativePath = substr($filePath, strlen($sourceDir) + 1);
            $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
            $zip->addFile($filePath, $relativePath);
        }
    }
    
    $zip->close();
    return $zipPath;
}

if (!function_exists('createDeploymentPackage')) {
    function createDeploymentPackage(int $uploadId): array {
        global $pdo;
        $packageDir = __DIR__ . '/deployment_packages';
        if (!is_dir($packageDir)) {
            mkdir($packageDir, 0755, true);
        }
        
        // Create unique package directory
        $packageId = sprintf('%d_%d_%s', $uploadId, time(), bin2hex(random_bytes(4)));
        $packagePath = $packageDir . '/pkg_' . $packageId;
        
        if (!mkdir($packagePath, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create package directory', 'path' => null];
        }
        
        // Check if generated site directory exists
        $siteDir = __DIR__ . '/generated_sites/' . $uploadId;
        if (!is_dir($siteDir)) {
            removeDirectory($packagePath);
            return ['ok' => false, 'error' => 'Generated site directory not found: ' . $siteDir, 'path' => null];
        }
        
        // Check for index.html in the site directory
        $htmlFile = $siteDir . '/index.html';
        if (!file_exists($htmlFile)) {
            removeDirectory($packagePath);
            return ['ok' => false, 'error' => 'index.html not found in site directory', 'path' => null];
        }
        
        // Copy the entire site directory structure to the package
        $copiedFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($siteDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($siteDir) + 1);
            $destPath = $packagePath . DIRECTORY_SEPARATOR . $relativePath;
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                if (copy($item->getPathname(), $destPath)) {
                    $copiedFiles[] = $relativePath;
                }
            }
        }

        // Include generated images referenced for this upload
        $stmt = $pdo->prepare('SELECT filename FROM website_images WHERE upload_id = ?');
        $stmt->execute([$uploadId]);
        $dbImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($dbImages) {
            $srcImgDir = __DIR__ . '/uploads/site_images/' . $uploadId;
            $destImgDir = $packagePath . '/generated_images';
            if (!is_dir($destImgDir)) {
                mkdir($destImgDir, 0755, true);
            }
            foreach ($dbImages as $img) {
                $src = $srcImgDir . '/' . $img;
                if (file_exists($src)) {
                    copy($src, $destImgDir . '/' . $img);
                    $copiedFiles[] = 'generated_images/' . $img;
                }
            }

            // Update HTML to reference relative image paths
            $packageIndex = $packagePath . '/index.html';
            if (file_exists($packageIndex)) {
                $html = file_get_contents($packageIndex);
                $pattern = '#https?://[^/]+/uploads/site_images/' . $uploadId . '/#';
                $html = preg_replace($pattern, '/generated_images/', $html);
                file_put_contents($packageIndex, $html);
            }
        }

        // Read HTML content to analyze what was copied
        $htmlContent = file_exists($packagePath . '/index.html') ? file_get_contents($packagePath . '/index.html') : '';
        $imageFiles = array_filter($copiedFiles, function($file) {
            return preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $file);
        });
        
        return [
            'ok' => true, 
            'path' => $packagePath, 
            'id' => $packageId,
            'files' => [
                'total_files' => count($copiedFiles),
                'html_files' => array_filter($copiedFiles, function($file) { return pathinfo($file, PATHINFO_EXTENSION) === 'html'; }),
                'image_files' => $imageFiles,
                'other_files' => array_diff($copiedFiles, $imageFiles)
            ]
        ];
    }
}

if (!function_exists('deployPackageToHosting')) {
    function deployPackageToHosting(string $packagePath, string $username, string $password): array {
        error_log("deployPackageToHosting called with packagePath: $packagePath, username: $username");
        
        if (!is_dir($packagePath)) {
            error_log("Package directory not found: $packagePath");
            return ['ok' => false, 'error' => 'Package directory not found'];
        }
        
        // Get server URL from WHM_HOST, but change port to 2083 for cPanel
        $whmHost = getenv('WHM_HOST');
        if (!$whmHost) {
            error_log("WHM_HOST not configured");
            return ['ok' => false, 'error' => 'WHM_HOST not configured'];
        }
        
        $p = parse_url($whmHost);
        $scheme = $p['scheme'] ?? 'https';
        $host = $p['host'] ?? '';
        
        // Build cPanel URL using same hostname but try port 2083 first, fallback to 2082
        $cpanelHttpsUrl = $scheme . '://' . $host . ':2083';
        $cpanelHttpUrl = 'http://' . $host . ':2082';
        
        error_log("Primary cPanel URL: $cpanelHttpsUrl, Fallback: $cpanelHttpUrl from WHM_HOST: $whmHost");
        
        // Test connectivity first
        $connectTest = testConnectivity($host, [2083, 2082]);
        error_log("Connectivity test: " . print_r($connectTest, true));
        
        // Choose the best URL based on connectivity
        $cpanelBaseUrl = $cpanelHttpsUrl; // Default to HTTPS
        if (!empty($connectTest[2083]) && $connectTest[2083]['status'] !== 'open') {
            if (!empty($connectTest[2082]) && $connectTest[2082]['status'] === 'open') {
                $cpanelBaseUrl = $cpanelHttpUrl;
                error_log("HTTPS port 2083 blocked, falling back to HTTP port 2082");
            }
        }
        
        // Create ZIP of the package
        error_log("Creating ZIP from directory: $packagePath");
        $zipPath = createZipFromDirectory($packagePath);
        if (!$zipPath) {
            error_log("Failed to create ZIP from directory");
            return ['ok' => false, 'error' => 'Failed to create deployment ZIP'];
        }
        
        // Validate the ZIP file was created properly
        $zipSize = filesize($zipPath);
        error_log("Created ZIP file: $zipPath (size: $zipSize bytes)");
        
        if ($zipSize < 100) { // ZIP files should be at least a few bytes
            error_log("ZIP file suspiciously small: $zipSize bytes");
            return ['ok' => false, 'error' => 'ZIP file appears to be empty or corrupt'];
        }
        
        try {
            // Use WHM-based deployment instead of direct cPanel connection
            error_log("Using WHM-based deployment (via port 2087)...");
            $deployResult = deployViaWhm_No2083($packagePath, $username);
            error_log("WHM deployment result: " . print_r($deployResult, true));
            
            if (!$deployResult['ok']) {
                error_log("WHM deployment failed: " . ($deployResult['error'] ?? 'unknown error'));
                return [
                    'ok' => false,
                    'stage' => 'deployment',
                    'error' => $deployResult['error'],
                    'cleanup' => [$packagePath]
                ];
            }
            
            error_log("Package deployed successfully via WHM");
            
            return [
                'ok' => true,
                'method' => 'whm_deployment',
                'response' => $deployResult
            ];
            
        } finally {
            // Always cleanup the ZIP
            if (file_exists($zipPath)) {
                unlink($zipPath);
                error_log("Cleaned up ZIP file: $zipPath");
            }
        }
    }
}

if (!function_exists('uploadFileToHosting')) {
    function uploadFileToHosting(string $filePath, string $username, string $password, string $cpanelBaseUrl): array {
        $uploadUrl = $cpanelBaseUrl . '/execute/Fileman/upload_files';
        
        error_log("Uploading to: $uploadUrl with username: $username");
        
        $postFields = [
            'dir' => 'public_html',
            'file-1' => curl_file_create($filePath, 'application/zip', basename($filePath))
        ];
        
        $ch = curl_init($uploadUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Business-Card-to-Website-Deploy/1.0'
            ],
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 120
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = 'cURL error: ' . curl_error($ch);
            error_log("Upload cURL error: $error");
            curl_close($ch);
            return ['ok' => false, 'error' => $error];
        }
        
        curl_close($ch);
        
        error_log("Upload response (HTTP $httpCode): " . substr($response, 0, 500));
        
        if ($response === false || $httpCode !== 200) {
            return ['ok' => false, 'error' => "File upload failed (HTTP $httpCode)"];
        }
        
        $result = json_decode($response, true);
        if (!is_array($result) || empty($result['status'])) {
            error_log("Invalid upload response: " . print_r($result, true));
            return ['ok' => false, 'error' => 'Upload response invalid'];
        }
        
        error_log("Upload successful");
        return ['ok' => true, 'filename' => basename($filePath), 'response' => $result];
    }
}

if (!function_exists('extractZipOnHosting')) {
    function extractZipOnHosting(string $zipName, string $username, string $password, string $cpanelBaseUrl): array {
        $extractUrl = $cpanelBaseUrl . '/json-api/cpanel?' . http_build_query([
            'cpanel_jsonapi_user' => $username,
            'cpanel_jsonapi_apiversion' => 2,
            'cpanel_jsonapi_module' => 'Fileman',
            'cpanel_jsonapi_func' => 'fileop',
            'op' => 'extract',
            'sourcefiles' => 'public_html/' . $zipName,
            'destfiles' => 'public_html',
            'doubledecode' => 1
        ]);
        
        error_log("Extracting ZIP: $extractUrl");
        
        $ch = curl_init($extractUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $username . ':' . $password,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = 'cURL error: ' . curl_error($ch);
            error_log("Extract cURL error: $error");
            curl_close($ch);
            return ['ok' => false, 'error' => $error];
        }
        
        curl_close($ch);
        
        error_log("Extract response (HTTP $httpCode): " . substr($response, 0, 500));
        
        if ($response === false || $httpCode !== 200) {
            return ['ok' => false, 'error' => "ZIP extraction failed (HTTP $httpCode)"];
        }
        
        $result = json_decode($response, true);
        $success = is_array($result) && 
                   isset($result['cpanelresult']['event']['result']) && 
                   (int)$result['cpanelresult']['event']['result'] === 1;
        
        if (!$success) {
            error_log("Extraction failed. Full response: " . print_r($result, true));
        } else {
            error_log("Extraction successful");
        }
        
        return [
            'ok' => $success,
            'error' => $success ? null : 'Extraction operation failed',
            'response' => $result
        ];
    }
}

if (!function_exists('deployGeneratedSiteToHosting')) {
    function deployGeneratedSiteToHosting(int $uploadId, string $domain): array {
        $username = substr(preg_replace('/[^a-z0-9]/i', '', explode('.', $domain)[0]), 0, 8);
        $password = bin2hex(random_bytes(8));
        
        error_log("Starting deployment for upload_id: $uploadId, domain: $domain, username: $username");
        
        // Step 1: Create WHM account
        $accountResult = createWhmAccount($username, $domain, $password);
        if (!$accountResult['ok'] || !($accountResult['data']['metadata']['result'] ?? 0)) {
            return [
                'ok' => false,
                'stage' => 'whm_account',
                'error' => $accountResult['error'] ?? 'Account creation failed',
                'cleanup' => []
            ];
        }
        
        error_log("WHM account created successfully");
        
        // Step 2: Create deployment package
        $packageResult = createDeploymentPackage($uploadId);
        if (!$packageResult['ok']) {
            return [
                'ok' => false,
                'stage' => 'package_creation',
                'error' => $packageResult['error'],
                'cleanup' => []
            ];
        }
        
        $packagePath = $packageResult['path'];
        $packageId = $packageResult['id'];
        
        error_log("Deployment package created: $packageId");
        
        try {
            // Step 3: Deploy package to hosting
            error_log("Starting package deployment to hosting...");
            $deployResult = deployPackageToHosting($packagePath, $username, $password);
            error_log("Deploy result: " . print_r($deployResult, true));
            
            if (!$deployResult['ok']) {
                error_log("Deployment failed: " . ($deployResult['error'] ?? 'Unknown error'));
                return [
                    'ok' => false,
                    'stage' => 'deployment',
                    'error' => $deployResult['error'],
                    'cleanup' => [$packagePath]
                ];
            }
            
            error_log("Package deployed successfully");
            
            // Step 4: Call manifest to activate
            $manifestResult = callManifest($username, $uploadId, $domain);
            
            return [
                'ok' => true,
                'account' => [
                    'username' => $username,
                    'domain' => $domain,
                    'ip' => $accountResult['ip'] ?? null
                ],
                'deployment' => [
                    'package_id' => $packageId,
                    'files_deployed' => $packageResult['files']
                ],
                'manifest' => $manifestResult,
                'cleanup' => [$packagePath]
            ];
            
        } finally {
            // Always cleanup the package directory
            if (is_dir($packagePath)) {
                removeDirectory($packagePath);
                error_log("Cleaned up deployment package: $packageId");
            }
        }
    }
}

// Main execution
$domain = $_GET['domain'] ?? '';
$uploadId = isset($_GET['upload_id']) ? (int)$_GET['upload_id'] : 0;

if ($domain === '' || $uploadId === 0) {
    die('Domain or upload ID missing');
}

echo "Publishing request: domain=$domain, upload_id=$uploadId<br>";

// Check if generated site exists
$generatedHtmlPath = __DIR__ . '/generated_sites/' . $uploadId . '/index.html';
if (!file_exists($generatedHtmlPath)) {
    die('Generated site not found. Please generate the site first.');
}

echo "Generated site found<br>";

// Deploy the generated site
$deploymentResult = deployGeneratedSiteToHosting($uploadId, $domain);

echo "Deployment completed<br>";
echo "<pre>" . print_r($deploymentResult, true) . "</pre>";

// Cleanup any temporary files
if (!empty($deploymentResult['cleanup'])) {
    foreach ($deploymentResult['cleanup'] as $path) {
        if (is_dir($path)) {
            removeDirectory($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }
}
?>
