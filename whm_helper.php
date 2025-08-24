<?php

function normalizeWhmHost(?string $host): ?string {
    if (!$host) {
        return null;
    }

    $parts    = parse_url($host);
    $scheme   = $parts['scheme'] ?? 'https';
    $port     = $parts['port'] ?? 2087;
    $hostname = $parts['host'] ?? $host;

    // cPanel often uses IP-encoded domains like 192-0-2-123.cprapid.com. If DNS
    // fails for such a host, convert it back to a dotted IPv4 address so cURL can
    // contact the server directly.
    if (preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)\.cprapid\.com$/', $hostname, $m)) {
        $hostname = sprintf('%d.%d.%d.%d', $m[1], $m[2], $m[3], $m[4]);
    }

    return sprintf('%s://%s:%d', $scheme, $hostname, $port);
}

function whmApiRequest(string $endpoint, array $params = []) {
    $host = normalizeWhmHost(getenv('WHM_HOST'));
    $token = getenv('WHM_API_TOKEN');
    $user  = getenv('WHM_ROOT_USER') ?: 'root';

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

    // When using an IP address instead of a hostname, the SSL certificate will not
    // match and the request would normally fail. Disable verification so calls to
    // cPanel's "cprapid" style hosts still work.
    if (preg_match('/^https:\/\/\d+\.\d+\.\d+\.\d+/i', $host)) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error calling WHM: ' . $error);
    }

    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status < 200 || $status >= 300) {
        return null;
    }

    $decoded = json_decode($response, true);

    // Some WHM API calls (e.g. createacct) return an array called "result" with
    // status information instead of the usual "metadata" block. Normalize the
    // response so callers can consistently check $response['metadata']['result'].
    if (isset($decoded['result']) && is_array($decoded['result'])) {
        $first = $decoded['result'][0] ?? [];
        if (!isset($decoded['metadata']) && isset($first['status'])) {
            $decoded['metadata'] = [
                'result' => $first['status'],
                'reason' => $first['statusmsg'] ?? ''
            ];
        }
    }

    return $decoded;
}

function createWhmAccount(string $username, string $domain, string $password): ?array {
    $params = [
        'username' => $username,
        'domain' => $domain,
        'password' => $password,
    ];
    return whmApiRequest('createacct', $params);
}

function uploadToCpanel(
    string $cpanelUser,
    string $cpanelPass,
    string $filePath,
    string $remoteFile = 'public_html/index.html'
): bool {
    $hostUrl = normalizeWhmHost(getenv('WHM_HOST'));
    $host    = $hostUrl ? parse_url($hostUrl, PHP_URL_HOST) : null;
    if (!$host || !is_file($filePath)) {
        return false;
    }

    $conn = @ftp_ssl_connect($host);
    if (!$conn) {
        $conn = @ftp_connect($host);
    }
    if (!$conn) {
        return false;
    }
    // Newly created cPanel accounts can take a moment before the FTP service
    // recognises the credentials. Retry the login a few times before failing.
    $loginOk = false;
    for ($i = 0; $i < 5 && !$loginOk; $i++) {
        $loginOk = @ftp_login($conn, $cpanelUser, $cpanelPass);
        if (!$loginOk) {
            sleep(1); // wait briefly before the next attempt
        }
    }
    if (!$loginOk) {
        error_log('FTP Login Issue for ' . $cpanelUser);
        ftp_close($conn);
        return false;
    }
    ftp_pasv($conn, true);

    $remoteDir = dirname($remoteFile);
    @ftp_mkdir($conn, $remoteDir);

    $html = @file_get_contents($filePath);
    if ($html === false) {
        ftp_close($conn);
        return false;
    }

    // Rewrite absolute references to generated_images to relative paths.
    $html = preg_replace('#https?://[^/]+/generated_images/#', 'generated_images/', $html);

    $tmp = tempnam(sys_get_temp_dir(), 'cphtml');
    file_put_contents($tmp, $html);
    $uploadOk = @ftp_put($conn, $remoteFile, $tmp, FTP_ASCII);
    unlink($tmp);
    if (!$uploadOk) {
        ftp_close($conn);
        return false;
    }

    if (preg_match_all('/generated_images\/([^"\'"\s>]+)/i', $html, $m)) {
        $imgRemoteDir = $remoteDir . '/generated_images';
        @ftp_mkdir($conn, $imgRemoteDir);
        foreach (array_unique($m[1]) as $img) {
            $localImg = __DIR__ . '/generated_images/' . basename($img);
            if (is_file($localImg)) {
                @ftp_put($conn, $imgRemoteDir . '/' . basename($img), $localImg, FTP_BINARY);
            }
        }
    }

    ftp_close($conn);
    return true;
}
?>
