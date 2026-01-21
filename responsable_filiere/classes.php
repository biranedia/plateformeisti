<?php
/**
 * Gestion des classes - Responsable de filière
 * Gestion des classes et affectation des étudiants
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

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'add_class') {
            $nom_classe = sanitize($_POST['nom_classe']);
            $niveau = sanitize($_POST['niveau']);
            $capacite_max = (int)$_POST['capacite_max'];
            $annee_academique_id = (int)$_POST['annee_academique_id'];
            $description = sanitize($_POST['description']);

            // Validation
            if (empty($nom_classe) || empty($niveau)) {
                $messages[] = ['type' => 'error', 'text' => 'Le nom de la classe et le niveau sont obligatoires.'];
            } else {
                try {
                    // Vérifier si la classe existe déjà
                    $check_query = "SELECT id FROM classes WHERE nom_classe = :nom AND filiere_id = :filiere_id AND annee_academique_id = :annee_id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':nom', $nom_classe);
                    $check_stmt->bindParam(':filiere_id', $filiere['id']);
                    $check_stmt->bindParam(':annee_id', $annee_academique_id);
                    $check_stmt->execute();

                    if ($check_stmt->fetch()) {
                        $messages[] = ['type' => 'error', 'text' => 'Une classe avec ce nom existe déjà pour cette année académique.'];
                    } else {
                        // Ajouter la classe
                        $query = "INSERT INTO classes (nom_classe, niveau, capacite_max, filiere_id, annee_academique_id, description, created_at)
                                 VALUES (:nom, :niveau, :capacite, :filiere_id, :annee_id, :description, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':nom', $nom_classe);
                        $stmt->bindParam(':niveau', $niveau);
                        $stmt->bindParam(':capacite', $capacite_max);
                        $stmt->bindParam(':filiere_id', $filiere['id']);
                        $stmt->bindParam(':annee_id', $annee_academique_id);
                        $stmt->bindParam(':description', $description);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Classe ajoutée avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Ajout de classe: $nom_classe", "classes");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'edit_class') {
            $class_id = (int)$_POST['class_id'];
            $nom_classe = sanitize($_POST['nom_classe']);
            $niveau = sanitize($_POST['niveau']);
            $capacite_max = (int)$_POST['capacite_max'];
            $description = sanitize($_POST['description']);

            // Validation
            if (empty($nom_classe) || empty($niveau)) {
                $messages[] = ['type' => 'error', 'text' => 'Le nom de la classe et le niveau sont obligatoires.'];
            } else {
                try {
                    // Vérifier si une autre classe avec ce nom existe
                    $check_query = "SELECT id FROM classes WHERE nom_classe = :nom AND filiere_id = :filiere_id AND id != :id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':nom', $nom_classe);
                    $check_stmt->bindParam(':filiere_id', $filiere['id']);
                    $check_stmt->bindParam(':id', $class_id);
                    $check_stmt->execute();

                    if ($check_stmt->fetch()) {
                        $messages[] = ['type' => 'error', 'text' => 'Une classe avec ce nom existe déjà.'];
                    } else {
                        // Modifier la classe
                        $query = "UPDATE classes SET nom_classe = :nom, niveau = :niveau, capacite_max = :capacite,
                                 description = :description WHERE id = :id AND filiere_id = :filiere_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':nom', $nom_classe);
                        $stmt->bindParam(':niveau', $niveau);
                        $stmt->bindParam(':capacite', $capacite_max);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':id', $class_id);
                        $stmt->bindParam(':filiere_id', $filiere['id']);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Classe modifiée avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Modification de classe: $nom_classe", "classes");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'delete_class') {
            $class_id = (int)$_POST['class_id'];

            try {
                // Vérifier s'il y a des étudiants dans cette classe
                $check_students = "SELECT COUNT(*) as count FROM etudiants_classes WHERE classe_id = :id";
                $check_stmt = $conn->prepare($check_students);
                $check_stmt->bindParam(':id', $class_id);
                $check_stmt->execute();
                $student_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

                if ($student_count > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette classe car elle contient des étudiants.'];
                } else {
                    // Récupérer le nom avant suppression
                    $name_query = "SELECT nom_classe FROM classes WHERE id = :id AND filiere_id = :filiere_id";
                    $name_stmt = $conn->prepare($name_query);
                    $name_stmt->bindParam(':id', $class_id);
                    $name_stmt->bindParam(':filiere_id', $filiere['id']);
                    $name_stmt->execute();
                    $class_name = $name_stmt->fetch(PDO::FETCH_ASSOC)['nom_classe'];

                    // Supprimer la classe
                    $query = "DELETE FROM classes WHERE id = :id AND filiere_id = :filiere_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':id', $class_id);
                    $stmt->bindParam(':filiere_id', $filiere['id']);
                    $stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Classe supprimée avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Suppression de classe: $class_name", "classes");
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'assign_student') {
            $class_id = (int)$_POST['class_id'];
            $student_id = (int)$_POST['student_id'];
            $annee_academique_id = (int)$_POST['annee_academique_id'];

            try {
                // Vérifier la capacité de la classe
                $capacity_query = "SELECT capacite_max, (SELECT COUNT(*) FROM etudiants_classes WHERE classe_id = classes.id) as current_count
                                 FROM classes WHERE id = :id AND filiere_id = :filiere_id";
                $capacity_stmt = $conn->prepare($capacity_query);
                $capacity_stmt->bindParam(':id', $class_id);
                $capacity_stmt->bindParam(':filiere_id', $filiere['id']);
                $capacity_stmt->execute();
                $capacity_info = $capacity_stmt->fetch(PDO::FETCH_ASSOC);

                if ($capacity_info['current_count'] >= $capacity_info['capacite_max']) {
                    $messages[] = ['type' => 'error', 'text' => 'La classe a atteint sa capacité maximale.'];
                } else {
                    // Vérifier si l'étudiant est déjà dans une classe pour cette année
                    $check_query = "SELECT id FROM etudiants_classes WHERE etudiant_id = :student_id AND annee_academique_id = :annee_id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':student_id', $student_id);
                    $check_stmt->bindParam(':annee_id', $annee_academique_id);
                    $check_stmt->execute();

                    if ($check_stmt->fetch()) {
                        $messages[] = ['type' => 'error', 'text' => 'Cet étudiant est déjà assigné à une classe pour cette année académique.'];
                    } else {
                        // Assigner l'étudiant à la classe
                        $query = "INSERT INTO etudiants_classes (etudiant_id, classe_id, annee_academique_id, date_inscription)
                                 VALUES (:student_id, :class_id, :annee_id, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':student_id', $student_id);
                        $stmt->bindParam(':class_id', $class_id);
                        $stmt->bindParam(':annee_id', $annee_academique_id);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Étudiant assigné à la classe avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Assignation étudiant ID $student_id à classe ID $class_id", "classes");
                    }
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'remove_student') {
            $assignment_id = (int)$_POST['assignment_id'];

            try {
                // Récupérer les informations avant suppression
                $info_query = "SELECT ec.*, u.nom, u.prenom, c.nom_classe FROM etudiants_classes ec
                              JOIN users u ON ec.etudiant_id = u.id
                              JOIN classes c ON ec.classe_id = c.id
                              WHERE ec.id = :id AND c.filiere_id = :filiere_id";
                $info_stmt = $conn->prepare($info_query);
                $info_stmt->bindParam(':id', $assignment_id);
                $info_stmt->bindParam(':filiere_id', $filiere['id']);
                $info_stmt->execute();
                $assignment_info = $info_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$assignment_info) {
                    throw new Exception('Assignation non trouvée ou non autorisée.');
                }

                // Supprimer l'assignation
                $query = "DELETE FROM etudiants_classes WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $assignment_id);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Étudiant retiré de la classe avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Retrait étudiant " . $assignment_info['nom'] . ' ' . $assignment_info['prenom'] . " de classe " . $assignment_info['nom_classe'], "classes");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'export_classes') {
            $format = sanitize($_POST['format']);
            $annee_filter = sanitize($_POST['annee_filter']);

            try {
                // Construction de la requête d'export
                $query = "SELECT c.*, aa.annee_academique,
                         (SELECT COUNT(*) FROM etudiants_classes ec WHERE ec.classe_id = c.id) as nb_etudiants
                         FROM classes c
                         LEFT JOIN annees_academiques aa ON c.annee_academique_id = aa.id
                         WHERE c.filiere_id = :filiere_id";

                $params = [':filiere_id' => $filiere['id']];

                if ($annee_filter !== 'all') {
                    $query .= " AND c.annee_academique_id = :annee";
                    $params[':annee'] = $annee_filter;
                }

                $query .= " ORDER BY c.nom_classe";

                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Simulation d'export
                $messages[] = ['type' => 'success', 'text' => count($export_data) . ' classes exportées au format ' . strtoupper($format) . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Export des classes au format $format", "classes");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'export: ' . $e->getMessage()];
            }
        }
    }
}

// Filtres
$annee_filter = $_GET['annee'] ?? 'all';
$search = $_GET['search'] ?? '';

// Construction de la requête avec filtres
$query = "SELECT c.*, aa.annee_academique,
         (SELECT COUNT(*) FROM etudiants_classes ec WHERE ec.classe_id = c.id) as nb_etudiants
         FROM classes c
         LEFT JOIN annees_academiques aa ON c.annee_academique_id = aa.id
         WHERE c.filiere_id = :filiere_id";

$params = [':filiere_id' => $filiere['id']];

if ($annee_filter !== 'all') {
    $query .= " AND c.annee_academique_id = :annee";
    $params[':annee'] = $annee_filter;
}

if (!empty($search)) {
    $query .= " AND (c.nom_classe LIKE :search OR c.niveau LIKE :search OR c.description LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY c.nom_classe";

$classes_stmt = $conn->prepare($query);
$classes_stmt->execute($params);
$classes_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des classes
$stats_classes = [
    'total' => 0,
    'total_etudiants' => 0,
    'moyenne_capacite' => 0,
    'classes_pleines' => 0
];

// Calcul des statistiques
$total_capacite = 0;
foreach ($classes_list as $classe) {
    $stats_classes['total']++;
    $stats_classes['total_etudiants'] += $classe['nb_etudiants'];
    $total_capacite += $classe['capacite_max'];

    if ($classe['nb_etudiants'] >= $classe['capacite_max']) {
        $stats_classes['classes_pleines']++;
    }
}

$stats_classes['moyenne_capacite'] = $stats_classes['total'] > 0 ? round($total_capacite / $stats_classes['total']) : 0;

// Récupération des années académiques pour les filtres
$annees_query = "SELECT id, annee_academique FROM annees_academiques WHERE is_active = 1 ORDER BY annee_academique DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees_list = $annees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Niveaux disponibles
$niveaux = [
    'L1' => 'Licence 1',
    'L2' => 'Licence 2',
    'L3' => 'Licence 3',
    'M1' => 'Master 1',
    'M2' => 'Master 2',
    'D1' => 'Doctorat 1',
    'D2' => 'Doctorat 2',
    'D3' => 'Doctorat 3'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Classes - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chalkboard text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Filière</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Filière: <?php echo htmlspecialchars($filiere['nom'] ?? ($filiere['nom_filiere'] ?? '')); ?></span>
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
                <a href="classes.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-chalkboard mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
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
                        <i class="fas fa-chalkboard text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total Classes</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_classes['total']; ?></p>
                        <p class="text-sm text-gray-600">classes actives</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-users text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total Étudiants</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_classes['total_etudiants']; ?></p>
                        <p class="text-sm text-gray-600">inscrits</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-chart-bar text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Capacité Moyenne</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo $stats_classes['moyenne_capacite']; ?></p>
                        <p class="text-sm text-gray-600">étudiants/classe</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Classes Pleines</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats_classes['classes_pleines']; ?></p>
                        <p class="text-sm text-gray-600">à capacité max</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-chalkboard mr-2"></i>Gestion des classes
                </h2>

                <div class="flex space-x-2">
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Ajouter une classe
                    </button>
                    <button onclick="openExportModal()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-file-export mr-2"></i>Exporter
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
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
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Nom de classe, niveau..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($annee_filter !== 'all' || !empty($search)): ?>
                        <a href="classes.php"
                           class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Réinitialiser
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Liste des classes -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (empty($classes_list)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-chalkboard text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune classe trouvée</h3>
                    <p class="text-gray-500 mb-4">Il n'y a pas de classes correspondant à vos critères de recherche.</p>
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Créer la première classe
                    </button>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niveau</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Capacité</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiants</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($classes_list as $classe): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-chalkboard text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($classe['nom_classe']); ?>
                                                </div>
                                                <?php if ($classe['description']): ?>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars(substr($classe['description'], 0, 50)); ?>...
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?php echo htmlspecialchars($niveaux[$classe['niveau']] ?? $classe['niveau']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $classe['nb_etudiants']; ?> / <?php echo $classe['capacite_max']; ?>
                                        <div class="w-full bg-gray-200 rounded-full h-2 mt-1">
                                            <div class="bg-<?php echo ($classe['nb_etudiants'] / $classe['capacite_max']) > 0.9 ? 'red' : (($classe['nb_etudiants'] / $classe['capacite_max']) > 0.7 ? 'yellow' : 'green'); ?>-600 h-2 rounded-full"
                                                 style="width: <?php echo min(100, ($classe['nb_etudiants'] / max(1, $classe['capacite_max'])) * 100); ?>%"></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <button onclick="openStudentsModal(<?php echo $classe['id']; ?>, '<?php echo addslashes($classe['nom_classe']); ?>')"
                                                class="text-blue-600 hover:text-blue-900">
                                            <?php echo $classe['nb_etudiants']; ?> étudiant(s)
                                        </button>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($classe['annee_academique'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(<?php echo $classe['id']; ?>, '<?php echo addslashes($classe['nom_classe']); ?>', '<?php echo addslashes($classe['niveau']); ?>', <?php echo $classe['capacite_max']; ?>, '<?php echo addslashes($classe['description'] ?? ''); ?>')"
                                                    class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="openAssignModal(<?php echo $classe['id']; ?>, '<?php echo addslashes($classe['nom_classe']); ?>')"
                                                    class="text-green-600 hover:text-green-900">
                                                <i class="fas fa-user-plus"></i>
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $classe['id']; ?>, '<?php echo addslashes($classe['nom_classe']); ?>')"
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

    <!-- Modal Ajouter Classe -->
    <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-plus mr-2"></i>Ajouter une classe
                    </h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_class">

                    <div>
                        <label for="add_nom_classe" class="block text-sm font-medium text-gray-700 mb-2">
                            Nom de la classe *
                        </label>
                        <input type="text" id="add_nom_classe" name="nom_classe" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="add_niveau" class="block text-sm font-medium text-gray-700 mb-2">
                            Niveau *
                        </label>
                        <select id="add_niveau" name="niveau" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($niveaux as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="add_capacite_max" class="block text-sm font-medium text-gray-700 mb-2">
                            Capacité maximale *
                        </label>
                        <input type="number" id="add_capacite_max" name="capacite_max" min="1" max="200" value="30" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
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

                    <div>
                        <label for="add_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description (optionnel)
                        </label>
                        <textarea id="add_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Description de la classe..."></textarea>
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

    <!-- Modal Modifier Classe -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-edit mr-2"></i>Modifier la classe
                    </h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit_class">
                    <input type="hidden" name="class_id" id="editModalId">

                    <div>
                        <label for="edit_nom_classe" class="block text-sm font-medium text-gray-700 mb-2">
                            Nom de la classe *
                        </label>
                        <input type="text" id="edit_nom_classe" name="nom_classe" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_niveau" class="block text-sm font-medium text-gray-700 mb-2">
                            Niveau *
                        </label>
                        <select id="edit_niveau" name="niveau" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($niveaux as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_capacite_max" class="block text-sm font-medium text-gray-700 mb-2">
                            Capacité maximale *
                        </label>
                        <input type="number" id="edit_capacite_max" name="capacite_max" min="1" max="200" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description (optionnel)
                        </label>
                        <textarea id="edit_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
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

    <!-- Modal Assigner Étudiant -->
    <div id="assignModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2xl shadow-lg rounded-md bg-white max-h-screen overflow-y-auto">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-user-plus mr-2"></i>Assigner un étudiant
                    </h3>
                    <button onclick="closeAssignModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded">
                    <p class="text-sm text-blue-800" id="assignModalClass"></p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="assign_student">
                    <input type="hidden" name="class_id" id="assignModalClassId">

                    <div>
                        <label for="assign_student" class="block text-sm font-medium text-gray-700 mb-2">
                            Étudiant *
                        </label>
                        <select id="assign_student" name="student_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner un étudiant...</option>
                            <!-- Les options seront chargées dynamiquement -->
                        </select>
                    </div>

                    <div>
                        <label for="assign_annee" class="block text-sm font-medium text-gray-700 mb-2">
                            Année académique *
                        </label>
                        <select id="assign_annee" name="annee_academique_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($annees_list as $annee): ?>
                                <option value="<?php echo $annee['id']; ?>"><?php echo htmlspecialchars($annee['annee_academique']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAssignModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Assigner
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Liste Étudiants -->
    <div id="studentsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-4xl shadow-lg rounded-md bg-white max-h-screen overflow-y-auto">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-users mr-2"></i>Étudiants de la classe
                    </h3>
                    <button onclick="closeStudentsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-gray-50 rounded">
                    <p class="text-sm text-gray-600" id="studentsModalTitle"></p>
                </div>

                <div id="studentsList" class="space-y-2">
                    <!-- La liste des étudiants sera chargée ici -->
                </div>

                <div class="flex justify-end mt-6">
                    <button onclick="closeStudentsModal()"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Export -->
    <div id="exportModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-file-export mr-2"></i>Exporter les classes
                    </h3>
                    <button onclick="closeExportModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="export_classes">

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
            document.getElementById('add_nom_classe').value = '';
            document.getElementById('add_niveau').selectedIndex = 0;
            document.getElementById('add_capacite_max').value = '30';
            document.getElementById('add_annee_academique').selectedIndex = 0;
            document.getElementById('add_description').value = '';
        }

        function openEditModal(id, nom, niveau, capacite, description) {
            document.getElementById('editModalId').value = id;
            document.getElementById('edit_nom_classe').value = nom;
            document.getElementById('edit_niveau').value = niveau;
            document.getElementById('edit_capacite_max').value = capacite;
            document.getElementById('edit_description').value = description;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function openAssignModal(classId, className) {
            document.getElementById('assignModalClassId').value = classId;
            document.getElementById('assignModalClass').textContent = 'Classe: ' + className;

            // Charger la liste des étudiants disponibles
            fetch('get_available_students.php?class_id=' + classId)
                .then(response => response.json())
                .then(data => {
                    const select = document.getElementById('assign_student');
                    select.innerHTML = '<option value="">Sélectionner un étudiant...</option>';
                    data.forEach(student => {
                        const option = document.createElement('option');
                        option.value = student.id;
                        option.textContent = student.nom + ' ' + student.prenom + ' (' + student.email + ')';
                        select.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Erreur lors du chargement des étudiants:', error);
                });

            document.getElementById('assignModal').classList.remove('hidden');
        }

        function closeAssignModal() {
            document.getElementById('assignModal').classList.add('hidden');
            document.getElementById('assign_student').selectedIndex = 0;
            document.getElementById('assign_annee').selectedIndex = 0;
        }

        function openStudentsModal(classId, className) {
            document.getElementById('studentsModalTitle').textContent = 'Classe: ' + className;

            // Charger la liste des étudiants de la classe
            fetch('get_class_students.php?class_id=' + classId)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('studentsList');
                    if (data.length === 0) {
                        container.innerHTML = '<p class="text-gray-500 text-center py-4">Aucun étudiant dans cette classe.</p>';
                    } else {
                        container.innerHTML = data.map(student => `
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0 h-8 w-8">
                                        <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600 text-sm"></i>
                                        </div>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm font-medium text-gray-900">${student.nom} ${student.prenom}</p>
                                        <p class="text-sm text-gray-500">${student.email}</p>
                                    </div>
                                </div>
                                <button onclick="removeStudent(${student.assignment_id}, '${student.nom} ${student.prenom}')"
                                        class="text-red-600 hover:text-red-900">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        `).join('');
                    }
                })
                .catch(error => {
                    console.error('Erreur lors du chargement des étudiants:', error);
                });

            document.getElementById('studentsModal').classList.remove('hidden');
        }

        function closeStudentsModal() {
            document.getElementById('studentsModal').classList.add('hidden');
        }

        function openExportModal() {
            document.getElementById('exportModal').classList.remove('hidden');
        }

        function closeExportModal() {
            document.getElementById('exportModal').classList.add('hidden');
            document.getElementById('exportFormat').selectedIndex = 0;
            document.getElementById('exportAnneeFilter').selectedIndex = 0;
        }

        function confirmDelete(id, nom) {
            if (confirm('Êtes-vous sûr de vouloir supprimer la classe "' + nom + '" ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_class">
                    <input type="hidden" name="class_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function removeStudent(assignmentId, studentName) {
            if (confirm('Êtes-vous sûr de vouloir retirer ' + studentName + ' de cette classe ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="remove_student">
                    <input type="hidden" name="assignment_id" value="${assignmentId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>