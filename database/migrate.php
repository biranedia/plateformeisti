<?php
/**
 * Script de migration automatique des colonnes manquantes dans la table users
 * À exécuter une fois pour mettre à jour la base de données existante
 */

// Démarrage de la session (si nécessaire pour l'authentification)
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';

try {
    // Initialisation de la connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();

    echo "<h2>Migration automatique de la base de données</h2>";
    echo "<p>Ajout des colonnes manquantes à la table users...</p>";

    // Liste des colonnes à ajouter
    $columns = [
        "matricule VARCHAR(20) UNIQUE",
        "date_naissance DATE",
        "telephone VARCHAR(20)",
        "role ENUM('admin', 'resp_dept', 'resp_filiere', 'resp_classe', 'etudiant', 'enseignant', 'agent_admin') DEFAULT 'etudiant'",
        "created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP",
        "updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    ];

    $success_count = 0;
    $errors = [];

    foreach ($columns as $column_def) {
        // Extraire le nom de la colonne
        $column_name = explode(' ', $column_def)[0];

        try {
            // Vérifier si la colonne existe déjà
            $check_query = "SHOW COLUMNS FROM users LIKE '$column_name'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute();

            if ($check_stmt->rowCount() == 0) {
                // La colonne n'existe pas, l'ajouter
                $alter_query = "ALTER TABLE users ADD COLUMN $column_def";
                $conn->exec($alter_query);
                echo "<p style='color: green;'>✓ Colonne '$column_name' ajoutée avec succès</p>";
                $success_count++;
            } else {
                echo "<p style='color: blue;'>- Colonne '$column_name' existe déjà</p>";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur lors de l'ajout de la colonne '$column_name': " . $e->getMessage();
            echo "<p style='color: red;'>✗ Erreur avec la colonne '$column_name': " . $e->getMessage() . "</p>";
        }
    }

    // Créer l'index sur matricule si nécessaire
    try {
        $index_query = "CREATE INDEX idx_users_matricule ON users(matricule)";
        $conn->exec($index_query);
        echo "<p style='color: green;'>✓ Index sur matricule créé</p>";
    } catch (Exception $e) {
        echo "<p style='color: blue;'>- Index sur matricule existe déjà ou erreur: " . $e->getMessage() . "</p>";
    }

    echo "<hr>";
    echo "<h3>Résumé de la migration</h3>";
    echo "<p>Colonnes ajoutées/modifiées: $success_count</p>";

    if (empty($errors)) {
        echo "<p style='color: green; font-weight: bold;'>Migration terminée avec succès !</p>";
        echo "<p>Vous pouvez maintenant utiliser toutes les fonctionnalités de la plateforme.</p>";
    } else {
        echo "<p style='color: orange;'>Migration partiellement réussie. Certaines erreurs ont été rencontrées:</p>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li>$error</li>";
        }
        echo "</ul>";
    }

    echo "<br><a href='../shared/login.php'>Retour à la page de connexion</a>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>Erreur de migration</h2>";
    echo "<p>Une erreur critique s'est produite: " . $e->getMessage() . "</p>";
    echo "<p>Vérifiez la configuration de votre base de données et réessayez.</p>";
}
?>