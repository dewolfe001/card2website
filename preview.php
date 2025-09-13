<?php
require 'config.php';
require_once 'i18n.php';

$supportedLanguages = getSupportedLanguages();
$currentOutputLang = getOutputLanguage();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();

$imgSrc = null;
if ($upload && !empty($upload['filename'])) {
    $localPath = __DIR__ . '/uploads/' . $upload['filename'];
    $remoteUrl = 'https://businesscard2website.com/uploads/' . $upload['filename'];

    if (file_exists($localPath)) {
        $imgSrc = 'uploads/' . $upload['filename'];
    } else {
        $imgData = @file_get_contents($remoteUrl);
        if ($imgData !== false) {
            if (!is_dir(__DIR__ . '/uploads')) {
                mkdir(__DIR__ . '/uploads', 0777, true);
            }
            file_put_contents($localPath, $imgData);
            $imgSrc = 'uploads/' . $upload['filename'];
        } else {
            $imgSrc = $remoteUrl;
        }
    }
}

$analysisJson = null;
$analysisArray = null;
if ($upload) {
    $stmt = $pdo->prepare('SELECT json_data FROM ocr_data WHERE upload_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$id]);
    $ocrRow = $stmt->fetch();
    if ($ocrRow && isset($ocrRow['json_data'])) {
        $data = json_decode($ocrRow['json_data'], true);
        if ($data) {
            if (isset($data['business_info']) || isset($data['design_elements'])) {
                $analysisJson = json_encode($data, JSON_PRETTY_PRINT);
                $analysisArray = $data;
                // Exclude raw text fields from the editable form
                unset($analysisArray['openai_text'], $analysisArray['raw_text']);
            } elseif (!empty($data['openai_text'])) {
                $analysisJson = $data['openai_text'];
                // Attempt to parse markdown bullet list into key/value pairs
                $analysisArray = parseBulletList($analysisJson);
                if (!empty($analysisArray)) {
                    $analysisJson = json_encode($analysisArray, JSON_PRETTY_PRINT);
                }
            } elseif (!empty($data['raw_text'])) {
                $analysisJson = $data['raw_text'];
            }
        }
    }
}

if (!$upload) {
    die('Upload not found');
}

function parseBulletList(string $text): array {
    $data = [];

    // First try: Match lines with format "- **Key:** Value"
    if (preg_match_all('/^\s*-\s*\*\*\s*(.+?)\s*:\s*\*\*\s*(.+)$/m', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = strtolower(str_replace(' ', '_', trim($m[1])));
            $value = trim($m[2]);
            $data[$key] = $value;
        }
    }
    // Second try: Match format "**Key**: Value" (your original pattern, but improved)
    elseif (preg_match_all('/^\s*-?\s*\*\*\s*(.+?)\s*\*\*\s*:\s*(.+)$/m', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $key = strtolower(str_replace(' ', '_', trim($m[1])));
            $value = trim($m[2]);
            $data[$key] = $value;
        }
    }
    // Third try: Match simple "Key: Value" or "- Key: Value" patterns
    else {
        $lines = preg_split('/\r?\n/', $text);
        foreach ($lines as $line) {
            $line = trim($line);
            // Remove leading dash and whitespace
            $line = preg_replace('/^\s*-\s*/', '', $line);
            
            if (preg_match('/^([^:]+?):\s*(.+)/', $line, $m)) {
                // Remove markdown formatting from key
                $key = preg_replace('/\*\*(.+?)\*\*/', '$1', trim($m[1]));
                $key = strtolower(str_replace(' ', '_', $key));
                $value = trim($m[2]);
                if (!empty($key) && !empty($value)) {
                    $data[$key] = $value;
                }
            }
        }
    }

    return $data;
}

function renderInputs(array $data, string $prefix = '') {
    foreach ($data as $key => $value) {
        $fieldName = $prefix === '' ? $key : $prefix . '[' . $key . ']';
        $label = ucwords(str_replace('_', ' ', $key));
        $translatedLabel = __($label);
        if (is_array($value)) {
            echo '<fieldset class="border p-2 mb-2">';
            echo '<legend class="font-semibold">' . htmlspecialchars($translatedLabel) . '</legend>';
            renderInputs($value, $fieldName);
            echo '</fieldset>';
        } else {
            echo '<label class="block mt-2">' . htmlspecialchars($translatedLabel) . '</label>';

            // Check if value is a hex color (# followed by 6 hex characters)
            $isHexColor = preg_match('/^#[0-9A-Fa-f]{6}$/', $value);
            $inputType = $isHexColor ? 'color' : 'text';
            
            echo '<input type="' . $inputType . '" name="' . htmlspecialchars($fieldName) . '" value="' . htmlspecialchars($value) . '" class="w-full border p-2 text-sm" />';
        
            // echo '<input type="text" name="' . htmlspecialchars($fieldName) . '" value="' . htmlspecialchars($value) . '" class="w-full border p-2 text-sm" />';
        }
    }
}

// Load available HTML templates from database
$layoutPreviews = [];
$stmt = $pdo->query('SELECT template_name, template_file, preview_image FROM html_templates ORDER BY template_name');
$layoutPreviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

$templateBaseUrl = 'https://businesscard2website.com/html_templates/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview - Business Card to Website</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .upload-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 800px;
            width: 100%;
        }

        .button-group {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 30px;
        }

        .upload-btn, .clear-btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .upload-btn {
            background: #4A90E2;
            color: white;
        }

        .upload-btn:hover {
            background: #357ABD;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(74, 144, 226, 0.4);
        }

        .clear-btn {
            background: #F5A5A5;
            color: white;
        }

        .clear-btn:hover {
            background: #E89393;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(245, 165, 165, 0.4);
        }

        .clear-btn:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .drop-zone-container {
            position: relative;
            margin: 20px 0;
        }

        .nav-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: #F5A5A5;
            color: white;
            border: none;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s ease;
            z-index: 10;
        }

        .nav-arrow:hover {
            background: #E89393;
            transform: translateY(-50%) scale(1.1);
        }

        .nav-arrow.left {
            left: -25px;
        }

        .nav-arrow.right {
            right: -25px;
        }

        .drop-zone {
            border: 3px dashed #D1D9E6;
            border-radius: 15px;
            padding: 80px 20px;
            text-align: center;
            background: #FAFBFC;
            transition: all 0.3s ease;
            position: relative;
            min-height: 300px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .drop-zone.dragover {
            border-color: #4A90E2;
            background: #F0F7FF;
            transform: scale(1.02);
        }

        .drop-text {
            font-size: 24px;
            color: #B8C5D6;
            font-weight: 300;
            margin-bottom: 20px;
            transition: color 0.3s ease;
        }

        .drop-zone.dragover .drop-text {
            color: #4A90E2;
        }

        .upload-icon {
            font-size: 48px;
            color: #D1D9E6;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .drop-zone.dragover .upload-icon {
            color: #4A90E2;
            transform: scale(1.2);
        }

        .file-input {
            display: none;
        }

        .file-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 20px;
            margin-top: 30px;
            max-height: 400px;
            overflow-y: auto;
            padding: 10px;
        }

        .file-card {
            background: white;
            border-radius: 12px;
            padding: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
            position: relative;
            border: 2px solid transparent;
        }

        .file-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            border-color: #4A90E2;
        }

        .file-preview {
            width: 100%;
            height: 120px;
            object-fit: cover;
            border-radius: 8px;
            margin-bottom: 10px;
            background: #f8f9fa;
        }

        .file-name {
            font-size: 12px;
            color: #666;
            word-break: break-word;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .file-size {
            font-size: 11px;
            color: #999;
            margin-bottom: 10px;
        }

        .remove-btn {
            position: absolute;
            top: 10px;
            right: 10px;
            background: #FF6B6B;
            color: white;
            border: none;
            border-radius: 50%;
            width: 25px;
            height: 25px;
            cursor: pointer;
            font-size: 12px;
            transition: all 0.3s ease;
        }

        .remove-btn:hover {
            background: #FF5252;
            transform: scale(1.1);
        }

        .file-count {
            text-align: center;
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
        }

        .progress-bar {
            width: 100%;
            height: 6px;
            background: #E9ECEF;
            border-radius: 3px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #4A90E2, #357ABD);
            width: 0%;
            transition: width 0.3s ease;
            border-radius: 3px;
        }

        .empty-state {
            color: #B8C5D6;
            font-style: italic;
            margin-top: 20px;
        }

        @keyframes uploadPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }

        .uploading {
            animation: uploadPulse 2s infinite;
        }
    </style>
</head>
<body class="bg-gray-100">
    <?php include 'header.php'; ?>
    <div id="loadingOverlay" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center text-white text-xl hidden">
        <svg class="animate-spin h-12 w-12 mr-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
        </svg>
        Generating your site...
    </div>
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Preview</h1>
        <div class="text-center mb-6">
            <?php if (!empty($imgSrc)): ?>
            <img src="<?php echo htmlspecialchars($imgSrc); ?>" class="mx-auto max-w-xs" alt="Uploaded Card">
            <?php else: ?>
            <p class="text-center text-red-500">Preview image not available.</p>
            <?php endif; ?>
        </div>
        <?php if ($analysisJson): ?>
        <form id="dataForm" action="generate.php" method="post" enctype="multipart/form-data" class="bg-white p-4 rounded shadow mb-4">
            <h2 class="text-lg font-semibold mb-2">Review &amp; Edit Data</h2>
            <input type="hidden" name="id" value="<?php echo $id; ?>" />
            
            <?php if ($analysisArray): ?>
                <?php renderInputs($analysisArray); ?>
            <?php else: ?>
                <textarea name="raw_analysis_text" rows="12" class="w-full border p-2 text-sm mb-4"><?php echo htmlspecialchars($analysisJson); ?></textarea>
            <?php endif; ?>
            
            <label class="block mt-4 mb-2 font-semibold"><?=__('describe_your_business')?></label>
            <textarea name="additional_details" rows="4" class="w-full border p-2 text-sm"></textarea>

            <label class="block mt-4 mb-2 font-semibold"><?=__('website_output_language')?></label>
            <select name="output_lang" class="border rounded p-2 text-sm">
                <?php foreach ($supportedLanguages as $code => $name): ?>
                <option value="<?php echo htmlspecialchars($code); ?>" <?php echo $code === $currentOutputLang ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>

            <?php if (!empty($layoutPreviews)): ?>
            <label class="block mt-4 mb-2 font-semibold"><?=__('choose_a_site_layout')?></label>
            <div class="flex flex-wrap gap-4">
                <?php foreach ($layoutPreviews as $idx => $layout): ?>
                <label class="cursor-pointer text-center">
                    <input type="radio" name="layout_choice" value="<?php echo htmlspecialchars($layout['template_file']); ?>" class="sr-only peer" <?php echo $idx === 0 ? 'checked' : ''; ?>>
                    <?php if (!empty($layout['preview_image'])): ?>
                        <img src="<?php echo $templateBaseUrl . htmlspecialchars($layout['preview_image']); ?>" alt="<?php echo htmlspecialchars($layout['template_name']); ?>" class="h-32 border-4 border-transparent peer-checked:border-blue-500">
                    <?php else: ?>
                        <span class="block h-32 w-48 flex items-center justify-center bg-gray-200 border-4 border-transparent peer-checked:border-blue-500"><?php echo htmlspecialchars($layout['template_name']); ?></span>
                    <?php endif; ?>
                </label>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <label class="block mt-4 mb-2 font-semibold"><?=__('add_images_to_website')?></label>
            <div class="upload-container">
                <div class="button-group">
                    <button type="button" class="upload-btn" onclick="triggerFileInput()">
                        <span>📁</span>
                        UPLOAD FILES
                    </button>
                    <button type="button" class="clear-btn" id="clearBtn" onclick="clearQueue()" disabled>
                        <span>❌</span>
                        CLEAR QUEUE
                    </button>
                </div>

                <div class="drop-zone-container">
                    <button type="button" class="nav-arrow left" onclick="scrollFiles('left')">‹</button>
                    <button type="button" class="nav-arrow right" onclick="scrollFiles('right')">›</button>

                    <div class="drop-zone" id="dropZone">
                        <div class="upload-icon">☁️</div>
                        <div class="drop-text">Drop Your Files Here</div>
                        <p style="color: #999; font-size: 14px;">or click "UPLOAD FILES" to browse</p>
                    </div>
                </div>

                <input type="file" id="fileInput" class="file-input" name="website_images[]" multiple accept="image/*">

                <div class="file-count" id="fileCount">No files selected</div>

                <div class="file-grid" id="fileGrid">
                    <div class="empty-state">Your uploaded files will appear here</div>
                </div>
            </div>
            <div class="text-center mt-4">
                <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded">Generate Site</button>
            </div>
        </form>
        <?php else: ?>
        <p class="text-center">Analysis not available. Please try again later.</p>
        <?php if (isset($_GET['error'])): ?>
        <p class="text-center text-red-600 mt-2">There was an error communicating with OpenAI. Check your API key and server logs.</p>
        <?php endif; ?>
        <?php endif; ?>
        <div class="text-center mt-6">
            <a href="index.php" class="text-blue-600">Upload another card</a>
        </div>
    </div>
    <?php include 'footer.php'; ?>

<script>
let selectedFiles = [];

const dropZone = document.getElementById('dropZone');
const fileInput = document.getElementById('fileInput');
const fileGrid = document.getElementById('fileGrid');
const fileCount = document.getElementById('fileCount');
const clearBtn = document.getElementById('clearBtn');

function triggerFileInput() {
    fileInput.click();
}

fileInput.addEventListener('change', function(e) {
    addFiles(Array.from(e.target.files));
});

dropZone.addEventListener('dragover', function(e) {
    e.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone.addEventListener('dragleave', function(e) {
    e.preventDefault();
    if (!dropZone.contains(e.relatedTarget)) {
        dropZone.classList.remove('dragover');
    }
});

dropZone.addEventListener('drop', function(e) {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    const files = Array.from(e.dataTransfer.files).filter(file => file.type.startsWith('image/'));
    addFiles(files);
});

dropZone.addEventListener('click', function() {
    fileInput.click();
});

function addFiles(newFiles) {
    const uniqueFiles = newFiles.filter(newFile =>
        !selectedFiles.some(existing => existing.name === newFile.name && existing.size === newFile.size)
    );
    selectedFiles = [...selectedFiles, ...uniqueFiles];
    updateDisplay();
}

function removeFile(index) {
    selectedFiles.splice(index, 1);
    updateDisplay();
}

function clearQueue() {
    selectedFiles = [];
    updateDisplay();
}

function updateDisplay() {
    updateFileCount();
    updateFileGrid();
    updateClearButton();
}

function updateFileCount() {
    const count = selectedFiles.length;
    if (count === 0) {
        fileCount.textContent = 'No files selected';
        fileCount.style.background = 'linear-gradient(135deg, #ccc 0%, #999 100%)';
    } else {
        fileCount.textContent = `${count} file${count !== 1 ? 's' : ''} ready to upload`;
        fileCount.style.background = 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)';
    }
}

function updateFileGrid() {
    if (selectedFiles.length === 0) {
        fileGrid.innerHTML = '<div class="empty-state">Your uploaded files will appear here</div>';
        return;
    }

    fileGrid.innerHTML = '';

    selectedFiles.forEach((file, index) => {
        const fileCard = document.createElement('div');
        fileCard.className = 'file-card';

        const preview = document.createElement('img');
        preview.className = 'file-preview';
        preview.alt = 'File preview';

        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; };
        reader.readAsDataURL(file);

        const fileName = document.createElement('div');
        fileName.className = 'file-name';
        fileName.textContent = file.name;

        const fileSize = document.createElement('div');
        fileSize.className = 'file-size';
        fileSize.textContent = formatFileSize(file.size);

        const removeBtn = document.createElement('button');
        removeBtn.className = 'remove-btn';
        removeBtn.innerHTML = '×';
        removeBtn.onclick = () => removeFile(index);

        const progressBar = document.createElement('div');
        progressBar.className = 'progress-bar';
        const progressFill = document.createElement('div');
        progressFill.className = 'progress-fill';
        progressBar.appendChild(progressFill);

        fileCard.appendChild(removeBtn);
        fileCard.appendChild(preview);
        fileCard.appendChild(fileName);
        fileCard.appendChild(fileSize);
        fileCard.appendChild(progressBar);

        fileGrid.appendChild(fileCard);
    });
}

function updateClearButton() {
    clearBtn.disabled = selectedFiles.length === 0;
}

function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function scrollFiles(direction) {
    const container = fileGrid;
    const scrollAmount = 220;
    if (direction === 'left') {
        container.scrollLeft -= scrollAmount;
    } else {
        container.scrollLeft += scrollAmount;
    }
}

// Update file input and show spinner before submitting the form
const dataForm = document.getElementById('dataForm');
dataForm.addEventListener('submit', function(e) {
    const dt = new DataTransfer();
    selectedFiles.forEach(file => dt.items.add(file));
    fileInput.files = dt.files;
    document.getElementById('loadingOverlay').classList.remove('hidden');
});
</script>
</body>
</html>
