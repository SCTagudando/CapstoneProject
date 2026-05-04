<?php
// index.php - S-Five Inland Resort Homepage
require_once 'includes/config.php';
$db = getDB();

// Get check-in/check-out from availability bar if submitted
$ci = isset($_GET['check_in'])  ? clean($_GET['check_in'])  : '';
$co = isset($_GET['check_out']) ? clean($_GET['check_out']) : '';
$gs = isset($_GET['guests'])    ? (int)$_GET['guests']      : 1;

// Fetch ALL cottages (we'll show unavailable ones too with a label)
// For each cottage check if it has an active confirmed/pending booking that overlaps the given dates
// If no dates given, just show general "booked today" status
if ($ci && $co) {
    $stmt = $db->prepare("
        SELECT c.*,
            (SELECT COUNT(*) FROM reservations r
             WHERE r.cottage_id = c.id AND r.status != 'Cancelled'
             AND NOT (r.check_out <= ? OR r.check_in >= ?)) AS is_booked_for_dates
        FROM cottages c
        WHERE c.is_available = 1
        ORDER BY FIELD(c.category,'Bahay Kubo','Open Cottage','Kubo Premium'), c.name ASC
    ");
    $stmt->execute([$ci, $co]);
} else {
    // Show cottages with any active booking today
    $stmt = $db->query("
        SELECT c.*,
            (SELECT COUNT(*) FROM reservations r
             WHERE r.cottage_id = c.id AND r.status IN ('Confirmed','Pending')
             AND r.check_in <= CURDATE() AND r.check_out > CURDATE()) AS is_booked_for_dates
        FROM cottages c
        WHERE c.is_available = 1
        ORDER BY FIELD(c.category,'Bahay Kubo','Open Cottage','Kubo Premium'), c.name ASC
    ");
}
$all_cottages = $stmt->fetchAll();

// Group by category
$grouped = [];
foreach ($all_cottages as $c) {
    $grouped[$c['category']][] = $c;
}

$category_info = [
    'Bahay Kubo'    => ['icon' => '🌿', 'desc' => 'Traditional bamboo kubos with electric fans. Simple, breezy, and authentically Filipino.'],
    'Open Cottage'  => ['icon' => '🎉', 'desc' => 'Large open-air venues for events — birthdays, weddings, reunions, and parties.'],
    'Kubo Premium'  => ['icon' => '✨', 'desc' => 'Luxury kubos with split-type aircon, premium beds, and resort-grade amenities.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>S-Five Inland Resort — Your Tropical Escape</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar" id="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-logo">
            <span class="logo-icon">🌴</span>
            <span class="logo-text">S-Five Resort</span>
        </a>
        <ul class="nav-links">
            <li><a href="#cottages">Cottages</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#amenities">Amenities</a></li>
            <li><a href="check_booking.php">My Booking</a></li>
            <li><a href="booking.php" class="btn-nav">Book Now</a></li>
        </ul>
        <button class="nav-toggle" id="navToggle">☰</button>
    </div>
</nav>

<!-- HERO -->
<section class="hero" id="home">
    <div class="hero-bg">
        <div class="hero-overlay"></div>
    
    </div>
    <div class="hero-content">
        <p class="hero-tagline">Welcome to</p>
        <h1 class="hero-title">S-Five Inland<br><em>Resort</em></h1>
        <p class="hero-sub">Where the breeze whispers Tagalog and every cottage tells a story.</p>
        <div class="hero-btns">
            <a href="booking.php" class="btn-primary">Reserve Now</a>
            <a href="#cottages" class="btn-ghost">Explore Cottages</a>
        </div>
    </div>
   
</section>

<!-- AVAILABILITY CHECKER -->
<section class="availability-bar">
    <div class="avail-container">
        <h3>Check Availability</h3>
        <form action="index.php" method="GET" class="avail-form" id="availForm">
            <div class="avail-field">
                <label>Check-in</label>
                <input type="date" name="check_in" id="check_in" value="<?= htmlspecialchars($ci) ?>"
                       min="<?= date('Y-m-d') ?>">
            </div>
            <div class="avail-divider">→</div>
            <div class="avail-field">
                <label>Check-out</label>
                <input type="date" name="check_out" id="check_out" value="<?= htmlspecialchars($co) ?>"
                       min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
            </div>
            <div class="avail-field">
                <label>Guests</label>
                <select name="guests">
                    <?php for($i=1;$i<=60;$i++): ?>
                    <option value="<?=$i?>" <?=$gs==$i?'selected':''?>><?=$i?> <?=$i==1?'Guest':'Guests'?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <button type="submit" class="btn-check">Check →</button>
            <?php if ($ci && $co): ?>
            <a href="index.php#cottages" class="btn-clear-avail">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($ci && $co): ?>
    <div class="avail-notice">
        Showing availability for <strong><?= date('M d', strtotime($ci)) ?> → <?= date('M d, Y', strtotime($co)) ?></strong>
        &nbsp;·&nbsp; <?= $gs ?> guest(s)
    </div>
    <?php endif; ?>
</section>

<!-- COTTAGES SECTION -->
<section class="cottages-section" id="cottages">
    <div class="container">
        <div class="section-header">
            <p class="section-label">Accommodations</p>
            <h2 class="section-title">Our <em>Cottages</em></h2>
            <p class="section-desc">Each cottage is designed to give you the full Filipino inland resort experience — rustic, warm, and deeply restful.</p>
        </div>

        <?php foreach ($grouped as $category => $cottages): ?>
        <?php $info = $category_info[$category]; ?>

        <!-- Category Header -->
        <div class="category-header">
            <div class="cat-icon"><?= $info['icon'] ?></div>
            <div class="cat-info">
                <h3><?= $category ?></h3>
                <p><?= $info['desc'] ?></p>
            </div>
            <div class="cat-count"><?= count($cottages) ?> available</div>
        </div>

        <div class="cottages-grid">
            <?php foreach ($cottages as $c):
                $booked   = (bool)$c['is_booked_for_dates'];
                $type_map = ['Bahay Kubo'=>'bahay_kubo','Open Cottage'=>'open_cottage','Kubo Premium'=>'kubo_premium'];
                $img_type = $type_map[$c['category']] ?? 'bahay_kubo';

                // Use first uploaded photo if it exists, otherwise use placeholder
                $photo_stmt = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? ORDER BY sort_order ASC LIMIT 1");
                $photo_stmt->execute([$c['id']]);
                $photo_row = $photo_stmt->fetch();
                $thumb_url = $photo_row
                    ? "uploads/cottages/" . $photo_row['filename']
                    : "images/cottage_placeholder.php?type={$img_type}&name=" . urlencode($c['name']) . "&n={$c['id']}";
            ?>
            <div class="cottage-card <?= $booked ? 'is-booked' : '' ?>">

                <!-- Single clean thumbnail -->
                <a href="cottage.php?id=<?= $c['id'] ?>" class="cottage-img-link">
                    <div class="cottage-img">
                        <img src="<?= $thumb_url ?>"
                             alt="<?= htmlspecialchars($c['name']) ?>"
                             class="cottage-thumb-img">

                        <!-- Availability badge only -->
                        <?php if ($booked): ?>
                        <div class="avail-badge unavailable">
                            <span class="avail-dot"></span> Unavailable
                        </div>
                        <?php else: ?>
                        <div class="avail-badge available">
                            <span class="avail-dot"></span> Available
                        </div>
                        <?php endif; ?>

                        <span class="cottage-badge">Up to <?= $c['capacity'] ?> guests</span>
                    </div>
                </a>

                <div class="cottage-body">
                    <div class="cottage-category-tag"><?= $c['category'] ?></div>
                    <h3 class="cottage-name">
                        <a href="cottage.php?id=<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                    </h3>
                    <p class="cottage-desc"><?= htmlspecialchars($c['description']) ?></p>

                    <div class="cottage-amenities">
                        <?php foreach (array_slice(explode(',', $c['amenities']), 0, 3) as $a): ?>
                        <span class="tag"><?= trim($a) ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="cottage-footer">
                        <div class="cottage-price">
                            <span class="price-amount">₱<?= number_format($c['price_per_night'], 0) ?></span>
                            <span class="price-unit">/night</span>
                        </div>

                        <?php if ($booked): ?>
                        <button class="btn-book btn-booked" disabled>Not Available</button>
                        <?php else: ?>
                        <a href="booking.php?cottage_id=<?= $c['id'] ?><?= $ci?"&check_in=$ci":'' ?><?= $co?"&check_out=$co":'' ?><?= $gs?"&guests=$gs":'' ?>"
                           class="btn-book">Book This</a>
                        <?php endif; ?>
                    </div>

                    <?php if ($booked && $ci && $co): ?>
                    <p class="booked-notice">⚠️ Already booked for <?= date('M d', strtotime($ci)) ?> – <?= date('M d', strtotime($co)) ?>. Try different dates.</p>
                    <?php elseif ($booked): ?>
                    <p class="booked-notice">⚠️ Currently occupied. Please check availability for your dates.</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php endforeach; ?>

        <?php if (empty($all_cottages)): ?>
        <div class="empty-cottages">
            <div>😔</div>
            <p>No cottages found. Please try different dates or guest count.</p>
            <a href="index.php#cottages">Reset</a>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ABOUT -->
<section class="about-section" id="about">
    <div class="container about-grid">
        <div class="about-visual">
            <div class="about-img-box"><div class="about-emoji-main">🌴</div>
                <div class="about-badge-float">Est. 2019</div>
            </div>
            <div class="about-stat-cards">
                <div class="stat-card"><strong>12</strong><span>Cottages</span></div>
                <div class="stat-card"><strong>500+</strong><span>Happy Guests</span></div>
                <div class="stat-card"><strong>★ 4.9</strong><span>Rating</span></div>
            </div>
        </div>
        <div class="about-text">
            <p class="section-label">Our Story</p>
            <h2 class="section-title">A Place to <em>Breathe Again</em></h2>
            <p>Nestled deep in the heart of inland Philippines, <strong>S-Five Inland Resort</strong> was born from a dream — to preserve the warmth and beauty of Filipino rural life while offering guests a peaceful retreat from the city.</p>
            <p>From fan-cooled Bahay Kubos to luxurious Premium Suites and grand event cottages, every corner of S-Five is designed to feel like home — just a more beautiful, more restful version of it.</p>
            <a href="booking.php" class="btn-primary" style="margin-top:1.5rem;display:inline-block;">Plan Your Stay</a>
        </div>
    </div>
</section>

<!-- AMENITIES -->
<section class="amenities-section" id="amenities">
    <div class="container">
        <div class="section-header">
            <p class="section-label">What We Offer</p>
            <h2 class="section-title">Resort <em>Amenities</em></h2>
        </div>
        <div class="amenities-grid">
            <div class="amenity-item"><div class="amenity-icon">🏊</div><h4>Natural Pools</h4><p>Cool, clean resort pools surrounded by tropical greenery</p></div>
            <div class="amenity-item"><div class="amenity-icon">🍽️</div><h4>Filipino Cuisine</h4><p>Authentic local dishes prepared fresh daily</p></div>
            <div class="amenity-item"><div class="amenity-icon">🎉</div><h4>Event Hosting</h4><p>Birthday, wedding, debut, and reunion packages available</p></div>
            <div class="amenity-item"><div class="amenity-icon">🔥</div><h4>BBQ Grilling</h4><p>Outdoor grilling areas for your evening gatherings</p></div>
            <div class="amenity-item"><div class="amenity-icon">🌿</div><h4>Garden Walks</h4><p>Guided walks through our lush tropical gardens</p></div>
            <div class="amenity-item"><div class="amenity-icon">🅿️</div><h4>Free Parking</h4><p>Safe, spacious parking for all resort guests</p></div>
        </div>
    </div>
</section>

<!-- CTA -->
<section class="cta-section">
    <div class="cta-leaves">🌿🌴🍃</div>
    <div class="container cta-inner">
        <h2>Ready for your <em>getaway?</em></h2>
        <p>Book your cottage today and escape to the heart of Filipino paradise.</p>
        <a href="booking.php" class="btn-primary btn-lg">Reserve Your Cottage</a>
    </div>
</section>

<!-- FOOTER -->
<footer class="footer">
    <div class="container footer-grid">
        <div class="footer-brand">
            <span class="logo-icon">🌴</span>
            <span class="logo-text">S-Five Resort</span>
            <p>Your tropical inland escape, anytime.</p>
        </div>
        <div class="footer-links">
            <h4>Quick Links</h4>
            <ul>
                <li><a href="index.php">Home</a></li>
                <li><a href="#cottages">Cottages</a></li>
                <li><a href="booking.php">Book Now</a></li>
                <li><a href="check_booking.php">Check Reservation</a></li>
            </ul>
        </div>
        <div class="footer-contact">
            <h4>Contact</h4>
            <p>📍 San Jose San Migeul, Iloilo</p>
            <p>📞 +63 912 345 6789</p>
            <p>✉️ sfiveresort@gamail.com</p>
            <p>💵 GCash: 0912 345 6789</p>
        </div>
    </div>
    <div class="footer-bottom">
        <p>&copy; <?= date('Y') ?> S-Five Inland Resort. All rights reserved.</p>
    </div>
</footer>

<script src="js/main.js"></script>
</body>
</html>
