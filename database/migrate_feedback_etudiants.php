<?php
/**
 * Migration pour créer la table feedback_etudiants
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    // Créer la table feedback_etudiants
    $create_table = "CREATE TABLE IF NOT EXISTS feedback_etudiants (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        etudiant_id BIGINT(20) UNSIGNED NOT NULL,
        cours_id INT(11) NOT NULL,
        enseignant_id BIGINT(20) UNSIGNED NOT NULL,
        note_cours TINYINT(1) NOT NULL,
        note_enseignant TINYINT(1) NOT NULL,
        commentaire_cours TEXT,
        commentaire_enseignant TEXT,
        anonyme TINYINT(1) DEFAULT 0,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (etudiant_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (enseignant_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_cours (cours_id),
        UNIQUE KEY unique_feedback (etudiant_id, cours_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $conn->exec($create_table);
    echo "✓ Table feedback_etudiants créée avec succès\n";
    
} catch (PDOException $e) {
    echo "✗ Erreur lors de la création de la table: " . $e->getMessage() . "\n";
    exit(1);
}
