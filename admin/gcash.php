<?php
require_once 'auth.php';
$page_title ='GCash Payments';
$db = getDB();
$msg = '';

// Verify / Reject payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gid    = (int)($_POST['gcash_id']  ?? 0);
    $rid    = (int)($_POST['res_id']    ?? 0);
    $action = $_POST['action'] ?? '';
    $notes  = clean($_POST['notes'] ?? '');

    if ($gid && in_array($action, ['Verified','Rejected'])) {
        // Update gcash payment
        $stmt = $db->prepare("UPDATE gcash_payments SET status=?, notes=?, verified_at=NOW() WHERE id=?");
        $stmt->execute([$action, $notes, $gid]);

        if ($action === 'Verified') {
            // Confirm the reservation and mark paid
            $stmt = $db->prepare("UPDATE reservations SET status='Confirmed', payment_status='Paid' WHERE id=?");
            $stmt->execute([$rid]);
            $msg = "success:Payment verified! Reservation has been Confirmed and marked as Paid.";
        } else {
            // Set payment status back
            $stmt = $db->prepare("UPDATE reservations SET payment_status='Unpaid' WHERE id=?");
            $stmt->execute([$rid]);
            $msg = "error:Payment rejected. Reservation remains Pending.";
        }
    }
}

// Fetch all gcash payments with reservation info
$payments = $db->query("
    SELECT g.*, r.booking_code, r.guest_name, r.guest_email, r.guest_phone,
           r.check_in, r.check_out, r.total_price, r.status AS res_status,
           c.name AS cottage_name
    FROM gcash_payments g
    JOIN reservations r ON g.reservation_id = r.id
    JOIN cottages c ON r.cottage_id = c.id
    ORDER BY FIELD(g.status,'Pending','Verified','Rejected'), g.submitted_at DESC
")->fetchAll();

// Stats
$pending_count  = count(array_filter($payments, fn($p) => $p['status']==='Pending'));
$verified_count = count(array_filter($payments, fn($p) => $p['status']==='Verified'));
$total_verified = array_sum(array_column(array_filter($payments, fn($p) => $p['status']==='Verified'), 'amount'));

[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['',''];
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
<?php if ($msg_text): ?>
<div class="alert-<?= $msg_type ?>"><?= $msg_text ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="stats-grid" style="margin-bottom:1.5rem;">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff8e1;">⏳</div>
        <div class="stat-info"><span>Pending Verification</span><strong><?= $pending_count ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#e8f5e9;">✅</div>
        <div class="stat-info"><span>Verified Payments</span><strong><?= $verified_count ?></strong></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f3e5f5;">💵</div>
        <div class="stat-info"><span>Total Verified GCash</span><strong>₱<?= number_format($total_verified, 0) ?></strong></div>
    </div>
</div>

<!-- Payments List -->
<div class="card">
    <div class="card-header">
        <h3>GCash Payment Submissions <span class="count-badge"><?= count($payments) ?></span></h3>
    </div>
    <div class="card-body">
        <?php if (empty($payments)): ?>
        <div class="empty-row" style="padding:3rem;text-align:center;">No GCash payments submitted yet.</div>
        <?php endif; ?>

        <?php foreach ($payments as $p): ?>
        <div class="gcash-payment-card <?= strtolower($p['status']) ?>">
            <div class="gpc-header">
                <div class="gpc-left">
                    <span class="badge badge-<?= $p['status']==='Pending'?'pending':($p['status']==='Verified'?'confirmed':'cancelled') ?>">
                        <?= $p['status'] === 'Pending' ? '⏳ Pending' : ($p['status']==='Verified'?'✅ Verified':'❌ Rejected') ?>
                    </span>
                    <code><?= $p['booking_code'] ?></code>
                    <span class="gpc-submitted">Submitted: <?= date('M d, Y g:i A', strtotime($p['submitted_at'])) ?></span>
                </div>
                <div class="gpc-amount">₱<?= number_format($p['amount'], 2) ?></div>
            </div>

            <div class="gpc-body">
                <div class="gpc-grid">
                    <!-- Guest Info -->
                    <div class="gpc-section">
                        <h5>Guest</h5>
                        <p><strong><?= htmlspecialchars($p['guest_name']) ?></strong></p>
                        <p><?= htmlspecialchars($p['guest_email']) ?></p>
                        <p><?= htmlspecialchars($p['guest_phone']) ?></p>
                    </div>
                    <!-- Booking Info -->
                    <div class="gpc-section">
                        <h5>Booking</h5>
                        <p><?= htmlspecialchars($p['cottage_name']) ?></p>
                        <p><?= date('M d', strtotime($p['check_in'])) ?> → <?= date('M d, Y', strtotime($p['check_out'])) ?></p>
                        <p>Reservation: <span class="badge badge-<?= strtolower($p['res_status']) ?>"><?= $p['res_status'] ?></span></p>
                    </div>
                    <!-- GCash Payment Info -->
                    <div class="gpc-section">
                        <h5>GCash Details</h5>
                        <p>Ref #: <strong><?= htmlspecialchars($p['reference_number']) ?></strong></p>
                        <p>Sender: <strong><?= htmlspecialchars($p['sender_name']) ?></strong></p>
                        <p>Number: <strong><?= htmlspecialchars($p['sender_number']) ?></strong></p>
                        <?php if ($p['proof_image']): ?>
                        <a href="../uploads/<?= htmlspecialchars($p['proof_image']) ?>" target="_blank" class="proof-link">
                            📷 View Screenshot
                        </a>
                        <?php else: ?>
                        <span style="color:#aaa;font-size:0.8rem;">No screenshot uploaded</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($p['notes']): ?>
                    <div class="gpc-section">
                        <h5>Notes</h5>
                        <p><?= htmlspecialchars($p['notes']) ?></p>
                        <?php if ($p['verified_at']): ?>
                        <p style="font-size:0.78rem;color:#888;">Actioned: <?= date('M d, Y g:i A', strtotime($p['verified_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Action form (only for Pending) -->
                <?php if ($p['status'] === 'Pending'): ?>
                <form method="POST" class="gpc-action-form">
                    <input type="hidden" name="gcash_id" value="<?= $p['id'] ?>">
                    <input type="hidden" name="res_id"   value="<?= $p['reservation_id'] ?>">
                    <div class="form-group" style="margin-bottom:0.75rem;">
                        <label style="font-size:0.78rem;font-weight:700;text-transform:uppercase;color:#888;">Admin Notes (optional)</label>
                        <input type="text" name="notes" placeholder="e.g. Verified via GCash app" style="width:100%;border:1.5px solid #e5e8e5;border-radius:6px;padding:0.5rem 0.75rem;font-family:Jost,sans-serif;font-size:0.88rem;">
                    </div>
                    <div class="gpc-btns">
                        <button type="submit" name="action" value="Verified"  class="btn-action approve" onclick="return confirm('Mark this GCash payment as verified and confirm the reservation?')">✅ Verify Payment</button>
                        <button type="submit" name="action" value="Rejected"  class="btn-action reject"  onclick="return confirm('Reject this payment?')">❌ Reject</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
</html>
<style>
.gcash-payment-card {
    border: 1.5px solid var(--border);
    border-radius: 10px;
    margin-bottom: 1.25rem;
    overflow: hidden;
}
.gcash-payment-card.pending  { border-left: 4px solid #f0ad4e; }
.gcash-payment-card.verified { border-left: 4px solid #10b959; }
.gcash-payment-card.rejected { border-left: 4px solid #dc3545; }

.gpc-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.9rem 1.25rem;
    background: #f8f9fa;
    border-bottom: 1px solid var(--border);
    gap: 1rem; flex-wrap: wrap;
}
.gpc-left { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
.gpc-left code { background: #e9ecef; padding: 0.2rem 0.6rem; border-radius: 4px; font-size: 0.85rem; color: #2d6a4f; }
.gpc-submitted { font-size: 0.78rem; color: #888; }
.gpc-amount { font-size: 1.2rem; font-weight: 700; color: #10b959; font-family: 'Playfair Display', serif; }

.gpc-body { padding: 1.25rem; }
.gpc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 1.25rem; margin-bottom: 1rem; }
.gpc-section h5 { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #888; margin-bottom: 0.5rem; }
.gpc-section p { font-size: 0.85rem; color: #444; margin-bottom: 0.2rem; }
.proof-link { display: inline-block; margin-top: 0.4rem; font-size: 0.82rem; color: #2d6a4f; font-weight: 600; }
.proof-link:hover { text-decoration: underline; }

.gpc-action-form { padding-top: 1rem; border-top: 1px solid var(--border); }
.gpc-btns { display: flex; gap: 0.75rem; flex-wrap: wrap; }
</style>

<?php include 'partials/footer.php'; ?>
