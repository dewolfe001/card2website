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
    $parts   = $hostUrl ? parse_url($hostUrl) : [];
    $scheme  = $parts['scheme'] ?? 'https';
    $host    = $parts['host'] ?? '';
    if (!$host || !is_file($filePath)) {
        error_log('Invalid host or local file missing.');
        return false;
    }

    $cpanelPort = ($scheme === 'https') ? 2083 : 2082;
    $base       = sprintf('%s://%s:%d', $scheme, $host, $cpanelPort);

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

    $apiCall = function (string $endpoint, array $params = [], array $files = []) use ($base, $cpanelUser, $cpanelPass) {
        $url = $base . '/execute/' . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $cpanelUser . ':' . $cpanelPass);
        if (!empty($files)) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $files);
        }

        if (preg_match('/^https:\/\/\d+\.\d+\.\d+\.\d+/', $base)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            error_log('cURL error: ' . curl_error($ch));
            curl_close($ch);
            return false;
        }
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($status >= 200 && $status < 300);
    };

    // Ensure the remote directory exists
    $parts = explode('/', $remoteDir);
    $path  = '';
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        $path = ltrim($path . '/' . $part, '/');
        $apiCall('Fileman/mkdir', ['path' => $path]);
    }

    // Upload HTML file
    $uploadOk = $apiCall(
        'Fileman/upload_files',
        ['dir' => $remoteDir],
        ['file-1' => curl_file_create($tmp, 'text/html', $remoteName)]
    );
    unlink($tmp);
    if (!$uploadOk) {
        error_log('Upload failed for ' . $remoteFile);
        return false;
    }

    // Upload images
    if (preg_match_all('/generated_images\/([^"\'\s>]+)/i', $html, $m)) {
        $imgDir  = trim($remoteDir . '/generated_images', '/');
        $parts   = explode('/', $imgDir);
        $path    = '';
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            $path = ltrim($path . '/' . $part, '/');
            $apiCall('Fileman/mkdir', ['path' => $path]);
        }

        foreach (array_unique($m[1]) as $img) {
            $localImg = __DIR__ . '/generated_images/' . basename($img);
            if (is_file($localImg)) {
                $apiCall(
                    'Fileman/upload_files',
                    ['dir' => $imgDir],
                    ['file-1' => curl_file_create($localImg, null, basename($img))]
                );
            }
        }
    }

    return true;
}
?>
