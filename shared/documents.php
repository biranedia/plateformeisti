<?php
/**
 * Gestion des documents partagés - ISTI Platform
 * Système de partage et gestion de documents
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification
if (!isLoggedIn()) {
    redirectWithMessage('login.php', 'Vous devez être connecté pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Variables
$documents = [];
$errors = [];
$success = '';
$page = (int)($_GET['page'] ?? 1);
$limit = 12;
$offset = ($page - 1) * $limit;

// Filtres
$filter_category = sanitize($_GET['category'] ?? 'all');
$filter_type = sanitize($_GET['type'] ?? 'all');
$filter_search = sanitize($_GET['search'] ?? '');

// Configuration des dossiers
$upload_dir = '../uploads/documents/';
$max_file_size = 10 * 1024 * 1024; // 10MB
$allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif'];

// Création du dossier d'upload s'il n'existe pas
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = sanitize($_POST['action'] ?? '');

    if ($action === 'upload_document') {
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $visibility = sanitize($_POST['visibility'] ?? 'private');
        $tags = sanitize($_POST['tags'] ?? '');

        // Validation
        if (empty($title) || empty($_FILES['document']['name'])) {
            $errors[] = 'Le titre et le fichier sont obligatoires.';
        } elseif ($_FILES['document']['size'] > $max_file_size) {
            $errors[] = 'Le fichier ne doit pas dépasser 10MB.';
        } else {
            $file_extension = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            if (!in_array($file_extension, $allowed_extensions)) {
                $errors[] = 'Type de fichier non autorisé. Extensions acceptées: ' . implode(', ', $allowed_extensions);
            } else {
                // Génération d'un nom de fichier unique
                $file_name = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9]/', '_', basename($_FILES['document']['name']));
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['document']['tmp_name'], $file_path)) {
                    try {
                        // Insertion dans la base de données
                        $query = "INSERT INTO documents (title, description, file_name, file_path, file_size, file_type, category, visibility, tags, uploaded_by, created_at)
                                 VALUES (:title, :description, :file_name, :file_path, :file_size, :file_type, :category, :visibility, :tags, :uploaded_by, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':title', $title);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':file_name', $file_name);
                        $stmt->bindParam(':file_path', $file_path);
                        $stmt->bindParam(':file_size', $_FILES['document']['size']);
                        $stmt->bindParam(':file_type', $file_extension);
                        $stmt->bindParam(':category', $category);
                        $stmt->bindParam(':visibility', $visibility);
                        $stmt->bindParam(':tags', $tags);
                        $stmt->bindParam(':uploaded_by', $user_id);
                        $stmt->execute();

                        $success = 'Document téléchargé avec succès.';

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Document téléchargé: $title", "documents");

                        // Création d'une notification pour les utilisateurs autorisés
                        if ($visibility === 'public') {
                            createNotification($conn, 'system', 'Nouveau document disponible', "Un nouveau document '$title' a été ajouté.", null, null, $user_id);
                        }

                    } catch (Exception $e) {
                        // Supprimer le fichier en cas d'erreur
                        if (file_exists($file_path)) {
                            unlink($file_path);
                        }
                        $errors[] = 'Erreur lors de l\'enregistrement: ' . $e->getMessage();
                    }
                } else {
                    $errors[] = 'Erreur lors du téléchargement du fichier.';
                }
            }
        }
    }

    if ($action === 'delete_document') {
        $document_id = (int)($_POST['document_id'] ?? 0);

        try {
            // Vérifier que l'utilisateur peut supprimer ce document
            $check_query = "SELECT * FROM documents WHERE id = :id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':id', $document_id);
            $check_stmt->execute();
            $document = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                $errors[] = 'Document introuvable.';
            } elseif ($document['uploaded_by'] != $user_id && !hasRole('admin_general')) {
                $errors[] = 'Vous n\'êtes pas autorisé à supprimer ce document.';
            } else {
                // Supprimer le fichier physique
                if (file_exists($document['file_path'])) {
                    unlink($document['file_path']);
                }

                // Supprimer de la base de données
                $delete_query = "DELETE FROM documents WHERE id = :id";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bindParam(':id', $document_id);
                $delete_stmt->execute();

                $success = 'Document supprimé avec succès.';

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Document supprimé: " . $document['title'], "documents");
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la suppression: ' . $e->getMessage();
        }
    }

    if ($action === 'update_document') {
        $document_id = (int)($_POST['document_id'] ?? 0);
        $title = sanitize($_POST['title'] ?? '');
        $description = sanitize($_POST['description'] ?? '');
        $category = sanitize($_POST['category'] ?? '');
        $visibility = sanitize($_POST['visibility'] ?? 'private');
        $tags = sanitize($_POST['tags'] ?? '');

        try {
            // Vérifier que l'utilisateur peut modifier ce document
            $check_query = "SELECT * FROM documents WHERE id = :id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':id', $document_id);
            $check_stmt->execute();
            $document = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$document) {
                $errors[] = 'Document introuvable.';
            } elseif ($document['uploaded_by'] != $user_id && !hasRole('admin_general')) {
                $errors[] = 'Vous n\'êtes pas autorisé à modifier ce document.';
            } else {
                // Mise à jour
                $update_query = "UPDATE documents SET title = :title, description = :description,
                               category = :category, visibility = :visibility, tags = :tags,
                               updated_at = NOW() WHERE id = :id";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bindParam(':title', $title);
                $update_stmt->bindParam(':description', $description);
                $update_stmt->bindParam(':category', $category);
                $update_stmt->bindParam(':visibility', $visibility);
                $update_stmt->bindParam(':tags', $tags);
                $update_stmt->bindParam(':id', $document_id);
                $update_stmt->execute();

                $success = 'Document modifié avec succès.';

                // Ajout dans le journal d'audit
                addAuditLog($conn, $user_id, "Document modifié: $title", "documents");
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la modification: ' . $e->getMessage();
        }
    }
}

// Construction de la requête avec filtres
$query = "SELECT d.*, u.name as uploader_name
          FROM documents d
          JOIN users u ON d.uploaded_by = u.id
          WHERE 1=1";

$params = [];

// Filtres de visibilité selon le rôle
if ($user_role === 'admin_general') {
    // Les admins voient tout
} elseif ($user_role === 'agent_administratif') {
    $query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    $params[':user'] = $user_id;
} else {
    $query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    $params[':user'] = $user_id;
}

if ($filter_category !== 'all') {
    $query .= " AND d.category = :category";
    $params[':category'] = $filter_category;
}

if ($filter_type !== 'all') {
    $query .= " AND d.file_type = :type";
    $params[':type'] = $filter_type;
}

if (!empty($filter_search)) {
    $query .= " AND (d.title LIKE :search OR d.description LIKE :search OR d.tags LIKE :search)";
    $params[':search'] = '%' . $filter_search . '%';
}

$query .= " ORDER BY d.created_at DESC LIMIT :limit OFFSET :offset";
$params[':limit'] = $limit;
$params[':offset'] = $offset;

// Récupération des documents
try {
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des documents.';
}

// Comptage total pour la pagination
$count_query = str_replace("SELECT d.*, u.name as uploader_name FROM documents d", "SELECT COUNT(*) as total FROM documents d", $query);
$count_query = preg_replace('/ORDER BY.*$/', '', $count_query);
$count_query = preg_replace('/LIMIT.*$/', '', $count_query);

try {
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($params);
    $total_documents = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (Exception $e) {
    $total_documents = 0;
}

$total_pages = ceil($total_documents / $limit);

// Statistiques des documents
$stats = [
    'total' => 0,
    'my_documents' => 0,
    'total_size' => 0,
    'by_category' => []
];

try {
    // Statistiques générales
    $stats_query = "SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN uploaded_by = :user THEN 1 ELSE 0 END) as my_documents,
                    SUM(file_size) as total_size
                    FROM documents d WHERE 1=1";

    $stats_params = [':user' => $user_id];

    // Appliquer les mêmes filtres de visibilité
    if ($user_role === 'agent_administratif') {
        $stats_query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    } else {
        $stats_query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    }

    $stats_stmt = $conn->prepare($stats_query);
    $stats_stmt->execute($stats_params);
    $stats_result = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    $stats['total'] = $stats_result['total'] ?? 0;
    $stats['my_documents'] = $stats_result['my_documents'] ?? 0;
    $stats['total_size'] = $stats_result['total_size'] ?? 0;

    // Statistiques par catégorie
    $cat_query = "SELECT category, COUNT(*) as count FROM documents d
                  WHERE 1=1";

    if ($user_role === 'agent_administratif') {
        $cat_query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    } else {
        $cat_query .= " AND (d.visibility = 'public' OR d.uploaded_by = :user)";
    }

    $cat_query .= " GROUP BY category ORDER BY count DESC";

    $cat_stmt = $conn->prepare($cat_query);
    $cat_stmt->bindParam(':user', $user_id);
    $cat_stmt->execute();
    $stats['by_category'] = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Valeurs par défaut en cas d'erreur
    $stats = ['total' => 0, 'my_documents' => 0, 'total_size' => 0, 'by_category' => []];
}

// Catégories de documents
$document_categories = [
    'cours' => 'Cours',
    'examen' => 'Examens',
    'administratif' => 'Administratif',
    'ressources' => 'Ressources pédagogiques',
    'divers' => 'Divers'
];

// Types de fichiers
$file_types = [
    'pdf' => 'PDF',
    'doc' => 'Word',
    'docx' => 'Word',
    'xls' => 'Excel',
    'xlsx' => 'Excel',
    'ppt' => 'PowerPoint',
    'pptx' => 'PowerPoint',
    'txt' => 'Texte',
    'jpg' => 'Image',
    'jpeg' => 'Image',
    'png' => 'Image',
    'gif' => 'Image'
];

// Fonction pour formater la taille du fichier
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2) . ' ' . $units[$i];
}

// Fonction pour obtenir l'icône selon le type de fichier
function getFileIcon($file_type) {
    $icons = [
        'pdf' => 'fas fa-file-pdf text-red-600',
        'doc' => 'fas fa-file-word text-blue-600',
        'docx' => 'fas fa-file-word text-blue-600',
        'xls' => 'fas fa-file-excel text-green-600',
        'xlsx' => 'fas fa-file-excel text-green-600',
        'ppt' => 'fas fa-file-powerpoint text-orange-600',
        'pptx' => 'fas fa-file-powerpoint text-orange-600',
        'txt' => 'fas fa-file-alt text-gray-600',
        'jpg' => 'fas fa-file-image text-purple-600',
        'jpeg' => 'fas fa-file-image text-purple-600',
        'png' => 'fas fa-file-image text-purple-600',
        'gif' => 'fas fa-file-image text-purple-600'
    ];
    return $icons[$file_type] ?? 'fas fa-file text-gray-600';
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents - ISTI</title>
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Documents</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages d'erreur et de succès -->
        <?php if (!empty($errors)): ?>
            <div class="mb-8 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-8 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total documents</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                        <p class="text-sm text-gray-600">disponibles</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-user text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Mes documents</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['my_documents']; ?></p>
                        <p class="text-sm text-gray-600">téléchargés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-orange-100 rounded-full p-3">
                        <i class="fas fa-hdd text-orange-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Espace utilisé</h3>
                        <p class="text-2xl font-bold text-orange-600"><?php echo formatFileSize($stats['total_size']); ?></p>
                        <p class="text-sm text-gray-600">au total</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-tags text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Catégories</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo count($stats['by_category']); ?></p>
                        <p class="text-sm text-gray-600">utilisées</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions et filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-4 md:space-y-0">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-file-alt mr-2"></i>Gestion des documents
                </h2>

                <div class="flex space-x-2">
                    <button onclick="openUploadModal()"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-upload mr-2"></i>Télécharger
                    </button>
                </div>
            </div>

            <!-- Filtres -->
            <form method="GET" class="mt-4 grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">Recherche</label>
                    <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($filter_search); ?>"
                           placeholder="Titre, description, tags..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div>
                    <label for="category" class="block text-sm font-medium text-gray-700 mb-2">Catégorie</label>
                    <select id="category" name="category"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $filter_category === 'all' ? 'selected' : ''; ?>>Toutes les catégories</option>
                        <?php foreach ($document_categories as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filter_category === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Type de fichier</label>
                    <select id="type" name="type"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>Tous les types</option>
                        <?php foreach ($file_types as $key => $label): ?>
                            <option value="<?php echo $key; ?>" <?php echo $filter_type === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 w-full">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                </div>
            </form>
        </div>

        <!-- Liste des documents -->
        <?php if (empty($documents)): ?>
            <div class="bg-white rounded-lg shadow-md p-8 text-center">
                <i class="fas fa-file-alt text-4xl text-gray-300 mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun document trouvé</h3>
                <p class="text-gray-500 mb-4">Il n'y a aucun document correspondant à vos critères de recherche.</p>
                <button onclick="openUploadModal()"
                        class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                    <i class="fas fa-upload mr-2"></i>Télécharger le premier document
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($documents as $document): ?>
                    <div class="bg-white rounded-lg shadow-md overflow-hidden hover:shadow-lg transition duration-200">
                        <div class="p-6">
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="flex-shrink-0">
                                        <i class="<?php echo getFileIcon($document['file_type']); ?> text-2xl"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-lg font-medium text-gray-900 truncate">
                                            <?php echo htmlspecialchars($document['title']); ?>
                                        </h3>
                                        <p class="text-sm text-gray-500">
                                            <?php echo htmlspecialchars($file_types[$document['file_type']] ?? $document['file_type']); ?> • <?php echo formatFileSize($document['file_size']); ?>
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <?php if (!empty($document['description'])): ?>
                                <p class="text-sm text-gray-600 mb-4 line-clamp-3">
                                    <?php echo htmlspecialchars(substr($document['description'], 0, 100)); ?>
                                    <?php if (strlen($document['description']) > 100): ?>...<?php endif; ?>
                                </p>
                            <?php endif; ?>

                            <div class="flex items-center justify-between mb-4">
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                    <?php
                                    switch ($document['visibility']) {
                                        case 'public': echo 'bg-green-100 text-green-800'; break;
                                        case 'private': echo 'bg-red-100 text-red-800'; break;
                                        default: echo 'bg-gray-100 text-gray-800';
                                    }
                                    ?>">
                                    <?php echo $document['visibility'] === 'public' ? 'Public' : 'Privé'; ?>
                                </span>
                                <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                    <?php echo htmlspecialchars($document_categories[$document['category']] ?? $document['category']); ?>
                                </span>
                            </div>

                            <div class="text-xs text-gray-500 mb-4">
                                <p>Par: <?php echo htmlspecialchars($document['uploader_nom'] . ' ' . $document['uploader_prenom']); ?></p>
                                <p>Le: <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($document['created_at']))); ?></p>
                            </div>

                            <div class="flex space-x-2">
                                <a href="<?php echo htmlspecialchars($document['file_path']); ?>" target="_blank"
                                   class="flex-1 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-3 rounded-md transition duration-200 text-center text-sm">
                                    <i class="fas fa-download mr-1"></i>Télécharger
                                </a>

                                <?php if ($document['uploaded_by'] == $user_id || hasRole('admin_general')): ?>
                                    <button onclick="openEditModal(<?php echo $document['id']; ?>, '<?php echo addslashes($document['title']); ?>', '<?php echo addslashes($document['description']); ?>', '<?php echo addslashes($document['category']); ?>', '<?php echo addslashes($document['visibility']); ?>', '<?php echo addslashes($document['tags']); ?>')"
                                            class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-3 rounded-md transition duration-200 text-sm">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button onclick="confirmDelete(<?php echo $document['id']; ?>, '<?php echo addslashes($document['title']); ?>')"
                                            class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-3 rounded-md transition duration-200 text-sm">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="mt-8 bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-700">
                            Affichage de <?php echo min(($page - 1) * $limit + 1, $total_documents); ?> à <?php echo min($page * $limit, $total_documents); ?> sur <?php echo $total_documents; ?> documents
                        </div>
                        <div class="flex space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($filter_type); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    <i class="fas fa-chevron-left mr-1"></i>Précédent
                                </a>
                            <?php endif; ?>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1): ?>
                                <a href="?page=1&category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($filter_type); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span class="px-2 py-1 text-gray-500">...</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($filter_type); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span class="px-2 py-1 text-gray-500">...</span>
                                <?php endif; ?>
                                <a href="?page=<?php echo $total_pages; ?>&category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($filter_type); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?php echo $total_pages; ?></a>
                            <?php endif; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&category=<?php echo urlencode($filter_category); ?>&type=<?php echo urlencode($filter_type); ?>&search=<?php echo urlencode($filter_search); ?>"
                                   class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                    Suivant<i class="fas fa-chevron-right ml-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <!-- Modal Téléchargement -->
    <div id="uploadModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-upload mr-2"></i>Télécharger un document
                    </h3>
                    <button onclick="closeUploadModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload_document">

                    <div>
                        <label for="upload_title" class="block text-sm font-medium text-gray-700 mb-2">
                            Titre *
                        </label>
                        <input type="text" id="upload_title" name="title" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Titre du document">
                    </div>

                    <div>
                        <label for="upload_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description
                        </label>
                        <textarea id="upload_description" name="description" rows="3" maxlength="1000"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                  placeholder="Description du document..."></textarea>
                    </div>

                    <div>
                        <label for="upload_category" class="block text-sm font-medium text-gray-700 mb-2">
                            Catégorie *
                        </label>
                        <select id="upload_category" name="category" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionner une catégorie...</option>
                            <?php foreach ($document_categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="upload_visibility" class="block text-sm font-medium text-gray-700 mb-2">
                            Visibilité *
                        </label>
                        <select id="upload_visibility" name="visibility" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="private">Privé (moi uniquement)</option>
                            <option value="public">Public (visible par tous)</option>
                        </select>
                    </div>

                    <div>
                        <label for="upload_tags" class="block text-sm font-medium text-gray-700 mb-2">
                            Tags (séparés par des virgules)
                        </label>
                        <input type="text" id="upload_tags" name="tags" maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                               placeholder="Ex: cours, informatique, 2024">
                    </div>

                    <div>
                        <label for="upload_document" class="block text-sm font-medium text-gray-700 mb-2">
                            Fichier *
                        </label>
                        <input type="file" id="upload_document" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <p class="text-xs text-gray-500 mt-1">
                            Formats acceptés: PDF, Word, Excel, PowerPoint, TXT, Images. Taille max: 10MB
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeUploadModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Télécharger
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modification -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-edit mr-2"></i>Modifier le document
                    </h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="update_document">
                    <input type="hidden" name="document_id" id="editModalId">

                    <div>
                        <label for="edit_title" class="block text-sm font-medium text-gray-700 mb-2">
                            Titre *
                        </label>
                        <input type="text" id="edit_title" name="title" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description
                        </label>
                        <textarea id="edit_description" name="description" rows="3" maxlength="1000"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>

                    <div>
                        <label for="edit_category" class="block text-sm font-medium text-gray-700 mb-2">
                            Catégorie *
                        </label>
                        <select id="edit_category" name="category" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($document_categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="edit_visibility" class="block text-sm font-medium text-gray-700 mb-2">
                            Visibilité *
                        </label>
                        <select id="edit_visibility" name="visibility" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="private">Privé (moi uniquement)</option>
                            <option value="public">Public (visible par tous)</option>
                        </select>
                    </div>

                    <div>
                        <label for="edit_tags" class="block text-sm font-medium text-gray-700 mb-2">
                            Tags (séparés par des virgules)
                        </label>
                        <input type="text" id="edit_tags" name="tags" maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeEditModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Modifier
                        </button>
                    </div>
                </form>
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
        function openUploadModal() {
            document.getElementById('uploadModal').classList.remove('hidden');
        }

        function closeUploadModal() {
            document.getElementById('uploadModal').classList.add('hidden');
            document.getElementById('upload_title').value = '';
            document.getElementById('upload_description').value = '';
            document.getElementById('upload_category').selectedIndex = 0;
            document.getElementById('upload_visibility').selectedIndex = 0;
            document.getElementById('upload_tags').value = '';
            document.getElementById('upload_document').value = '';
        }

        function openEditModal(id, title, description, category, visibility, tags) {
            document.getElementById('editModalId').value = id;
            document.getElementById('edit_title').value = title;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_category').value = category;
            document.getElementById('edit_visibility').value = visibility;
            document.getElementById('edit_tags').value = tags;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function confirmDelete(id, title) {
            if (confirm('Êtes-vous sûr de vouloir supprimer le document "' + title + '" ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_document">
                    <input type="hidden" name="document_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>