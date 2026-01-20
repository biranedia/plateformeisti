<?php
/**
 * Migration: Modification du matricule pour auto-génération
 * Le matricule sera unique et généré automatiquement au format ISTI-YYYY-NNNN
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Migration: Configuration du matricule auto-généré...\n";

try {
    // Vérifier si la colonne matricule existe et est UNIQUE
    echo "→ Modification de la colonne matricule pour être UNIQUE...\n";
    
    $conn->exec("ALTER TABLE users 
                MODIFY matricule VARCHAR(20) UNIQUE NULL");
    
    echo "✓ Colonne matricule configurée avec contrainte UNIQUE\n";
    
    // Générer des matricules pour les utilisateurs existants qui n'en ont pas
    echo "→ Génération de matricules pour les utilisateurs existants...\n";
    
    $query = "SELECT id, role, created_at FROM users WHERE matricule IS NULL OR matricule = ''";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $count = 0;
    foreach ($users as $user) {
        // Générer un matricule basé sur l'année de création et l'ID
        $year = date('Y', strtotime($user['created_at']));
        $numero = str_pad($user['id'], 4, '0', STR_PAD_LEFT);
        $matricule = "ISTI-{$year}-{$numero}";
        
        $update = "UPDATE users SET matricule = :matricule WHERE id = :id";
        $update_stmt = $conn->prepare($update);
        $update_stmt->bindParam(':matricule', $matricule);
        $update_stmt->bindParam(':id', $user['id']);
        $update_stmt->execute();
        $count++;
    }
    
    echo "✓ {$count} matricule(s) généré(s) pour les utilisateurs existants\n";
    echo "✓ Migration terminée avec succès\n";
    
} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}
?>
