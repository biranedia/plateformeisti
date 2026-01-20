<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Tables existantes dans la base de données:\n";
echo str_repeat("=", 50) . "\n\n";

$query = "SHOW TABLES";
$stmt = $conn->query($query);
$tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    echo "- " . $table . "\n";
}

echo "\n" . str_repeat("=", 50) . "\n";
echo "Total: " . count($tables) . " tables\n";
