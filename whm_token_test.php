<?php
require_once __DIR__ . '/whm_helper.php';

try {
    // Test the WHM connection by calling version and applist endpoints
    $version = whmApiRequest('version', ['api.version' => 1]);
    if (!$version) {
        throw new RuntimeException('No response from version endpoint');
    }

    $applist = whmApiRequest('applist', ['api.version' => 1]);
    if (!$applist) {
        throw new RuntimeException('No response from applist endpoint');
    }

    echo "WHM version: " . ($version['version'] ?? 'unknown') . PHP_EOL;
    echo "Applications returned: " . count($applist['data']['applications'] ?? []) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[!] Error: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
