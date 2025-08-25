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

function uploadToCpanel(
    string $cpanelUser,
    string $cpanelPass,
    string $filePath,
    string $remoteFile = 'public_html/index.html',
    string $address = ''
): bool {
    $hostUrl = $address ?: normalizeWhmHost(getenv('WHM_HOST'));
    $host    = $hostUrl ? parse_url($hostUrl, PHP_URL_HOST) : '';
    if (!$host || !is_file($filePath)) {
        error_log('Invalid host or local file missing.');
        return false;
    }

    $remoteDir  = trim(dirname($remoteFile), '/');
    $remoteDir  = $remoteDir === '' ? '.' : $remoteDir;
    $remoteName = basename($remoteFile);

    $html = file_get_contents($filePath);
    if ($html === false) {
        return false;
    }
    $html = preg_replace('#https?://[^/]+/generated_images/#', 'generated_images/', $html);

    $tmp = tempnam(sys_get_temp_dir(), 'cphtml');
    file_put_contents($tmp, $html);

    $conn = @ftp_ssl_connect($host, 21, 10);
    if (!$conn) {
        $conn = @ftp_connect($host, 21, 10);
    }
    if (!$conn) {
        error_log('Unable to connect to FTP server ' . $host);
        unlink($tmp);
        return false;
    }
    if (!@ftp_login($conn, $cpanelUser, $cpanelPass)) {
        error_log('FTP login failed');
        ftp_close($conn);
        unlink($tmp);
        return false;
    }
    ftp_pasv($conn, true);

    $parts = explode('/', $remoteDir);
    $path  = '';
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        $path .= '/' . $part;
        @ftp_mkdir($conn, $path);
    }

    $remotePath = ($remoteDir === '.' ? '' : $remoteDir . '/') . $remoteName;
    if (!ftp_put($conn, $remotePath, $tmp, FTP_ASCII)) {
        error_log('Upload failed for ' . $remotePath);
        ftp_close($conn);
        unlink($tmp);
        return false;
    }
    unlink($tmp);

    if (preg_match_all('/generated_images\/([^"\'\s>]+)/i', $html, $m)) {
        $imgDir  = trim($remoteDir . '/generated_images', '/');
        $parts   = explode('/', $imgDir);
        $path    = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $path .= '/' . $part;
            @ftp_mkdir($conn, $path);
        }
        foreach (array_unique($m[1]) as $img) {
            $localImg = __DIR__ . '/generated_images/' . basename($img);
            if (is_file($localImg)) {
                $remoteImg = ($imgDir === '' ? '' : $imgDir . '/') . basename($img);
                @ftp_put($conn, $remoteImg, $localImg, FTP_BINARY);
            }
        }
    }

    ftp_close($conn);
    return true;
}
?>
