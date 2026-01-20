<?php
// Initialisation de la session
session_start();

// Configuration de l'en-tête
header("HTTP/1.0 500 Internal Server Error");

// Journalisation de l'erreur
$requested_url = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'URL inconnue';
$ip = $_SERVER['REMOTE_ADDR'];
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
$error_message = isset($error_message) ? $error_message : "Erreur serveur interne inconnue";

// Si disponible, enregistrer dans le journal d'audit
if (file_exists('config/database.php')) {
    require_once 'config/database.php';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_cible) VALUES (?, ?, 'error_logs')");
        $action = "Erreur 500 - Erreur serveur interne: " . $requested_url . " - Message: " . $error_message . " - IP: " . $ip;
        $stmt->execute([$user_id, $action]);
    } catch (PDOException $e) {
        // Silence l'erreur pour ne pas aggraver le problème
        error_log("Erreur lors de la journalisation de l'erreur 500: " . $e->getMessage());
    }
}

// Déterminer si l'utilisateur est connecté
$is_logged_in = isset($_SESSION['user_id']);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Erreur serveur - Plateforme ISTI</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .error-container {
            perspective: 1000px;
        }
        .error-text {
            animation: shake 5s cubic-bezier(.36,.07,.19,.97) infinite;
        }
        @keyframes shake {
            0%, 92%, 94%, 98%, 100% {
                transform: translateX(0);
            }
            93%, 97% {
                transform: translateX(-5px);
            }
            95%, 99% {
                transform: translateX(5px);
            }
        }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center">
    <div class="container mx-auto px-4">
        <div class="error-container max-w-lg mx-auto text-center">
            <div class="mb-8 error-text">
                <h1 class="text-9xl font-bold text-red-600">500</h1>
                <p class="text-2xl font-semibold text-gray-700 mt-4">Erreur serveur interne</p>
            </div>
            
            <div class="bg-white rounded-lg shadow-md p-8 mb-8">
                <div class="text-gray-600 mb-6">
                    <p>Nous rencontrons un problème technique. Notre équipe a été informée et travaille à le résoudre.</p>
                    <p class="mt-2">Veuillez nous excuser pour la gêne occasionnée.</p>
                </div>
                
                <div class="flex flex-col space-y-4">
                    <?php if ($is_logged_in): ?>
                        <!-- Options pour utilisateurs connectés -->
                        <a href="/dashboard.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-md transition duration-300 flex items-center justify-center">
                            <i class="fas fa-home mr-2"></i> Retour au tableau de bord
                        </a>
                    <?php else: ?>
                        <!-- Options pour visiteurs non connectés -->
                        <a href="/login.php" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 px-4 rounded-md transition duration-300 flex items-center justify-center">
                            <i class="fas fa-sign-in-alt mr-2"></i> Page de connexion
                        </a>
                    <?php endif; ?>
                    
                    <a href="javascript:window.location.reload(true)" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-3 px-4 rounded-md transition duration-300 flex items-center justify-center">
                        <i class="fas fa-sync-alt mr-2"></i> Rafraîchir la page
                    </a>
                    
                    <a href="javascript:history.back()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-3 px-4 rounded-md transition duration-300 flex items-center justify-center">
                        <i class="fas fa-arrow-left mr-2"></i> Page précédente
                    </a>
                </div>
            </div>
            
            <div class="text-gray-500 text-sm">
                <p>Si le problème persiste, veuillez <a href="mailto:support@isti.edu" class="text-blue-600 hover:underline">contacter l'administrateur</a></p>
                <p class="mt-2">ID d'erreur: <?php echo uniqid('err_'); ?></p>
            </div>
        </div>
    </div>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Animation d'apparition
        const errorContainer = document.querySelector('.error-container');
        errorContainer.classList.add('opacity-0');
        setTimeout(function() {
            errorContainer.classList.remove('opacity-0');
            errorContainer.classList.add('transition-opacity', 'duration-500', 'opacity-100');
        }, 100);
        
        // Enregistrement de l'URL qui a causé l'erreur
        console.log("Erreur serveur sur: " + window.location.href);
        
        // Notification automatique (simulée)
        console.log("Notification d'erreur envoyée à l'administrateur");
    });
    </script>
</body>
</html>