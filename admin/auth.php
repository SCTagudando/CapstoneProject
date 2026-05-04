<?php
// admin/auth.php — include at top of every admin page
require_once '../includes/config.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$admin_name = $_SESSION['admin_name'] ?? 'Admin';
?>
