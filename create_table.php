<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$sql = "CREATE TABLE annees_academiques (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_academique VARCHAR(20) NOT NULL UNIQUE,
    date_debut DATE NOT NULL,
    date_fin DATE NOT NULL,
    is_active BOOLEAN DEFAULT false,
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)";
try {
    $conn->exec($sql);
    echo 'Table annees_academiques created successfully' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error creating table: ' . $e->getMessage() . PHP_EOL;
}
?>