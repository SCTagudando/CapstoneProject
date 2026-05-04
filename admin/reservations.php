<?php
require_once 'auth.php';
$page_title = 'Reservations';
$db = getDB();
$msg = '';

// ===== HANDLE ACTIONS =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id     = (int)($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    if ($id && in_array($action, ['Confirmed', 'Cancelled', 'Pending'])) {
        $stmt = $db->prepare("UPDATE reservations SET status = ? WHERE id = ?");
        $stmt->execute([$action, $id]);
        $msg = "Reservation updated to <strong>$action</strong> successfully.";
    }
}

// Handle payment toggle
if (isset($_GET['toggle_payment'])) {
    $id = (int)$_GET['toggle_payment'];
    $stmt = $db->prepare("UPDATE reservations SET payment_status = IF(payment_status='Paid','Unpaid','Paid') WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: reservations.php');
    exit;
}

// ===== FILTERS =====
$status_filter = clean($_GET['status'] ?? '');
$search        = clean($_GET['search'] ?? '');
$view_id       = (int)($_GET['view'] ?? 0);

// Build query
$where = [];
$params = [];
if ($status_filter) { $where[] = "r.status = ?"; $params[] = $status_filter; }
if ($search) {
    $where[] = "(r.booking_code LIKE ? OR r.guest_name LIKE ? OR r.guest_email LIKE ?)";
    $params = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$reservations = $db->prepare("
    SELECT r.*, c.name AS cottage_name, c.price_per_night
    FROM reservations r JOIN cottages c ON r.cottage_id = c.id
    $where_sql
    ORDER BY r.created_at DESC
");
$reservations->execute($params);
$reservations = $reservations->fetchAll();

// Single reservation view
$viewing = null;
if ($view_id) {
    $stmt = $db->prepare("SELECT r.*, c.name AS cottage_name, c.description AS cottage_desc, c.capacity FROM reservations r JOIN cottages c ON r.cottage_id=c.id WHERE r.id=?");
    $stmt->execute([$view_id]);
    $viewing = $stmt->fetch();
}

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
<?php if ($msg): ?>
<div class="alert-success"><?= $msg ?></div>
<?php endif; ?>

<!-- DETAIL VIEW -->
<?php if ($viewing): ?>
<div class="card" style="margin-bottom:2rem;">
    <div class="card-header">
        <h3>Reservation — <?= $viewing['booking_code'] ?></h3>
        <a href="reservations.php" class="btn-sm">← Back to List</a>
    </div>
    <div class="card-body">
        <div class="detail-grid">
            <div class="detail-section">
                <h4>Guest Information</h4>
                <div class="detail-row"><span>Name</span><strong><?= htmlspecialchars($viewing['guest_name']) ?></strong></div>
                <div class="detail-row"><span>Email</span><strong><?= htmlspecialchars($viewing['guest_email']) ?></strong></div>
                <div class="detail-row"><span>Phone</span><strong><?= htmlspecialchars($viewing['guest_phone']) ?></strong></div>
                <div class="detail-row"><span>Guests</span><strong><?= $viewing['num_guests'] ?></strong></div>
                <?php if ($viewing['special_requests']): ?>
                <div class="detail-row"><span>Special Requests</span><strong><?= htmlspecialchars($viewing['special_requests']) ?></strong></div>
                <?php endif; ?>
            </div>
            <div class="detail-section">
                <h4>Booking Details</h4>
                <div class="detail-row"><span>Cottage</span><strong><?= htmlspecialchars($viewing['cottage_name']) ?></strong></div>
                <div class="detail-row"><span>Check-in</span><strong><?= date('F d, Y', strtotime($viewing['check_in'])) ?></strong></div>
                <div class="detail-row"><span>Check-out</span><strong><?= date('F d, Y', strtotime($viewing['check_out'])) ?></strong></div>
                <?php $nights = (strtotime($viewing['check_out'])-strtotime($viewing['check_in']))/86400; ?>
                <div class="detail-row"><span>Duration</span><strong><?= $nights ?> night(s)</strong></div>
                <div class="detail-row"><span>Total Price</span><strong>₱<?= number_format($viewing['total_price'],2) ?></strong></div>
                <div class="detail-row"><span>Payment</span><strong><?= $viewing['payment_status'] ?></strong></div>
                <div class="detail-row"><span>Booked On</span><strong><?= date('M d, Y g:i A', strtotime($viewing['created_at'])) ?></strong></div>
            </div>
        </div>

        <!-- ACTION FORM -->
        <div class="action-bar">
            <strong>Status: <span class="badge badge-<?= strtolower($viewing['status']) ?>"><?= $viewing['status'] ?></span></strong>
            <div class="action-btns">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
                    <input type="hidden" name="action" value="Confirmed">
                    <button type="submit" class="btn-action approve" <?= $viewing['status']==='Confirmed'?'disabled':'' ?>>✅ Confirm</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
                    <input type="hidden" name="action" value="Cancelled">
                    <button type="submit" class="btn-action reject" <?= $viewing['status']==='Cancelled'?'disabled':'' ?>>❌ Cancel</button>
                </form>
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="id" value="<?= $viewing['id'] ?>">
                    <input type="hidden" name="action" value="Pending">
                    <button type="submit" class="btn-action neutral" <?= $viewing['status']==='Pending'?'disabled':'' ?>>⏳ Set Pending</button>
                </form>
                <a href="reservations.php?toggle_payment=<?= $viewing['id'] ?>" class="btn-action neutral">
                    💰 Mark <?= $viewing['payment_status']==='Paid'?'Unpaid':'Paid' ?>
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- FILTERS -->
<div class="card">
    <div class="card-header">
        <h3>All Reservations <span class="count-badge"><?= count($reservations) ?></span></h3>
    </div>
    <div class="card-body">
        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <input type="text" name="search" placeholder="Search by name, email, code..." value="<?= htmlspecialchars($search) ?>">
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="Pending"   <?= $status_filter==='Pending'  ?'selected':'' ?>>Pending</option>
                    <option value="Confirmed" <?= $status_filter==='Confirmed'?'selected':'' ?>>Confirmed</option>
                    <option value="Cancelled" <?= $status_filter==='Cancelled'?'selected':'' ?>>Cancelled</option>
                </select>
                <button type="submit" class="btn-filter">Filter</button>
                <?php if ($search || $status_filter): ?>
                <a href="reservations.php" class="btn-filter-clear">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Guest</th>
                    <th>Cottage</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Guests</th>
                    <th>Total</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                <tr><td colspan="10" class="empty-row">No reservations found.</td></tr>
                <?php endif; ?>
                <?php foreach ($reservations as $r): ?>
                <tr>
                    <td><code><?= $r['booking_code'] ?></code></td>
                    <td>
                        <strong><?= htmlspecialchars($r['guest_name']) ?></strong><br>
                        <small><?= htmlspecialchars($r['guest_email']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($r['cottage_name']) ?></td>
                    <td><?= date('M d, Y', strtotime($r['check_in'])) ?></td>
                    <td><?= date('M d, Y', strtotime($r['check_out'])) ?></td>
                    <td><?= $r['num_guests'] ?></td>
                    <td>₱<?= number_format($r['total_price'], 0) ?></td>
                    <td>
                        <span class="badge badge-<?= $r['payment_status']==='Paid'?'confirmed':'pending' ?>">
                            <?= $r['payment_status'] ?>
                        </span>
                    </td>
                    <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
                    <td>
                        <div class="inline-actions">
                            <a href="reservations.php?view=<?= $r['id'] ?>" class="btn-sm">View</a>
                            <?php if ($r['status'] === 'Pending'): ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="action" value="Confirmed">
                                <button type="submit" class="btn-sm btn-sm-approve">✅</button>
                            </form>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <input type="hidden" name="action" value="Cancelled">
                                <button type="submit" class="btn-sm btn-sm-reject">❌</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</html>
<?php include 'partials/footer.php'; ?>
