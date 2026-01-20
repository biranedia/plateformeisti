<?php
/**
 * Liste des étudiants pour les responsables de classe
 * Permet de consulter et gérer les étudiants de la classe
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

// Récupération des étudiants de la classe
$etudiants_query = "SELECT u.id, u.name, u.email, u.matricule, u.date_naissance, u.telephone,
                          i.date_inscription, i.statut,
                          COUNT(DISTINCT n.id) as nombre_notes,
                          AVG(CASE WHEN n.note IS NOT NULL THEN n.note END) as moyenne_generale,
                          SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) as presences,
                          COUNT(p.id) as total_cours
                   FROM users u
                   JOIN inscriptions i ON u.id = i.etudiant_id
                   LEFT JOIN notes n ON u.id = n.etudiant_id
                   LEFT JOIN presence p ON u.id = p.etudiant_id
                   WHERE i.classe_id = :classe_id
                   GROUP BY u.id, u.name, u.email, u.matricule, u.date_naissance, u.telephone,
                            i.date_inscription, i.statut
                   ORDER BY u.name";
$etudiants_stmt = $conn->prepare($etudiants_query);
$etudiants_stmt->bindParam(':classe_id', $classe['id']);
$etudiants_stmt->execute();
$etudiants = $etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques de la classe
$stats_query = "SELECT
    COUNT(*) as total_etudiants,
    COUNT(CASE WHEN i.statut = 'active' THEN 1 END) as actifs,
    COUNT(CASE WHEN i.statut = 'inactive' THEN 1 END) as inactifs,
    AVG(CASE WHEN n.note IS NOT NULL THEN n.note END) as moyenne_classe,
    SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(p.id), 0) as taux_presence
FROM inscriptions i
JOIN users u ON i.etudiant_id = u.id
LEFT JOIN notes n ON u.id = n.etudiant_id
LEFT JOIN presence p ON u.id = p.etudiant_id
WHERE i.classe_id = :classe_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':classe_id', $classe['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Filtrage
$filtre_statut = isset($_GET['statut']) ? sanitize($_GET['statut']) : '';
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';

if ($filtre_statut || $recherche) {
    $etudiants = array_filter($etudiants, function($etudiant) use ($filtre_statut, $recherche) {
        $match_statut = !$filtre_statut || $etudiant['statut'] === $filtre_statut;
        $match_recherche = !$recherche ||
            stripos($etudiant['nom'], $recherche) !== false ||
            stripos($etudiant['prenom'], $recherche) !== false ||
            stripos($etudiant['matricule'], $recherche) !== false ||
            stripos($etudiant['email'], $recherche) !== false;
        return $match_statut && $match_recherche;
    });
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=etudiants_' . $classe['nom_classe'] . '.csv');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    // En-têtes
    fputcsv($output, ['Matricule', 'Nom', 'Prénom', 'Email', 'Téléphone', 'Date de naissance', 'Date d\'inscription', 'Statut', 'Nombre de notes', 'Moyenne générale', 'Taux de présence']);

    // Données
    foreach ($etudiants as $etudiant) {
        $taux_presence = $etudiant['total_cours'] > 0 ? round(($etudiant['presences'] / $etudiant['total_cours']) * 100, 1) : 0;
        fputcsv($output, [
            $etudiant['matricule'],
            $etudiant['nom'],
            $etudiant['prenom'],
            $etudiant['email'],
            $etudiant['telephone'] ?? '',
            $etudiant['date_naissance'] ? date('d/m/Y', strtotime($etudiant['date_naissance'])) : '',
            date('d/m/Y', strtotime($etudiant['date_inscription'])),
            $etudiant['statut'],
            $etudiant['nombre_notes'],
            $etudiant['moyenne_generale'] ? number_format($etudiant['moyenne_generale'], 2) : 'N/A',
            $taux_presence . '%'
        ]);
    }

    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste Étudiants - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-users text-2xl mr-3"></i>
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
                <a href="liste_etudiants.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-users mr-1"></i>Étudiants
                </a>
                <a href="documents_classes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-indigo-600">
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
        <!-- Statistiques -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques de la classe
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_etudiants']; ?></p>
                    <p class="text-sm text-gray-600">étudiants</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-check text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Actifs</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['actifs']; ?></p>
                    <p class="text-sm text-gray-600">étudiants</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-chart-line text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Moyenne classe</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['moyenne_classe'] ? number_format($stats['moyenne_classe'], 1) : 'N/A'; ?>/20</p>
                    <p class="text-sm text-gray-600">moyenne générale</p>
                </div>

                <div class="text-center">
                    <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-percentage text-purple-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Présence</h3>
                    <p class="text-2xl font-bold text-purple-600"><?php echo $stats['taux_presence'] ? number_format($stats['taux_presence'], 1) : '0'; ?>%</p>
                    <p class="text-sm text-gray-600">taux moyen</p>
                </div>

                <div class="text-center">
                    <div class="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-times text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Inactifs</h3>
                    <p class="text-2xl font-bold text-red-600"><?php echo $stats['inactifs']; ?></p>
                    <p class="text-sm text-gray-600">étudiants</p>
                </div>
            </div>
        </div>

        <!-- Filtres et actions -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <label for="recherche" class="block text-sm font-medium text-gray-700 mb-1">
                            Rechercher
                        </label>
                        <input type="text" id="recherche" placeholder="Nom, prénom, matricule..."
                               class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                    </div>

                    <div>
                        <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">
                            Statut
                        </label>
                        <select id="statut"
                                class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Tous</option>
                            <option value="active">Actif</option>
                            <option value="inactive">Inactif</option>
                        </select>
                    </div>

                    <div class="flex items-end">
                        <button onclick="appliquerFiltres()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-search mr-2"></i>Filtrer
                        </button>
                    </div>
                </div>

                <div class="flex items-center space-x-2">
                    <a href="?export=csv" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-download mr-2"></i>Exporter CSV
                    </a>
                </div>
            </div>
        </div>

        <!-- Liste des étudiants -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-users mr-2"></i>Étudiants de la classe
            </h2>

            <?php if (empty($etudiants)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-users text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun étudiant trouvé</h3>
                    <p class="text-gray-500">Il n'y a pas d'étudiants correspondant aux critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Étudiant
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Contact
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Notes
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Présence
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($etudiants as $etudiant): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                                <i class="fas fa-user text-indigo-600"></i>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($etudiant['name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                Matricule: <?php echo htmlspecialchars($etudiant['matricule']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($etudiant['email']); ?></div>
                                    <?php if ($etudiant['telephone']): ?>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($etudiant['telephone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $etudiant['statut'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $etudiant['statut'] === 'active' ? 'Actif' : 'Inactif'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div><?php echo $etudiant['nombre_notes']; ?> notes</div>
                                    <div class="text-gray-500">
                                        Moy: <?php echo $etudiant['moyenne_generale'] ? number_format($etudiant['moyenne_generale'], 1) . '/20' : 'N/A'; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                    $taux_presence = $etudiant['total_cours'] > 0 ? round(($etudiant['presences'] / $etudiant['total_cours']) * 100, 1) : 0;
                                    ?>
                                    <div><?php echo $etudiant['presences']; ?>/<?php echo $etudiant['total_cours']; ?> cours</div>
                                    <div class="text-gray-500"><?php echo $taux_presence; ?>%</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button onclick="voirDetails(<?php echo $etudiant['id']; ?>)"
                                                class="text-indigo-600 hover:text-indigo-900 text-sm underline">
                                            <i class="fas fa-eye mr-1"></i>Détails
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations sur la gestion des étudiants -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Gestion des étudiants
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Surveillez régulièrement les moyennes et taux de présence de vos étudiants</li>
                            <li>Contactez les étudiants ayant des difficultés académiques</li>
                            <li>Utilisez l'export CSV pour analyser les données dans Excel</li>
                            <li>Les étudiants inactifs peuvent nécessiter un suivi particulier</li>
                            <li>Consultez les détails individuels pour un suivi personnalisé</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de détails étudiant -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-screen overflow-y-auto">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-user mr-2"></i>Détails de l'étudiant
                        </h3>
                        <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <div id="detailsContent">
                        <!-- Le contenu sera chargé dynamiquement -->
                    </div>
                </div>
            </div>
        </div>
    </div>

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
            const statut = document.getElementById('statut').value;

            let url = window.location.pathname;
            const params = new URLSearchParams();

            if (recherche) params.append('recherche', recherche);
            if (statut) params.append('statut', statut);

            if (params.toString()) {
                url += '?' + params.toString();
            }

            window.location.href = url;
        }

        function voirDetails(etudiantId) {
            // Simulation du chargement des détails (dans un vrai système, ce serait un appel AJAX)
            const detailsContent = document.getElementById('detailsContent');
            detailsContent.innerHTML = `
                <div class="animate-pulse">
                    <div class="h-4 bg-gray-200 rounded w-3/4 mb-4"></div>
                    <div class="h-4 bg-gray-200 rounded w-1/2 mb-4"></div>
                    <div class="h-4 bg-gray-200 rounded w-2/3"></div>
                </div>
            `;

            // Ici, vous feriez un appel AJAX pour récupérer les détails complets
            setTimeout(() => {
                detailsContent.innerHTML = `
                    <div class="text-center py-8">
                        <i class="fas fa-user text-gray-300 text-4xl mb-4"></i>
                        <p class="text-gray-600">Fonctionnalité en développement</p>
                        <p class="text-sm text-gray-500 mt-2">Les détails complets de l'étudiant seront disponibles prochainement.</p>
                    </div>
                `;
            }, 1000);

            document.getElementById('detailsModal').classList.remove('hidden');
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        // Fermer le modal en cliquant en dehors
        document.getElementById('detailsModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDetailsModal();
            }
        });

        // Recherche en temps réel
        document.getElementById('recherche').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                appliquerFiltres();
            }
        });
    </script>
</body>
</html>