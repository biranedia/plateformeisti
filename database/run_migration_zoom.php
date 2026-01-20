<?php
/**
 * Script d'exécution de la migration pour les séances Zoom
 */

require_once '../config/database.php';

$database = new Database();
$conn = $database->getConnection();

try {
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
        FOREIGN KEY (classe_id) REFERENCES classes(id) ON DELETE CASCADE,
        FOREIGN KEY (cours_id) REFERENCES cours(id) ON DELETE SET NULL,
        FOREIGN KEY (enseignant_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX (date_seance),
        INDEX (enseignant_id)
    )");

    echo "✓ Table seances_zoom créée avec succès<br>";

    // Créer la table user_vues_zoom
    $conn->exec("CREATE TABLE IF NOT EXISTS user_vues_zoom (
        id INT AUTO_INCREMENT PRIMARY KEY,
        seance_id INT NOT NULL,
        user_id INT NOT NULL,
        date_vue TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (seance_id) REFERENCES seances_zoom(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY (seance_id, user_id),
        INDEX (date_vue)
    )");

    echo "✓ Table user_vues_zoom créée avec succès<br>";

    // Vérifier et créer le dossier uploads/zoom
    if (!is_dir('../uploads/zoom')) {
        mkdir('../uploads/zoom', 0755, true);
        echo "✓ Dossier uploads/zoom créé<br>";
    }

    // Vérifier et créer le dossier uploads/profils
    if (!is_dir('../uploads/profils')) {
        mkdir('../uploads/profils', 0755, true);
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
