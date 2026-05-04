<?php
require_once 'auth.php';
$page_title = 'Reports';
$db = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));

// Summary for selected month
$monthly_stats = $db->prepare("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN status='Confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN status='Cancelled' THEN 1 ELSE 0 END) AS cancelled,
        COALESCE(SUM(CASE WHEN status='Confirmed' THEN total_price ELSE 0 END), 0) AS revenue
    FROM reservations
    WHERE MONTH(created_at) = ? AND YEAR(created_at) = ?
");
$monthly_stats->execute([$month, $year]);
$stats = $monthly_stats->fetch();

// Bookings for selected month
$bookings = $db->prepare("
    SELECT r.*, c.name AS cottage_name
    FROM reservations r JOIN cottages c ON r.cottage_id=c.id
    WHERE MONTH(r.created_at)=? AND YEAR(r.created_at)=?
    ORDER BY r.created_at DESC
");
$bookings->execute([$month, $year]);
$bookings = $bookings->fetchAll();

// Revenue per cottage
$by_cottage = $db->prepare("
    SELECT c.name, COUNT(r.id) AS bookings,
           COALESCE(SUM(CASE WHEN r.status='Confirmed' THEN r.total_price ELSE 0 END),0) AS revenue
    FROM cottages c
    LEFT JOIN reservations r ON c.id=r.cottage_id AND MONTH(r.created_at)=? AND YEAR(r.created_at)=?
    GROUP BY c.id, c.name ORDER BY revenue DESC
");
$by_cottage->execute([$month, $year]);
$by_cottage = $by_cottage->fetchAll();

// All-time totals
$all_time = $db->query("
    SELECT COUNT(*) AS total,
           COALESCE(SUM(CASE WHEN status='Confirmed' THEN total_price ELSE 0 END),0) AS revenue
    FROM reservations
")->fetch();

$months_list = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$years_list  = range(date('Y'), date('Y')-3);

include 'partials/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - S-Five Inland Resort</title>
    <link rel="stylesheet" href="admin.css">
</head>
<!-- FILTERS -->
<div class="card" style="margin-bottom:1.5rem;">
    <div class="card-body">
        <form method="GET" class="filter-form report-filter">
            <div class="form-group" style="margin-bottom:0;">
                <label>Month</label>
                <select name="month">
                    <?php for($m=1;$m<=12;$m++): ?>
                    <option value="<?=$m?>" <?=$month==$m?'selected':''?>><?= $months_list[$m] ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Year</label>
                <select name="year">
                    <?php foreach($years_list as $y): ?>
                    <option value="<?=$y?>" <?=$year==$y?'selected':''?>><?=$y?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn-filter" style="align-self:flex-end;">Generate</button>
            <button type="button" onclick="window.print()" class="btn-filter" style="align-self:flex-end;background:#444;">🖨️ Print</button>
        </form>
    </div>
</div>

<!-- MONTHLY SUMMARY STATS -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">📋</div>
        <div class="stat-info"><span>Total Bookings</span><strong><?= $stats['total'] ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">✅</div>
        <div class="stat-info"><span>Confirmed</span><strong><?= $stats['confirmed'] ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">⏳</div>
        <div class="stat-info"><span>Pending</span><strong><?= $stats['pending'] ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;">❌</div>
        <div class="stat-info"><span>Cancelled</span><strong><?= $stats['cancelled'] ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f3e5f5;">💰</div>
        <div class="stat-info"><span>Monthly Revenue</span><strong>₱<?= number_format($stats['revenue'],0) ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e0f7fa;">🏆</div>
        <div class="stat-info"><span>All-Time Revenue</span><strong>₱<?= number_format($all_time['revenue'],0) ?></strong></div>
    </div>
</div>

<div class="report-grid">
    <!-- REVENUE BY COTTAGE -->
    <div class="card">
        <div class="card-header"><h3>Revenue by Cottage — <?= $months_list[$month] ?> <?= $year ?></h3></div>
        <div class="card-body">
            <table class="admin-table">
                <thead><tr><th>Cottage</th><th>Bookings</th><th>Revenue</th></tr></thead>
                <tbody>
                    <?php foreach ($by_cottage as $bc): ?>
                    <tr>
                        <td><?= htmlspecialchars($bc['name']) ?></td>
                        <td><?= $bc['bookings'] ?></td>
                        <td>₱<?= number_format($bc['revenue'],0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- GUEST LIST -->
    <div class="card">
        <div class="card-header">
            <h3>Guest List — <?= $months_list[$month] ?> <?= $year ?></h3>
            <span class="count-badge"><?= count($bookings) ?></span>
        </div>
        <div class="card-body">
            <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Guest</th>
                        <th>Cottage</th>
                        <th>Check-in</th>
                        <th>Total</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bookings)): ?>
                    <tr><td colspan="6" class="empty-row">No bookings for this month.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td><code><?= $b['booking_code'] ?></code></td>
                        <td><?= htmlspecialchars($b['guest_name']) ?></td>
                        <td><?= htmlspecialchars($b['cottage_name']) ?></td>
                        <td><?= date('M d', strtotime($b['check_in'])) ?></td>
                        <td>₱<?= number_format($b['total_price'],0) ?></td>
                        <td><span class="badge badge-<?= strtolower($b['status']) ?>"><?= $b['status'] ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
</div>
</html>
<?php include 'partials/footer.php'; ?>
