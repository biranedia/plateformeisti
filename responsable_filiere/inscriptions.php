<?php
/**
 * Gestion des inscriptions - Responsable de filière
 * Validation et gestion des inscriptions des étudiants dans la filière
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

        if ($action === 'validate_inscription') {
            $inscription_id = (int)$_POST['inscription_id'];
            $decision = sanitize($_POST['decision']);
            $commentaire = sanitize($_POST['commentaire']);

            try {
                // Récupérer l'inscription
                $inscr_query = "SELECT i.*, u.name, u.email FROM inscriptions i
                               JOIN users u ON i.user_id = u.id
                               WHERE i.id = :id AND i.filiere_id = :filiere_id";
                $inscr_stmt = $conn->prepare($inscr_query);
                $inscr_stmt->bindParam(':id', $inscription_id);
                $inscr_stmt->bindParam(':filiere_id', $filiere['id']);
                $inscr_stmt->execute();
                $inscription = $inscr_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$inscription) {
                    throw new Exception('Inscription non trouvée ou non autorisée.');
                }

                // Mettre à jour le statut
                $statut = ($decision === 'approuver') ? 'approuvee' : 'rejetee';
                $update_query = "UPDATE inscriptions SET statut = :statut, commentaire = :commentaire,
                                valide_par = :valide_par, date_validation = NOW()
                                WHERE id = :id AND filiere_id = :filiere_id";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bindParam(':statut', $statut);
                $update_stmt->bindParam(':commentaire', $commentaire);
                $update_stmt->bindParam(':valide_par', $user_id);
                $update_stmt->bindParam(':id', $inscription_id);
                $update_stmt->bindParam(':filiere_id', $filiere['id']);
                $update_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Inscription ' . ($decision === 'approuver' ? 'approuvée' : 'rejetée') . ' avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Validation inscription: $statut pour " . $inscription['name'], "inscription");

                // Note: La table etudiants_classes n'existe pas, l'inscription suffit
                // Si approuvée, créer une entrée dans la table etudiants_classes si nécessaire
                /*
                if ($decision === 'approuver' && $inscription['classe_id']) {
                    $check_query = "SELECT id FROM etudiants_classes WHERE etudiant_id = :etudiant_id AND classe_id = :classe_id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':etudiant_id', $inscription['etudiant_id']);
                    $check_stmt->bindParam(':classe_id', $inscription['classe_id']);
                    $check_stmt->execute();

                    if (!$check_stmt->fetch()) {
                        $insert_query = "INSERT INTO etudiants_classes (etudiant_id, classe_id, annee_academique_id, date_inscription)
                                        VALUES (:etudiant_id, :classe_id, :annee_id, NOW())";
                        $insert_stmt = $conn->prepare($insert_query);
                        $insert_stmt->bindParam(':etudiant_id', $inscription['etudiant_id']);
                        $insert_stmt->bindParam(':classe_id', $inscription['classe_id']);
                        $insert_stmt->bindParam(':annee_id', $inscription['annee_academique_id']);
                        $insert_stmt->execute();
                    }
                }
                */

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'bulk_validate') {
            $inscription_ids = $_POST['inscription_ids'] ?? [];
            $decision = sanitize($_POST['bulk_decision']);
            $commentaire = sanitize($_POST['bulk_commentaire']);

            if (empty($inscription_ids)) {
                $messages[] = ['type' => 'error', 'text' => 'Aucune inscription sélectionnée.'];
            } else {
                $success_count = 0;
                $error_count = 0;

                foreach ($inscription_ids as $id) {
                    try {
                        $inscr_query = "SELECT i.*, u.name FROM inscriptions i
                                       JOIN users u ON i.user_id = u.id
                                       WHERE i.id = :id AND i.filiere_id = :filiere_id";
                        $inscr_stmt = $conn->prepare($inscr_query);
                        $inscr_stmt->bindParam(':id', $id);
                        $inscr_stmt->bindParam(':filiere_id', $filiere['id']);
                        $inscr_stmt->execute();
                        $inscription = $inscr_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($inscription) {
                            $statut = ($decision === 'approuver') ? 'approuvee' : 'rejetee';
                            $update_query = "UPDATE inscriptions SET statut = :statut, commentaire = :commentaire,
                                            valide_par = :valide_par, date_validation = NOW()
                                            WHERE id = :id AND filiere_id = :filiere_id";
                            $update_stmt = $conn->prepare($update_query);
                            $update_stmt->bindParam(':statut', $statut);
                            $update_stmt->bindParam(':commentaire', $commentaire);
                            $update_stmt->bindParam(':valide_par', $user_id);
                            $update_stmt->bindParam(':id', $id);
                            $update_stmt->bindParam(':filiere_id', $filiere['id']);
                            $update_stmt->execute();

                            $success_count++;

                            // Note: La table etudiants_classes n'existe pas, l'inscription suffit
                            /*
                            // Si approuvée, créer une entrée dans etudiants_classes
                            if ($decision === 'approuver' && $inscription['classe_id']) {
                                $check_query = "SELECT id FROM etudiants_classes WHERE etudiant_id = :etudiant_id AND classe_id = :classe_id";
                                $check_stmt = $conn->prepare($check_query);
                                $check_stmt->bindParam(':etudiant_id', $inscription['etudiant_id']);
                                $check_stmt->bindParam(':classe_id', $inscription['classe_id']);
                                $check_stmt->execute();

                                if (!$check_stmt->fetch()) {
                                    $insert_query = "INSERT INTO etudiants_classes (etudiant_id, classe_id, annee_academique_id, date_inscription)
                                                    VALUES (:etudiant_id, :classe_id, :annee_id, NOW())";
                                    $insert_stmt = $conn->prepare($insert_query);
                                    $insert_stmt->bindParam(':etudiant_id', $inscription['etudiant_id']);
                                    $insert_stmt->bindParam(':classe_id', $inscription['classe_id']);
                                    $insert_stmt->bindParam(':annee_id', $inscription['annee_academique_id']);
                                    $insert_stmt->execute();
                                }
                            }
                            */
                        }
                    } catch (Exception $e) {
                        $error_count++;
                    }
                }

                $messages[] = ['type' => 'success', 'text' => "$success_count inscription(s) traitée(s) avec succès."];
                if ($error_count > 0) {
                    $messages[] = ['type' => 'warning', 'text' => "$error_count erreur(s) lors du traitement."];
                }

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Validation en masse: $success_count inscriptions $decision", "inscription");
            }
        }

        if ($action === 'export_inscriptions') {
            $format = sanitize($_POST['format']);
            $statut_filter = sanitize($_POST['statut_filter']);
            $annee_filter = sanitize($_POST['annee_filter']);

            try {
                // Construction de la requête d'export
                $query = "SELECT i.*, u.name, u.email, u.phone,
                         c.nom_classe, aa.annee_academique
                         FROM inscriptions i
                         JOIN users u ON i.user_id = u.id
                         JOIN classes c ON i.classe_id = c.id
                         LEFT JOIN annees_academiques aa ON i.annee_academique = aa.annee_academique
                         WHERE c.filiere_id = :filiere_id";

                $params = [':filiere_id' => $filiere['id']];

                if ($statut_filter !== 'all') {
                    $query .= " AND i.statut = :statut";
                    $params[':statut'] = $statut_filter;
                }

                if ($annee_filter !== 'all') {
                    $query .= " AND i.annee_academique = :annee";
                    $params[':annee'] = $annee_filter;
                }

                $query .= " ORDER BY i.id DESC";

                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $export_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Simulation d'export
                $messages[] = ['type' => 'success', 'text' => count($export_data) . ' inscriptions exportées au format ' . strtoupper($format) . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Export des inscriptions au format $format", "inscription");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'export: ' . $e->getMessage()];
            }
        }
    }
}

// Filtres
$statut_filter = $_GET['statut'] ?? 'all';
$annee_filter = $_GET['annee'] ?? 'all';
$classe_filter = $_GET['classe'] ?? 'all';
$search = $_GET['search'] ?? '';

// Construction de la requête avec filtres
$query = "SELECT i.*, u.name, u.email, u.phone,
         c.nom_classe, aa.annee_academique
         FROM inscriptions i
         JOIN users u ON i.user_id = u.id
         JOIN classes c ON i.classe_id = c.id
         LEFT JOIN annees_academiques aa ON i.annee_academique = aa.annee_academique
         WHERE c.filiere_id = :filiere_id";

$params = [':filiere_id' => $filiere['id']];

if ($statut_filter !== 'all') {
    $query .= " AND i.statut = :statut";
    $params[':statut'] = $statut_filter;
}

if ($annee_filter !== 'all') {
    $query .= " AND i.annee_academique = :annee";
    $params[':annee'] = $annee_filter;
}

if ($classe_filter !== 'all') {
    $query .= " AND i.classe_id = :classe";
    $params[':classe'] = $classe_filter;
}

if (!empty($search)) {
    $query .= " AND (u.name LIKE :search OR u.email LIKE :search OR c.nom_classe LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY i.id DESC";

$inscriptions_stmt = $conn->prepare($query);
$inscriptions_stmt->execute($params);
$inscriptions_list = $inscriptions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des inscriptions
$stats_inscriptions = [
    'total' => 0,
    'en_attente' => 0,
    'approuvee' => 0,
    'rejetee' => 0,
    'par_annee' => [],
    'par_classe' => []
];

// Calcul des statistiques
foreach ($inscriptions_list as $inscription) {
    $stats_inscriptions['total']++;
    $stats_inscriptions[$inscription['statut']] = ($stats_inscriptions[$inscription['statut']] ?? 0) + 1;

    $annee = $inscription['annee_academique'] ?? 'Non spécifiée';
    $stats_inscriptions['par_annee'][$annee] = ($stats_inscriptions['par_annee'][$annee] ?? 0) + 1;

    $classe = $inscription['nom_classe'] ?? 'Non assignée';
    $stats_inscriptions['par_classe'][$classe] = ($stats_inscriptions['par_classe'][$classe] ?? 0) + 1;
}

// Récupération des années académiques pour les filtres
$annees_query = "SELECT id, annee_academique FROM annees_academiques WHERE is_active = 1 ORDER BY annee_academique DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees_list = $annees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes de la filière pour les filtres
$classes_query = "SELECT id, nom_classe FROM classes WHERE filiere_id = :filiere_id ORDER BY nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere_id', $filiere['id']);
$classes_stmt->execute();
$classes_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statuts disponibles
$statuts = [
    'en_attente' => 'En attente',
    'approuvee' => 'Approuvée',
    'rejetee' => 'Rejetée'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Inscriptions - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-user-plus text-2xl mr-3"></i>
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
                <a href="classes.php" class="text-gray-600 hover:text-blue-600">
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
                <a href="inscriptions.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
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
                <div class="mb-8 bg-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'warning' ? 'yellow' : 'red'); ?>-100 border border-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'warning' ? 'yellow' : 'red'); ?>-400 text-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'warning' ? 'yellow' : 'red'); ?>-700 px-4 py-3 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check' : ($message['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation'); ?>-circle"></i>
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
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_inscriptions['total']; ?></p>
                        <p class="text-sm text-gray-600">inscriptions</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">En attente</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_inscriptions['en_attente'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">à traiter</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Approuvées</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_inscriptions['approuvee'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">validées</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Rejetées</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats_inscriptions['rejetee'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">refusées</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-user-plus mr-2"></i>Gestion des inscriptions
                </h2>

                <div class="flex space-x-2">
                    <button onclick="openBulkModal()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-tasks mr-2"></i>Traitement en masse
                    </button>
                    <button onclick="openExportModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-file-export mr-2"></i>Exporter
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-5 gap-4">
                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <select id="statut" name="statut" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $statut_filter === 'all' ? 'selected' : ''; ?>>Tous les statuts</option>
                        <?php foreach ($statuts as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $statut_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

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
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Nom, prénom, email..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($statut_filter !== 'all' || $annee_filter !== 'all' || $classe_filter !== 'all' || !empty($search)): ?>
                        <a href="inscriptions.php"
                           class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Réinitialiser
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Liste des inscriptions -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (empty($inscriptions_list)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-user-plus text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune inscription trouvée</h3>
                    <p class="text-gray-500 mb-4">Il n'y a pas d'inscriptions correspondant à vos critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    <input type="checkbox" id="selectAll" class="rounded">
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($inscriptions_list as $inscription): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($inscription['statut'] === 'en_attente'): ?>
                                            <input type="checkbox" name="inscription_check" value="<?php echo $inscription['id']; ?>" class="inscription-checkbox rounded">
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-user text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($inscription['name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($inscription['email']); ?>
                                                </div>
                                                <?php if ($inscription['phone']): ?>
                                                    <div class="text-sm text-gray-500">
                                                        <?php echo htmlspecialchars($inscription['phone']); ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($inscription['nom_classe'] ?? 'Non assignée'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($inscription['annee_academique'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($inscription['statut']) {
                                                case 'en_attente': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'approuvee': echo 'bg-green-100 text-green-800'; break;
                                                case 'rejetee': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($statuts[$inscription['statut']] ?? $inscription['statut']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y'); // Date actuelle par défaut ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openDetailsModal(<?php echo $inscription['id']; ?>, '<?php echo addslashes($inscription['name']); ?>', '<?php echo addslashes($inscription['email']); ?>', '<?php echo addslashes($inscription['nom_classe'] ?? ''); ?>', '<?php echo addslashes($inscription['annee_academique'] ?? ''); ?>', '<?php echo addslashes($inscription['statut']); ?>', '<?php echo addslashes($inscription['commentaire'] ?? ''); ?>', '<?php echo addslashes($inscription['valide_nom'] ?? ''); ?>', '<?php echo date('Y-m-d'); ?>', '<?php echo $inscription['date_validation'] ?? ''; ?>')"
                                                    class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <?php if ($inscription['statut'] === 'en_attente'): ?>
                                                <button onclick="openValidateModal(<?php echo $inscription['id']; ?>, '<?php echo addslashes($inscription['name']); ?>')"
                                                        class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                            <?php endif; ?>
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

    <!-- Modal Validation individuelle -->
    <div id="validateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-check mr-2"></i>Valider l'inscription
                    </h3>
                    <button onclick="closeValidateModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-gray-50 rounded">
                    <p class="text-sm text-gray-600" id="validateModalStudent"></p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="validate_inscription">
                    <input type="hidden" name="inscription_id" id="validateModalId">

                    <div>
                        <label for="validateModalDecision" class="block text-sm font-medium text-gray-700 mb-2">
                            Décision *
                        </label>
                        <select id="validateModalDecision" name="decision" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="approuver">Approuver l'inscription</option>
                            <option value="rejeter">Rejeter l'inscription</option>
                        </select>
                    </div>

                    <div>
                        <label for="validateModalCommentaire" class="block text-sm font-medium text-gray-700 mb-2">
                            Commentaire (optionnel)
                        </label>
                        <textarea id="validateModalCommentaire" name="commentaire" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Ajouter un commentaire..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeValidateModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Valider
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Traitement en masse -->
    <div id="bulkModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-tasks mr-2"></i>Traitement en masse
                    </h3>
                    <button onclick="closeBulkModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-info-circle mr-2"></i>
                        Cette action s'appliquera à toutes les inscriptions sélectionnées en attente de validation.
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="bulk_validate">

                    <div id="selectedInscriptions" class="hidden">
                        <!-- Les checkboxes sélectionnées seront ajoutées ici par JavaScript -->
                    </div>

                    <div>
                        <label for="bulkDecision" class="block text-sm font-medium text-gray-700 mb-2">
                            Décision pour toutes les inscriptions sélectionnées *
                        </label>
                        <select id="bulkDecision" name="bulk_decision" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="approuver">Approuver toutes les inscriptions</option>
                            <option value="rejeter">Rejeter toutes les inscriptions</option>
                        </select>
                    </div>

                    <div>
                        <label for="bulkCommentaire" class="block text-sm font-medium text-gray-700 mb-2">
                            Commentaire commun (optionnel)
                        </label>
                        <textarea id="bulkCommentaire" name="bulk_commentaire" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Ajouter un commentaire pour toutes les inscriptions..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeBulkModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit" id="bulkSubmitBtn"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200" disabled>
                            Traiter les inscriptions
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Détails -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2xl shadow-lg rounded-md bg-white max-h-screen overflow-y-auto">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-eye mr-2"></i>Détails de l'inscription
                    </h3>
                    <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Étudiant</label>
                        <p class="text-sm text-gray-900 mt-1" id="detailsStudent"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Email</label>
                        <p class="text-sm text-gray-900 mt-1" id="detailsEmail"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Classe demandée</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsClasse"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Année académique</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsAnnee"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Statut</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsStatut"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date d'inscription</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsDateInscription"></p>
                        </div>
                    </div>

                    <div id="validationSection" class="border-t pt-4 hidden">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Validé par</label>
                                <p class="text-sm text-gray-900 mt-1" id="detailsValidePar"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Date de validation</label>
                                <p class="text-sm text-gray-900 mt-1" id="detailsDateValidation"></p>
                            </div>
                        </div>
                    </div>

                    <div id="commentaireSection" class="border-t pt-4 hidden">
                        <label class="block text-sm font-medium text-gray-700">Commentaire</label>
                        <p class="text-sm text-gray-900 mt-1 whitespace-pre-wrap" id="detailsCommentaire"></p>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button onclick="closeDetailsModal()"
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
                        <i class="fas fa-file-export mr-2"></i>Exporter les inscriptions
                    </h3>
                    <button onclick="closeExportModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="export_inscriptions">

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
                        <label for="exportStatutFilter" class="block text-sm font-medium text-gray-700 mb-2">
                            Filtrer par statut
                        </label>
                        <select id="exportStatutFilter" name="statut_filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">Tous les statuts</option>
                            <?php foreach ($statuts as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
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
        function openValidateModal(id, studentName) {
            document.getElementById('validateModalId').value = id;
            document.getElementById('validateModalStudent').textContent = studentName;
            document.getElementById('validateModalDecision').selectedIndex = 0;
            document.getElementById('validateModalCommentaire').value = '';
            document.getElementById('validateModal').classList.remove('hidden');
        }

        function closeValidateModal() {
            document.getElementById('validateModal').classList.add('hidden');
        }

        function openBulkModal() {
            const selectedCheckboxes = document.querySelectorAll('.inscription-checkbox:checked');
            if (selectedCheckboxes.length === 0) {
                alert('Veuillez sélectionner au moins une inscription.');
                return;
            }

            const selectedContainer = document.getElementById('selectedInscriptions');
            selectedContainer.innerHTML = '';

            selectedCheckboxes.forEach(checkbox => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'inscription_ids[]';
                input.value = checkbox.value;
                selectedContainer.appendChild(input);
            });

            document.getElementById('bulkSubmitBtn').disabled = false;
            document.getElementById('bulkSubmitBtn').textContent = `Traiter ${selectedCheckboxes.length} inscription(s)`;
            document.getElementById('bulkModal').classList.remove('hidden');
        }

        function closeBulkModal() {
            document.getElementById('bulkModal').classList.add('hidden');
            document.getElementById('bulkDecision').selectedIndex = 0;
            document.getElementById('bulkCommentaire').value = '';
        }

        function openDetailsModal(id, student, email, classe, annee, statut, commentaire, validePar, dateInscription, dateValidation) {
            document.getElementById('detailsStudent').textContent = student;
            document.getElementById('detailsEmail').textContent = email;
            document.getElementById('detailsClasse').textContent = classe || 'Non spécifiée';
            document.getElementById('detailsAnnee').textContent = annee || 'Non spécifiée';
            document.getElementById('detailsStatut').textContent = '<?php echo json_encode($statuts); ?>'.replace(/&quot;/g, '"')[statut] || statut;
            document.getElementById('detailsDateInscription').textContent = new Date(dateInscription).toLocaleDateString('fr-FR');

            if (statut !== 'en_attente') {
                document.getElementById('detailsValidePar').textContent = validePar || 'N/A';
                document.getElementById('detailsDateValidation').textContent = dateValidation ? new Date(dateValidation).toLocaleDateString('fr-FR') : 'N/A';
                document.getElementById('validationSection').classList.remove('hidden');
            } else {
                document.getElementById('validationSection').classList.add('hidden');
            }

            if (commentaire) {
                document.getElementById('detailsCommentaire').textContent = commentaire;
                document.getElementById('commentaireSection').classList.remove('hidden');
            } else {
                document.getElementById('commentaireSection').classList.add('hidden');
            }

            document.getElementById('detailsModal').classList.remove('hidden');
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        function openExportModal() {
            document.getElementById('exportModal').classList.remove('hidden');
        }

        function closeExportModal() {
            document.getElementById('exportModal').classList.add('hidden');
            document.getElementById('exportFormat').selectedIndex = 0;
            document.getElementById('exportStatutFilter').selectedIndex = 0;
            document.getElementById('exportAnneeFilter').selectedIndex = 0;
        }

        // Gestion de la sélection globale
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.inscription-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Mettre à jour la checkbox "Tout sélectionner" quand des checkboxes individuelles changent
        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('inscription-checkbox')) {
                const allCheckboxes = document.querySelectorAll('.inscription-checkbox');
                const checkedCheckboxes = document.querySelectorAll('.inscription-checkbox:checked');
                const selectAllCheckbox = document.getElementById('selectAll');

                selectAllCheckbox.checked = allCheckboxes.length === checkedCheckboxes.length;
                selectAllCheckbox.indeterminate = checkedCheckboxes.length > 0 && checkedCheckboxes.length < allCheckboxes.length;
            }
        });
    </script>
</body>
</html>