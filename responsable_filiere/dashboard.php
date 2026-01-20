<?php
/**
 * Dashboard du responsable de filière
 * Gestion des classes, inscriptions, enseignants, emploi du temps, etc.
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_filiere')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de filière pour accéder à cette page.', 'error');
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

// Récupération de la filière gérée
$filiere_query = "SELECT f.*, d.nom as departement_nom
                 FROM filieres f
                 JOIN departements d ON f.departement_id = d.id
                 WHERE f.responsable_id = :user_id";
$filiere_stmt = $conn->prepare($filiere_query);
$filiere_stmt->bindParam(':user_id', $user_id);
$filiere_stmt->execute();
$filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de filière assignée
if (!$filiere) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucune filière assignée</h2>
            <p>Vous n'êtes pas encore assigné à une filière. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Statistiques de la filière
$stats_filiere = [
    'classes' => 0,
    'etudiants' => 0,
    'enseignants' => 0,
    'inscriptions' => 0
];

// Nombre de classes
$classes_query = "SELECT COUNT(*) as count FROM classes WHERE filiere_id = :filiere_id";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere_id', $filiere['id']);
$classes_stmt->execute();
$stats_filiere['classes'] = $classes_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre d'étudiants
$etudiants_query = "SELECT COUNT(DISTINCT i.user_id) as count FROM inscriptions i
                   JOIN classes c ON i.classe_id = c.id
                   WHERE c.filiere_id = :filiere_id AND i.statut IN ('inscrit', 'reinscrit')";
$etudiants_stmt = $conn->prepare($etudiants_query);
$etudiants_stmt->bindParam(':filiere_id', $filiere['id']);
$etudiants_stmt->execute();
$stats_filiere['etudiants'] = $etudiants_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre d'enseignants
$enseignants_query = "SELECT COUNT(DISTINCT e.enseignant_id) as count FROM enseignements e
                     JOIN classes c ON e.classe_id = c.id
                     WHERE c.filiere_id = :filiere_id";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':filiere_id', $filiere['id']);
$enseignants_stmt->execute();
$stats_filiere['enseignants'] = $enseignants_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Nombre d'inscriptions totales
$inscriptions_query = "SELECT COUNT(*) as count FROM inscriptions i
                      JOIN classes c ON i.classe_id = c.id
                      WHERE c.filiere_id = :filiere_id";
$inscriptions_stmt = $conn->prepare($inscriptions_query);
$inscriptions_stmt->bindParam(':filiere_id', $filiere['id']);
$inscriptions_stmt->execute();
$stats_filiere['inscriptions'] = $inscriptions_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Classes de la filière
$classes_query = "SELECT c.*, COUNT(i.id) as nb_etudiants
                 FROM classes c
                 LEFT JOIN inscriptions i ON c.id = i.classe_id AND i.statut IN ('inscrit', 'reinscrit')
                 WHERE c.filiere_id = :filiere_id
                 GROUP BY c.id";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere_id', $filiere['id']);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Enseignants de la filière
$enseignants_query = "SELECT DISTINCT u.id, u.name, u.email, COUNT(e.id) as nb_cours
                     FROM users u
                     JOIN enseignements e ON u.id = e.enseignant_id
                     JOIN classes c ON e.classe_id = c.id
                     WHERE c.filiere_id = :filiere_id
                     GROUP BY u.id";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':filiere_id', $filiere['id']);
$enseignants_stmt->execute();
$enseignants_list = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Inscriptions récentes
$inscriptions_recent_query = "SELECT i.*, u.name as user_name, c.niveau
                             FROM inscriptions i
                             JOIN users u ON i.user_id = u.id
                             JOIN classes c ON i.classe_id = c.id
                             WHERE c.filiere_id = :filiere_id
                             ORDER BY i.id DESC LIMIT 10";
$inscriptions_recent_stmt = $conn->prepare($inscriptions_recent_query);
$inscriptions_recent_stmt->bindParam(':filiere_id', $filiere['id']);
$inscriptions_recent_stmt->execute();
$inscriptions_recent = $inscriptions_recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Demandes de documents
$docs_query = "SELECT d.*, u.name as user_name
              FROM documents d
              JOIN users u ON d.user_id = u.id
              JOIN inscriptions i ON u.id = i.user_id
              JOIN classes c ON i.classe_id = c.id
              WHERE c.filiere_id = :filiere_id
              ORDER BY d.date_creation DESC LIMIT 10";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':filiere_id', $filiere['id']);
$docs_stmt->execute();
$docs = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Responsable Filière - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-orange-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Filière</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Filière: <?php echo htmlspecialchars($filiere['nom']); ?></span>
                    <span class="text-sm">Département: <?php echo htmlspecialchars($filiere['departement_nom']); ?></span>
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
                <a href="dashboard.php" class="text-orange-600 border-b-2 border-orange-600 pb-2">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-school mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-chart-bar mr-1"></i>Notes
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Informations de la filière -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-graduation-cap mr-2"></i>Filière: <?php echo htmlspecialchars($filiere['nom']); ?>
            </h2>
            <p class="text-gray-600">Département: <?php echo htmlspecialchars($filiere['departement_nom']); ?> | Responsable: <?php echo htmlspecialchars($user['name']); ?></p>
        </div>

        <!-- Statistiques de la filière -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-school text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Classes</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_filiere['classes']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-users text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Étudiants</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_filiere['etudiants']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-chalkboard-teacher text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Enseignants</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_filiere['enseignants']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-user-plus text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Inscriptions</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats_filiere['inscriptions']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Classes de la filière -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-school mr-2"></i>Classes de la filière
            </h2>
            <?php if (empty($classes)): ?>
                <p class="text-gray-600">Aucune classe dans cette filière.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($classes as $classe): ?>
                    <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($classe['niveau']); ?></h3>
                        <p class="text-sm text-gray-600">Étudiants: <?php echo htmlspecialchars($classe['nb_etudiants']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Enseignants de la filière -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-chalkboard-teacher mr-2"></i>Enseignants de la filière
            </h2>
            <?php if (empty($enseignants_list)): ?>
                <p class="text-gray-600">Aucun enseignant assigné à cette filière.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cours</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($enseignants_list as $enseignant): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($enseignant['name']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($enseignant['email']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($enseignant['nb_cours']); ?> cours</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Inscriptions récentes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-user-plus mr-2"></i>Inscriptions récentes
            </h2>
            <?php if (empty($inscriptions_recent)): ?>
                <p class="text-gray-600">Aucune inscription récente.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($inscriptions_recent as $inscription): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['user_name']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['niveau']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['annee_academique']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm">
                                    <?php if ($inscription['statut'] == 'inscrit'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Inscrit</span>
                                    <?php elseif ($inscription['statut'] == 'reinscrit'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Réinscrit</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Abandon</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Demandes de documents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-alt mr-2"></i>Demandes de documents récentes
            </h2>
            <?php if (empty($docs)): ?>
                <p class="text-gray-600">Aucune demande de document.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($docs as $doc): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($doc['type_document']); ?></h4>
                            <p class="text-sm text-gray-600">Demandeur: <?php echo htmlspecialchars($doc['user_name']); ?> | Date: <?php echo htmlspecialchars($doc['date_creation']); ?></p>
                        </div>
                        <div class="text-right">
                            <?php if ($doc['statut'] == 'valide'): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Validé</span>
                            <?php elseif ($doc['statut'] == 'en_attente'): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">En attente</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Rejeté</span>
                            <?php endif; ?>
                        </div>
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