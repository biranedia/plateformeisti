<?php
/**
 * Gestion des feedbacks pour les responsables de classe
 * Permet de consulter les feedbacks des étudiants sur les cours et enseignants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('responsable_classe')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de classe pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération de la classe du responsable
$classe_query = "SELECT c.*, f.nom_filiere, d.nom_departement
                FROM classes c
                JOIN filieres f ON c.filiere_id = f.id
                JOIN departements d ON f.departement_id = d.id
                JOIN responsables_classe rc ON c.id = rc.classe_id
                WHERE rc.user_id = :user_id";
$classe_stmt = $conn->prepare($classe_query);
$classe_stmt->bindParam(':user_id', $user_id);
$classe_stmt->execute();
$classe = $classe_stmt->fetch(PDO::FETCH_ASSOC);

if (!$classe) {
    die("Erreur: Classe non trouvée pour ce responsable.");
}

// Récupération des feedbacks de la classe
$feedbacks_query = "SELECT f.*, u.name as nom_etudiant, u.matricule,
                          c.nom_cours, e.name as nom_enseignant,
                          f.note_pedagogie, f.note_contenu, f.note_materiel, f.note_globale,
                          f.commentaire_pedagogie, f.commentaire_contenu, f.commentaire_materiel, f.commentaire_general
                   FROM feedback_etudiants f
                   JOIN users u ON f.etudiant_id = u.id
                   JOIN inscriptions i ON u.id = i.etudiant_id
                   JOIN cours c ON f.cours_id = c.id
                   JOIN users e ON c.enseignant_id = e.id
                   WHERE i.classe_id = :classe_id
                   ORDER BY f.date_creation DESC";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->bindParam(':classe_id', $classe['id']);
$feedbacks_stmt->execute();
$feedbacks = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des feedbacks
$stats_query = "SELECT
    COUNT(*) as total_feedbacks,
    AVG(note_globale) as moyenne_globale,
    AVG(note_pedagogie) as moyenne_pedagogie,
    AVG(note_contenu) as moyenne_contenu,
    AVG(note_materiel) as moyenne_materiel,
    COUNT(DISTINCT cours_id) as cours_evalues,
    COUNT(DISTINCT etudiant_id) as etudiants_participants
FROM feedback_etudiants f
JOIN users u ON f.etudiant_id = u.id
JOIN inscriptions i ON u.id = i.etudiant_id
WHERE i.classe_id = :classe_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':classe_id', $classe['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Statistiques par cours
$cours_stats_query = "SELECT c.nom_cours, e.name as nom_enseignant,
                            COUNT(f.id) as nombre_feedbacks,
                            AVG(f.note_globale) as moyenne_globale,
                            AVG(f.note_pedagogie) as moyenne_pedagogie,
                            AVG(f.note_contenu) as moyenne_contenu,
                            AVG(f.note_materiel) as moyenne_materiel
                     FROM cours c
                     JOIN users e ON c.enseignant_id = e.id
                     LEFT JOIN feedback_etudiants f ON c.id = f.cours_id
                     JOIN inscriptions i ON f.etudiant_id = i.etudiant_id
                     WHERE i.classe_id = :classe_id
                     GROUP BY c.id, c.nom_cours, e.name
                     ORDER BY moyenne_globale DESC";
$cours_stats_stmt = $conn->prepare($cours_stats_query);
$cours_stats_stmt->bindParam(':classe_id', $classe['id']);
$cours_stats_stmt->execute();
$cours_stats = $cours_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrage
$filtre_cours = isset($_GET['cours']) ? (int)$_GET['cours'] : '';
$filtre_enseignant = isset($_GET['enseignant']) ? (int)$_GET['enseignant'] : '';
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';

if ($filtre_cours || $filtre_enseignant || $recherche) {
    $feedbacks = array_filter($feedbacks, function($feedback) use ($filtre_cours, $filtre_enseignant, $recherche) {
        $match_cours = !$filtre_cours || $feedback['cours_id'] == $filtre_cours;
        $match_enseignant = !$filtre_enseignant || $feedback['enseignant_id'] == $filtre_enseignant;
        $match_recherche = !$recherche ||
            stripos($feedback['nom_etudiant'], $recherche) !== false ||
            stripos($feedback['prenom_etudiant'], $recherche) !== false ||
            stripos($feedback['matricule'], $recherche) !== false ||
            stripos($feedback['nom_cours'], $recherche) !== false ||
            stripos($feedback['commentaire_general'], $recherche) !== false;
        return $match_cours && $match_enseignant && $match_recherche;
    });
}

// Récupération des cours pour le filtre
$cours_query = "SELECT DISTINCT c.id, c.nom_cours, e.name as nom_enseignant
               FROM cours c
               JOIN users e ON c.enseignant_id = e.id
               JOIN inscriptions i ON c.classe_id = i.classe_id
               WHERE i.classe_id = :classe_id
               ORDER BY c.nom_cours";
$cours_stmt = $conn->prepare($cours_query);
$cours_stmt->bindParam(':classe_id', $classe['id']);
$cours_stmt->execute();
$cours_list = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fonction pour afficher les étoiles
function afficherEtoiles($note) {
    $etoiles = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $note) {
            $etoiles .= '<i class="fas fa-star text-yellow-400"></i>';
        } else {
            $etoiles .= '<i class="far fa-star text-gray-300"></i>';
        }
    }
    return $etoiles;
}

// Fonction pour obtenir la couleur selon la note
function getNoteColor($note) {
    if ($note >= 4) return 'green';
    if ($note >= 3) return 'yellow';
    if ($note >= 2) return 'orange';
    return 'red';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Feedbacks - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-comments text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Classe</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Classe: <?php echo htmlspecialchars($classe['nom_classe']); ?> - <?php echo htmlspecialchars($classe['nom_filiere']); ?></span>
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Responsable'); ?></span>
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
                <a href="liste_etudiants.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-users mr-1"></i>Étudiants
                </a>
                <a href="documents_classes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="feedback.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="remontees.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-exclamation-triangle mr-1"></i>Remontées
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques générales -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des feedbacks
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-comments text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total feedbacks</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_feedbacks']; ?></p>
                    <p class="text-sm text-gray-600">évaluations</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-star text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Note globale</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['moyenne_globale'] ? number_format($stats['moyenne_globale'], 1) : 'N/A'; ?>/5</p>
                    <p class="text-sm text-gray-600">moyenne générale</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-graduation-cap text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Cours évalués</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['cours_evalues']; ?></p>
                    <p class="text-sm text-gray-600">cours différents</p>
                </div>

                <div class="text-center">
                    <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-users text-purple-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Étudiants actifs</h3>
                    <p class="text-2xl font-bold text-purple-600"><?php echo $stats['etudiants_participants']; ?></p>
                    <p class="text-sm text-gray-600">participants</p>
                </div>
            </div>
        </div>

        <!-- Statistiques par cours -->
        <?php if (!empty($cours_stats)): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-line mr-2"></i>Évaluation par cours
            </h2>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Cours & Enseignant
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Feedbacks
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Note globale
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Pédagogie
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Contenu
                            </th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Matériel
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($cours_stats as $cours): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($cours['nom_cours']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($cours['nom_enseignant']); ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <?php echo $cours['nombre_feedbacks']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center">
                                <div class="flex items-center justify-center">
                                    <span class="text-sm font-medium text-gray-900 mr-2">
                                        <?php echo $cours['moyenne_globale'] ? number_format($cours['moyenne_globale'], 1) : 'N/A'; ?>/5
                                    </span>
                                    <?php if ($cours['moyenne_globale']): ?>
                                        <div class="flex">
                                            <?php echo afficherEtoiles(round($cours['moyenne_globale'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <?php echo $cours['moyenne_pedagogie'] ? number_format($cours['moyenne_pedagogie'], 1) : 'N/A'; ?>/5
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <?php echo $cours['moyenne_contenu'] ? number_format($cours['moyenne_contenu'], 1) : 'N/A'; ?>/5
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                <?php echo $cours['moyenne_materiel'] ? number_format($cours['moyenne_materiel'], 1) : 'N/A'; ?>/5
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-wrap items-center gap-4">
                <div>
                    <label for="recherche" class="block text-sm font-medium text-gray-700 mb-1">
                        Rechercher
                    </label>
                    <input type="text" id="recherche" placeholder="Étudiant, cours, commentaire..."
                           class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="cours" class="block text-sm font-medium text-gray-700 mb-1">
                        Cours
                    </label>
                    <select id="cours"
                            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les cours</option>
                        <?php foreach ($cours_list as $cours): ?>
                        <option value="<?php echo $cours['id']; ?>">
                            <?php echo htmlspecialchars($cours['nom_cours']); ?> - <?php echo htmlspecialchars($cours['nom_enseignant']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <button onclick="appliquerFiltres()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                </div>
            </div>
        </div>

        <!-- Liste des feedbacks -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-comments mr-2"></i>Feedbacks détaillés
            </h2>

            <?php if (empty($feedbacks)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-comments text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun feedback trouvé</h3>
                    <p class="text-gray-500">Il n'y a pas de feedbacks correspondant aux critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($feedbacks as $feedback): ?>
                    <div class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition duration-200">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center">
                                        <i class="fas fa-user text-indigo-600"></i>
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">
                                        <?php echo htmlspecialchars($feedback['nom_etudiant']); ?>
                                        <span class="text-sm text-gray-500 font-normal">
                                            (<?php echo htmlspecialchars($feedback['matricule']); ?>)
                                        </span>
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        Cours: <?php echo htmlspecialchars($feedback['nom_cours']); ?> •
                                        Enseignant: <?php echo htmlspecialchars($feedback['nom_enseignant']); ?> •
                                        <?php echo date('d/m/Y à H:i', strtotime($feedback['date_creation'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="flex items-center mb-2">
                                    <span class="text-sm font-medium text-gray-700 mr-2">Note globale:</span>
                                    <div class="flex">
                                        <?php echo afficherEtoiles($feedback['note_globale']); ?>
                                    </div>
                                    <span class="ml-2 text-sm font-bold text-<?php echo getNoteColor($feedback['note_globale']); ?>-600">
                                        <?php echo $feedback['note_globale']; ?>/5
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Notes détaillées -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div class="bg-blue-50 p-3 rounded-md">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-blue-800">Pédagogie</span>
                                    <div class="flex">
                                        <?php echo afficherEtoiles($feedback['note_pedagogie']); ?>
                                    </div>
                                </div>
                                <p class="text-sm text-blue-700"><?php echo $feedback['note_pedagogie']; ?>/5</p>
                            </div>

                            <div class="bg-green-50 p-3 rounded-md">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-green-800">Contenu</span>
                                    <div class="flex">
                                        <?php echo afficherEtoiles($feedback['note_contenu']); ?>
                                    </div>
                                </div>
                                <p class="text-sm text-green-700"><?php echo $feedback['note_contenu']; ?>/5</p>
                            </div>

                            <div class="bg-purple-50 p-3 rounded-md">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-medium text-purple-800">Matériel</span>
                                    <div class="flex">
                                        <?php echo afficherEtoiles($feedback['note_materiel']); ?>
                                    </div>
                                </div>
                                <p class="text-sm text-purple-700"><?php echo $feedback['note_materiel']; ?>/5</p>
                            </div>
                        </div>

                        <!-- Commentaires -->
                        <?php if ($feedback['commentaire_general'] || $feedback['commentaire_pedagogie'] || $feedback['commentaire_contenu'] || $feedback['commentaire_materiel']): ?>
                        <div class="space-y-3">
                            <?php if ($feedback['commentaire_general']): ?>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-1">Commentaire général:</h4>
                                <p class="text-gray-700 bg-gray-50 p-3 rounded-md">
                                    <?php echo nl2br(htmlspecialchars($feedback['commentaire_general'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <?php if ($feedback['commentaire_pedagogie']): ?>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-1">Commentaire pédagogie:</h4>
                                <p class="text-gray-700 bg-blue-50 p-3 rounded-md">
                                    <?php echo nl2br(htmlspecialchars($feedback['commentaire_pedagogie'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <?php if ($feedback['commentaire_contenu']): ?>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-1">Commentaire contenu:</h4>
                                <p class="text-gray-700 bg-green-50 p-3 rounded-md">
                                    <?php echo nl2br(htmlspecialchars($feedback['commentaire_contenu'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <?php if ($feedback['commentaire_materiel']): ?>
                            <div>
                                <h4 class="font-medium text-gray-900 mb-1">Commentaire matériel:</h4>
                                <p class="text-gray-700 bg-purple-50 p-3 rounded-md">
                                    <?php echo nl2br(htmlspecialchars($feedback['commentaire_materiel'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations sur l'analyse des feedbacks -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Analyse des feedbacks
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Les feedbacks permettent d'identifier les points forts et les axes d'amélioration</li>
                            <li>Portez une attention particulière aux cours avec des notes faibles</li>
                            <li>Les commentaires qualitatifs sont souvent plus riches que les notes quantitatives</li>
                            <li>Utilisez ces données pour orienter les discussions avec les enseignants</li>
                            <li>Encouragez la participation des étudiants aux évaluations pour une meilleure représentativité</li>
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

    <script>
        function appliquerFiltres() {
            const recherche = document.getElementById('recherche').value;
            const cours = document.getElementById('cours').value;

            let url = window.location.pathname;
            const params = new URLSearchParams();

            if (recherche) params.append('recherche', recherche);
            if (cours) params.append('cours', cours);

            if (params.toString()) {
                url += '?' + params.toString();
            }

            window.location.href = url;
        }

        // Recherche en temps réel
        document.getElementById('recherche').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                appliquerFiltres();
            }
        });
    </script>
</body>
</html>