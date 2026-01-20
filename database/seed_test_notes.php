<?php
/**
 * Script pour insérer des notes de test
 */

require_once __DIR__ . '/../config/database.php';

$database = new Database();
$conn = $database->getConnection();

// Récupérer un étudiant existant
$etudiant_query = "SELECT u.id FROM users u 
                   JOIN inscriptions i ON u.id = i.user_id 
                   WHERE u.role = 'etudiant' AND i.statut = 'inscrit' 
                   LIMIT 1";
$etudiant_stmt = $conn->query($etudiant_query);
$etudiant = $etudiant_stmt->fetch(PDO::FETCH_ASSOC);

if (!$etudiant) {
    echo "Aucun étudiant inscrit trouvé. Veuillez d'abord créer des inscriptions.\n";
    exit;
}

$etudiant_id = $etudiant['id'];

// Récupérer ou créer des enseignements
$enseignements_data = [
    ['matiere' => 'Mathématiques', 'enseignant_id' => 1, 'classe_id' => 1, 'volume_horaire' => 40],
    ['matiere' => 'Algorithmique', 'enseignant_id' => 1, 'classe_id' => 1, 'volume_horaire' => 35],
    ['matiere' => 'Bases de données', 'enseignant_id' => 1, 'classe_id' => 1, 'volume_horaire' => 30],
    ['matiere' => 'Programmation Web', 'enseignant_id' => 1, 'classe_id' => 1, 'volume_horaire' => 45],
    ['matiere' => 'Réseaux informatiques', 'enseignant_id' => 1, 'classe_id' => 1, 'volume_horaire' => 30],
];

$enseignement_ids = [];

foreach ($enseignements_data as $ens) {
    // Vérifier si l'enseignement existe déjà
    $check = $conn->prepare("SELECT id FROM enseignements WHERE matiere = :matiere LIMIT 1");
    $check->execute([':matiere' => $ens['matiere']]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $enseignement_ids[] = $existing['id'];
    } else {
        // Insérer l'enseignement
        $insert = $conn->prepare("INSERT INTO enseignements (enseignant_id, classe_id, matiere, volume_horaire) 
                                  VALUES (:enseignant_id, :classe_id, :matiere, :volume_horaire)");
        $insert->execute([
            ':enseignant_id' => $ens['enseignant_id'],
            ':classe_id' => $ens['classe_id'],
            ':matiere' => $ens['matiere'],
            ':volume_horaire' => $ens['volume_horaire']
        ]);
        $enseignement_ids[] = $conn->lastInsertId();
    }
}

// Insérer des notes pour l'étudiant
$types_evaluation = ['devoir', 'examen', 'tp', 'projet'];
$notes_inserted = 0;

foreach ($enseignement_ids as $enseignement_id) {
    // 2-3 notes par matière
    $nb_notes = rand(2, 3);
    for ($i = 0; $i < $nb_notes; $i++) {
        $type = $types_evaluation[array_rand($types_evaluation)];
        $note = rand(10, 20); // Note entre 10 et 20
        
        // Vérifier si une note similaire existe déjà
        $check_note = $conn->prepare("SELECT id FROM notes 
                                       WHERE etudiant_id = :etudiant_id 
                                       AND enseignement_id = :enseignement_id 
                                       AND type_evaluation = :type LIMIT 1");
        $check_note->execute([
            ':etudiant_id' => $etudiant_id,
            ':enseignement_id' => $enseignement_id,
            ':type' => $type
        ]);
        
        if (!$check_note->fetch()) {
            $insert_note = $conn->prepare("INSERT INTO notes (etudiant_id, enseignement_id, note, type_evaluation, commentaire) 
                                           VALUES (:etudiant_id, :enseignement_id, :note, :type, :commentaire)");
            $insert_note->execute([
                ':etudiant_id' => $etudiant_id,
                ':enseignement_id' => $enseignement_id,
                ':note' => $note,
                ':type' => $type,
                ':commentaire' => 'Note de test'
            ]);
            $notes_inserted++;
        }
    }
}

echo "✓ $notes_inserted notes de test insérées pour l'étudiant ID $etudiant_id\n";
echo "✓ Vous pouvez maintenant tester la génération de bulletins\n";
