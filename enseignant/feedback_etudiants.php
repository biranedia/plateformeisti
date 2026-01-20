<?php
/**
 * Feedback des étudiants pour les enseignants
 * Permet de consulter les avis des étudiants sur les cours
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

// Récupération des feedbacks pour cet enseignant
$feedbacks_query = "SELECT f.*, e.matiere as nom_cours, u.name as etudiant_nom,
                           cl.nom_classe, fi.nom as nom_filiere
                    FROM feedback_etudiants f
                    JOIN enseignements e ON f.enseignement_id = e.id
                    JOIN users u ON f.etudiant_id = u.id
                    JOIN classes cl ON e.classe_id = cl.id
                    JOIN filieres fi ON cl.filiere_id = fi.id
                    WHERE f.enseignant_id = :enseignant_id
                    ORDER BY f.date_creation DESC";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->bindParam(':enseignant_id', $user_id);
$feedbacks_stmt->execute();
$feedbacks = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des feedbacks
$total_feedbacks = count($feedbacks);
$moyenne_cours = $total_feedbacks > 0 ? array_sum(array_column($feedbacks, 'note_cours')) / $total_feedbacks : 0;
$moyenne_enseignant = $total_feedbacks > 0 ? array_sum(array_column($feedbacks, 'note_enseignant')) / $total_feedbacks : 0;

// Grouper par cours
$feedbacks_par_cours = [];
foreach ($feedbacks as $feedback) {
    $enseignement_id = $feedback['enseignement_id'];
    if (!isset($feedbacks_par_cours[$enseignement_id])) {
        $feedbacks_par_cours[$enseignement_id] = [
            'nom_cours' => $feedback['nom_cours'],
            'feedbacks' => [],
            'moyenne_cours' => 0,
            'moyenne_enseignant' => 0,
            'total' => 0
        ];
    }
    $feedbacks_par_cours[$enseignement_id]['feedbacks'][] = $feedback;
    $feedbacks_par_cours[$enseignement_id]['total']++;
}

// Calculer les moyennes par cours
foreach ($feedbacks_par_cours as &$cours_data) {
    $cours_data['moyenne_cours'] = array_sum(array_column($cours_data['feedbacks'], 'note_cours')) / $cours_data['total'];
    $cours_data['moyenne_enseignant'] = array_sum(array_column($cours_data['feedbacks'], 'note_enseignant')) / $cours_data['total'];
}
unset($cours_data);

// Filtrage par cours
$cours_filter = isset($_GET['cours']) ? sanitize($_GET['cours']) : '';
if ($cours_filter) {
    $feedbacks = array_filter($feedbacks, function($f) use ($cours_filter) {
        return $f['enseignement_id'] == $cours_filter;
    });
}

// Récupération des cours de l'enseignant pour le filtre
$cours_enseignant_query = "SELECT DISTINCT e.id, e.matiere as nom_cours
                          FROM enseignements e
                          WHERE e.enseignant_id = :enseignant_id
                          ORDER BY e.matiere";
$cours_enseignant_stmt = $conn->prepare($cours_enseignant_query);
$cours_enseignant_stmt->bindParam(':enseignant_id', $user_id);
$cours_enseignant_stmt->execute();
$cours_enseignant = $cours_enseignant_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedback Étudiants - ISTI</title>
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
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Enseignant'); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="cours.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-book mr-1"></i>Cours
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="presence.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-user-check mr-1"></i>Présence
                </a>
                <a href="feedback_etudiants.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="ressources.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-folder-open mr-1"></i>Ressources
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques générales -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des feedbacks
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-comments text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total feedbacks</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $total_feedbacks; ?></p>
                    <p class="text-sm text-gray-600">avis reçus</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-star text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Note moyenne cours</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($moyenne_cours, 1); ?>/5</p>
                    <p class="text-sm text-gray-600">sur <?php echo $total_feedbacks; ?> avis</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-graduate text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Note moyenne enseignant</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo number_format($moyenne_enseignant, 1); ?>/5</p>
                    <p class="text-sm text-gray-600">sur <?php echo $total_feedbacks; ?> avis</p>
                </div>
            </div>
        </div>

        <!-- Filtre par cours -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-filter mr-2"></i>Filtrer par cours
            </h3>

            <form method="GET" class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-64">
                    <label for="cours" class="block text-sm font-medium text-gray-700 mb-2">
                        Sélectionner un cours
                    </label>
                    <select name="cours" id="cours"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Tous les cours</option>
                        <?php foreach ($cours_enseignant as $cours): ?>
                            <option value="<?php echo $cours['id']; ?>" <?php echo $cours_filter == $cours['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cours['nom_cours']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex space-x-2">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($cours_filter): ?>
                        <a href="feedback_etudiants.php" class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Effacer
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Feedbacks par cours -->
        <?php if (empty($feedbacks_par_cours)): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="text-center py-12">
                    <i class="fas fa-comments text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun feedback reçu</h3>
                    <p class="text-gray-500">Les étudiants n'ont pas encore donné leur avis sur vos cours.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="space-y-8">
                <?php foreach ($feedbacks_par_cours as $cours_id => $cours_data): ?>
                    <?php if (!$cours_filter || $cours_filter == $cours_id): ?>
                    <div class="bg-white rounded-lg shadow-md p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-xl font-bold text-gray-800">
                                <i class="fas fa-book mr-2"></i><?php echo htmlspecialchars($cours_data['nom_cours']); ?>
                            </h3>
                            <div class="flex items-center space-x-4 text-sm">
                                <div class="flex items-center">
                                    <span class="text-gray-600 mr-2">Cours:</span>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= round($cours_data['moyenne_cours']) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                    <?php endfor; ?>
                                    <span class="ml-1 font-semibold"><?php echo number_format($cours_data['moyenne_cours'], 1); ?>/5</span>
                                </div>
                                <div class="flex items-center">
                                    <span class="text-gray-600 mr-2">Enseignant:</span>
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <i class="fas fa-star <?php echo $i <= round($cours_data['moyenne_enseignant']) ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                    <?php endfor; ?>
                                    <span class="ml-1 font-semibold"><?php echo number_format($cours_data['moyenne_enseignant'], 1); ?>/5</span>
                                </div>
                                <span class="text-gray-600">(<?php echo $cours_data['total']; ?> avis)</span>
                            </div>
                        </div>

                        <div class="space-y-4">
                            <?php foreach ($cours_data['feedbacks'] as $feedback): ?>
                            <div class="border rounded-lg p-4 bg-gray-50">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex items-center space-x-3">
                                        <div class="bg-blue-100 rounded-full w-10 h-10 flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                        <div>
                                            <h4 class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($feedback['etudiant_nom'] . ' ' . $feedback['etudiant_prenom']); ?>
                                                <?php if ($feedback['anonyme']): ?>
                                                    <span class="text-xs text-gray-500 ml-2">(Anonyme)</span>
                                                <?php endif; ?>
                                            </h4>
                                            <p class="text-sm text-gray-600">
                                                <?php echo htmlspecialchars($feedback['nom_classe'] ?? 'Classe inconnue'); ?> -
                                                <?php echo htmlspecialchars($feedback['nom_filiere'] ?? 'Filière inconnue'); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($feedback['date_creation']))); ?>
                                    </span>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-3">
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">Note du cours:</span>
                                        <div class="flex items-center mt-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $feedback['note_cours'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="ml-2 text-sm font-semibold"><?php echo $feedback['note_cours']; ?>/5</span>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">Note de l'enseignant:</span>
                                        <div class="flex items-center mt-1">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <i class="fas fa-star <?php echo $i <= $feedback['note_enseignant'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                            <?php endfor; ?>
                                            <span class="ml-2 text-sm font-semibold"><?php echo $feedback['note_enseignant']; ?>/5</span>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($feedback['commentaire_cours'] || $feedback['commentaire_enseignant']): ?>
                                <div class="space-y-2">
                                    <?php if ($feedback['commentaire_cours']): ?>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">Commentaire sur le cours:</span>
                                        <p class="text-sm text-gray-600 mt-1 bg-white p-2 rounded"><?php echo htmlspecialchars($feedback['commentaire_cours']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($feedback['commentaire_enseignant']): ?>
                                    <div>
                                        <span class="text-sm font-medium text-gray-700">Commentaire sur l'enseignant:</span>
                                        <p class="text-sm text-gray-600 mt-1 bg-white p-2 rounded"><?php echo htmlspecialchars($feedback['commentaire_enseignant']); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Conseils pour améliorer les feedbacks -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-lightbulb text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Conseils pour améliorer vos cours
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Prenez en compte les commentaires constructifs pour améliorer vos méthodes pédagogiques</li>
                            <li>Les feedbacks anonymes permettent aux étudiants de s'exprimer librement</li>
                            <li>Une bonne communication et disponibilité améliore généralement les notes</li>
                            <li>Utilisez les ressources pédagogiques pour enrichir vos cours</li>
                            <li>En cas de difficultés, contactez votre responsable pédagogique</li>
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
