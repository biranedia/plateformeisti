<?php
/**
 * Journal d'audit - ISTI Platform
 * Consultation et gestion du journal d'audit système
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification
if (!isLoggedIn()) {
    redirectWithMessage('login.php', 'Vous devez être connecté pour accéder à cette page.', 'error');
}

// Vérification des permissions (seulement admin_general et agent_administratif peuvent voir l'audit)
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['admin_general', 'agent_administratif'])) {
    redirectWithMessage('dashboard.php', 'Vous n\'avez pas les permissions pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Variables
$audit_logs = [];
$errors = [];
$success = '';
$page = (int)($_GET['page'] ?? 1);
$limit = 25;
$offset = ($page - 1) * $limit;

// Filtres
$filter_user = sanitize($_GET['user'] ?? 'all');
$filter_action = sanitize($_GET['action'] ?? 'all');
$filter_date_from = sanitize($_GET['date_from'] ?? '');
$filter_date_to = sanitize($_GET['date_to'] ?? '');
$filter_search = sanitize($_GET['search'] ?? '');

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'clear_old_logs' && hasRole('admin_general')) {
        $days = (int)($_POST['days'] ?? 30);

        try {
            $query = "DELETE FROM audit_logs WHERE date_action < DATE_SUB(NOW(), INTERVAL :days DAY)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':days', $days);
            $stmt->execute();

            $success = "Les logs d'audit de plus de $days jours ont été supprimés.";

            // Ajout dans le journal d'audit
            addAuditLog($conn, $_SESSION['user_id'], "Suppression des logs d'audit de plus de $days jours", "audit_logs");

        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
        }
    }

    if ($action === 'export_logs' && hasRole('admin_general')) {
        try {
            // Construction de la requête d'export
            $export_query = "SELECT al.*, u.nom, u.prenom, u.email
                           FROM audit_logs al
                           LEFT JOIN users u ON al.user_id = u.id
                           WHERE 1=1";

            $params = [];

            if ($filter_user !== 'all') {
                $export_query .= " AND al.user_id = :user";
                $params[':user'] = $filter_user;
            }

            if ($filter_action !== 'all') {
                $export_query .= " AND al.action = :action";
                $params[':action'] = $filter_action;
            }

            if (!empty($filter_date_from)) {
                $export_query .= " AND DATE(al.date_action) >= :date_from";
                $params[':date_from'] = $filter_date_from;
            }

            if (!empty($filter_date_to)) {
                $export_query .= " AND DATE(al.date_action) <= :date_to";
                $params[':date_to'] = $filter_date_to;
            }

            if (!empty($filter_search)) {
                $export_query .= " AND (al.details LIKE :search OR al.action LIKE :search OR u.nom LIKE :search OR u.prenom LIKE :search)";
                $params[':search'] = '%' . $filter_search . '%';
            }

            $export_query .= " ORDER BY al.date_action DESC";

            $export_stmt = $conn->prepare($export_query);
            $export_stmt->execute($params);
            $export_logs = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Génération du fichier CSV
            $filename = 'audit_log_' . date('Y-m-d_H-i-s') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');

            // En-têtes CSV
            fputcsv($output, ['ID', 'Utilisateur', 'Email', 'Action', 'Détails', 'Adresse IP', 'Date']);

            // Données
            foreach ($export_logs as $log) {
                fputcsv($output, [
                    $log['id'],
                    $log['nom'] . ' ' . $log['prenom'],
                    $log['email'],
                    $log['action'],
                    $log['details'],
                    $log['ip_address'],
                    $log['date_action']
                ]);
            }

            fclose($output);
            exit;

        } catch (Exception $e) {
            $errors[] = 'Erreur lors de l\'export: ' . $e->getMessage();
        }
    }
}

// Construction de la requête avec filtres
$query = "SELECT al.*, u.nom, u.prenom, u.email
          FROM audit_logs al
          LEFT JOIN users u ON al.user_id = u.id
          WHERE 1=1";

$params = [];

if ($filter_user !== 'all') {
    $query .= " AND al.user_id = :user";
    $params[':user'] = $filter_user;
}

if ($filter_action !== 'all') {
    $query .= " AND al.action = :action";
    $params[':action'] = $filter_action;
}

if (!empty($filter_date_from)) {
        $query .= " AND DATE(al.date_action) >= :date_from";
        $params[':date_from'] = $filter_date_from;
    }

    if (!empty($filter_date_to)) {
        $query .= " AND DATE(al.date_action) <= :date_to";
}

if (!empty($filter_search)) {
    $query .= " AND (al.details LIKE :search OR al.action LIKE :search OR u.nom LIKE :search OR u.prenom LIKE :search)";
    $params[':search'] = '%' . $filter_search . '%';
}

$query .= " ORDER BY al.date_action DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $limit;
$params[':offset'] = $offset;

// Récupération des logs d'audit
try {
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $audit_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des logs d\'audit.';
}

// Comptage total pour la pagination
$count_query = str_replace("SELECT al.*, u.nom, u.prenom, u.email FROM audit_logs al", "SELECT COUNT(*) as total FROM audit_logs al", $query);
$count_query = preg_replace('/ORDER BY.*$/', '', $count_query);
$count_query = preg_replace('/LIMIT.*$/', '', $count_query);

try {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($params);
    $total_logs = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $total_logs = 0;
}

$total_pages = ceil($total_logs / $limit);

// Récupération des utilisateurs pour le filtre
$users = [];
try {
    $users_query = "SELECT id, nom, prenom FROM users ORDER BY nom, prenom";
    $users_stmt = $conn->prepare($users_query);
    $users_stmt->execute();
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Valeurs par défaut en cas d'erreur
}

// Récupération des actions distinctes pour le filtre
$actions = [];
try {
    $actions_query = "SELECT DISTINCT action FROM audit_logs ORDER BY action";
    $actions_stmt = $conn->prepare($actions_query);
    $actions_stmt->execute();
    $actions = $actions_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Valeurs par défaut en cas d'erreur
}

// Statistiques des logs d'audit
$stats = [
    'total_logs' => 0,
    'today_logs' => 0,
    'week_logs' => 0,
    'month_logs' => 0,
    'top_actions' => []
];

try {
    // Statistiques générales
    $stats_query = "SELECT
                    COUNT(*) as total_logs,
                    COUNT(CASE WHEN DATE(date_action) = CURDATE() THEN 1 END) as today_logs,
                    COUNT(CASE WHEN date_action >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 END) as week_logs,
                    COUNT(CASE WHEN date_action >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 END) as month_logs
                    FROM audit_logs";
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $stats['total_logs'] = $stats_result['total_logs'] ?? 0;
    $stats['today_logs'] = $stats_result['today_logs'] ?? 0;
    $stats['week_logs'] = $stats_result['week_logs'] ?? 0;
    $stats['month_logs'] = $stats_result['month_logs'] ?? 0;

    // Top actions
    $top_actions_query = "SELECT action, COUNT(*) as count FROM audit_logs
                         GROUP BY action ORDER BY count DESC LIMIT 5";
    $top_actions_stmt = $conn->prepare($top_actions_query);
    $top_actions_stmt->execute();
    $stats['top_actions'] = $top_actions_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Valeurs par défaut en cas d'erreur
    $stats = ['total_logs' => 0, 'today_logs' => 0, 'week_logs' => 0, 'month_logs' => 0, 'top_actions' => []];
}

// Fonction pour obtenir l'icône selon l'action
function getActionIcon($action) {
    $icons = [
        'login' => 'fas fa-sign-in-alt text-green-600',
        'logout' => 'fas fa-sign-out-alt text-red-600',
        'create' => 'fas fa-plus text-blue-600',
        'update' => 'fas fa-edit text-orange-600',
        'delete' => 'fas fa-trash text-red-600',
        'view' => 'fas fa-eye text-gray-600',
        'export' => 'fas fa-download text-purple-600',
        'import' => 'fas fa-upload text-purple-600',
        'permission' => 'fas fa-shield-alt text-yellow-600',
        'system' => 'fas fa-cog text-gray-600'
    ];

    // Recherche par mot-clé dans l'action
    foreach ($icons as $keyword => $icon) {
        if (stripos($action, $keyword) !== false) {
            return $icon;
        }
    }

    return 'fas fa-info-circle text-gray-600';
}

// Fonction pour obtenir la couleur selon l'action
function getActionColor($action) {
    $colors = [
        'login' => 'bg-green-100 text-green-800',
        'logout' => 'bg-red-100 text-red-800',
        'create' => 'bg-blue-100 text-blue-800',
        'update' => 'bg-orange-100 text-orange-800',
        'delete' => 'bg-red-100 text-red-800',
        'view' => 'bg-gray-100 text-gray-800',
        'export' => 'bg-purple-100 text-purple-800',
        'import' => 'bg-purple-100 text-purple-800',
        'permission' => 'bg-yellow-100 text-yellow-800',
        'system' => 'bg-gray-100 text-gray-800'
    ];

    // Recherche par mot-clé dans l'action
    foreach ($colors as $keyword => $color) {
        if (stripos($action, $keyword) !== false) {
            return $color;
        }
    }

    return 'bg-gray-100 text-gray-800';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Journal d'audit - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-history text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Journal d'audit</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
                    <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-arrow-left mr-1"></i>Retour
                    </a>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages d'erreur et de succès -->
        <?php if (!empty($errors)): ?>
            <div class="mb-8 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-8 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

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
                        <p class="text-sm text-gray-600">dans le système</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-calendar-day text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Aujourd'hui</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['today_logs']); ?></p>
                        <p class="text-sm text-gray-600">logs enregistrés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-calendar-week text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Cette semaine</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo number_format($stats['week_logs']); ?></p>
                        <p class="text-sm text-gray-600">logs enregistrés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-calendar-alt text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Ce mois</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['month_logs']); ?></p>
                        <p class="text-sm text-gray-600">logs enregistrés</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-filter mr-2"></i>Filtrage et actions
                </h2>

                <div class="flex space-x-2">
                    <?php if (hasRole('admin_general')): ?>
                        <button onclick="openClearModal()"
                                class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-trash-alt mr-2"></i>Nettoyer
                        </button>
                        <button onclick="exportLogs()"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-download mr-2"></i>Exporter
                        </button>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-6 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Utilisateur, action, détails..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label for="user" class="block text-sm font-medium text-gray-700 mb-2">Utilisateur</label>
                    <select id="user" name="user"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $filter_user === 'all' ? 'selected' : ''; ?>>Tous les utilisateurs</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php echo $filter_user === (string)$user['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['nom'] . ' ' . $user['prenom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="action" class="block text-sm font-medium text-gray-700 mb-2">Action</label>
                    <select id="action" name="action"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $filter_action === 'all' ? 'selected' : ''; ?>>Toutes les actions</option>
                        <?php foreach ($actions as $action_item): ?>
                            <option value="<?php echo $action_item['action']; ?>" <?php echo $filter_action === $action_item['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action_item['action']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">Date début</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">Date fin</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-end">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 w-full">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                </div>
            </form>
        </div>

        <!-- Top actions -->
        <?php if (!empty($stats['top_actions'])): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-bar mr-2"></i>Actions les plus fréquentes
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                    <?php foreach ($stats['top_actions'] as $action): ?>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $action['count']; ?></div>
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($action['action']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Liste des logs d'audit -->
        <?php if (empty($audit_logs)): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-history text-4xl text-gray-300 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun log d'audit trouvé</h3>
                <p class="text-gray-500">Il n'y a aucun log d'audit correspondant à vos critères de recherche.</p>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Utilisateur
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Action
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Détails
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    IP
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date & Heure
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($audit_logs as $log): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                    <i class="fas fa-user text-gray-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($log['nom'] . ' ' . $log['prenom']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($log['email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <i class="<?php echo getActionIcon($log['action']); ?> mr-2"></i>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo getActionColor($log['action']); ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 max-w-xs truncate" title="<?php echo htmlspecialchars($log['details']); ?>">
                                            <?php echo htmlspecialchars($log['details']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($log['ip_address']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <div>
                                            <div><?php echo htmlspecialchars(date('d/m/Y', strtotime($log['date_action']))); ?></div>
                                            <div><?php echo htmlspecialchars(date('H:i:s', strtotime($log['date_action']))); ?></div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-8 bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Affichage de <?php echo min(($page - 1) * $limit + 1, $total_logs); ?> à <?php echo min($page * $limit, $total_logs); ?> sur <?php echo $total_logs; ?> logs
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&user=<?php echo urlencode($filter_user); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    <i class="fas fa-chevron-left mr-1"></i>Précédent
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                                <a href="?page=1&user=<?php echo urlencode($filter_user); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="px-2 py-1 text-gray-500">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&user=<?php echo urlencode($filter_user); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="px-2 py-1 text-gray-500">...</span>
                                <?php endif; ?>
                                <a href="?page=<?php echo $total_pages; ?>&user=<?php echo urlencode($filter_user); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?php echo $total_pages; ?></a>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&user=<?php echo urlencode($filter_user); ?>&action=<?php echo urlencode($filter_action); ?>&date_from=<?php echo urlencode($filter_date_from); ?>&date_to=<?php echo urlencode($filter_date_to); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Suivant<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Modal Nettoyage -->
    <div id="clearModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-trash-alt mr-2"></i>Nettoyer les anciens logs
                    </h3>
                    <button onclick="closeClearModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="clear_old_logs">

                    <div>
                        <label for="clear_days" class="block text-sm font-medium text-gray-700 mb-2">
                            Supprimer les logs de plus de :
                        </label>
                        <select id="clear_days" name="days" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="7">7 jours</option>
                            <option value="30" selected>30 jours</option>
                            <option value="90">90 jours</option>
                            <option value="180">180 jours</option>
                            <option value="365">1 an</option>
                        </select>
                    </div>

                    <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">
                                    Attention
                                </h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>Cette action est irréversible. Les logs supprimés ne pourront pas être récupérés.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeClearModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Supprimer
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
        function openClearModal() {
            document.getElementById('clearModal').classList.remove('hidden');
        }

        function closeClearModal() {
            document.getElementById('clearModal').classList.add('hidden');
        }

        function exportLogs() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="export_logs">
                <input type="hidden" name="user" value="<?php echo htmlspecialchars($filter_user); ?>">
                <input type="hidden" name="action_filter" value="<?php echo htmlspecialchars($filter_action); ?>">
                <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($filter_search); ?>">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>