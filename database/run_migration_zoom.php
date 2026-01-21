<?php
/**
 * Script d'exécution de la migration pour les séances Zoom
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
    // Créer la table cours si elle n'existe pas (nom minimal pour clé étrangère)
    $conn->exec("CREATE TABLE IF NOT EXISTS cours (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nom_cours VARCHAR(255) NOT NULL,
        description TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Créer la table seances_zoom
    $conn->exec("CREATE TABLE IF NOT EXISTS seances_zoom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titre VARCHAR(255) NOT NULL,
        description TEXT,
        date_seance DATE NOT NULL,
        heure_debut TIME NOT NULL,
        duree_minutes INT NOT NULL DEFAULT 60,
        zoom_url VARCHAR(500) NOT NULL,
        zoom_id VARCHAR(50) NOT NULL,
        zoom_password VARCHAR(50),
        video_url VARCHAR(500),
        classe_id INT,
        cours_id INT,
        enseignant_id INT NOT NULL,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (classe_id),
        INDEX (cours_id),
        INDEX (enseignant_id),
        INDEX (date_seance)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "✓ Table seances_zoom créée avec succès<br>";

    // Créer la table user_vues_zoom
    $conn->exec("CREATE TABLE IF NOT EXISTS user_vues_zoom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seance_id INT NOT NULL,
        user_id INT NOT NULL,
        date_vue TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY (seance_id, user_id),
        INDEX (seance_id),
        INDEX (user_id),
        INDEX (date_vue)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    echo "✓ Table user_vues_zoom créée avec succès<br>";

    // Vérifier et créer le dossier uploads/zoom
    if (!is_dir(__DIR__ . '/../uploads/zoom')) {
        mkdir(__DIR__ . '/../uploads/zoom', 0755, true);
        echo "✓ Dossier uploads/zoom créé<br>";
    }

    // Vérifier et créer le dossier uploads/profils
    if (!is_dir(__DIR__ . '/../uploads/profils')) {
        mkdir(__DIR__ . '/../uploads/profils', 0755, true);
        echo "✓ Dossier uploads/profils créé<br>";
    }

    echo "<div style='color: green; font-weight: bold; margin-top: 20px;'>";
    echo "✓ Migration réussie !<br>";
    echo "Les tables pour les séances Zoom et les uploads de profils ont été créées avec succès.";
    echo "</div>";

} catch (PDOException $e) {
    echo "<div style='color: red; font-weight: bold;'>";
    echo "✗ Erreur lors de la migration: " . $e->getMessage();
    echo "</div>";
}
?>
