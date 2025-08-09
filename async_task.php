<?php
require 'config.php';
require_once 'openai_helper.php';
require_once 'gemini_helper.php';

header('Content-Type: application/json');

$asyncDir = __DIR__ . '/async_tasks/';
$action   = $_GET['action'] ?? '';
$id       = $_GET['id'] ?? '';
$contextPath = $asyncDir . $id . '_context.json';
if (!file_exists($contextPath)) {
    echo json_encode(['error' => 'missing context']);
    exit;
}
$context = json_decode(file_get_contents($contextPath), true);

switch ($action) {
    case 'generate_image':
        $type = $_GET['type'] ?? 'main';
        $size = $_GET['size'] ?? '1024x1024';
        $basePrompt = $context['img_prompt'] ?? '';
        switch ($type) {
            case 'side':
                $prompt = "Make an image that fit to the side of the website content. Size it {$size} " . $basePrompt;
                break;
            case 'square':
                $prompt = "Make an image that will work great low on the web page. Size it {$size} " . $basePrompt;
                break;
            default:
                $prompt = "Make an image that will work great above the fold. Size it {$size} " . $basePrompt;
        }
        $err = null;
        $img = generateBusinessCardImage($prompt, $size, $err);
        $file = $asyncDir . "{$id}_{$type}.json";
        file_put_contents($file, json_encode($img));
        echo json_encode(['status' => 'ok', 'file' => basename($file)]);
        break;

    case 'analyze_image':
        $imgUrl = $_GET['img'] ?? '';
        $idx    = $_GET['idx'] ?? 0;
        $businessData = $context['business_data'] ?? '';
        $res = generateFromImages($businessData, $imgUrl, $id);
        $file = $asyncDir . "{$id}_imginfo_{$idx}.json";
        file_put_contents($file, $res);
        echo json_encode(['status' => 'ok', 'file' => basename($file)]);
        break;

    case 'fetch_reviews':
        $query = $_GET['query'] ?? '';
        $idx   = $_GET['idx'] ?? 0;
        $searchapi = getenv('SEARCHAPI_KEY');
        $fetcher = new GoogleMapsReviewsFetcherCurl($searchapi);
        $reviews = [];
        try {
            $reviews = $fetcher->getReviewsByQuery($query);
        } catch (Exception $e) {
            // ignore
        }
        $file = $asyncDir . "{$id}_reviews_{$idx}.json";
        file_put_contents($file, json_encode($reviews));
        echo json_encode(['status' => 'ok', 'file' => basename($file)]);
        break;

    default:
        echo json_encode(['error' => 'unknown action']);
}
