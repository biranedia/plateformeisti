<?php
/**
 * Gestion de l'emploi du temps - Responsable de filière
 * Planification et gestion des cours pour la filière
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_filiere')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de filière pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération de la filière gérée
$filiere_query = "SELECT * FROM filieres WHERE responsable_id = :user_id";
$filiere_stmt = $conn->prepare($filiere_query);
$filiere_stmt->bindParam(':user_id', $user_id);
$filiere_stmt->execute();
$filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de filière assignée
if (!$filiere) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucune filière assignée</h2>
            <p>Vous n'êtes pas encore assigné à une filière. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Messages de succès ou d'erreur
$messages = [];

// Jours de la semaine et créneaux horaires
$jours_semaine = [
    1 => 'Lundi',
    2 => 'Mardi',
    3 => 'Mercredi',
    4 => 'Jeudi',
    5 => 'Vendredi',
    6 => 'Samedi'
];

$creneaux_horaires = [
    '08:00-09:30' => '08:00 - 09:30',
    '09:30-11:00' => '09:30 - 11:00',
    '11:00-12:30' => '11:00 - 12:30',
    '13:00-14:30' => '13:00 - 14:30',
    '14:30-16:00' => '14:30 - 16:00',
    '16:00-17:30' => '16:00 - 17:30',
    '17:30-19:00' => '17:30 - 19:00'
];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'add_cours') {
            $matiere_id = (int)$_POST['matiere_id'];
            $enseignant_id = (int)$_POST['enseignant_id'];
            $classe_id = (int)$_POST['classe_id'];
            $jour_semaine = (int)$_POST['jour_semaine'];
            $creneau_horaire = sanitize($_POST['creneau_horaire']);
            $salle = sanitize($_POST['salle']);
            $type_cours = sanitize($_POST['type_cours']);
            $annee_academique_id = (int)$_POST['annee_academique_id'];

            // Validation
            if (empty($matiere_id) || empty($enseignant_id) || empty($classe_id) || empty($jour_semaine) || empty($creneau_horaire)) {
                $messages[] = ['type' => 'error', 'text' => 'Tous les champs obligatoires doivent être remplis.'];
            } else {
                try {
                    // Vérifier les conflits d'horaire
                    $conflict_query = "SELECT c.*, m.nom_matiere, u.nom as enseignant_nom, u.prenom as enseignant_prenom, cl.nom_classe
                                     FROM emploi_du_temps c
                                     JOIN matieres m ON c.matiere_id = m.id
                                     JOIN users u ON c.enseignant_id = u.id
                                     JOIN classes cl ON c.classe_id = cl.id
                                     WHERE c.jour_semaine = :jour AND c.creneau_horaire = :creneau
                                     AND c.annee_academique_id = :annee
                                     AND (c.enseignant_id = :enseignant OR c.classe_id = :classe OR c.salle = :salle)";

                    $conflict_stmt = $conn->prepare($conflict_query);
                    $conflict_stmt->bindParam(':jour', $jour_semaine);
                    $conflict_stmt->bindParam(':creneau', $creneau_horaire);
                    $conflict_stmt->bindParam(':annee', $annee_academique_id);
                    $conflict_stmt->bindParam(':enseignant', $enseignant_id);
                    $conflict_stmt->bindParam(':classe', $classe_id);
                    $conflict_stmt->bindParam(':salle', $salle);
                    $conflict_stmt->execute();
                    $conflicts = $conflict_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $conflict_messages = [];
                    foreach ($conflicts as $conflict) {
                        if ($conflict['enseignant_id'] == $enseignant_id) {
                            $conflict_messages[] = "L'enseignant " . $conflict['enseignant_nom'] . ' ' . $conflict['enseignant_prenom'] . " a déjà un cours";
                        }
                        if ($conflict['classe_id'] == $classe_id) {
                            $conflict_messages[] = "La classe " . $conflict['nom_classe'] . " a déjà un cours";
                        }
                        if ($conflict['salle'] == $salle) {
                            $conflict_messages[] = "La salle " . $salle . " est déjà occupée";
                        }
                    }

                    if (!empty($conflict_messages)) {
                        $messages[] = ['type' => 'error', 'text' => 'Conflits détectés: ' . implode(', ', array_unique($conflict_messages))];
                    } else {
                        // Ajouter le cours
                        $query = "INSERT INTO emploi_du_temps (matiere_id, enseignant_id, classe_id, jour_semaine, creneau_horaire, salle, type_cours, annee_academique_id, filiere_id, created_at)
                                 VALUES (:matiere, :enseignant, :classe, :jour, :creneau, :salle, :type, :annee, :filiere, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':matiere', $matiere_id);
                        $stmt->bindParam(':enseignant', $enseignant_id);
                        $stmt->bindParam(':classe', $classe_id);
                        $stmt->bindParam(':jour', $jour_semaine);
                        $stmt->bindParam(':creneau', $creneau_horaire);
                        $stmt->bindParam(':salle', $salle);
                        $stmt->bindParam(':type', $type_cours);
                        $stmt->bindParam(':annee', $annee_academique_id);
                        $stmt->bindParam(':filiere', $filiere['id']);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Cours ajouté avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Ajout de cours: matière ID $matiere_id, classe ID $classe_id", "emploi_du_temps");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'edit_cours') {
            $cours_id = (int)$_POST['cours_id'];
            $matiere_id = (int)$_POST['matiere_id'];
            $enseignant_id = (int)$_POST['enseignant_id'];
            $classe_id = (int)$_POST['classe_id'];
            $jour_semaine = (int)$_POST['jour_semaine'];
            $creneau_horaire = sanitize($_POST['creneau_horaire']);
            $salle = sanitize($_POST['salle']);
            $type_cours = sanitize($_POST['type_cours']);

            // Validation
            if (empty($matiere_id) || empty($enseignant_id) || empty($classe_id) || empty($jour_semaine) || empty($creneau_horaire)) {
                $messages[] = ['type' => 'error', 'text' => 'Tous les champs obligatoires doivent être remplis.'];
            } else {
                try {
                    // Vérifier les conflits d'horaire (en excluant le cours actuel)
                    $conflict_query = "SELECT c.*, m.nom_matiere, u.nom as enseignant_nom, u.prenom as enseignant_prenom, cl.nom_classe
                                     FROM emploi_du_temps c
                                     JOIN matieres m ON c.matiere_id = m.id
                                     JOIN users u ON c.enseignant_id = u.id
                                     JOIN classes cl ON c.classe_id = cl.id
                                     WHERE c.jour_semaine = :jour AND c.creneau_horaire = :creneau
                                     AND c.annee_academique_id = (SELECT annee_academique_id FROM emploi_du_temps WHERE id = :cours_id)
                                     AND c.id != :cours_id
                                     AND (c.enseignant_id = :enseignant OR c.classe_id = :classe OR c.salle = :salle)";

                    $conflict_stmt = $conn->prepare($conflict_query);
                    $conflict_stmt->bindParam(':jour', $jour_semaine);
                    $conflict_stmt->bindParam(':creneau', $creneau_horaire);
                    $conflict_stmt->bindParam(':cours_id', $cours_id);
                    $conflict_stmt->bindParam(':enseignant', $enseignant_id);
                    $conflict_stmt->bindParam(':classe', $classe_id);
                    $conflict_stmt->bindParam(':salle', $salle);
                    $conflict_stmt->execute();
                    $conflicts = $conflict_stmt->fetchAll(PDO::FETCH_ASSOC);

                    $conflict_messages = [];
                    foreach ($conflicts as $conflict) {
                        if ($conflict['enseignant_id'] == $enseignant_id) {
                            $conflict_messages[] = "L'enseignant " . $conflict['enseignant_nom'] . ' ' . $conflict['enseignant_prenom'] . " a déjà un cours";
                        }
                        if ($conflict['classe_id'] == $classe_id) {
                            $conflict_messages[] = "La classe " . $conflict['nom_classe'] . " a déjà un cours";
                        }
                        if ($conflict['salle'] == $salle) {
                            $conflict_messages[] = "La salle " . $salle . " est déjà occupée";
                        }
                    }

                    if (!empty($conflict_messages)) {
                        $messages[] = ['type' => 'error', 'text' => 'Conflits détectés: ' . implode(', ', array_unique($conflict_messages))];
                    } else {
                        // Modifier le cours
                        $query = "UPDATE emploi_du_temps SET matiere_id = :matiere, enseignant_id = :enseignant,
                                 classe_id = :classe, jour_semaine = :jour, creneau_horaire = :creneau,
                                 salle = :salle, type_cours = :type WHERE id = :id AND filiere_id = :filiere";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':matiere', $matiere_id);
                        $stmt->bindParam(':enseignant', $enseignant_id);
                        $stmt->bindParam(':classe', $classe_id);
                        $stmt->bindParam(':jour', $jour_semaine);
                        $stmt->bindParam(':creneau', $creneau_horaire);
                        $stmt->bindParam(':salle', $salle);
                        $stmt->bindParam(':type', $type_cours);
                        $stmt->bindParam(':id', $cours_id);
                        $stmt->bindParam(':filiere', $filiere['id']);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Cours modifié avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Modification de cours ID $cours_id", "emploi_du_temps");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'delete_cours') {
            $cours_id = (int)$_POST['cours_id'];

            try {
                // Récupérer les informations avant suppression
                $info_query = "SELECT c.*, m.nom_matiere, cl.nom_classe FROM emploi_du_temps c
                              JOIN matieres m ON c.matiere_id = m.id
                              JOIN classes cl ON c.classe_id = cl.id
                              WHERE c.id = :id AND c.filiere_id = :filiere";
                $info_stmt = $conn->prepare($info_query);
                $info_stmt->bindParam(':id', $cours_id);
                $info_stmt->bindParam(':filiere', $filiere['id']);
                $info_stmt->execute();
                $cours_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$cours_info) {
                    throw new Exception('Cours non trouvé ou non autorisé.');
                }

                // Supprimer le cours
                $query = "DELETE FROM emploi_du_temps WHERE id = :id AND filiere_id = :filiere";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $cours_id);
                $stmt->bindParam(':filiere', $filiere['id']);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Cours supprimé avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Suppression de cours: " . $cours_info['nom_matiere'] . " - " . $cours_info['nom_classe'], "emploi_du_temps");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'duplicate_schedule') {
            $source_annee = (int)$_POST['source_annee'];
            $target_annee = (int)$_POST['target_annee'];

            try {
                // Vérifier que l'année cible n'a pas déjà des cours
                $check_query = "SELECT COUNT(*) as count FROM emploi_du_temps WHERE annee_academique_id = :annee AND filiere_id = :filiere";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':annee', $target_annee);
                $check_stmt->bindParam(':filiere', $filiere['id']);
                $check_stmt->execute();
                $existing_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

                if ($existing_count > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'L\'année cible contient déjà des cours. Veuillez la vider d\'abord.'];
                } else {
                    // Dupliquer l'emploi du temps
                    $duplicate_query = "INSERT INTO emploi_du_temps (matiere_id, enseignant_id, classe_id, jour_semaine, creneau_horaire, salle, type_cours, annee_academique_id, filiere_id, created_at)
                                       SELECT matiere_id, enseignant_id, classe_id, jour_semaine, creneau_horaire, salle, type_cours, :target_annee, filiere_id, NOW()
                                       FROM emploi_du_temps WHERE annee_academique_id = :source_annee AND filiere_id = :filiere";
                    $duplicate_stmt = $conn->prepare($duplicate_query);
                    $duplicate_stmt->bindParam(':target_annee', $target_annee);
                    $duplicate_stmt->bindParam(':source_annee', $source_annee);
                    $duplicate_stmt->bindParam(':filiere', $filiere['id']);
                    $duplicate_stmt->execute();

                    $duplicated_count = $duplicate_stmt->rowCount();
                    $messages[] = ['type' => 'success', 'text' => "$duplicated_count cours dupliqués avec succès."];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Duplication d'emploi du temps: $duplicated_count cours de l'année $source_annee vers $target_annee", "emploi_du_temps");
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'export_schedule') {
            $format = sanitize($_POST['format']);
            $annee_filter = sanitize($_POST['annee_filter']);
            $classe_filter = sanitize($_POST['classe_filter']);

            try {
                // Construction de la requête d'export
                $query = "SELECT c.*, m.nom_matiere, u.nom as enseignant_nom, u.prenom as enseignant_prenom,
                         cl.nom_classe, aa.annee_academique
                         FROM emploi_du_temps c
                         JOIN matieres m ON c.matiere_id = m.id
                         JOIN users u ON c.enseignant_id = u.id
                         JOIN classes cl ON c.classe_id = cl.id
                         JOIN annees_academiques aa ON c.annee_academique_id = aa.id
                         WHERE c.filiere_id = :filiere";

                $params = [':filiere' => $filiere['id']];

                if ($annee_filter !== 'all') {
                    $query .= " AND c.annee_academique_id = :annee";
                    $params[':annee'] = $annee_filter;
                }

                if ($classe_filter !== 'all') {
                    $query .= " AND c.classe_id = :classe";
                    $params[':classe'] = $classe_filter;
                }

                $query .= " ORDER BY c.jour_semaine, c.creneau_horaire";

                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Simulation d'export
                $messages[] = ['type' => 'success', 'text' => count($export_data) . ' cours exportés au format ' . strtoupper($format) . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Export d'emploi du temps au format $format", "emploi_du_temps");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'export: ' . $e->getMessage()];
            }
        }
    }
}

// Filtres
$annee_filter = $_GET['annee'] ?? 'all';
$classe_filter = $_GET['classe'] ?? 'all';
$jour_filter = $_GET['jour'] ?? 'all';

// Construction de la requête avec filtres
$query = "SELECT c.*, m.nom_matiere, u.nom as enseignant_nom, u.prenom as enseignant_prenom,
         cl.nom_classe, aa.annee_academique
         FROM emploi_du_temps c
         JOIN matieres m ON c.matiere_id = m.id
         JOIN users u ON c.enseignant_id = u.id
         JOIN classes cl ON c.classe_id = cl.id
         JOIN annees_academiques aa ON c.annee_academique_id = aa.id
         WHERE c.filiere_id = :filiere";

$params = [':filiere' => $filiere['id']];

if ($annee_filter !== 'all') {
    $query .= " AND c.annee_academique_id = :annee";
    $params[':annee'] = $annee_filter;
}

if ($classe_filter !== 'all') {
    $query .= " AND c.classe_id = :classe";
    $params[':classe'] = $classe_filter;
}

if ($jour_filter !== 'all') {
    $query .= " AND c.jour_semaine = :jour";
    $params[':jour'] = $jour_filter;
}

$query .= " ORDER BY c.jour_semaine, c.creneau_horaire";

$cours_stmt = $conn->prepare($query);
$cours_stmt->execute($params);
$cours_list = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des cours
$stats_cours = [
    'total' => 0,
    'par_jour' => [],
    'par_type' => [],
    'par_classe' => []
];

// Calcul des statistiques
foreach ($cours_list as $cours) {
    $stats_cours['total']++;
    $stats_cours['par_jour'][$cours['jour_semaine']] = ($stats_cours['par_jour'][$cours['jour_semaine']] ?? 0) + 1;
    $stats_cours['par_type'][$cours['type_cours']] = ($stats_cours['par_type'][$cours['type_cours']] ?? 0) + 1;
    $stats_cours['par_classe'][$cours['classe_id']] = ($stats_cours['par_classe'][$cours['classe_id']] ?? 0) + 1;
}

// Récupération des données pour les formulaires
$annees_query = "SELECT id, annee_academique FROM annees_academiques WHERE active = 1 ORDER BY annee_academique DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees_list = $annees_stmt->fetchAll(PDO::FETCH_ASSOC);

$matieres_query = "SELECT id, nom_matiere FROM matieres WHERE filiere_id = :filiere ORDER BY nom_matiere";
$matieres_stmt = $conn->prepare($matieres_query);
$matieres_stmt->bindParam(':filiere', $filiere['id']);
$matieres_stmt->execute();
$matieres_list = $matieres_stmt->fetchAll(PDO::FETCH_ASSOC);

$enseignants_query = "SELECT u.id, u.nom, u.prenom FROM users u
                     JOIN enseignants_filieres ef ON u.id = ef.enseignant_id
                     WHERE ef.filiere_id = :filiere AND u.role = 'enseignant'
                     ORDER BY u.nom, u.prenom";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':filiere', $filiere['id']);
$enseignants_stmt->execute();
$enseignants_list = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_query = "SELECT id, nom_classe FROM classes WHERE filiere_id = :filiere ORDER BY nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere', $filiere['id']);
$classes_stmt->execute();
$classes_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de cours
$types_cours = [
    'cours' => 'Cours magistral',
    'td' => 'Travaux dirigés',
    'tp' => 'Travaux pratiques',
    'exam' => 'Examen',
    'rattrapage' => 'Rattrapage'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emploi du temps - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-calendar-alt text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Filière</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Filière: <?php echo htmlspecialchars($filiere['nom_filiere']); ?></span>
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Resp. Filière'); ?></span>
                    <a href="../shared/logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chalkboard mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="emploi_du_temps.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Demandes documents
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-8 bg-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
                        </div>
                        <div class="ml-3">
                            <p><?php echo htmlspecialchars($message['text']); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-calendar-check text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total Cours</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_cours['total']; ?></p>
                        <p class="text-sm text-gray-600">planifiés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chalkboard-teacher text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Enseignants</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo count($enseignants_list); ?></p>
                        <p class="text-sm text-gray-600">actifs</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-users text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Classes</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo count($classes_list); ?></p>
                        <p class="text-sm text-gray-600">couvertes</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-book text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Matières</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo count($matieres_list); ?></p>
                        <p class="text-sm text-gray-600">enseignées</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-calendar-alt mr-2"></i>Gestion de l'emploi du temps
                </h2>

                <div class="flex space-x-2">
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Ajouter un cours
                    </button>
                    <button onclick="openDuplicateModal()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-copy mr-2"></i>Dupliquer EDT
                    </button>
                    <button onclick="openExportModal()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-file-export mr-2"></i>Exporter
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="annee" class="block text-sm font-medium text-gray-700 mb-2">Année académique</label>
                    <select id="annee" name="annee" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $annee_filter === 'all' ? 'selected' : ''; ?>>Toutes les années</option>
                        <?php foreach ($annees_list as $annee): ?>
                            <option value="<?php echo $annee['id']; ?>" <?php echo $annee_filter === (string)$annee['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['annee_academique']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="classe" class="block text-sm font-medium text-gray-700 mb-2">Classe</label>
                    <select id="classe" name="classe" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $classe_filter === 'all' ? 'selected' : ''; ?>>Toutes les classes</option>
                        <?php foreach ($classes_list as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $classe_filter === (string)$classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom_classe']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="jour" class="block text-sm font-medium text-gray-700 mb-2">Jour</label>
                    <select id="jour" name="jour" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $jour_filter === 'all' ? 'selected' : ''; ?>>Tous les jours</option>
                        <?php foreach ($jours_semaine as $key => $jour): ?>
                            <option value="<?php echo $key; ?>" <?php echo $jour_filter === (string)$key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($jour); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <a href="emploi_du_temps.php"
                       class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200 w-full text-center">
                        <i class="fas fa-times mr-2"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Grille d'emploi du temps -->
        <?php if ($annee_filter !== 'all' && $classe_filter !== 'all'): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-table mr-2"></i>Emploi du temps -
                    <?php
                    $selected_annee = array_filter($annees_list, fn($a) => $a['id'] == $annee_filter)[0]['annee_academique'] ?? 'Année inconnue';
                    $selected_classe = array_filter($classes_list, fn($c) => $c['id'] == $classe_filter)[0]['nom_classe'] ?? 'Classe inconnue';
                    echo htmlspecialchars($selected_classe . ' - ' . $selected_annee);
                    ?>
                </h3>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Horaire</th>
                                <?php foreach ($jours_semaine as $jour_id => $jour_nom): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?php echo htmlspecialchars($jour_nom); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($creneaux_horaires as $creneau_key => $creneau_label): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($creneau_label); ?>
                                    </td>
                                    <?php foreach ($jours_semaine as $jour_id => $jour_nom): ?>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $cours_cell = array_filter($cours_list, function($c) use ($jour_id, $creneau_key, $annee_filter, $classe_filter) {
                                                return $c['jour_semaine'] == $jour_id &&
                                                       $c['creneau_horaire'] == $creneau_key &&
                                                       $c['annee_academique_id'] == $annee_filter &&
                                                       $c['classe_id'] == $classe_filter;
                                            });
                                            $cours_cell = reset($cours_cell);
                                            ?>
                                            <?php if ($cours_cell): ?>
                                                <div class="bg-blue-50 border border-blue-200 rounded p-2">
                                                    <div class="text-sm font-medium text-blue-900">
                                                        <?php echo htmlspecialchars($cours_cell['nom_matiere']); ?>
                                                    </div>
                                                    <div class="text-xs text-blue-700">
                                                        <?php echo htmlspecialchars($cours_cell['enseignant_nom'] . ' ' . $cours_cell['enseignant_prenom']); ?>
                                                    </div>
                                                    <div class="text-xs text-blue-600">
                                                        Salle: <?php echo htmlspecialchars($cours_cell['salle']); ?>
                                                    </div>
                                                    <div class="text-xs text-blue-600">
                                                        <?php echo htmlspecialchars($types_cours[$cours_cell['type_cours']] ?? $cours_cell['type_cours']); ?>
                                                    </div>
                                                    <div class="mt-1 flex space-x-1">
                                                        <button onclick="openEditModal(<?php echo $cours_cell['id']; ?>, '<?php echo addslashes($cours_cell['matiere_id']); ?>', '<?php echo addslashes($cours_cell['enseignant_id']); ?>', '<?php echo addslashes($cours_cell['classe_id']); ?>', '<?php echo addslashes($cours_cell['jour_semaine']); ?>', '<?php echo addslashes($cours_cell['creneau_horaire']); ?>', '<?php echo addslashes($cours_cell['salle']); ?>', '<?php echo addslashes($cours_cell['type_cours']); ?>')"
                                                                class="text-blue-600 hover:text-blue-900 text-xs">
                                                            <i class="fas fa-edit"></i>
                                                        </button>
                                                        <button onclick="confirmDelete(<?php echo $cours_cell['id']; ?>, '<?php echo addslashes($cours_cell['nom_matiere']); ?>')"
                                                                class="text-red-600 hover:text-red-900 text-xs">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="text-gray-400 text-sm">-</div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>

        <!-- Liste détaillée des cours -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-bold text-gray-800">
                    <i class="fas fa-list mr-2"></i>Liste détaillée des cours
                </h3>
            </div>

            <?php if (empty($cours_list)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-calendar-alt text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun cours trouvé</h3>
                    <p class="text-gray-500 mb-4">Il n'y a pas de cours correspondant à vos critères de recherche.</p>
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Planifier le premier cours
                    </button>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cours</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enseignant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jour & Horaire</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Salle</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($cours_list as $cours): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-book text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($cours['nom_matiere']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($cours['annee_academique']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($cours['enseignant_nom'] . ' ' . $cours['enseignant_prenom']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($cours['nom_classe']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($jours_semaine[$cours['jour_semaine']] . ' ' . $creneaux_horaires[$cours['creneau_horaire']]); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($cours['salle']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($cours['type_cours']) {
                                                case 'cours': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'td': echo 'bg-green-100 text-green-800'; break;
                                                case 'tp': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'exam': echo 'bg-red-100 text-red-800'; break;
                                                case 'rattrapage': echo 'bg-purple-100 text-purple-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($types_cours[$cours['type_cours']] ?? $cours['type_cours']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(<?php echo $cours['id']; ?>, '<?php echo addslashes($cours['matiere_id']); ?>', '<?php echo addslashes($cours['enseignant_id']); ?>', '<?php echo addslashes($cours['classe_id']); ?>', '<?php echo addslashes($cours['jour_semaine']); ?>', '<?php echo addslashes($cours['creneau_horaire']); ?>', '<?php echo addslashes($cours['salle']); ?>', '<?php echo addslashes($cours['type_cours']); ?>')"
                                                    class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $cours['id']; ?>, '<?php echo addslashes($cours['nom_matiere']); ?>')"
                                                    class="text-red-600 hover:text-red-900">
                                                        <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Ajouter Cours -->
    <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-plus mr-2"></i>Ajouter un cours
                    </h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_cours">

                    <div>
                        <label for="add_matiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Matière *
                        </label>
                        <select id="add_matiere" name="matiere_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une matière...</option>
                            <?php foreach ($matieres_list as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>"><?php echo htmlspecialchars($matiere['nom_matiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="add_enseignant" class="block text-sm font-medium text-gray-700 mb-2">
                            Enseignant *
                        </label>
                        <select id="add_enseignant" name="enseignant_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner un enseignant...</option>
                            <?php foreach ($enseignants_list as $enseignant): ?>
                                <option value="<?php echo $enseignant['id']; ?>"><?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="add_classe" class="block text-sm font-medium text-gray-700 mb-2">
                            Classe *
                        </label>
                        <select id="add_classe" name="classe_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une classe...</option>
                            <?php foreach ($classes_list as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"><?php echo htmlspecialchars($classe['nom_classe']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="add_jour" class="block text-sm font-medium text-gray-700 mb-2">
                                Jour *
                            </label>
                            <select id="add_jour" name="jour_semaine" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($jours_semaine as $key => $jour): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($jour); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="add_creneau" class="block text-sm font-medium text-gray-700 mb-2">
                                Horaire *
                            </label>
                            <select id="add_creneau" name="creneau_horaire" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($creneaux_horaires as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="add_salle" class="block text-sm font-medium text-gray-700 mb-2">
                                Salle *
                            </label>
                            <input type="text" id="add_salle" name="salle" required placeholder="Ex: A101"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="add_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Type *
                            </label>
                            <select id="add_type" name="type_cours" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($types_cours as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="add_annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                            Année académique *
                        </label>
                        <select id="add_annee_academique" name="annee_academique_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($annees_list as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['annee_academique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Ajouter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Cours -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-edit mr-2"></i>Modifier le cours
                    </h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit_cours">
                    <input type="hidden" name="cours_id" id="editModalId">

                    <div>
                        <label for="edit_matiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Matière *
                        </label>
                        <select id="edit_matiere" name="matiere_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($matieres_list as $matiere): ?>
                                <option value="<?php echo $matiere['id']; ?>"><?php echo htmlspecialchars($matiere['nom_matiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_enseignant" class="block text-sm font-medium text-gray-700 mb-2">
                            Enseignant *
                        </label>
                        <select id="edit_enseignant" name="enseignant_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($enseignants_list as $enseignant): ?>
                                <option value="<?php echo $enseignant['id']; ?>"><?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_classe" class="block text-sm font-medium text-gray-700 mb-2">
                            Classe *
                        </label>
                        <select id="edit_classe" name="classe_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($classes_list as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"><?php echo htmlspecialchars($classe['nom_classe']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_jour" class="block text-sm font-medium text-gray-700 mb-2">
                                Jour *
                            </label>
                            <select id="edit_jour" name="jour_semaine" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($jours_semaine as $key => $jour): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($jour); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="edit_creneau" class="block text-sm font-medium text-gray-700 mb-2">
                                Horaire *
                            </label>
                            <select id="edit_creneau" name="creneau_horaire" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($creneaux_horaires as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_salle" class="block text-sm font-medium text-gray-700 mb-2">
                                Salle *
                            </label>
                            <input type="text" id="edit_salle" name="salle" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="edit_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Type *
                            </label>
                            <select id="edit_type" name="type_cours" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($types_cours as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Modifier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Dupliquer EDT -->
    <div id="duplicateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-copy mr-2"></i>Dupliquer l'emploi du temps
                    </h3>
                    <button onclick="closeDuplicateModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm text-blue-800">
                        <i class="fas fa-info-circle mr-2"></i>
                        Cette action copie tous les cours d'une année académique vers une autre année.
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="duplicate_schedule">

                    <div>
                        <label for="source_annee" class="block text-sm font-medium text-gray-700 mb-2">
                            Année source *
                        </label>
                        <select id="source_annee" name="source_annee" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner l'année source...</option>
                            <?php foreach ($annees_list as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['annee_academique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="target_annee" class="block text-sm font-medium text-gray-700 mb-2">
                            Année cible *
                        </label>
                        <select id="target_annee" name="target_annee" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner l'année cible...</option>
                            <?php foreach ($annees_list as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['annee_academique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeDuplicateModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Dupliquer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Export -->
    <div id="exportModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-file-export mr-2"></i>Exporter l'emploi du temps
                    </h3>
                    <button onclick="closeExportModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="export_schedule">

                    <div>
                        <label for="exportFormat" class="block text-sm font-medium text-gray-700 mb-2">
                            Format d'export *
                        </label>
                        <select id="exportFormat" name="format" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="pdf">PDF</option>
                            <option value="excel">Excel (XLSX)</option>
                            <option value="csv">CSV</option>
                            <option value="json">JSON</option>
                        </select>
                    </div>

                    <div>
                        <label for="exportAnneeFilter" class="block text-sm font-medium text-gray-700 mb-2">
                            Filtrer par année
                        </label>
                        <select id="exportAnneeFilter" name="annee_filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">Toutes les années</option>
                            <?php foreach ($annees_list as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['annee_academique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="exportClasseFilter" class="block text-sm font-medium text-gray-700 mb-2">
                            Filtrer par classe
                        </label>
                        <select id="exportClasseFilter" name="classe_filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">Toutes les classes</option>
                            <?php foreach ($classes_list as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"><?php echo htmlspecialchars($classe['nom_classe']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeExportModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Exporter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center">
                <p>&copy; 2024 Institut Supérieur de Technologie et d'Informatique. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    <script>
        function openAddModal() {
            document.getElementById('addModal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
            document.getElementById('add_matiere').selectedIndex = 0;
            document.getElementById('add_enseignant').selectedIndex = 0;
            document.getElementById('add_classe').selectedIndex = 0;
            document.getElementById('add_jour').selectedIndex = 0;
            document.getElementById('add_creneau').selectedIndex = 0;
            document.getElementById('add_salle').value = '';
            document.getElementById('add_type').selectedIndex = 0;
            document.getElementById('add_annee_academique').selectedIndex = 0;
        }

        function openEditModal(id, matiereId, enseignantId, classeId, jour, creneau, salle, type) {
            document.getElementById('editModalId').value = id;
            document.getElementById('edit_matiere').value = matiereId;
            document.getElementById('edit_enseignant').value = enseignantId;
            document.getElementById('edit_classe').value = classeId;
            document.getElementById('edit_jour').value = jour;
            document.getElementById('edit_creneau').value = creneau;
            document.getElementById('edit_salle').value = salle;
            document.getElementById('edit_type').value = type;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function openDuplicateModal() {
            document.getElementById('duplicateModal').classList.remove('hidden');
        }

        function closeDuplicateModal() {
            document.getElementById('duplicateModal').classList.add('hidden');
            document.getElementById('source_annee').selectedIndex = 0;
            document.getElementById('target_annee').selectedIndex = 0;
        }

        function openExportModal() {
            document.getElementById('exportModal').classList.remove('hidden');
        }

        function closeExportModal() {
            document.getElementById('exportModal').classList.add('hidden');
            document.getElementById('exportFormat').selectedIndex = 0;
            document.getElementById('exportAnneeFilter').selectedIndex = 0;
            document.getElementById('exportClasseFilter').selectedIndex = 0;
        }

        function confirmDelete(id, matiereName) {
            if (confirm('Êtes-vous sûr de vouloir supprimer le cours "' + matiereName + '" ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_cours">
                    <input type="hidden" name="cours_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>