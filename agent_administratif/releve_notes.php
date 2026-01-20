<?php
/**
 * Génération de relevés de notes - Agent Administratif
 * Permet de générer et gérer les relevés de notes des étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('agent_admin')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'agent administratif pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

/**
 * Récupère le template de bulletin depuis la base de données
 */
function getBulletinTemplate($conn) {
    $query = "SELECT content_html FROM document_templates WHERE type = 'bulletin' ORDER BY id DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['content_html'] : null;
}

/**
 * Rendu du template avec les données
 */
function renderTemplate($template, $data) {
    $html = $template;
    
    // Remplacement des variables simples
    foreach ($data as $key => $value) {
        if (!is_array($value)) {
            $html = str_replace('{{' . $key . '}}', htmlspecialchars($value), $html);
        }
    }
    
    // Gestion des boucles {{#notes}}...{{/notes}}
    if (isset($data['notes']) && is_array($data['notes'])) {
        $pattern = '/\{\{#notes\}\}(.*?)\{\{\/notes\}\}/s';
        if (preg_match($pattern, $html, $matches)) {
            $loopTemplate = $matches[1];
            $loopHtml = '';
            foreach ($data['notes'] as $note) {
                $itemHtml = $loopTemplate;
                foreach ($note as $key => $value) {
                    $itemHtml = str_replace('{{' . $key . '}}', htmlspecialchars($value), $itemHtml);
                }
                $loopHtml .= $itemHtml;
            }
            $html = preg_replace($pattern, $loopHtml, $html);
        }
    }
    
    return $html;
}

/**
 * Génère un PDF à partir du HTML avec dompdf
 */
function generatePdfFromHtml($html, $outputPath) {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    file_put_contents($outputPath, $dompdf->output());
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'generer_releve' && isset($_POST['etudiant_id'], $_POST['annee_academique'])) {
            $etudiant_id = (int)$_POST['etudiant_id'];
            $annee_academique = sanitize($_POST['annee_academique']);

            // Vérifier que l'étudiant existe et a une inscription active
            $check_query = "SELECT u.id, u.name, u.matricule, i.classe_id, c.nom_classe, f.nom as nom_filiere, d.nom as nom_departement
                           FROM users u
                           JOIN inscriptions i ON u.id = i.user_id
                           JOIN classes c ON i.classe_id = c.id
                           JOIN filieres f ON c.filiere_id = f.id
                           JOIN departements d ON f.departement_id = d.id
                           WHERE u.id = :etudiant_id AND i.annee_academique = :annee AND i.statut = 'inscrit'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':etudiant_id', $etudiant_id);
            $check_stmt->bindParam(':annee', $annee_academique);
            $check_stmt->execute();
            $etudiant = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($etudiant) {
                // Récupérer les notes de l'étudiant avec les matières
                $notes_query = "SELECT e.matiere, n.note, n.type_evaluation
                               FROM notes n
                               JOIN enseignements e ON n.enseignement_id = e.id
                               WHERE n.etudiant_id = :etudiant_id
                               ORDER BY e.matiere, n.type_evaluation";
                $notes_stmt = $conn->prepare($notes_query);
                $notes_stmt->bindParam(':etudiant_id', $etudiant_id);
                $notes_stmt->execute();
                $notes_data = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Formater les notes pour le template
                $notes_formatted = [];
                foreach ($notes_data as $note) {
                    $notes_formatted[] = [
                        'matiere' => $note['matiere'],
                        'type' => ucfirst($note['type_evaluation']),
                        'note' => number_format($note['note'], 2)
                    ];
                }
                
                // Récupérer le template de bulletin
                $template = getBulletinTemplate($conn);
                if (!$template) {
                    $message = "Erreur: Template de bulletin introuvable. Veuillez contacter l'administrateur.";
                    $message_type = "error";
                } else {
                    // Préparer les données pour le template
                    $data = [
                        'name' => $etudiant['name'],
                        'matricule' => $etudiant['matricule'],
                        'nom_classe' => $etudiant['nom_classe'],
                        'nom_filiere' => $etudiant['nom_filiere'],
                        'annee_academique' => $annee_academique,
                        'notes' => $notes_formatted
                    ];
                    
                    // Rendu du template
                    $html = renderTemplate($template, $data);
                    
                    // Créer le dossier de sortie si nécessaire
                    $output_dir = __DIR__ . '/outputs/bulletins/';
                    if (!file_exists($output_dir)) {
                        mkdir($output_dir, 0777, true);
                    }
                    
                    // Générer les noms de fichiers
                    $filename_base = 'bulletin_' . $etudiant['matricule'] . '_' . str_replace('/', '-', $annee_academique) . '_' . date('YmdHis');
                    $html_path = $output_dir . $filename_base . '.html';
                    $pdf_path = $output_dir . $filename_base . '.pdf';
                    
                    // Sauvegarder le HTML
                    file_put_contents($html_path, $html);
                    
                    // Générer le PDF
                    generatePdfFromHtml($html, $pdf_path);
                    
                    $message = "Bulletin généré avec succès ! <a href='outputs/bulletins/$filename_base.pdf' target='_blank' class='underline'>Télécharger le PDF</a> | <a href='outputs/bulletins/$filename_base.html' target='_blank' class='underline'>Voir HTML</a>";
                    $message_type = "success";
                }
            } else {
                $message = "Étudiant non trouvé ou non inscrit pour cette année académique.";
                $message_type = "error";
            }
        }

        if ($action === 'annuler_releve' && isset($_POST['releve_id'])) {
            // Les bulletins ne sont pas stockés en base pour le moment
            $message = "Les bulletins de notes ne sont pas stockés en base de données et ne peuvent pas être annulés.";
            $message_type = "warning";
        }
    }
}

// Recherche d'étudiants pour génération de bulletins
$releves_query = "SELECT DISTINCT u.id, u.name, u.email, u.matricule,
                         c.nom_classe, f.nom as nom_filiere, d.nom as nom_departement,
                         i.annee_academique, COUNT(n.id) as nombre_notes
                  FROM users u
                  JOIN inscriptions i ON u.id = i.user_id AND i.statut = 'inscrit'
                  LEFT JOIN classes c ON i.classe_id = c.id
                  LEFT JOIN filieres f ON c.filiere_id = f.id
                  LEFT JOIN departements d ON f.departement_id = d.id
                  LEFT JOIN notes n ON u.id = n.etudiant_id
                  GROUP BY u.id, u.name, u.email, u.matricule, c.nom_classe, f.nom, d.nom, i.annee_academique
                  HAVING nombre_notes > 0
                  ORDER BY u.name LIMIT 50";
$releves_stmt = $conn->prepare($releves_query);
$releves_stmt->execute();
$releves = $releves_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des relevés (basées sur les étudiants ayant des notes)
$stats_query = "SELECT
    COUNT(DISTINCT u.id) as total,
    COUNT(DISTINCT CASE WHEN i.statut = 'inscrit' THEN u.id END) as actifs,
    0 as annules,
    COUNT(DISTINCT CASE WHEN DATE(u.created_at) = CURDATE() THEN u.id END) as aujourd_hui
FROM users u
JOIN inscriptions i ON u.id = i.user_id
LEFT JOIN notes n ON u.id = n.etudiant_id
WHERE n.id IS NOT NULL";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des années académiques disponibles
$annees_query = "SELECT DISTINCT annee_academique FROM inscriptions ORDER BY annee_academique DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees_academiques = $annees_stmt->fetchAll(PDO::FETCH_COLUMN);

// Recherche d'étudiants pour génération
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';
$etudiants = [];

if ($recherche) {
    $search_query = "SELECT DISTINCT u.id, u.name, u.matricule, u.email,
                           GROUP_CONCAT(DISTINCT i.annee_academique ORDER BY i.annee_academique DESC) as annees
                    FROM users u
                    JOIN inscriptions i ON u.id = i.user_id
                    WHERE i.statut = 'inscrit' AND (
                        u.name LIKE :recherche OR
                        u.matricule LIKE :recherche OR
                        u.email LIKE :recherche
                    )
                    GROUP BY u.id, u.name, u.matricule, u.email
                    ORDER BY u.name LIMIT 20";
    $search_stmt = $conn->prepare($search_query);
    $search_stmt->bindValue(':recherche', '%' . $recherche . '%');
    $search_stmt->execute();
    $etudiants = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relevés de Notes - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chart-bar text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Agent Administratif</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?></span>
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
                <a href="inscriptions.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="attestation_inscription.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-contract mr-1"></i>Attestations
                </a>
                <a href="certificat_scolarite.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Certificats
                </a>
                <a href="releve_notes.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-chart-bar mr-1"></i>Relevés
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="saisie_donnees.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-edit mr-1"></i>Saisie
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Message de succès/erreur -->
        <?php if (isset($message)): ?>
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-400 text-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-700 px-4 py-3 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : ($message_type === 'warning' ? 'exclamation-triangle' : 'exclamation'); ?>-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p><?php echo $message; ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-chart-bar text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total relevés</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600">toutes périodes</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Actifs</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['actifs']; ?></p>
                        <p class="text-sm text-gray-600">relevés valides</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Annulés</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['annules']; ?></p>
                        <p class="text-sm text-gray-600">relevés annulés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-calendar-day text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Aujourd'hui</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['aujourd_hui']; ?></p>
                        <p class="text-sm text-gray-600">émis aujourd'hui</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recherche et génération -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-search mr-2"></i>Générer un bulletin de notes
            </h2>

            <form method="POST" action="">
                <input type="hidden" name="action" value="generer_releve">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                    <div>
                        <label for="etudiant_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Sélectionner un étudiant
                        </label>
                        <select name="etudiant_id" id="etudiant_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Choisir un étudiant...</option>
                            <?php foreach ($releves as $releve): ?>
                                <option value="<?php echo $releve['id']; ?>">
                                    <?php echo htmlspecialchars($releve['name']) . ' (' . htmlspecialchars($releve['matricule']) . ')'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                            Année académique
                        </label>
                        <select name="annee_academique" id="annee_academique" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            <option value="">Sélectionner une année</option>
                            <?php foreach ($annees_academiques as $annee): ?>
                                <option value="<?php echo htmlspecialchars($annee); ?>"><?php echo htmlspecialchars($annee); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                        <i class="fas fa-file-pdf mr-2"></i>Générer le bulletin PDF
                    </button>
                </div>
            </form>
        </div>

        <!-- Liste des relevés récents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-history mr-2"></i>Étudiants avec notes disponibles
            </h2>

            <?php if (empty($releves)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-chart-bar text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune note saisie</h3>
                    <p class="text-gray-500">Aucune note n'a encore été saisie pour les étudiants.</p>
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
                                    Classe / Filière
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Année
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Nombre de notes
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($releves as $releve): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($releve['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($releve['matricule']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo htmlspecialchars($releve['nom_classe'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($releve['nom_filiere'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($releve['annee_academique']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo $releve['nombre_notes']; ?> note(s)
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>


        <!-- Modal d'annulation -->
        <div id="annulationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Annuler le relevé
                            </h3>
                            <button onclick="closeAnnulationModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p class="text-gray-600 mb-6">
                            Êtes-vous sûr de vouloir annuler ce relevé de notes ? Cette action est irréversible.
                        </p>

                        <form id="annulationForm" method="POST">
                            <input type="hidden" name="action" value="annuler_releve">
                            <input type="hidden" id="releveIdAnnulation" name="releve_id">

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeAnnulationModal()"
                                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                    Annuler
                                </button>
                                <button type="submit"
                                        class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-times mr-2"></i>Confirmer l'annulation
                                </button>
                            </div>
                        </form>
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
        // Aucun JavaScript nécessaire pour le moment
    </script>
</body>
</html>