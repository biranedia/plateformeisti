<?php
// Script pour corriger feedback_etudiants.php

$file = __DIR__ . '/enseignant/feedback_etudiants.php';
$content = file_get_contents($file);

// Remplacement 1: Query principale
$content = str_replace(
    "SELECT f.*, c.nom_cours, u.nom as etudiant_nom, u.prenom as etudiant_prenom,
                           cl.nom_classe, fi.nom_filiere
                    FROM feedback_etudiants f
                    JOIN cours c ON f.cours_id = c.id",
    "SELECT f.*, e.matiere as nom_cours, u.name as etudiant_nom,
                           cl.nom_classe, fi.nom as nom_filiere
                    FROM feedback_etudiants f
                    JOIN enseignements e ON f.enseignement_id = e.id",
    $content
);

$content = str_replace(
    "LEFT JOIN enseignements e ON c.id = e.cours_id
                    LEFT JOIN classes cl ON e.classe_id = cl.id
                    LEFT JOIN filieres fi ON cl.filiere_id = fi.id",
    "JOIN classes cl ON e.classe_id = cl.id
                    JOIN filieres fi ON cl.filiere_id = fi.id",
    $content
);

// Remplacement 2: cours_id -> enseignement_id
$content = str_replace('$cours_id = $feedback[\'cours_id\'];', '$enseignement_id = $feedback[\'enseignement_id\'];', $content);
$content = str_replace('if (!isset($feedbacks_par_cours[$cours_id]))', 'if (!isset($feedbacks_par_cours[$enseignement_id]))', $content);
$content = str_replace('$feedbacks_par_cours[$cours_id] = [', '$feedbacks_par_cours[$enseignement_id] = [', $content);
$content = str_replace('$feedbacks_par_cours[$cours_id][\'feedbacks\'][] = $feedback;', '$feedbacks_par_cours[$enseignement_id][\'feedbacks\'][] = $feedback;', $content);
$content = str_replace('$feedbacks_par_cours[$cours_id][\'total\']++;', '$feedbacks_par_cours[$enseignement_id][\'total\']++;', $content);

// Remplacement 3: Filtrage
$content = str_replace('return $f[\'cours_id\'] == $cours_filter;', 'return $f[\'enseignement_id\'] == $cours_filter;', $content);

// Remplacement 4: Query cours enseignant
$content = str_replace(
    "SELECT DISTINCT c.id, c.nom_cours
                          FROM cours c
                          JOIN enseignements e ON c.id = e.cours_id
                          WHERE e.enseignant_id = :enseignant_id
                          ORDER BY c.nom_cours",
    "SELECT DISTINCT e.id, e.matiere as nom_cours
                          FROM enseignements e
                          WHERE e.enseignant_id = :enseignant_id
                          ORDER BY e.matiere",
    $content
);

file_put_contents($file, $content);
echo "✓ Fichier corrigé avec succès!\n";
