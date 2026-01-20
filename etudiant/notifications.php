<?php
/**
 * Notifications pour les étudiants
 * Permet de consulter les notifications reçues
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('etudiant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'étudiant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération des notifications
$notif_query = "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY date_creation DESC";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bindParam(':user_id', $user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des notifications
$total_notifications = count($notifications);
$non_lues = count(array_filter($notifications, function($n) { return !$n['lue']; }));
$recents = count(array_filter($notifications, function($n) {
    return strtotime($n['date_creation']) > strtotime('-7 days');
}));

// Traitement de la mise à jour du statut de lecture
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'marquer_lue') {
        $notification_id = sanitize($_POST['notification_id']);

        try {
            $update_query = "UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE id = :id AND user_id = :user_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':id', $notification_id);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();

            $messages[] = ['type' => 'success', 'text' => 'Notification marquée comme lue.'];

            // Recharger les notifications
            $notif_stmt->execute();
            $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
            $non_lues = count(array_filter($notifications, function($n) { return !$n['lue']; }));

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la mise à jour: ' . $e->getMessage()];
        }
    } elseif ($_POST['action'] === 'marquer_toutes_lues') {
        try {
            $update_query = "UPDATE notifications SET lue = 1, date_lecture = NOW() WHERE user_id = :user_id AND lue = 0";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();

            $messages[] = ['type' => 'success', 'text' => 'Toutes les notifications ont été marquées comme lues.'];

            // Recharger les notifications
            $notif_stmt->execute();
            $notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
            $non_lues = 0;

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la mise à jour: ' . $e->getMessage()];
        }
    }
}

// Grouper les notifications par type
$notifications_par_type = [];
foreach ($notifications as $notif) {
    $type = $notif['type'];
    if (!isset($notifications_par_type[$type])) {
        $notifications_par_type[$type] = [];
    }
    $notifications_par_type[$type][] = $notif;
}

// Types de notifications avec icônes
$types_notifications = [
    'info' => ['icon' => 'fas fa-info-circle', 'color' => 'blue', 'label' => 'Informations'],
    'success' => ['icon' => 'fas fa-check-circle', 'color' => 'green', 'label' => 'Succès'],
    'warning' => ['icon' => 'fas fa-exclamation-triangle', 'color' => 'yellow', 'label' => 'Avertissement'],
    'error' => ['icon' => 'fas fa-times-circle', 'color' => 'red', 'label' => 'Erreur'],
    'academic' => ['icon' => 'fas fa-graduation-cap', 'color' => 'purple', 'label' => 'Académique'],
    'administrative' => ['icon' => 'fas fa-file-alt', 'color' => 'indigo', 'label' => 'Administratif']
];
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
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Étudiant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Étudiant'); ?></span>
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
                <a href="profil.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user mr-1"></i>Profil
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="notifications.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-bell mr-1"></i>Notifications
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded <?php echo $message['type'] === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700'; ?> border">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Aperçu des notifications
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-bell text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $total_notifications; ?></p>
                    <p class="text-sm text-gray-600">notifications</p>
                </div>

                <div class="text-center">
                    <div class="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-envelope text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Non lues</h3>
                    <p class="text-2xl font-bold text-red-600"><?php echo $non_lues; ?></p>
                    <p class="text-sm text-gray-600">notifications</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clock text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Cette semaine</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $recents; ?></p>
                    <p class="text-sm text-gray-600">notifications</p>
                </div>
            </div>

            <?php if ($non_lues > 0): ?>
            <div class="mt-6 text-center">
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="marquer_toutes_lues">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-check-double mr-2"></i>Marquer toutes comme lues
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- Notifications -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-bell mr-2"></i>Mes notifications
            </h2>

            <?php if (empty($notifications)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-bell-slash text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune notification</h3>
                    <p class="text-gray-500">Vous n'avez reçu aucune notification pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($notifications as $notif): ?>
                    <div class="border rounded-lg p-4 <?php echo !$notif['lue'] ? 'bg-blue-50 border-blue-200' : 'bg-gray-50'; ?> hover:shadow-md transition duration-200">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-3 flex-1">
                                <div class="flex-shrink-0">
                                    <?php
                                    $type_info = $types_notifications[$notif['type']] ?? $types_notifications['info'];
                                    $color_class = 'text-' . $type_info['color'] . '-600';
                                    ?>
                                    <i class="<?php echo $type_info['icon']; ?> <?php echo $color_class; ?> text-xl mt-1"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-1">
                                        <h3 class="font-semibold text-gray-800">
                                            <?php echo htmlspecialchars($notif['titre']); ?>
                                        </h3>
                                        <?php if (!$notif['lue']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-blue-100 text-blue-800">
                                            <i class="fas fa-circle mr-1"></i>Nouveau
                                        </span>
                                        <?php endif; ?>
                                        <span class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars($types_notifications[$notif['type']]['label'] ?? 'Info'); ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-700 mb-2"><?php echo htmlspecialchars($notif['message']); ?></p>
                                    <div class="flex items-center text-sm text-gray-600 space-x-4">
                                        <span>
                                            <i class="fas fa-calendar-alt mr-1"></i>
                                            <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($notif['date_creation']))); ?>
                                        </span>
                                        <?php if ($notif['date_lecture']): ?>
                                        <span>
                                            <i class="fas fa-eye mr-1"></i>
                                            Lu le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($notif['date_lecture']))); ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php if (!$notif['lue']): ?>
                            <div class="ml-4">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="action" value="marquer_lue">
                                    <input type="hidden" name="notification_id" value="<?php echo $notif['id']; ?>">
                                    <button type="submit" class="text-blue-600 hover:text-blue-800 text-sm underline">
                                        Marquer comme lu
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations sur les notifications -->
        <div class="bg-gray-50 border border-gray-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-lightbulb text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-gray-800">
                        À propos des notifications
                    </h3>
                    <div class="mt-2 text-sm text-gray-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Les notifications importantes apparaissent en bleu avec un indicateur "Nouveau"</li>
                            <li>Vous recevrez des notifications pour les nouvelles notes, documents validés, changements d'emploi du temps, etc.</li>
                            <li>Les notifications sont conservées pendant 6 mois</li>
                            <li>Vous pouvez marquer les notifications comme lues individuellement ou toutes en une fois</li>
                            <li>En cas de problème, contactez l'administration pour vérifier vos paramètres de notification</li>
                        </ul>
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
</body>
</html>