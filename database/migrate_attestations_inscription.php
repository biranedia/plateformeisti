<?php
/**
 * Migration: Création de la table attestations_inscription
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration: Création de la table attestations_inscription...\n";

try {
    // Vérifier si la table existe
    $check = $conn->query("SHOW TABLES LIKE 'attestations_inscription'");
    
    if ($check->rowCount() === 0) {
        echo "→ Création de la table attestations_inscription...\n";
        
        $conn->exec("CREATE TABLE attestations_inscription (
            id INT PRIMARY KEY AUTO_INCREMENT,
            inscription_id INT NOT NULL REFERENCES inscriptions(id),
            numero_attestation VARCHAR(50) UNIQUE NULL,
            date_emission TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_annulation TIMESTAMP NULL,
            statut ENUM('active', 'annulee') DEFAULT 'active',
            genere_par INT REFERENCES users(id),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_inscription (inscription_id),
            INDEX idx_numero (numero_attestation),
            INDEX idx_statut (statut)
        )");
        
        echo "✓ Table attestations_inscription créée avec succès\n";
    } else {
        echo "✓ La table attestations_inscription existe déjà\n";
    }
    
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
