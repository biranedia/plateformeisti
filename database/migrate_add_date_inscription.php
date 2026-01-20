<?php
/**
 * Migration: Ajout de la colonne date_inscription à la table inscriptions
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration: Ajout de la colonne date_inscription...\n";

try {
    // Vérifier si la colonne existe déjà
    $check = $conn->query("DESCRIBE inscriptions");
    $columns = $check->fetchAll(PDO::FETCH_ASSOC);
    $column_names = array_column($columns, 'Field');
    
    if (!in_array('date_inscription', $column_names)) {
        echo "→ Ajout de la colonne date_inscription...\n";
        
        $conn->exec("ALTER TABLE inscriptions 
                    ADD COLUMN date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER statut");
        
        echo "✓ Colonne date_inscription ajoutée avec succès\n";
    } else {
        echo "✓ La colonne date_inscription existe déjà\n";
    }
    
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
?>
