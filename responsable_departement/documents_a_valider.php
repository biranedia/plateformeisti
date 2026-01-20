<?php
/**
 * Documents à valider - Responsable de département
 * Validation des documents soumis par les étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_dept')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de département pour accéder à cette page.', 'error');
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

// Récupération du département géré
$dept_query = "SELECT * FROM departements WHERE responsable_id = :user_id";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->bindParam(':user_id', $user_id);
$dept_stmt->execute();
$departement = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de département assigné
if (!$departement) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucun département assigné</h2>
            <p>Vous n'êtes pas encore assigné à un département. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Messages de succès ou d'erreur
$messages = [];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'validate_document') {
            $document_id = (int)$_POST['document_id'];
            $commentaire = sanitize($_POST['commentaire']);

            try {
                // Récupérer les informations du document
                $doc_query = "SELECT d.*, u.nom as user_nom, u.prenom as user_prenom, f.nom_filiere
                             FROM documents d
                             JOIN users u ON d.user_id = u.id
                             JOIN inscriptions i ON u.id = i.etudiant_id
                             JOIN classes c ON i.classe_id = c.id
                             JOIN filieres f ON c.filiere_id = f.id
                             WHERE d.id = :id AND f.departement_id = :dept_id";
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':id', $document_id);
                $doc_stmt->bindParam(':dept_id', $departement['id']);
                $doc_stmt->execute();
                $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$document) {
                    $messages[] = ['type' => 'error', 'text' => 'Document introuvable ou non autorisé.'];
                } else {
                    // Valider le document
                    $query = "UPDATE documents SET status = 'valide', validated_by = :validator_id,
                             validation_date = NOW(), commentaire_validation = :commentaire
                             WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':validator_id', $user_id);
                    $stmt->bindParam(':commentaire', $commentaire);
                    $stmt->bindParam(':id', $document_id);
                    $stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Document validé avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Validation du document: {$document['type_document']} - {$document['user_nom']} {$document['user_prenom']}", "documents");
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'reject_document') {
            $document_id = (int)$_POST['document_id'];
            $commentaire = sanitize($_POST['commentaire']);

            try {
                // Récupérer les informations du document
                $doc_query = "SELECT d.*, u.nom as user_nom, u.prenom as user_prenom, f.nom_filiere
                             FROM documents d
                             JOIN users u ON d.user_id = u.id
                             JOIN inscriptions i ON u.id = i.etudiant_id
                             JOIN classes c ON i.classe_id = c.id
                             JOIN filieres f ON c.filiere_id = f.id
                             WHERE d.id = :id AND f.departement_id = :dept_id";
                $doc_stmt = $conn->prepare($doc_query);
                $doc_stmt->bindParam(':id', $document_id);
                $doc_stmt->bindParam(':dept_id', $departement['id']);
                $doc_stmt->execute();
                $document = $doc_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$document) {
                    $messages[] = ['type' => 'error', 'text' => 'Document introuvable ou non autorisé.'];
                } else {
                    // Rejeter le document
                    $query = "UPDATE documents SET status = 'rejete', validated_by = :validator_id,
                             validation_date = NOW(), commentaire_validation = :commentaire
                             WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':validator_id', $user_id);
                    $stmt->bindParam(':commentaire', $commentaire);
                    $stmt->bindParam(':id', $document_id);
                    $stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Document rejeté.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Rejet du document: {$document['type_document']} - {$document['user_nom']} {$document['user_prenom']}", "documents");
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }

        if ($action === 'bulk_validate') {
            $document_ids = $_POST['document_ids'] ?? [];

            if (empty($document_ids)) {
                $messages[] = ['type' => 'error', 'text' => 'Aucun document sélectionné.'];
            } else {
                try {
                    $validated_count = 0;
                    foreach ($document_ids as $doc_id) {
                        $doc_id = (int)$doc_id;

                        // Vérifier que le document appartient au département
                        $check_query = "SELECT d.id FROM documents d
                                       JOIN users u ON d.user_id = u.id
                                       JOIN inscriptions i ON u.id = i.etudiant_id
                                       JOIN classes c ON i.classe_id = c.id
                                       JOIN filieres f ON c.filiere_id = f.id
                                       WHERE d.id = :id AND f.departement_id = :dept_id AND d.status = 'soumis'";
                        $check_stmt = $conn->prepare($check_query);
                        $check_stmt->bindParam(':id', $doc_id);
                        $check_stmt->bindParam(':dept_id', $departement['id']);
                        $check_stmt->execute();

                        if ($check_stmt->rowCount() > 0) {
                            $query = "UPDATE documents SET status = 'valide', validated_by = :validator_id,
                                     validation_date = NOW(), commentaire_validation = 'Validation en lot'
                                     WHERE id = :id";
                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':validator_id', $user_id);
                            $stmt->bindParam(':id', $doc_id);
                            $stmt->execute();
                            $validated_count++;
                        }
                    }

                    $messages[] = ['type' => 'success', 'text' => "$validated_count document(s) validé(s) avec succès."];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Validation en lot de $validated_count documents", "documents");

                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la validation en lot: ' . $e->getMessage()];
                }
            }
        }
    }
}

// Filtres
$status_filter = $_GET['status'] ?? 'all';
$type_filter = $_GET['type'] ?? 'all';
$search = $_GET['search'] ?? '';

// Construction de la requête avec filtres
$query = "SELECT d.*, u.nom as user_nom, u.prenom as user_prenom, u.email as user_email,
                 f.nom_filiere, c.nom_classe, a.nom_annee
          FROM documents d
          JOIN users u ON d.user_id = u.id
          JOIN inscriptions i ON u.id = i.etudiant_id
          JOIN classes c ON i.classe_id = c.id
          JOIN filieres f ON c.filiere_id = f.id
          JOIN annees_academiques a ON i.annee_academique_id = a.id
          WHERE f.departement_id = :dept_id";

$params = [':dept_id' => $departement['id']];

if ($status_filter !== 'all') {
    $query .= " AND d.status = :status";
    $params[':status'] = $status_filter;
}

if ($type_filter !== 'all') {
    $query .= " AND d.type_document = :type";
    $params[':type'] = $type_filter;
}

if (!empty($search)) {
    $query .= " AND (u.nom LIKE :search OR u.prenom LIKE :search OR u.email LIKE :search OR d.type_document LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY d.created_at DESC";

$documents_stmt = $conn->prepare($query);
$documents_stmt->execute($params);
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des documents
$stats_query = "SELECT
    COUNT(CASE WHEN status = 'soumis' THEN 1 END) as soumis,
    COUNT(CASE WHEN status = 'valide' THEN 1 END) as valides,
    COUNT(CASE WHEN status = 'rejete' THEN 1 END) as rejetes,
    COUNT(*) as total
    FROM documents d
    JOIN users u ON d.user_id = u.id
    JOIN inscriptions i ON u.id = i.etudiant_id
    JOIN classes c ON i.classe_id = c.id
    JOIN filieres f ON c.filiere_id = f.id
    WHERE f.departement_id = :dept_id";

$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':dept_id', $departement['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Types de documents disponibles
$document_types = [
    'certificat_scolarite' => 'Certificat de scolarité',
    'releve_notes' => 'Relevé de notes',
    'attestation_inscription' => 'Attestation d\'inscription',
    'diplome' => 'Diplôme',
    'carte_etudiant' => 'Carte étudiant',
    'autre' => 'Autre'
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents à valider - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-file-alt text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Département</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Département: <?php echo htmlspecialchars($departement['nom_departement']); ?></span>
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Resp. Dept'); ?></span>
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
                <a href="filieres.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Filières
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="documents_a_valider.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-file-alt mr-1"></i>Documents à valider
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback étudiants
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-8 bg-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
                        </div>
                        <div class="ml-3">
                            <p><?php echo htmlspecialchars($message['text']); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">En attente</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['soumis']; ?></p>
                        <p class="text-sm text-gray-600">documents à traiter</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Validés</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['valides']; ?></p>
                        <p class="text-sm text-gray-600">documents approuvés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Rejetés</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['rejetes']; ?></p>
                        <p class="text-sm text-gray-600">documents refusés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600">documents traités</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres et recherche -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-filter mr-2"></i>Documents à valider
                </h2>

                <!-- Actions en lot -->
                <?php if ($stats['soumis'] > 0): ?>
                    <form method="POST" id="bulkForm" class="flex space-x-2">
                        <input type="hidden" name="action" value="bulk_validate">
                        <button type="submit" onclick="return confirm('Valider tous les documents sélectionnés ?')"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-check-double mr-2"></i>Valider la sélection
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="status" class="block text-sm font-medium text-gray-700 mb-2">Statut</label>
                    <select id="status" name="status" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Tous les statuts</option>
                        <option value="soumis" <?php echo $status_filter === 'soumis' ? 'selected' : ''; ?>>En attente</option>
                        <option value="valide" <?php echo $status_filter === 'valide' ? 'selected' : ''; ?>>Validés</option>
                        <option value="rejete" <?php echo $status_filter === 'rejete' ? 'selected' : ''; ?>>Rejetés</option>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Type de document</label>
                    <select id="type" name="type" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $type_filter === 'all' ? 'selected' : ''; ?>>Tous les types</option>
                        <?php foreach ($document_types as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Nom, prénom, email..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <?php if ($status_filter !== 'all' || $type_filter !== 'all' || !empty($search)): ?>
                        <a href="documents_a_valider.php"
                           class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-times mr-2"></i>Réinitialiser
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Liste des documents -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (empty($documents)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-file-alt text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun document trouvé</h3>
                    <p class="text-gray-500">Il n'y a pas de documents correspondant à vos critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <?php if ($status_filter === 'soumis' || $status_filter === 'all'): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                    </th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Document</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($documents as $document): ?>
                                <tr class="hover:bg-gray-50">
                                    <?php if ($status_filter === 'soumis' || $status_filter === 'all'): ?>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($document['status'] === 'soumis'): ?>
                                                <input type="checkbox" name="document_ids[]" value="<?php echo $document['id']; ?>"
                                                       form="bulkForm" class="document-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-user text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($document['user_nom'] . ' ' . $document['user_prenom']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500">
                                                    <?php echo htmlspecialchars($document['user_email']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900">
                                            <?php echo htmlspecialchars($document_types[$document['type_document']] ?? $document['type_document']); ?>
                                        </div>
                                        <?php if ($document['commentaire']): ?>
                                            <div class="text-sm text-gray-500 truncate max-w-xs">
                                                <?php echo htmlspecialchars(substr($document['commentaire'], 0, 30)); ?>...
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($document['nom_classe']); ?><br>
                                        <span class="text-gray-500"><?php echo htmlspecialchars($document['nom_filiere']); ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                            <?php
                                            switch ($document['status']) {
                                                case 'soumis': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'valide': echo 'bg-green-100 text-green-800'; break;
                                                case 'rejete': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php
                                            switch ($document['status']) {
                                                case 'soumis': echo 'En attente'; break;
                                                case 'valide': echo 'Validé'; break;
                                                case 'rejete': echo 'Rejeté'; break;
                                                default: echo ucfirst($document['status']);
                                            }
                                            ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y H:i', strtotime($document['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <?php if ($document['fichier_path']): ?>
                                                <a href="<?php echo htmlspecialchars($document['fichier_path']); ?>" target="_blank"
                                                   class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($document['status'] === 'soumis'): ?>
                                                <button onclick="openValidationModal(<?php echo $document['id']; ?>, 'validate', '<?php echo addslashes($document['user_nom'] . ' ' . $document['user_prenom']); ?>', '<?php echo addslashes($document_types[$document['type_document']] ?? $document['type_document']); ?>')"
                                                        class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button onclick="openValidationModal(<?php echo $document['id']; ?>, 'reject', '<?php echo addslashes($document['user_nom'] . ' ' . $document['user_prenom']); ?>', '<?php echo addslashes($document_types[$document['type_document']] ?? $document['type_document']); ?>')"
                                                        class="text-red-600 hover:text-red-900">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>

                                            <?php if ($document['status'] !== 'soumis' && $document['commentaire_validation']): ?>
                                                <button onclick="showValidationDetails('<?php echo addslashes($document['commentaire_validation']); ?>', '<?php echo $document['validation_date'] ? date('d/m/Y H:i', strtotime($document['validation_date'])) : ''; ?>')"
                                                        class="text-gray-600 hover:text-gray-900">
                                                    <i class="fas fa-info-circle"></i>
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
    </main>

    <!-- Modal de validation -->
    <div id="validationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900" id="modalTitle">
                        <i class="fas fa-check mr-2"></i>Valider le document
                    </h3>
                    <button onclick="closeValidationModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="mb-4 p-3 bg-gray-50 rounded">
                    <p class="text-sm text-gray-600" id="modalInfo"></p>
                </div>

                <form method="POST" id="validationForm">
                    <input type="hidden" name="document_id" id="modalDocumentId">
                    <input type="hidden" name="action" id="modalAction">

                    <div class="mb-4">
                        <label for="modalCommentaire" class="block text-sm font-medium text-gray-700 mb-2">
                            Commentaire (optionnel)
                        </label>
                        <textarea id="modalCommentaire" name="commentaire" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Ajouter un commentaire..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeValidationModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit" id="modalSubmitBtn"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Confirmer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal détails validation -->
    <div id="detailsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-info-circle mr-2"></i>Détails de validation
                    </h3>
                    <button onclick="closeDetailsModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <div class="space-y-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Date de validation</label>
                        <p class="text-sm text-gray-900" id="detailsDate"></p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Commentaire</label>
                        <p class="text-sm text-gray-900" id="detailsComment"></p>
                    </div>
                </div>

                <div class="flex justify-end mt-6">
                    <button onclick="closeDetailsModal()"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        Fermer
                    </button>
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
        function openValidationModal(documentId, action, studentName, documentType) {
            document.getElementById('modalDocumentId').value = documentId;
            document.getElementById('modalAction').value = action === 'validate' ? 'validate_document' : 'reject_document';

            const title = action === 'validate' ? 'Valider le document' : 'Rejeter le document';
            const icon = action === 'validate' ? 'fas fa-check text-green-600' : 'fas fa-times text-red-600';
            const btnClass = action === 'validate' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700';

            document.getElementById('modalTitle').innerHTML = `<i class="${icon} mr-2"></i>${title}`;
            document.getElementById('modalInfo').textContent = `${documentType} - ${studentName}`;
            document.getElementById('modalSubmitBtn').className = `${btnClass} text-white font-medium py-2 px-4 rounded-md transition duration-200`;
            document.getElementById('modalSubmitBtn').textContent = action === 'validate' ? 'Valider' : 'Rejeter';

            document.getElementById('validationModal').classList.remove('hidden');
        }

        function closeValidationModal() {
            document.getElementById('validationModal').classList.add('hidden');
            document.getElementById('modalCommentaire').value = '';
        }

        function showValidationDetails(comment, date) {
            document.getElementById('detailsComment').textContent = comment || 'Aucun commentaire';
            document.getElementById('detailsDate').textContent = date || 'Date inconnue';
            document.getElementById('detailsModal').classList.remove('hidden');
        }

        function closeDetailsModal() {
            document.getElementById('detailsModal').classList.add('hidden');
        }

        // Sélection multiple
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.document-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    </script>
</body>
</html>