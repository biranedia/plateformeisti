<?php
require_once 'config/database.php';
require_once 'config/utils.php';

session_start();
$_SESSION['user_id'] = 1;

$db = new Database();
$conn = $db->getConnection();

// Test the addAuditLog function
try {
    addAuditLog($conn, 1, "Test audit log from stats.php", "stats");
    echo "addAuditLog function executed successfully!\n";

    // Check if the log was added
    $result = $conn->query("SELECT COUNT(*) as count FROM audit_logs WHERE action = 'Test audit log from stats.php'");
    $count = $result->fetch(PDO::FETCH_ASSOC);
    echo "Audit log entries with test message: " . $count['count'] . "\n";

} catch (Exception $e) {
    echo "Error testing addAuditLog: " . $e->getMessage() . "\n";
}
?>