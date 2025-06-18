<?php
function whmApiRequest(string $endpoint, array $params = []) {
    $host = getenv('WHM_HOST');
    $token = getenv('WHM_API_TOKEN');
    $user = getenv('WHM_ROOT_USER') ?: 'root';
    if (!$host || !$token) {
        return null;
    }
    $url = rtrim($host, '/') . '/json-api/' . ltrim($endpoint, '/');
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: whm ' . $user . ':' . $token
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        return null;
    }
    return json_decode($response, true);
}

function createWhmAccount(string $username, string $domain, string $password): ?array {
    $params = [
        'username' => $username,
        'domain' => $domain,
        'password' => $password,
    ];
    return whmApiRequest('createacct', $params);
}

function uploadToCpanel(string $cpanelUser, string $cpanelPass, string $filePath, string $remoteFile = 'public_html/index.html'): bool {
    $host = getenv('WHM_HOST');
    if (!$host) {
        return false;
    }
    $url = 'ftp://' . $cpanelUser . ':' . $cpanelPass . '@' . parse_url($host, PHP_URL_HOST) . '/' . $remoteFile;
    $fp = fopen($filePath, 'r');
    if (!$fp) {
        return false;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_UPLOAD, true);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, filesize($filePath));
    curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, true);
    $res = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $res === true;
}
?>
