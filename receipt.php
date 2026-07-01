<?php
// receipt.php — Screenshot-friendly booking receipt (save as PNG or JPEG)
// Since no email or SMS confirmation is actually sent to guests, this page
// gives them something concrete to download and keep as proof of booking.
require_once 'includes/config.php';
$db = getDB();

$code = clean($_GET['code'] ?? '');
$reservation = null;
$error = '';

if ($code) {
    $stmt = $db->prepare("
        SELECT r.*, c.name AS cottage_name, c.category AS cottage_category
        FROM reservations r
        JOIN cottages c ON r.cottage_id = c.id
        WHERE r.booking_code = ?
    ");
    $stmt->execute([$code]);
    $reservation = $stmt->fetch();
    if (!$reservation) $error = "No reservation found with code: " . htmlspecialchars($code);
} else {
    $error = "No booking code provided.";
}

// Pull latest GCash payment info, if any (for reference number on the receipt)
$gcash = null;
if ($reservation) {
    $gstmt = $db->prepare("SELECT * FROM gcash_payments WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
    $gstmt->execute([$reservation['id']]);
    $gcash = $gstmt->fetch();
}

$nights = $reservation ? (strtotime($reservation['check_out']) - strtotime($reservation['check_in'])) / 86400 : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Receipt <?= $reservation ? htmlspecialchars($reservation['booking_code']) : '' ?> — S-Five Inland Resort</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
    :root {
        --green-deep: #1a3a2e;
        --green-mid:  #2d6a4f;
        --gold:       #c9a84c;
        --cream:      #fdf6ec;
        --text-dark:  #1c1c1c;
        --text-mid:   #4a4a4a;
        --text-light: #888;
        --border:     #e5e8e5;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Jost', sans-serif;
        background: var(--cream);
        color: var(--text-dark);
        padding: 2.5rem 1rem;
    }
    .receipt-toolbar {
        max-width: 640px;
        margin: 0 auto 1.25rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.75rem;
        flex-wrap: wrap;
    }
    .receipt-toolbar a {
        font-size: 0.85rem;
        color: var(--green-mid);
        text-decoration: none;
        font-weight: 600;
    }
    .btn-save-img {
        background: var(--green-deep);
        color: #fff;
        border: none;
        padding: 0.65rem 1.2rem;
        border-radius: 8px;
        font-family: 'Jost', sans-serif;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
    }
    .btn-save-img:hover { background: var(--green-mid); }
    .toolbar-actions { display: flex; gap: 0.6rem; flex-wrap: wrap; }

    .receipt {
        max-width: 640px;
        margin: 0 auto;
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 8px 32px rgba(26,58,46,0.12);
        overflow: hidden;
    }
    .receipt-head {
        background: var(--green-deep);
        color: #fff;
        padding: 2rem 2rem 1.5rem;
        text-align: center;
    }
    .receipt-head .logo {
        margin: 0 auto 0.6rem;
        display: flex;
        justify-content: center;
    }
    .receipt-head .logo img {
        width: 70px;
        height: 70px;
        border-radius: 50%;
        object-fit: cover;
        display: block;
        background: #fff;
        border: 2px solid rgba(255,255,255,0.55);
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    .receipt-head h1 {
        font-family: 'Playfair Display', serif;
        font-size: 1.5rem;
        font-weight: 700;
        letter-spacing: 0.02em;
    }
    .receipt-head p { font-size: 0.8rem; opacity: 0.75; margin-top: 0.3rem; }

    .receipt-status {
        text-align: center;
        padding: 1.25rem 2rem 0;
    }
    .status-pill {
        display: inline-block;
        padding: 0.35rem 1rem;
        border-radius: 30px;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }
    .status-pill.confirmed { background: #d1e7dd; color: #0a5c36; }
    .status-pill.pending   { background: #fff3cd; color: #856404; }
    .status-pill.cancelled { background: #f8d7da; color: #842029; }

    .receipt-code {
        text-align: center;
        padding: 0.75rem 2rem 0;
    }
    .receipt-code code {
        font-size: 1.1rem;
        font-weight: 700;
        color: var(--green-deep);
        letter-spacing: 0.05em;
    }

    .receipt-body { padding: 1.5rem 2rem 2rem; }
    .receipt-section { margin-bottom: 1.5rem; }
    .receipt-section:last-child { margin-bottom: 0; }
    .receipt-section h4 {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: var(--text-light);
        margin-bottom: 0.6rem;
        border-bottom: 1px solid var(--border);
        padding-bottom: 0.4rem;
    }
    .receipt-row {
        display: flex;
        justify-content: space-between;
        padding: 0.3rem 0;
        font-size: 0.9rem;
    }
    .receipt-row span { color: var(--text-mid); }
    .receipt-row strong { color: var(--text-dark); text-align: right; }
    .receipt-row.total {
        margin-top: 0.5rem;
        padding-top: 0.75rem;
        border-top: 1.5px dashed var(--border);
        font-size: 1.05rem;
    }
    .receipt-row.total strong { color: var(--green-deep); font-family: 'Playfair Display', serif; }

    .receipt-footnote {
        text-align: center;
        font-size: 0.75rem;
        color: var(--text-light);
        padding: 1.25rem 2rem 1.75rem;
        border-top: 1px solid var(--border);
    }

    .error-box {
        max-width: 480px;
        margin: 3rem auto;
        background: #fff;
        border-radius: 12px;
        padding: 2rem;
        text-align: center;
        box-shadow: 0 8px 32px rgba(26,58,46,0.10);
    }
    .error-box a { color: var(--green-mid); font-weight: 600; }
</style>
</head>
<body>

<?php if ($reservation): ?>
    <?php
    $statusClass = strtolower($reservation['status']);
    ?>
    <div class="receipt-toolbar">
        <a href="check_booking.php?code=<?= htmlspecialchars($reservation['booking_code']) ?>">← Back to Booking</a>
        <div class="toolbar-actions">
            <button class="btn-save-img png" onclick="saveAsImage()">⬇️ Save as PNG</button>
        </div>
    </div>

    <div class="receipt" id="receiptCard">
        <div class="receipt-head">
            <div class="logo"><img src="images/sfive_logo.jpg" alt="S-Five Inland Resort" class="nav-logo-img"></div>
            <h1>S-Five Inland Resort</h1>
            <p>San Miguel, Iloilo, Philippines</p>
        </div>

        <div class="receipt-status">
            <span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars($reservation['status']) ?></span>
        </div>

        <div class="receipt-code">
            <code><?= htmlspecialchars($reservation['booking_code']) ?></code>
        </div>

        <div class="receipt-body">
            <div class="receipt-section">
                <h4>Guest Details</h4>
                <div class="receipt-row"><span>Name</span><strong><?= htmlspecialchars($reservation['guest_name']) ?></strong></div>
                <div class="receipt-row"><span>Email</span><strong><?= htmlspecialchars($reservation['guest_email']) ?></strong></div>
                <div class="receipt-row"><span>Phone</span><strong><?= htmlspecialchars($reservation['guest_phone']) ?></strong></div>
            </div>

            <div class="receipt-section">
                <h4>Stay Details</h4>
                <div class="receipt-row"><span>Cottage</span><strong><?= htmlspecialchars($reservation['cottage_name']) ?></strong></div>
                <div class="receipt-row"><span>Check-in</span><strong><?= date('F d, Y', strtotime($reservation['check_in'])) ?></strong></div>
                <div class="receipt-row"><span>Check-out</span><strong><?= date('F d, Y', strtotime($reservation['check_out'])) ?></strong></div>
                <div class="receipt-row"><span>Duration</span><strong><?= (int)$nights ?> night(s)</strong></div>
                <div class="receipt-row"><span>Guests</span><strong><?= (int)$reservation['num_guests'] ?></strong></div>
                <?php if (!empty($reservation['special_requests'])): ?>
                <div class="receipt-row"><span>Special Requests</span><strong><?= htmlspecialchars($reservation['special_requests']) ?></strong></div>
                <?php endif; ?>
            </div>

            <div class="receipt-section">
                <h4>Payment</h4>
                <div class="receipt-row"><span>Method</span><strong><?= htmlspecialchars($reservation['payment_method']) ?></strong></div>
                <div class="receipt-row"><span>Payment Status</span><strong><?= htmlspecialchars($reservation['payment_status']) ?></strong></div>
                <?php if ($gcash): ?>
                <div class="receipt-row"><span>GCash Reference #</span><strong><?= htmlspecialchars($gcash['reference_number']) ?></strong></div>
                <?php endif; ?>
                <div class="receipt-row total"><span>Total Amount</span><strong>₱<?= number_format($reservation['total_price'], 2) ?></strong></div>
            </div>

            <div class="receipt-section">
                <h4>Booking Info</h4>
                <div class="receipt-row"><span>Booked On</span><strong><?= date('F d, Y g:i A', strtotime($reservation['created_at'])) ?></strong></div>
            </div>
        </div>

        <div class="receipt-footnote">
            This receipt was generated by you and serves as your proof of booking.<br>
            For questions, present this booking code at the front desk: <strong><?= htmlspecialchars($reservation['booking_code']) ?></strong>
        </div>
    </div>

<?php else: ?>
    <div class="error-box">
        <p style="margin-bottom:1rem;"><?= $error ?></p>
        <a href="check_booking.php">← Look up your booking</a>
    </div>
<?php endif; ?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function saveAsImage() {
    const card = document.getElementById('receiptCard');
    if (!card) return;

    html2canvas(card, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
        const dataUrl = canvas.toDataURL('image/png');

        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = 'sfive-receipt-<?= $reservation ? htmlspecialchars($reservation["booking_code"]) : "receipt" ?>.png';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }).catch(function () {
        alert('Could not generate the image. Please try again.');
    });
}
</script>
</body>
</html>