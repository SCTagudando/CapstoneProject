<?php
// admin/login.php
require_once '../includes/config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['admin_id']   = $user['id'];
        $_SESSION['admin_name'] = $user['name'];
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid email or password.';
    }
}

// Redirect if already logged in
if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — S-Five Resort</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="admin.css">
</head>
<body class="login-body">
    <div class="login-wrap">
        <div class="login-card">
            <div class="login-logo">
                <span>🌴</span>
                <h1>S-Five Resort</h1>
                <p>Admin Panel</p>
            </div>
            <?php if ($error): ?>
            <div class="alert-error"><?= $error ?></div>
            <?php endif; ?>
            <form method="POST" class="login-form">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" placeholder="admin@sfive.com" required
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
                <button type="submit" class="btn-login">Sign In →</button>
            </form>
            <a href="../index.php" class="back-link">← Back to Resort Website</a>
        </div>
    </div>
</body>
</html>
