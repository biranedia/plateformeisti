<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin_general';

try {
    require_once 'admin_general/stats.php';
    echo 'Stats page loaded successfully!' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>