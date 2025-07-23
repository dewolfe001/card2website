<?php
require 'config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare('SELECT filename FROM uploads WHERE id = ?');
$stmt->execute([$id]);
$upload = $stmt->fetch();

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
            } elseif (!empty($data['openai_text'])) {
                $analysisJson = $data['openai_text'];
            } elseif (!empty($data['raw_text'])) {
                $analysisJson = $data['raw_text'];
            }
        }
    }
}

if (!$upload) {
    die('Upload not found');
}

function renderInputs(array $data, string $prefix = '') {
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? $key : $prefix . '.' . $key;
        $label = ucwords(str_replace('_', ' ', $key));
        if (is_array($value)) {
            echo '<fieldset class="border p-2 mb-2">';
            echo '<legend class="font-semibold">' . htmlspecialchars($label) . '</legend>';
            renderInputs($value, $path);
            echo '</fieldset>';
        } else {
            echo '<label class="block mt-2">' . htmlspecialchars($label) . '</label>';
            echo '<input type="text" data-json-path="' . htmlspecialchars($path) . '" value="' . htmlspecialchars($value) . '" class="w-full border p-2 text-sm" />';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preview - BusinessCard2Website</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100">
    <div class="container mx-auto p-8">
        <h1 class="text-2xl font-bold mb-4 text-center">Preview</h1>
        <div class="text-center mb-6">
            <img src="uploads/<?php echo htmlspecialchars($upload['filename']); ?>" class="mx-auto max-w-xs" alt="Uploaded Card">
        </div>
        <?php if ($analysisJson): ?>
        <form id="dataForm" action="generate.php" method="post" enctype="multipart/form-data" class="bg-white p-4 rounded shadow mb-4">
            <h2 class="text-lg font-semibold mb-2">Review &amp; Edit Data</h2>
            <input type="hidden" name="id" value="<?php echo $id; ?>" />
            <textarea id="edited_data" name="edited_data" style="display:none;"><?php echo htmlspecialchars($analysisJson); ?></textarea>
            <?php if ($analysisArray): ?>
                <?php renderInputs($analysisArray); ?>
            <?php else: ?>
                <textarea id="analysis_text" rows="12" class="w-full border p-2 text-sm mb-4"><?php echo htmlspecialchars($analysisJson); ?></textarea>
            <?php endif; ?>
            <label class="block mt-4 mb-2 font-semibold">Describe your business</label>
            <textarea name="additional_details" rows="4" class="w-full border p-2 text-sm"></textarea>
            <label class="block mt-4 mb-2 font-semibold">Upload images for your website</label>
            <input type="file" name="website_images[]" multiple accept="image/*" class="w-full border p-2 text-sm" />
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
    <?php if ($analysisArray): ?>
    <script>
    function buildJson() {
        const data = {};
        document.querySelectorAll('[data-json-path]').forEach(el => {
            const path = el.dataset.jsonPath.split('.');
            let obj = data;
            for (let i = 0; i < path.length - 1; i++) {
                const key = path[i];
                if (!obj[key]) obj[key] = {};
                obj = obj[key];
            }
            obj[path[path.length - 1]] = el.value;
        });
        return data;
    }
    document.getElementById('dataForm').addEventListener('submit', function(e){
        const json = buildJson();
        document.getElementById('edited_data').value = JSON.stringify(json);
    });
    </script>
    <?php else: ?>
    <script>
    document.getElementById('dataForm').addEventListener('submit', function(){
        document.getElementById('edited_data').value = document.getElementById('analysis_text').value;
    });
    </script>
    <?php endif; ?>
</body>
</html>
