<?php
/**
 * Journal d'audit - Administration ISTI
 * Affiche l'historique des actions effectuées sur la plateforme
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

// Filtres
$user_filter = isset($_GET['user']) ? sanitize($_GET['user']) : '';
$action_filter = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$table_filter = isset($_GET['table']) ? sanitize($_GET['table']) : '';
$date_debut = isset($_GET['date_debut']) ? sanitize($_GET['date_debut']) : '';
$date_fin = isset($_GET['date_fin']) ? sanitize($_GET['date_fin']) : '';
$search = isset($_GET['search']) ? sanitize($_GET['search']) : '';

// Construction de la requête avec filtres
$query = "SELECT al.*, u.name as user_name, u.email as user_email
          FROM audit_logs al
          LEFT JOIN users u ON al.user_id = u.id
          WHERE 1=1";

$params = [];

if ($user_filter) {
    $query .= " AND al.user_id = :user_id";
    $params[':user_id'] = $user_filter;
}

if ($action_filter) {
    $query .= " AND al.action LIKE :action";
    $params[':action'] = '%' . $action_filter . '%';
}

if ($table_filter) {
    $query .= " AND al.table_cible = :table";
    $params[':table'] = $table_filter;
}

if ($date_debut) {
    $query .= " AND DATE(al.date_action) >= :date_debut";
    $params[':date_debut'] = $date_debut;
}

if ($date_fin) {
    $query .= " AND DATE(al.date_action) <= :date_fin";
    $params[':date_fin'] = $date_fin;
}

if ($search) {
    $query .= " AND (al.details LIKE :search OR u.name LIKE :search OR u.email LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

$query .= " ORDER BY al.date_action DESC LIMIT 500";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des utilisateurs pour le filtre
$users_query = "SELECT id, name, email FROM users WHERE is_active = true ORDER BY name";
$users_stmt = $conn->prepare($users_query);
$users_stmt->execute();
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des tables affectées
$tables_query = "SELECT DISTINCT table_cible FROM audit_logs ORDER BY table_cible";
$tables_stmt = $conn->prepare($tables_query);
$tables_stmt->execute();
$tables = $tables_stmt->fetchAll(PDO::FETCH_COLUMN);

// Statistiques du journal d'audit
$stats_query = "SELECT
    COUNT(*) as total_logs,
    COUNT(DISTINCT user_id) as utilisateurs_actifs,
    COUNT(CASE WHEN DATE(date_action) = CURDATE() THEN 1 END) as logs_aujourdhui,
    COUNT(CASE WHEN DATE(date_action) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as logs_semaine
FROM audit_logs";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Actions les plus fréquentes
$actions_query = "SELECT action, COUNT(*) as count FROM audit_logs
                  GROUP BY action ORDER BY count DESC LIMIT 10";
$actions_stmt = $conn->prepare($actions_query);
$actions_stmt->execute();
$top_actions = $actions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fonction pour obtenir l'icône selon le type d'action
function getActionIcon($action) {
    $action = strtolower($action);

    if (strpos($action, 'création') !== false || strpos($action, 'ajout') !== false) {
        return 'fas fa-plus text-green-500';
    } elseif (strpos($action, 'modification') !== false || strpos($action, 'mise à jour') !== false) {
        return 'fas fa-edit text-blue-500';
    } elseif (strpos($action, 'suppression') !== false || strpos($action, 'supprim') !== false) {
        return 'fas fa-trash text-red-500';
    } elseif (strpos($action, 'connexion') !== false) {
        return 'fas fa-sign-in-alt text-green-500';
    } elseif (strpos($action, 'déconnexion') !== false) {
        return 'fas fa-sign-out-alt text-orange-500';
    } elseif (strpos($action, 'validation') !== false) {
        return 'fas fa-check text-green-500';
    } elseif (strpos($action, 'rejet') !== false) {
        return 'fas fa-times text-red-500';
    } else {
        return 'fas fa-info-circle text-gray-500';
    }
}

// Fonction pour obtenir la couleur selon le type d'action
function getActionColor($action) {
    $action = strtolower($action);

    if (strpos($action, 'création') !== false || strpos($action, 'ajout') !== false) {
        return 'border-green-200 bg-green-50';
    } elseif (strpos($action, 'modification') !== false || strpos($action, 'mise à jour') !== false) {
        return 'border-blue-200 bg-blue-50';
    } elseif (strpos($action, 'suppression') !== false || strpos($action, 'supprim') !== false) {
        return 'border-red-200 bg-red-50';
    } elseif (strpos($action, 'connexion') !== false) {
        return 'border-green-200 bg-green-50';
    } elseif (strpos($action, 'déconnexion') !== false) {
        return 'border-orange-200 bg-orange-50';
    } elseif (strpos($action, 'validation') !== false) {
        return 'border-green-200 bg-green-50';
    } elseif (strpos($action, 'rejet') !== false) {
        return 'border-red-200 bg-red-50';
    } else {
        return 'border-gray-200 bg-gray-50';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal d'Audit - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-history text-2xl mr-3"></i>
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
                <a href="annees_academiques.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Années Académiques
                </a>
                <a href="audit_log.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-history mr-1"></i>Audit
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
        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-list text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total logs</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_logs']); ?></p>
                        <p class="text-sm text-gray-600">entrées d'audit</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-users text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Utilisateurs actifs</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['utilisateurs_actifs']; ?></p>
                        <p class="text-sm text-gray-600">dans les logs</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-calendar-day text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Aujourd'hui</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['logs_aujourdhui']; ?></p>
                        <p class="text-sm text-gray-600">actions</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-calendar-week text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Cette semaine</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats['logs_semaine']; ?></p>
                        <p class="text-sm text-gray-600">actions</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions les plus fréquentes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Actions les plus fréquentes
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($top_actions as $action): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                    <div class="flex items-center">
                        <i class="<?php echo getActionIcon($action['action']); ?> mr-3"></i>
                        <span class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($action['action']); ?></span>
                    </div>
                    <span class="text-lg font-bold text-indigo-600"><?php echo $action['count']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-filter mr-2"></i>Filtres de recherche
            </h2>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div>
                    <label for="user" class="block text-sm font-medium text-gray-700 mb-2">
                        Utilisateur
                    </label>
                    <select id="user" name="user" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les utilisateurs</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $user_filter == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name'] . ' (' . $user['email'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="action" class="block text-sm font-medium text-gray-700 mb-2">
                        Action
                    </label>
                    <input type="text" id="action" name="action" value="<?php echo htmlspecialchars($action_filter); ?>"
                           placeholder="Ex: création, modification..." class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="table" class="block text-sm font-medium text-gray-700 mb-2">
                        Table
                    </label>
                    <select id="table" name="table" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Toutes les tables</option>
                        <?php foreach ($tables as $table): ?>
                            <option value="<?php echo $table; ?>" <?php echo $table_filter === $table ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($table); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-2">
                        Date début
                    </label>
                    <input type="date" id="date_debut" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-2">
                        Date fin
                    </label>
                    <input type="date" id="date_fin" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-1"></i>Filtrer
                    </button>
                    <a href="audit_log.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-times mr-1"></i>Réinitialiser
                    </a>
                </div>
            </form>

            <div class="mt-4">
                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Recherche globale..." class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
            </div>
        </div>

        <!-- Journal d'audit -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list mr-2"></i>Journal d'audit
            </h2>

            <?php if (empty($audit_logs)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-history text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun log d'audit</h3>
                    <p class="text-gray-500">Aucune action n'a encore été enregistrée dans le journal d'audit.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($audit_logs as $log): ?>
                    <div class="border-l-4 <?php echo getActionColor($log['action']); ?> p-4 rounded-r-lg">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start">
                                <i class="<?php echo getActionIcon($log['action']); ?> text-xl mt-1 mr-3"></i>
                                <div class="flex-1">
                                    <div class="flex items-center space-x-4 mb-2">
                                        <h4 class="text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($log['action']); ?>
                                        </h4>
                                        <span class="text-xs text-gray-500">
                                            <?php echo date('d/m/Y H:i:s', strtotime($log['date_action'])); ?>
                                        </span>
                                        <span class="text-xs bg-gray-200 text-gray-800 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($log['table_cible']); ?>
                                        </span>
                                    </div>
                                    <p class="text-sm text-gray-700 mb-2">
                                        <?php echo htmlspecialchars($log['details'] ?? 'Aucun détail disponible'); ?>
                                    </p>
                                    <div class="flex items-center text-xs text-gray-500">
                                        <i class="fas fa-user mr-1"></i>
                                        <span><?php echo htmlspecialchars($log['user_name'] ?? 'Système'); ?></span>
                                        <?php if ($log['user_email']): ?>
                                        <span class="ml-2">(<?php echo htmlspecialchars($log['user_email']); ?>)</span>
                                        <?php endif; ?>
                                        <span class="ml-4">
                                            <i class="fas fa-code-branch mr-1"></i>
                                            ID: <?php echo $log['id']; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Pagination pourrait être ajoutée ici si nécessaire -->
                <div class="mt-6 text-center text-sm text-gray-500">
                    Affichage des 500 dernières entrées d'audit
                </div>
            <?php endif; ?>
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
        // Recherche en temps réel
        let searchTimeout;
        document.getElementById('search').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            searchTimeout = setTimeout(() => {
                if (query.length > 0) {
                    // Ajouter le paramètre de recherche à l'URL
                    const url = new URL(window.location);
                    url.searchParams.set('search', query);
                    window.location.href = url.toString();
                }
            }, 500);
        });
    </script>
</body>
</html>