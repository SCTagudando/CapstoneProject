<?php
// booking.php — Reservation + GCash Payment (PayMongo API)
require_once 'includes/config.php';
require_once 'includes/paymongo.php';
$db = getDB();

$errors       = [];
$success      = false;
$booking_result = null;

// URL params
$check_in   = isset($_GET['check_in'])   ? clean($_GET['check_in'])   : '';
$check_out  = isset($_GET['check_out'])  ? clean($_GET['check_out'])  : '';
$guests     = isset($_GET['guests'])     ? (int)$_GET['guests']       : 2;
$cottage_id = isset($_GET['cottage_id']) ? (int)$_GET['cottage_id']   : 0;

// All cottages with booking status
function getCottagesWithStatus($db, $check_in, $check_out) {
    if ($check_in && $check_out) {
        // Check against selected date range
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
        // No dates selected — still mark cottages occupied TODAY as booked
        $stmt = $db->query("
            SELECT c.*,
                (SELECT COUNT(*) FROM reservations r
                 WHERE r.cottage_id = c.id
                 AND r.status IN ('Confirmed','Pending')
                 AND r.check_in <= CURDATE()
                 AND r.check_out > CURDATE()) AS is_booked
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

    // GCash uses PayMongo API — no manual fields needed

    // Validation
    if (!$guest_name)  $errors[] = "Full name is required.";
    if (!filter_var($guest_email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (!$guest_phone) $errors[] = "Phone number is required.";
    if (!$cottage_id)  $errors[] = "Please select a cottage.";
    if (!$check_in || !$check_out) $errors[] = "Check-in and check-out dates are required.";
    if ($check_in && $check_out && $check_in >= $check_out) $errors[] = "Check-out must be after check-in.";
    if ($check_in && $check_in < date('Y-m-d')) $errors[] = "Check-in cannot be in the past.";

    // Double booking check
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
                'amount'        => (int)($total_price * 100), // convert to centavos
                'description'   => $cottage['name'] . ' (' . $nights . ' night' . ($nights>1?'s':'') . ')',
                'booking_code'  => $booking_code,
                'customer_name' => $guest_name,
                'email'         => $guest_email,
                'phone'         => $guest_phone,
            ]);

            if ($pm_result['success']) {
                $gcash_checkout_url = $pm_result['checkout_url'];
                $link_id            = $pm_result['link_id'];

                // Save PayMongo link to reservation
                $db->prepare("UPDATE reservations SET paymongo_link_id=?, paymongo_checkout_url=?, payment_status='Pending Verification' WHERE id=?")
                   ->execute([$link_id, $gcash_checkout_url, $res_id]);

                // Log to gcash_payments
                $db->prepare("INSERT INTO gcash_payments (reservation_id, reference_number, amount, sender_name, sender_number, status) VALUES (?,?,?,?,?,'Pending')")
                   ->execute([$res_id, $link_id, $total_price, $guest_name, $guest_phone]);
            } else {
                // PayMongo failed — fall back to manual verification
                $gcash_checkout_url = '';
                $errors[] = 'GCash payment link could not be created: ' . $pm_result['error'] . '. Your booking was saved — please contact us to pay manually.';
            }
        }

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
            'gcash_checkout_url' => $gcash_checkout_url ?? '',
        ];
        $success = true;
    }
}

// Group cottages by category for display
$grouped_cottages = [];
foreach ($all_cottages as $c) {
    $grouped_cottages[$c['category']][] = $c;
}
$cat_icons = ['Bahay Kubo'=>'🏠','Open Cottage'=>'🎉','Kubo Premium'=>'✨'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Your Stay — S-Five Inland Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-light" id="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-logo"><span class="logo-icon">🌴</span><span class="logo-text">S-Five Resort</span></a>
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
        <div class="success-icon"><?= $booking_result['payment_method']==='GCash' ? '💵' : '🎉' ?></div>
        <h2>Booking <?= $booking_result['payment_method']==='GCash' ? 'Submitted!' : 'Received!' ?></h2>
        <p>Thank you for choosing <strong>S-Five Inland Resort!</strong> 🌴</p>

        <?php if ($booking_result['payment_method'] === 'GCash'): ?>
        <div class="gcash-success-note">
            <div class="gcash-icon-big">💵</div>
            <?php if (!empty($booking_result['gcash_checkout_url'])): ?>
            <p><strong>Your GCash payment link is ready!</strong></p>
            <p>Click the button below to complete your payment via GCash. Your booking will be automatically confirmed once payment is received.</p>
            <a href="<?= htmlspecialchars($booking_result['gcash_checkout_url']) ?>"
               class="btn-gcash-pay" target="_blank" rel="noopener">
                💵 Pay via GCash Now
            </a>
            <p style="margin-top:0.75rem;font-size:0.78rem;color:#888;">
                You'll be redirected to a secure PayMongo payment page.<br>
                Booking code: <code><?= $booking_result['code'] ?></code>
            </p>
            <?php else: ?>
            <p><strong>Booking submitted!</strong> Please pay manually:</p>
            <p>Send <strong>₱<?= number_format($booking_result['total'], 2) ?></strong> to GCash <strong>0912 345 6789</strong></p>
            <p>Then message us your booking code: <code><?= $booking_result['code'] ?></code></p>
            <?php endif; ?>
        </div>
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

        <p class="note-text">📧 Confirmation will be sent to <strong><?= htmlspecialchars($booking_result['email']) ?></strong></p>
        <?php if ($booking_result['payment_method'] === 'Pay at Resort'): ?>
        <p class="note-text">💰 Pay on arrival — Cash or GCash accepted</p>
        <?php endif; ?>

        <div class="success-actions">
            <a href="check_booking.php?code=<?= $booking_result['code'] ?>" class="btn-primary">Track Booking</a>
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
                                   value="<?= htmlspecialchars($check_in ?: ($_POST['check_in'] ?? '')) ?>"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Check-out Date *</label>
                            <input type="date" name="check_out" id="check_out"
                                   value="<?= htmlspecialchars($check_out ?: ($_POST['check_out'] ?? '')) ?>"
                                   min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Number of Guests *</label>
                        <select name="num_guests" id="num_guests">
                            <?php for($i=1;$i<=60;$i++): ?>
                            <option value="<?=$i?>" <?=$guests==$i?'selected':''?>><?=$i?> <?=$i==1?'Guest':'Guests'?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>

                <!-- COTTAGE SELECTION with availability labels -->
                <div class="form-section">
                    <h3 class="form-section-title">🏡 Choose Cottage</h3>
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
                            <div class="pay-icon">💵</div>
                            <div class="pay-info">
                                <strong>Pay via GCash</strong>
                                <span>Send payment now to secure your booking instantly.</span>
                            </div>
                        </label>
                    </div>

                    <!-- GCASH PAYMENT FORM -->
                    <div class="gcash-form" id="gcashForm" style="display:none;">
                        <div class="gcash-instructions">
                            <div class="gcash-logo">💵 GCash</div>
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
                            <span>💵</span>
                            <p>Scan to Pay via GCash</p>
                            <strong>0912 345 6789</strong>
                            <small>S-Five Inland Resort</small>
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
// Cottage data map
const cottageData = {
    <?php foreach($all_cottages as $c): ?>
    <?= $c['id'] ?>: { name: "<?= addslashes($c['name']) ?>", price: <?= $c['price_per_night'] ?>, booked: <?= (int)$c['is_booked'] > 0 ? 'true' : 'false' ?> },
    <?php endforeach; ?>
};

let selectedCottageId = <?= $cottage_id ?: 0 ?>;
let selectedPrice     = <?= $selected_cottage ? $selected_cottage['price_per_night'] : 0 ?>;

// Cottage radio click
document.querySelectorAll('.cottage-option:not(.booked-option)').forEach(label => {
    label.addEventListener('click', function() {
        if (this.classList.contains('booked-option')) return;
        document.querySelectorAll('.cottage-option').forEach(l => l.classList.remove('selected'));
        this.classList.add('selected');
        const radio = this.querySelector('input[type=radio]');
        if (!radio || radio.disabled) return;
        selectedCottageId = parseInt(radio.value);
        selectedPrice     = parseFloat(this.dataset.price);
        document.getElementById('summary-cottage-name').textContent = this.dataset.name;
        updateTotal();
    });
});

// Update total
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
            }
        }
    }
}

document.getElementById('check_in').addEventListener('change', function() {
    const d = new Date(this.value); d.setDate(d.getDate()+1);
    document.getElementById('check_out').min = d.toISOString().split('T')[0];
    updateTotal();
});
document.getElementById('check_out').addEventListener('change', updateTotal);
document.getElementById('num_guests').addEventListener('change', updateTotal);

// Payment method toggle
document.querySelectorAll('input[name=payment_method]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.payment-option').forEach(o => o.classList.remove('selected'));
        this.closest('.payment-option').classList.add('selected');

        const isGcash = this.value === 'GCash';
        document.getElementById('gcashForm').style.display   = isGcash ? 'block' : 'none';
        document.getElementById('sidebarGcash').style.display = isGcash ? 'block' : 'none';
        document.getElementById('submitBtn').textContent      = isGcash ? 'Submit Booking + Payment 💵' : 'Confirm Reservation 🌴';
        document.getElementById('formNote').textContent       = isGcash ? 'Your booking will be confirmed after payment is verified.' : 'No payment required online. Settle upon arrival.';
        document.getElementById('sidebar-pay-note').innerHTML = isGcash ? '💵 Paying via GCash<br>Send to: 0912 345 6789' : '💰 Pay on arrival<br>Cash or GCash accepted';
    });
});

// Init if GCash was pre-selected (after form error)
if (document.querySelector('input[name=payment_method][value=GCash]:checked')) {
    document.getElementById('gcashForm').style.display    = 'block';
    document.getElementById('sidebarGcash').style.display = 'block';
}
</script>
<script src="js/main.js"></script>
</body>
</html>