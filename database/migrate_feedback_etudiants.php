<?php
/**
 * Migration pour créer la table feedback_etudiants
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    // Créer la table si elle n'existe pas
    $create_table = "CREATE TABLE IF NOT EXISTS feedback_etudiants (
        id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        etudiant_id BIGINT(20) UNSIGNED NOT NULL,
        enseignement_id INT(11) NULL,
        enseignant_id BIGINT(20) UNSIGNED NOT NULL,
        note_cours TINYINT(1) NOT NULL,
        note_enseignant TINYINT(1) NOT NULL,
        commentaire_cours TEXT,
        commentaire_enseignant TEXT,
        anonyme TINYINT(1) DEFAULT 0,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_enseignement (enseignement_id),
        UNIQUE KEY unique_feedback (etudiant_id, enseignement_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $conn->exec($create_table);

    // Si la table existait déjà avec cours_id, la migrer vers enseignement_id
    $has_enseignement = $conn->query("SHOW COLUMNS FROM feedback_etudiants LIKE 'enseignement_id'")->rowCount() > 0;
    $has_cours = $conn->query("SHOW COLUMNS FROM feedback_etudiants LIKE 'cours_id'")->rowCount() > 0;

    if (!$has_enseignement && $has_cours) {
        echo "→ Ajout de la colonne enseignement_id et migration des données...\n";
        // Ajouter la colonne
        $conn->exec("ALTER TABLE feedback_etudiants ADD COLUMN enseignement_id INT(11) NULL AFTER etudiant_id");
        // Copier les valeurs existantes depuis cours_id
        $conn->exec("UPDATE feedback_etudiants SET enseignement_id = cours_id WHERE cours_id IS NOT NULL");
    }

    // S'assurer que la clé unique est sur (etudiant_id, enseignement_id)
    try {
        $idxUnique = $conn->query("SHOW INDEX FROM feedback_etudiants WHERE Key_name = 'unique_feedback'");
        if ($idxUnique->rowCount() > 0) {
            $conn->exec("ALTER TABLE feedback_etudiants DROP INDEX unique_feedback");
        }
    } catch (PDOException $e) {
        echo "⚠️ Impossible de supprimer l'index unique_feedback: " . $e->getMessage() . " (ignoré)\n";
    }

    $idxUniqueNew = $conn->query("SHOW INDEX FROM feedback_etudiants WHERE Key_name = 'unique_feedback_enseignement'");
    if ($idxUniqueNew->rowCount() === 0) {
        $conn->exec("ALTER TABLE feedback_etudiants ADD UNIQUE KEY unique_feedback_enseignement (etudiant_id, enseignement_id)");
    }

    // Ajouter l'index sur enseignement_id si absent
    $idxEns = $conn->query("SHOW INDEX FROM feedback_etudiants WHERE Key_name = 'idx_enseignement'");
    if ($idxEns->rowCount() === 0) {
        try {
            $conn->exec("ALTER TABLE feedback_etudiants ADD INDEX idx_enseignement (enseignement_id)");
        } catch (PDOException $e) {
            echo "⚠️ Impossible d'ajouter l'index idx_enseignement: " . $e->getMessage() . " (ignoré)\n";
        }
    }

    // Ne pas supprimer cours_id pour éviter les contraintes résiduelles; on garde la colonne si présente

    echo "✓ Table feedback_etudiants migrée avec succès\n";
    
} catch (PDOException $e) {
    echo "✗ Erreur lors de la création de la table: " . $e->getMessage() . "\n";
    exit(1);
}
