<?php
// Migration: create document_templates table for dynamic templates
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    $sql = "CREATE TABLE IF NOT EXISTS document_templates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type ENUM('certificat_scolarite','bulletin') NOT NULL,
        name VARCHAR(150) NOT NULL,
        content_html LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_type_name (type, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

    $conn->exec($sql);
    echo "Table document_templates vérifiée/créée avec succès.";
} catch (Exception $e) {
    echo "Erreur lors de la migration document_templates: " . $e->getMessage();
}
