<?php
// paymongo_webhook.php — Receives PayMongo payment events
// Register this URL in your PayMongo Dashboard > Webhooks:
// https://yourdomain.com/sfive/paymongo_webhook.php

require_once 'includes/config.php';
require_once 'includes/paymongo.php';

// Read raw body and signature
$rawBody   = file_get_contents('php://input');
$signature = $_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '';

// Verify signature
if (!verifyPaymongoWebhook($rawBody, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$event = json_decode($rawBody, true);
if (!$event) {
    http_response_code(400);
    exit;
}

$eventType = $event['data']['attributes']['type'] ?? '';
$resource  = $event['data']['attributes']['data'] ?? [];

// Handle payment.paid event (from payment link)
if ($eventType === 'payment.paid' || $eventType === 'link.payment.paid') {
    $metadata     = $resource['attributes']['metadata'] ?? [];
    $booking_code = $metadata['booking_code'] ?? '';
    $amount_paid  = ($resource['attributes']['amount'] ?? 0) / 100;
    $payment_id   = $resource['id'] ?? '';

    if ($booking_code) {
        $db = getDB();

        // Find reservation
        $stmt = $db->prepare("SELECT * FROM reservations WHERE booking_code = ?");
        $stmt->execute([$booking_code]);
        $reservation = $stmt->fetch();

        if ($reservation) {
            // Update reservation to Confirmed + Paid
            $stmt = $db->prepare("
                UPDATE reservations
                SET status = 'Confirmed', payment_status = 'Paid'
                WHERE booking_code = ?
            ");
            $stmt->execute([$booking_code]);

            // Log to gcash_payments table
            $stmt = $db->prepare("
                INSERT INTO gcash_payments
                (reservation_id, reference_number, amount, sender_name, sender_number, status)
                VALUES (?, ?, ?, 'PayMongo GCash', 'via PayMongo API', 'Verified')
                ON DUPLICATE KEY UPDATE status='Verified', verified_at=NOW()
            ");
            $stmt->execute([$reservation['id'], $payment_id, $amount_paid]);

            // Log webhook event
            $log = date('Y-m-d H:i:s') . " | PAID | {$booking_code} | ₱{$amount_paid} | {$payment_id}\n";
            file_put_contents(__DIR__ . '/logs/webhook.log', $log, FILE_APPEND);
        }
    }
}

http_response_code(200);
echo json_encode(['received' => true]);
?>
