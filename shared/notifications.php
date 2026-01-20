<?php
/**
 * Système de notifications - ISTI Platform
 * Gestion des notifications pour tous les utilisateurs
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

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Variables
$notifications = [];
$errors = [];
$success = '';
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Filtres
$filter_type = sanitize($_GET['type'] ?? 'all');
$filter_status = sanitize($_GET['status'] ?? 'all'); // all, read, unread

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'mark_as_read') {
        $notification_ids = $_POST['notification_ids'] ?? [];
        if (!empty($notification_ids)) {
            try {
                $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
                $query = "UPDATE notifications SET is_read = 1, read_at = NOW()
                         WHERE id IN ($placeholders) AND user_id = ?";
                $stmt = $conn->prepare($query);
                $params = array_merge($notification_ids, [$user_id]);
                $stmt->execute($params);

                $success = count($notification_ids) . ' notification(s) marquée(s) comme lue(s).';

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Notifications marquées comme lues: " . implode(', ', $notification_ids), "notifications");

            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la mise à jour: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'mark_all_as_read') {
        try {
            $query = "UPDATE notifications SET is_read = 1, read_at = NOW()
                     WHERE user_id = :user AND is_read = 0";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':user', $user_id);
            $stmt->execute();

            $affected_rows = $stmt->rowCount();
            $success = "Toutes les notifications ont été marquées comme lues ($affected_rows notification(s)).";

            // Ajout dans le journal d'audit
            addAuditLog($conn, $user_id, "Toutes les notifications marquées comme lues", "notifications");

        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la mise à jour: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_notifications') {
        $notification_ids = $_POST['notification_ids'] ?? [];
        if (!empty($notification_ids)) {
            try {
                $placeholders = str_repeat('?,', count($notification_ids) - 1) . '?';
                $query = "DELETE FROM notifications WHERE id IN ($placeholders) AND user_id = ?";
                $stmt = $conn->prepare($query);
                $params = array_merge($notification_ids, [$user_id]);
                $stmt->execute($params);

                $success = count($notification_ids) . ' notification(s) supprimée(s).';

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Notifications supprimées: " . implode(', ', $notification_ids), "notifications");

            } catch (Exception $e) {
                $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
            }
        }
    }

    if ($action === 'delete_old_notifications') {
        $days = (int)($_POST['days'] ?? 30);
        try {
            $query = "DELETE FROM notifications
                     WHERE user_id = :user AND date_envoi < DATE_SUB(NOW(), INTERVAL :days DAY)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':user', $user_id);
            $stmt->bindParam(':days', $days, PDO::PARAM_INT);
            $stmt->execute();

            $affected_rows = $stmt->rowCount();
            $success = "$affected_rows ancienne(s) notification(s) supprimée(s).";

            // Ajout dans le journal d'audit
            addAuditLog($conn, $user_id, "Anciennes notifications supprimées ($days jours)", "notifications");

        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
        }
    }
}

// Construction de la requête avec filtres
$query = "SELECT * FROM notifications WHERE user_id = :user";
$params = [':user' => $user_id];

if ($filter_type !== 'all') {
    $query .= " AND type = :type";
    $params[':type'] = $filter_type;
}

if ($filter_status === 'read') {
    $query .= " AND is_read = 1";
} elseif ($filter_status === 'unread') {
    $query .= " AND is_read = 0";
}

$query .= " ORDER BY date_envoi DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $limit;
$params[':offset'] = $offset;

// Récupération des notifications
try {
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des notifications.';
}

// Comptage total pour la pagination
$count_query = "SELECT COUNT(*) as total FROM notifications WHERE user_id = :user";
$count_params = [':user' => $user_id];

if ($filter_type !== 'all') {
    $count_query .= " AND type = :type";
    $count_params[':type'] = $filter_type;
}

if ($filter_status === 'read') {
    $count_query .= " AND is_read = 1";
} elseif ($filter_status === 'unread') {
    $count_query .= " AND is_read = 0";
}

try {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($count_params);
    $total_notifications = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $total_notifications = 0;
}

$total_pages = ceil($total_notifications / $limit);

// Statistiques des notifications
$stats = [
    'total' => 0,
    'unread' => 0,
    'read' => 0,
    'by_type' => []
];

try {
    // Statistiques générales
    $stats_query = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
                    SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END) as read
                    FROM notifications WHERE user_id = :user";
    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->bindParam(':user', $user_id);
    $stats_stmt->execute();
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $stats['total'] = $stats_result['total'] ?? 0;
    $stats['unread'] = $stats_result['unread'] ?? 0;
    $stats['read'] = $stats_result['read'] ?? 0;

    // Statistiques par type
    $type_query = "SELECT type, COUNT(*) as count FROM notifications
                   WHERE user_id = :user GROUP BY type ORDER BY count DESC";
    $type_stmt = $conn->prepare($type_query);
    $type_stmt->bindParam(':user', $user_id);
    $type_stmt->execute();
    $stats['by_type'] = $type_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Valeurs par défaut en cas d'erreur
    $stats = ['total' => 0, 'unread' => 0, 'read' => 0, 'by_type' => []];
}

// Types de notifications disponibles
$notification_types = [
    'all' => 'Tous les types',
    'system' => 'Système',
    'academic' => 'Académique',
    'administrative' => 'Administrative',
    'message' => 'Message',
    'deadline' => 'Échéance',
    'alert' => 'Alerte'
];

// Fonction pour obtenir l'icône selon le type
function getNotificationIcon($type) {
    $icons = [
        'system' => 'fas fa-cog',
        'academic' => 'fas fa-graduation-cap',
        'administrative' => 'fas fa-building',
        'message' => 'fas fa-envelope',
        'deadline' => 'fas fa-clock',
        'alert' => 'fas fa-exclamation-triangle'
    ];
    return $icons[$type] ?? 'fas fa-bell';
}

// Fonction pour obtenir la couleur selon le type
function getNotificationColor($type) {
    $colors = [
        'system' => 'blue',
        'academic' => 'green',
        'administrative' => 'purple',
        'message' => 'orange',
        'deadline' => 'red',
        'alert' => 'yellow'
    ];
    return $colors[$type] ?? 'gray';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-bell text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Notifications</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
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
                        <i class="fas fa-bell text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600">notifications</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-envelope text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Non lues</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['unread']; ?></p>
                        <p class="text-sm text-gray-600">en attente</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Lues</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['read']; ?></p>
                        <p class="text-sm text-gray-600">consultées</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-chart-pie text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Types</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo count($stats['by_type']); ?></p>
                        <p class="text-sm text-gray-600">différents</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-bell mr-2"></i>Gestion des notifications
                </h2>

                <div class="flex space-x-2">
                    <button onclick="markAllAsRead()"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-check-double mr-2"></i>Tout marquer comme lu
                    </button>
                    <button onclick="openCleanupModal()"
                            class="bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-trash mr-2"></i>Nettoyer
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Type</label>
                    <select id="type" name="type" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <?php foreach ($notification_types as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filter_type === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <select id="status" name="status" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>Tous les statuts</option>
                        <option value="unread" <?php echo $filter_status === 'unread' ? 'selected' : ''; ?>>Non lues</option>
                        <option value="read" <?php echo $filter_status === 'read' ? 'selected' : ''; ?>>Lues</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <a href="notifications.php"
                       class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200 w-full text-center">
                        <i class="fas fa-times mr-2"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des notifications -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <div class="px-6 py-4 border-b border-gray-200">
                <div class="flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-list mr-2"></i>Notifications
                    </h3>
                    <form method="POST" class="inline" onsubmit="return confirmBulkAction()">
                        <input type="hidden" name="action" value="mark_as_read">
                        <button type="submit" id="bulkReadBtn"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-1 px-3 rounded-md transition duration-200 text-sm disabled:opacity-50"
                                disabled>
                            <i class="fas fa-check mr-1"></i>Marquer comme lu
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-bell-slash text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune notification</h3>
                    <p class="text-gray-500">Vous n'avez aucune notification correspondant à vos critères.</p>
                </div>
            <?php else: ?>
                <form method="POST" id="bulkForm">
                    <input type="hidden" name="action" value="mark_as_read">
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($notifications as $notification): ?>
                            <div class="p-6 hover:bg-gray-50 <?php echo !$notification['is_read'] ? 'bg-blue-50' : ''; ?>">
                                <div class="flex items-start space-x-4">
                                    <div class="flex-shrink-0">
                                        <input type="checkbox" name="notification_ids[]" value="<?php echo $notification['id']; ?>"
                                               class="notification-checkbox w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                                    </div>

                                    <div class="flex-shrink-0">
                                        <div class="w-10 h-10 rounded-full bg-<?php echo getNotificationColor($notification['type']); ?>-100 flex items-center justify-center">
                                            <i class="<?php echo getNotificationIcon($notification['type']); ?> text-<?php echo getNotificationColor($notification['type']); ?>-600"></i>
                                        </div>
                                    </div>

                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <h4 class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($notification['title']); ?>
                                            </h4>
                                            <?php if (!$notification['is_read']): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                    Nouveau
                                                </span>
                                            <?php endif; ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                                                <?php
                                                $color = getNotificationColor($notification['type']);
                                                echo "bg-{$color}-100 text-{$color}-800";
                                                ?>">
                                                <?php echo htmlspecialchars($notification_types[$notification['type']] ?? $notification['type']); ?>
                                            </span>
                                        </div>

                                        <p class="text-sm text-gray-600 mb-2">
                                            <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                        </p>

                                        <?php if (!empty($notification['action_url'])): ?>
                                            <div class="mb-2">
                                                <a href="<?php echo htmlspecialchars($notification['action_url']); ?>"
                                                   class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                                                    <?php echo htmlspecialchars($notification['action_text'] ?? 'Voir'); ?>
                                                </a>
                                            </div>
                                        <?php endif; ?>

                                        <div class="flex items-center space-x-4 text-xs text-gray-500">
                                            <span>
                                                <i class="fas fa-calendar mr-1"></i>
                                                <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($notification['date_envoi']))); ?>
                                            </span>
                                            <?php if ($notification['is_read'] && $notification['read_at']): ?>
                                                <span>
                                                    <i class="fas fa-eye mr-1"></i>
                                                    Lu le <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($notification['read_at']))); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="flex-shrink-0 flex flex-col space-y-2">
                                        <?php if (!$notification['is_read']): ?>
                                            <button onclick="markAsRead(<?php echo $notification['id']; ?>)"
                                                    class="text-blue-600 hover:text-blue-900 text-sm">
                                                <i class="fas fa-check"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button onclick="deleteNotification(<?php echo $notification['id']; ?>)"
                                                class="text-red-600 hover:text-red-900 text-sm">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </form>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                Affichage de <?php echo min(($page - 1) * $limit + 1, $total_notifications); ?> à <?php echo min($page * $limit, $total_notifications); ?> sur <?php echo $total_notifications; ?> notifications
                            </div>
                            <div class="flex space-x-1">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"
                                       class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                        <i class="fas fa-chevron-left mr-1"></i>Précédent
                                    </a>
                                <?php endif; ?>

                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);

                                if ($start_page > 1): ?>
                                    <a href="?page=1&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"
                                       class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>
                                    <?php if ($start_page > 2): ?>
                                        <span class="px-2 py-1 text-gray-500">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"
                                       class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <span class="px-2 py-1 text-gray-500">...</span>
                                    <?php endif; ?>
                                    <a href="?page=<?php echo $total_pages; ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"
                                       class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?php echo $total_pages; ?></a>
                                <?php endif; ?>

                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&type=<?php echo urlencode($filter_type); ?>&status=<?php echo urlencode($filter_status); ?>"
                                       class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                        Suivant<i class="fas fa-chevron-right ml-1"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Nettoyage -->
    <div id="cleanupModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-trash mr-2"></i>Nettoyer les notifications
                    </h3>
                    <button onclick="closeCleanupModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-yellow-50 border border-yellow-200 rounded">
                    <p class="text-sm text-yellow-800">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        Cette action supprimera définitivement les anciennes notifications.
                    </p>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="delete_old_notifications">

                    <div>
                        <label for="cleanupDays" class="block text-sm font-medium text-gray-700 mb-2">
                            Supprimer les notifications de plus de :
                        </label>
                        <select id="cleanupDays" name="days" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="7">7 jours</option>
                            <option value="30" selected>30 jours</option>
                            <option value="90">90 jours</option>
                            <option value="180">180 jours</option>
                            <option value="365">1 an</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeCleanupModal()"
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
        function markAsRead(notificationId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="mark_as_read">
                <input type="hidden" name="notification_ids[]" value="${notificationId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }

        function deleteNotification(notificationId) {
            if (confirm('Êtes-vous sûr de vouloir supprimer cette notification ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_notifications">
                    <input type="hidden" name="notification_ids[]" value="${notificationId}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function markAllAsRead() {
            if (confirm('Êtes-vous sûr de vouloir marquer toutes les notifications comme lues ?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = '<input type="hidden" name="action" value="mark_all_as_read">';
                document.body.appendChild(form);
                form.submit();
            }
        }

        function openCleanupModal() {
            document.getElementById('cleanupModal').classList.remove('hidden');
        }

        function closeCleanupModal() {
            document.getElementById('cleanupModal').classList.add('hidden');
            document.getElementById('cleanupDays').selectedIndex = 1; // Reset to 30 days
        }

        function confirmBulkAction() {
            const checkedBoxes = document.querySelectorAll('.notification-checkbox:checked');
            if (checkedBoxes.length === 0) {
                alert('Veuillez sélectionner au moins une notification.');
                return false;
            }
            return confirm(`Êtes-vous sûr de vouloir marquer ${checkedBoxes.length} notification(s) comme lue(s) ?`);
        }

        // Gestion des cases à cocher pour les actions groupées
        document.addEventListener('DOMContentLoaded', function() {
            const checkboxes = document.querySelectorAll('.notification-checkbox');
            const bulkReadBtn = document.getElementById('bulkReadBtn');
            const bulkForm = document.getElementById('bulkForm');

            function updateBulkButton() {
                const checkedBoxes = document.querySelectorAll('.notification-checkbox:checked');
                bulkReadBtn.disabled = checkedBoxes.length === 0;
                bulkReadBtn.innerHTML = `<i class="fas fa-check mr-1"></i>Marquer comme lu (${checkedBoxes.length})`;
            }

            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', updateBulkButton);
            });

            // Coche/décoche toutes les cases
            const selectAllCheckbox = document.createElement('input');
            selectAllCheckbox.type = 'checkbox';
            selectAllCheckbox.className = 'mr-2';
            selectAllCheckbox.addEventListener('change', function() {
                checkboxes.forEach(cb => cb.checked = this.checked);
                updateBulkButton();
            });

            // Ajouter la case "Tout sélectionner" dans l'en-tête
            const header = document.querySelector('.divide-y').previousElementSibling;
            if (header) {
                const selectAllDiv = document.createElement('div');
                selectAllDiv.className = 'px-6 py-2 border-b border-gray-200 bg-gray-50';
                selectAllDiv.innerHTML = '<label class="flex items-center text-sm text-gray-700"><input type="checkbox" class="mr-2 select-all"> Tout sélectionner</label>';
                header.parentNode.insertBefore(selectAllDiv, header.nextSibling);

                selectAllDiv.querySelector('.select-all').addEventListener('change', function() {
                    checkboxes.forEach(cb => cb.checked = this.checked);
                    updateBulkButton();
                });
            }
        });
    </script>
</body>
</html>