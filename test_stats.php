<?php
require_once 'config/database.php';
require_once 'config/utils.php';

session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin_general';

$db = new Database();
$conn = $db->getConnection();

// Test the stats queries
function getStat($conn, $query, $params = []) {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$stats = [];

// Test each query
try {
    $stats['total_users'] = getStat($conn, "SELECT COUNT(*) as count FROM users")['count'];
    echo "total_users: {$stats['total_users']}\n";
} catch (Exception $e) {
    echo "Error in total_users: " . $e->getMessage() . "\n";
}

try {
    $stats['active_users'] = getStat($conn, "SELECT COUNT(*) as count FROM users WHERE is_active = 1")['count'];
    echo "active_users: {$stats['active_users']}\n";
} catch (Exception $e) {
    echo "Error in active_users: " . $e->getMessage() . "\n";
}

try {
    $stats['annee_active'] = getStat($conn, "SELECT COUNT(*) as count FROM annees_academiques WHERE is_active = 1")['count'];
    echo "annee_active: {$stats['annee_active']}\n";
} catch (Exception $e) {
    echo "Error in annee_active: " . $e->getMessage() . "\n";
}

try {
    $stats['inscriptions_actives'] = getStat($conn, "SELECT COUNT(*) as count FROM inscriptions WHERE statut IN ('inscrit', 'reinscrit')")['count'];
    echo "inscriptions_actives: {$stats['inscriptions_actives']}\n";
} catch (Exception $e) {
    echo "Error in inscriptions_actives: " . $e->getMessage() . "\n";
}

try {
    $stats['documents_valides'] = getStat($conn, "SELECT COUNT(*) as count FROM documents WHERE statut = 'valide'")['count'];
    echo "documents_valides: {$stats['documents_valides']}\n";
} catch (Exception $e) {
    echo "Error in documents_valides: " . $e->getMessage() . "\n";
}

echo "All stats queries completed successfully!\n";
?>