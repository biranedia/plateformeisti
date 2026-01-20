<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query('DESCRIBE users');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Colonnes de la table users:\n";
foreach ($columns as $col) {
    echo "  - {$col['Field']}: {$col['Type']}\n";
}
?>
