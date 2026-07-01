<?php

/**
 * Save a pending GCash payment submission after booking.
 * Called right after the reservation is inserted.
 */
function saveGcashSubmission(array $params): array {


    $db = $params['db'];

    try {
        $stmt = $db->prepare("
            INSERT INTO gcash_payments
                (reservation_id, reference_number, amount, sender_name, sender_number, proof_image, status)
            VALUES (?, ?, ?, ?, ?, ?, 'Pending')
        ");
        $stmt->execute([
            $params['reservation_id'],
            $params['reference_number'],
            $params['amount'],
            $params['guest_name'],
            $params['guest_phone'],
            $params['proof_image'] ?? '',
        ]);

        return ['success' => true];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Handle screenshot upload for GCash proof.
 * Returns the saved filename or '' on failure.
 */
function uploadGcashProof(array $file, string $booking_code): string {
    if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime    = mime_content_type($file['tmp_name']);

    if (!in_array($mime, $allowed)) {
        return '';
    }

    if ($file['size'] > 5 * 1024 * 1024) { // 5 MB max
        return '';
    }

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'gcash_' . preg_replace('/[^a-zA-Z0-9]/', '', $booking_code) . '_' . time() . '.' . strtolower($ext);
    $dest     = __DIR__ . '/../uploads/gcash/' . $filename;

    if (!is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }

    return move_uploaded_file($file['tmp_name'], $dest) ? $filename : '';
}
?>