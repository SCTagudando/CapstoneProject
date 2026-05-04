<?php
// includes/paymongo.php — PayMongo GCash API Integration
// Keys are defined in includes/config.php
// Sign up at: https://dashboard.paymongo.com

define('PAYMONGO_API_BASE', 'https://api.paymongo.com/v1');

/**
 * Create a PayMongo Payment Link for GCash
 * Returns the checkout_url the customer visits to pay
 */
function createPaymongoGcashLink(array $params): array {
    // $params = [
    //   'amount'       => 80000,        // in centavos (₱800 = 80000)
    //   'description'  => 'Bahay Kubo 1',
    //   'booking_code' => 'SFR-ABCD1234',
    //   'customer_name'=> 'Juan dela Cruz',
    //   'email'        => 'juan@email.com',
    //   'phone'        => '09123456789',
    // ]

    $payload = [
        'data' => [
            'attributes' => [
                'amount'      => (int)$params['amount'],  // centavos
                'currency'    => 'PHP',
                'description' => 'S-Five Resort — ' . $params['description'],
                'remarks'     => 'Booking: ' . $params['booking_code'],
                'payment_method_types' => ['gcash'],
                'send_email_receipt'   => true,
                'show_description'     => true,
                'show_line_items'      => true,
                'line_items' => [
                    [
                        'currency'  => 'PHP',
                        'amount'    => (int)$params['amount'],
                        'name'      => $params['description'],
                        'quantity'  => 1,
                    ]
                ],
                'metadata' => [
                    'booking_code' => $params['booking_code'],
                ],
            ]
        ]
    ];

    $response = paymongoRequest('POST', '/links', $payload);

    if (!$response || isset($response['errors'])) {
        $errMsg = $response['errors'][0]['detail'] ?? 'PayMongo API error';
        return ['success' => false, 'error' => $errMsg];
    }

    $attr = $response['data']['attributes'] ?? [];
    return [
        'success'      => true,
        'link_id'      => $response['data']['id'],
        'checkout_url' => $attr['checkout_url'] ?? '',
        'status'       => $attr['status'] ?? '',
        'reference_number' => $attr['reference_number'] ?? '',
    ];
}

/**
 * Retrieve a payment link status from PayMongo
 */
function getPaymongoLinkStatus(string $link_id): array {
    $response = paymongoRequest('GET', '/links/' . $link_id, []);

    if (!$response || isset($response['errors'])) {
        return ['success' => false, 'status' => 'unknown'];
    }

    $attr = $response['data']['attributes'] ?? [];
    $payments = $attr['payments'] ?? [];
    $paid = false;
    $payment_id = '';

    foreach ($payments as $p) {
        if (($p['attributes']['status'] ?? '') === 'paid') {
            $paid = true;
            $payment_id = $p['id'];
            break;
        }
    }

    return [
        'success'    => true,
        'status'     => $attr['status'] ?? 'unpaid',
        'paid'       => $paid,
        'payment_id' => $payment_id,
        'amount'     => ($attr['amount'] ?? 0) / 100,
    ];
}

/**
 * Verify PayMongo webhook signature
 */
function verifyPaymongoWebhook(string $rawBody, string $signatureHeader): bool {
    // Signature format: t=TIMESTAMP,te=TEST_SIG,li=LIVE_SIG
    $parts = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k] = $v;
    }

    $timestamp = $parts['t'] ?? '';
    $testSig   = $parts['te'] ?? '';
    $liveSig   = $parts['li'] ?? '';

    $toSign  = $timestamp . '.' . $rawBody;
    $secret  = PAYMONGO_WEBHOOK_SECRET;
    $computed = hash_hmac('sha256', $toSign, $secret);

    return hash_equals($computed, $testSig) || hash_equals($computed, $liveSig);
}

/**
 * Core HTTP request to PayMongo API
 */
function paymongoRequest(string $method, string $endpoint, array $data): ?array {
    $credentials = base64_encode(PAYMONGO_SECRET_KEY . ':');
    $url = PAYMONGO_API_BASE . $endpoint;

    $opts = [
        'http' => [
            'method'  => $method,
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . $credentials,
            ]),
            'ignore_errors' => true,
        ]
    ];

    if ($method !== 'GET' && !empty($data)) {
        $opts['http']['content'] = json_encode($data);
    }

    $ctx      = stream_context_create($opts);
    $raw      = @file_get_contents($url, false, $ctx);

    if ($raw === false) return null;
    return json_decode($raw, true);
}
?>
