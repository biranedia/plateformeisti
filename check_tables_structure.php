<?php
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Vérification des tables...\n\n";

// Vérifier l'existence des tables
$tables = ['users', 'enseignements'];
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    echo "Table $table: " . ($result->rowCount() > 0 ? "✓ EXISTS" : "✗ NOT FOUND") . "\n";
}

echo "\n";

// Vérifier la structure de users
echo "Structure de 'users':\n";
$result = $conn->query("DESCRIBE users");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['Field']} ({$row['Type']}) {$row['Key']}\n";
}

echo "\n";

// Vérifier la structure de enseignements si elle existe
$result = $conn->query("SHOW TABLES LIKE 'enseignements'");
if ($result->rowCount() > 0) {
    echo "Structure de 'enseignements':\n";
    $result = $conn->query("DESCRIBE enseignements");
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['Field']} ({$row['Type']}) {$row['Key']}\n";
    }
} else {
    echo "Table 'enseignements' n'existe pas!\n";
}
