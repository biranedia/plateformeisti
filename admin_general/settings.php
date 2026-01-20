<?php
/**
 * Paramètres système - Administration ISTI
 * Gestion des paramètres globaux de la plateforme
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

        if ($action === 'update_general_settings') {
            $site_name = sanitize($_POST['site_name']);
            $site_description = sanitize($_POST['site_description']);
            $admin_email = sanitize($_POST['admin_email']);
            $timezone = sanitize($_POST['timezone']);
            $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;

            try {
                // Mettre à jour ou insérer les paramètres généraux
                $settings = [
                    'site_name' => $site_name,
                    'site_description' => $site_description,
                    'admin_email' => $admin_email,
                    'timezone' => $timezone,
                    'maintenance_mode' => $maintenance_mode
                ];

                foreach ($settings as $key => $value) {
                    $query = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                             ON DUPLICATE KEY UPDATE setting_value = :value";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':key', $key);
                    $stmt->bindParam(':value', $value);
                    $stmt->execute();
                }

                $messages[] = ['type' => 'success', 'text' => 'Paramètres généraux mis à jour avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Modification des paramètres généraux", "settings");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'update_security_settings') {
            $session_timeout = (int)$_POST['session_timeout'];
            $password_min_length = (int)$_POST['password_min_length'];
            $login_attempts_max = (int)$_POST['login_attempts_max'];
            $lockout_duration = (int)$_POST['lockout_duration'];
            $two_factor_required = isset($_POST['two_factor_required']) ? 1 : 0;

            try {
                $security_settings = [
                    'session_timeout' => $session_timeout,
                    'password_min_length' => $password_min_length,
                    'login_attempts_max' => $login_attempts_max,
                    'lockout_duration' => $lockout_duration,
                    'two_factor_required' => $two_factor_required
                ];

                foreach ($security_settings as $key => $value) {
                    $query = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                             ON DUPLICATE KEY UPDATE setting_value = :value";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':key', $key);
                    $stmt->bindParam(':value', $value);
                    $stmt->execute();
                }

                $messages[] = ['type' => 'success', 'text' => 'Paramètres de sécurité mis à jour avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Modification des paramètres de sécurité", "settings");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'update_email_settings') {
            $smtp_host = sanitize($_POST['smtp_host']);
            $smtp_port = (int)$_POST['smtp_port'];
            $smtp_username = sanitize($_POST['smtp_username']);
            $smtp_password = $_POST['smtp_password']; // Ne pas sanitiser le mot de passe
            $smtp_encryption = sanitize($_POST['smtp_encryption']);
            $email_from = sanitize($_POST['email_from']);
            $email_from_name = sanitize($_POST['email_from_name']);

            try {
                $email_settings = [
                    'smtp_host' => $smtp_host,
                    'smtp_port' => $smtp_port,
                    'smtp_username' => $smtp_username,
                    'smtp_password' => $smtp_password,
                    'smtp_encryption' => $smtp_encryption,
                    'email_from' => $email_from,
                    'email_from_name' => $email_from_name
                ];

                foreach ($email_settings as $key => $value) {
                    $query = "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                             ON DUPLICATE KEY UPDATE setting_value = :value";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':key', $key);
                    $stmt->bindParam(':value', $value);
                    $stmt->execute();
                }

                $messages[] = ['type' => 'success', 'text' => 'Paramètres email mis à jour avec succès.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Modification des paramètres email", "settings");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'test_email') {
            $test_email = sanitize($_POST['test_email']);

            if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                $messages[] = ['type' => 'error', 'text' => 'Adresse email de test invalide.'];
            } else {
                // Simulation d'envoi d'email de test
                $messages[] = ['type' => 'info', 'text' => 'Test d\'email envoyé à ' . $test_email . ' (fonctionnalité à implémenter avec une bibliothèque email).'];
            }
        }

        if ($action === 'reset_settings') {
            try {
                // Supprimer tous les paramètres
                $query = "DELETE FROM settings";
                $stmt = $conn->prepare($query);
                $stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Tous les paramètres ont été réinitialisés.'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Réinitialisation de tous les paramètres", "settings");

            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la réinitialisation: ' . $e->getMessage()];
            }
        }
    }
}

// Récupération des paramètres actuels
$current_settings = [
    'site_name' => getSetting('site_name', 'Plateforme ISTI'),
    'site_description' => getSetting('site_description', 'Institut Supérieur de Technologie et d\'Informatique'),
    'admin_email' => getSetting('admin_email', ''),
    'timezone' => getSetting('timezone', 'Europe/Paris'),
    'maintenance_mode' => getSetting('maintenance_mode', '0'),
    'session_timeout' => getSetting('session_timeout', '3600'),
    'password_min_length' => getSetting('password_min_length', '8'),
    'login_attempts_max' => getSetting('login_attempts_max', '5'),
    'lockout_duration' => getSetting('lockout_duration', '900'),
    'two_factor_required' => getSetting('two_factor_required', '0'),
    'smtp_host' => getSetting('smtp_host', ''),
    'smtp_port' => getSetting('smtp_port', '587'),
    'smtp_username' => getSetting('smtp_username', ''),
    'smtp_password' => getSetting('smtp_password', ''),
    'smtp_encryption' => getSetting('smtp_encryption', 'tls'),
    'email_from' => getSetting('email_from', ''),
    'email_from_name' => getSetting('email_from_name', 'ISTI')
];

// Liste des fuseaux horaires
$timezones = [
    'Europe/Paris' => 'Europe/Paris (UTC+1/+2)',
    'Europe/London' => 'Europe/London (UTC+0/+1)',
    'America/New_York' => 'America/New_York (UTC-5/-4)',
    'Asia/Dubai' => 'Asia/Dubai (UTC+4)',
    'Asia/Tokyo' => 'Asia/Tokyo (UTC+9)',
    'Australia/Sydney' => 'Australia/Sydney (UTC+10/+11)'
];

// Statistiques des paramètres
$stats_query = "SELECT COUNT(*) as total_settings FROM settings";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paramètres Système - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-cog text-2xl mr-3"></i>
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
                <a href="stats.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chart-bar mr-1"></i>Statistiques
                </a>
                <a href="settings.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
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

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-cog text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Paramètres actifs</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_settings']; ?></p>
                        <p class="text-sm text-gray-600">paramètres configurés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-shield-alt text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Sécurité</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $current_settings['password_min_length']; ?> chars</p>
                        <p class="text-sm text-gray-600">longueur min. mot de passe</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Session</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo round($current_settings['session_timeout'] / 60); ?> min</p>
                        <p class="text-sm text-gray-600">timeout de session</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-envelope text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Email</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $current_settings['smtp_host'] ? 'Configuré' : 'Non configuré'; ?></p>
                        <p class="text-sm text-gray-600">serveur SMTP</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="bg-white rounded-lg shadow-md">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex">
                    <button onclick="showTab('general')" id="tab-general" class="tab-button w-1/4 py-4 px-1 text-center border-b-2 font-medium text-sm border-indigo-500 text-indigo-600">
                        Général
                    </button>
                    <button onclick="showTab('security')" id="tab-security" class="tab-button w-1/4 py-4 px-1 text-center border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                        Sécurité
                    </button>
                    <button onclick="showTab('email')" id="tab-email" class="tab-button w-1/4 py-4 px-1 text-center border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                        Email
                    </button>
                    <button onclick="showTab('maintenance')" id="tab-maintenance" class="tab-button w-1/4 py-4 px-1 text-center border-b-2 font-medium text-sm border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300">
                        Maintenance
                    </button>
                </nav>
            </div>

            <div class="p-6">
                <!-- Paramètres généraux -->
                <div id="content-general" class="tab-content">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-globe mr-2"></i>Paramètres généraux
                    </h2>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_general_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="site_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Nom du site *
                                </label>
                                <input type="text" id="site_name" name="site_name" required
                                       value="<?php echo htmlspecialchars($current_settings['site_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="admin_email" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email administrateur
                                </label>
                                <input type="email" id="admin_email" name="admin_email"
                                       value="<?php echo htmlspecialchars($current_settings['admin_email']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="md:col-span-2">
                                <label for="site_description" class="block text-sm font-medium text-gray-700 mb-2">
                                    Description du site
                                </label>
                                <textarea id="site_description" name="site_description" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($current_settings['site_description']); ?></textarea>
                            </div>

                            <div>
                                <label for="timezone" class="block text-sm font-medium text-gray-700 mb-2">
                                    Fuseau horaire
                                </label>
                                <select id="timezone" name="timezone"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <?php foreach ($timezones as $tz_key => $tz_name): ?>
                                        <option value="<?php echo $tz_key; ?>" <?php echo $current_settings['timezone'] === $tz_key ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($tz_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" id="maintenance_mode" name="maintenance_mode" value="1"
                                       <?php echo $current_settings['maintenance_mode'] == '1' ? 'checked' : ''; ?>
                                       class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <label for="maintenance_mode" class="ml-2 text-sm text-gray-700">
                                    Mode maintenance activé
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Enregistrer les paramètres généraux
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Paramètres de sécurité -->
                <div id="content-security" class="tab-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-shield-alt mr-2"></i>Paramètres de sécurité
                    </h2>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_security_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="session_timeout" class="block text-sm font-medium text-gray-700 mb-2">
                                    Timeout de session (secondes)
                                </label>
                                <input type="number" id="session_timeout" name="session_timeout" min="300" max="86400" required
                                       value="<?php echo htmlspecialchars($current_settings['session_timeout']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <p class="text-xs text-gray-500 mt-1">Minimum 300 secondes (5 minutes), maximum 86400 secondes (24 heures)</p>
                            </div>

                            <div>
                                <label for="password_min_length" class="block text-sm font-medium text-gray-700 mb-2">
                                    Longueur minimale du mot de passe
                                </label>
                                <input type="number" id="password_min_length" name="password_min_length" min="6" max="32" required
                                       value="<?php echo htmlspecialchars($current_settings['password_min_length']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="login_attempts_max" class="block text-sm font-medium text-gray-700 mb-2">
                                    Nombre maximum de tentatives de connexion
                                </label>
                                <input type="number" id="login_attempts_max" name="login_attempts_max" min="3" max="20" required
                                       value="<?php echo htmlspecialchars($current_settings['login_attempts_max']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="lockout_duration" class="block text-sm font-medium text-gray-700 mb-2">
                                    Durée de blocage après échec (secondes)
                                </label>
                                <input type="number" id="lockout_duration" name="lockout_duration" min="60" max="3600" required
                                       value="<?php echo htmlspecialchars($current_settings['lockout_duration']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <p class="text-xs text-gray-500 mt-1">Durée pendant laquelle l'utilisateur ne peut pas se reconnecter</p>
                            </div>

                            <div class="md:col-span-2">
                                <label class="flex items-center">
                                    <input type="checkbox" id="two_factor_required" name="two_factor_required" value="1"
                                           <?php echo $current_settings['two_factor_required'] == '1' ? 'checked' : ''; ?>
                                           class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                    <label for="two_factor_required" class="ml-2 text-sm text-gray-700">
                                        Authentification à deux facteurs obligatoire pour tous les utilisateurs
                                    </label>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Enregistrer les paramètres de sécurité
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Paramètres email -->
                <div id="content-email" class="tab-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-envelope mr-2"></i>Paramètres email
                    </h2>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_email_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="smtp_host" class="block text-sm font-medium text-gray-700 mb-2">
                                    Serveur SMTP
                                </label>
                                <input type="text" id="smtp_host" name="smtp_host"
                                       value="<?php echo htmlspecialchars($current_settings['smtp_host']); ?>"
                                       placeholder="smtp.gmail.com"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="smtp_port" class="block text-sm font-medium text-gray-700 mb-2">
                                    Port SMTP
                                </label>
                                <input type="number" id="smtp_port" name="smtp_port" min="1" max="65535"
                                       value="<?php echo htmlspecialchars($current_settings['smtp_port']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="smtp_username" class="block text-sm font-medium text-gray-700 mb-2">
                                    Nom d'utilisateur SMTP
                                </label>
                                <input type="text" id="smtp_username" name="smtp_username"
                                       value="<?php echo htmlspecialchars($current_settings['smtp_username']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="smtp_password" class="block text-sm font-medium text-gray-700 mb-2">
                                    Mot de passe SMTP
                                </label>
                                <input type="password" id="smtp_password" name="smtp_password"
                                       value="<?php echo htmlspecialchars($current_settings['smtp_password']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="smtp_encryption" class="block text-sm font-medium text-gray-700 mb-2">
                                    Chiffrement
                                </label>
                                <select id="smtp_encryption" name="smtp_encryption"
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="none" <?php echo $current_settings['smtp_encryption'] === 'none' ? 'selected' : ''; ?>>Aucun</option>
                                    <option value="ssl" <?php echo $current_settings['smtp_encryption'] === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                    <option value="tls" <?php echo $current_settings['smtp_encryption'] === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                </select>
                            </div>

                            <div>
                                <label for="email_from" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email expéditeur
                                </label>
                                <input type="email" id="email_from" name="email_from"
                                       value="<?php echo htmlspecialchars($current_settings['email_from']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="md:col-span-2">
                                <label for="email_from_name" class="block text-sm font-medium text-gray-700 mb-2">
                                    Nom de l'expéditeur
                                </label>
                                <input type="text" id="email_from_name" name="email_from_name"
                                       value="<?php echo htmlspecialchars($current_settings['email_from_name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="flex justify-between">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="test_email">
                                <label for="test_email" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email de test
                                </label>
                                <div class="flex space-x-2">
                                    <input type="email" id="test_email" name="test_email" placeholder="votre.email@test.com"
                                           class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-paper-plane mr-2"></i>Tester
                                    </button>
                                </div>
                            </form>

                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Enregistrer les paramètres email
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Maintenance -->
                <div id="content-maintenance" class="tab-content hidden">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-tools mr-2"></i>Outils de maintenance
                    </h2>

                    <div class="space-y-6">
                        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6">
                            <div class="flex items-start">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-xl mt-1 mr-3"></i>
                                <div>
                                    <h3 class="text-lg font-medium text-yellow-800 mb-2">Zone de danger</h3>
                                    <p class="text-yellow-700 mb-4">
                                        Les actions suivantes sont irréversibles et peuvent affecter le fonctionnement de la plateforme.
                                        Utilisez-les avec précaution.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="bg-white border border-gray-200 rounded-lg p-6">
                                <h3 class="text-lg font-medium text-gray-800 mb-4">
                                    <i class="fas fa-database mr-2"></i>Base de données
                                </h3>
                                <div class="space-y-3">
                                    <button onclick="alert('Fonctionnalité à implémenter')" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-download mr-2"></i>Sauvegarder la base de données
                                    </button>
                                    <button onclick="alert('Fonctionnalité à implémenter')" class="w-full bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-upload mr-2"></i>Restaurer la base de données
                                    </button>
                                </div>
                            </div>

                            <div class="bg-white border border-gray-200 rounded-lg p-6">
                                <h3 class="text-lg font-medium text-gray-800 mb-4">
                                    <i class="fas fa-cog mr-2"></i>Paramètres système
                                </h3>
                                <div class="space-y-3">
                                    <button onclick="exportSettings()" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-file-export mr-2"></i>Exporter les paramètres
                                    </button>
                                    <form method="POST" onsubmit="return confirm('Êtes-vous sûr de vouloir réinitialiser tous les paramètres ? Cette action est irréversible.')">
                                        <input type="hidden" name="action" value="reset_settings">
                                        <button type="submit" class="w-full bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                            <i class="fas fa-undo mr-2"></i>Réinitialiser tous les paramètres
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white border border-gray-200 rounded-lg p-6">
                            <h3 class="text-lg font-medium text-gray-800 mb-4">
                                <i class="fas fa-info-circle mr-2"></i>Informations système
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="font-medium text-gray-700">Version PHP:</span>
                                    <span class="text-gray-600"><?php echo phpversion(); ?></span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Base de données:</span>
                                    <span class="text-gray-600">MySQL <?php echo $conn->getAttribute(PDO::ATTR_SERVER_VERSION); ?></span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Serveur web:</span>
                                    <span class="text-gray-600"><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Inconnu'; ?></span>
                                </div>
                                <div>
                                    <span class="font-medium text-gray-700">Mémoire utilisée:</span>
                                    <span class="text-gray-600"><?php echo round(memory_get_peak_usage() / 1024 / 1024, 2); ?> MB</span>
                                </div>
                            </div>
                        </div>
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
        function showTab(tabName) {
            // Masquer tous les contenus
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            // Désactiver tous les onglets
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('border-indigo-500', 'text-indigo-600');
                button.classList.add('border-transparent', 'text-gray-500');
            });

            // Afficher l'onglet sélectionné
            document.getElementById('content-' + tabName).classList.remove('hidden');
            document.getElementById('tab-' + tabName).classList.add('border-indigo-500', 'text-indigo-600');
            document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');
        }

        function exportSettings() {
            alert('Export des paramètres (fonctionnalité à implémenter)');
        }
    </script>
</body>
</html>