<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check departements table structure
echo "Departements table structure:\n";
$result = $conn->query('DESCRIBE departements');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}

echo "\nFilieres table structure:\n";
$result = $conn->query('DESCRIBE filieres');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}

echo "\nClasses table structure:\n";
$result = $conn->query('DESCRIBE classes');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}
?>