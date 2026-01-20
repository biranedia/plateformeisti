<?php
/**
 * Gestion des années académiques - Administration ISTI
 * Permet de créer, modifier et gérer les années académiques
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('admin')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'administrateur pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Messages de succès ou d'erreur
$messages = [];

// Traitement des actions (création, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Ajout d'une nouvelle année académique
    if (isset($_POST['action']) && $_POST['action'] === 'add_annee') {
        $annee_academique = trim($_POST['annee_academique']);
        $date_debut = trim($_POST['date_debut']);
        $date_fin = trim($_POST['date_fin']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($annee_academique) || empty($date_debut) || empty($date_fin)) {
            $messages[] = ['type' => 'error', 'text' => 'Tous les champs sont obligatoires.'];
        } elseif (strtotime($date_debut) >= strtotime($date_fin)) {
            $messages[] = ['type' => 'error', 'text' => 'La date de fin doit être postérieure à la date de début.'];
        } else {
            try {
                // Vérifier si l'année académique existe déjà
                $checkQuery = "SELECT COUNT(*) FROM annees_academiques WHERE annee_academique = :annee";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':annee', $annee_academique);
                $checkStmt->execute();

                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Cette année académique existe déjà.'];
                } else {
                    $query = "INSERT INTO annees_academiques (annee_academique, date_debut, date_fin, is_active, created_by)
                             VALUES (:annee, :date_debut, :date_fin, :is_active, :created_by)";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':annee', $annee_academique);
                    $stmt->bindParam(':date_debut', $date_debut);
                    $stmt->bindParam(':date_fin', $date_fin);
                    $stmt->bindParam(':is_active', $is_active);
                    $stmt->bindParam(':created_by', $_SESSION['user_id']);

                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'L\'année académique a été ajoutée avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $_SESSION['user_id'], "Création de l'année académique: $annee_academique", "annees_academiques");
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'ajout de l\'année académique.'];
                    }
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }

    // Modification d'une année académique
    if (isset($_POST['action']) && $_POST['action'] === 'edit_annee') {
        $id = (int)$_POST['id'];
        $annee_academique = trim($_POST['annee_academique']);
        $date_debut = trim($_POST['date_debut']);
        $date_fin = trim($_POST['date_fin']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (empty($annee_academique) || empty($date_debut) || empty($date_fin)) {
            $messages[] = ['type' => 'error', 'text' => 'Tous les champs sont obligatoires.'];
        } elseif (strtotime($date_debut) >= strtotime($date_fin)) {
            $messages[] = ['type' => 'error', 'text' => 'La date de fin doit être postérieure à la date de début.'];
        } else {
            try {
                $query = "UPDATE annees_academiques SET annee_academique = :annee, date_debut = :date_debut,
                         date_fin = :date_fin, is_active = :is_active, updated_at = NOW()
                         WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':annee', $annee_academique);
                $stmt->bindParam(':date_debut', $date_debut);
                $stmt->bindParam(':date_fin', $date_fin);
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':id', $id);

                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'L\'année académique a été modifiée avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Modification de l'année académique: $annee_academique", "annees_academiques");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification de l\'année académique.'];
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }

    // Suppression d'une année académique
    if (isset($_POST['action']) && $_POST['action'] === 'delete_annee') {
        $id = (int)$_POST['id'];

        try {
            // Vérifier si l'année est utilisée dans des inscriptions
            $checkQuery = "SELECT COUNT(*) FROM inscriptions WHERE annee_academique = (SELECT annee_academique FROM annees_academiques WHERE id = :id)";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bindParam(':id', $id);
            $checkStmt->execute();

            if ($checkStmt->fetchColumn() > 0) {
                $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette année académique car elle est utilisée dans des inscriptions.'];
            } else {
                $query = "DELETE FROM annees_academiques WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $id);

                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'L\'année académique a été supprimée avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Suppression d'une année académique (ID: $id)", "annees_academiques");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression de l\'année académique.'];
                }
            }
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
        }
    }

    // Activation/désactivation d'une année académique
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_annee') {
        $id = (int)$_POST['id'];

        try {
            $query = "UPDATE annees_academiques SET is_active = NOT is_active, updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $id);

            if ($stmt->execute()) {
                $messages[] = ['type' => 'success', 'text' => 'Le statut de l\'année académique a été modifié avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Changement de statut d'une année académique (ID: $id)", "annees_academiques");
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification du statut.'];
            }
        } catch (Exception $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
        }
    }
}

// Récupération des années académiques
$query = "SELECT aa.*, u.name as created_by_name,
          (SELECT COUNT(*) FROM inscriptions i WHERE i.annee_academique = aa.annee_academique) as nb_inscriptions
          FROM annees_academiques aa
          LEFT JOIN users u ON aa.created_by = u.id
          ORDER BY aa.date_debut DESC";
$stmt = $conn->prepare($query);
$stmt->execute();
$annees_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats_query = "SELECT
    COUNT(*) as total_annees,
    COUNT(CASE WHEN is_active = 1 THEN 1 END) as annees_actives,
    COUNT(CASE WHEN is_active = 0 THEN 1 END) as annees_inactives,
    (SELECT annee_academique FROM annees_academiques WHERE is_active = 1 ORDER BY date_debut DESC LIMIT 1) as annee_courante
FROM annees_academiques";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Années Académiques - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-calendar-alt text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Administration</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Admin'); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="users.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-users mr-1"></i>Utilisateurs
                </a>
                <a href="departements.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-building mr-1"></i>Départements
                </a>
                <a href="filieres.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Filières
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chalkboard mr-1"></i>Classes
                </a>
                <a href="annees_academiques.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-calendar-alt mr-1"></i>Années Académiques
                </a>
                <a href="stats.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chart-bar mr-1"></i>Statistiques
                </a>
                <a href="settings.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-cog mr-1"></i>Paramètres
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
                        <i class="fas fa-calendar-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total années</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_annees']; ?></p>
                        <p class="text-sm text-gray-600">années académiques</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Années actives</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['annees_actives']; ?></p>
                        <p class="text-sm text-gray-600">en cours</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-gray-100 rounded-full p-3">
                        <i class="fas fa-pause text-gray-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Années inactives</h3>
                        <p class="text-2xl font-bold text-gray-600"><?php echo $stats['annees_inactives']; ?></p>
                        <p class="text-sm text-gray-600">fermées</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-graduation-cap text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Année courante</h3>
                        <p class="text-lg font-bold text-yellow-600"><?php echo htmlspecialchars($stats['annee_courante'] ?? 'Aucune'); ?></p>
                        <p class="text-sm text-gray-600">actuellement active</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Bouton d'ajout -->
        <div class="mb-8">
            <button onclick="openAddModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                <i class="fas fa-plus mr-2"></i>Ajouter une année académique
            </button>
        </div>

        <!-- Liste des années académiques -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-list mr-2"></i>Années académiques
                </h2>
            </div>

            <?php if (empty($annees_academiques)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-calendar-alt text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune année académique</h3>
                    <p class="text-gray-500">Aucune année académique n'a encore été créée.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Année académique
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Période
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Inscriptions
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Créé par
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($annees_academiques as $annee): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($annee['annee_academique']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d/m/Y', strtotime($annee['date_debut'])); ?> -
                                    <?php echo date('d/m/Y', strtotime($annee['date_fin'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $annee['is_active'] ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo $annee['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $annee['nb_inscriptions']; ?> inscription(s)
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($annee['created_by_name'] ?? 'Système'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button onclick="openEditModal(<?php echo $annee['id']; ?>, '<?php echo htmlspecialchars($annee['annee_academique']); ?>', '<?php echo $annee['date_debut']; ?>', '<?php echo $annee['date_fin']; ?>', <?php echo $annee['is_active']; ?>)"
                                                class="text-indigo-600 hover:text-indigo-900 text-sm underline">
                                            <i class="fas fa-edit mr-1"></i>Modifier
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir <?php echo $annee['is_active'] ? 'désactiver' : 'activer'; ?> cette année académique ?')">
                                            <input type="hidden" name="action" value="toggle_annee">
                                            <input type="hidden" name="id" value="<?php echo $annee['id']; ?>">
                                            <button type="submit" class="text-<?php echo $annee['is_active'] ? 'yellow' : 'green'; ?>-600 hover:text-<?php echo $annee['is_active'] ? 'yellow' : 'green'; ?>-900 text-sm underline ml-2">
                                                <i class="fas fa-<?php echo $annee['is_active'] ? 'pause' : 'play'; ?> mr-1"></i><?php echo $annee['is_active'] ? 'Désactiver' : 'Activer'; ?>
                                            </button>
                                        </form>
                                        <?php if ($annee['nb_inscriptions'] == 0): ?>
                                        <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette année académique ?')">
                                            <input type="hidden" name="action" value="delete_annee">
                                            <input type="hidden" name="id" value="<?php echo $annee['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900 text-sm underline ml-2">
                                                <i class="fas fa-trash mr-1"></i>Supprimer
                                            </button>
                                        </form>
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

        <!-- Modal d'ajout -->
        <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-plus mr-2 text-indigo-500"></i>Ajouter une année académique
                            </h3>
                            <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="add_annee">

                            <div class="mb-4">
                                <label for="annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                                    Année académique *
                                </label>
                                <input type="text" id="annee_academique" name="annee_academique" required
                                       placeholder="Ex: 2023/2024" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-4">
                                <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date de début *
                                </label>
                                <input type="date" id="date_debut" name="date_debut" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-4">
                                <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date de fin *
                                </label>
                                <input type="date" id="date_fin" name="date_fin" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="is_active" value="1" checked
                                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Année active</span>
                                </label>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeAddModal()"
                                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                    Annuler
                                </button>
                                <button type="submit"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-plus mr-2"></i>Ajouter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de modification -->
        <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-edit mr-2 text-indigo-500"></i>Modifier l'année académique
                            </h3>
                            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="action" value="edit_annee">
                            <input type="hidden" id="edit_id" name="id">

                            <div class="mb-4">
                                <label for="edit_annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                                    Année académique *
                                </label>
                                <input type="text" id="edit_annee_academique" name="annee_academique" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-4">
                                <label for="edit_date_debut" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date de début *
                                </label>
                                <input type="date" id="edit_date_debut" name="date_debut" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-4">
                                <label for="edit_date_fin" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date de fin *
                                </label>
                                <input type="date" id="edit_date_fin" name="date_fin" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" id="edit_is_active" name="is_active" value="1"
                                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                    <span class="ml-2 text-sm text-gray-700">Année active</span>
                                </label>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeEditModal()"
                                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                    Annuler
                                </button>
                                <button type="submit"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-save mr-2"></i>Modifier
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

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
            document.getElementById('annee_academique').value = '';
            document.getElementById('date_debut').value = '';
            document.getElementById('date_fin').value = '';
        }

        function openEditModal(id, annee, dateDebut, dateFin, isActive) {
            document.getElementById('edit_id').value = id;
            document.getElementById('edit_annee_academique').value = annee;
            document.getElementById('edit_date_debut').value = dateDebut;
            document.getElementById('edit_date_fin').value = dateFin;
            document.getElementById('edit_is_active').checked = isActive == 1;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        // Fermer les modals en cliquant en dehors
        document.getElementById('addModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAddModal();
            }
        });

        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>
</html>