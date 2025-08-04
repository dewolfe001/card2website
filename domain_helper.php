<?php
function suggestDomainNames(string $text, int $count = 5): array {
    $apiKey = getenv('OPENAI_API_KEY');
    if ($apiKey) {
        $prompt = "Suggest $count short, memorable domain names for this business. Only respond with a JSON array of the domains.";
        $postData = [
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $prompt],
                ['role' => 'user', 'content' => $text]
            ],
            'max_tokens' => 100
        ];
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        $response = curl_exec($ch);
        if ($response !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
            $json = json_decode($response, true);
            if ($json && isset($json['choices'][0]['message']['content'])) {
                $content = trim($json['choices'][0]['message']['content']);
                $domains = json_decode($content, true);
                if (is_array($domains)) {
                    return array_slice($domains, 0, $count);
                }
            }
        }
        curl_close($ch);
    }
    $clean = preg_replace('/[^a-zA-Z0-9]/', '', strtolower(substr($text, 0, 15)));
    $tlds = ['.com', '.net', '.co', '.org', '.biz'];
    $suggestions = [];
    foreach ($tlds as $tld) {
        $suggestions[] = $clean . $tld;
        if (count($suggestions) >= $count) break;
    }
    return $suggestions;
}


function namecheapApiRequest(array $params): ?SimpleXMLElement
{
    $apiUser  = getenv('NAMECHEAP_API_USER');
    $apiKey   = getenv('NAMECHEAP_API_KEY');
    $userName = getenv('NAMECHEAP_USERNAME');
    $clientIp = '209.54.114.162'; // getenv('NAMECHEAP_CLIENT_IP');

    if (! $apiUser || ! $apiKey || ! $userName || ! $clientIp) {
        error_log("Namecheap creds missing or CLIENT_IP not set");
        return null;
    }

    $base = 'https://api.namecheap.com/xml.response';
    // merge defaults first, then any overrides in $params
    $query = array_merge([
        'ApiUser'   => $apiUser,
        'ApiKey'    => $apiKey,
        'UserName'  => $userName,
        'ClientIp'  => $clientIp,
    ], $params);

    $url = $base . '?' . http_build_query($query);
    error_log("Namecheap API URL: $url");  // <— inspect this in your logs

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false) {
        error_log("Namecheap curl error");
        return null;
    }
    if ($code !== 200) {
        error_log("Namecheap returned HTTP $code: $resp");
        return null;
    }

    $xml = @simplexml_load_string($resp);
    if (! $xml) {
        error_log("Failed to parse XML: $resp");
        return null;
    }
    // catch any API‑level errors
    if (isset($xml->Errors->Error)) {
        error_log("Namecheap API error: " . (string)$xml->Errors->Error);
    }
    return $xml;
}


function xnamecheapApiRequest(array $params): ?SimpleXMLElement {
    $apiUser = getenv('NAMECHEAP_API_USER');
    $apiKey = getenv('NAMECHEAP_API_KEY');
    $userName = getenv('NAMECHEAP_USERNAME');
    $clientIp = getenv('NAMECHEAP_CLIENT_IP');
    if (!$apiUser || !$apiKey || !$userName || !$clientIp) {
        return null;
    }
    
    $base = 'https://api.namecheap.com/xml.response';
    $query = array_merge([
        'ApiUser' => $apiUser,
        'ApiKey' => $apiKey,
        'UserName' => $userName,
        'ClientIp' => $clientIp
    ], $params);
    $url = $base . '?' . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    if ($response === false) {
        return null;
    }
    return simplexml_load_string($response);
}

function checkDomainAvailability(array $domains): array {
    $result = [];
    $xml = namecheapApiRequest([
        'Command' => 'namecheap.domains.check',
        'DomainList' => implode(',', $domains)
    ]);
    if (!$xml || !isset($xml->CommandResponse->DomainCheckResult)) {
        foreach ($domains as $d) {
            $result[$d] = null;
        }
        error_log(print_r($xml, TRUE));
        return $result;
    }
    foreach ($xml->CommandResponse->DomainCheckResult as $item) {
        $domain = (string)$item['Domain'];
        $avail = ((string)$item['Available'] === 'true');
        $result[$domain] = $avail;
    }
    return $result;
}

function registerDomain(string $domain): ?string {
    $contact = [
        'RegistrantFirstName' => getenv('NAMECHEAP_CONTACT_FIRST') ?: 'John',
        'RegistrantLastName' => getenv('NAMECHEAP_CONTACT_LAST') ?: 'Doe',
        'RegistrantAddress1' => getenv('NAMECHEAP_CONTACT_ADDRESS') ?: '123 Example St',
        'RegistrantCity' => getenv('NAMECHEAP_CONTACT_CITY') ?: 'City',
        'RegistrantStateProvince' => getenv('NAMECHEAP_CONTACT_STATE') ?: 'CA',
        'RegistrantPostalCode' => getenv('NAMECHEAP_CONTACT_ZIP') ?: '00000',
        'RegistrantCountry' => getenv('NAMECHEAP_CONTACT_COUNTRY') ?: 'US',
        'RegistrantPhone' => getenv('NAMECHEAP_CONTACT_PHONE') ?: '+1.5555555555',
        'RegistrantEmailAddress' => getenv('NAMECHEAP_CONTACT_EMAIL') ?: 'example@example.com'
    ];
    $params = array_merge([
        'Command' => 'namecheap.domains.create',
        'DomainName' => $domain,
        'Years' => 1
    ], $contact);
    $xml = namecheapApiRequest($params);
    if (!$xml || !isset($xml->CommandResponse->DomainCreateResult)) {
        return null;
    }
    $res = $xml->CommandResponse->DomainCreateResult;
    if ((string)$res['Registered'] === 'true') {
        return (string)$res['Domain'];
    }
    return null;
}
?>
