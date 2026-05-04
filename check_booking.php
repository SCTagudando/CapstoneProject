<?php
// check_booking.php - Guest can look up their reservation
require_once 'includes/config.php';

$db = getDB();
$reservation = null;
$cottage = null;
$error = '';

$code = clean($_GET['code'] ?? $_POST['code'] ?? '');

if ($code) {
    $stmt = $db->prepare("
        SELECT r.*, c.name AS cottage_name, c.price_per_night, c.description AS cottage_desc
        FROM reservations r
        JOIN cottages c ON r.cottage_id = c.id
        WHERE r.booking_code = ?
    ");
    $stmt->execute([$code]);
    $reservation = $stmt->fetch();
    if (!$reservation) $error = "No reservation found with code: <strong>" . htmlspecialchars($code) . "</strong>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Booking — S-Five Inland Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<nav class="navbar navbar-light" id="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-logo"><span class="logo-icon">🌴</span><span class="logo-text">S-Five Resort</span></a>
        <ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="booking.php" class="btn-nav">Book Now</a></li>
        </ul>
    </div>
</nav>

<div class="booking-page">
    <div class="booking-hero">
        <h1>Track Your <em>Reservation</em></h1>
        <p>Enter your booking code to check your reservation status.</p>
    </div>

    <div class="container" style="max-width:680px;">

        <!-- SEARCH FORM -->
        <form method="GET" class="lookup-form">
            <div class="lookup-row">
                <input type="text" name="code" placeholder="e.g. SFR-AB12CD34"
                       value="<?= htmlspecialchars($code) ?>"
                       style="text-transform:uppercase;" required>
                <button type="submit" class="btn-primary">Search</button>
            </div>
        </form>

        <!-- ERROR -->
        <?php if ($error): ?>
        <div class="alert-error"><?= $error ?></div>
        <?php endif; ?>

        <!-- RESERVATION RESULT -->
        <?php if ($reservation): ?>
        <?php
        $statusClass = strtolower($reservation['status']);
        $statusEmoji = ['pending'=>'⏳','confirmed'=>'✅','cancelled'=>'❌'][$statusClass] ?? '📋';
        $nights = (strtotime($reservation['check_out']) - strtotime($reservation['check_in'])) / 86400;
        ?>
        <div class="success-card">
            <div class="success-icon"><?= $statusEmoji ?></div>
            <h2><?= $reservation['booking_code'] ?></h2>
            <p>Booking status: <span class="status-badge <?= $statusClass ?>"><?= $reservation['status'] ?></span></p>

            <div class="booking-summary-box">
                <div class="summary-row"><span>Guest Name</span><strong><?= htmlspecialchars($reservation['guest_name']) ?></strong></div>
                <div class="summary-row"><span>Cottage</span><strong><?= htmlspecialchars($reservation['cottage_name']) ?></strong></div>
                <div class="summary-row"><span>Check-in</span><strong><?= date('F d, Y', strtotime($reservation['check_in'])) ?></strong></div>
                <div class="summary-row"><span>Check-out</span><strong><?= date('F d, Y', strtotime($reservation['check_out'])) ?></strong></div>
                <div class="summary-row"><span>Duration</span><strong><?= $nights ?> night(s)</strong></div>
                <div class="summary-row"><span>Guests</span><strong><?= $reservation['num_guests'] ?></strong></div>
                <div class="summary-row"><span>Payment</span><strong><?= $reservation['payment_status'] ?></strong></div>
                <div class="summary-row total-row"><span>Total</span><strong>₱<?= number_format($reservation['total_price'], 2) ?></strong></div>
            </div>

            <?php if ($reservation['special_requests']): ?>
            <p class="note-text">📝 Special requests: <?= htmlspecialchars($reservation['special_requests']) ?></p>
            <?php endif; ?>

            <p class="note-text">📅 Booked on: <?= date('F d, Y g:i A', strtotime($reservation['created_at'])) ?></p>

            <div class="success-actions">
                <a href="index.php" class="btn-ghost">Back to Home</a>
                <a href="booking.php" class="btn-primary">New Booking</a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$code && !$reservation): ?>
        <div class="empty-state">
            <div style="font-size:4rem;">🔍</div>
            <p>Enter your booking code above to find your reservation.</p>
            <p><a href="booking.php">Don't have a booking yet? Reserve now →</a></p>
        </div>
        <?php endif; ?>

    </div>
</div>

<footer class="footer">
    <div class="footer-bottom"><p>&copy; <?= date('Y') ?> S-Five Inland Resort.</p></div>
</footer>
<script src="js/main.js"></script>
</body>
</html>
