<?php
/**
 * Migration: Correction de la table emplois_du_temps
 * Ajoute les colonnes manquantes et modifie la structure
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration de la table emplois_du_temps...\n";

try {
    // Vérifier si les colonnes existent déjà
    $check = $conn->query("SHOW COLUMNS FROM emplois_du_temps LIKE 'jour_semaine'");
    
    if ($check->rowCount() === 0) {
        echo "→ Ajout des colonnes manquantes...\n";
        
        // Modifier la structure de la table
        $conn->exec("ALTER TABLE emplois_du_temps 
                     ADD COLUMN jour_semaine INT DEFAULT 1,
                     ADD COLUMN creneau_horaire VARCHAR(20) DEFAULT '08:00-09:30',
                     ADD COLUMN annee_academique VARCHAR(20),
                     CHANGE COLUMN jour jour_old ENUM('Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'),
                     CHANGE COLUMN matiere matiere_nom VARCHAR(100)");
        
        // Migrer les données existantes si nécessaire
        $conn->exec("UPDATE emplois_du_temps SET 
                     jour_semaine = CASE jour_old
                         WHEN 'Lundi' THEN 1
                         WHEN 'Mardi' THEN 2
                         WHEN 'Mercredi' THEN 3
                         WHEN 'Jeudi' THEN 4
                         WHEN 'Vendredi' THEN 5
                         WHEN 'Samedi' THEN 6
                         WHEN 'Dimanche' THEN 7
                         ELSE 1
                     END WHERE jour_old IS NOT NULL");
        
        // Supprimer l'ancienne colonne jour
        $conn->exec("ALTER TABLE emplois_du_temps DROP COLUMN jour_old");
        
        echo "✓ Colonnes ajoutées avec succès\n";
    } else {
        echo "✓ Les colonnes existent déjà\n";
    }
    
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
