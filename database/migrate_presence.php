<?php
/**
 * Migration: Création de la table presence
 * Permet de gérer la présence des étudiants aux cours
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration de la table presence...\n";

try {
    // Vérifier si la table existe déjà
    $check = $conn->query("SHOW TABLES LIKE 'presence'");
    
    if ($check->rowCount() === 0) {
        echo "→ Création de la table presence...\n";
        
        $conn->exec("CREATE TABLE presence (
            id INT AUTO_INCREMENT PRIMARY KEY,
            etudiant_id INT NOT NULL,
            enseignement_id INT NOT NULL,
            date_cours DATE NOT NULL,
            present TINYINT(1) NOT NULL DEFAULT 0,
            enseignant_id INT NOT NULL,
            remarque TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_presence (etudiant_id, enseignement_id, date_cours),
            INDEX idx_date_cours (date_cours),
            INDEX idx_enseignement (enseignement_id),
            INDEX idx_etudiant (etudiant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        echo "✓ Table presence créée avec succès\n";
    } else {
        echo "✓ La table presence existe déjà\n";
    }
    
    echo "✓ Migration terminée avec succès\n";

} catch (PDOException $e) {
    echo "✗ Erreur lors de la migration: " . $e->getMessage() . "\n";
    exit(1);
}
