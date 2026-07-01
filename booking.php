<?php
// booking.php — Reservation + GCash Payment via PayMongo API
require_once 'includes/config.php';
require_once 'includes/paymongo.php';
$db = getDB();

// ===============================================================
// SELF-CONTAINED AJAX ENDPOINTS
// ===============================================================
// Instead of separate files (check_availability.php, get_booked_dates.php)
// that have to be remembered and uploaded alongside this one, the JS on
// this page calls booking.php?action=... and gets handled right here,
// before any HTML is rendered. One file, nothing extra to misplace.
if (isset($_GET['action'])) {
    header('Content-Type: application/json');

    // ---- Cottage-by-cottage availability for a date range ----
    if ($_GET['action'] === 'check_availability') {
        $ci = isset($_GET['check_in'])  ? clean($_GET['check_in'])  : '';
        $co = isset($_GET['check_out']) ? clean($_GET['check_out']) : '';

        if (!$ci || !$co || strtotime($ci) === false || strtotime($co) === false || $ci >= $co) {
            echo json_encode(['success' => false, 'error' => 'Invalid date range']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT c.id,
                (SELECT COUNT(*) FROM reservations r
                 WHERE r.cottage_id = c.id
                 AND r.status != 'Cancelled'
                 AND NOT (r.check_out <= ? OR r.check_in >= ?)) AS is_booked
            FROM cottages c
            WHERE c.is_available = 1
        ");
        $stmt->execute([$ci, $co]);

        $availability = [];
        foreach ($stmt->fetchAll() as $row) {
            $availability[$row['id']] = ((int)$row['is_booked'] > 0) ? 'booked' : 'available';
        }

        echo json_encode(['success' => true, 'availability' => $availability]);
        exit;
    }

    // ---- Booked dates, for the red "Unavailable: ..." text under the date fields ----
    if ($_GET['action'] === 'get_booked_dates') {
        $cottage_id  = isset($_GET['cottage_id']) ? (int)$_GET['cottage_id'] : 0;
        $range_start = date('Y-m-d');
        $range_end   = date('Y-m-d', strtotime('+12 months'));

        if ($cottage_id) {
            $stmt = $db->prepare("
                SELECT check_in, check_out FROM reservations
                WHERE cottage_id = ? AND status != 'Cancelled' AND check_out > ?
            ");
            $stmt->execute([$cottage_id, $range_start]);
            echo json_encode(['success' => true, 'mode' => 'cottage', 'ranges' => $stmt->fetchAll()]);
            exit;
        }

        // No specific cottage chosen yet: find dates where EVERY cottage is taken.
        $total_cottages = (int)$db->query("SELECT COUNT(*) FROM cottages WHERE is_available = 1")->fetchColumn();
        if ($total_cottages === 0) {
            echo json_encode(['success' => true, 'mode' => 'resort', 'fully_booked_dates' => []]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT cottage_id, check_in, check_out FROM reservations
            WHERE status != 'Cancelled' AND check_out > ?
        ");
        $stmt->execute([$range_start]);

        $day_booked_cottages = [];
        foreach ($stmt->fetchAll() as $r) {
            $d   = new DateTime(max($r['check_in'], $range_start));
            $end = new DateTime(min($r['check_out'], $range_end));
            while ($d < $end) {
                $day_booked_cottages[$d->format('Y-m-d')][$r['cottage_id']] = true;
                $d->modify('+1 day');
            }
        }

        $fully_booked = [];
        foreach ($day_booked_cottages as $day => $cottages) {
            if (count($cottages) >= $total_cottages) $fully_booked[] = $day;
        }
        sort($fully_booked);

        echo json_encode(['success' => true, 'mode' => 'resort', 'fully_booked_dates' => $fully_booked]);
        exit;
    }

    // ── Poll PayMongo payment status (called by success page JS) ──────────
    if ($_GET['action'] === 'poll_payment') {
        require_once 'includes/paymongo.php';
        $booking_code = clean($_GET['code'] ?? '');

        if (!$booking_code) {
            echo json_encode(['success' => false, 'error' => 'No booking code']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM reservations WHERE booking_code = ?");
        $stmt->execute([$booking_code]);
        $res = $stmt->fetch();

        if (!$res) {
            echo json_encode(['success' => false, 'error' => 'Not found']);
            exit;
        }

        // Already confirmed — just return the status
        if ($res['payment_status'] === 'Paid') {
            echo json_encode(['success' => true, 'paid' => true, 'status' => 'Confirmed']);
            exit;
        }

        // Ask PayMongo
        if (!empty($res['paymongo_link_id'])) {
            $pm = getPaymongoLinkStatus($res['paymongo_link_id']);

            if ($pm['success'] && $pm['paid']) {
                $db->prepare("
                    UPDATE reservations
                    SET status = 'Confirmed', payment_status = 'Paid'
                    WHERE booking_code = ?
                ")->execute([$booking_code]);

                $db->prepare("
                    INSERT INTO gcash_payments
                        (reservation_id, reference_number, amount, sender_name, sender_number, status)
                    VALUES (?, ?, ?, 'GCash via PayMongo', 'PayMongo Poll', 'Verified')
                    ON DUPLICATE KEY UPDATE status='Verified', verified_at=NOW()
                ")->execute([$res['id'], $pm['payment_id'], $pm['amount']]);

                $logDir = __DIR__ . '/logs/';
                if (!is_dir($logDir)) mkdir($logDir, 0755, true);
                file_put_contents($logDir . 'webhook.log',
                    date('Y-m-d H:i:s') . " | ✅ AJAX POLL CONFIRMED | {$booking_code}\n",
                    FILE_APPEND
                );

                echo json_encode(['success' => true, 'paid' => true, 'status' => 'Confirmed']);
                exit;
            }
        }

        echo json_encode(['success' => true, 'paid' => false, 'status' => $res['payment_status']]);
        exit;
    }
    // ──────────────────────────────────────────────────────────────────────

    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

$errors       = [];
$success      = false;
$booking_result = null;

// URL params
$check_in   = isset($_GET['check_in'])   ? clean($_GET['check_in'])   : '';
$check_out  = isset($_GET['check_out'])  ? clean($_GET['check_out'])  : '';
$guests     = isset($_GET['guests'])     ? (int)$_GET['guests']       : 2;
$cottage_id = isset($_GET['cottage_id']) ? (int)$_GET['cottage_id']   : 0;

// All cottages with booking status
//
// FIX: Previously, when no check_in/check_out were supplied, this fell back
// to checking whether a cottage was booked "right now" (today). That status
// has nothing to do with the dates the visitor is actually trying to book,
// and — combined with the JS never re-checking availability after the page
// loaded — caused cottages to stay stuck as "Unavailable" even after picking
// completely different dates. Now: if no dates are known yet, every cottage
// defaults to available, and the real per-date check happens live via
// the ?action=check_availability endpoint at the top of this file (see
// JS at the bottom of this file) the moment both dates are filled in or
// changed.
function getCottagesWithStatus($db, $check_in, $check_out) {
    if ($check_in && $check_out) {
        $stmt = $db->prepare("
            SELECT c.*,
                (SELECT COUNT(*) FROM reservations r
                 WHERE r.cottage_id = c.id
                 AND r.status != 'Cancelled'
                 AND NOT (r.check_out <= ? OR r.check_in >= ?)) AS is_booked
            FROM cottages c
            WHERE c.is_available = 1
            ORDER BY FIELD(c.category,'Bahay Kubo','Open Cottage','Kubo Premium'), c.name
        ");
        $stmt->execute([$check_in, $check_out]);
    } else {
        $stmt = $db->query("
            SELECT c.*, 0 AS is_booked
            FROM cottages c
            WHERE c.is_available = 1
            ORDER BY FIELD(c.category,'Bahay Kubo','Open Cottage','Kubo Premium'), c.name
        ");
    }
    return $stmt->fetchAll();
}

$all_cottages = getCottagesWithStatus($db, $check_in, $check_out);

// Selected cottage
$selected_cottage = null;
if ($cottage_id) {
    $stmt = $db->prepare("SELECT * FROM cottages WHERE id=?");
    $stmt->execute([$cottage_id]);
    $selected_cottage = $stmt->fetch();
}

// Price calc
$nights = 0; $total_price = 0;
if ($check_in && $check_out && $selected_cottage) {
    $nights      = (strtotime($check_out) - strtotime($check_in)) / 86400;
    $total_price = $nights * $selected_cottage['price_per_night'];
}

// ==============================
// HANDLE FORM SUBMISSION
// ==============================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $guest_name     = clean($_POST['guest_name']     ?? '');
    $guest_email    = clean($_POST['guest_email']    ?? '');
    $guest_phone    = clean($_POST['guest_phone']    ?? '');
    $cottage_id     = (int)($_POST['cottage_id']     ?? 0);
    $check_in       = clean($_POST['check_in']       ?? '');
    $check_out      = clean($_POST['check_out']      ?? '');
    $num_guests     = (int)($_POST['num_guests']     ?? 1);
    $special_req    = clean($_POST['special_requests'] ?? '');
    $payment_method = clean($_POST['payment_method'] ?? 'Pay at Resort');

    // Validation
    if (!$guest_name)  $errors[] = "Full name is required.";
    if (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (!$guest_phone) $errors[] = "Phone number is required.";
    if (!$cottage_id)  $errors[] = "Please select a cottage.";
    if (!$check_in || !$check_out) $errors[] = "Check-in and check-out dates are required.";
    if ($check_in && $check_out && $check_in >= $check_out) $errors[] = "Check-out must be after check-in.";
    if ($check_in && $check_in < date('Y-m-d')) $errors[] = "Check-in cannot be in the past.";

    // Guest capacity check (server-side)
    if (empty($errors) && $cottage_id) {
        $stmt = $db->prepare("SELECT capacity FROM cottages WHERE id=?");
        $stmt->execute([$cottage_id]);
        $max_cap = $stmt->fetchColumn();
        if ($num_guests > $max_cap) {
            $errors[] = "The selected cottage can only accommodate up to {$max_cap} guests. You entered {$num_guests}.";
        }
    }

    // Double booking check — this is the authoritative, server-side check
    // for the EXACT dates submitted. It always re-evaluates against the
    // current dates in $_POST, so it's already correct for "another day or
    // date" — the bug was only in the front-end list rendering above.
    if (empty($errors) && $cottage_id) {
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM reservations
            WHERE cottage_id=? AND status != 'Cancelled'
            AND NOT (check_out <= ? OR check_in >= ?)
        ");
        $stmt->execute([$cottage_id, $check_in, $check_out]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "This cottage is already booked for the selected dates. Please choose other dates or a different cottage.";
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("SELECT * FROM cottages WHERE id=?");
        $stmt->execute([$cottage_id]);
        $cottage = $stmt->fetch();

        $nights      = (strtotime($check_out) - strtotime($check_in)) / 86400;
        $total_price = $nights * $cottage['price_per_night'];
        $booking_code = generateBookingCode();

        $pay_status = ($payment_method === 'GCash') ? 'Pending Verification' : 'Unpaid';

        // Insert reservation
        $stmt = $db->prepare("
            INSERT INTO reservations
            (booking_code, guest_name, guest_email, guest_phone, cottage_id,
             check_in, check_out, num_guests, special_requests, total_price,
             status, payment_method, payment_status)
            VALUES (?,?,?,?,?,?,?,?,?,?,'Pending',?,?)
        ");
        $stmt->execute([
            $booking_code, $guest_name, $guest_email, $guest_phone, $cottage_id,
            $check_in, $check_out, $num_guests, $special_req, $total_price,
            $payment_method, $pay_status
        ]);
        $res_id = $db->lastInsertId();

        // ===== GCASH via PayMongo API =====
        $gcash_checkout_url = '';
        if ($payment_method === 'GCash') {
            $pm_result = createPaymongoGcashLink([
                'amount'        => (int)($total_price * 100),
                'description'   => $cottage['name'] . ' (' . $nights . ' night' . ($nights>1?'s':'') . ')',
                'booking_code'  => $booking_code,
                'customer_name' => $guest_name,
                'email'         => $guest_email,
                'phone'         => $guest_phone,
            ]);

            if ($pm_result['success']) {
                $gcash_checkout_url = $pm_result['checkout_url'];
                $link_id            = $pm_result['link_id'];

                $db->prepare("UPDATE reservations SET paymongo_link_id=?, paymongo_checkout_url=?, payment_status='Pending Verification' WHERE id=?")
                   ->execute([$link_id, $gcash_checkout_url, $res_id]);

                $db->prepare("INSERT INTO gcash_payments (reservation_id, reference_number, amount, sender_name, sender_number, status) VALUES (?,?,?,?,?,'Pending')")
                   ->execute([$res_id, $link_id, $total_price, $guest_name, $guest_phone]);
            } else {
                $gcash_checkout_url = '';
                $errors[] = 'GCash payment link could not be created: ' . $pm_result['error'] . '. Your booking was saved — please contact us to pay manually.';
            }
        }

        if (empty($errors)) {
            $booking_result = [
                'code'               => $booking_code,
                'name'               => $guest_name,
                'email'              => $guest_email,
                'cottage'            => $cottage['name'],
                'check_in'           => $check_in,
                'check_out'          => $check_out,
                'nights'             => $nights,
                'total'              => $total_price,
                'payment_method'     => $payment_method,
                'pay_status'         => $pay_status,
                'gcash_checkout_url' => $gcash_checkout_url,
                'gcash_ref'          => '',
            ];
            $success = true;
        }
    }
}

// Group cottages by category for display
$grouped_cottages = [];
foreach ($all_cottages as $c) {
    $grouped_cottages[$c['category']][] = $c;
}
$cat_icons = ['Bahay Kubo'=>'🌿','Open Cottage'=>'🎉','Kubo Premium'=>'✨'];

$check_in_val  = $check_in ?: ($_POST['check_in'] ?? '');
$check_out_val = $check_out ?: ($_POST['check_out'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Your Stay — S-Five Inland Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <style>
        .date-unavailable-note {
            font-size: 0.78rem; color: #A32D2D; margin-top: 6px;
            min-height: 1em;
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-light" id="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-logo"><img src="images/sfive_logo.jpg" alt="S-Five Inland Resort" class="nav-logo-img"></a>
        <ul class="nav-links">
            <li><a href="index.php#cottages">Cottages</a></li>
            <li><a href="index.php#about">About</a></li>
            <li><a href="check_booking.php">My Booking</a></li>
        </ul>
    </div>
</nav>

<div class="booking-page">
    <div class="booking-hero">
        <h1>Reserve Your <em>Cottage</em></h1>
        <p>Fill in your details below and we'll confirm your stay shortly.</p>
    </div>
    <div class="container">

    <!-- ====== SUCCESS ====== -->
    <?php if ($success && $booking_result): ?>
    <div class="success-card">
        <div class="success-icon"><?= $booking_result['payment_method']==='GCash' ? '💚' : '🎉' ?></div>
        <h2>Booking <?= $booking_result['payment_method']==='GCash' ? 'Submitted!' : 'Received!' ?></h2>
        <p>Thank you for choosing <strong>S-Five Inland Resort!</strong> 🌴</p>

        <?php if ($booking_result['payment_method'] === 'GCash'): ?>
        <div class="gcash-success-note">
            <div class="gcash-icon-big">💚</div>
            <?php if (!empty($booking_result['gcash_checkout_url'])): ?>
            <p><strong>Your GCash payment link is ready!</strong></p>
            <p>Click the button below to complete your payment via GCash. This page will automatically confirm once payment is received.</p>
            <a href="<?= htmlspecialchars($booking_result['gcash_checkout_url']) ?>"
               class="btn-gcash-pay" target="_blank" rel="noopener"
               onclick="startPaymentPolling()">
                💚 Pay via GCash Now
            </a>

            <!-- Live payment status indicator -->
            <div id="paymentStatusBox" style="margin-top:1.2rem;padding:0.85rem 1rem;border-radius:10px;background:#f0fdf6;border:1.5px solid #d1fae5;display:none;">
                <span id="paymentStatusIcon">⏳</span>
                <span id="paymentStatusText" style="font-size:0.9rem;color:#065f46;font-weight:600;margin-left:0.4rem;">Waiting for payment...</span>
            </div>

            <div id="paymentConfirmedBox" style="margin-top:1.2rem;padding:1rem;border-radius:10px;background:#d1fae5;border:2px solid #10b959;display:none;text-align:center;">
                <div style="font-size:2rem;">✅</div>
                <p style="font-weight:700;color:#065f46;margin:0.3rem 0;">Payment Confirmed!</p>
                <p style="font-size:0.85rem;color:#047857;">Your booking is now <strong>Confirmed</strong>. Thank you!</p>
            </div>

            <p style="margin-top:0.75rem;font-size:0.78rem;color:#888;">
                Booking code: <code><?= $booking_result['code'] ?></code>
            </p>
            <?php else: ?>
            <p><strong>Booking submitted!</strong> We'll send you the GCash payment link shortly.</p>
            <p>Your booking code: <code><?= $booking_result['code'] ?></code></p>
            <?php endif; ?>
        </div>

        <script>
        // Auto-poll PayMongo every 5 seconds after guest clicks Pay
        let pollInterval = null;
        let pollCount    = 0;
        const MAX_POLLS  = 72; // poll for up to 6 minutes

        function startPaymentPolling() {
            document.getElementById('paymentStatusBox').style.display = 'block';
            if (pollInterval) return; // already polling
            pollInterval = setInterval(checkPaymentStatus, 5000);
        }

        async function checkPaymentStatus() {
            pollCount++;
            if (pollCount > MAX_POLLS) {
                clearInterval(pollInterval);
                document.getElementById('paymentStatusText').textContent = 'Payment not detected yet. Check your booking code later.';
                document.getElementById('paymentStatusIcon').textContent = '⚠️';
                return;
            }

            try {
                const res  = await fetch('booking.php?action=poll_payment&code=<?= urlencode($booking_result['code']) ?>');
                const data = await res.json();

                if (data.paid) {
                    clearInterval(pollInterval);
                    document.getElementById('paymentStatusBox').style.display  = 'none';
                    document.getElementById('paymentConfirmedBox').style.display = 'block';
                } else {
                    document.getElementById('paymentStatusText').textContent =
                        'Waiting for payment... (check ' + pollCount + ')';
                }
            } catch(e) {
                // network hiccup — keep polling
            }
        }
        </script>
        <?php else: ?>
        <p>Your reservation is <span class="status-badge pending">Pending</span> — our team will confirm it shortly.</p>
        <?php endif; ?>

        <div class="booking-summary-box">
            <div class="summary-row"><span>Booking Code</span><strong><?= $booking_result['code'] ?></strong></div>
            <div class="summary-row"><span>Guest Name</span><strong><?= htmlspecialchars($booking_result['name']) ?></strong></div>
            <div class="summary-row"><span>Cottage</span><strong><?= htmlspecialchars($booking_result['cottage']) ?></strong></div>
            <div class="summary-row"><span>Check-in</span><strong><?= date('F d, Y', strtotime($booking_result['check_in'])) ?></strong></div>
            <div class="summary-row"><span>Check-out</span><strong><?= date('F d, Y', strtotime($booking_result['check_out'])) ?></strong></div>
            <div class="summary-row"><span>Duration</span><strong><?= $booking_result['nights'] ?> night(s)</strong></div>
            <div class="summary-row"><span>Payment</span><strong><?= $booking_result['payment_method'] ?></strong></div>
            <div class="summary-row total-row"><span>Total Amount</span><strong>₱<?= number_format($booking_result['total'], 2) ?></strong></div>
        </div>

        <p class="note-text">🧾 No email confirmation is sent — save or screenshot your <a href="receipt.php?code=<?= $booking_result['code'] ?>" target="_blank">receipt</a> as proof of this booking.</p>
        <?php if ($booking_result['payment_method'] === 'Pay at Resort'): ?>
        <p class="note-text">💰 Pay on arrival — Cash or GCash accepted</p>
        <?php endif; ?>

        <div class="success-actions">
            <a href="check_booking.php?code=<?= $booking_result['code'] ?>" class="btn-primary">Track Booking</a>
            <a href="receipt.php?code=<?= $booking_result['code'] ?>" class="btn-ghost" target="_blank">🧾 View Receipt</a>
            <a href="index.php" class="btn-ghost">Back to Home</a>
        </div>
    </div>

    <?php else: ?>

    <div class="booking-layout">
        <div class="booking-form-col">

            <?php if (!empty($errors)): ?>
            <div class="alert-error">
                <strong>Please fix the following:</strong>
                <ul><?php foreach($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
            </div>
            <?php endif; ?>

            <form action="booking.php" method="POST" enctype="multipart/form-data" class="booking-form" id="bookingForm">

                <!-- DATES -->
                <div class="form-section">
                    <h3 class="form-section-title">📅 Your Stay</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Check-in Date *</label>
                            <input type="date" name="check_in" id="check_in"
                                   value="<?= htmlspecialchars($check_in_val) ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out Date *</label>
                            <input type="date" name="check_out" id="check_out"
                                   value="<?= htmlspecialchars($check_out_val) ?>"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                    </div>
                    <p class="date-unavailable-note" id="unavailableDatesNote"></p>
                    <div class="form-group">
                        <label>Number of Guests *</label>
                        <select name="num_guests" id="num_guests">
                            <?php for($i=1;$i<=60;$i++): ?>
                            <option value="<?=$i?>" <?=$guests==$i?'selected':''?>><?=$i?> <?=$i==1?'Guest':'Guests'?></option>
                            <?php endfor; ?>
                        </select>
                        <!-- Guest capacity warning injected here by JS -->
                    </div>
                </div>

                <!-- COTTAGE SELECTION -->
                <div class="form-section">
                    <h3 class="form-section-title">🏡 Choose Cottage</h3>
                    <p id="availabilityStatus" style="font-size:0.8rem;color:#888;margin:-4px 0 10px;"></p>
                    <div class="cottage-options" id="cottageOptions">
                        <?php foreach ($grouped_cottages as $cat => $cats_cottages): ?>
                        <div class="co-category-label"><?= $cat_icons[$cat] ?? '🏠' ?> <?= $cat ?></div>
                        <?php foreach ($cats_cottages as $c):
                            $isSelected = ($c['id'] == $cottage_id);
                            $isBooked   = (bool)$c['is_booked'];
                        ?>
                        <label class="cottage-option <?= $isSelected?'selected':'' ?> <?= $isBooked?'booked-option':'' ?>"
                               data-price="<?= $c['price_per_night'] ?>"
                               data-name="<?= htmlspecialchars($c['name']) ?>"
                               data-capacity="<?= $c['capacity'] ?>">
                            <input type="radio" name="cottage_id" value="<?= $c['id'] ?>"
                                   <?= $isSelected?'checked':'' ?>
                                   <?= $isBooked?'disabled':'' ?>>
                            <div class="co-icon"><?= $cat_icons[$cat] ?? '🏠' ?></div>
                            <div class="co-info">
                                <strong><?= htmlspecialchars($c['name']) ?></strong>
                                <span>Up to <?= $c['capacity'] ?> guests</span>
                            </div>
                            <div class="co-right">
                                <div class="co-price">₱<?= number_format($c['price_per_night'],0) ?>/night</div>
                                <?php if ($isBooked): ?>
                                <span class="co-unavail-tag">Unavailable</span>
                                <?php else: ?>
                                <span class="co-avail-tag">Available</span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- GUEST INFO -->
                <div class="form-section">
                    <h3 class="form-section-title">👤 Your Details</h3>
                    <div class="form-group">
                        <label>Full Name *</label>
                        <input type="text" name="guest_name" placeholder="Juan dela Cruz"
                               value="<?= htmlspecialchars($_POST['guest_name'] ?? '') ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Email Address *</label>
                            <input type="email" name="guest_email" placeholder="juan@email.com"
                                   value="<?= htmlspecialchars($_POST['guest_email'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Phone Number *</label>
                            <input type="tel" name="guest_phone" placeholder="+63 912 345 6789"
                                   value="<?= htmlspecialchars($_POST['guest_phone'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Special Requests <span class="optional">(optional)</span></label>
                        <textarea name="special_requests" rows="3" placeholder="Extra beddings, early check-in, event setup, etc."><?= htmlspecialchars($_POST['special_requests'] ?? '') ?></textarea>
                    </div>
                </div>

                <!-- PAYMENT METHOD -->
                <div class="form-section">
                    <h3 class="form-section-title">💳 Payment Method</h3>
                    <div class="payment-options">
                        <label class="payment-option <?= ($_POST['payment_method'] ?? 'Pay at Resort')==='Pay at Resort'?'selected':'' ?>" id="opt-cash">
                            <input type="radio" name="payment_method" value="Pay at Resort"
                                   <?= ($_POST['payment_method'] ?? 'Pay at Resort')==='Pay at Resort'?'checked':'' ?>>
                            <div class="pay-icon">💵</div>
                            <div class="pay-info">
                                <strong>Pay at Resort</strong>
                                <span>Cash / GCash on arrival. No upfront payment needed.</span>
                            </div>
                        </label>
                        <label class="payment-option <?= ($_POST['payment_method'] ?? '')==='GCash'?'selected':'' ?>" id="opt-gcash">
                            <input type="radio" name="payment_method" value="GCash"
                                   <?= ($_POST['payment_method'] ?? '')==='GCash'?'checked':'' ?>>
                            <div class="pay-icon">💚</div>
                            <div class="pay-info">
                                <strong>Pay via GCash</strong>
                                <span>Send payment now to secure your booking instantly.</span>
                            </div>
                        </label>
                    </div>

                    <!-- GCASH PAYMENT FORM -->
                    <div class="gcash-form" id="gcashForm" style="display:none;">
                        <div class="gcash-instructions">
                            <div class="gcash-logo">💚 GCash</div>
                            <div class="gcash-steps">
                                <p><strong>How it works:</strong></p>
                                <ol>
                                    <li>Click <strong>"Confirm Reservation"</strong> below</li>
                                    <li>You'll get a secure <strong>GCash payment link</strong></li>
                                    <li>Click the link to pay instantly via GCash</li>
                                    <li>Your booking is <strong>auto-confirmed</strong> upon payment</li>
                                </ol>
                            </div>
                        </div>
                        <div class="gcash-note">
                            🔒 Powered by <strong>PayMongo</strong> — secure, instant GCash payments. No manual transfer needed.
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">Confirm Reservation 🌴</button>
                <p class="form-note" id="formNote">No payment required online. Settle upon arrival.</p>
            </form>
        </div>

        <!-- SIDEBAR -->
        <div class="booking-sidebar">
            <div class="sidebar-card sticky">
                <h3>Booking Summary</h3>
                <div class="summary-item">
                    <span>Cottage</span>
                    <strong id="summary-cottage-name"><?= $selected_cottage?htmlspecialchars($selected_cottage['name']):'—' ?></strong>
                </div>
                <div class="summary-item"><span>Check-in</span><strong id="summary-checkin"><?= $check_in?date('M d, Y',strtotime($check_in)):'—' ?></strong></div>
                <div class="summary-item"><span>Check-out</span><strong id="summary-checkout"><?= $check_out?date('M d, Y',strtotime($check_out)):'—' ?></strong></div>
                <div class="summary-item"><span>Nights</span><strong id="summary-nights"><?= $nights?:'—' ?></strong></div>
                <div class="summary-item"><span>Guests</span><strong id="summary-guests"><?= $guests ?></strong></div>
                <div class="summary-divider"></div>
                <div class="summary-item summary-total">
                    <span>Total</span>
                    <strong id="summary-total"><?= $total_price?'₱'.number_format($total_price,2):'—' ?></strong>
                </div>

                <!-- GCash QR Placeholder -->
                <div class="sidebar-gcash" id="sidebarGcash" style="display:none;">
                    <div class="gcash-qr-box">
                        <div class="qr-placeholder">
                            <span>💚</span>
                            <p>Scan to Pay via GCash</p>
                            <strong>Secure GCash Link</strong>
                            <small>Powered by PayMongo</small>
                        </div>
                    </div>
                </div>

                <div class="sidebar-note">
                    <p id="sidebar-pay-note">💰 Pay on arrival<br>Cash or GCash accepted</p>
                    <p>✅ Free cancellation 24hrs before check-in</p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>
</div>

<footer class="footer">
    <div class="footer-bottom"><p>&copy; <?= date('Y') ?> S-Five Inland Resort.</p></div>
</footer>

<script>
// Cottage data map — includes capacity
const cottageData = {
    <?php foreach($all_cottages as $c): ?>
    <?= $c['id'] ?>: {
        name: "<?= addslashes($c['name']) ?>",
        price: <?= $c['price_per_night'] ?>,
        capacity: <?= $c['capacity'] ?>,
        booked: <?= (int)$c['is_booked'] > 0 ? 'true' : 'false' ?>
    },
    <?php endforeach; ?>
};

let selectedCottageId = <?= $cottage_id ?: 0 ?>;
let selectedPrice     = <?= $selected_cottage ? $selected_cottage['price_per_night'] : 0 ?>;
let selectedCapacity  = <?= $selected_cottage ? $selected_cottage['capacity'] : 0 ?>;

// ── Guest capacity validation ──────────────────────────────
function validateGuests() {
    const guestSelect = document.getElementById('num_guests');
    const submitBtn   = document.getElementById('submitBtn');
    const guestCount  = parseInt(guestSelect.value);
    let warning       = document.getElementById('guest-capacity-warning');

    if (selectedCapacity > 0 && guestCount > selectedCapacity) {
        guestSelect.style.borderColor  = '#E24B4A';
        guestSelect.style.outline      = '2px solid #FCEBEB';
        submitBtn.disabled = true;
        if (!warning) {
            warning = document.createElement('p');
            warning.id = 'guest-capacity-warning';
            warning.style.cssText = 'color:#A32D2D;background:#FCEBEB;border:1px solid #F09595;border-radius:6px;padding:8px 12px;font-size:0.82rem;margin-top:8px;';
            guestSelect.insertAdjacentElement('afterend', warning);
        }
        warning.textContent = '⚠️ This cottage fits a maximum of ' + selectedCapacity + ' guest' + (selectedCapacity > 1 ? 's' : '') + '. Please lower the number or choose a larger cottage.';
    } else {
        guestSelect.style.borderColor = '';
        guestSelect.style.outline     = '';
        submitBtn.disabled = false;
        if (warning) warning.remove();
    }
}

// ── Cottage radio click ────────────────────────────────────
document.querySelectorAll('.cottage-options').forEach(container => {
    container.addEventListener('click', function(e) {
        const label = e.target.closest('.cottage-option');
        if (!label || label.classList.contains('booked-option')) return;
        const radio = label.querySelector('input[type=radio]');
        if (!radio || radio.disabled) return;

        document.querySelectorAll('.cottage-option').forEach(l => l.classList.remove('selected'));
        label.classList.add('selected');
        selectedCottageId = parseInt(radio.value);
        selectedPrice      = parseFloat(label.dataset.price);
        selectedCapacity   = parseInt(label.dataset.capacity);
        selectedCottageName = label.dataset.name;
        document.getElementById('summary-cottage-name').textContent = label.dataset.name;
        updateTotal();
        validateGuests();
        // Now that a specific cottage is chosen, the indicator should show
        // THAT cottage's booked dates rather than resort-wide fully-booked dates.
        loadBookedDates(selectedCottageId);
    });
});

// ── Total price calculation ────────────────────────────────
function updateTotal() {
    const ci = document.getElementById('check_in').value;
    const co = document.getElementById('check_out').value;
    document.getElementById('summary-guests').textContent = document.getElementById('num_guests').value;
    if (ci && co) {
        const nights = Math.round((new Date(co) - new Date(ci)) / 86400000);
        if (nights > 0) {
            document.getElementById('summary-checkin').textContent  = new Date(ci).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
            document.getElementById('summary-checkout').textContent = new Date(co).toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
            document.getElementById('summary-nights').textContent   = nights;
            if (selectedPrice > 0) {
                const total = nights * selectedPrice;
                document.getElementById('summary-total').textContent = '₱' + total.toLocaleString('en-PH',{minimumFractionDigits:2});
            } else {
                document.getElementById('summary-total').textContent = '—';
            }
        }
    }
}

// ── THE FIX: live availability check ───────────────────────
// Re-queries the server for the dates currently in the form and
// enables/disables each cottage option to match — instead of trusting
// whatever was rendered when the page first loaded.
let availabilityRequestSeq = 0;
function refreshCottageAvailability() {
    const ci = document.getElementById('check_in').value;
    const co = document.getElementById('check_out').value;
    const statusEl = document.getElementById('availabilityStatus');

    if (!ci || !co || co <= ci) {
        if (statusEl) statusEl.textContent = '';
        return;
    }

    const thisRequest = ++availabilityRequestSeq;
    if (statusEl) statusEl.textContent = 'Checking availability for your dates…';

    fetch(`booking.php?action=check_availability&check_in=${encodeURIComponent(ci)}&check_out=${encodeURIComponent(co)}`)
        .then(res => res.json())
        .then(data => {
            // Ignore stale responses if the user changed dates again before this returned
            if (thisRequest !== availabilityRequestSeq) return;
            if (!data.success) {
                if (statusEl) statusEl.textContent = '';
                return;
            }

            let anyDeselected = false;

            document.querySelectorAll('.cottage-option').forEach(label => {
                const radio = label.querySelector('input[type=radio]');
                if (!radio) return;
                const status = data.availability[radio.value];
                const tag = label.querySelector('.co-avail-tag, .co-unavail-tag');

                if (status === 'booked') {
                    radio.disabled = true;
                    label.classList.add('booked-option');
                    if (tag) { tag.textContent = 'Unavailable'; tag.className = 'co-unavail-tag'; }

                    if (radio.checked) {
                        radio.checked = false;
                        label.classList.remove('selected');
                        anyDeselected = true;
                    }
                } else if (status === 'available') {
                    radio.disabled = false;
                    label.classList.remove('booked-option');
                    if (tag) { tag.textContent = 'Available'; tag.className = 'co-avail-tag'; }
                }
            });

            if (anyDeselected) {
                selectedCottageId = 0;
                selectedPrice = 0;
                selectedCapacity = 0;
                selectedCottageName = '';
                document.getElementById('summary-cottage-name').textContent = '—';
                document.getElementById('summary-total').textContent = '—';
                if (statusEl) statusEl.textContent = '⚠️ Your previously selected cottage is booked for these dates — please choose another.';
                loadBookedDates(0); // back to resort-wide indicator, no cottage chosen anymore
            } else if (statusEl) {
                statusEl.textContent = '✓ Availability updated for your selected dates.';
            }

            validateGuests();
        })
        .catch(err => {
            console.error('Availability check failed:', err);
            if (statusEl) statusEl.textContent = '';
        });
}

// ── Unavailable-dates indicator ─────────────────────────────
// Keeps the plain native date pickers, but shows a short text line below
// them listing which dates are already booked — for the chosen cottage if
// one's selected, or resort-wide "fully booked" dates (every cottage taken)
// before a cottage is picked.
let selectedCottageName = '';

function parseISO(s) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(Date.UTC(y, m - 1, d));
}
function toISO(date) { return date.toISOString().slice(0, 10); }
function addDaysISO(s, n) {
    const d = parseISO(s);
    d.setUTCDate(d.getUTCDate() + n);
    return toISO(d);
}

function formatRangeLabel(startISO, endISOExclusive) {
    const start = parseISO(startISO);
    const lastDay = parseISO(endISOExclusive);
    lastDay.setUTCDate(lastDay.getUTCDate() - 1);
    const opts = { month: 'short', day: 'numeric', timeZone: 'UTC' };
    const startLabel = start.toLocaleDateString('en-US', opts);

    if (toISO(start) === toISO(lastDay)) return startLabel;

    const sameMonth = start.getUTCMonth() === lastDay.getUTCMonth() && start.getUTCFullYear() === lastDay.getUTCFullYear();
    return sameMonth
        ? `${startLabel}–${lastDay.getUTCDate()}`
        : `${startLabel} – ${lastDay.toLocaleDateString('en-US', opts)}`;
}

function collapseDatesToRanges(sortedISODates) {
    const ranges = [];
    let rangeStart = null, prev = null;
    sortedISODates.forEach(iso => {
        if (rangeStart === null) {
            rangeStart = iso;
        } else if (addDaysISO(prev, 1) !== iso) {
            ranges.push([rangeStart, addDaysISO(prev, 1)]);
            rangeStart = iso;
        }
        prev = iso;
    });
    if (rangeStart !== null) ranges.push([rangeStart, addDaysISO(prev, 1)]);
    return ranges;
}

function renderUnavailableNote(data) {
    const note = document.getElementById('unavailableDatesNote');
    if (!note) return;
    if (!data || !data.success) { note.textContent = ''; return; }

    let ranges, prefix;
    if (data.mode === 'cottage') {
        ranges = (data.ranges || []).map(r => [r.check_in, r.check_out]);
        prefix = selectedCottageName ? `Unavailable for ${selectedCottageName}: ` : 'Unavailable: ';
    } else {
        ranges = collapseDatesToRanges((data.fully_booked_dates || []).slice().sort());
        prefix = 'Fully booked resort-wide (no cottages free): ';
    }

    if (ranges.length === 0) { note.textContent = ''; return; }
    note.textContent = '🔴 ' + prefix + ranges.map(([s, e]) => formatRangeLabel(s, e)).join(', ');
}

function loadBookedDates(cottageId) {
    const url = cottageId
        ? `booking.php?action=get_booked_dates&cottage_id=${cottageId}`
        : `booking.php?action=get_booked_dates`;
    return fetch(url)
        .then(res => res.json())
        .then(data => renderUnavailableNote(data))
        .catch(err => console.error('Could not load booked dates:', err));
}

// ── Date change listeners ──────────────────────────────────
document.getElementById('check_in').addEventListener('change', function() {
    const d = new Date(this.value); d.setDate(d.getDate() + 1);
    document.getElementById('check_out').min = d.toISOString().split('T')[0];
    updateTotal();
    refreshCottageAvailability();
});
document.getElementById('check_out').addEventListener('change', function() {
    updateTotal();
    refreshCottageAvailability();
});

// ── Guest count change listener ────────────────────────────
document.getElementById('num_guests').addEventListener('change', function() {
    updateTotal();
    validateGuests();
});

// ── Payment method toggle ──────────────────────────────────
document.querySelectorAll('input[name=payment_method]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
        this.closest('.payment-option').classList.add('selected');

        const isGcash = this.value === 'GCash';
        document.getElementById('gcashForm').style.display    = isGcash ? 'block' : 'none';
        document.getElementById('sidebarGcash').style.display = isGcash ? 'block' : 'none';
        document.getElementById('submitBtn').textContent      = isGcash ? 'Submit Booking + Payment 💚' : 'Confirm Reservation 🌴';
        document.getElementById('formNote').textContent       = isGcash ? 'Your booking will be confirmed after payment is verified.' : 'No payment required online. Settle upon arrival.';
        document.getElementById('sidebar-pay-note').innerHTML = isGcash ? '💚 Paying via GCash<br>Secure link sent after booking' : '💰 Pay on arrival<br>Cash or GCash accepted';
    });
});

// ── Init: restore GCash state after form error re-render ───
if (document.querySelector('input[name=payment_method][value=GCash]:checked')) {
    document.getElementById('gcashForm').style.display    = 'block';
    document.getElementById('sidebarGcash').style.display = 'block';
}

// ── Init: run guest validation + a fresh availability check on load ──
// (covers the case where the page loaded with dates already in the URL —
// we still re-verify live rather than trusting the initial render)
validateGuests();
refreshCottageAvailability();
loadBookedDates(selectedCottageId);
</script>
<script src="js/main.js"></script>
</body>
</html>