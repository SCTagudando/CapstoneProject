<?php
require_once 'auth.php';
$page_title = 'Dashboard';

$db = getDB();

// Stats
$total_bookings  = $db->query("SELECT COUNT(*) FROM reservations")->fetchColumn();
$pending         = $db->query("SELECT COUNT(*) FROM reservations WHERE status='Pending'")->fetchColumn();
$confirmed       = $db->query("SELECT COUNT(*) FROM reservations WHERE status='Confirmed'")->fetchColumn();
$cancelled       = $db->query("SELECT COUNT(*) FROM reservations WHERE status='Cancelled'")->fetchColumn();
$monthly_revenue = $db->query("SELECT COALESCE(SUM(total_price),0) FROM reservations WHERE status='Confirmed' AND MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
$guests_today    = $db->query("SELECT COUNT(*) FROM reservations WHERE check_in = CURDATE() AND status='Confirmed'")->fetchColumn();

// Cottage availability tracking
// Total active (not hidden by admin)
$total_cottages  = $db->query("SELECT COUNT(*) FROM cottages WHERE is_available=1")->fetchColumn();

// Currently occupied = cottages with a Confirmed OR Pending booking that covers today
$occupied_now    = $db->query("
    SELECT COUNT(DISTINCT cottage_id) FROM reservations
    WHERE status IN ('Confirmed','Pending')
    AND check_in <= CURDATE()
    AND check_out  > CURDATE()
")->fetchColumn();

// Available right now = total active minus occupied
$available_now   = max(0, $total_cottages - $occupied_now);

// Recent reservations
$recent = $db->query("
    SELECT r.*, c.name AS cottage_name
    FROM reservations r JOIN cottages c ON r.cottage_id = c.id
    ORDER BY r.created_at DESC LIMIT 8
")->fetchAll();

// Monthly bookings chart data (last 6 months)
$chart_data = $db->query("
    SELECT DATE_FORMAT(created_at,'%b') AS month, COUNT(*) AS count
    FROM reservations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY YEAR(created_at), MONTH(created_at)
    ORDER BY created_at ASC
")->fetchAll();

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
<!-- STAT CARDS -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">📋</div>
        <div class="stat-info">
            <span>Total Bookings</span>
            <strong><?= $total_bookings ?></strong>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">⏳</div>
        <div class="stat-info">
            <span>Pending</span>
            <strong><?= $pending ?></strong>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e3f2fd;">✅</div>
        <div class="stat-info">
            <span>Confirmed</span>
            <strong><?= $confirmed ?></strong>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f3e5f5;">💰</div>
        <div class="stat-info">
            <span>This Month Revenue</span>
            <strong>₱<?= number_format($monthly_revenue, 0) ?></strong>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fce4ec;">🌴</div>
        <div class="stat-info">
            <span>Available Cottages</span>
            <strong><?= $available_now ?> <small style="font-size:0.65rem;font-weight:500;color:#888;">/ <?= $total_cottages ?> total</small></strong>
            <div class="cottage-mini-bar">
                <div class="mini-bar-available" style="width:<?= $total_cottages > 0 ? round(($available_now/$total_cottages)*100) : 0 ?>%"></div>
                <div class="mini-bar-occupied" style="width:<?= $total_cottages > 0 ? round(($occupied_now/$total_cottages)*100) : 0 ?>%"></div>
            </div>
            <div class="cottage-mini-legend">
                <span class="dot-avail"></span><?= $available_now ?> Available
                &nbsp;
                <span class="dot-occ"></span><?= $occupied_now ?> occupied
            </div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e0f7fa;">👥</div>
        <div class="stat-info">
            <span>Check-ins Today</span>
            <strong><?= $guests_today ?></strong>
        </div>
    </div>
</div>

<!-- CHART + QUICK ACTIONS -->
<div class="dash-mid-grid">
    <div class="card">
        <div class="card-header">
            <h3>Bookings (Last 6 Months)</h3>
        </div>
        <div class="card-body">
            <canvas id="bookingsChart" height="100"></canvas>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3>Quick Actions</h3></div>
        <div class="card-body quick-actions">
            <a href="reservations.php?status=Pending" class="qa-btn">
                <span>⏳</span>
                <div><strong>Pending Reservations</strong><small><?= $pending ?> awaiting review</small></div>
            </a>
            <a href="cottages.php?action=add" class="qa-btn">
                <span>➕</span>
                <div><strong>Add New Cottage</strong><small>List a new accommodation</small></div>
            </a>
            <a href="reports.php" class="qa-btn">
                <span>📈</span>
                <div><strong>Generate Report</strong><small>View revenue & guest stats</small></div>
            </a>
            <a href="../booking.php" target="_blank" class="qa-btn">
                <span>🌐</span>
                <div><strong>View Booking Page</strong><small>See customer-facing site</small></div>
            </a>
        </div>
    </div>
</div>

<!-- RECENT RESERVATIONS -->
<div class="card">
    <div class="card-header">
        <h3>Recent Reservations</h3>
        <a href="reservations.php" class="card-link">View All →</a>
    </div>
    <div class="card-body">
        <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Booking Code</th>
                    <th>Guest</th>
                    <th>Cottage</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent)): ?>
                <tr><td colspan="8" class="empty-row">No reservations yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $r): ?>
                <tr>
                    <td><code><?= $r['booking_code'] ?></code></td>
                    <td>
                        <strong><?= htmlspecialchars($r['guest_name']) ?></strong><br>
                        <small><?= htmlspecialchars($r['guest_email']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($r['cottage_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($r['check_in'])) ?></td>
                    <td><?= date('M d, Y', strtotime($r['check_out'])) ?></td>
                    <td>₱<?= number_format($r['total_price'], 0) ?></td>
                    <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
                    <td><a href="reservations.php?view=<?= $r['id'] ?>" class="btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</html>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('bookingsChart').getContext('2d');
new Chart(ctx, {
    type: 'bar',
    data: {
        labels: [<?= implode(',', array_map(fn($d) => '"'.$d['month'].'"', $chart_data)) ?>],
        datasets: [{
            label: 'Bookings',
            data: [<?= implode(',', array_column($chart_data, 'count')) ?>],
            backgroundColor: 'rgba(45,106,79,0.7)',
            borderColor: 'rgba(45,106,79,1)',
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } }
        }
    }
});
</script>

<?php include 'partials/footer.php'; ?>
