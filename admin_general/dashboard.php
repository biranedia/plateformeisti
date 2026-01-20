<?php
/**
 * Dashboard de l'administrateur général
 * Affiche une vue d'ensemble des statistiques et alertes de la plateforme ISTI
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

// Fonction pour obtenir le nombre total d'utilisateurs
function getTotalUsers($conn) {
    $query = "SELECT COUNT(*) as total FROM users WHERE is_active = true";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'];
}

// Fonction pour obtenir le nombre d'utilisateurs par rôle
function getUsersByRole($conn) {
    $query = "SELECT user_roles.role, COUNT(*) as count 
              FROM user_roles 
              JOIN users ON user_roles.user_id = users.id 
              WHERE users.is_active = true 
              GROUP BY user_roles.role";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir le nombre total de départements
function getTotalDepartements($conn) {
    $query = "SELECT COUNT(*) as total FROM departements";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'];
}

// Fonction pour obtenir le nombre total de filières
function getTotalFilieres($conn) {
    $query = "SELECT COUNT(*) as total FROM filieres";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'];
}

// Fonction pour obtenir le nombre total de classes
function getTotalClasses($conn) {
    $query = "SELECT COUNT(*) as total FROM classes";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['total'];
}

// Fonction pour obtenir les statistiques d'inscriptions par année académique
function getInscriptionsByYear($conn) {
    $query = "SELECT annee_academique, COUNT(*) as count 
              FROM inscriptions 
              GROUP BY annee_academique 
              ORDER BY annee_academique DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les statistiques d'inscriptions par statut
function getInscriptionsByStatus($conn) {
    $query = "SELECT statut, COUNT(*) as count 
              FROM inscriptions 
              GROUP BY statut";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les statistiques de classes par niveau
function getClassesByLevel($conn) {
    $query = "SELECT niveau, COUNT(*) as count 
              FROM classes 
              GROUP BY niveau";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les statistiques de filières par département
function getFilieresByDepartement($conn) {
    $query = "SELECT d.nom as departement, COUNT(f.id) as filieres_count 
              FROM departements d 
              LEFT JOIN filieres f ON d.id = f.departement_id 
              GROUP BY d.id, d.nom";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les statistiques d'étudiants par filière
function getStudentsByFiliere($conn) {
    $query = "SELECT f.nom as filiere, COUNT(DISTINCT i.user_id) as students_count 
              FROM filieres f 
              JOIN classes c ON f.id = c.filiere_id 
              JOIN inscriptions i ON c.id = i.classe_id 
              GROUP BY f.id, f.nom";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les demandes de documents récentes
function getRecentDocumentRequests($conn) {
    $query = "SELECT d.id, d.type_document, d.statut, d.date_creation, u.name as user_name 
              FROM documents d 
              JOIN users u ON d.user_id = u.id 
              ORDER BY d.date_creation DESC 
              LIMIT 10";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les actions d'audit récentes
function getRecentAuditLogs($conn) {
    $query = "SELECT a.action, a.table_cible, a.date_action, u.name as user_name 
              FROM audit_logs a 
              JOIN users u ON a.user_id = u.id 
              ORDER BY a.date_action DESC 
              LIMIT 10";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction pour obtenir les notifications non lues
function getUnreadNotifications($conn) {
    $query = "SELECT COUNT(*) as count 
              FROM notifications 
              WHERE statut = 'non_lu'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'];
}

// Fonction pour obtenir les feedbacks récents
function getRecentFeedbacks($conn) {
    $query = "SELECT f.message, f.type, f.date_envoi, u.name as user_name 
              FROM feedbacks f 
              JOIN users u ON f.user_id = u.id 
              ORDER BY f.date_envoi DESC 
              LIMIT 5";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupération des données pour le dashboard
$totalUsers = getTotalUsers($conn);
$usersByRole = getUsersByRole($conn);
$totalDepartements = getTotalDepartements($conn);
$totalFilieres = getTotalFilieres($conn);
$totalClasses = getTotalClasses($conn);
$inscriptionsByYear = getInscriptionsByYear($conn);
$inscriptionsByStatus = getInscriptionsByStatus($conn);
$classesByLevel = getClassesByLevel($conn);
$filieresByDepartement = getFilieresByDepartement($conn);
$studentsByFiliere = getStudentsByFiliere($conn);
$recentDocumentRequests = getRecentDocumentRequests($conn);
$recentAuditLogs = getRecentAuditLogs($conn);
$unreadNotifications = getUnreadNotifications($conn);
$recentFeedbacks = getRecentFeedbacks($conn);

// Preparation des données pour les graphiques
$chartUserRolesLabels = [];
$chartUserRolesData = [];
foreach ($usersByRole as $role) {
    $chartUserRolesLabels[] = $role['role'];
    $chartUserRolesData[] = $role['count'];
}

$chartInscriptionsYearLabels = [];
$chartInscriptionsYearData = [];
foreach ($inscriptionsByYear as $year) {
    $chartInscriptionsYearLabels[] = $year['annee_academique'];
    $chartInscriptionsYearData[] = $year['count'];
}

$chartInscriptionsStatusLabels = [];
$chartInscriptionsStatusData = [];
foreach ($inscriptionsByStatus as $status) {
    $chartInscriptionsStatusLabels[] = $status['statut'];
    $chartInscriptionsStatusData[] = $status['count'];
}

$chartClassesLevelLabels = [];
$chartClassesLevelData = [];
foreach ($classesByLevel as $level) {
    $chartClassesLevelLabels[] = $level['niveau'];
    $chartClassesLevelData[] = $level['count'];
}

$chartFilieresDeptLabels = [];
$chartFilieresDeptData = [];
foreach ($filieresByDepartement as $dept) {
    $chartFilieresDeptLabels[] = $dept['departement'];
    $chartFilieresDeptData[] = $dept['filieres_count'];
}

$chartStudentsFiliereLabels = [];
$chartStudentsFiliereData = [];
foreach ($studentsByFiliere as $filiere) {
    $chartStudentsFiliereLabels[] = $filiere['filiere'];
    $chartStudentsFiliereData[] = $filiere['students_count'];
}

// Fonction pour formater des données en JSON pour les graphiques
function formatDataForChart($labels, $data) {
    return json_encode([
        'labels' => $labels,
        'data' => $data
    ]);
}

// Formatage des données pour les graphiques JS
$userRolesChartData = formatDataForChart($chartUserRolesLabels, $chartUserRolesData);
$inscriptionsYearChartData = formatDataForChart($chartInscriptionsYearLabels, $chartInscriptionsYearData);
$inscriptionsStatusChartData = formatDataForChart($chartInscriptionsStatusLabels, $chartInscriptionsStatusData);
$classesLevelChartData = formatDataForChart($chartClassesLevelLabels, $chartClassesLevelData);
$filieresDeptChartData = formatDataForChart($chartFilieresDeptLabels, $chartFilieresDeptData);
$studentsFiliereChartData = formatDataForChart($chartStudentsFiliereLabels, $chartStudentsFiliereData);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Administration ISTI</title>
    <!-- Tailwind CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Chart.js pour les graphiques -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
    <!-- Font Awesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        .stat-card {
            transition: transform 0.3s ease;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .chart-container {
            position: relative;
            height: 250px;
            width: 100%;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex flex-col">
       <!-- En-tête (Header) -->
<header class="bg-blue-800 text-white shadow-lg">
    <div class="container mx-auto px-4 py-4 flex justify-between items-center">
        <!-- Logo et titre -->
        <div class="flex items-center">
            <h1 class="text-2xl font-bold">ISTI Admin</h1>
            <span class="ml-2 px-3 py-1 bg-blue-700 rounded-full text-sm">Administrateur Général</span>
        </div>

        <!-- Icônes et profil -->
        <div class="flex items-center space-x-4">
            <!-- Notifications -->
            <div class="relative">
                <button class="p-2 rounded-full hover:bg-blue-700">
                    <i class="fas fa-bell"></i>
                    <?php if ($unreadNotifications > 0): ?>
                        <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full">
                            <?php echo $unreadNotifications; ?>
                        </span>
                    <?php endif; ?>
                </button>
            </div>

            <!-- Menu Admin (Dropdown) -->
            <div class="relative group">
                <button class="bg-blue-700 hover:bg-blue-600 px-3 py-1 rounded text-sm">📁 Gestion</button>
                <ul class="absolute right-0 mt-2 w-72 bg-white text-gray-800 rounded shadow-lg opacity-0 group-hover:opacity-100 transition duration-200 z-50 text-sm divide-y divide-gray-200">
                    <li><a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100">📊 Vue d’ensemble</a></li>
                    <li><a href="departements.php" class="block px-4 py-2 hover:bg-gray-100">🏛️ Départements</a></li>
                    <li><a href="filieres.php" class="block px-4 py-2 hover:bg-gray-100">🧩 Filières</a></li>
                    <li><a href="classes.php" class="block px-4 py-2 hover:bg-gray-100">🏫 Classes</a></li>
                    <li><a href="users.php" class="block px-4 py-2 hover:bg-gray-100">👥 Utilisateurs</a></li>
                    <li><a href="annees_academiques.php" class="block px-4 py-2 hover:bg-gray-100">📅 Années / Semestres</a></li>
                    <li><a href="stats.php" class="block px-4 py-2 hover:bg-gray-100">📈 Statistiques</a></li>
                    <li><a href="audit_log.php" class="block px-4 py-2 hover:bg-gray-100">📋 Journalisation</a></li>
                    <li><a href="settings.php" class="block px-4 py-2 hover:bg-gray-100">⚙️ Paramètres</a></li>
                </ul>
            </div>

            <!-- Profil -->
            <div class="flex items-center space-x-2">
                <span class="hidden md:inline-block"><?php echo $_SESSION['user_name'] ?? 'Administrateur'; ?></span>
                <img class="h-8 w-8 rounded-full border-2 border-white" src="<?php echo $_SESSION['user_photo'] ?? '../assets/img/default-avatar.png'; ?>" alt="Photo de profil">
            </div>

            <!-- Déconnexion -->
            <a href="../shared/logout.php" class="text-sm bg-red-700 hover:bg-red-800 px-3 py-1 rounded">Déconnexion</a>
        </div>
    </div>
</header>


        <!-- Contenu principal -->
        <main class="flex-grow container mx-auto px-4 py-6">
            <!-- Titre de la page -->
            <div class="mb-6">
                <h2 class="text-3xl font-bold text-gray-800">Tableau de bord</h2>
                <p class="text-gray-600">Vue d'ensemble de la plateforme ISTI</p>
            </div>

            <!-- Statistiques générales (Cards) -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="stat-card bg-white rounded-lg shadow-md p-6 border-t-4 border-blue-500">
                    <div class="flex items-center">
                        <div class="rounded-full bg-blue-100 p-3">
                            <i class="fas fa-users text-blue-500 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-gray-500 text-sm uppercase">Utilisateurs</h3>
                            <div class="flex items-center">
                                <span class="text-2xl font-bold text-gray-800"><?php echo $totalUsers; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow-md p-6 border-t-4 border-green-500">
                    <div class="flex items-center">
                        <div class="rounded-full bg-green-100 p-3">
                            <i class="fas fa-building text-green-500 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-gray-500 text-sm uppercase">Départements</h3>
                            <div class="flex items-center">
                                <span class="text-2xl font-bold text-gray-800"><?php echo $totalDepartements; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow-md p-6 border-t-4 border-yellow-500">
                    <div class="flex items-center">
                        <div class="rounded-full bg-yellow-100 p-3">
                            <i class="fas fa-graduation-cap text-yellow-500 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-gray-500 text-sm uppercase">Filières</h3>
                            <div class="flex items-center">
                                <span class="text-2xl font-bold text-gray-800"><?php echo $totalFilieres; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stat-card bg-white rounded-lg shadow-md p-6 border-t-4 border-purple-500">
                    <div class="flex items-center">
                        <div class="rounded-full bg-purple-100 p-3">
                            <i class="fas fa-chalkboard text-purple-500 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="text-gray-500 text-sm uppercase">Classes</h3>
                            <div class="flex items-center">
                                <span class="text-2xl font-bold text-gray-800"><?php echo $totalClasses; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Graphiques et données détaillées -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Répartition des utilisateurs par rôle -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Répartition des utilisateurs par rôle</h3>
                    <div class="chart-container">
                        <canvas id="userRolesChart"></canvas>
                    </div>
                </div>

                <!-- Inscriptions par année académique -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Inscriptions par année académique</h3>
                    <div class="chart-container">
                        <canvas id="inscriptionsYearChart"></canvas>
                    </div>
                </div>

                <!-- Classes par niveau -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Classes par niveau</h3>
                    <div class="chart-container">
                        <canvas id="classesLevelChart"></canvas>
                    </div>
                </div>

                <!-- Filières par département -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Filières par département</h3>
                    <div class="chart-container">
                        <canvas id="filieresDeptChart"></canvas>
                    </div>
                </div>

                <!-- Étudiants par filière -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Étudiants par filière</h3>
                    <div class="chart-container">
                        <canvas id="studentsFiliereChart"></canvas>
                    </div>
                </div>

                <!-- Répartition des inscriptions par statut -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Inscriptions par statut</h3>
                    <div class="chart-container">
                        <canvas id="inscriptionsStatusChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Tableaux des dernières activités -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Demandes de documents récentes -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Demandes de documents récentes</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($recentDocumentRequests as $request): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($request['user_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($request['type_document']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php 
                                            $statusClass = '';
                                            switch($request['statut']) {
                                                case 'en_attente':
                                                    $statusClass = 'bg-yellow-100 text-yellow-800'; 
                                                    break;
                                                case 'valide':
                                                    $statusClass = 'bg-green-100 text-green-800';
                                                    break;
                                                case 'rejete':
                                                    $statusClass = 'bg-red-100 text-red-800';
                                                    break;
                                            }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                            <?php echo htmlspecialchars($request['statut']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d/m/Y H:i', strtotime($request['date_creation'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentDocumentRequests)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">Aucune demande récente</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Journal d'audit récent -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Journal d'audit récent</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Action</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Table</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($recentAuditLogs as $log): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($log['user_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($log['action']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($log['table_cible']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo date('d/m/Y H:i', strtotime($log['date_action'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($recentAuditLogs)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-4 text-center text-sm text-gray-500">Aucune action récente</td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Feedbacks récents -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-bold text-gray-800 mb-4">Feedbacks récents</h3>
                    <div class="space-y-4">
                        <?php foreach ($recentFeedbacks as $feedback): ?>
                            <div class="p-4 border rounded-lg">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <span class="font-semibold"><?php echo htmlspecialchars($feedback['user_name']); ?></span>
                                        <span class="text-gray-500 text-sm ml-2"><?php echo date('d/m/Y H:i', strtotime($feedback['date_envoi'])); ?></span>
                                    </div>
                                    <?php 
                                        $typeClass = '';
                                        switch($feedback['type']) {
                                            case 'bug':
                                                $typeClass = 'bg-red-100 text-red-800'; 
                                                break;
                                            case 'suggestion':
                                                $typeClass = 'bg-blue-100 text-blue-800';
                                                break;
                                            case 'plainte':
                                                $typeClass = 'bg-orange-100 text-orange-800';
                                                break;
                                        }
                                    ?>
                                    <span class="px-2 py-1 text-xs leading-4 font-semibold rounded <?php echo $typeClass; ?>">
                                        <?php echo htmlspecialchars($feedback['type']); ?>
                                    </span>
                                </div>
                                <p class="text-gray-700"><?php echo htmlspecialchars($feedback['message']); ?></p>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($recentFeedbacks)): ?>
                            <div class="p-4 border rounded-lg text-center text-gray-500">
                                Aucun feedback récent
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- Pied de page -->
        <footer class="bg-blue-900 text-white py-4">
            <div class="container mx-auto px-4 text-center">
                <p>© <?php echo date('Y'); ?> Plateforme ISTI - Tous droits réservés</p>
            </div>
        </footer>
    </div>

    <!-- Scripts pour les graphiques -->
    <script>
        // Configuration des couleurs pour les graphiques
        const chartColors = [
            'rgba(54, 162, 235, 0.7)',
            'rgba(75, 192, 192, 0.7)',
            'rgba(255, 206, 86, 0.7)',
            'rgba(255, 99, 132, 0.7)',
            'rgba(153, 102, 255, 0.7)',
            'rgba(255, 159, 64, 0.7)',
            'rgba(199, 199, 199, 0.7)',
            'rgba(83, 102, 255, 0.7)',
            'rgba(40, 159, 64, 0.7)',
            'rgba(210, 199, 199, 0.7)'
        ];
        
        const chartBorderColors = [
            'rgba(54, 162, 235, 1)',
            'rgba(75, 192, 192, 1)',
            'rgba(255, 206, 86, 1)',
            'rgba(255, 99, 132, 1)',
            'rgba(153, 102, 255, 1)',
            'rgba(255, 159, 64, 1)',
            'rgba(199, 199, 199, 1)',
            'rgba(83, 102, 255, 1)',
            'rgba(40, 159, 64, 1)',
            'rgba(210, 199, 199, 1)'
        ];

        // Fonction pour créer un graphique en doughnut
        function createDoughnutChart(elementId, chartData, title) {
            const ctx = document.getElementById(elementId).getContext('2d');
            const data = JSON.parse(chartData);
            
            return new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.labels,
                    datasets: [{
                        data: data.data,
                        backgroundColor: chartColors.slice(0, data.data.length),
                        borderColor: chartBorderColors.slice(0, data.data.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                boxWidth: 12
                            }
                        },
                        title: {
                            display: false,
                            text: title
                        }
                    }
                }
            });
        }

        // Fonction pour créer un graphique en barre
        function createBarChart(elementId, chartData, title) {
            const ctx = document.getElementById(elementId).getContext('2d');
            const data = JSON.parse(chartData);
            
            return new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: title,
                        data: data.data,
                        backgroundColor: chartColors.slice(0, data.data.length),
                        borderColor: chartBorderColors.slice(0, data.data.length),
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        title: {
                            display: false,
                            text: title
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        }

        // Initialisation des graphiques quand la page est chargée
        document.addEventListener('DOMContentLoaded', function() {
            // Graphique des utilisateurs par rôle
            const userRolesChart = createDoughnutChart(
                'userRolesChart', 
                '<?php echo $userRolesChartData; ?>', 
                'Répartition des utilisateurs par rôle'
            );
            
            // Graphique des inscriptions par année
            const inscriptionsYearChart = createBarChart(
                'inscriptionsYearChart', 
                '<?php echo $inscriptionsYearChartData; ?>', 
                'Inscriptions par année académique'
            );
            
            // Graphique des inscriptions par statut
            const inscriptionsStatusChart = createDoughnutChart(
                'inscriptionsStatusChart', 
                '<?php echo $inscriptionsStatusChartData; ?>', 
                'Répartition des inscriptions par statut'
            );
            
            // Graphique des classes par niveau
            const classesLevelChart = createBarChart(
                'classesLevelChart', 
                '<?php echo $classesLevelChartData; ?>', 
                'Classes par niveau'
            );
            
            // Graphique des filières par département
            const filieresDeptChart = createBarChart(
                'filieresDeptChart', 
                '<?php echo $filieresDeptChartData; ?>', 
                'Filières par département'
            );
            
            // Graphique des étudiants par filière
            const studentsFiliereChart = createBarChart(
                'studentsFiliereChart', 
                '<?php echo $studentsFiliereChartData; ?>', 
                'Étudiants par filière'
            );
        });
    </script>
</body>
</html>