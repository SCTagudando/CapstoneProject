<?php
require_once 'auth.php';
$page_title = 'Guests';
$db = getDB();

$search = clean($_GET['search'] ?? '');

$params = [];
$where = '';
if ($search) {
    $where = "WHERE r.guest_name LIKE ? OR r.guest_email LIKE ? OR r.guest_phone LIKE ?";
    $params = ["%$search%", "%$search%", "%$search%"];
}

$guests = $db->prepare("
    SELECT 
        r.guest_name, r.guest_email, r.guest_phone,
        COUNT(r.id) AS total_bookings,
        SUM(CASE WHEN r.status='Confirmed' THEN r.total_price ELSE 0 END) AS total_spent,
        MAX(r.created_at) AS last_booking,
        GROUP_CONCAT(r.status ORDER BY r.created_at DESC SEPARATOR ',') AS statuses
    FROM reservations r
    $where
    GROUP BY r.guest_email, r.guest_name, r.guest_phone
    ORDER BY last_booking DESC
");
$guests->execute($params);
$guests = $guests->fetchAll();

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
<div class="card">
    <div class="card-header">
        <h3>All Guests <span class="count-badge"><?= count($guests) ?></span></h3>
    </div>
    <div class="card-body">
        <div class="filter-bar">
            <form method="GET" class="filter-form">
                <input type="text" name="search" placeholder="Search by name, email, or phone..."
                       value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn-filter">Search</button>
                <?php if ($search): ?>
                <a href="guests.php" class="btn-filter-clear">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="table-wrap">
        <table class="admin-table">
            <thead>
                <tr>
                    <th>Guest Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Total Bookings</th>
                    <th>Total Spent</th>
                    <th>Last Booking</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($guests)): ?>
                <tr><td colspan="7" class="empty-row">No guests found.</td></tr>
                <?php endif; ?>
                <?php foreach ($guests as $g): ?>
                <tr>
                    <td>
                        <div class="guest-name-cell">
                            <div class="guest-avatar"><?= strtoupper(substr($g['guest_name'],0,1)) ?></div>
                            <strong><?= htmlspecialchars($g['guest_name']) ?></strong>
                        </div>
                    </td>
                    <td><?= htmlspecialchars($g['guest_email']) ?></td>
                    <td><?= htmlspecialchars($g['guest_phone']) ?></td>
                    <td><strong><?= $g['total_bookings'] ?></strong></td>
                    <td>₱<?= number_format($g['total_spent'], 0) ?></td>
                    <td><?= date('M d, Y', strtotime($g['last_booking'])) ?></td>
                    <td>
                        <a href="reservations.php?search=<?= urlencode($g['guest_email']) ?>" class="btn-sm">View Bookings</a>
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
