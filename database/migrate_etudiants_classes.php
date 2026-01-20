<?php
/**
 * Migration pour ajouter la table etudiants_classes manquante
 * À exécuter une fois pour mettre à jour la base de données existante
 */

session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';

try {
    // Initialisation de la connexion à la base de données
    $database = new Database();
    $conn = $database->getConnection();

    echo "<h2>Migration - Ajout de la table etudiants_classes</h2>";
    echo "<p>Création de la table etudiants_classes...</p>";

    // Créer la table etudiants_classes
    $create_table_query = "CREATE TABLE IF NOT EXISTS etudiants_classes (
        id SERIAL PRIMARY KEY,
        etudiant_id INTEGER REFERENCES users(id),
        classe_id INTEGER REFERENCES classes(id),
        annee_academique_id INTEGER REFERENCES annees_academiques(id),
        date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        statut ENUM('actif', 'transfere', 'exclu') DEFAULT 'actif'
    )";

    $conn->exec($create_table_query);
    echo "<p style='color: green;'>✓ Table etudiants_classes créée avec succès</p>";

    // Créer un index pour optimiser les performances
    try {
        $index_query = "CREATE INDEX IF NOT EXISTS idx_etudiants_classes_etudiant ON etudiants_classes(etudiant_id)";
        $conn->exec($index_query);
        echo "<p style='color: green;'>✓ Index sur etudiant_id créé</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>- Index déjà existant ou erreur: " . $e->getMessage() . "</p>";
    }

    try {
        $index_query2 = "CREATE INDEX IF NOT EXISTS idx_etudiants_classes_classe ON etudiants_classes(classe_id)";
        $conn->exec($index_query2);
        echo "<p style='color: green;'>✓ Index sur classe_id créé</p>";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>- Index déjà existant ou erreur: " . $e->getMessage() . "</p>";
    }

    echo "<hr><h3>Résumé de la migration</h3>";
    echo "<p style='color: green; font-weight: bold;'>Migration terminée avec succès !</p>";
    echo "<p>La table etudiants_classes est maintenant disponible pour gérer les affectations d'étudiants aux classes.</p><br>";
    echo "<a href='../shared/login.php'>Retour à la page de connexion</a>";

} catch (Exception $e) {
    echo "<h2 style='color: red;'>Erreur de migration</h2>";
    echo "<p style='color: red;'>" . $e->getMessage() . "</p>";
    echo "<a href='../shared/login.php'>Retour à la page de connexion</a>";
}
?>