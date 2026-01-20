<?php
require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();

function printTable($conn, $table) {
    $stmt = $conn->prepare("SHOW TABLES LIKE :t");
    $stmt->execute([':t' => $table]);
    if ($stmt->rowCount() === 0) {
        echo "Table '$table' inexistante\n";
        return;
    }
    echo "Structure de $table:\n";
    $desc = $conn->query("DESCRIBE $table")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($desc as $col) {
        echo "- {$col['Field']} {$col['Type']} {$col['Null']} {$col['Key']}\n";
    }
    echo "\n";
}

printTable($conn, 'emplois_du_temps');
