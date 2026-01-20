<?php
/**
 * Gestion des documents - Agent Administratif
 * Permet de gérer et valider les documents administratifs des étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

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

        if ($action === 'valider_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];

            $update_query = "UPDATE documents SET statut = 'valide' WHERE id = :document_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':document_id', $document_id);

            if ($update_stmt->execute()) {
                $message = "Document validé avec succès.";
                $message_type = "success";
            } else {
                $message = "Erreur lors de la validation du document.";
                $message_type = "error";
            }
        }

        if ($action === 'rejeter_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];

            $update_query = "UPDATE documents SET statut = 'rejete' WHERE id = :document_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':document_id', $document_id);

            if ($update_stmt->execute()) {
                $message = "Document rejeté.";
                $message_type = "success";
            } else {
                $message = "Erreur lors du rejet du document.";
                $message_type = "error";
            }
        }

        if ($action === 'telecharger_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];

            // Récupérer les informations du document
            $doc_query = "SELECT * FROM documents WHERE id = :document_id";
            $doc_stmt = $conn->prepare($doc_query);
            $doc_stmt->bindParam(':document_id', $document_id);
            $doc_stmt->execute();
            $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

            if ($document) {
                // Simulation du téléchargement (en production, servir le fichier réel)
                $fileName = $document['nom_fichier'] ?? basename($document['fichier_url']);
                $message = "Téléchargement du document simulé: " . $fileName;
                $message_type = "info";
            } else {
                $message = "Document non trouvé.";
                $message_type = "error";
            }
        }
    }
}

// Filtres
$statut_filter = isset($_GET['statut']) ? sanitize($_GET['statut']) : '';
$type_filter = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';

// Construction de la requête avec filtres
$query = "SELECT d.*, SUBSTRING_INDEX(d.fichier_url, '/', -1) AS nom_fichier,
                 u.name, u.matricule, u.email,
                 c.nom_classe, f.nom, dep.nom
          FROM documents d
          JOIN users u ON d.user_id = u.id
          LEFT JOIN inscriptions i ON u.id = i.user_id
          LEFT JOIN classes c ON i.classe_id = c.id
          LEFT JOIN filieres f ON c.filiere_id = f.id
          LEFT JOIN departements dep ON f.departement_id = dep.id
          WHERE 1=1";

$params = [];

if ($statut_filter) {
    $query .= " AND d.statut = :statut";
    $params[':statut'] = $statut_filter;
}

if ($type_filter) {
    $query .= " AND d.type_document = :type";
    $params[':type'] = $type_filter;
}

if ($recherche) {
    $query .= " AND (u.name LIKE :recherche OR u.matricule LIKE :recherche OR u.email LIKE :recherche OR d.fichier_url LIKE :recherche)";
    $params[':recherche'] = '%' . $recherche . '%';
}

$query .= " ORDER BY d.date_creation DESC LIMIT 100";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des documents
$stats_query = "SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'en_attente' THEN 1 END) as en_attente,
    COUNT(CASE WHEN statut = 'valide' THEN 1 END) as valides,
    COUNT(CASE WHEN statut = 'rejete' THEN 1 END) as rejetes,
    COUNT(CASE WHEN DATE(date_creation) = CURDATE() THEN 1 END) as aujourd_hui
FROM documents";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Types de documents disponibles
$types_documents = [
    'carte_identite' => 'Carte d\'identité',
    'diplome_bac' => 'Diplôme BAC',
    'releve_notes_bac' => 'Relevé de notes BAC',
    'certificat_medical' => 'Certificat médical',
    'photo_identite' => 'Photo d\'identité',
    'autre' => 'Autre'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Documents - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-file-alt text-2xl mr-3"></i>
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
                <a href="releve_notes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chart-bar mr-1"></i>Relevés
                </a>
                <a href="documents.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
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
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'error' ? 'red' : 'blue'); ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'error' ? 'red' : 'blue'); ?>-400 text-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'error' ? 'red' : 'blue'); ?>-700 px-4 py-3 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : ($message_type === 'error' ? 'exclamation' : 'info'); ?>-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total documents</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600">tous statuts</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">En attente</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['en_attente']; ?></p>
                        <p class="text-sm text-gray-600">à valider</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Validés</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['valides']; ?></p>
                        <p class="text-sm text-gray-600">approuvés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Rejetés</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['rejetes']; ?></p>
                        <p class="text-sm text-gray-600">refusés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-calendar-day text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Aujourd'hui</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats['aujourd_hui']; ?></p>
                        <p class="text-sm text-gray-600">téléchargés</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres et recherche -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-filter mr-2"></i>Filtres et recherche
            </h2>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-2">
                        Statut
                    </label>
                    <select id="statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="valide" <?php echo $statut_filter === 'valide' ? 'selected' : ''; ?>>Validé</option>
                        <option value="rejete" <?php echo $statut_filter === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-2">
                        Type de document
                    </label>
                    <select id="type" name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les types</option>
                        <?php foreach ($types_documents as $key => $value): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="recherche" class="block text-sm font-medium text-gray-700 mb-2">
                        Recherche
                    </label>
                    <input type="text" id="recherche" name="recherche" value="<?php echo htmlspecialchars($recherche); ?>"
                           placeholder="Nom, matricule, fichier..." class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-1"></i>Filtrer
                    </button>
                    <a href="documents.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-times mr-1"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des documents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list mr-2"></i>Documents des étudiants
            </h2>

            <?php if (empty($documents)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-alt text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun document</h3>
                    <p class="text-gray-500">Aucun document ne correspond aux critères de recherche.</p>
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
                                    Document
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date upload
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
                            <?php foreach ($documents as $document): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($document['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($document['matricule']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($document['nom_classe'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($document['nom_fichier']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo '-'; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($types_documents[$document['type_document']] ?? $document['type_document']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo date('d/m/Y H:i', strtotime($document['date_creation'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php
                                        switch ($document['statut']) {
                                            case 'en_attente': echo 'bg-yellow-100 text-yellow-800'; break;
                                            case 'valide': echo 'bg-green-100 text-green-800'; break;
                                            case 'rejete': echo 'bg-red-100 text-red-800'; break;
                                            default: echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php
                                        switch ($document['statut']) {
                                            case 'en_attente': echo 'En attente'; break;
                                            case 'valide': echo 'Validé'; break;
                                            case 'rejete': echo 'Rejeté'; break;
                                            default: echo ucfirst($document['statut']);
                                        }
                                        ?>
                                    </span>
                                    <div class="text-xs text-gray-500 mt-1">
                                        -
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <form method="POST" class="inline">
                                            <input type="hidden" name="action" value="telecharger_document">
                                            <input type="hidden" name="document_id" value="<?php echo $document['id']; ?>">
                                            <button type="submit" class="text-indigo-600 hover:text-indigo-900 text-sm underline">
                                                <i class="fas fa-download mr-1"></i>Voir
                                            </button>
                                        </form>

                                        <?php if ($document['statut'] === 'en_attente'): ?>
                                        <button onclick="validerDocument(<?php echo $document['id']; ?>, '<?php echo htmlspecialchars($document['nom_fichier']); ?>')"
                                                class="text-green-600 hover:text-green-900 text-sm underline ml-2">
                                            <i class="fas fa-check mr-1"></i>Valider
                                        </button>
                                        <button onclick="rejeterDocument(<?php echo $document['id']; ?>, '<?php echo htmlspecialchars($document['nom_fichier']); ?>')"
                                                class="text-red-600 hover:text-red-900 text-sm underline ml-2">
                                            <i class="fas fa-times mr-1"></i>Rejeter
                                        </button>
                                        <?php endif; ?>

                                        <?php if (!empty($document['commentaire_validation'] ?? '')): ?>
                                        <button onclick="voirCommentaire('<?php echo htmlspecialchars($document['commentaire_validation']); ?>')"
                                                class="text-blue-600 hover:text-blue-900 text-sm underline ml-2">
                                            <i class="fas fa-comment mr-1"></i>Commentaire
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

        <!-- Modal de validation -->
        <div id="validationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-check mr-2 text-green-500"></i>Valider le document
                            </h3>
                            <button onclick="closeValidationModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p class="text-gray-600 mb-4">
                            Document: <span id="documentNameValidation" class="font-medium"></span>
                        </p>

                        <form id="validationForm" method="POST">
                            <input type="hidden" name="action" value="valider_document">
                            <input type="hidden" id="documentIdValidation" name="document_id">

                            <div class="mb-4">
                                <label for="commentaire_validation" class="block text-sm font-medium text-gray-700 mb-2">
                                    Commentaire (optionnel)
                                </label>
                                <textarea id="commentaire_validation" name="commentaire" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                          placeholder="Ajouter un commentaire..."></textarea>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeValidationModal()"
                                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                    Annuler
                                </button>
                                <button type="submit"
                                        class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-check mr-2"></i>Valider le document
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de rejet -->
        <div id="rejetModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-times mr-2 text-red-500"></i>Rejeter le document
                            </h3>
                            <button onclick="closeRejetModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p class="text-gray-600 mb-4">
                            Document: <span id="documentNameRejet" class="font-medium"></span>
                        </p>

                        <form id="rejetForm" method="POST">
                            <input type="hidden" name="action" value="rejeter_document">
                            <input type="hidden" id="documentIdRejet" name="document_id">

                            <div class="mb-4">
                                <label for="commentaire_rejet" class="block text-sm font-medium text-gray-700 mb-2">
                                    Commentaire (obligatoire)
                                </label>
                                <textarea id="commentaire_rejet" name="commentaire" rows="3" required
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                          placeholder="Expliquer la raison du rejet..."></textarea>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <button type="button" onclick="closeRejetModal()"
                                        class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                    Annuler
                                </button>
                                <button type="submit"
                                        class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-times mr-2"></i>Rejeter le document
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal de commentaire -->
        <div id="commentaireModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
            <div class="flex items-center justify-center min-h-screen p-4">
                <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-lg font-bold text-gray-800">
                                <i class="fas fa-comment mr-2 text-blue-500"></i>Commentaire de validation
                            </h3>
                            <button onclick="closeCommentaireModal()" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times text-xl"></i>
                            </button>
                        </div>

                        <p id="commentaireContent" class="text-gray-600"></p>

                        <div class="flex justify-end mt-6">
                            <button onclick="closeCommentaireModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Fermer
                            </button>
                        </div>
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
        function validerDocument(documentId, documentName) {
            document.getElementById('documentIdValidation').value = documentId;
            document.getElementById('documentNameValidation').textContent = documentName;
            document.getElementById('validationModal').classList.remove('hidden');
        }

        function closeValidationModal() {
            document.getElementById('validationModal').classList.add('hidden');
            document.getElementById('commentaire_validation').value = '';
        }

        function rejeterDocument(documentId, documentName) {
            document.getElementById('documentIdRejet').value = documentId;
            document.getElementById('documentNameRejet').textContent = documentName;
            document.getElementById('rejetModal').classList.remove('hidden');
        }

        function closeRejetModal() {
            document.getElementById('rejetModal').classList.add('hidden');
            document.getElementById('commentaire_rejet').value = '';
        }

        function voirCommentaire(commentaire) {
            document.getElementById('commentaireContent').textContent = commentaire;
            document.getElementById('commentaireModal').classList.remove('hidden');
        }

        function closeCommentaireModal() {
            document.getElementById('commentaireModal').classList.add('hidden');
        }

        // Fermer les modals en cliquant en dehors
        document.getElementById('validationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeValidationModal();
            }
        });

        document.getElementById('rejetModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejetModal();
            }
        });

        document.getElementById('commentaireModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCommentaireModal();
            }
        });
    </script>
</body>
</html>