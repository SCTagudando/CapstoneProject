<?php
// paymongo_webhook.php — Receives PayMongo payment events
// Register this URL in your PayMongo Dashboard > Webhooks:
// https://yourdomain.com/sfive/paymongo_webhook.php
//
// Events to enable in dashboard:
//   - link.payment.paid
//   - payment.paid

require_once 'includes/config.php';
require_once 'includes/paymongo.php';

$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// Log every incoming webhook for debugging
$logDir = __DIR__ . '/logs/';
file_put_contents($logDir . 'webhook.log',
    date('Y-m-d H:i:s') . " | RECEIVED | sig=" . substr($signature, 0, 40) . "...\n",
    FILE_APPEND
);

// Verify signature
if (!verifyPaymongoWebhook($rawBody, $signature)) {
    file_put_contents($logDir . 'webhook.log',
        date('Y-m-d H:i:s') . " | SIGNATURE FAILED\n",
        FILE_APPEND
    );
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawBody, true);
if (!$event) {
    http_response_code(400);
    exit;
}

// Log the event type
$eventType = $event['data']['attributes']['type'] ?? '';
file_put_contents($logDir . 'webhook.log',
    date('Y-m-d H:i:s') . " | EVENT TYPE: {$eventType}\n",
    FILE_APPEND
);

// Log full payload for debugging (remove in production)
file_put_contents($logDir . 'webhook_payload.log',
    date('Y-m-d H:i:s') . "\n" . json_encode($event, JSON_PRETTY_PRINT) . "\n\n",
    FILE_APPEND
);

if ($eventType === 'link.payment.paid' || $eventType === 'payment.paid') {

    $db = getDB();
    $booking_code = '';
    $amount_paid  = 0;
    $payment_id   = '';

    if ($eventType === 'link.payment.paid') {
        // Payload: event.data.attributes.data = the payment link object
        // The link has metadata with booking_code
        $linkObj      = $event['data']['attributes']['data'] ?? [];
        $linkAttr     = $linkObj['attributes'] ?? [];
        $payment_id   = $linkObj['id'] ?? '';
        $amount_paid  = ($linkAttr['amount'] ?? 0) / 100;

        // booking_code is stored in link metadata
        $metadata     = $linkAttr['metadata'] ?? [];
        $booking_code = $metadata['booking_code'] ?? '';

        // Also try to get booking_code from remarks if metadata is empty
        if (!$booking_code) {
            $remarks      = $linkAttr['remarks'] ?? '';
            // remarks format: "Booking: SFR-XXXXXXXX"
            if (preg_match('/Booking:\s*(SFR-\w+)/i', $remarks, $m)) {
                $booking_code = $m[1];
            }
        }

        // Fallback: look up by paymongo_link_id
        if (!$booking_code) {
            $link_id = $linkObj['id'] ?? '';
            if ($link_id) {
                $stmt = $db->prepare("SELECT booking_code FROM reservations WHERE paymongo_link_id = ?");
                $stmt->execute([$link_id]);
                $row = $stmt->fetch();
                $booking_code = $row['booking_code'] ?? '';
            }
        }

    } elseif ($eventType === 'payment.paid') {
        // Payload: event.data.attributes.data = the payment object
        $payObj       = $event['data']['attributes']['data'] ?? [];
        $payAttr      = $payObj['attributes'] ?? [];
        $payment_id   = $payObj['id'] ?? '';
        $amount_paid  = ($payAttr['amount'] ?? 0) / 100;

        // metadata on the payment object
        $metadata     = $payAttr['metadata'] ?? [];
        $booking_code = $metadata['booking_code'] ?? '';

        // Fallback: check description / statement_descriptor
        if (!$booking_code) {
            $desc = $payAttr['description'] ?? '';
            if (preg_match('/(SFR-\w+)/', $desc, $m)) {
                $booking_code = $m[1];
            }
        }
    }

    file_put_contents($logDir . 'webhook.log',
        date('Y-m-d H:i:s') . " | booking_code={$booking_code} amount=₱{$amount_paid} payment_id={$payment_id}\n",
        FILE_APPEND
    );

    if ($booking_code) {
        $stmt = $db->prepare("SELECT * FROM reservations WHERE booking_code = ?");
        $stmt->execute([$booking_code]);
        $reservation = $stmt->fetch();

        if ($reservation) {
            // Confirm reservation and mark paid
            $db->prepare("
                UPDATE reservations
                SET status = 'Confirmed', payment_status = 'Paid'
                WHERE booking_code = ?
            ")->execute([$booking_code]);

            // Log to gcash_payments
            $db->prepare("
                INSERT INTO gcash_payments
                    (reservation_id, reference_number, amount, sender_name, sender_number, status)
                VALUES (?, ?, ?, 'GCash via PayMongo', 'PayMongo API', 'Verified')
                ON DUPLICATE KEY UPDATE status='Verified', verified_at=NOW()
            ")->execute([$reservation['id'], $payment_id, $amount_paid]);

            file_put_contents($logDir . 'webhook.log',
                date('Y-m-d H:i:s') . " | ✅ CONFIRMED | {$booking_code} | ₱{$amount_paid}\n",
                FILE_APPEND
            );
        } else {
            file_put_contents($logDir . 'webhook.log',
                date('Y-m-d H:i:s') . " | ❌ Reservation not found for code: {$booking_code}\n",
                FILE_APPEND
            );
        }
    } else {
        file_put_contents($logDir . 'webhook.log',
            date('Y-m-d H:i:s') . " | ❌ Could not extract booking_code from payload\n",
            FILE_APPEND
        );
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
?>
