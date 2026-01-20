<?php
/**
 * Migration: Création de la table documents_inscription
 * Permet aux étudiants de soumettre leurs relevés et diplôme lors de l'inscription
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration: Création de la table documents_inscription...\n";

try {
    // Vérifier si la table existe
    $check = $conn->query("SHOW TABLES LIKE 'documents_inscription'");
    
    if ($check->rowCount() === 0) {
        echo "→ Création de la table documents_inscription...\n";
        
        $conn->exec("CREATE TABLE documents_inscription (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL REFERENCES users(id),
            inscription_id INT REFERENCES inscriptions(id),
            type_document ENUM('releve_bac', 'diplome_bac', 'certificat', 'autre') NOT NULL,
            nom_fichier VARCHAR(255) NOT NULL,
            chemin_fichier VARCHAR(500) NOT NULL,
            type_mime VARCHAR(100),
            taille_fichier INT,
            date_upload TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            statut ENUM('soumis', 'valide', 'rejete') DEFAULT 'soumis',
            commentaire_validation TEXT NULL,
            valide_par INT REFERENCES users(id),
            date_validation TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user (user_id),
            INDEX idx_inscription (inscription_id),
            INDEX idx_type (type_document),
            INDEX idx_statut (statut)
        )");
        
        echo "✓ Table documents_inscription créée avec succès\n";
    } else {
        echo "✓ La table documents_inscription existe déjà\n";
    }
    
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
?>
