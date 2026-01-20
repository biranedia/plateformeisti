<?php
/**
 * Dashboard de l'enseignant
 * Affiche les classes enseignées, emploi du temps, ressources, etc.
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('enseignant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'enseignant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des enseignements de l'enseignant
$enseignements_query = "SELECT e.*, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                       FROM enseignements e
                       JOIN classes c ON e.classe_id = c.id
                       JOIN filieres f ON c.filiere_id = f.id
                       JOIN departements d ON f.departement_id = d.id
                       WHERE e.enseignant_id = :user_id";
$enseignements_stmt = $conn->prepare($enseignements_query);
$enseignements_stmt->bindParam(':user_id', $user_id);
$enseignements_stmt->execute();
$enseignements = $enseignements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération de l'emploi du temps
$edt_query = "SELECT e.* FROM emplois_du_temps e
              WHERE e.enseignant_id = :user_id
              ORDER BY e.jour_semaine, e.heure_debut";
$edt_stmt = $conn->prepare($edt_query);
$edt_stmt->bindParam(':user_id', $user_id);
$edt_stmt->execute();
$emploi_du_temps = $edt_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des ressources pédagogiques
$ressources_query = "SELECT fp.*, e.matiere
                    FROM fichiers_pedagogiques fp
                    JOIN enseignements e ON fp.enseignement_id = e.id
                    WHERE e.enseignant_id = :user_id
                    ORDER BY fp.date_upload DESC LIMIT 10";
$ressources_stmt = $conn->prepare($ressources_query);
$ressources_stmt->bindParam(':user_id', $user_id);
$ressources_stmt->execute();
$ressources = $ressources_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des feedbacks récents
$feedbacks_query = "SELECT f.* FROM feedbacks f
                   WHERE f.type = 'enseignant' OR f.user_id IN (
                       SELECT i.user_id FROM inscriptions i
                       JOIN enseignements e ON i.classe_id = e.classe_id
                       WHERE e.enseignant_id = :user_id
                   )
                   ORDER BY f.date_envoi DESC LIMIT 5";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->bindParam(':user_id', $user_id);
$feedbacks_stmt->execute();
$feedbacks = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$total_classes = count($enseignements);
$total_heures = array_sum(array_column($enseignements, 'volume_horaire'));
$total_ressources = count($ressources);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Enseignant - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-green-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chalkboard-teacher text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Enseignant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($user['name']); ?></span>
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
                <a href="dashboard.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="presence.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-user-check mr-1"></i>Présence
                </a>
                <a href="ressources.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-book mr-1"></i>Ressources
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-comments mr-1"></i>Feedbacks
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-school text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Classes enseignées</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $total_classes; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-clock text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Heures totales</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $total_heures; ?>h</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Ressources</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $total_ressources; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-comments text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Feedbacks</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo count($feedbacks); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Classes enseignées -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-chalkboard mr-2"></i>Classes enseignées
            </h2>
            <?php if (empty($enseignements)): ?>
                <p class="text-gray-600">Aucune classe assignée pour le moment.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($enseignements as $enseignement): ?>
                    <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($enseignement['matiere']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($enseignement['niveau']); ?> - <?php echo htmlspecialchars($enseignement['filiere_nom']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($enseignement['departement_nom']); ?></p>
                        <p class="text-sm text-blue-600">Volume horaire: <?php echo htmlspecialchars($enseignement['volume_horaire']); ?>h</p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Emploi du temps de la semaine -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-calendar-week mr-2"></i>Emploi du temps cette semaine
            </h2>
            <?php if (empty($emploi_du_temps)): ?>
                <p class="text-gray-600">Aucun cours prévu cette semaine.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jour</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Heure</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matière</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Salle</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($emploi_du_temps as $cours): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['jour']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['heure_debut'] . ' - ' . $cours['heure_fin']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['matiere']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                    $classe_query = "SELECT c.niveau, f.nom as filiere FROM classes c JOIN filieres f ON c.filiere_id = f.id WHERE c.id = :id";
                                    $classe_stmt = $conn->prepare($classe_query);
                                    $classe_stmt->bindParam(':id', $cours['classe_id']);
                                    $classe_stmt->execute();
                                    $classe = $classe_stmt->fetch(PDO::FETCH_ASSOC);
                                    echo htmlspecialchars(($classe['niveau'] ?? 'N/A') . ' - ' . ($classe['filiere'] ?? ''));
                                    ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['salle']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Ressources pédagogiques récentes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-book mr-2"></i>Ressources pédagogiques récentes
            </h2>
            <?php if (empty($ressources)): ?>
                <p class="text-gray-600">Aucune ressource pédagogique pour le moment.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($ressources as $ressource): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($ressource['titre'] ?: 'Sans titre'); ?></h4>
                            <p class="text-sm text-gray-600">Matière: <?php echo htmlspecialchars($ressource['matiere']); ?> | Upload: <?php echo htmlspecialchars($ressource['date_upload']); ?></p>
                        </div>
                        <div class="text-right">
                            <a href="<?php echo htmlspecialchars($ressource['fichier_url']); ?>" target="_blank" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-download mr-1"></i>Télécharger
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Feedbacks récents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-comments mr-2"></i>Feedbacks récents
            </h2>
            <?php if (empty($feedbacks)): ?>
                <p class="text-gray-600">Aucun feedback pour le moment.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($feedbacks as $feedback): ?>
                    <div class="p-4 bg-gray-50 rounded-lg">
                        <p class="text-gray-800"><?php echo htmlspecialchars($feedback['message']); ?></p>
                        <p class="text-sm text-gray-600">Type: <?php echo htmlspecialchars($feedback['type']); ?> | Date: <?php echo htmlspecialchars($feedback['date_envoi']); ?></p>
                    </div>
                    <?php endforeach; ?>
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
</body>
</html>