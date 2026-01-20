<?php
require_once 'config/database.php';
require_once 'config/utils.php';

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin_general';

$db = new Database();
$conn = $db->getConnection();

// Test the role distribution query
try {
    $role_distribution = $conn->query("
        SELECT ur.role, COUNT(u.id) as count
        FROM users u
        JOIN user_roles ur ON u.id = ur.user_id
        GROUP BY ur.role
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo "Role distribution query successful! Found " . count($role_distribution) . " roles:\n";
    foreach ($role_distribution as $role) {
        echo "- {$role['role']}: {$role['count']} users\n";
    }
} catch (Exception $e) {
    echo "Role distribution query error: " . $e->getMessage() . "\n";
}

// Test a few basic stats
$tests = [
    "total_users" => "SELECT COUNT(*) as count FROM users",
    "total_departements" => "SELECT COUNT(*) as count FROM departements",
    "total_filieres" => "SELECT COUNT(*) as count FROM filieres",
    "total_audit_logs" => "SELECT COUNT(*) as count FROM audit_logs"
];

foreach ($tests as $name => $query) {
    try {
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "$name: {$result['count']}\n";
    } catch (Exception $e) {
        echo "$name error: " . $e->getMessage() . "\n";
    }
}
?>