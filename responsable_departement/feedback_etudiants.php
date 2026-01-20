<?php
/**
 * Feedback des étudiants - Responsable de département
 * Consultation et gestion des retours des étudiants
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

        if ($action === 'add_feedback') {
            $titre = sanitize($_POST['titre']);
            $description = sanitize($_POST['description']);
            $categorie = sanitize($_POST['categorie']);
            $priorite = sanitize($_POST['priorite']);
            $statut = 'ouvert';

            // Validation
            if (empty($titre) || empty($description)) {
                $messages[] = ['type' => 'error', 'text' => 'Le titre et la description sont obligatoires.'];
            } else {
                try {
                    // Ajouter le feedback
                    $query = "INSERT INTO feedback_etudiants (titre, description, categorie, priorite, statut, departement_id, created_by, created_at)
                             VALUES (:titre, :description, :categorie, :priorite, :statut, :dept_id, :created_by, NOW())";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':titre', $titre);
                    $stmt->bindParam(':description', $description);
                    $stmt->bindParam(':categorie', $categorie);
                    $stmt->bindParam(':priorite', $priorite);
                    $stmt->bindParam(':statut', $statut);
                    $stmt->bindParam(':dept_id', $departement['id']);
                    $stmt->bindParam(':created_by', $user_id);
                    $stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Feedback ajouté avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Ajout de feedback: $titre", "feedback");

                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'update_feedback_status') {
            $feedback_id = (int)$_POST['feedback_id'];
            $statut = sanitize($_POST['statut']);
            $reponse = sanitize($_POST['reponse']);

            try {
                // Mettre à jour le statut et ajouter une réponse
                $query = "UPDATE feedback_etudiants SET statut = :statut, reponse = :reponse,
                         traite_par = :traite_par, date_traitement = NOW()
                         WHERE id = :id AND departement_id = :dept_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':statut', $statut);
                $stmt->bindParam(':reponse', $reponse);
                $stmt->bindParam(':traite_par', $user_id);
                $stmt->bindParam(':id', $feedback_id);
                $stmt->bindParam(':dept_id', $departement['id']);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Statut du feedback mis à jour avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Mise à jour statut feedback ID $feedback_id: $statut", "feedback");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'delete_feedback') {
            $feedback_id = (int)$_POST['feedback_id'];

            try {
                // Récupérer le titre avant suppression
                $title_query = "SELECT titre FROM feedback_etudiants WHERE id = :id AND departement_id = :dept_id";
                $title_stmt = $conn->prepare($title_query);
                $title_stmt->bindParam(':id', $feedback_id);
                $title_stmt->bindParam(':dept_id', $departement['id']);
                $title_stmt->execute();
                $feedback_title = $title_stmt->fetch(PDO::FETCH_ASSOC)['titre'];

                // Supprimer le feedback
                $query = "DELETE FROM feedback_etudiants WHERE id = :id AND departement_id = :dept_id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $feedback_id);
                $stmt->bindParam(':dept_id', $departement['id']);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Feedback supprimé avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Suppression de feedback: $feedback_title", "feedback");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'export_feedback') {
            $format = sanitize($_POST['format']);
            $categorie_filter = sanitize($_POST['categorie_filter']);
            $statut_filter = sanitize($_POST['statut_filter']);

            try {
                // Construction de la requête d'export
                $query = "SELECT f.*, u.nom as createur_nom, u.prenom as createur_prenom,
                         t.nom as traite_nom, t.prenom as traite_prenom
                         FROM feedback_etudiants f
                         LEFT JOIN users u ON f.created_by = u.id
                         LEFT JOIN users t ON f.traite_par = t.id
                         WHERE f.departement_id = :dept_id";

                $params = [':dept_id' => $departement['id']];

                if ($categorie_filter !== 'all') {
                    $query .= " AND f.categorie = :categorie";
                    $params[':categorie'] = $categorie_filter;
                }

                if ($statut_filter !== 'all') {
                    $query .= " AND f.statut = :statut";
                    $params[':statut'] = $statut_filter;
                }

                $query .= " ORDER BY f.created_at DESC";

                $stmt = $conn->prepare($query);
                $stmt->execute($params);
                $feedback_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Simulation d'export
                $messages[] = ['type' => 'success', 'text' => count($feedback_data) . ' feedbacks exportés au format ' . strtoupper($format) . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Export des feedbacks au format $format", "feedback");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'export: ' . $e->getMessage()];
            }
        }
    }
}

// Filtres
$categorie_filter = $_GET['categorie'] ?? 'all';
$statut_filter = $_GET['statut'] ?? 'all';
$search = $_GET['search'] ?? '';

// Construction de la requête avec filtres
$query = "SELECT f.*, u.nom as createur_nom, u.prenom as createur_prenom,
         t.nom as traite_nom, t.prenom as traite_prenom
         FROM feedback_etudiants f
         LEFT JOIN users u ON f.created_by = u.id
         LEFT JOIN users t ON f.traite_par = t.id
         WHERE f.departement_id = :dept_id";

$params = [':dept_id' => $departement['id']];

if ($categorie_filter !== 'all') {
    $query .= " AND f.categorie = :categorie";
    $params[':categorie'] = $categorie_filter;
}

if ($statut_filter !== 'all') {
    $query .= " AND f.statut = :statut";
    $params[':statut'] = $statut_filter;
}

if (!empty($search)) {
    $query .= " AND (f.titre LIKE :search OR f.description LIKE :search OR u.nom LIKE :search OR u.prenom LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY f.created_at DESC";

$feedback_stmt = $conn->prepare($query);
$feedback_stmt->execute($params);
$feedback_list = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des feedbacks
$stats_feedback = [
    'total' => 0,
    'ouvert' => 0,
    'en_cours' => 0,
    'resolu' => 0,
    'ferme' => 0,
    'par_categorie' => []
];

// Calcul des statistiques
foreach ($feedback_list as $feedback) {
    $stats_feedback['total']++;
    $stats_feedback[$feedback['statut']] = ($stats_feedback[$feedback['statut']] ?? 0) + 1;

    $categorie = $feedback['categorie'];
    $stats_feedback['par_categorie'][$categorie] = ($stats_feedback['par_categorie'][$categorie] ?? 0) + 1;
}

// Catégories et statuts disponibles
$categories = [
    'enseignement' => 'Enseignement',
    'infrastructure' => 'Infrastructure',
    'administration' => 'Administration',
    'services' => 'Services',
    'autre' => 'Autre'
];

$statuts = [
    'ouvert' => 'Ouvert',
    'en_cours' => 'En cours',
    'resolu' => 'Résolu',
    'ferme' => 'Fermé'
];

$priorites = [
    'basse' => 'Basse',
    'normale' => 'Normale',
    'haute' => 'Haute',
    'urgente' => 'Urgente'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Étudiants - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-comments text-2xl mr-3"></i>
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
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="documents_a_valider.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents à valider
                </a>
                <a href="feedback_etudiants.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
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
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-comments text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_feedback['total']; ?></p>
                        <p class="text-sm text-gray-600">feedbacks</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Ouverts</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_feedback['ouvert'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">à traiter</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-spinner text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">En cours</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo $stats_feedback['en_cours'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">en traitement</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Résolus</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_feedback['resolu'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">traités</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-gray-100 rounded-full p-3">
                        <i class="fas fa-archive text-gray-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Fermés</h3>
                        <p class="text-2xl font-bold text-gray-600"><?php echo $stats_feedback['ferme'] ?? 0; ?></p>
                        <p class="text-sm text-gray-600">archivés</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-comments mr-2"></i>Gestion des feedbacks
                </h2>

                <div class="flex space-x-2">
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Ajouter un feedback
                    </button>
                    <button onclick="openExportModal()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-file-export mr-2"></i>Exporter
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="categorie" class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                    <select id="categorie" name="categorie" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $categorie_filter === 'all' ? 'selected' : ''; ?>>Toutes les catégories</option>
                        <?php foreach ($categories as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $categorie_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

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
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Titre, description, auteur..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($categorie_filter !== 'all' || $statut_filter !== 'all' || !empty($search)): ?>
                        <a href="feedback_etudiants.php"
                           class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Réinitialiser
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Liste des feedbacks -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (empty($feedback_list)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-comments text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun feedback trouvé</h3>
                    <p class="text-gray-500 mb-4">Il n'y a pas de feedbacks correspondant à vos critères de recherche.</p>
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Créer le premier feedback
                    </button>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Feedback</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catégorie</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priorité</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Auteur</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($feedback_list as $feedback): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-comment text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($feedback['titre']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 truncate max-w-xs">
                                                    <?php echo htmlspecialchars(substr($feedback['description'], 0, 50)); ?>...
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?php echo htmlspecialchars($categories[$feedback['categorie']] ?? $feedback['categorie']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($feedback['priorite']) {
                                                case 'urgente': echo 'bg-red-100 text-red-800'; break;
                                                case 'haute': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'normale': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'basse': echo 'bg-gray-100 text-gray-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($priorites[$feedback['priorite']] ?? $feedback['priorite']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($feedback['statut']) {
                                                case 'ouvert': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'en_cours': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'resolu': echo 'bg-green-100 text-green-800'; break;
                                                case 'ferme': echo 'bg-gray-100 text-gray-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($statuts[$feedback['statut']] ?? $feedback['statut']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php if ($feedback['createur_nom']): ?>
                                            <?php echo htmlspecialchars($feedback['createur_nom'] . ' ' . $feedback['createur_prenom']); ?>
                                        <?php else: ?>
                                            <span class="text-gray-500">Anonyme</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y', strtotime($feedback['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openDetailsModal(<?php echo $feedback['id']; ?>, '<?php echo addslashes($feedback['titre']); ?>', '<?php echo addslashes($feedback['description']); ?>', '<?php echo addslashes($feedback['categorie']); ?>', '<?php echo addslashes($feedback['priorite']); ?>', '<?php echo addslashes($feedback['statut']); ?>', '<?php echo addslashes($feedback['reponse'] ?? ''); ?>', '<?php echo $feedback['created_at']; ?>', '<?php echo $feedback['date_traitement'] ?? ''; ?>')"
                                                    class="text-blue-600 hover:text-blue-900">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button onclick="openStatusModal(<?php echo $feedback['id']; ?>, '<?php echo addslashes($feedback['titre']); ?>', '<?php echo addslashes($feedback['statut']); ?>')"
                                                    class="text-green-600 hover:text-green-900">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="confirmDelete(<?php echo $feedback['id']; ?>, '<?php echo addslashes($feedback['titre']); ?>')"
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

    <!-- Modal Ajouter Feedback -->
    <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-plus mr-2"></i>Ajouter un feedback
                    </h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_feedback">

                    <div>
                        <label for="add_titre" class="block text-sm font-medium text-gray-700 mb-2">
                            Titre *
                        </label>
                        <input type="text" id="add_titre" name="titre" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="add_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description *
                        </label>
                        <textarea id="add_description" name="description" rows="4" required
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="add_categorie" class="block text-sm font-medium text-gray-700 mb-2">
                                Catégorie *
                            </label>
                            <select id="add_categorie" name="categorie" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($categories as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="add_priorite" class="block text-sm font-medium text-gray-700 mb-2">
                                Priorité *
                            </label>
                            <select id="add_priorite" name="priorite" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <?php foreach ($priorites as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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

    <!-- Modal Modifier Statut -->
    <div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-edit mr-2"></i>Modifier le statut
                    </h3>
                    <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-gray-50 rounded">
                    <p class="text-sm text-gray-600" id="statusModalTitle"></p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_feedback_status">
                    <input type="hidden" name="feedback_id" id="statusModalId">

                    <div>
                        <label for="statusModalStatut" class="block text-sm font-medium text-gray-700 mb-2">
                            Nouveau statut *
                        </label>
                        <select id="statusModalStatut" name="statut" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($statuts as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="statusModalReponse" class="block text-sm font-medium text-gray-700 mb-2">
                            Réponse (optionnel)
                        </label>
                        <textarea id="statusModalReponse" name="reponse" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Ajouter une réponse ou un commentaire..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeStatusModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Mettre à jour
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
                        <i class="fas fa-eye mr-2"></i>Détails du feedback
                    </h3>
                    <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Titre</label>
                        <p class="text-sm text-gray-900 mt-1" id="detailsTitre"></p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700">Description</label>
                        <p class="text-sm text-gray-900 mt-1 whitespace-pre-wrap" id="detailsDescription"></p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Catégorie</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsCategorie"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Priorité</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsPriorite"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Statut</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsStatut"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Date de création</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsDateCreation"></p>
                        </div>
                    </div>

                    <div id="reponseSection" class="border-t pt-4 hidden">
                        <label class="block text-sm font-medium text-gray-700">Réponse</label>
                        <p class="text-sm text-gray-900 mt-1 whitespace-pre-wrap" id="detailsReponse"></p>
                        <div class="mt-2">
                            <label class="block text-sm font-medium text-gray-700">Date de traitement</label>
                            <p class="text-sm text-gray-900 mt-1" id="detailsDateTraitement"></p>
                        </div>
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
                        <i class="fas fa-file-export mr-2"></i>Exporter les feedbacks
                    </h3>
                    <button onclick="closeExportModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="export_feedback">

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
                        <label for="categorieFilter" class="block text-sm font-medium text-gray-700 mb-2">
                            Filtrer par catégorie
                        </label>
                        <select id="categorieFilter" name="categorie_filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">Toutes les catégories</option>
                            <?php foreach ($categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="statutFilter" class="block text-sm font-medium text-gray-700 mb-2">
                            Filtrer par statut
                        </label>
                        <select id="statutFilter" name="statut_filter"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="all">Tous les statuts</option>
                            <?php foreach ($statuts as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
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
            document.getElementById('add_titre').value = '';
            document.getElementById('add_description').value = '';
            document.getElementById('add_categorie').selectedIndex = 0;
            document.getElementById('add_priorite').selectedIndex = 0;
        }

        function openStatusModal(id, titre, statut) {
            document.getElementById('statusModalId').value = id;
            document.getElementById('statusModalTitle').textContent = titre;
            document.getElementById('statusModalStatut').value = statut;
            document.getElementById('statusModalReponse').value = '';
            document.getElementById('statusModal').classList.remove('hidden');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }

        function openDetailsModal(id, titre, description, categorie, priorite, statut, reponse, dateCreation, dateTraitement) {
            document.getElementById('detailsTitre').textContent = titre;
            document.getElementById('detailsDescription').textContent = description;
            document.getElementById('detailsCategorie').textContent = '<?php echo json_encode($categories); ?>'.replace(/&quot;/g, '"')[categorie] || categorie;
            document.getElementById('detailsPriorite').textContent = '<?php echo json_encode($priorites); ?>'.replace(/&quot;/g, '"')[priorite] || priorite;
            document.getElementById('detailsStatut').textContent = '<?php echo json_encode($statuts); ?>'.replace(/&quot;/g, '"')[statut] || statut;
            document.getElementById('detailsDateCreation').textContent = new Date(dateCreation).toLocaleDateString('fr-FR');

            if (reponse) {
                document.getElementById('detailsReponse').textContent = reponse;
                document.getElementById('detailsDateTraitement').textContent = dateTraitement ? new Date(dateTraitement).toLocaleDateString('fr-FR') : '';
                document.getElementById('reponseSection').classList.remove('hidden');
            } else {
                document.getElementById('reponseSection').classList.add('hidden');
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
            document.getElementById('categorieFilter').selectedIndex = 0;
            document.getElementById('statutFilter').selectedIndex = 0;
        }

        function confirmDelete(id, titre) {
            if (confirm('Êtes-vous sûr de vouloir supprimer le feedback "' + titre + '" ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_feedback">
                    <input type="hidden" name="feedback_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>