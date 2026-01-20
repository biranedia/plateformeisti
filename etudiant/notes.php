<?php
/**
 * Page des notes pour les étudiants
 * Affiche les notes par matière et calcul des moyennes
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
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des notes de l'étudiant
$notes_query = "SELECT n.*, e.matiere, e.volume_horaire, ens.name as enseignant_nom
               FROM notes n
               JOIN enseignements e ON n.enseignement_id = e.id
               JOIN users ens ON e.enseignant_id = ens.id
               WHERE n.etudiant_id = :user_id
               ORDER BY n.date_saisie DESC";
$notes_stmt = $conn->prepare($notes_query);
$notes_stmt->bindParam(':user_id', $user_id);
$notes_stmt->execute();
$notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcul des statistiques
$stats = [
    'total_notes' => count($notes),
    'moyenne_generale' => 0,
    'notes_par_matiere' => [],
    'dernieres_notes' => array_slice($notes, 0, 5)
];

if (!empty($notes)) {
    $total_points = 0;
    $total_coefficients = 0;

    foreach ($notes as $note) {
        $matiere = $note['matiere'];
        if (!isset($stats['notes_par_matiere'][$matiere])) {
            $stats['notes_par_matiere'][$matiere] = [
                'notes' => [],
                'moyenne' => 0,
                'enseignant' => $note['enseignant_nom']
            ];
        }
        $stats['notes_par_matiere'][$matiere]['notes'][] = $note;

        // Calcul de la moyenne pondérée (coefficient = volume horaire)
        $coefficient = $note['volume_horaire'] ?: 1;
        $total_points += $note['note'] * $coefficient;
        $total_coefficients += $coefficient;
    }

    $stats['moyenne_generale'] = $total_coefficients > 0 ? round($total_points / $total_coefficients, 2) : 0;

    // Calcul des moyennes par matière
    foreach ($stats['notes_par_matiere'] as $matiere => &$data) {
        $somme = 0;
        $count = 0;
        foreach ($data['notes'] as $note) {
            $somme += $note['note'];
            $count++;
        }
        $data['moyenne'] = $count > 0 ? round($somme / $count, 2) : 0;
    }
}

// Filtrage par matière
$filtre_matiere = isset($_GET['matiere']) ? $_GET['matiere'] : '';
$notes_filtrees = $notes;
if ($filtre_matiere) {
    $notes_filtrees = array_filter($notes, function($note) use ($filtre_matiere) {
        return $note['matiere'] === $filtre_matiere;
    });
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Notes - ISTI</title>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="profil.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user mr-1"></i>Profil
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
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
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques générales -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-calculator text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Moyenne générale</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['moyenne_generale']; ?>/20</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-list-ol text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Total des notes</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_notes']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-book text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Matières</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo count($stats['notes_par_matiere']); ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-filter mr-2"></i>Filtres
            </h2>
            <form method="GET" class="flex flex-wrap gap-4">
                <div>
                    <label for="matiere" class="block text-sm font-medium text-gray-700 mb-1">Matière</label>
                    <select name="matiere" id="matiere"
                            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Toutes les matières</option>
                        <?php foreach ($stats['notes_par_matiere'] as $matiere => $data): ?>
                            <option value="<?php echo htmlspecialchars($matiere); ?>" <?php echo $filtre_matiere === $matiere ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($matiere); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-end">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($filtre_matiere): ?>
                        <a href="notes.php" class="ml-2 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Effacer
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Notes par matière -->
        <?php if (!empty($stats['notes_par_matiere'])): ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <?php foreach ($stats['notes_par_matiere'] as $matiere => $data): ?>
                <?php if (empty($filtre_matiere) || $filtre_matiere === $matiere): ?>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex justify-between items-start mb-4">
                        <div>
                            <h3 class="text-lg font-bold text-gray-800"><?php echo htmlspecialchars($matiere); ?></h3>
                            <p class="text-sm text-gray-600">Enseignant: <?php echo htmlspecialchars($data['enseignant']); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-bold text-blue-600"><?php echo $data['moyenne']; ?>/20</span>
                            <p class="text-sm text-gray-600">Moyenne</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <?php foreach ($data['notes'] as $note): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                            <div>
                                <span class="font-medium text-gray-800"><?php echo htmlspecialchars($note['type_evaluation']); ?></span>
                                <span class="text-sm text-gray-600 ml-2"><?php echo htmlspecialchars($note['date_saisie']); ?></span>
                            </div>
                            <div class="flex items-center">
                                <span class="font-bold text-lg mr-2
                                    <?php echo $note['note'] >= 16 ? 'text-green-600' :
                                             ($note['note'] >= 14 ? 'text-blue-600' :
                                             ($note['note'] >= 12 ? 'text-yellow-600' :
                                             ($note['note'] >= 10 ? 'text-orange-600' : 'text-red-600'))); ?>">
                                    <?php echo htmlspecialchars($note['note']); ?>/20
                                </span>
                                <?php if ($note['commentaire']): ?>
                                    <span class="text-xs text-gray-500 ml-2" title="<?php echo htmlspecialchars($note['commentaire']); ?>">
                                        <i class="fas fa-comment"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Toutes les notes -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-table mr-2"></i>Toutes les notes
            </h2>

            <?php if (empty($notes_filtrees)): ?>
                <p class="text-gray-600">Aucune note disponible.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matière</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Note</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enseignant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Commentaire</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($notes_filtrees as $note): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['date_saisie']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['matiere']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['type_evaluation']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm font-medium
                                    <?php echo $note['note'] >= 16 ? 'text-green-600' :
                                             ($note['note'] >= 14 ? 'text-blue-600' :
                                             ($note['note'] >= 12 ? 'text-yellow-600' :
                                             ($note['note'] >= 10 ? 'text-orange-600' : 'text-red-600'))); ?>">
                                    <?php echo htmlspecialchars($note['note']); ?>/20
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['enseignant_nom']); ?></td>
                                <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($note['commentaire'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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