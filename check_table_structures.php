<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();

// Check users table structure
echo "Users table structure:\n";
$result = $conn->query('DESCRIBE users');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}

echo "\nInscriptions table structure:\n";
$result = $conn->query('DESCRIBE inscriptions');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}

echo "\nNotes table structure:\n";
$result = $conn->query('DESCRIBE notes');
$columns = $result->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['Field']}\n";
}
?>