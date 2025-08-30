<?php
function stripeRequest(string $method, string $endpoint, array $params = []) {
    $secret = getenv('STRIPE_SECRET_KEY');
    // Fallback to defined constant if environment variable is not set
    if (!$secret && defined('STRIPE_SECRET_KEY')) {
        $secret = STRIPE_SECRET_KEY;
    }
    if (!$secret) {
        return null;
    }
    
    // print "We got something";
    
    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $secret . ':');
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    } elseif ($method === 'GET' && !empty($params)) {
        $url .= '?' . http_build_query($params);
        curl_setopt($ch, CURLOPT_URL, $url);
    }
    $response = curl_exec($ch);
    if ($response === false) {
        curl_close($ch);
        return null;
    }
    
    /*
    print '<pre>';
    print print_r($response, TRUE);
    print print_r($ch, TRUE);    
    print '</pre>';
    */
    
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status < 200 || $status >= 300) {
        return null;
    }
    return json_decode($response, true);
}

function createCheckoutSession(string $customerEmail, string $priceId, string $successUrl, string $cancelUrl, ?string $clientReferenceId = null, ?string $customerId = null) {
    $separator = (strpos($successUrl, '?') === false) ? '?' : '&';
    $params = [
        'mode' => 'subscription',
        'line_items' => [[ 'price' => $priceId, 'quantity' => 1 ]],
        'success_url' => $successUrl . $separator . 'session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl
    ];
    if ($customerId) {
        $params['customer'] = $customerId;
    } else {
        $params['customer_email'] = $customerEmail;
    }
    if ($clientReferenceId !== null) {
        $params['client_reference_id'] = $clientReferenceId;
    }
    // Because Stripe expects line_items[] style fields, manually build query
    $flat = [];
    foreach ($params['line_items'] as $i => $item) {
        foreach ($item as $k => $v) {
            $flat["line_items[$i][$k]"] = $v;
        }
    }
    unset($params['line_items']);
    $flat = array_merge($params, $flat);
    return stripeRequest('POST', 'checkout/sessions', $flat);
}

function createBillingPortalSession(string $customerId, string $returnUrl) {
    $params = [
        'customer' => $customerId,
        'return_url' => $returnUrl
    ];
    return stripeRequest('POST', 'billing_portal/sessions', $params);
}
?>