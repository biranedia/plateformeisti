<?php

// Inclusion des fichiers de configuration
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/utils.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISTI - Institut Supérieur de Technologie Industrielle</title>
    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Styles personnalisés */
        .isti-primary { color: #003366; }
        .isti-bg-primary { background-color: #003366; }
        .isti-secondary { color: #F5A623; }
        .isti-bg-secondary { background-color: #F5A623; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col">
    <!-- Navbar -->
    <nav class="bg-white shadow-lg">
        <div class="container mx-auto px-4">
            <div class="flex justify-between">
                <div class="flex space-x-7">
                    <div>
                        <!-- Logo -->
                        <a href="/" class="flex items-center py-4">
                            <span class="font-semibold text-2xl isti-primary">ISTI</span>
                        </a>
                    </div>
                </div>
                <!-- Navigation principale -->
                <div class="flex items-center space-x-1">
                    <a href="../shared/login.php" class="py-4 px-2 text-gray-500 hover:text-blue-500 transition duration-300">Connexion</a>
                    <a href="../shared/register.php" class="py-2 px-4 bg-blue-600 hover:bg-blue-700 text-white rounded transition duration-300">Inscription</a>
                </div>
            </div>
        </div>
    </nav>

    <?php
    // Affichage des messages d'alerte
    if (isset($_SESSION['alert_message'])) {
        echo alert($_SESSION['alert_message'], $_SESSION['alert_type'] ?? 'info');
        unset($_SESSION['alert_message']);
        unset($_SESSION['alert_type']);
    }
    ?>

    <!-- Contenu principal -->
    <main class="flex-grow container mx-auto px-4 py-8">