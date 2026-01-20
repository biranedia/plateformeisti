<?php
/**
 * Documents téléchargés pour les étudiants
 * Permet de consulter et télécharger les documents validés
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

// Récupération des documents validés
$docs_query = "SELECT * FROM documents WHERE user_id = :user_id AND statut = 'valide' AND fichier_url IS NOT NULL ORDER BY date_creation DESC";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':user_id', $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de documents disponibles
$types_documents = [
    'attestation_scolarite' => 'Attestation de scolarité',
    'releve_notes' => 'Relevé de notes',
    'certificat_reussite' => 'Certificat de réussite'
];

// Statistiques des documents
$stats_query = "SELECT type_document, COUNT(*) as count FROM documents WHERE user_id = :user_id AND statut = 'valide' GROUP BY type_document";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':user_id', $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organiser les statistiques
$stats_by_type = [];
foreach ($stats as $stat) {
    $stats_by_type[$stat['type_document']] = $stat['count'];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents Téléchargés - ISTI</title>
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
                    <i class="fas fa-file-alt mr-1"></i>Demandes
                </a>
                <a href="documents_telecharges.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-download mr-1"></i>Téléchargements
                </a>
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des documents
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($types_documents as $key => $label): ?>
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-file-alt text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2"><?php echo htmlspecialchars($label); ?></h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats_by_type[$key] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">document(s)</p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Documents disponibles -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-folder-open mr-2"></i>Documents disponibles
            </h2>

            <?php if (empty($documents)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-folder-open text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun document disponible</h3>
                    <p class="text-gray-500 mb-6">Vous n'avez pas encore de documents validés à télécharger.</p>
                    <a href="demandes_documents.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Faire une demande
                    </a>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($documents as $doc): ?>
                    <div class="border rounded-lg p-6 hover:shadow-lg transition duration-200">
                        <div class="flex items-center mb-4">
                            <div class="bg-blue-100 rounded-full p-3 mr-4">
                                <i class="fas fa-file-pdf text-blue-600 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold text-gray-800">
                                    <?php echo htmlspecialchars($types_documents[$doc['type_document']] ?? $doc['type_document']); ?>
                                </h3>
                                <p class="text-sm text-gray-600">
                                    <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['date_creation']))); ?>
                                </p>
                            </div>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-calendar-alt mr-2"></i>
                                Demandé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['date_creation']))); ?>
                            </div>

                            <?php if ($doc['date_validation']): ?>
                            <div class="flex items-center text-sm text-green-600">
                                <i class="fas fa-check-circle mr-2"></i>
                                Validé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['date_validation']))); ?>
                            </div>
                            <?php endif; ?>

                            <div class="pt-3">
                                <a href="<?php echo htmlspecialchars($doc['fichier_url']); ?>" target="_blank"
                                   class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 text-center block">
                                    <i class="fas fa-download mr-2"></i>Télécharger
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations importantes -->
        <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">
                        Informations importantes
                    </h3>
                    <div class="mt-2 text-sm text-yellow-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Conservez vos documents dans un endroit sécurisé</li>
                            <li>Les documents sont disponibles pendant 1 an après validation</li>
                            <li>En cas de problème de téléchargement, contactez l'administration</li>
                            <li>Les documents officiels doivent être présentés avec la carte d'étudiant</li>
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