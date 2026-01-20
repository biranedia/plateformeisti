<?php
/**
 * Validation des documents d'inscription - Agent Administratif
 * Permet de consulter et valider les documents fournis lors de l'inscription (BAC, relevés, etc.)
 */

session_start();

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

// Traitement des actions POST (validation/rejet de documents)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'valider_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];
            $commentaire = sanitize($_POST['commentaire'] ?? '');

            $update_query = "UPDATE documents_inscription 
                           SET statut = 'valide', 
                               commentaire_validation = :commentaire,
                               valide_par = :valide_par,
                               date_validation = NOW()
                           WHERE id = :document_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':document_id', $document_id);
            $update_stmt->bindParam(':commentaire', $commentaire);
            $update_stmt->bindParam(':valide_par', $user_id);

            if ($update_stmt->execute()) {
                $message = "Document validé avec succès.";
                $message_type = "success";
            } else {
                $message = "Erreur lors de la validation du document.";
                $message_type = "error";
            }
        } elseif ($action === 'rejeter_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];
            $raison = sanitize($_POST['raison'] ?? '');

            $update_query = "UPDATE documents_inscription 
                           SET statut = 'rejete', 
                               commentaire_validation = :raison,
                               valide_par = :valide_par,
                               date_validation = NOW()
                           WHERE id = :document_id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':document_id', $document_id);
            $update_stmt->bindParam(':raison', $raison);
            $update_stmt->bindParam(':valide_par', $user_id);

            if ($update_stmt->execute()) {
                $message = "Document rejeté.";
                $message_type = "success";
            } else {
                $message = "Erreur lors du rejet du document.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des statistiques
$stats_query = "SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'soumis' THEN 1 END) as en_attente,
    COUNT(CASE WHEN statut = 'valide' THEN 1 END) as valides,
    COUNT(CASE WHEN statut = 'rejete' THEN 1 END) as rejetes
FROM documents_inscription";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Filtrage
$filtre_statut = isset($_GET['statut']) ? sanitize($_GET['statut']) : 'soumis';
$filtre_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';

$query = "SELECT di.*, u.name, u.email, u.phone
         FROM documents_inscription di
         JOIN users u ON di.user_id = u.id
         WHERE 1=1";

if ($filtre_statut) {
    $query .= " AND di.statut = :statut";
}

if ($filtre_type) {
    $query .= " AND di.type_document = :type";
}

$query .= " ORDER BY di.date_upload DESC";

$documents_stmt = $conn->prepare($query);
if ($filtre_statut) {
    $documents_stmt->bindParam(':statut', $filtre_statut);
}
if ($filtre_type) {
    $documents_stmt->bindParam(':type', $filtre_type);
}
$documents_stmt->execute();
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération d'un document spécifique pour la prévisualisation
$document_detail = null;
if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $doc_id = intval($_GET['view']);
    $detail_query = "SELECT di.*, u.name, u.email, u.phone FROM documents_inscription di JOIN users u ON di.user_id = u.id WHERE di.id = :id";
    $detail_stmt = $conn->prepare($detail_query);
    $detail_stmt->bindParam(':id', $doc_id);
    $detail_stmt->execute();
    $document_detail = $detail_stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validation des Documents - ISTI</title>
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Validation des Documents</h1>
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
                <a href="validation_documents.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-check-circle mr-1"></i>Validation Docs
                </a>
                <a href="attestation_inscription.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-contract mr-1"></i>Attestations
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Message de succès/erreur -->
        <?php if (isset($message)): ?>
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : 'exclamation'; ?>-circle mr-2"></i>
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="text-gray-600 text-sm font-medium">Total</div>
                <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></div>
            </div>
            <div class="bg-yellow-50 rounded-lg shadow p-6 border-l-4 border-yellow-500">
                <div class="text-yellow-600 text-sm font-medium">En attente</div>
                <div class="mt-2 text-3xl font-bold text-yellow-900"><?php echo $stats['en_attente']; ?></div>
            </div>
            <div class="bg-green-50 rounded-lg shadow p-6 border-l-4 border-green-500">
                <div class="text-green-600 text-sm font-medium">Validés</div>
                <div class="mt-2 text-3xl font-bold text-green-900"><?php echo $stats['valides']; ?></div>
            </div>
            <div class="bg-red-50 rounded-lg shadow p-6 border-l-4 border-red-500">
                <div class="text-red-600 text-sm font-medium">Rejetés</div>
                <div class="mt-2 text-3xl font-bold text-red-900"><?php echo $stats['rejetes']; ?></div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow p-6 mb-8">
            <div class="flex space-x-4">
                <a href="?statut=soumis" class="px-4 py-2 rounded <?php echo $filtre_statut === 'soumis' ? 'bg-yellow-500 text-white' : 'bg-gray-200 text-gray-700'; ?> hover:bg-yellow-500 hover:text-white transition">
                    <i class="fas fa-clock mr-1"></i>En attente
                </a>
                <a href="?statut=valide" class="px-4 py-2 rounded <?php echo $filtre_statut === 'valide' ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-700'; ?> hover:bg-green-500 hover:text-white transition">
                    <i class="fas fa-check mr-1"></i>Validés
                </a>
                <a href="?statut=rejete" class="px-4 py-2 rounded <?php echo $filtre_statut === 'rejete' ? 'bg-red-500 text-white' : 'bg-gray-200 text-gray-700'; ?> hover:bg-red-500 hover:text-white transition">
                    <i class="fas fa-times mr-1"></i>Rejetés
                </a>
                <a href="?statut=" class="px-4 py-2 rounded <?php echo empty($filtre_statut) ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700'; ?> hover:bg-blue-500 hover:text-white transition">
                    <i class="fas fa-list mr-1"></i>Tous
                </a>
            </div>
        </div>

        <!-- Tableau des documents -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Étudiant</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Type de Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Date Upload</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500">
                                <i class="fas fa-inbox text-2xl mb-2 block"></i>
                                Aucun document à afficher
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($doc['name']); ?>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($doc['email']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php 
                                    $type_icons = [
                                        'releve_bac' => '<i class="fas fa-file-pdf text-red-500"></i> Relevé BAC',
                                        'diplome_bac' => '<i class="fas fa-certificate text-green-500"></i> Diplôme BAC'
                                    ];
                                    echo $type_icons[$doc['type_document']] ?? htmlspecialchars($doc['type_document']);
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('d/m/Y H:i', strtotime($doc['date_upload'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php 
                                    $status_classes = [
                                        'soumis' => 'bg-yellow-100 text-yellow-800',
                                        'valide' => 'bg-green-100 text-green-800',
                                        'rejete' => 'bg-red-100 text-red-800'
                                    ];
                                    $status_labels = [
                                        'soumis' => 'En attente',
                                        'valide' => 'Validé',
                                        'rejete' => 'Rejeté'
                                    ];
                                    $class = $status_classes[$doc['statut']] ?? 'bg-gray-100 text-gray-800';
                                    $label = $status_labels[$doc['statut']] ?? $doc['statut'];
                                    ?>
                                    <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $class; ?>">
                                        <?php echo $label; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                    <a href="?view=<?php echo $doc['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Prévisualiser">
                                        <i class="fas fa-eye"></i> Voir
                                    </a>
                                    <?php if ($doc['statut'] === 'soumis'): ?>
                                        <button onclick="openValidationModal(<?php echo $doc['id']; ?>, 'valider')" class="text-green-600 hover:text-green-900">
                                            <i class="fas fa-check"></i> Valider
                                        </button>
                                        <button onclick="openValidationModal(<?php echo $doc['id']; ?>, 'rejeter')" class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-times"></i> Rejeter
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Détail du document (Prévisualisation) -->
        <?php if ($document_detail): ?>
            <div class="mt-8 bg-white rounded-lg shadow p-6 border-l-4 border-indigo-500">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-file mr-2"></i>Détails du document
                    </h2>
                    <a href="validation_documents.php<?php echo $filtre_statut ? '?statut=' . urlencode($filtre_statut) : ''; ?>" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-times text-lg"></i>
                    </a>
                </div>

                <div class="grid grid-cols-2 gap-6 mb-6">
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Étudiant</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($document_detail['name']); ?></p>
                        <p class="text-xs text-gray-500"><?php echo htmlspecialchars($document_detail['email']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Téléphone</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($document_detail['phone'] ?? 'Non fourni'); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Type de document</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($document_detail['type_document']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Nom du fichier</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo htmlspecialchars($document_detail['nom_fichier']); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Date d'upload</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo date('d/m/Y à H:i', strtotime($document_detail['date_upload'])); ?></p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Taille du fichier</p>
                        <p class="mt-1 text-lg text-gray-900"><?php echo number_format($document_detail['taille_fichier'] / 1024, 2); ?> KB</p>
                    </div>
                    <div>
                        <p class="text-sm text-gray-600 font-medium">Statut</p>
                        <div class="mt-1">
                            <?php 
                            $status_classes = [
                                'soumis' => 'bg-yellow-100 text-yellow-800',
                                'valide' => 'bg-green-100 text-green-800',
                                'rejete' => 'bg-red-100 text-red-800'
                            ];
                            $status_labels = [
                                'soumis' => 'En attente de validation',
                                'valide' => 'Validé ✓',
                                'rejete' => 'Rejeté ✗'
                            ];
                            $status_icons = [
                                'soumis' => 'fas fa-hourglass-end',
                                'valide' => 'fas fa-check-circle',
                                'rejete' => 'fas fa-times-circle'
                            ];
                            $class = $status_classes[$document_detail['statut']] ?? 'bg-gray-100 text-gray-800';
                            $label = $status_labels[$document_detail['statut']] ?? $document_detail['statut'];
                            $icon = $status_icons[$document_detail['statut']] ?? 'fas fa-question';
                            ?>
                            <span class="px-3 py-1 rounded-full text-sm font-medium inline-flex items-center <?php echo $class; ?>">
                                <i class="<?php echo $icon; ?> mr-2"></i>
                                <?php echo $label; ?>
                            </span>
                        </div>
                    </div>
                    <?php if ($document_detail['date_validation']): ?>
                        <div>
                            <p class="text-sm text-gray-600 font-medium">Date de validation</p>
                            <p class="mt-1 text-lg text-gray-900"><?php echo date('d/m/Y à H:i', strtotime($document_detail['date_validation'])); ?></p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($document_detail['commentaire_validation']): ?>
                    <div class="mb-6 p-4 rounded-lg <?php echo $document_detail['statut'] === 'valide' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'; ?>">
                        <p class="text-sm font-medium <?php echo $document_detail['statut'] === 'valide' ? 'text-green-800' : 'text-red-800'; ?>">
                            <?php echo $document_detail['statut'] === 'valide' ? 'Commentaire de validation' : 'Raison du rejet'; ?>
                        </p>
                        <p class="text-sm mt-2 <?php echo $document_detail['statut'] === 'valide' ? 'text-green-700' : 'text-red-700'; ?>">
                            <?php echo nl2br(htmlspecialchars($document_detail['commentaire_validation'])); ?>
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Prévisualisation du fichier -->
                <?php 
                $file_path = '../' . $document_detail['chemin_fichier'];
                $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
                ?>
                
                <div class="bg-gray-50 rounded-lg p-6 mb-6">
                    <p class="text-sm font-medium text-gray-700 mb-4">Aperçu du document</p>
                    
                    <?php if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                        <!-- Prévisualisation d'image -->
                        <div class="max-h-96 overflow-auto bg-white rounded border border-gray-300">
                            <img src="../<?php echo htmlspecialchars($document_detail['chemin_fichier']); ?>" alt="Prévisualisation" class="w-full">
                        </div>
                    <?php elseif ($extension === 'pdf'): ?>
                        <!-- Prévisualisation PDF -->
                        <iframe src="../<?php echo htmlspecialchars($document_detail['chemin_fichier']); ?>#toolbar=0&navpanes=0" class="w-full border border-gray-300 rounded" style="height: 500px;"></iframe>
                    <?php else: ?>
                        <!-- Autres types de fichiers -->
                        <div class="bg-white border border-gray-300 rounded p-8 text-center">
                            <i class="fas fa-file text-4xl text-gray-400 mb-4"></i>
                            <p class="text-gray-600 mb-4">Prévisualisation non disponible pour ce type de fichier</p>
                            <a href="../<?php echo htmlspecialchars($document_detail['chemin_fichier']); ?>" target="_blank" class="inline-block bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                                <i class="fas fa-download mr-2"></i>Télécharger le fichier
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <?php if ($document_detail['statut'] === 'soumis'): ?>
                    <div class="flex gap-3">
                        <button onclick="openValidationModal(<?php echo $document_detail['id']; ?>, 'valider')" class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-md font-medium transition">
                            <i class="fas fa-check mr-2"></i>Valider ce document
                        </button>
                        <button onclick="openValidationModal(<?php echo $document_detail['id']; ?>, 'rejeter')" class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-md font-medium transition">
                            <i class="fas fa-times mr-2"></i>Rejeter ce document
                        </button>
                    </div>
                <?php endif; ?>

                <div class="mt-4 flex justify-between">
                    <a href="validation_documents.php<?php echo $filtre_statut ? '?statut=' . urlencode($filtre_statut) : ''; ?>" class="text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left mr-2"></i>Retour à la liste
                    </a>
                    <a href="../<?php echo htmlspecialchars($document_detail['chemin_fichier']); ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        <i class="fas fa-download mr-2"></i>Télécharger
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modal de Validation -->
    <div id="validationModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-lg p-6 max-w-sm">
            <h3 class="text-lg font-bold mb-4" id="modalTitle"></h3>
            <form method="post" class="space-y-4">
                <input type="hidden" name="document_id" id="documentId" value="">
                <input type="hidden" name="action" id="modalAction" value="">
                
                <div id="commentDiv" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Commentaire</label>
                    <textarea name="commentaire" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3" placeholder="Entrez vos commentaires..."></textarea>
                </div>
                
                <div id="raisonDiv" class="hidden">
                    <label class="block text-sm font-medium text-gray-700 mb-2">Raison du rejet</label>
                    <textarea name="raison" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3" placeholder="Expliquez pourquoi ce document est rejeté..." required></textarea>
                </div>

                <div class="flex space-x-3">
                    <button type="button" onclick="closeValidationModal()" class="flex-1 px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50">
                        Annuler
                    </button>
                    <button type="submit" class="flex-1 px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700">
                        Confirmer
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openValidationModal(documentId, actionType) {
            const modal = document.getElementById('validationModal');
            const docIdInput = document.getElementById('documentId');
            const actionInput = document.getElementById('modalAction');
            const modalTitle = document.getElementById('modalTitle');
            const commentDiv = document.getElementById('commentDiv');
            const raisonDiv = document.getElementById('raisonDiv');

            docIdInput.value = documentId;
            actionInput.value = actionType === 'valider' ? 'valider_document' : 'rejeter_document';
            
            if (actionType === 'valider') {
                modalTitle.textContent = 'Valider le document';
                commentDiv.classList.remove('hidden');
                raisonDiv.classList.add('hidden');
            } else {
                modalTitle.textContent = 'Rejeter le document';
                commentDiv.classList.add('hidden');
                raisonDiv.classList.remove('hidden');
            }

            modal.classList.remove('hidden');
        }

        function closeValidationModal() {
            document.getElementById('validationModal').classList.add('hidden');
        }

        // Fermer le modal en cliquant en dehors
        document.getElementById('validationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeValidationModal();
            }
        });
    </script>
</body>
</html>
