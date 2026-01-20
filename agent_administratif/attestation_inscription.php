<?php
/**
 * Génération d'attestations d'inscription - Agent Administratif
 * Permet de générer et gérer les attestations d'inscription des étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Import de Dompdf pour la génération de PDF
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

        if ($action === 'generer_attestation' && isset($_POST['inscription_id'])) {
            $inscription_id = (int)$_POST['inscription_id'];

            // Vérifier que l'inscription existe et est active
            $check_query = "SELECT i.*, u.name, u.matricule, u.date_naissance,
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
                // Insérer d'abord dans la table attestations_inscription (sans numéro)
                $insert_query = "INSERT INTO attestations_inscription
                               (inscription_id, date_emission, statut, genere_par)
                               VALUES (:inscription_id, NOW(), 'active', :genere_par)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':inscription_id', $inscription_id);
                $insert_stmt->bindParam(':genere_par', $user_id);

                if ($insert_stmt->execute()) {
                    $attestation_id = $conn->lastInsertId();
                    
                    // Générer un numéro d'attestation unique basé sur l'ID de l'attestation
                    $numero_attestation = 'ATT-' . date('Y') . '-' . str_pad($attestation_id, 6, '0', STR_PAD_LEFT);
                    
                    // Mettre à jour le numéro d'attestation
                    $update_query = "UPDATE attestations_inscription SET numero_attestation = :numero_attestation WHERE id = :attestation_id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bindParam(':numero_attestation', $numero_attestation);
                    $update_stmt->bindParam(':attestation_id', $attestation_id);
                    
                    if ($update_stmt->execute()) {
                        $message = "Attestation générée avec succès. Numéro: $numero_attestation";
                        $message_type = "success";

                        // Générer le PDF
                        try {
                            $pdf_path = genererAttestationPDF($inscription, $numero_attestation, $attestation_id);
                        } catch (Exception $e) {
                            $message = "Attestation créée mais erreur lors de la génération du PDF: " . $e->getMessage();
                            $message_type = "warning";
                        }
                    } else {
                        $message = "Erreur lors de la mise à jour du numéro d'attestation.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Erreur lors de la génération de l'attestation.";
                    $message_type = "error";
                }
            } else {
                $message = "Inscription non trouvée ou inactive.";
                $message_type = "error";
            }
        }

        if ($action === 'annuler_attestation' && isset($_POST['attestation_id'])) {
            $attestation_id = (int)$_POST['attestation_id'];

            $update_query = "UPDATE attestations_inscription SET statut = 'annulee', date_annulation = NOW()
                           WHERE id = :attestation_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':attestation_id', $attestation_id);

            if ($update_stmt->execute()) {
                $message = "Attestation annulée avec succès.";
                $message_type = "success";
            } else {
                $message = "Erreur lors de l'annulation de l'attestation.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des attestations récentes
$attestations_query = "SELECT ai.*, u.name, u.matricule, u.email,
                             c.nom_classe, f.nom, d.nom,
                             i.annee_academique
                      FROM attestations_inscription ai
                      JOIN inscriptions i ON ai.inscription_id = i.id
                      JOIN users u ON i.user_id = u.id
                      JOIN classes c ON i.classe_id = c.id
                      JOIN filieres f ON c.filiere_id = f.id
                      JOIN departements d ON f.departement_id = d.id
                      ORDER BY ai.date_emission DESC LIMIT 50";
$attestations_stmt = $conn->prepare($attestations_query);
$attestations_stmt->execute();
$attestations = $attestations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des attestations
$stats_query = "SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'active' THEN 1 END) as actives,
    COUNT(CASE WHEN statut = 'annulee' THEN 1 END) as annulees,
    COUNT(CASE WHEN DATE(date_emission) = CURDATE() THEN 1 END) as aujourd_hui
FROM attestations_inscription";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Recherche d'étudiants pour génération
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';
$etudiants = [];

if ($recherche) {
    $search_query = "SELECT i.id, u.name, u.matricule, u.email,
                           c.nom_classe, f.nom, d.nom,
                           i.date_inscription, i.annee_academique, i.statut
                    FROM inscriptions i
                    JOIN users u ON i.etudiant_id = u.id
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

// Fonction de génération PDF pour attestations
function genererAttestationPDF($inscription, $numero_attestation, $attestation_id) {
    // Créer le répertoire de stockage des PDF s'il n'existe pas
    $dir = __DIR__ . '/../documents/attestations';
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    // Générer le contenu HTML de l'attestation
    $date_naissance = date('d/m/Y', strtotime($inscription['date_naissance']));
    $date_inscription = date('d/m/Y', strtotime($inscription['date_inscription']));
    $date_emission = date('d/m/Y');

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body {
                font-family: Arial, sans-serif;
                margin: 40px;
                line-height: 1.6;
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
            }
            .header h1 {
                margin: 0;
                font-size: 20px;
                color: #003366;
            }
            .header p {
                margin: 5px 0;
                font-size: 12px;
                color: #666;
            }
            .content {
                margin: 40px 0;
                text-align: justify;
            }
            .info-box {
                margin: 20px 0;
                padding: 10px;
                border-left: 4px solid #003366;
                background-color: #f5f5f5;
            }
            .info-row {
                margin: 8px 0;
                font-size: 14px;
            }
            .label {
                font-weight: bold;
                display: inline-block;
                width: 150px;
            }
            .signature {
                margin-top: 40px;
                text-align: center;
                font-size: 12px;
            }
            .signature-line {
                display: inline-block;
                width: 150px;
                border-top: 1px solid #000;
                margin-top: 50px;
            }
            .numero {
                text-align: right;
                font-size: 11px;
                color: #999;
                margin-top: 20px;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>ATTESTATION D'INSCRIPTION</h1>
            <p>INSTITUT SUPÉRIEUR DE TECHNOLOGIE ET D'INFORMATIQUE (ISTI)</p>
            <p>Tunis, Tunisie</p>
        </div>

        <div class='content'>
            <p>Je soussigné(e), Directeur(rice) de l'Institut Supérieur de Technologie et d'Informatique, atteste que :</p>

            <div class='info-box'>
                <div class='info-row'>
                    <span class='label'>Nom et Prénom :</span>
                    <span>" . htmlspecialchars($inscription['name']) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Matricule :</span>
                    <span>" . htmlspecialchars($inscription['matricule']) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Date de Naissance :</span>
                    <span>$date_naissance</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Année Académique :</span>
                    <span>" . htmlspecialchars($inscription['annee_academique']) . "</span>
                </div>
            </div>

            <p>Est régulièrement inscrit(e) à l'Institut Supérieur de Technologie et d'Informatique pour l'année académique " . htmlspecialchars($inscription['annee_academique']) . ".</p>

            <div class='info-box'>
                <div class='info-row'>
                    <span class='label'>Classe :</span>
                    <span>" . htmlspecialchars($inscription['nom_classe']) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Filière :</span>
                    <span>" . htmlspecialchars($inscription['nom']) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Département :</span>
                    <span>" . htmlspecialchars($inscription['nom']) . "</span>
                </div>
                <div class='info-row'>
                    <span class='label'>Date d'Inscription :</span>
                    <span>$date_inscription</span>
                </div>
            </div>

            <p>Cette attestation est délivrée pour servir et valoir ce que de droit.</p>

            <p>Fait à Tunis, le $date_emission</p>
        </div>

        <div class='signature'>
            <p>Le Directeur / La Directrice</p>
            <div class='signature-line'></div>
        </div>

        <div class='numero'>
            <p>Attestation n° : $numero_attestation</p>
        </div>
    </body>
    </html>";

    // Générer le PDF
    $pdf_path = $dir . '/' . $numero_attestation . '.pdf';
    generatePdfFromHtml($html, $pdf_path);

    return $pdf_path;
}

// Fonction utilitaire pour générer un PDF à partir de HTML
function generatePdfFromHtml($html, $outputPath) {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('chroot', realpath(dirname(__FILE__)));

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    file_put_contents($outputPath, $dompdf->output());
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attestations d'Inscription - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-file-contract text-2xl mr-3"></i>
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
                <a href="attestation_inscription.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-file-contract mr-1"></i>Attestations
                </a>
                <a href="certificat_scolarite.php" class="text-gray-600 hover:text-indigo-600">
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
                        <i class="fas fa-file-contract text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total attestations</h3>
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
                        <h3 class="font-semibold text-gray-800">Actives</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['actives']; ?></p>
                        <p class="text-sm text-gray-600">attestations valides</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Annulées</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['annulees']; ?></p>
                        <p class="text-sm text-gray-600">attestations annulées</p>
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
                        <p class="text-sm text-gray-600">émises aujourd'hui</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recherche et génération -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-search mr-2"></i>Générer une attestation
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

        <!-- Liste des attestations récentes -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-history mr-2"></i>Attestations récentes
            </h2>

            <?php if (empty($attestations)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-contract text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune attestation</h3>
                    <p class="text-gray-500">Aucune attestation n'a encore été générée.</p>
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
                            <?php foreach ($attestations as $attestation): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($attestation['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($attestation['matricule']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        <?php echo htmlspecialchars($attestation['nom_classe']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($attestation['nom']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($attestation['numero_attestation']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d/m/Y', strtotime($attestation['date_emission'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $attestation['statut'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo $attestation['statut'] === 'active' ? 'Active' : 'Annulée'; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <?php if ($attestation['statut'] === 'active'): ?>
                                        <button onclick="telechargerAttestation(<?php echo $attestation['id']; ?>)"
                                                class="text-indigo-600 hover:text-indigo-900 text-sm underline">
                                            <i class="fas fa-download mr-1"></i>Télécharger
                                        </button>
                                        <button onclick="annulerAttestation(<?php echo $attestation['id']; ?>)"
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
                                <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Annuler l'attestation
                            </h3>
                            <button onclick="closeAnnulationModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p class="text-gray-600 mb-6">
                            Êtes-vous sûr de vouloir annuler cette attestation ? Cette action est irréversible.
                        </p>

                        <form id="annulationForm" method="POST">
                            <input type="hidden" name="action" value="annuler_attestation">
                            <input type="hidden" id="attestationIdAnnulation" name="attestation_id">

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
                            <input type="hidden" name="action" value="generer_attestation">
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

        function telechargerAttestation(attestationId) {
            // Créer un formulaire caché pour télécharger le PDF
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'download_attestation.php';
            form.style.display = 'none';

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'attestation_id';
            input.value = attestationId;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            document.body.removeChild(form);
        }

        function annulerAttestation(attestationId) {
            document.getElementById('attestationIdAnnulation').value = attestationId;
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