<?php
// build_site.php - final website assembly
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'openai_helper.php';
require_once 'i18n.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    die('Invalid id');
}

$asyncDir = __DIR__ . '/async_tasks/';
$contextPath = $asyncDir . $id . '_context.json';
$progressFile = $asyncDir . $id . '_progress.txt';

function updateProgress($message, $file)
{
    file_put_contents($file, $message . PHP_EOL, FILE_APPEND);
}

if (!file_exists($contextPath)) {
    updateProgress('Context file missing', $progressFile);
    die('Context missing');
}

$context = json_decode(file_get_contents($contextPath), true);
$businessData      = $context['business_data'] ?? '';
$additional        = $context['additional'] ?? '';
$layoutTemplate    = $context['layout_template'] ?? null;
$inputLang         = $context['input_lang'] ?? 'en';
$outputLang        = $context['output_lang'] ?? 'en';

$progressCb = function($chunk) use ($progressFile) {
    file_put_contents($progressFile, $chunk, FILE_APPEND);
};
updateProgress('Generating website HTML...', $progressFile);

$error = null;
$result = generateWebsiteFromData($businessData, $additional, $layoutTemplate, $inputLang, $outputLang, $error, $progressCb);
$html = $result['html_code'] ?? null;
updateProgress('Website HTML generation complete', $progressFile);
if ($error) {
    updateProgress('Website generation error: ' . $error, $progressFile);
}

if (!$html) {
    $html = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Generated Site</title></head><body><h1>Generation failed</h1></body></html>";
    updateProgress('Using fallback HTML', $progressFile);
}

// Add branding
$new_footer = '<span class="credits" style="float: right; text-align: right;"><a href="https://businesscard2website.com/" target="_blank" title="Turn your business card into a website.">Get Your Own Website</a></span></footer>';
$html = str_replace('</footer>', $new_footer, $html);

$html = str_replace('\n', "\n", $html);
$html = stripslashes($html);

// inject contact form script
$contactScript = '<script src="https://funcs.businesscard2website.com/contactform.js" crossorigin="anonymous"></script>';
if (stripos($html, '</body>') !== false) {
    $html = str_replace('</body>', $contactScript . '</body>', $html);
} else {
    $html .= $contactScript;
}

// Ensure links to terms and privacy pages in footer
$links = '<div class="mt-4 text-sm"><a href="terms.html" class="mr-4">Terms &amp; Conditions</a><a href="privacy.html">Privacy Policy</a></div>';
if (stripos($html, '</footer>') !== false) {
    $html = str_replace('</footer>', $links . '</footer>', $html);
} elseif (stripos($html, '</body>') !== false) {
    $html = str_replace('</body>', $links . '</body>', $html);
}

// Prepare directory
$dir = __DIR__ . '/generated_sites/' . $id;
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

// Create terms.html and privacy.html
$businessArr = json_decode($businessData, true);
$bizName = $businessArr['business_info']['company_name'] ?? ($businessArr['company_name'] ?? 'This Website');
$contactEmail = $businessArr['business_info']['email'] ?? ($businessArr['email'] ?? '');

$termsContent = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Terms & Conditions</title></head><body><h1>Terms & Conditions</h1><p>This website is owned and operated by {$bizName}. By using this site you agree to these terms.</p><p>Contact us at {$contactEmail} for more information.</p></body></html>";
$privacyContent = "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Privacy Policy</title></head><body><h1>Privacy Policy</h1><p>{$bizName} respects your privacy. Any information submitted through this site will be used only to respond to inquiries.</p><p>Contact us at {$contactEmail} with any questions.</p></body></html>";

file_put_contents($dir . '/terms.html', $termsContent);
file_put_contents($dir . '/privacy.html', $privacyContent);

// Generate sitemap.xml
$sitemap = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
$pages = ['index.html','terms.html','privacy.html'];
foreach ($pages as $p) {
    $sitemap .= "  <url><loc>" . htmlspecialchars($p) . "</loc></url>\n";
}
$sitemap .= "</urlset>";
file_put_contents($dir . '/sitemap.xml', $sitemap);

// robots.txt
$robots = "User-agent: *\nAllow: /\nSitemap: sitemap.xml\n";
file_put_contents($dir . '/robots.txt', $robots);

// llms.txt
$llms = "This site encourages proper citation of its content.\n";
file_put_contents($dir . '/llms.txt', $llms);

// Save index.html and compatibility copy
file_put_contents($dir . '/index.html', $html);
copy($dir . '/index.html', __DIR__ . '/generated_sites/' . $id . '.html');

// Record in database
$stmt = $pdo->prepare('INSERT INTO generated_sites (upload_id, html_code, public_url, created_at) VALUES (?, ?, ?, NOW())');
$stmt->execute([$id, $html, '/generated_sites/' . $id . '/index.html']);
updateProgress('Site saved', $progressFile);
updateProgress('Generation finished', $progressFile);

header('Location: view_site.php?id=' . $id);
exit;
