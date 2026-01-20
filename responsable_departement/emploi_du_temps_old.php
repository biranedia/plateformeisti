<?php
/**
 * Gestion de l'emploi du temps - Responsable de département
 * Consultation et gestion des emplois du temps du département
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_dept')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de département pour accéder à cette page.', 'error');
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

// Récupération du département géré
$dept_query = "SELECT * FROM departements WHERE responsable_id = :user_id";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->bindParam(':user_id', $user_id);
$dept_stmt->execute();
$departement = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de département assigné
if (!$departement) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucun département assigné</h2>
            <p>Vous n'êtes pas encore assigné à un département. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Messages de succès ou d'erreur
$messages = [];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'add_cours') {
            $filiere_id = (int)$_POST['filiere_id'];
            $classe_id = (int)$_POST['classe_id'];
            $matiere = sanitize($_POST['matiere']);
            $enseignant_id = (int)$_POST['enseignant_id'];
            $jour_semaine = sanitize($_POST['jour_semaine']);
            $heure_debut = sanitize($_POST['heure_debut']);
            $heure_fin = sanitize($_POST['heure_fin']);
            $salle = sanitize($_POST['salle']);
            $type_cours = sanitize($_POST['type_cours']);
            $annee_academique_id = (int)$_POST['annee_academique_id'];

            // Validation
            if (empty($matiere) || empty($jour_semaine) || empty($heure_debut) || empty($heure_fin)) {
                $messages[] = ['type' => 'error', 'text' => 'Tous les champs obligatoires doivent être remplis.'];
            } elseif (strtotime($heure_debut) >= strtotime($heure_fin)) {
                $messages[] = ['type' => 'error', 'text' => 'L\'heure de fin doit être postérieure à l\'heure de début.'];
            } else {
                try {
                    // Vérifier les conflits d'horaire
                    $conflict_query = "SELECT id FROM emploi_du_temps
                                     WHERE ((heure_debut < :heure_fin AND heure_fin > :heure_debut)
                                     AND jour_semaine = :jour AND salle = :salle
                                     AND annee_academique_id = :annee_id)";
                    $conflict_stmt = $conn->prepare($conflict_query);
                    $conflict_stmt->bindParam(':heure_debut', $heure_debut);
                    $conflict_stmt->bindParam(':heure_fin', $heure_fin);
                    $conflict_stmt->bindParam(':jour', $jour_semaine);
                    $conflict_stmt->bindParam(':salle', $salle);
                    $conflict_stmt->bindParam(':annee_id', $annee_academique_id);
                    $conflict_stmt->execute();

                    if ($conflict_stmt->rowCount() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Conflit d\'horaire détecté dans cette salle.'];
                    } else {
                        // Ajouter le cours
                        $query = "INSERT INTO emploi_du_temps (filiere_id, classe_id, matiere, enseignant_id,
                                 jour_semaine, heure_debut, heure_fin, salle, type_cours, annee_academique_id, created_at)
                                 VALUES (:filiere_id, :classe_id, :matiere, :enseignant_id, :jour_semaine,
                                 :heure_debut, :heure_fin, :salle, :type_cours, :annee_id, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':filiere_id', $filiere_id);
                        $stmt->bindParam(':classe_id', $classe_id);
                        $stmt->bindParam(':matiere', $matiere);
                        $stmt->bindParam(':enseignant_id', $enseignant_id);
                        $stmt->bindParam(':jour_semaine', $jour_semaine);
                        $stmt->bindParam(':heure_debut', $heure_debut);
                        $stmt->bindParam(':heure_fin', $heure_fin);
                        $stmt->bindParam(':salle', $salle);
                        $stmt->bindParam(':type_cours', $type_cours);
                        $stmt->bindParam(':annee_id', $annee_academique_id);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Cours ajouté avec succès à l\'emploi du temps.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Ajout de cours: $matiere - $jour_semaine $heure_debut", "emploi_du_temps");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'edit_cours') {
            $cours_id = (int)$_POST['cours_id'];
            $filiere_id = (int)$_POST['filiere_id'];
            $classe_id = (int)$_POST['classe_id'];
            $matiere = sanitize($_POST['matiere']);
            $enseignant_id = (int)$_POST['enseignant_id'];
            $jour_semaine = sanitize($_POST['jour_semaine']);
            $heure_debut = sanitize($_POST['heure_debut']);
            $heure_fin = sanitize($_POST['heure_fin']);
            $salle = sanitize($_POST['salle']);
            $type_cours = sanitize($_POST['type_cours']);

            // Validation
            if (empty($matiere) || empty($jour_semaine) || empty($heure_debut) || empty($heure_fin)) {
                $messages[] = ['type' => 'error', 'text' => 'Tous les champs obligatoires doivent être remplis.'];
            } elseif (strtotime($heure_debut) >= strtotime($heure_fin)) {
                $messages[] = ['type' => 'error', 'text' => 'L\'heure de fin doit être postérieure à l\'heure de début.'];
            } else {
                try {
                    // Vérifier les conflits d'horaire (sauf pour ce cours)
                    $conflict_query = "SELECT id FROM emploi_du_temps
                                     WHERE ((heure_debut < :heure_fin AND heure_fin > :heure_debut)
                                     AND jour_semaine = :jour AND salle = :salle AND id != :cours_id)";
                    $conflict_stmt = $conn->prepare($conflict_query);
                    $conflict_stmt->bindParam(':heure_debut', $heure_debut);
                    $conflict_stmt->bindParam(':heure_fin', $heure_fin);
                    $conflict_stmt->bindParam(':jour', $jour_semaine);
                    $conflict_stmt->bindParam(':salle', $salle);
                    $conflict_stmt->bindParam(':cours_id', $cours_id);
                    $conflict_stmt->execute();

                    if ($conflict_stmt->rowCount() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Conflit d\'horaire détecté dans cette salle.'];
                    } else {
                        // Mettre à jour le cours
                        $query = "UPDATE emploi_du_temps SET filiere_id = :filiere_id, classe_id = :classe_id,
                                 matiere = :matiere, enseignant_id = :enseignant_id, jour_semaine = :jour_semaine,
                                 heure_debut = :heure_debut, heure_fin = :heure_fin, salle = :salle,
                                 type_cours = :type_cours, updated_at = NOW()
                                 WHERE id = :cours_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':filiere_id', $filiere_id);
                        $stmt->bindParam(':classe_id', $classe_id);
                        $stmt->bindParam(':matiere', $matiere);
                        $stmt->bindParam(':enseignant_id', $enseignant_id);
                        $stmt->bindParam(':jour_semaine', $jour_semaine);
                        $stmt->bindParam(':heure_debut', $heure_debut);
                        $stmt->bindParam(':heure_fin', $heure_fin);
                        $stmt->bindParam(':salle', $salle);
                        $stmt->bindParam(':type_cours', $type_cours);
                        $stmt->bindParam(':cours_id', $cours_id);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Cours mis à jour avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Modification de cours: $matiere - $jour_semaine $heure_debut", "emploi_du_temps");
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
                $info_query = "SELECT matiere, jour_semaine, heure_debut FROM emploi_du_temps WHERE id = :id";
                $info_stmt = $conn->prepare($info_query);
                $info_stmt->bindParam(':id', $cours_id);
                $info_stmt->execute();
                $cours_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

                // Supprimer le cours
                $query = "DELETE FROM emploi_du_temps WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $cours_id);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Cours supprimé avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Suppression de cours: {$cours_info['matiere']} - {$cours_info['jour_semaine']} {$cours_info['heure_debut']}", "emploi_du_temps");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'duplicate_week') {
            $source_annee_id = (int)$_POST['source_annee_id'];
            $target_annee_id = (int)$_POST['target_annee_id'];

            try {
                // Récupérer tous les cours de l'année source
                $source_query = "SELECT * FROM emploi_du_temps
                               WHERE annee_academique_id = :source_annee
                               AND filiere_id IN (SELECT id FROM filieres WHERE departement_id = :dept_id)";
                $source_stmt = $conn->prepare($source_query);
                $source_stmt->bindParam(':source_annee', $source_annee_id);
                $source_stmt->bindParam(':dept_id', $departement['id']);
                $source_stmt->execute();
                $source_cours = $source_stmt->fetchAll(PDO::FETCH_ASSOC);

                $duplicated_count = 0;
                foreach ($source_cours as $cours) {
                    // Insérer dans l'année cible
                    $insert_query = "INSERT INTO emploi_du_temps
                                   (filiere_id, classe_id, matiere, enseignant_id, jour_semaine,
                                   heure_debut, heure_fin, salle, type_cours, annee_academique_id, created_at)
                                   VALUES (:filiere_id, :classe_id, :matiere, :enseignant_id, :jour_semaine,
                                   :heure_debut, :heure_fin, :salle, :type_cours, :annee_id, NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':filiere_id', $cours['filiere_id']);
                    $insert_stmt->bindParam(':classe_id', $cours['classe_id']);
                    $insert_stmt->bindParam(':matiere', $cours['matiere']);
                    $insert_stmt->bindParam(':enseignant_id', $cours['enseignant_id']);
                    $insert_stmt->bindParam(':jour_semaine', $cours['jour_semaine']);
                    $insert_stmt->bindParam(':heure_debut', $cours['heure_debut']);
                    $insert_stmt->bindParam(':heure_fin', $cours['heure_fin']);
                    $insert_stmt->bindParam(':salle', $cours['salle']);
                    $insert_stmt->bindParam(':type_cours', $cours['type_cours']);
                    $insert_stmt->bindParam(':annee_id', $target_annee_id);
                    $insert_stmt->execute();
                    $duplicated_count++;
                }

                $messages[] = ['type' => 'success', 'text' => "$duplicated_count cours dupliqués avec succès vers la nouvelle année."];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Duplication de $duplicated_count cours vers l'année $target_annee_id", "emploi_du_temps");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la duplication: ' . $e->getMessage()];
            }
        }
    }
}

// Filtres
$selected_filiere = $_GET['filiere'] ?? 'all';
$selected_classe = $_GET['classe'] ?? 'all';
$selected_annee = $_GET['annee'] ?? 'all';

// Récupération des données pour les filtres
$filieres_query = "SELECT * FROM filieres WHERE departement_id = :dept_id ORDER BY nom_filiere";
$filieres_stmt = $conn->prepare($filieres_query);
$filieres_stmt->bindParam(':dept_id', $departement['id']);
$filieres_stmt->execute();
$filieres = $filieres_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_query = "SELECT c.*, f.nom_filiere FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 WHERE f.departement_id = :dept_id ORDER BY c.nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':dept_id', $departement['id']);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$annees_query = "SELECT * FROM annees_academiques ORDER BY nom_annee DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees_academiques = $annees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Année active par défaut
if ($selected_annee === 'all') {
    foreach ($annees_academiques as $annee) {
        if ($annee['status'] === 'active') {
            $selected_annee = $annee['id'];
            break;
        }
    }
    if ($selected_annee === 'all' && !empty($annees_academiques)) {
        $selected_annee = $annees_academiques[0]['id'];
    }
}

// Récupération des enseignants du département
$enseignants_query = "SELECT DISTINCT u.id, u.nom, u.prenom FROM users u
                     JOIN inscriptions i ON u.id = i.etudiant_id
                     JOIN classes c ON i.classe_id = c.id
                     JOIN filieres f ON c.filiere_id = f.id
                     WHERE f.departement_id = :dept_id AND u.role_id = (SELECT id FROM roles WHERE role_name = 'enseignant')
                     ORDER BY u.nom, u.prenom";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':dept_id', $departement['id']);
$enseignants_stmt->execute();
$enseignants = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Construction de la requête pour les cours
$query = "SELECT edt.*, f.nom_filiere, c.nom_classe, u.nom as ens_nom, u.prenom as ens_prenom, a.nom_annee
          FROM emploi_du_temps edt
          JOIN filieres f ON edt.filiere_id = f.id
          LEFT JOIN classes c ON edt.classe_id = c.id
          LEFT JOIN users u ON edt.enseignant_id = u.id
          JOIN annees_academiques a ON edt.annee_academique_id = a.id
          WHERE f.departement_id = :dept_id";

$params = [':dept_id' => $departement['id']];

if ($selected_filiere !== 'all') {
    $query .= " AND edt.filiere_id = :filiere_id";
    $params[':filiere_id'] = $selected_filiere;
}

if ($selected_classe !== 'all') {
    $query .= " AND edt.classe_id = :classe_id";
    $params[':classe_id'] = $selected_classe;
}

if ($selected_annee !== 'all') {
    $query .= " AND edt.annee_academique_id = :annee_id";
    $params[':annee_id'] = $selected_annee;
}

$query .= " ORDER BY FIELD(edt.jour_semaine, 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'), edt.heure_debut";

$cours_stmt = $conn->prepare($query);
$cours_stmt->execute($params);
$cours_list = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats_cours = [
    'total' => count($cours_list),
    'par_jour' => [],
    'par_type' => []
];

// Calcul des statistiques
foreach ($cours_list as $cours) {
    $jour = $cours['jour_semaine'];
    $type = $cours['type_cours'];

    if (!isset($stats_cours['par_jour'][$jour])) {
        $stats_cours['par_jour'][$jour] = 0;
    }
    $stats_cours['par_jour'][$jour]++;

    if (!isset($stats_cours['par_type'][$type])) {
        $stats_cours['par_type'][$type] = 0;
    }
    $stats_cours['par_type'][$type]++;
}

// Jours de la semaine
$jours_semaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
$types_cours = ['Cours', 'TD', 'TP', 'Exam', 'Autre'];
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Département</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Département: <?php echo htmlspecialchars($departement['nom_departement']); ?></span>
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Resp. Dept'); ?></span>
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
                <a href="filieres.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Filières
                </a>
                <a href="emploi_du_temps.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="documents_a_valider.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents à valider
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback étudiants
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
                        <i class="fas fa-calendar text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total cours</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_cours['total']; ?></p>
                        <p class="text-sm text-gray-600">dans l'emploi du temps</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chalkboard-teacher text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Cours magistraux</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_cours['par_type']['Cours'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">cours théoriques</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-users text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">TD/TP</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo ($stats_cours['par_type']['TD'] ?? 0) + ($stats_cours['par_type']['TP'] ?? 0); ?></p>
                        <p class="text-sm text-gray-600">travaux dirigés/pratiques</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-clock text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Cette semaine</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo array_sum($stats_cours['par_jour']); ?></p>
                        <p class="text-sm text-gray-600">cours planifiés</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres et actions -->
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
                        <i class="fas fa-copy mr-2"></i>Dupliquer vers nouvelle année
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="filiere" class="block text-sm font-medium text-gray-700 mb-2">Filière</label>
                    <select id="filiere" name="filiere" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $selected_filiere === 'all' ? 'selected' : ''; ?>>Toutes les filières</option>
                        <?php foreach ($filieres as $filiere): ?>
                            <option value="<?php echo $filiere['id']; ?>" <?php echo $selected_filiere == $filiere['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filiere['nom_filiere']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="classe" class="block text-sm font-medium text-gray-700 mb-2">Classe</label>
                    <select id="classe" name="classe" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $selected_classe === 'all' ? 'selected' : ''; ?>>Toutes les classes</option>
                        <?php foreach ($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $selected_classe == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['nom_filiere']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="annee" class="block text-sm font-medium text-gray-700 mb-2">Année académique</label>
                    <select id="annee" name="annee" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($annees_academiques as $annee): ?>
                            <option value="<?php echo $annee['id']; ?>" <?php echo $selected_annee == $annee['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['nom_annee']); ?>
                                <?php if ($annee['status'] === 'active'): echo ' (Active)'; endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <a href="emploi_du_temps.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-times mr-2"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Emploi du temps -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="p-6 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-800">
                    Emploi du temps <?php echo $selected_annee !== 'all' ? '- ' . htmlspecialchars($annees_academiques[array_search($selected_annee, array_column($annees_academiques, 'id'))]['nom_annee']) : ''; ?>
                </h3>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Horaire</th>
                            <?php foreach ($jours_semaine as $jour): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"><?php echo $jour; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php
                        // Créer une grille horaire (8h à 18h par exemple)
                        $heures = [];
                        for ($h = 8; $h <= 18; $h++) {
                            for ($m = 0; $m < 60; $m += 30) {
                                $heures[] = sprintf('%02d:%02d', $h, $m);
                            }
                        }

                        // Organiser les cours par jour et heure
                        $cours_par_jour_heure = [];
                        foreach ($cours_list as $cours) {
                            $jour = $cours['jour_semaine'];
                            $heure_debut = $cours['heure_debut'];
                            if (!isset($cours_par_jour_heure[$jour])) {
                                $cours_par_jour_heure[$jour] = [];
                            }
                            $cours_par_jour_heure[$jour][$heure_debut] = $cours;
                        }

                        foreach ($heures as $heure):
                        ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo $heure; ?>
                                </td>
                                <?php foreach ($jours_semaine as $jour): ?>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $cours_a_heure = null;
                                        if (isset($cours_par_jour_heure[$jour])) {
                                            foreach ($cours_par_jour_heure[$jour] as $heure_debut => $cours) {
                                                if ($heure >= $heure_debut && $heure < $cours['heure_fin']) {
                                                    $cours_a_heure = $cours;
                                                    break;
                                                }
                                            }
                                        }

                                        if ($cours_a_heure):
                                        ?>
                                            <div class="bg-blue-100 border border-blue-200 rounded p-2">
                                                <div class="text-sm font-medium text-blue-900">
                                                    <?php echo htmlspecialchars($cours_a_heure['matiere']); ?>
                                                </div>
                                                <div class="text-xs text-blue-700">
                                                    <?php echo htmlspecialchars($cours_a_heure['type_cours']); ?> - Salle <?php echo htmlspecialchars($cours_a_heure['salle']); ?>
                                                </div>
                                                <div class="text-xs text-blue-600">
                                                    <?php echo htmlspecialchars($cours_a_heure['nom_classe']); ?>
                                                </div>
                                                <?php if ($cours_a_heure['ens_nom']): ?>
                                                    <div class="text-xs text-blue-600">
                                                        <?php echo htmlspecialchars($cours_a_heure['ens_nom'] . ' ' . $cours_a_heure['ens_prenom']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mt-2 flex space-x-1">
                                                    <button onclick="openEditModal(<?php echo $cours_a_heure['id']; ?>, '<?php echo addslashes($cours_a_heure['filiere_id']); ?>', '<?php echo addslashes($cours_a_heure['classe_id']); ?>', '<?php echo addslashes($cours_a_heure['matiere']); ?>', '<?php echo addslashes($cours_a_heure['enseignant_id']); ?>', '<?php echo addslashes($cours_a_heure['jour_semaine']); ?>', '<?php echo addslashes($cours_a_heure['heure_debut']); ?>', '<?php echo addslashes($cours_a_heure['heure_fin']); ?>', '<?php echo addslashes($cours_a_heure['salle']); ?>', '<?php echo addslashes($cours_a_heure['type_cours']); ?>')"
                                                            class="text-blue-600 hover:text-blue-900 text-xs">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                    <button onclick="confirmDelete(<?php echo $cours_a_heure['id']; ?>, '<?php echo addslashes($cours_a_heure['matiere']); ?>')"
                                                            class="text-red-600 hover:text-red-900 text-xs">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="text-gray-400 text-xs">-</div>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>

    <!-- Modal Ajouter Cours -->
    <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white max-h-screen overflow-y-auto">
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
                    <input type="hidden" name="annee_academique_id" value="<?php echo $selected_annee; ?>">

                    <div>
                        <label for="add_filiere_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Filière *
                        </label>
                        <select id="add_filiere_id" name="filiere_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une filière</option>
                            <?php foreach ($filieres as $filiere): ?>
                                <option value="<?php echo $filiere['id']; ?>"><?php echo htmlspecialchars($filiere['nom_filiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="add_classe_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Classe
                        </label>
                        <select id="add_classe_id" name="classe_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une classe (optionnel)</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"><?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['nom_filiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="add_matiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Matière *
                        </label>
                        <input type="text" id="add_matiere" name="matiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="add_enseignant_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Enseignant
                        </label>
                        <select id="add_enseignant_id" name="enseignant_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner un enseignant (optionnel)</option>
                            <?php foreach ($enseignants as $enseignant): ?>
                                <option value="<?php echo $enseignant['id']; ?>"><?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="add_jour_semaine" class="block text-sm font-medium text-gray-700 mb-2">
                                Jour *
                            </label>
                            <select id="add_jour_semaine" name="jour_semaine" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($jours_semaine as $jour): ?>
                                    <option value="<?php echo $jour; ?>"><?php echo $jour; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="add_type_cours" class="block text-sm font-medium text-gray-700 mb-2">
                                Type *
                            </label>
                            <select id="add_type_cours" name="type_cours" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($types_cours as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="add_heure_debut" class="block text-sm font-medium text-gray-700 mb-2">
                                Heure début *
                            </label>
                            <input type="time" id="add_heure_debut" name="heure_debut" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="add_heure_fin" class="block text-sm font-medium text-gray-700 mb-2">
                                Heure fin *
                            </label>
                            <input type="time" id="add_heure_fin" name="heure_fin" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="add_salle" class="block text-sm font-medium text-gray-700 mb-2">
                            Salle *
                        </label>
                        <input type="text" id="add_salle" name="salle" required placeholder="Ex: A101, B205..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
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
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white max-h-screen overflow-y-auto">
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
                    <input type="hidden" id="edit_cours_id" name="cours_id">

                    <div>
                        <label for="edit_filiere_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Filière *
                        </label>
                        <select id="edit_filiere_id" name="filiere_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($filieres as $filiere): ?>
                                <option value="<?php echo $filiere['id']; ?>"><?php echo htmlspecialchars($filiere['nom_filiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_classe_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Classe
                        </label>
                        <select id="edit_classe_id" name="classe_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une classe (optionnel)</option>
                            <?php foreach ($classes as $classe): ?>
                                <option value="<?php echo $classe['id']; ?>"><?php echo htmlspecialchars($classe['nom_classe'] . ' - ' . $classe['nom_filiere']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_matiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Matière *
                        </label>
                        <input type="text" id="edit_matiere" name="matiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_enseignant_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Enseignant
                        </label>
                        <select id="edit_enseignant_id" name="enseignant_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner un enseignant (optionnel)</option>
                            <?php foreach ($enseignants as $enseignant): ?>
                                <option value="<?php echo $enseignant['id']; ?>"><?php echo htmlspecialchars($enseignant['nom'] . ' ' . $enseignant['prenom']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_jour_semaine" class="block text-sm font-medium text-gray-700 mb-2">
                                Jour *
                            </label>
                            <select id="edit_jour_semaine" name="jour_semaine" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($jours_semaine as $jour): ?>
                                    <option value="<?php echo $jour; ?>"><?php echo $jour; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="edit_type_cours" class="block text-sm font-medium text-gray-700 mb-2">
                                Type *
                            </label>
                            <select id="edit_type_cours" name="type_cours" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($types_cours as $type): ?>
                                    <option value="<?php echo $type; ?>"><?php echo $type; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="edit_heure_debut" class="block text-sm font-medium text-gray-700 mb-2">
                                Heure début *
                            </label>
                            <input type="time" id="edit_heure_debut" name="heure_debut" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="edit_heure_fin" class="block text-sm font-medium text-gray-700 mb-2">
                                Heure fin *
                            </label>
                            <input type="time" id="edit_heure_fin" name="heure_fin" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <div>
                        <label for="edit_salle" class="block text-sm font-medium text-gray-700 mb-2">
                            Salle *
                        </label>
                        <input type="text" id="edit_salle" name="salle" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
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

    <!-- Modal Dupliquer -->
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

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="duplicate_week">

                    <div>
                        <label for="source_annee_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Année source *
                        </label>
                        <select id="source_annee_id" name="source_annee_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner l'année source</option>
                            <?php foreach ($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['nom_annee']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="target_annee_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Année cible *
                        </label>
                        <select id="target_annee_id" name="target_annee_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner l'année cible</option>
                            <?php foreach ($annees_academiques as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['nom_annee']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded p-3">
                        <p class="text-sm text-yellow-700">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            Cette action va copier tous les cours de l'année source vers l'année cible.
                            Les conflits d'horaire ne seront pas vérifiés.
                        </p>
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
            // Reset form
            document.getElementById('add_filiere_id').value = '';
            document.getElementById('add_classe_id').value = '';
            document.getElementById('add_matiere').value = '';
            document.getElementById('add_enseignant_id').value = '';
            document.getElementById('add_jour_semaine').selectedIndex = 0;
            document.getElementById('add_type_cours').selectedIndex = 0;
            document.getElementById('add_heure_debut').value = '';
            document.getElementById('add_heure_fin').value = '';
            document.getElementById('add_salle').value = '';
        }

        function openEditModal(id, filiereId, classeId, matiere, enseignantId, jour, heureDebut, heureFin, salle, typeCours) {
            document.getElementById('edit_cours_id').value = id;
            document.getElementById('edit_filiere_id').value = filiereId;
            document.getElementById('edit_classe_id').value = classeId;
            document.getElementById('edit_matiere').value = matiere;
            document.getElementById('edit_enseignant_id').value = enseignantId;
            document.getElementById('edit_jour_semaine').value = jour;
            document.getElementById('edit_heure_debut').value = heureDebut;
            document.getElementById('edit_heure_fin').value = heureFin;
            document.getElementById('edit_salle').value = salle;
            document.getElementById('edit_type_cours').value = typeCours;
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
            document.getElementById('source_annee_id').value = '';
            document.getElementById('target_annee_id').value = '';
        }

        function confirmDelete(id, matiere) {
            if (confirm('Êtes-vous sûr de vouloir supprimer le cours "' + matiere + '" ? Cette action est irréversible.')) {
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