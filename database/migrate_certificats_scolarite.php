<?php
// Migration script to create certificats_scolarite table if missing
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    $conn->exec(
        "CREATE TABLE IF NOT EXISTS certificats_scolarite (
            id INT AUTO_INCREMENT PRIMARY KEY,
            inscription_id INT NOT NULL,
            numero_certificat VARCHAR(50) NOT NULL,
            date_emission TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_annulation TIMESTAMP NULL,
            statut ENUM('active','annule') DEFAULT 'active',
            genere_par INT NULL,
            INDEX idx_certificat_inscription (inscription_id),
            INDEX idx_certificat_numero (numero_certificat)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    echo "Table certificats_scolarite vérifiée/créée avec succès.";
} catch (Exception $e) {
    echo "Erreur lors de la migration: " . $e->getMessage();
}
