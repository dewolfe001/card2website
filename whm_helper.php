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


/**
 * Call WHM JSON API with a WHM (root/reseller) API Token and extract the account IP when present.
 *
 * Returns a normalized array:
 *   [
 *     'ok'     => bool,
 *     'status' => int,
 *     'data'   => mixed,    // decoded JSON (array) or raw body (string) on non-JSON
 *     'error'  => ?string,  // present on failure
 *     'hint'   => ?string,  // extra guidance
 *     'ip'     => ?string,  // extracted IPv4 if found (e.g., from createacct output)
 *   ]
 *
 * Env:
 *   WHM_HOST       e.g. https://server.example.com:2087  (can be IP)
 *   WHM_API_TOKEN  WHM token created in WHM (NOT a cPanel token)
 *   WHM_ROOT_USER  usually 'root' or your reseller username
 */
function whmApiRequest($endpoint, array $params = [])
{
    $host  = normalizeWhmHost(getenv('WHM_HOST'));
    $token = getenv('WHM_API_TOKEN');
    $user  = getenv('WHM_ROOT_USER') ?: 'root';

    if (!$host || !$token) {
        return [
            'ok'     => false,
            'status' => 0,
            'data'   => null,
            'error'  => 'Missing WHM_HOST or WHM_API_TOKEN',
            'hint'   => 'Set WHM_HOST (https://host:2087) and a WHM API token from WHM » Development » Manage API Tokens.',
            'ip'     => null,
        ];
    }

    // Ensure scheme/port for WHM (2087)
    $parts    = parse_url($host);
    $scheme   = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
    $hostname = isset($parts['host'])   ? $parts['host']   : $host;
    $port     = isset($parts['port'])   ? (int)$parts['port'] : 2087;

    if ($port === 2083) {
        return [
            'ok'     => false,
            'status' => 0,
            'data'   => null,
            'error'  => 'WHM_HOST points to cPanel port :2083, not WHM :2087',
            'hint'   => 'Use the WHM service on port 2087 for WHM JSON API calls.',
            'ip'     => null,
        ];
    }

    $base = $scheme . '://' . $hostname . ':' . $port;

    // Add api.version=1 if caller didn’t supply it
    if (!array_key_exists('api.version', $params)) {
        $params['api.version'] = 1;
    }

    $endpoint = ltrim($endpoint, '/');
    $url = rtrim($base, '/') . '/json-api/' . $endpoint;
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: whm ' . $user . ':' . $token,
        'User-Agent: web321-whm-client/1.1'
    ]);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);

    // If HTTPS + host is an IP, disable verify to tolerate cert mismatch
    $isIp = filter_var($hostname, FILTER_VALIDATE_IP) !== false;
    if ($scheme === 'https' && $isIp) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $error = curl_error($ch);
        $info  = curl_getinfo($ch);
        curl_close($ch);
        return [
            'ok'     => false,
            'status' => 0,
            'data'   => null,
            'error'  => 'cURL error calling WHM: ' . $error,
            'hint'   => 'Check connectivity to ' . $hostname . ':' . $port . ' and firewall/SSL. Info: ' . json_encode($info),
            'ip'     => null,
        ];
    }

    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($response, true);
    $ip = extractWhmIpFromDecoded($decoded);

    // Heuristic: cPanel token against WHM often returns a cpanelresult envelope
    if (is_array($decoded) && isset($decoded['cpanelresult'])) {
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => $decoded,
            'error'  => 'Got cPanel response envelope from WHM endpoint (likely wrong token type)',
            'hint'   => 'Use a WHM API token (Authorization: whm ' . $user . ':<token>) on :2087. A cPanel account token will be rejected by WHM.',
            'ip'     => $ip,
        ];
    }

    if ($status < 200 || $status >= 300) {
        $reason = null;
        if (is_array($decoded)) {
            if (isset($decoded['metadata']['reason'])) {
                $reason = $decoded['metadata']['reason'];
            } elseif (isset($decoded['error'])) {
                $reason = $decoded['error'];
            } elseif (isset($decoded['message'])) {
                $reason = $decoded['message'];
            }
        }
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => $decoded ?: $response,
            'error'  => 'WHM API HTTP ' . $status . ($reason ? (' – ' . $reason) : ''),
            'hint'   => 'Verify the token is a WHM token for user "' . $user . '", the endpoint exists, and your IP is not blocked by cPHulk.',
            'ip'     => $ip,
        ];
    }

    if (!is_array($decoded)) {
        return [
            'ok'     => false,
            'status' => $status,
            'data'   => $response,
            'error'  => 'Non-JSON response from WHM',
            'hint'   => 'Check endpoint: ' . $endpoint . ' and params: ' . json_encode($params),
            'ip'     => null,
        ];
    }

    // Normalize legacy "result" array (e.g., createacct) to a metadata-like shape
    if (isset($decoded['result']) && is_array($decoded['result'])) {
        $first = isset($decoded['result'][0]) && is_array($decoded['result'][0]) ? $decoded['result'][0] : [];
        if (!isset($decoded['metadata']) && isset($first['status'])) {
            $decoded['metadata'] = [
                'result' => (int) $first['status'],
                'reason' => isset($first['statusmsg']) ? $first['statusmsg'] : ''
            ];
        }
        // In case IP lives here and we didn’t see it earlier
        if ($ip === null && isset($first['options']['ip'])) {
            $ip = $first['options']['ip'];
        }
        if ($ip === null && isset($first['rawout']) && is_string($first['rawout'])) {
            $ip = extractIpFromText($first['rawout']);
        }
    }

    return [
        'ok'     => true,
        'status' => $status,
        'data'   => $decoded,
        'error'  => null,
        'hint'   => null,
        'ip'     => $ip,
    ];
}

/**
 * Try hard to find an IPv4 in common WHM response shapes.
 * - createacct: result[0].options.ip or result[0].rawout ("IP Address: 1.2.3.4")
 * - accountsummary/listaccts: acct[0].ip
 * - any shallow 'ip' fields; otherwise scan all strings for first IPv4
 */
function extractWhmIpFromDecoded($decoded)
{
    if (!is_array($decoded)) {
        return null;
    }

    // 1) createacct style
    if (isset($decoded['result'][0]) && is_array($decoded['result'][0])) {
        $first = $decoded['result'][0];

        if (isset($first['options']['ip']) && is_string($first['options']['ip'])) {
            return $first['options']['ip'];
        }
        if (isset($first['ip']) && is_string($first['ip'])) {
            return $first['ip'];
        }
        if (isset($first['rawout']) && is_string($first['rawout'])) {
            $ip = extractIpFromText($first['rawout']);
            if ($ip !== null) return $ip;
        }
        if (isset($first['output']) && is_string($first['output'])) {
            $ip = extractIpFromText($first['output']);
            if ($ip !== null) return $ip;
        }
    }

    // 2) accountsummary / listaccts style
    if (isset($decoded['acct'][0]['ip']) && is_string($decoded['acct'][0]['ip'])) {
        return $decoded['acct'][0]['ip'];
    }

    // 3) shallow 'ip' anywhere (e.g., data.ip)
    foreach (['ip', 'mainip'] as $k) {
        if (isset($decoded[$k]) && is_string($decoded[$k])) {
            return $decoded[$k];
        }
        if (isset($decoded['data'][$k]) && is_string($decoded['data'][$k])) {
            return $decoded['data'][$k];
        }
    }

    // 4) recursive scan for IPv4 in any string value
    $ip = recursiveFindIpv4($decoded);
    return $ip ?: null;
}

/** Extract "IP Address: x.x.x.x" or any IPv4 from a text blob. */
function extractIpFromText($text)
{
    if (!is_string($text) || $text === '') {
        return null;
    }

    // Prefer explicit "IP Address:" marker if present
    if (preg_match('/IP\s*Address:\s*([0-9]{1,3}(?:\.[0-9]{1,3}){3})/i', $text, $m)) {
        return $m[1];
    }

    // Fallback: first IPv4 pattern in text
    if (preg_match('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $text, $m)) {
        return $m[0];
    }

    return null;
}

/** Walk array recursively and return the first IPv4 found in any string. */
function recursiveFindIpv4($value)
{
    if (is_string($value)) {
        if (preg_match('/\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b/', $value, $m)) {
            return $m[0];
        }
        return null;
    }
    if (is_array($value)) {
        foreach ($value as $v) {
            $found = recursiveFindIpv4($v);
            if ($found !== null) return $found;
        }
    }
    return null;
}


function olde_whmApiRequest(string $endpoint, array $params = []) {
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
    error_log(print_r($response, TRUE));

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

    // Upload the HTML file
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

    // Upload referenced images if present
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
