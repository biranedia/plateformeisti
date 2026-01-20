<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Structure de la table feedbacks:\n";
echo str_repeat("=", 50) . "\n\n";

$query = "DESCRIBE feedbacks";
$stmt = $conn->query($query);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $column) {
    echo sprintf("%-30s: %s\n", $column['Field'], $column['Type']);
}
