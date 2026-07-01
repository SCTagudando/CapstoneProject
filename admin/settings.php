<?php
// admin/settings.php — Admin account settings (change password)
require_once 'auth.php';
$page_title = 'Settings';
$db  = getDB();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND role = 'admin'");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($current_password, $admin['password'])) {
        $msg = "error:Current password is incorrect.";
    } elseif (strlen($new_password) < 6) {
        $msg = "error:New password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $msg = "error:New password and confirmation do not match.";
    } elseif (password_verify($new_password, $admin['password'])) {
        $msg = "error:New password must be different from your current password.";
    } else {
        $hashed = password_hash($new_password, PASSWORD_DEFAULT);
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([$hashed, $admin['id']]);
        $msg = "success:Password updated successfully.";
    }
}

[$msg_type, $msg_text] = $msg ? explode(':', $msg, 2) : ['', ''];
include 'partials/header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings — S-Five Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<?php if ($msg_text): ?>
<div class="alert-<?= $msg_type ?>"><?= htmlspecialchars($msg_text) ?></div>
<?php endif; ?>

<div class="card" style="max-width:520px;">
    <div class="card-header">
        <h3>Change Password</h3>
    </div>
    <div class="card-body">
        <form method="POST" class="admin-form">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" placeholder="At least 6 characters" required minlength="6" autocomplete="new-password">
            </div>
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" placeholder="Re-enter new password" required minlength="6" autocomplete="new-password">
            </div>
            <button type="submit" class="btn-save">Update Password</button>
        </form>
    </div>
</div>

<?php include 'partials/footer.php'; ?>
