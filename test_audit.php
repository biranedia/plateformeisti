<?php
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin_general';
require_once 'admin_general/audit_log.php';
echo 'Audit log page loaded successfully' . PHP_EOL;
?>