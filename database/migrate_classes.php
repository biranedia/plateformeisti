<?php
/**
 * Migration pour ajouter les colonnes manquantes à la table classes
 * À exécuter une fois pour mettre à jour la base de données existante
 */

session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';

try {
    // Initialisation de la connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();

    echo "<h2>Migration des colonnes manquantes - Table classes</h2>";
    echo "<p>Ajout des colonnes manquantes à la table classes...</p>";

    // Liste des colonnes à ajouter
    $columns = [
        "nom_classe VARCHAR(100) NOT NULL DEFAULT 'Classe'",
        "capacite_max INTEGER DEFAULT 30",
        "annee_academique_id INTEGER",
        "description TEXT",
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
            $check_query = "SHOW COLUMNS FROM classes LIKE '$column_name'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute();

            if ($check_stmt->rowCount() == 0) {
                // La colonne n'existe pas, l'ajouter
                $alter_query = "ALTER TABLE classes ADD COLUMN $column_def";
                $conn->exec($alter_query);
                echo "<p style='color: green;'>✓ Colonne '$column_name' ajoutée avec succès</p>";
                $success_count++;
            } else {
                echo "<p style='color: blue;'>- Colonne '$column_name' existe déjà</p>";
            }
        } catch (Exception $e) {
            $errors[] = "Erreur pour la colonne '$column_name': " . $e->getMessage();
            echo "<p style='color: red;'>✗ Erreur pour la colonne '$column_name': " . $e->getMessage() . "</p>";
        }
    }

    // Mettre à jour les valeurs par défaut pour nom_classe (concaténation de niveau et nom de filière)
    try {
        $update_query = "UPDATE classes c
                        LEFT JOIN filieres f ON c.filiere_id = f.id
                        SET c.nom_classe = CONCAT(c.niveau, ' ', COALESCE(f.nom, ''))
                        WHERE c.nom_classe = 'Classe' OR c.nom_classe IS NULL";
        $conn->exec($update_query);
        echo "<p style='color: green;'>✓ Noms de classes mis à jour</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>- Erreur lors de la mise à jour des noms de classes: " . $e->getMessage() . "</p>";
    }

    // Ajouter les contraintes de clés étrangères si elles n'existent pas
    try {
        // Vérifier si la table annees_academiques existe
        $check_table_query = "SHOW TABLES LIKE 'annees_academiques'";
        $table_exists = $conn->query($check_table_query)->rowCount() > 0;

        if ($table_exists) {
            // Ajouter la contrainte de clé étrangère pour annee_academique_id
            $fk_query = "ALTER TABLE classes ADD CONSTRAINT fk_classes_annee_academique
                        FOREIGN KEY (annee_academique_id) REFERENCES annees_academiques(id)";
            $conn->exec($fk_query);
            echo "<p style='color: green;'>✓ Contrainte de clé étrangère ajoutée pour annee_academique_id</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color: orange;'>- Erreur lors de l'ajout de la contrainte FK: " . $e->getMessage() . "</p>";
    }

    echo "<hr><h3>Résumé de la migration</h3>";
    echo "<p>Colonnes ajoutées/modifiées: $success_count</p>";
    if (!empty($errors)) {
        echo "<p style='color: red;'>Erreurs rencontrées: " . count($errors) . "</p>";
        foreach ($errors as $error) {
            echo "<p style='color: red;'>- $error</p>";
        }
    }

    if ($success_count > 0 || empty($errors)) {
        echo "<p style='color: green; font-weight: bold;'>Migration terminée avec succès !</p>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>Migration terminée avec des erreurs.</p>";
    }

    echo "<p>Vous pouvez maintenant utiliser toutes les fonctionnalités de gestion des classes.</p><br>";
    echo "<a href='../shared/login.php'>Retour à la page de connexion</a>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>Erreur de migration</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<a href='../shared/login.php'>Retour à la page de connexion</a>";
}
?>