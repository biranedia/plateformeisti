<?php
/**
 * Dashboard du responsable de département
 * Gestion des filières, enseignants, emploi du temps, etc.
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_dept')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de département pour accéder à cette page.', 'error');
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

// Récupération du département géré
$dept_query = "SELECT * FROM departements WHERE responsable_id = :user_id";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->bindParam(':user_id', $user_id);
$dept_stmt->execute();
$departement = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de département assigné
if (!$departement) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucun département assigné</h2>
            <p>Vous n'êtes pas encore assigné à un département. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Statistiques du département
$stats_dept = [
    'filieres' => 0,
    'classes' => 0,
    'etudiants' => 0,
    'enseignants' => 0
];

// Nombre de filières
$filieres_query = "SELECT COUNT(*) as count FROM filieres WHERE departement_id = :dept_id";
$filieres_stmt = $conn->prepare($filieres_query);
$filieres_stmt->bindParam(':dept_id', $departement['id']);
$filieres_stmt->execute();
$stats_dept['filieres'] = $filieres_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre de classes
$classes_query = "SELECT COUNT(*) as count FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 WHERE f.departement_id = :dept_id";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':dept_id', $departement['id']);
$classes_stmt->execute();
$stats_dept['classes'] = $classes_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre d'étudiants
$etudiants_query = "SELECT COUNT(DISTINCT i.user_id) as count FROM inscriptions i
                   JOIN classes c ON i.classe_id = c.id
                   JOIN filieres f ON c.filiere_id = f.id
                   WHERE f.departement_id = :dept_id AND i.statut IN ('inscrit', 'reinscrit')";
$etudiants_stmt = $conn->prepare($etudiants_query);
$etudiants_stmt->bindParam(':dept_id', $departement['id']);
$etudiants_stmt->execute();
$stats_dept['etudiants'] = $etudiants_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre d'enseignants
$enseignants_query = "SELECT COUNT(DISTINCT e.enseignant_id) as count FROM enseignements e
                     JOIN classes c ON e.classe_id = c.id
                     JOIN filieres f ON c.filiere_id = f.id
                     WHERE f.departement_id = :dept_id";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':dept_id', $departement['id']);
$enseignants_stmt->execute();
$stats_dept['enseignants'] = $enseignants_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Filières du département
$filieres_query = "SELECT f.*, COUNT(c.id) as nb_classes
                  FROM filieres f
                  LEFT JOIN classes c ON f.id = c.filiere_id
                  WHERE f.departement_id = :dept_id
                  GROUP BY f.id";
$filieres_stmt = $conn->prepare($filieres_query);
$filieres_stmt->bindParam(':dept_id', $departement['id']);
$filieres_stmt->execute();
$filieres = $filieres_stmt->fetchAll(PDO::FETCH_ASSOC);

// Feedbacks du département
$feedbacks_query = "SELECT f.*, u.name as user_name
                   FROM feedbacks f
                   JOIN users u ON f.user_id = u.id
                   WHERE f.type = 'departement'
                   ORDER BY f.date_envoi DESC LIMIT 10";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->execute();
$feedbacks = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Documents à valider
$docs_query = "SELECT d.*, u.name as user_name
              FROM documents d
              JOIN users u ON d.user_id = u.id
              JOIN inscriptions i ON u.id = i.user_id
              JOIN classes c ON i.classe_id = c.id
              JOIN filieres f ON c.filiere_id = f.id
              WHERE f.departement_id = :dept_id AND d.statut = 'en_attente'
              ORDER BY d.date_creation DESC LIMIT 10";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':dept_id', $departement['id']);
$docs_stmt->execute();
$docs_pending = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Responsable Département - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-teal-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-building text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Département</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Département: <?php echo htmlspecialchars($departement['nom']); ?></span>
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
                <a href="dashboard.php" class="text-teal-600 border-b-2 border-teal-600 pb-2">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="filieres.php" class="text-gray-600 hover:text-teal-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Filières
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-teal-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="documents_a_valider.php" class="text-gray-600 hover:text-teal-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-teal-600">
                    <i class="fas fa-comments mr-1"></i>Feedbacks
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Informations du département -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-building mr-2"></i>Département: <?php echo htmlspecialchars($departement['nom']); ?>
            </h2>
            <p class="text-gray-600">Responsable: <?php echo htmlspecialchars($user['name']); ?></p>
        </div>

        <!-- Statistiques du département -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-graduation-cap text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Filières</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_dept['filieres']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-school text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Classes</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_dept['classes']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-users text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Étudiants</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_dept['etudiants']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-chalkboard-teacher text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Enseignants</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats_dept['enseignants']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filières du département -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-graduation-cap mr-2"></i>Filières du département
            </h2>
            <?php if (empty($filieres)): ?>
                <p class="text-gray-600">Aucune filière dans ce département.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($filieres as $filiere): ?>
                    <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($filiere['nom']); ?></h3>
                        <p class="text-sm text-gray-600">Classes: <?php echo htmlspecialchars($filiere['nb_classes']); ?></p>
                        <?php
                        // Nombre d'étudiants par filière
                        $etuds_filiere_query = "SELECT COUNT(DISTINCT i.user_id) as count FROM inscriptions i
                                               JOIN classes c ON i.classe_id = c.id
                                               WHERE c.filiere_id = :filiere_id AND i.statut IN ('inscrit', 'reinscrit')";
                        $etuds_filiere_stmt = $conn->prepare($etuds_filiere_query);
                        $etuds_filiere_stmt->bindParam(':filiere_id', $filiere['id']);
                        $etuds_filiere_stmt->execute();
                        $nb_etudiants = $etuds_filiere_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                        ?>
                        <p class="text-sm text-blue-600">Étudiants: <?php echo $nb_etudiants; ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Documents à valider -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-alt mr-2"></i>Documents à valider
            </h2>
            <?php if (empty($docs_pending)): ?>
                <p class="text-gray-600">Aucun document en attente de validation.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($docs_pending as $doc): ?>
                    <div class="flex items-center justify-between p-4 bg-yellow-50 rounded-lg border-l-4 border-yellow-400">
                        <div>
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($doc['type_document']); ?></h4>
                            <p class="text-sm text-gray-600">Demandeur: <?php echo htmlspecialchars($doc['user_name']); ?> | Date: <?php echo htmlspecialchars($doc['date_creation']); ?></p>
                        </div>
                        <div class="flex space-x-2">
                            <button class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm">
                                <i class="fas fa-check mr-1"></i>Valider
                            </button>
                            <button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm">
                                <i class="fas fa-times mr-1"></i>Rejeter
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Feedbacks du département -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-comments mr-2"></i>Feedbacks récents
            </h2>
            <?php if (empty($feedbacks)): ?>
                <p class="text-gray-600">Aucun feedback reçu.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($feedbacks as $feedback): ?>
                    <div class="p-4 bg-blue-50 rounded-lg border-l-4 border-blue-400">
                        <p class="text-gray-800"><?php echo htmlspecialchars($feedback['message']); ?></p>
                        <p class="text-sm text-gray-600">De: <?php echo htmlspecialchars($feedback['user_name']); ?> | Type: <?php echo htmlspecialchars($feedback['type']); ?> | Date: <?php echo htmlspecialchars($feedback['date_envoi']); ?></p>
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