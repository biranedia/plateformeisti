<?php
/**
 * Gestion des documents de classe pour les responsables de classe
 * Permet de consulter et gérer les documents partagés avec la classe
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

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'upload_document' && isset($_FILES['document'])) {
            $titre = sanitize($_POST['titre']);
            $description = sanitize($_POST['description']);
            $categorie = sanitize($_POST['categorie']);
            $file = $_FILES['document'];

            // Validation du fichier
            $allowed_types = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png'];
            $max_size = 10 * 1024 * 1024; // 10MB

            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($file_extension, $allowed_types)) {
                $message = "Type de fichier non autorisé. Extensions acceptées: " . implode(', ', $allowed_types);
                $message_type = "error";
            } elseif ($file['size'] > $max_size) {
                $message = "Le fichier est trop volumineux. Taille maximale: 10MB";
                $message_type = "error";
            } else {
                // Création du dossier de destination
                $upload_dir = '../uploads/documents_classe/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                // Génération d'un nom de fichier unique
                $file_name = uniqid('doc_classe_') . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;

                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    // Insertion en base de données
                    $insert_query = "INSERT INTO documents (titre, description, fichier_path, categorie, type_document,
                                      uploaded_by, classe_id, date_upload, statut)
                                    VALUES (:titre, :description, :fichier_path, :categorie, 'classe',
                                           :uploaded_by, :classe_id, NOW(), 'active')";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':titre', $titre);
                    $insert_stmt->bindParam(':description', $description);
                    $insert_stmt->bindParam(':fichier_path', $file_name);
                    $insert_stmt->bindParam(':categorie', $categorie);
                    $insert_stmt->bindParam(':uploaded_by', $user_id);
                    $insert_stmt->bindParam(':classe_id', $classe['id']);

                    if ($insert_stmt->execute()) {
                        $message = "Document uploadé avec succès.";
                        $message_type = "success";
                    } else {
                        unlink($file_path); // Supprimer le fichier si l'insertion échoue
                        $message = "Erreur lors de l'enregistrement du document.";
                        $message_type = "error";
                    }
                } else {
                    $message = "Erreur lors de l'upload du fichier.";
                    $message_type = "error";
                }
            }
        } elseif ($action === 'delete_document' && isset($_POST['document_id'])) {
            $document_id = (int)$_POST['document_id'];

            // Vérification que le document appartient à la classe du responsable
            $check_query = "SELECT fichier_path FROM documents
                           WHERE id = :id AND classe_id = :classe_id AND type_document = 'classe'";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':id', $document_id);
            $check_stmt->bindParam(':classe_id', $classe['id']);
            $check_stmt->execute();
            $document = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($document) {
                // Suppression du fichier physique
                $file_path = '../uploads/documents_classe/' . $document['fichier_path'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }

                // Suppression de la base de données
                $delete_query = "DELETE FROM documents WHERE id = :id";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bindParam(':id', $document_id);

                if ($delete_stmt->execute()) {
                    $message = "Document supprimé avec succès.";
                    $message_type = "success";
                } else {
                    $message = "Erreur lors de la suppression du document.";
                    $message_type = "error";
                }
            } else {
                $message = "Document non trouvé ou accès non autorisé.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des documents de la classe
$documents_query = "SELECT d.*, u.name as nom_uploader
                   FROM documents d
                   JOIN users u ON d.uploaded_by = u.id
                   WHERE d.classe_id = :classe_id AND d.type_document = 'classe' AND d.statut = 'active'
                   ORDER BY d.date_upload DESC";
$documents_stmt = $conn->prepare($documents_query);
$documents_stmt->bindParam(':classe_id', $classe['id']);
$documents_stmt->execute();
$documents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des documents
$stats_query = "SELECT
    COUNT(*) as total_documents,
    COUNT(CASE WHEN categorie = 'cours' THEN 1 END) as documents_cours,
    COUNT(CASE WHEN categorie = 'administratif' THEN 1 END) as documents_admin,
    COUNT(CASE WHEN categorie = 'examen' THEN 1 END) as documents_examen,
    COUNT(CASE WHEN categorie = 'divers' THEN 1 END) as documents_divers,
    SUM(taille_fichier) as taille_totale
FROM documents
WHERE classe_id = :classe_id AND type_document = 'classe' AND statut = 'active'";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':classe_id', $classe['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Filtrage
$filtre_categorie = isset($_GET['categorie']) ? sanitize($_GET['categorie']) : '';
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';

if ($filtre_categorie || $recherche) {
    $documents = array_filter($documents, function($document) use ($filtre_categorie, $recherche) {
        $match_categorie = !$filtre_categorie || $document['categorie'] === $filtre_categorie;
        $match_recherche = !$recherche ||
            stripos($document['titre'], $recherche) !== false ||
            stripos($document['description'], $recherche) !== false ||
            stripos($document['nom_uploader'], $recherche) !== false ||
            stripos($document['prenom_uploader'], $recherche) !== false;
        return $match_categorie && $match_recherche;
    });
}

// Fonction pour obtenir l'icône selon le type de fichier
function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    switch ($extension) {
        case 'pdf': return 'fas fa-file-pdf text-red-500';
        case 'doc':
        case 'docx': return 'fas fa-file-word text-blue-500';
        case 'xls':
        case 'xlsx': return 'fas fa-file-excel text-green-500';
        case 'ppt':
        case 'pptx': return 'fas fa-file-powerpoint text-orange-500';
        case 'txt': return 'fas fa-file-alt text-gray-500';
        case 'jpg':
        case 'jpeg':
        case 'png': return 'fas fa-file-image text-purple-500';
        default: return 'fas fa-file text-gray-500';
    }
}

// Fonction pour formater la taille du fichier
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

// Fonction pour obtenir le libellé de la catégorie
function getCategoryLabel($categorie) {
    switch ($categorie) {
        case 'cours': return 'Cours';
        case 'administratif': return 'Administratif';
        case 'examen': return 'Examen';
        case 'divers': return 'Divers';
        default: return 'Non catégorisé';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents Classe - ISTI</title>
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
                <a href="documents_classes.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
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
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des documents
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-file-alt text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_documents']; ?></p>
                    <p class="text-sm text-gray-600">documents</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-graduation-cap text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Cours</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['documents_cours']; ?></p>
                    <p class="text-sm text-gray-600">documents</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clipboard-list text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Administratif</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['documents_admin']; ?></p>
                    <p class="text-sm text-gray-600">documents</p>
                </div>

                <div class="text-center">
                    <div class="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-file-signature text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Examens</h3>
                    <p class="text-2xl font-bold text-red-600"><?php echo $stats['documents_examen']; ?></p>
                    <p class="text-sm text-gray-600">documents</p>
                </div>

                <div class="text-center">
                    <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-hdd text-purple-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Espace utilisé</h3>
                    <p class="text-2xl font-bold text-purple-600"><?php echo $stats['taille_totale'] ? formatFileSize($stats['taille_totale']) : '0 B'; ?></p>
                    <p class="text-sm text-gray-600">total</p>
                </div>
            </div>
        </div>

        <!-- Bouton d'ajout de document -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-plus-circle mr-2"></i>Ajouter un document
                </h2>
                <button onclick="toggleUploadForm()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                    <i class="fas fa-upload mr-2"></i>Ajouter un document
                </button>
            </div>

            <!-- Formulaire d'upload (caché par défaut) -->
            <div id="uploadForm" class="hidden mt-6 border-t border-gray-200 pt-6">
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload_document">

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="titre" class="block text-sm font-medium text-gray-700 mb-1">
                                Titre du document *
                            </label>
                            <input type="text" id="titre" name="titre" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        </div>

                        <div>
                            <label for="categorie" class="block text-sm font-medium text-gray-700 mb-1">
                                Catégorie *
                            </label>
                            <select id="categorie" name="categorie" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                                <option value="">Sélectionner une catégorie</option>
                                <option value="cours">Cours</option>
                                <option value="administratif">Administratif</option>
                                <option value="examen">Examen</option>
                                <option value="divers">Divers</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                            Description
                        </label>
                        <textarea id="description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                  placeholder="Description du document..."></textarea>
                    </div>

                    <div>
                        <label for="document" class="block text-sm font-medium text-gray-700 mb-1">
                            Fichier *
                        </label>
                        <input type="file" id="document" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.jpg,.jpeg,.png"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <p class="text-sm text-gray-500 mt-1">
                            Formats acceptés: PDF, Word, Excel, PowerPoint, TXT, Images. Taille max: 10MB
                        </p>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="toggleUploadForm()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-upload mr-2"></i>Uploader
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-wrap items-center gap-4">
                <div>
                    <label for="recherche" class="block text-sm font-medium text-gray-700 mb-1">
                        Rechercher
                    </label>
                    <input type="text" id="recherche" placeholder="Titre, description, uploader..."
                           class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="categorie_filter" class="block text-sm font-medium text-gray-700 mb-1">
                        Catégorie
                    </label>
                    <select id="categorie_filter"
                            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Toutes les catégories</option>
                        <option value="cours">Cours</option>
                        <option value="administratif">Administratif</option>
                        <option value="examen">Examen</option>
                        <option value="divers">Divers</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button onclick="appliquerFiltres()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                </div>
            </div>
        </div>

        <!-- Liste des documents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-file-alt mr-2"></i>Documents de la classe
            </h2>

            <?php if (empty($documents)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-file-alt text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun document trouvé</h3>
                    <p class="text-gray-500">Il n'y a pas de documents correspondant aux critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($documents as $document): ?>
                    <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition duration-200">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center">
                                <i class="<?php echo getFileIcon($document['fichier_path']); ?> text-2xl mr-3"></i>
                                <div>
                                    <h3 class="font-semibold text-gray-900 text-sm">
                                        <?php echo htmlspecialchars($document['titre']); ?>
                                    </h3>
                                    <p class="text-xs text-gray-500">
                                        <?php echo getCategoryLabel($document['categorie']); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex space-x-1">
                                <a href="../uploads/documents_classe/<?php echo htmlspecialchars($document['fichier_path']); ?>"
                                   target="_blank"
                                   class="text-indigo-600 hover:text-indigo-900 text-sm">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="../uploads/documents_classe/<?php echo htmlspecialchars($document['fichier_path']); ?>"
                                   download
                                   class="text-green-600 hover:text-green-900 text-sm">
                                    <i class="fas fa-download"></i>
                                </a>
                                <button onclick="confirmerSuppression(<?php echo $document['id']; ?>, '<?php echo htmlspecialchars($document['titre']); ?>')"
                                        class="text-red-600 hover:text-red-900 text-sm">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>

                        <?php if ($document['description']): ?>
                        <p class="text-sm text-gray-600 mb-3 line-clamp-2">
                            <?php echo htmlspecialchars($document['description']); ?>
                        </p>
                        <?php endif; ?>

                        <div class="text-xs text-gray-500 space-y-1">
                            <p>Uploadé par: <?php echo htmlspecialchars($document['nom_uploader']); ?></p>
                            <p>Date: <?php echo date('d/m/Y à H:i', strtotime($document['date_upload'])); ?></p>
                            <?php if ($document['taille_fichier']): ?>
                            <p>Taille: <?php echo formatFileSize($document['taille_fichier']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations sur la gestion des documents -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Gestion des documents de classe
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Organisez les documents par catégories pour faciliter la recherche</li>
                            <li>Vérifiez régulièrement les documents obsolètes à supprimer</li>
                            <li>Les formats PDF sont recommandés pour une meilleure compatibilité</li>
                            <li>Utilisez des noms de fichiers descriptifs pour une identification rapide</li>
                            <li>Les étudiants peuvent accéder à ces documents depuis leur espace personnel</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-trash mr-2"></i>Confirmer la suppression
                        </h3>
                        <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <p class="text-gray-700 mb-6">
                        Êtes-vous sûr de vouloir supprimer le document "<span id="deleteDocumentTitle"></span>" ?
                        Cette action est irréversible.
                    </p>

                    <form id="deleteForm" method="POST">
                        <input type="hidden" name="action" value="delete_document">
                        <input type="hidden" id="deleteDocumentId" name="document_id">

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeDeleteModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-trash mr-2"></i>Supprimer
                            </button>
                        </div>
                    </form>
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
        function toggleUploadForm() {
            const form = document.getElementById('uploadForm');
            form.classList.toggle('hidden');
        }

        function appliquerFiltres() {
            const recherche = document.getElementById('recherche').value;
            const categorie = document.getElementById('categorie_filter').value;

            let url = window.location.pathname;
            const params = new URLSearchParams();

            if (recherche) params.append('recherche', recherche);
            if (categorie) params.append('categorie', categorie);

            if (params.toString()) {
                url += '?' + params.toString();
            }

            window.location.href = url;
        }

        function confirmerSuppression(documentId, documentTitle) {
            document.getElementById('deleteDocumentId').value = documentId;
            document.getElementById('deleteDocumentTitle').textContent = documentTitle;
            document.getElementById('deleteModal').classList.remove('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.add('hidden');
        }

        // Fermer le modal en cliquant en dehors
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
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