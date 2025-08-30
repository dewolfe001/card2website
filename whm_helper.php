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
    string $address = '',
    string $domain = ''
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
    $ftpUser = $domain ? $cpanelUser . '@' . $domain : $cpanelUser;
    if (!@ftp_login($conn, $ftpUser, $cpanelPass)) {
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

function OldewhmUapiCpanel(string $cpUser, string $module, string $func, array $params = []): array {
    $whmHostRaw = (string)getenv('WHM_HOST');              // e.g. https://192.250.238.76:2087 or https://panel.example.com:2087
    $token      = (string)getenv('WHM_API_TOKEN');
    $whmUser    = getenv('WHM_ROOT_USER') ?: 'root';
    $sniHost    = getenv('WHM_SNI_HOST') ?: '';            // e.g. panel.example.com (CN/SAN on cert)
    $strict     = (string)(getenv('WHM_STRICT_SSL') ?: '1') !== '0';

    if ($whmHostRaw === '' || $token === '') {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Missing WHM_HOST or WHM_API_TOKEN'];
    }

    // Parse WHM_HOST (can be IP or hostname)
    $p        = parse_url($whmHostRaw);
    $scheme   = strtolower($p['scheme'] ?? 'https');
    $host     = $p['host'] ?? '';
    $port     = isset($p['port']) ? (int)$p['port'] : 2087;
    $isIpHost = filter_var($host, FILTER_VALIDATE_IP) !== false;

    // If cert is for a hostname, we must present that hostname in the URL for SNI.
    // When WHM_HOST is an IP, use WHM_SNI_HOST in the URL and pin it to the IP with CURLOPT_RESOLVE.
    $urlHost  = $isIpHost && $sniHost ? $sniHost : $host;
    $base     = $scheme . '://' . $urlHost . ':' . $port;

    $query = array_merge(['api.version'=>1,'user'=>$cpUser,'module'=>$module,'function'=>$func], $params);
    $url   = rtrim($base,'/') . '/json-api/uapi?' . http_build_query($query);

    $ch = curl_init($url);
    $headers = [
        'Authorization: whm ' . $whmUser . ':' . $token,
        'User-Agent: web321-whm-uapi-proxy/1.1',
        'Accept: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CONNECTTIMEOUT => (int)(getenv('WHM_CONNECT_TIMEOUT') ?: 10),
        CURLOPT_TIMEOUT        => (int)(getenv('WHM_TIMEOUT') ?: 30),
        CURLOPT_SSL_VERIFYPEER => $strict,
        CURLOPT_SSL_VERIFYHOST => $strict ? 2 : 0,
    ]);

    // Pin SNI host to the IP when WHM_HOST is an IP and WHM_SNI_HOST is provided
    if ($isIpHost && $sniHost) {
        curl_setopt($ch, CURLOPT_RESOLVE, [$sniHost . ':' . $port . ':' . $host]);
        // Optional: also send Host header (not necessary for SNI, but ok)
        $headers[] = 'Host: ' . $sniHost;
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err  = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM uapi error: '.$err,'hint'=>json_encode($info)];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $dec = json_decode($raw, true);
    if (!is_array($dec)) return ['ok'=>false,'status'=>$status,'data'=>$raw,'error'=>'Non-JSON from WHM uapi'];

    $ok = isset($dec['result']['status']) && (int)$dec['result']['status'] === 1;
    return ['ok'=>$ok && $status>=200 && $status<300,'status'=>$status,'data'=>$dec,'error'=>$ok?null:(($dec['result']['errors'][0] ?? 'UAPI via WHM failed'))];
}

function whmUapiCpanel(string $cpUser, string $module, string $func, array $params = []): array {
    $whmHostRaw = (string)getenv('WHM_HOST');
    $token      = (string)getenv('WHM_API_TOKEN');
    $whmUser    = getenv('WHM_ROOT_USER') ?: 'root';

    if ($whmHostRaw === '' || $token === '') {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Missing WHM_HOST or WHM_API_TOKEN'];
    }

    // Parse WHM_HOST
    $p = parse_url($whmHostRaw);
    $scheme = strtolower($p['scheme'] ?? 'https');
    $host = $p['host'] ?? '';
    $port = isset($p['port']) ? (int)$p['port'] : 2087;
    
    // Convert cprapid hostname back to IP if needed
    if (preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)\.cprapid\.com$/', $host, $m)) {
        $host = sprintf('%d.%d.%d.%d', $m[1], $m[2], $m[3], $m[4]);
    }
    
    $base = $scheme . '://' . $host . ':' . $port;

    // Use cpanel_jsonapi instead of uapi for better compatibility
    $query = [
        'cpanel_jsonapi_user' => $cpUser,
        'cpanel_jsonapi_apiversion' => 3, // UAPI is version 3
        'cpanel_jsonapi_module' => $module,
        'cpanel_jsonapi_func' => $func
    ];
    
    // Add function parameters
    foreach ($params as $key => $value) {
        $query[$key] = $value;
    }
    
    $url = rtrim($base,'/') . '/json-api/cpanel?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: whm '.$whmUser.':'.$token,
            'User-Agent: web321-whm-uapi-proxy/1.1',
            'Accept: application/json',
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        // Disable SSL verification for IP addresses
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM uapi error: '.$err,'hint'=>json_encode($info)];
    }
    
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return ['ok'=>false,'status'=>$status,'data'=>$raw,'error'=>'Non-JSON from WHM uapi'];
    }

    // Check for UAPI success
    $ok = isset($dec['result']['status']) && (int)$dec['result']['status'] === 1;
    
    return [
        'ok' => $ok && $status >= 200 && $status < 300,
        'status' => $status,
        'data' => $dec,
        'error' => $ok ? null : ($dec['result']['errors'][0] ?? 'UAPI via WHM failed')
    ];
}

/**
 * Create cPanel token directly via cPanel port 2083 using basic auth
 * This bypasses the WHM UAPI proxy issues
 */
function createCpanelTokenDirect(string $username, string $password, ?string $tokenName = null): array {
    $tokenName = $tokenName ?: ('web321-' . gmdate('Ymd-His'));
    
    // Get the IP from WHM_HOST
    $whmHost = getenv('WHM_HOST');
    if (!$whmHost) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM_HOST not set'];
    }
    
    $p = parse_url($whmHost);
    $host = $p['host'] ?? '';
    
    // Convert cprapid hostname to IP if needed
    if (preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)\.cprapid\.com$/', $host, $m)) {
        $host = sprintf('%d.%d.%d.%d', $m[1], $m[2], $m[3], $m[4]);
    }
    
    // Use HTTP for cPanel since SSL isn't set up yet for new accounts
    $cpanelUrl = "http://{$host}:2082/execute/Tokens/create_full_access";
    
    $postData = http_build_query([
        'name' => $tokenName
    ]);
    
    $ch = curl_init($cpanelUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: web321-cpanel-direct/1.0'
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'cURL error: '.$err];
    }
    
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $dec = json_decode($raw, true);
    if (!is_array($dec)) {
        return ['ok'=>false,'status'=>$status,'data'=>$raw,'error'=>'Non-JSON response'];
    }
    
    $ok = isset($dec['result']['status']) && (int)$dec['result']['status'] === 1;
    if (!$ok) {
        return ['ok'=>false,'status'=>$status,'data'=>$dec,'error'=>$dec['result']['errors'][0] ?? 'Token creation failed'];
    }
    
    $token = $dec['result']['data']['token'] ?? null;
    if (!$token) {
        return ['ok'=>false,'status'=>$status,'data'=>$dec,'error'=>'Token not found in response'];
    }
    
    return ['ok'=>true,'status'=>$status,'data'=>['token'=>$token,'name'=>$tokenName]];
}

/**
 * Alternative file deployment using HTTP and basic auth
 * This avoids SSL issues with new accounts
 */
function deployFilesViaHttp(string $localDir, string $username, string $password): array {
    if (!is_dir($localDir)) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Local directory not found: '.$localDir];
    }
    
    // Get the IP from WHM_HOST
    $whmHost = getenv('WHM_HOST');
    if (!$whmHost) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM_HOST not set'];
    }
    
    $p = parse_url($whmHost);
    $host = $p['host'] ?? '';
    
    // Convert cprapid hostname to IP if needed  
    if (preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)\.cprapid\.com$/', $host, $m)) {
        $host = sprintf('%d.%d.%d.%d', $m[1], $m[2], $m[3], $m[4]);
    }
    
    // Create a zip file
    $zipPath = zipDirectory($localDir);
    if (!$zipPath) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Failed to create ZIP file'];
    }
    
    // Upload via HTTP cPanel (port 2082)
    $uploadUrl = "http://{$host}:2082/execute/Fileman/upload_files";
    
    $postFields = [
        'dir' => 'public_html',
        'file-1' => curl_file_create($zipPath, 'application/zip', basename($zipPath))
    ];
    
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPHEADER => [
            'User-Agent: web321-cpanel-direct/1.0'
        ],
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120
    ]);
    
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($raw === false) {
        @unlink($zipPath);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Upload failed'];
    }
    
    $uploadResult = json_decode($raw, true);
    $uploadOk = is_array($uploadResult) && !empty($uploadResult['status']);
    
    if (!$uploadOk) {
        @unlink($zipPath);
        return ['ok'=>false,'status'=>$status,'data'=>$uploadResult,'error'=>'File upload failed'];
    }
    
    // Extract the zip
    $extractUrl = "http://{$host}:2082/json-api/cpanel?" . http_build_query([
        'cpanel_jsonapi_user' => $username,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'fileop',
        'op' => 'extract',
        'sourcefiles' => 'public_html/' . basename($zipPath),
        'destfiles' => 'public_html',
        'doubledecode' => 1
    ]);
    
    $ch = curl_init($extractUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60
    ]);
    
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    @unlink($zipPath); // Clean up local zip
    
    if ($raw === false) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Extract operation failed'];
    }
    
    $extractResult = json_decode($raw, true);
    $extractOk = is_array($extractResult) && 
                 isset($extractResult['cpanelresult']['event']['result']) && 
                 (int)$extractResult['cpanelresult']['event']['result'] === 1;
    
    return [
        'ok' => $extractOk,
        'status' => $status,
        'data' => $extractResult,
        'error' => $extractOk ? null : 'File extraction failed'
    ];
}

function createCpanelTokenViaWhm(string $cpUser, ?string $name=null, ?int $expiresAt=null): array {
    $name = $name ?: ('web321-'.gmdate('Ymd-His'));
    $params = ['name'=>$name]; if ($expiresAt) $params['expires_at'] = $expiresAt;
    $res = whmUapiCpanel($cpUser,'Tokens','create_full_access',$params); // UAPI Tokens::create_full_access
    if (!$res['ok']) return $res;
    $token = $res['data']['result']['data']['token'] ?? null;
    if (!$token) return ['ok'=>false,'status'=>$res['status'],'data'=>$res['data'],'error'=>'Token missing from response'];
    return ['ok'=>true,'status'=>$res['status'],'data'=>['token'=>$token,'name'=>$name]];
}

function cpanelUapiUploadFiles(array $files, string $destDir, string $cpUser, string $token, ?string $cpBase=null): array {
    $base = $cpBase ?: cpanelBaseFromWhmHost(); if (!$base) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'No cPanel base URL'];
    $url  = rtrim($base,'/') . '/execute/Fileman/upload_files'; // UAPI
    $payload = ['dir'=>$destDir];
    $i = 1; foreach ($files as $path) {
        if (!is_file($path)) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Missing local file: '.$path];
        $payload['file-'.$i] = curl_file_create($path, null, basename($path));
        $i++;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel '.$cpUser.':'.$token, 'User-Agent: web321-cpanel-client/1.2'],
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 120,
    ]);
    // Allow opting out of TLS checks when using IP with name-mismatch:
    $strict = getenv('CPANEL_STRICT_SSL'); $verify = ($strict === false || $strict === '' || $strict === '1' || $strict === 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

    $raw = curl_exec($ch);
    if ($raw === false) { $e=curl_error($ch); $i=curl_getinfo($ch); curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'upload_files cURL error: '.$e,'hint'=>json_encode($i)];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $dec = json_decode($raw,true); $ok = is_array($dec) && !empty($dec['status']);
    return ['ok'=>$ok && $status>=200 && $status<300,'status'=>$status,'data'=>$dec,'error'=>$ok?null:(($dec['errors'][0]??'upload_files failed'))];
}

function cpanelApi2ExtractZip(string $cpUser, string $token, string $zipPath, string $destDir, ?string $cpBase=null): array {
    $base = $cpBase ?: cpanelBaseFromWhmHost(); if (!$base) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'No cPanel base URL'];
    $query = [
        'cpanel_jsonapi_user'       => $cpUser,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module'     => 'Fileman',
        'cpanel_jsonapi_func'       => 'fileop',
        'op'                        => 'extract',
        'sourcefiles'               => $zipPath,        // e.g. public_html/site.zip
        'destfiles'                 => $destDir,        // e.g. public_html
        'doubledecode'              => 1,
    ];
    $url = rtrim($base,'/') . '/json-api/cpanel?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: cpanel '.$cpUser.':'.$token, 'User-Agent: web321-cpanel-client/1.2'],
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $strict = getenv('CPANEL_STRICT_SSL'); $verify = ($strict === false || $strict === '' || $strict === '1' || $strict === 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify); curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);
    $raw = curl_exec($ch);
    if ($raw === false) { $e=curl_error($ch); $i=curl_getinfo($ch); curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'API2 extract cURL error: '.$e,'hint'=>json_encode($i)];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $dec = json_decode($raw,true);
    $ok = is_array($dec) && (int)($dec['cpanelresult']['event']['result'] ?? 0) === 1;
    return ['ok'=>$ok && $status>=200 && $status<300,'status'=>$status,'data'=>$dec,'error'=>$ok?null:'API2 extract failed'];
}


/** Upload a zip to public_html and extract it there */
function deployViaToken_ZipAndExtract(string $localDir, string $cpUser, string $cpToken): array {
    $zip = zipDirectory($localDir);
    if (!$zip) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Failed to create ZIP (ZipArchive?)'];
    $up  = cpanelUapiUploadFiles([$zip], 'public_html', $cpUser, $cpToken);
    @unlink($zip);
    if (!$up['ok']) return $up;
    $zipName = basename($zip);
    return cpanelApi2ExtractZip($cpUser, $cpToken, 'public_html/'.$zipName, 'public_html');
}

/** Run API2 via WHM (for mkdir, etc.) */
function whmApi2Cpanel(string $cpUser, string $module, string $func, array $params = []): array {
    $host  = normalizeWhmHost((string)getenv('WHM_HOST'));
    $token = (string)getenv('WHM_API_TOKEN');
    $whmUser = getenv('WHM_ROOT_USER') ?: 'root';
    if ($host === '' || $token === '') return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Missing WHM_HOST or WHM_API_TOKEN'];

    $p = parse_url($host);
    $base = ($p['scheme'] ?? 'https').'://'.($p['host'] ?? '').':'.($p['port'] ?? 2087);
    $q = array_merge([
        'cpanel_jsonapi_user'       => $cpUser,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module'     => $module,
        'cpanel_jsonapi_func'       => $func,
    ], $params);
    $url = rtrim($base,'/').'/json-api/cpanel?'.http_build_query($q);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: whm '.$whmUser.':'.$token, 'User-Agent: web321-whm-cpanel-proxy/1.0'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) { $e=curl_error($ch); $i=curl_getinfo($ch); curl_close($ch);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM cpanel(API2) error: '.$e,'hint'=>json_encode($i)];
    }
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $dec = json_decode($raw,true); $ok = is_array($dec) && (int)($dec['cpanelresult']['event']['result'] ?? 0) === 1;
    return ['ok'=>$ok && $status>=200 && $status<300,'status'=>$status,'data'=>$dec,'error'=>$ok?null:'API2 call failed'];
}

/** Ensure a path like public_html/foo/bar exists (creates pieces via API2 Fileman::mkdir) */
function ensureRemotePathViaWhm(string $cpUser, string $relativeDir): array {
    $relativeDir = trim($relativeDir, '/');
    if ($relativeDir === '' || $relativeDir === 'public_html') return ['ok'=>true];
    $parts = explode('/', $relativeDir);
    $soFar = 'public_html';
    foreach ($parts as $p) {
        if ($p === '' || $p === 'public_html') continue;
        $res = whmApi2Cpanel($cpUser, 'Fileman', 'mkdir', ['path'=>$soFar, 'name'=>$p]);
        // Ignore "already exists" messages; fail only on hard errors
        if (!$res['ok'] && stripos(json_encode($res['data']), 'exists') === false) return $res;
        $soFar .= '/'.$p;
    }
    return ['ok'=>true];
}

/** Save one file’s bytes into public_html using UAPI Fileman::save_file_content via WHM uapi_cpanel */
function saveFileViaWhmUapi(string $cpUser, string $remoteDir, string $filename, string $bytes): array {
    // UAPI Fileman::save_file_content — params: dir, file, content (+charset options)
    $params = ['dir'=>'/home/'.$cpUser.'/'.$remoteDir, 'file'=>$filename, 'content'=>$bytes];
    // Note: docs show dir can be absolute; using absolute avoids home ambiguity. :contentReference[oaicite:8]{index=8}
    $res = whmUapiCpanel($cpUser, 'Fileman', 'save_file_content', $params);
    return $res['ok'] ? $res : $res;
}

/** Walk a local directory and push all files into public_html entirely via :2087 proxies */
function deployViaWhm_No2083(string $localDir, string $cpUser, string $remoteBase='public_html'): array {
    $localDir = rtrim($localDir, DIRECTORY_SEPARATOR);
    if (!is_dir($localDir)) return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Local dir not found'];

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localDir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isDir()) continue;
        $full = $f->getPathname();
        $rel  = ltrim(str_replace(DIRECTORY_SEPARATOR,'/', substr($full, strlen($localDir))), '/');
        $remoteDir = $remoteBase . (dirname($rel) !== '.' ? '/'.dirname($rel) : '');
        $mkdir = ensureRemotePathViaWhm($cpUser, $remoteDir);
        if (!$mkdir['ok']) return $mkdir;

        $bytes = file_get_contents($full);
        $save  = saveFileViaWhmUapi($cpUser, $remoteDir, basename($rel), $bytes);
        if (!$save['ok']) return $save;
    }
    return ['ok'=>true,'status'=>200,'data'=>['deployed'=>true],'error'=>null];
}



/**
 * Call /~user/manifest.php immediately after account creation using file_get_contents().
 *
 * How it routes:
 * - Connects to the server IP/host derived from WHM_HOST (no DNS for the customer domain required)
 * - Uses the /~{username}/ path so we hit the user's public_html right away
 * - Defaults to HTTP (no SSL/SNI needed pre-AutoSSL). You can force HTTPS via MANIFEST_SCHEME env.
 *
 * Optional:
 * - Set USERDIR_HOST (e.g., your server's primary hostname) if mod_userdir protection
 *   requires a known Host header. If not set, no Host header is sent.
 *
 * Env vars:
 *   WHM_HOST            e.g. https://192.250.238.76:2087   (REQUIRED)
 *   USERDIR_HOST        e.g. server.example.net            (OPTIONAL, recommended if mod_userdir protection is enabled)
 *   MANIFEST_SCHEME     'http' | 'https'                   (OPTIONAL; default 'http')
 *   MANIFEST_VERIFY_SSL '1' to verify SSL; default: off    (OPTIONAL; only matters if MANIFEST_SCHEME=https to an IP)
 */
function callManifest(string $username, int $id, string $domain): bool
{
    $whmHost = normalizeWhmHost(getenv('WHM_HOST'));
    if (!$whmHost) {
        error_log('WHM_HOST not set.');
        return false;
    }

    $parts       = parse_url($whmHost);
    $connectHost = $parts['host'] ?? '';
    if ($connectHost === '') {
        error_log('Could not parse host from WHM_HOST.');
        return false;
    }

    // Prefer HTTP on day zero (no AutoSSL/SNI issues). Allow override via env.
    $scheme = getenv('MANIFEST_SCHEME') ?: 'http';
    $scheme = ($scheme === 'https') ? 'https' : 'http';

    // Build the userdir URL (this ensures we hit /home/{user}/public_html/)
    $url = sprintf(
        '%s://%s/~%s/manifest.php?id=%d&domain=%s',
        $scheme,
        $connectHost,
        rawurlencode($username),
        $id,
        rawurlencode($domain)
    );

    // Optional Host header for mod_userdir “Exclude Protection” (server’s primary hostname).
    $userdirHost = trim((string) getenv('USERDIR_HOST'));

    $headers = [
        // Add Host header only if you’ve set a hostname (do NOT put an IP here).
        // If USERDIR_HOST is empty, omit the Host header entirely.
    ];
    if ($userdirHost !== '' && !preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $userdirHost)) {
        $headers[] = 'Host: ' . $userdirHost;
        // A referer that matches the Host sometimes helps with picky WAF rules:
        $headers[] = 'Referer: ' . $scheme . '://' . $userdirHost . '/';
    }

    // Friendly, browser-ish defaults (helps avoid ModSecurity/hotlink rules)
    $headers = array_merge($headers, [
        'Accept: */*',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: identity',
        'Connection: close',
        'User-Agent: Mozilla/5.0 (compatible; PHP streams; +https://web321.co/)',
        'Expect:', // disable 100-continue
    ]);

    $opts = [
        'http' => [
            'method'        => 'GET',
            'timeout'       => 120,
            'ignore_errors' => true,               // capture body on non-2xx for logging
            'header'        => implode("\r\n", $headers),
            'protocol_version' => 1.1,             // play it safe with HTTP/1.1
        ],
    ];

    // If you *do* force HTTPS to an IP, the cert CN won't match → allow insecure unless you opt in
    if ($scheme === 'https') {
        $verify = (getenv('MANIFEST_VERIFY_SSL') === '1');
        $opts['ssl'] = [
            'verify_peer'       => $verify,
            'verify_peer_name'  => $verify,
            // If you ever use a hostname (not IP) in $connectHost, you can also set:
            // 'SNI_enabled'    => true,
            // 'peer_name'      => $userdirHost ?: $connectHost,
        ];
    }

    $context = stream_context_create($opts);
    $body    = @file_get_contents($url, false, $context);

    // Helper: parse status code from $http_response_header
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $line) {
            if (stripos($line, 'HTTP/') === 0 && preg_match('/\s(\d{3})\s/', $line, $m)) {
                $status = (int) $m[1];
                break;
            }
        }
    }

    if ($body === false) {
        $err = error_get_last();
        error_log(sprintf(
            'Manifest (userdir) failed for %s — %s',
            $url,
            $err['message'] ?? 'unknown stream error'
        ));
        return false;
    }

    if ($status < 200 || $status >= 300) {
        $hdrs = isset($http_response_header) ? implode(' | ', array_slice($http_response_header, 0, 6)) : '(no headers)';
        $snippet = substr(trim((string) $body), 0, 1024);
        error_log(sprintf(
            'Manifest (userdir) non-2xx %d for %s. Headers: %s Body: %s',
            $status,
            $url,
            preg_replace('/\s+/', ' ', $hdrs),
            preg_replace('/\s+/', ' ', $snippet)
        ));
        return false;
    }

    // Optional: validate expected JSON shape
    // $json = json_decode($body, true);
    // if (!is_array($json) || empty($json['ok'])) {
    //     error_log('Manifest JSON invalid or missing ok=true');
    //     return false;
    // }

    return true;
}

function callManifestImage(string $username, int $id, string $domain): string
{
    $whmHost = normalizeWhmHost(getenv('WHM_HOST'));
    if (!$whmHost) {
        error_log('WHM_HOST not set.');
        return false;
    }

    $parts       = parse_url($whmHost);
    $connectHost = $parts['host'] ?? '';
    if ($connectHost === '') {
        error_log('Could not parse host from WHM_HOST.');
        return false;
    }

    // Prefer HTTP on day zero (no AutoSSL/SNI issues). Allow override via env.
    $scheme = getenv('MANIFEST_SCHEME') ?: 'http';
    $scheme = ($scheme === 'https') ? 'https' : 'http';

    // Build the userdir URL (this ensures we hit /home/{user}/public_html/)
    $url = sprintf(
        'http://%s/~%s/manifest.php?id=%d&domain=%s&security=x&return=img&r=%s',
        $connectHost,
        rawurlencode($username),
        $id,
        rawurlencode($domain),
        bin2hex(random_bytes(4))
    );
    return $url;
}

/**
 * Derive a cPanel base URL (port :2083) from your WHM host (port :2087).
 * Reuses normalizeWhmHost() from your file.
 */
function cpanelBaseFromWhmHost(?string $whmHost = null): ?string {
    $whm = normalizeWhmHost($whmHost ?: getenv('WHM_HOST'));
    if (!$whm) return null;
    $p = parse_url($whm);
    if (!$p || empty($p['host'])) return null;
    $scheme = isset($p['scheme']) ? strtolower($p['scheme']) : 'https';
    $host   = $p['host'];
    // Use 2083 (secure cPanel UAPI port)
    return sprintf('%s://%s:%d', $scheme, $host, 2083);
}

/**
 * Low-level UAPI request helper (JSON).
 * - If $token provided => Authorization: cpanel USER:TOKEN
 * - Else if $basicUserPass provided => CURLOPT_USERPWD basic auth
 */
function cpanelUapiRequest(
    string $module,
    string $func,
    array $params = [],
    ?string $cpanelUser = null,
    ?string $token = null,
    ?array $basicUserPass = null, // ['user' => ..., 'pass' => ...]
    ?string $cpanelBase = null    // e.g. https://host:2083
): array {
    $base = $cpanelBase ?: cpanelBaseFromWhmHost();
    if (!$base) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Missing/invalid WHM_HOST → cannot derive cPanel base URL', 'hint' => null];
    }

    $url = rtrim($base, '/') . '/execute/' . rawurlencode($module) . '/' . rawurlencode($func);
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $headers = ['User-Agent: web321-cpanel-client/1.0'];
    if ($token && $cpanelUser) {
        // Token auth for UAPI:
        // Authorization: cpanel username:APITOKEN
        $headers[] = 'Authorization: cpanel ' . $cpanelUser . ':' . $token;
    } elseif ($basicUserPass && isset($basicUserPass['user'], $basicUserPass['pass'])) {
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $basicUserPass['user'] . ':' . $basicUserPass['pass']);
    } else {
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Missing cPanel credentials (token or basic)', 'hint' => 'Provide $token + $cpanelUser or BasicAuth username/password'];
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    // TLS verification (allow override for self-signed/IP-certs)
    $strict = getenv('CPANEL_STRICT_SSL');
    $verify = ($strict === false || $strict === '' || $strict === '1' || $strict === 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cURL error calling UAPI: ' . $err, 'hint' => json_encode($info)];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status, 'data' => $raw, 'error' => 'Non-JSON UAPI response'];
    }

    // UAPI common envelope: result.status 1/0 + data/errors
    $ok = isset($decoded['result']['status']) ? (int)$decoded['result']['status'] === 1 : false;
    return [
        'ok'     => $ok && $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => $decoded,
        'error'  => $ok ? null : (($decoded['result']['errors'][0] ?? null) ?: 'UAPI call failed'),
        'hint'   => null,
    ];
}

/**
 * Multipart POST helper for UAPI Fileman::upload_files
 * $files is an array of local paths. Each gets posted as file-1, file-2, ...
 */

/**
 * Call cPanel API 2 over HTTP to run Fileman::fileop op=extract (to extract a zip).
 * Auth via the same cPanel token header works against /json-api/cpanel.
 */
function cpanelApi2FileopExtract(
    string $cpanelUser,
    string $token,
    string $sourceZip,  // e.g. 'public_html/payload.zip'
    string $destDir,    // e.g. 'public_html'
    ?string $cpanelBase = null
): array {
    $base = $cpanelBase ?: cpanelBaseFromWhmHost();
    if (!$base) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Missing/invalid cPanel base URL'];
    }

    $query = [
        'cpanel_jsonapi_user'       => $cpanelUser,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module'     => 'Fileman',
        'cpanel_jsonapi_func'       => 'fileop',
        'op'                        => 'extract',
        'sourcefiles'               => $sourceZip,
        'destfiles'                 => $destDir,
        'doubledecode'              => 1,
    ];
    $url = rtrim($base, '/') . '/json-api/cpanel?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: cpanel ' . $cpanelUser . ':' . $token,
        'User-Agent: web321-cpanel-client/1.0',
    ]);

    $strict = getenv('CPANEL_STRICT_SSL');
    $verify = ($strict === false || $strict === '' || $strict === '1' || $strict === 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'cURL error calling API2 fileop extract: ' . $err, 'hint' => json_encode($info)];
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    $ok = is_array($decoded) && isset($decoded['cpanelresult']['event']['result']) && (int)$decoded['cpanelresult']['event']['result'] === 1;
    return [
        'ok'     => $ok && $status >= 200 && $status < 300,
        'status' => $status,
        'data'   => $decoded,
        'error'  => $ok ? null : 'API2 extract failed',
        'hint'   => null,
    ];
}

/**
 * Create a cPanel API token (full access) for a newly-created account.
 * Uses BasicAuth with the account's username/password just set in createacct.
 *
 * @param string      $cpanelUser   The new cPanel username
 * @param string      $cpanelPass   The same password you passed to WHM createacct
 * @param null|string $name         Token name (default "web321-<timestamp>")
 * @param null|int    $expiresAt    Optional UNIX timestamp for expiry; omit for no expiry
 */
function createCpanelApiTokenForUser(
    string $cpanelUser,
    string $cpanelPass,
    ?string $name = null,
    ?int $expiresAt = null,
    ?string $cpanelBase = null
): array {
    $name = $name ?: ('web321-' . gmdate('Ymd-His'));
    $params = ['name' => $name];
    if ($expiresAt !== null) {
        $params['expires_at'] = $expiresAt; // UAPI expects UNIX timestamp
    }

    $res = cpanelUapiRequest(
        'Tokens',
        'create_full_access',
        $params,
        null,                // $cpanelUser for token header (not used here)
        null,                // $token
        ['user' => $cpanelUser, 'pass' => $cpanelPass], // BasicAuth for first token
        $cpanelBase
    );

    if (!$res['ok']) return $res;

    $token = $res['data']['result']['data']['token'] ?? null;
    if (!$token) {
        return ['ok' => false, 'status' => $res['status'], 'data' => $res['data'], 'error' => 'Token not present in create_full_access response'];
    }

    return [
        'ok'     => true,
        'status' => $res['status'],
        'data'   => ['token' => $token, 'name' => $name],
        'error'  => null,
        'hint'   => null,
    ];
}

/**
 * Zip a directory recursively into a temp .zip (returns filepath).
 */
function zipDirectory(string $sourceDir): ?string {
    if (!is_dir($sourceDir)) return null;
    if (!class_exists('ZipArchive')) return null;

    $zipPath = sys_get_temp_dir() . '/deploy_' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return null;
    }

    $sourceDir = rtrim($sourceDir, DIRECTORY_SEPARATOR);
    $len = strlen($sourceDir) + 1;

    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($rii as $file) {
        /** @var SplFileInfo $file */
        if ($file->isDir()) continue;
        $full = $file->getPathname();
        $rel  = substr($full, $len);
        // Normalize ZIP paths to forward slashes
        $rel = str_replace(DIRECTORY_SEPARATOR, '/', $rel);
        $zip->addFile($full, $rel);
    }

    $zip->close();
    return $zipPath;
}

/**
 * Deploy a whole local directory to public_html using a cPanel API token.
 * Strategy:
 *  - Zip the directory
 *  - Upload the zip to public_html
 *  - Extract it into public_html
 *  - Remove the uploaded archive
 */
function deployDirectoryToPublicHtmlWithToken(
    string $localDir,
    string $cpanelUser,
    string $cpanelToken,
    ?string $cpanelBase = null
): array {
    $zip = zipDirectory($localDir);
    if (!$zip) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Failed to create ZIP (check ZipArchive extension and path)'];
    }

    $upload = cpanelUapiUploadFiles([$zip], 'public_html', $cpanelUser, $cpanelToken, $cpanelBase);
    if (!$upload['ok']) {
        @unlink($zip);
        return $upload;
    }

    $zipName = basename($zip);
    $extract = cpanelApi2FileopExtract($cpanelUser, $cpanelToken, 'public_html/' . $zipName, 'public_html', $cpanelBase);
    // Try to delete the uploaded archive regardless of extract result
    @unlink($zip);
    // Best-effort cleanup of remote archive (ignore errors if it doesn't exist)
    cpanelApi2FileopExtract($cpanelUser, $cpanelToken, 'public_html/' . $zipName, '/dev/null', $cpanelBase); // no-op if already gone

    return $extract['ok'] ? $extract : $extract; // return status from extract
}

/**
 * One-shot orchestration:
 *  - create WHM account
 *  - create cPanel API token for that account
 *  - deploy local directory to public_html via that token
 *
 * Returns:
 *   [
 *     'ok' => bool,
 *     'createacct' => <whmApiRequest result>,
 *     'token' => '...token...',
 *     'deployment' => <extract result array>,
 *   ]
 */
function provisionAccountAndDeploy(
    string $username,
    string $domain,
    string $password,
    string $localDirToUpload
): array {
    // 1) Create the account in WHM
    $acct = createWhmAccount($username, $domain, $password);
    if (!$acct || empty($acct['ok'])) {
        return ['ok' => false, 'createacct' => $acct, 'token' => null, 'deployment' => null];
    }

    // 2) Create a cPanel API token using the same username/password
    $tok = createCpanelApiTokenForUser($username, $password);
    if (empty($tok['ok'])) {
        return ['ok' => false, 'createacct' => $acct, 'token' => null, 'deployment' => null, 'token_error' => $tok['error'] ?? ''];
    }
    $token = $tok['data']['token'];

    // 3) Deploy your files to public_html using the token
    $deploy = deployDirectoryToPublicHtmlWithToken($localDirToUpload, $username, $token);

    return [
        'ok'         => !empty($deploy['ok']),
        'createacct' => $acct,
        'token'      => $token,          // <-- share back to your application
        'deployment' => $deploy,
    ];
}


/**
 * Concurrent build management functions
 */

/**
 * Create a unique build directory for this request
 * Uses upload_id + timestamp + random string for uniqueness
 */
function createUniqueBuildDir(int $uploadId): array {
    $baseDir = __DIR__ . '/build-temp';
    
    // Ensure base directory exists
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0755, true)) {
            return ['ok' => false, 'error' => 'Cannot create build temp directory', 'path' => null];
        }
    }
    
    // Create unique directory name
    $uniqueId = sprintf('%d_%d_%s', 
        $uploadId, 
        time(), 
        bin2hex(random_bytes(4))
    );
    
    $buildPath = $baseDir . '/build_' . $uniqueId;
    
    if (!mkdir($buildPath, 0755, true)) {
        return ['ok' => false, 'error' => 'Cannot create unique build directory', 'path' => null];
    }
    
    return ['ok' => true, 'error' => null, 'path' => $buildPath, 'id' => $uniqueId];
}

/**
 * Copy files from a source template to the unique build directory
 * This assumes you have a template directory with your base site files
 */
function populateBuildDir(string $buildPath, string $templateDir = null): array {
    $templateDir = $templateDir ?: __DIR__ . '/site-template';
    
    if (!is_dir($templateDir)) {
        return ['ok' => false, 'error' => 'Template directory not found: ' . $templateDir];
    }
    
    if (!is_dir($buildPath)) {
        return ['ok' => false, 'error' => 'Build directory not found: ' . $buildPath];
    }
    
    try {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($templateDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen($templateDir) + 1);
            $destPath = $buildPath . DIRECTORY_SEPARATOR . $relativePath;
            
            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0755, true);
                }
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    mkdir($destDir, 0755, true);
                }
                if (!copy($item->getPathname(), $destPath)) {
                    return ['ok' => false, 'error' => 'Failed to copy: ' . $item->getPathname()];
                }
            }
        }
        
        return ['ok' => true, 'error' => null];
        
    } catch (Exception $e) {
        return ['ok' => false, 'error' => 'Copy failed: ' . $e->getMessage()];
    }
}

/**
 * Generate/customize files in the build directory
 * This is where you'd customize the HTML, add user-specific content, etc.
 */
function customizeBuildFiles(string $buildPath, int $uploadId, string $domain, array $customData = []): array {
    // Example: customize index.html with user data
    $indexPath = $buildPath . '/index.html';
    
    if (is_file($indexPath)) {
        $content = file_get_contents($indexPath);
        
        // Replace placeholders with actual data
        $replacements = [
            '{{DOMAIN}}' => htmlspecialchars($domain),
            '{{UPLOAD_ID}}' => $uploadId,
            '{{GENERATED_AT}}' => date('Y-m-d H:i:s'),
            '{{CACHE_BUSTER}}' => time(),
        ];
        
        // Add custom data replacements
        foreach ($customData as $key => $value) {
            $replacements['{{' . strtoupper($key) . '}}'] = htmlspecialchars($value);
        }
        
        $content = str_replace(array_keys($replacements), array_values($replacements), $content);
        
        if (file_put_contents($indexPath, $content) === false) {
            return ['ok' => false, 'error' => 'Failed to write customized index.html'];
        }
    }
    
    // Add manifest.php with the specific upload_id and domain
    $manifestPath = $buildPath . '/manifest.php';
    $manifestContent = "<?php
// Auto-generated manifest for upload ID: {$uploadId}
// Domain: {$domain}
// Generated: " . date('Y-m-d H:i:s') . "

\$upload_id = " . intval($uploadId) . ";
\$domain = '" . addslashes($domain) . "';
\$security = \$_GET['security'] ?? '';
\$return_type = \$_GET['return'] ?? 'json';

// Basic security check
if (\$security !== 'x') {
    http_response_code(403);
    exit('Forbidden');
}

if (\$return_type === 'img') {
    // Return an image response for preview
    header('Content-Type: image/png');
    // You could generate or return a site screenshot here
    exit();
}

// Return success JSON
header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'upload_id' => \$upload_id,
    'domain' => \$domain,
    'status' => 'deployed',
    'timestamp' => time()
]);
?>";
    
    if (file_put_contents($manifestPath, $manifestContent) === false) {
        return ['ok' => false, 'error' => 'Failed to create manifest.php'];
    }
    
    return ['ok' => true, 'error' => null];
}

/**
 * Clean up old build directories to prevent disk space issues
 * Call this periodically or after successful deployment
 */
function cleanupOldBuilds(int $maxAgeMinutes = 60): array {
    $baseDir = __DIR__ . '/build-temp';
    
    if (!is_dir($baseDir)) {
        return ['ok' => true, 'cleaned' => 0]; // Nothing to clean
    }
    
    $cleaned = 0;
    $cutoffTime = time() - ($maxAgeMinutes * 60);
    
    $iterator = new DirectoryIterator($baseDir);
    
    foreach ($iterator as $dir) {
        if ($dir->isDot() || !$dir->isDir()) continue;
        
        $dirName = $dir->getFilename();
        
        // Only clean directories that match our naming pattern
        if (!preg_match('/^build_\d+_\d+_[a-f0-9]+$/', $dirName)) continue;
        
        $dirPath = $dir->getPathname();
        $dirTime = $dir->getMTime();
        
        if ($dirTime < $cutoffTime) {
            if (removeDirectory($dirPath)) {
                $cleaned++;
                error_log("Cleaned up old build directory: $dirName");
            }
        }
    }
    
    return ['ok' => true, 'cleaned' => $cleaned];
}

/**
 * Recursively remove a directory and all its contents
 */
 
if (!function_exists('removeDirectory')) { 
    function removeDirectory(string $dir): bool {
    if (!is_dir($dir)) return false;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    
    return rmdir($dir);
}
}

/**
 * Enhanced deployment function that works with unique build directories
 */
function deployUniqueBuildsViaHttp(string $buildPath, string $username, string $password): array {
    if (!is_dir($buildPath)) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Build directory not found: '.$buildPath];
    }
    
    // Get the IP from WHM_HOST
    $whmHost = getenv('WHM_HOST');
    if (!$whmHost) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'WHM_HOST not set'];
    }
    
    $p = parse_url($whmHost);
    $host = $p['host'] ?? '';
    
    // Convert cprapid hostname to IP if needed  
    if (preg_match('/^(\d+)-(\d+)-(\d+)-(\d+)\.cprapid\.com$/', $host, $m)) {
        $host = sprintf('%d.%d.%d.%d', $m[1], $m[2], $m[3], $m[4]);
    }
    
    // Create a zip file from the build directory
    $zipPath = zipDirectory($buildPath);
    if (!$zipPath) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Failed to create ZIP file from build'];
    }
    
    // Upload via HTTP cPanel (port 2082)
    $uploadUrl = "http://{$host}:2082/execute/Fileman/upload_files";
    
    $postFields = [
        'dir' => 'public_html',
        'file-1' => curl_file_create($zipPath, 'application/zip', basename($zipPath))
    ];
    
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postFields,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_HTTPHEADER => [
            'User-Agent: web321-cpanel-build-deploy/1.0'
        ],
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => 120
    ]);
    
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($raw === false) {
        @unlink($zipPath);
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Upload failed'];
    }
    
    $uploadResult = json_decode($raw, true);
    $uploadOk = is_array($uploadResult) && !empty($uploadResult['status']);
    
    if (!$uploadOk) {
        @unlink($zipPath);
        return ['ok'=>false,'status'=>$status,'data'=>$uploadResult,'error'=>'File upload failed'];
    }
    
    // Extract the zip
    $extractUrl = "http://{$host}:2082/json-api/cpanel?" . http_build_query([
        'cpanel_jsonapi_user' => $username,
        'cpanel_jsonapi_apiversion' => 2,
        'cpanel_jsonapi_module' => 'Fileman',
        'cpanel_jsonapi_func' => 'fileop',
        'op' => 'extract',
        'sourcefiles' => 'public_html/' . basename($zipPath),
        'destfiles' => 'public_html',
        'doubledecode' => 1
    ]);
    
    $ch = curl_init($extractUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $username . ':' . $password,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 60
    ]);
    
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    @unlink($zipPath); // Clean up local zip
    
    if ($raw === false) {
        return ['ok'=>false,'status'=>0,'data'=>null,'error'=>'Extract operation failed'];
    }
    
    $extractResult = json_decode($raw, true);
    $extractOk = is_array($extractResult) && 
                 isset($extractResult['cpanelresult']['event']['result']) && 
                 (int)$extractResult['cpanelresult']['event']['result'] === 1;
    
    return [
        'ok' => $extractOk,
        'status' => $status,
        'data' => $extractResult,
        'error' => $extractOk ? null : 'File extraction failed'
    ];
}
?>
