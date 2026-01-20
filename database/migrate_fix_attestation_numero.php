<?php
/**
 * Migration: Modification de la colonne numero_attestation pour permettre NULL
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration: Modification de la colonne numero_attestation...\n";

try {
    // Modifier la colonne numero_attestation pour permettre NULL
    echo "→ Modification de la colonne numero_attestation pour permettre NULL...\n";
    
    $conn->exec("ALTER TABLE attestations_inscription 
                MODIFY numero_attestation VARCHAR(50) UNIQUE NULL");
    
    echo "✓ Colonne numero_attestation modifiée avec succès\n";
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
?>
