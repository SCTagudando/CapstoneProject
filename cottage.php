<?php
// cottage.php — Cottage Detail Page with Photo Gallery
require_once 'includes/config.php';
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $db->prepare("SELECT * FROM cottages WHERE id = ? AND is_available = 1");
$stmt->execute([$id]);
$cottage = $stmt->fetch();
if (!$cottage) { header('Location: index.php'); exit; }

// Check if booked today
$booked_stmt = $db->prepare("
    SELECT COUNT(*) FROM reservations
    WHERE cottage_id=? AND status IN ('Confirmed','Pending')
    AND check_in <= CURDATE() AND check_out > CURDATE()
");
$booked_stmt->execute([$id]);
$is_booked_now = $booked_stmt->fetchColumn() > 0;

// Related cottages (same category, not this one)
$related = $db->prepare("SELECT * FROM cottages WHERE category=? AND id!=? AND is_available=1 LIMIT 3");
$related->execute([$cottage['category'], $id]);
$related = $related->fetchAll();

// Single thumbnail — use uploaded photo or placeholder
$type_map = ['Bahay Kubo'=>'bahay_kubo','Open Cottage'=>'open_cottage','Kubo Premium'=>'kubo_premium'];
$img_type = $type_map[$cottage['category']] ?? 'bahay_kubo';

$photo_stmt = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? ORDER BY sort_order ASC LIMIT 1");
$photo_stmt->execute([$id]);
$photo_row  = $photo_stmt->fetch();
$single_img = $photo_row
    ? "uploads/cottages/" . $photo_row['filename']
    : "images/cottage_placeholder.php?type={$img_type}&name=" . urlencode($cottage['name']) . "&n={$id}";

$amenities_list = array_map('trim', explode(',', $cottage['amenities']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($cottage['name']) ?> — S-Five Inland Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/cottage.css">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar navbar-light" id="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-logo"><span class="logo-icon">🌴</span><span class="logo-text">S-Five Resort</span></a>
        <ul class="nav-links">
            <li><a href="index.php#cottages">← All Cottages</a></li>
            <li><a href="check_booking.php">My Booking</a></li>
            <?php if (!$is_booked_now): ?>
            <li><a href="booking.php?cottage_id=<?= $id ?>" class="btn-nav">Book This</a></li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<div class="cottage-detail-page">

    <!-- BREADCRUMB -->
    <div class="breadcrumb-bar">
        <div class="container">
            <a href="index.php">Home</a> <span>›</span>
            <a href="index.php#cottages">Cottages</a> <span>›</span>
            <span><?= htmlspecialchars($cottage['name']) ?></span>
        </div>
    </div>

    <div class="container cottage-detail-layout">

        <!-- LEFT: GALLERY + INFO -->
        <div class="cottage-detail-main">

            <!-- SINGLE PHOTO ONLY -->
            <div class="gallery-main single-photo">
                <img src="<?= $single_img ?>"
                     alt="<?= htmlspecialchars($cottage['name']) ?>"
                     class="main-photo" id="mainPhotoImg">
                <?php if ($is_booked_now): ?>
                <div class="booked-ribbon">Currently Booked</div>
                <?php else: ?>
                <div class="avail-ribbon available-ribbon">
                    <span class="avail-dot"></span> Available
                </div>
                <?php endif; ?>
                <?php if (!$photo_row): ?>
                <div class="placeholder-notice">📷 Sample image — replace via Admin → Cottages</div>
                <?php endif; ?>
            </div>

            <!-- COTTAGE INFO -->
            <div class="cottage-detail-info">
                <div class="cdi-header">
                    <div class="cdi-cat-tag"><?= $cottage['category'] ?></div>
                    <h1><?= htmlspecialchars($cottage['name']) ?></h1>
                    <div class="cdi-meta">
                        <span>👥 Up to <?= $cottage['capacity'] ?> guests</span>
                        <span>🌙 ₱<?= number_format($cottage['price_per_night'], 0) ?>/night</span>
                    </div>
                </div>

                <div class="cdi-desc">
                    <h3>About this Cottage</h3>
                    <p><?= htmlspecialchars($cottage['description']) ?></p>
                </div>

                <div class="cdi-amenities">
                    <h3>Amenities & Inclusions</h3>
                    <div class="amenities-list">
                        <?php foreach ($amenities_list as $a): ?>
                        <div class="amenity-chip">
                            <span class="chip-icon">
                                <?php
                                $icons_map = [
                                    'Fan'=>'🌀','Aircon'=>'❄️','Air conditioning'=>'❄️','Split-type Aircon'=>'❄️','Central Aircon'=>'❄️',
                                    'Bathroom'=>'🚿','Hot'=>'🚿','shower'=>'🚿','Jacuzzi'=>'🛁',
                                    'Bed'=>'🛏️','King bed'=>'🛏️','Queen beds'=>'🛏️','bedding'=>'🛏️',
                                    'TV'=>'📺','Smart TV'=>'📺',
                                    'ref'=>'🧊','Mini ref'=>'🧊','Mini bar'=>'🧊',
                                    'BBQ'=>'🔥','grill'=>'🔥',
                                    'Parking'=>'🅿️',
                                    'Fan'=>'🌀','Electric Fan'=>'🌀',
                                    'Veranda'=>'🌿','Garden'=>'🌿',
                                    'tables'=>'🪑','chairs'=>'🪑',
                                    'lighting'=>'💡','lights'=>'💡',
                                    'Sound'=>'🔊','system'=>'🔊',
                                    'Mosquito'=>'🦟','net'=>'🦟',
                                    'view'=>'🏔️','View'=>'🏔️',
                                    'Breakfast'=>'🍳',
                                    'Hammock'=>'🏖️',
                                    'Balcony'=>'🌅',
                                ];
                                $chip_icon = '✅';
                                foreach ($icons_map as $kw => $ico) {
                                    if (stripos($a, $kw) !== false) { $chip_icon = $ico; break; }
                                }
                                echo $chip_icon;
                                ?>
                            </span>
                            <?= htmlspecialchars(trim($a)) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: BOOKING PANEL -->
        <div class="cottage-detail-sidebar">
            <div class="booking-panel">
                <div class="bp-price">
                    <strong>₱<?= number_format($cottage['price_per_night'], 0) ?></strong>
                    <span>per night</span>
                </div>

                <?php if ($is_booked_now): ?>
                <div class="bp-unavail">
                    <span>⚠️ Currently occupied</span>
                    <p>This cottage is booked right now. Check other dates or browse other cottages.</p>
                </div>
                <?php endif; ?>

                <div class="bp-quick-form">
                    <h4>Check Availability</h4>
                    <form action="booking.php" method="GET">
                        <input type="hidden" name="cottage_id" value="<?= $id ?>">
                        <div class="bp-field">
                            <label>Check-in</label>
                            <input type="date" name="check_in" min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="bp-field">
                            <label>Check-out</label>
                            <input type="date" name="check_out" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
                        </div>
                        <div class="bp-field">
                            <label>Guests</label>
                            <select name="guests">
                                <?php for($i=1;$i<=$cottage['capacity'];$i++): ?>
                                <option value="<?=$i?>"><?=$i?> <?=$i==1?'Guest':'Guests'?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn-primary" style="width:100%;margin-top:0.5rem;">
                            <?= $is_booked_now ? 'Check Other Dates →' : 'Book This Cottage →' ?>
                        </button>
                    </form>
                </div>

                <div class="bp-notes">
                    <p>💰 Pay at resort or via GCash</p>
                    <p>✅ Free cancellation 24hrs before</p>
                    <p>📞 Call us: +63 912 345 6789</p>
                </div>
            </div>

            <?php if (!empty($related)): ?>
            <div class="related-cottages">
                <h4>Other <?= $cottage['category'] ?>s</h4>
                <?php foreach ($related as $r):
                    $rt = $type_map[$r['category']] ?? 'bahay_kubo';
                    $rp = $db->prepare("SELECT filename FROM cottage_images WHERE cottage_id=? ORDER BY sort_order ASC LIMIT 1");
                    $rp->execute([$r['id']]);
                    $rf = $rp->fetchColumn();
                    $r_img = $rf ? "uploads/cottages/$rf" : "images/cottage_placeholder.php?type={$rt}&n={$r['id']}";
                ?>
                <a href="cottage.php?id=<?= $r['id'] ?>" class="related-card">
                    <img src="<?= $r_img ?>"
                         alt="<?= htmlspecialchars($r['name']) ?>">
                    <div class="related-info">
                        <strong><?= htmlspecialchars($r['name']) ?></strong>
                        <span>₱<?= number_format($r['price_per_night'],0) ?>/night · Up to <?= $r['capacity'] ?> guests</span>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- LIGHTBOX -->
<footer class="footer">
    <div class="footer-bottom"><p>&copy; <?= date('Y') ?> S-Five Inland Resort.</p></div>
</footer>
<script src="js/main.js"></script>
</body>
</html>
