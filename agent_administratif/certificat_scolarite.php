<?php
/**
 * Génération de certificats de scolarité - Agent Administratif
 * Permet de générer et gérer les certificats de scolarité des étudiants
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

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'generer_certificat' && isset($_POST['inscription_id'])) {
            $inscription_id = (int)$_POST['inscription_id'];

                 // Vérifier que l'inscription existe et est active
                 $check_query = "SELECT i.id, i.user_id, i.classe_id, i.annee_academique, i.statut,
                              u.name, u.matricule, u.date_naissance,
                              c.nom_classe, f.nom, d.nom
                          FROM inscriptions i
                          JOIN users u ON i.user_id = u.id
                          JOIN classes c ON i.classe_id = c.id
                          JOIN filieres f ON c.filiere_id = f.id
                          JOIN departements d ON f.departement_id = d.id
                          WHERE i.id = :inscription_id AND i.statut = 'inscrit'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':inscription_id', $inscription_id);
            $check_stmt->execute();
            $inscription = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($inscription) {
                // Générer le numéro de certificat
                $numero_certificat = 'CERT-' . date('Y') . '-' . str_pad($inscription_id, 6, '0', STR_PAD_LEFT);

                // Insérer dans la table certificats_scolarite
                $insert_query = "INSERT INTO certificats_scolarite
                               (inscription_id, numero_certificat, date_emission, statut, genere_par)
                               VALUES (:inscription_id, :numero_certificat, NOW(), 'active', :genere_par)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':inscription_id', $inscription_id);
                $insert_stmt->bindParam(':numero_certificat', $numero_certificat);
                $insert_stmt->bindParam(':genere_par', $user_id);

                if ($insert_stmt->execute()) {
                    $certificat_id = $conn->lastInsertId();
                    $message = "Certificat de scolarité généré avec succès. Numéro: $numero_certificat";
                    $message_type = "success";

                    // Rendu du template (simulation PDF)
                    $template = getCertificatTemplate($conn);
                    $payload = [
                        'numero_certificat' => $numero_certificat,
                        'date_emission' => date('d/m/Y'),
                        'name' => $inscription['name'],
                        'matricule' => $inscription['matricule'],
                        'date_naissance' => date('d/m/Y', strtotime($inscription['date_naissance'])),
                        'nom_classe' => $inscription['nom_classe'],
                        'nom_filiere' => $inscription['nom'],
                        'nom_departement' => $inscription['nom'],
                        'annee_academique' => $inscription['annee_academique'],
                    ];
                    $rendered = renderTemplate($template, $payload);

                    // Sauvegarde HTML + PDF
                    $dir = __DIR__ . '/outputs/certificats';
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0775, true);
                    }
                    $html_path = $dir . '/' . $numero_certificat . '.html';
                    $pdf_path = $dir . '/' . $numero_certificat . '.pdf';
                    file_put_contents($html_path, $rendered);
                    generatePdfFromHtml($rendered, $pdf_path);
                } else {
                    $message = "Erreur lors de la génération du certificat.";
                    $message_type = "error";
                }
            } else {
                $message = "Inscription non trouvée ou inactive.";
                $message_type = "error";
            }
        }

        if ($action === 'annuler_certificat' && isset($_POST['certificat_id'])) {
            $certificat_id = (int)$_POST['certificat_id'];

            $update_query = "UPDATE certificats_scolarite SET statut = 'annule', date_annulation = NOW()
                           WHERE id = :certificat_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':certificat_id', $certificat_id);

            if ($update_stmt->execute()) {
                $message = "Certificat annulé avec succès.";
                $message_type = "success";
            } else {
                $message = "Erreur lors de l'annulation du certificat.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des certificats récents
$certificats_query = "SELECT cs.*, u.name, u.matricule, u.email,
                            c.nom_classe, f.nom, d.nom,
                               NULL as date_inscription, i.annee_academique
                     FROM certificats_scolarite cs
                     JOIN inscriptions i ON cs.inscription_id = i.id
                           JOIN users u ON i.user_id = u.id
                     JOIN classes c ON i.classe_id = c.id
                     JOIN filieres f ON c.filiere_id = f.id
                     JOIN departements d ON f.departement_id = d.id
                     ORDER BY cs.date_emission DESC LIMIT 50";
$certificats_stmt = $conn->prepare($certificats_query);
$certificats_stmt->execute();
$certificats = $certificats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des certificats
$stats_query = "SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'active' THEN 1 END) as actifs,
    COUNT(CASE WHEN statut = 'annule' THEN 1 END) as annules,
    COUNT(CASE WHEN DATE(date_emission) = CURDATE() THEN 1 END) as aujourd_hui
FROM certificats_scolarite";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Recherche d'étudiants pour génération
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';
$etudiants = [];

// Récupération du template actif pour certificat
function getCertificatTemplate(PDO $conn) {
    $stmt = $conn->prepare("SELECT content_html FROM document_templates WHERE type = 'certificat_scolarite' ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['content_html'])) {
        return $row['content_html'];
    }
    // Fallback simple
    return "<html><body><h1>Certificat de scolarité</h1><p>Étudiant: {{name}} ({{matricule}})</p><p>Classe: {{nom_classe}} — Filière: {{nom_filiere}}</p><p>Année académique: {{annee_academique}}</p><p>Délivré le {{date_emission}}</p></body></html>";
}

function renderTemplate($template, array $data) {
    $rendered = $template;
    foreach ($data as $key => $value) {
        $rendered = str_replace('{{' . $key . '}}', $value, $rendered);
    }
    return $rendered;
}

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

if ($recherche) {
    $search_query = "SELECT i.id, u.name, u.matricule, u.email,
                              c.nom_classe, f.nom, d.nom,
                              NULL as date_inscription, i.annee_academique, i.statut
                    FROM inscriptions i
                          JOIN users u ON i.user_id = u.id
                    JOIN classes c ON i.classe_id = c.id
                    JOIN filieres f ON c.filiere_id = f.id
                    JOIN departements d ON f.departement_id = d.id
                    WHERE i.statut = 'active' AND (
                        u.name LIKE :recherche OR
                        u.matricule LIKE :recherche OR
                        u.email LIKE :recherche
                    )
                    ORDER BY u.name LIMIT 20";
    $search_stmt = $conn->prepare($search_query);
    $search_stmt->bindValue(':recherche', '%' . $recherche . '%');
    $search_stmt->execute();
    $etudiants = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fonction de génération PDF (simulation)
function genererCertificatPDF($inscription, $numero_certificat) {
    // En production, utiliser une bibliothèque comme TCPDF, FPDF, etc.
    // Pour l'instant, on simule la génération

    $contenu = "
    CERTIFICAT DE SCOLARITE
    INSTITUT SUPERIEUR DE TECHNOLOGIE ET D'INFORMATIQUE

    Numero de certificat: $numero_certificat
    Date d'émission: " . date('d/m/Y') . "

    Je soussigné, Directeur de l'ISTI, certifie que :

    Nom et prénom: {$inscription['name']}
    Matricule: {$inscription['matricule']}
    Date de naissance: " . date('d/m/Y', strtotime($inscription['date_naissance'])) . "

    Est régulièrement inscrit(e) en qualité d'étudiant(e) à l'Institut Supérieur
    de Technologie et d'Informatique pour l'année académique {$inscription['annee_academique']}

    Classe: {$inscription['nom_classe']}
    Filière: {$inscription['nom']}
    Département: {$inscription['nom']}

    Date d'inscription: " . date('d/m/Y', strtotime($inscription['date_inscription'])) . "

    Ce certificat est délivré à la demande de l'intéressé(e) pour servir et valoir
    ce que de droit.

    Fait à Tunis, le " . date('d/m/Y') . "

    Le Directeur
    ";

    // En production, générer un vrai PDF et le sauvegarder
    // Pour l'instant, on affiche juste un message
    echo "<script>alert('Certificat PDF généré (simulation)');</script>";
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificats de Scolarité - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
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
                <a href="certificat_scolarite.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-graduation-cap mr-1"></i>Certificats
                </a>
                <a href="releve_notes.php" class="text-gray-600 hover:text-indigo-600">
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
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-graduation-cap text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total certificats</h3>
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
                        <p class="text-sm text-gray-600">certificats valides</p>
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
                        <p class="text-sm text-gray-600">certificats annulés</p>
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
                <i class="fas fa-search mr-2"></i>Générer un certificat de scolarité
            </h2>

            <div class="mb-4">
                <label for="recherche_etudiant" class="block text-sm font-medium text-gray-700 mb-2">
                    Rechercher un étudiant inscrit
                </label>
                <input type="text" id="recherche_etudiant" placeholder="Nom, prénom, matricule ou email..."
                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div id="resultats_recherche" class="space-y-2 max-h-60 overflow-y-auto">
                <!-- Les résultats de recherche apparaîtront ici -->
            </div>
        </div>

        <!-- Liste des certificats récents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-history mr-2"></i>Certificats de scolarité récents
            </h2>

            <?php if (empty($certificats)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-graduation-cap text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun certificat</h3>
                    <p class="text-gray-500">Aucun certificat de scolarité n'a encore été généré.</p>
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
                                    Classe
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Numéro
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date d'émission
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($certificats as $certificat): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($certificat['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($certificat['matricule']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo htmlspecialchars($certificat['nom_classe']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($certificat['nom']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($certificat['numero_certificat']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d/m/Y', strtotime($certificat['date_emission'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $certificat['statut'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $certificat['statut'] === 'active' ? 'Actif' : 'Annulé'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <?php if ($certificat['statut'] === 'active'): ?>
                                        <button onclick="telechargerCertificat(<?php echo $certificat['id']; ?>)"
                                                class="text-indigo-600 hover:text-indigo-900 text-sm underline">
                                            <i class="fas fa-download mr-1"></i>Télécharger
                                        </button>
                                        <button onclick="annulerCertificat(<?php echo $certificat['id']; ?>)"
                                                class="text-red-600 hover:text-red-900 text-sm underline ml-2">
                                            <i class="fas fa-times mr-1"></i>Annuler
                                        </button>
                                        <?php endif; ?>
                                    </div>
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
                                <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Annuler le certificat
                            </h3>
                            <button onclick="closeAnnulationModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p class="text-gray-600 mb-6">
                            Êtes-vous sûr de vouloir annuler ce certificat de scolarité ? Cette action est irréversible.
                        </p>

                        <form id="annulationForm" method="POST">
                            <input type="hidden" name="action" value="annuler_certificat">
                            <input type="hidden" id="certificatIdAnnulation" name="certificat_id">

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
        // Recherche d'étudiants
        let searchTimeout;
        document.getElementById('recherche_etudiant').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();

            if (query.length < 2) {
                document.getElementById('resultats_recherche').innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(() => {
                rechercherEtudiants(query);
            }, 300);
        });

        function rechercherEtudiants(query) {
            // Simulation de recherche (en production, faire un appel AJAX)
            const resultatsDiv = document.getElementById('resultats_recherche');

            // Simulation de résultats
            const resultatsSimules = [
                {id: 1, nom: 'Dupont', prenom: 'Jean', matricule: '20240001', classe: 'L1 Informatique'},
                {id: 2, nom: 'Martin', prenom: 'Marie', matricule: '20240002', classe: 'L1 Informatique'}
            ];

            let html = '';
            resultatsSimules.forEach(etudiant => {
                html += `
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                        <div>
                            <span class="font-medium">${etudiant.nom} ${etudiant.prenom}</span>
                            <span class="text-sm text-gray-500 ml-2">${etudiant.matricule} - ${etudiant.classe}</span>
                        </div>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="generer_certificat">
                            <input type="hidden" name="inscription_id" value="${etudiant.id}">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-1 px-3 rounded-md transition duration-200">
                                <i class="fas fa-plus mr-1"></i>Générer
                            </button>
                        </form>
                    </div>
                `;
            });

            resultatsDiv.innerHTML = html;
        }

        function telechargerCertificat(certificatId) {
            // Créer un formulaire caché pour télécharger le PDF
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_certificat.php';
            form.style.display = 'none';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'certificat_id';
            input.value = certificatId;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function annulerCertificat(certificatId) {
            document.getElementById('certificatIdAnnulation').value = certificatId;
            document.getElementById('annulationModal').classList.remove('hidden');
        }

        function closeAnnulationModal() {
            document.getElementById('annulationModal').classList.add('hidden');
        }

        // Fermer le modal en cliquant en dehors
        document.getElementById('annulationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeAnnulationModal();
            }
        });
    </script>
</body>
</html>