<?php
/**
 * Statistiques de la plateforme - Administration ISTI
 * Dashboard de statistiques et analyses
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

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'generate_report') {
            $report_type = sanitize($_POST['report_type']);
            $start_date = sanitize($_POST['start_date']);
            $end_date = sanitize($_POST['end_date']);

            try {
                // Génération de rapport (simulation)
                $messages[] = ['type' => 'success', 'text' => 'Rapport "' . $report_type . '" généré avec succès pour la période du ' . $start_date . ' au ' . $end_date . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Génération de rapport: $report_type", "stats");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la génération du rapport: ' . $e->getMessage()];
            }
        }

        if ($action === 'export_stats') {
            $export_format = sanitize($_POST['export_format']);

            try {
                // Export des statistiques (simulation)
                $messages[] = ['type' => 'success', 'text' => 'Statistiques exportées au format ' . strtoupper($export_format) . '.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Export des statistiques au format $export_format", "stats");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'export: ' . $e->getMessage()];
            }
        }
    }
}

// Fonction pour exécuter une requête et retourner le résultat
function getStat($conn, $query, $params = []) {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Statistiques générales
$stats = [];

// Utilisateurs
$stats['total_users'] = getStat($conn, "SELECT COUNT(*) as count FROM users")['count'];
$stats['active_users'] = getStat($conn, "SELECT COUNT(*) as count FROM users WHERE is_active = 1")['count'];
$stats['inactive_users'] = getStat($conn, "SELECT COUNT(*) as count FROM users WHERE is_active = 0")['count'];

// Répartition par rôle
$stats['role_distribution'] = $conn->query("
    SELECT ur.role, COUNT(u.id) as count
    FROM users u
    JOIN user_roles ur ON u.id = ur.user_id
    GROUP BY ur.role
    ORDER BY count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Départements
$stats['total_departements'] = getStat($conn, "SELECT COUNT(*) as count FROM departements")['count'];

// Filières
$stats['total_filieres'] = getStat($conn, "SELECT COUNT(*) as count FROM filieres")['count'];

// Classes
$stats['total_classes'] = getStat($conn, "SELECT COUNT(*) as count FROM classes")['count'];

// Années académiques
$stats['total_annees_academiques'] = getStat($conn, "SELECT COUNT(*) as count FROM annees_academiques")['count'];
$stats['annee_active'] = getStat($conn, "SELECT COUNT(*) as count FROM annees_academiques WHERE is_active = 1")['count'];

// Inscriptions
$stats['total_inscriptions'] = getStat($conn, "SELECT COUNT(*) as count FROM inscriptions")['count'];
$stats['inscriptions_actives'] = getStat($conn, "SELECT COUNT(*) as count FROM inscriptions WHERE statut IN ('inscrit', 'reinscrit')")['count'];

// Notes
$stats['total_notes'] = getStat($conn, "SELECT COUNT(*) as count FROM notes")['count'];
$stats['moyenne_generale'] = getStat($conn, "SELECT ROUND(AVG(note), 2) as avg FROM notes WHERE note IS NOT NULL")['avg'];

// Documents
$stats['total_documents'] = getStat($conn, "SELECT COUNT(*) as count FROM documents")['count'];
$stats['documents_valides'] = getStat($conn, "SELECT COUNT(*) as count FROM documents WHERE statut = 'valide'")['count'];

// Audit logs
$stats['total_audit_logs'] = getStat($conn, "SELECT COUNT(*) as count FROM audit_logs")['count'];
$stats['audit_logs_today'] = getStat($conn, "SELECT COUNT(*) as count FROM audit_logs WHERE DATE(date_action) = CURDATE()")['count'];

// Statistiques temporelles (derniers 30 jours)
// Note: Certaines tables n'ont pas de colonnes de timestamp, donc ces stats sont commentées
// $stats['new_users_30_days'] = getStat($conn, "SELECT COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['count'];
// $stats['new_inscriptions_30_days'] = getStat($conn, "SELECT COUNT(*) as count FROM inscriptions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['count'];
$stats['new_notes_30_days'] = getStat($conn, "SELECT COUNT(*) as count FROM notes WHERE date_saisie >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['count'];
$stats['audit_logs_30_days'] = getStat($conn, "SELECT COUNT(*) as count FROM audit_logs WHERE date_action >= DATE_SUB(NOW(), INTERVAL 30 DAY)")['count'];

// Top activités d'audit
$stats['top_audit_actions'] = $conn->query("
    SELECT action, COUNT(*) as count
    FROM audit_logs
    WHERE date_action >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY action
    ORDER BY count DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Répartition des notes par tranche
$stats['notes_distribution'] = $conn->query("
    SELECT
        CASE
            WHEN note >= 16 THEN '16-20'
            WHEN note >= 14 THEN '14-15.99'
            WHEN note >= 12 THEN '12-13.99'
            WHEN note >= 10 THEN '10-11.99'
            WHEN note >= 8 THEN '08-09.99'
            WHEN note >= 6 THEN '06-07.99'
            ELSE '00-05.99'
        END as tranche,
        COUNT(*) as count
    FROM notes
    WHERE note IS NOT NULL
    GROUP BY tranche
    ORDER BY tranche DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Statistiques par département
$stats['stats_by_departement'] = $conn->query("
    SELECT
        d.nom as nom_departement,
        COUNT(DISTINCT f.id) as filieres_count,
        COUNT(DISTINCT c.id) as classes_count,
        COUNT(DISTINCT i.id) as inscriptions_count
    FROM departements d
    LEFT JOIN filieres f ON d.id = f.departement_id
    LEFT JOIN classes c ON f.id = c.filiere_id
    LEFT JOIN inscriptions i ON c.id = i.classe_id
    GROUP BY d.id, d.nom
    ORDER BY d.nom
")->fetchAll(PDO::FETCH_ASSOC);

// Évolution mensuelle des inscriptions (6 derniers mois)
// Note: La table inscriptions n'a pas de colonne created_at, donc cette stat est commentée
/*
$stats['monthly_inscriptions'] = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count
    FROM inscriptions
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);
*/

// Évolution mensuelle des utilisateurs (6 derniers mois)
// Note: La table users n'a pas de colonne created_at, donc cette stat est commentée
/*
$stats['monthly_users'] = $conn->query("
    SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month
")->fetchAll(PDO::FETCH_ASSOC);
*/
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiques - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chart-bar text-2xl mr-3"></i>
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
                <a href="audit_log.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-history mr-1"></i>Audit
                </a>
                <a href="settings.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-cog mr-1"></i>Paramètres
                </a>
                <a href="stats.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-chart-bar mr-1"></i>Statistiques
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-8 bg-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'error' ? 'red' : 'blue'); ?>-100 border border-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'error' ? 'red' : 'blue'); ?>-400 text-<?php echo $message['type'] === 'success' ? 'green' : ($message['type'] === 'error' ? 'red' : 'blue'); ?>-700 px-4 py-3 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check' : ($message['type'] === 'error' ? 'exclamation' : 'info'); ?>-circle"></i>
                        </div>
                        <div class="ml-3">
                            <p><?php echo htmlspecialchars($message['text']); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques principales -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Utilisateurs</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_users']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo $stats['active_users']; ?> actifs</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-graduation-cap text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Inscriptions</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['total_inscriptions']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo $stats['inscriptions_actives']; ?> actives</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Documents</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['total_documents']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo $stats['documents_valides']; ?> validés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Moyenne générale</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats['moyenne_generale'] ?: 'N/A'; ?>/20</p>
                        <p class="text-sm text-gray-600"><?php echo number_format($stats['total_notes']); ?> notes</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graphiques -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Répartition par rôle -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-user-tag mr-2"></i>Répartition par rôle
                </h3>
                <canvas id="roleChart" width="400" height="300"></canvas>
            </div>

            <!-- Répartition des notes -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-chart-pie mr-2"></i>Répartition des notes
                </h3>
                <canvas id="notesChart" width="400" height="300"></canvas>
            </div>

            <!-- Évolution des inscriptions -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-calendar-plus mr-2"></i>Évolution des inscriptions (6 mois)
                </h3>
                <canvas id="inscriptionsChart" width="400" height="300"></canvas>
            </div>

            <!-- Évolution des utilisateurs -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-user-plus mr-2"></i>Évolution des utilisateurs (6 mois)
                </h3>
                <canvas id="usersChart" width="400" height="300"></canvas>
            </div>
        </div>

        <!-- Statistiques détaillées -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Statistiques par département -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-building mr-2"></i>Statistiques par département
                </h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Département</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filières</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Inscriptions</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Moyenne</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($stats['stats_by_departement'] as $dept): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($dept['nom_departement']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $dept['filieres_count']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $dept['classes_count']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $dept['inscriptions_count']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo $dept['moyenne_notes'] ?: 'N/A'; ?>/20
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Top activités d'audit -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-history mr-2"></i>Top activités d'audit (30 jours)
                </h3>
                <div class="space-y-3">
                    <?php foreach ($stats['top_audit_actions'] as $action): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-sm text-gray-600"><?php echo htmlspecialchars($action['action']); ?></span>
                            <span class="text-sm font-medium text-gray-900"><?php echo $action['count']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Statistiques récapitulatives -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-6">
                <i class="fas fa-clipboard-list mr-2"></i>Récapitulatif général
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-building text-blue-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800"><?php echo $stats['total_departements']; ?></h4>
                    <p class="text-sm text-gray-600">Départements</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-graduation-cap text-green-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800"><?php echo $stats['total_filieres']; ?></h4>
                    <p class="text-sm text-gray-600">Filières</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-chalkboard text-yellow-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800"><?php echo $stats['total_classes']; ?></h4>
                    <p class="text-sm text-gray-600">Classes</p>
                </div>

                <div class="text-center">
                    <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-3">
                        <i class="fas fa-calendar-alt text-purple-600 text-xl"></i>
                    </div>
                    <h4 class="font-semibold text-gray-800"><?php echo $stats['total_annees_academiques']; ?></h4>
                    <p class="text-sm text-gray-600">Années académiques</p>
                </div>
            </div>
        </div>

        <!-- Outils de rapport -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-800 mb-6">
                <i class="fas fa-file-export mr-2"></i>Outils de rapport
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Génération de rapport -->
                <div>
                    <h4 class="font-medium text-gray-800 mb-4">Générer un rapport</h4>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="generate_report">

                        <div>
                            <label for="report_type" class="block text-sm font-medium text-gray-700 mb-2">
                                Type de rapport
                            </label>
                            <select id="report_type" name="report_type" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="utilisateurs">Rapport utilisateurs</option>
                                <option value="inscriptions">Rapport inscriptions</option>
                                <option value="notes">Rapport notes</option>
                                <option value="documents">Rapport documents</option>
                                <option value="audit">Rapport audit</option>
                                <option value="general">Rapport général</option>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date début
                                </label>
                                <input type="date" id="start_date" name="start_date" required
                                       value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date fin
                                </label>
                                <input type="date" id="end_date" name="end_date" required
                                       value="<?php echo date('Y-m-d'); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-file-pdf mr-2"></i>Générer le rapport
                        </button>
                    </form>
                </div>

                <!-- Export des statistiques -->
                <div>
                    <h4 class="font-medium text-gray-800 mb-4">Exporter les statistiques</h4>
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="export_stats">

                        <div>
                            <label for="export_format" class="block text-sm font-medium text-gray-700 mb-2">
                                Format d'export
                            </label>
                            <select id="export_format" name="export_format" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="pdf">PDF</option>
                                <option value="excel">Excel (XLSX)</option>
                                <option value="csv">CSV</option>
                                <option value="json">JSON</option>
                            </select>
                        </div>

                        <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-download mr-2"></i>Exporter
                        </button>
                    </form>
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
        // Graphique répartition par rôle
        const roleCtx = document.getElementById('roleChart').getContext('2d');
        const roleData = <?php echo json_encode($stats['role_distribution']); ?>;
        new Chart(roleCtx, {
            type: 'doughnut',
            data: {
                labels: roleData.map(item => item.role_name),
                datasets: [{
                    data: roleData.map(item => item.count),
                    backgroundColor: [
                        '#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4'
                    ]
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Graphique répartition des notes
        const notesCtx = document.getElementById('notesChart').getContext('2d');
        const notesData = <?php echo json_encode($stats['notes_distribution']); ?>;
        new Chart(notesCtx, {
            type: 'bar',
            data: {
                labels: notesData.map(item => item.tranche),
                datasets: [{
                    label: 'Nombre d\'étudiants',
                    data: notesData.map(item => item.count),
                    backgroundColor: '#10B981',
                    borderColor: '#059669',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Graphique évolution des inscriptions
        const inscriptionsCtx = document.getElementById('inscriptionsChart').getContext('2d');
        const inscriptionsData = <?php echo json_encode($stats['monthly_inscriptions']); ?>;
        new Chart(inscriptionsCtx, {
            type: 'line',
            data: {
                labels: inscriptionsData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Inscriptions',
                    data: inscriptionsData.map(item => item.count),
                    borderColor: '#3B82F6',
                    backgroundColor: '#3B82F640',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Graphique évolution des utilisateurs
        const usersCtx = document.getElementById('usersChart').getContext('2d');
        const usersData = <?php echo json_encode($stats['monthly_users']); ?>;
        new Chart(usersCtx, {
            type: 'line',
            data: {
                labels: usersData.map(item => {
                    const date = new Date(item.month + '-01');
                    return date.toLocaleDateString('fr-FR', { month: 'short', year: 'numeric' });
                }),
                datasets: [{
                    label: 'Nouveaux utilisateurs',
                    data: usersData.map(item => item.count),
                    borderColor: '#10B981',
                    backgroundColor: '#10B98140',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
</body>
</html>