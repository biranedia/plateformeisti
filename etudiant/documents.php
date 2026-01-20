<?php
/**
 * Gestion des documents d'inscription - Étudiant
 * Permet à l'étudiant de consulter et télécharger ses documents soumis
 */

session_start();

require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('etudiant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'étudiant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Récupération des documents de l'utilisateur
$query = "SELECT * FROM documents_inscription 
         WHERE user_id = :user_id 
         ORDER BY date_upload DESC";
$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats = [
    'total' => 0,
    'soumis' => 0,
    'valide' => 0,
    'rejete' => 0
];

$messages = [];
$errors = [];

foreach ($documents as $doc) {
    $stats['total']++;
    $stats[$doc['statut']]++;
}

// Upload d'un document demandé par l'administration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document') {
    $titre = sanitize($_POST['titre'] ?? 'Document demandé');
    $file = $_FILES['document_admin'] ?? null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = "Veuillez sélectionner un fichier.";
    } else {
        $accepted_types = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 5 * 1024 * 1024; // 5MB

        // MIME check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $accepted_types)) {
            $errors[] = "Type de fichier non accepté (PDF, JPG ou PNG seulement).";
        }

        if ($file['size'] > $max_size) {
            $errors[] = "Fichier trop volumineux (max 5MB).";
        }

        if (empty($errors)) {
            // Répertoire de stockage
            $user_dir = __DIR__ . '/../documents/inscriptions/user_' . $user_id;
            if (!is_dir($user_dir)) {
                mkdir($user_dir, 0777, true);
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safe_filename = 'doc_admin_' . time() . '.' . $extension;
            $filepath = $user_dir . '/' . $safe_filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $insert = "INSERT INTO documents_inscription (user_id, type_document, nom_fichier, chemin_fichier, type_mime, taille_fichier, statut, commentaire_validation)
                           VALUES (:user_id, 'autre', :nom_fichier, :chemin_fichier, :type_mime, :taille_fichier, 'soumis', :commentaire)";
                $stmt_insert = $conn->prepare($insert);
                $relative_path = str_replace(__DIR__ . '/../', '', $filepath);
                $stmt_insert->bindParam(':user_id', $user_id);
                $stmt_insert->bindParam(':nom_fichier', $titre ?: $file['name']);
                $stmt_insert->bindParam(':chemin_fichier', $relative_path);
                $stmt_insert->bindParam(':type_mime', $mime_type);
                $stmt_insert->bindParam(':taille_fichier', $file['size']);
                $stmt_insert->bindValue(':commentaire', null, PDO::PARAM_NULL);

                if ($stmt_insert->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'Document envoyé. Il est en attente de validation.'];
                    // Recharger la liste
                    $stmt->execute();
                    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $errors[] = "Erreur lors de l'enregistrement du document.";
                }
            } else {
                $errors[] = "Erreur lors du transfert du fichier.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Documents - ISTI</title>
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Mes Documents</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Étudiant'); ?></span>
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
                <a href="inscription.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="documents.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-file-alt mr-1"></i>Mes Documents
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-6">
                <p class="font-semibold">Erreurs :</p>
                <ul class="list-disc ml-5 text-sm">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo $error; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $msg): ?>
                <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-6">
                    <?php echo htmlspecialchars($msg['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="flex justify-between items-center mb-4">
            <h2 class="text-xl font-bold text-gray-800">Documents</h2>
            <button onclick="document.getElementById('upload_form').scrollIntoView({behavior:'smooth'});" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md text-sm shadow">
                <i class="fas fa-plus mr-2"></i>Ajouter un document
            </button>
        </div>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-lg shadow p-6">
                <div class="text-gray-600 text-sm font-medium">Total</div>
                <div class="mt-2 text-3xl font-bold text-gray-900"><?php echo $stats['total']; ?></div>
            </div>
            <div class="bg-yellow-50 rounded-lg shadow p-6 border-l-4 border-yellow-500">
                <div class="text-yellow-600 text-sm font-medium">En attente</div>
                <div class="mt-2 text-3xl font-bold text-yellow-900"><?php echo $stats['soumis']; ?></div>
            </div>
            <div class="bg-green-50 rounded-lg shadow p-6 border-l-4 border-green-500">
                <div class="text-green-600 text-sm font-medium">Validés</div>
                <div class="mt-2 text-3xl font-bold text-green-900"><?php echo $stats['valide']; ?></div>
            </div>
            <div class="bg-red-50 rounded-lg shadow p-6 border-l-4 border-red-500">
                <div class="text-red-600 text-sm font-medium">Rejetés</div>
                <div class="mt-2 text-3xl font-bold text-red-900"><?php echo $stats['rejete']; ?></div>
            </div>
        </div>

        <!-- Info Alert -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-8">
            <div class="flex items-start">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-500 text-lg"></i>
                </div>
                <div class="ml-3">
                    <p class="text-sm text-blue-700">
                        <strong>Suivi de vos documents :</strong> Cette page vous permet de consulter le statut de validation de vos documents d'inscription (relevé BAC et diplôme BAC). Vous serez notifié une fois que l'administration aura examiné vos documents.
                    </p>
                </div>
            </div>
        </div>

        <!-- Upload d'un document demandé -->
        <div id="upload_form" class="bg-white rounded-lg shadow p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">
                <i class="fas fa-upload mr-2"></i>Envoyer un document demandé par l'administration
            </h2>
            <form method="POST" enctype="multipart/form-data" class="space-y-4">
                <input type="hidden" name="action" value="upload_document">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Titre du document (optionnel)</label>
                    <input type="text" name="titre" class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Ex: Pièce complémentaire">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fichier (PDF, JPG, PNG - max 5MB)</label>
                    <input type="file" name="document_admin" accept=".pdf,.jpg,.jpeg,.png" required class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md">
                        Envoyer
                    </button>
                </div>
            </form>
        </div>

        <!-- Tableau des documents -->
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50 border-b">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Type de Document</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Nom du Fichier</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Date Upload</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Statut</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-700 uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php if (empty($documents)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-4 text-center text-gray-500 py-12">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-inbox text-4xl mb-4 text-gray-400"></i>
                                    <p class="text-lg font-medium">Aucun document soumis</p>
                                    <p class="text-sm text-gray-400 mt-2">Vos documents d'inscription seront listés ici</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php 
                                    $type_icons = [
                                        'releve_bac' => '<i class="fas fa-file-pdf text-red-500 mr-2"></i>Relevé BAC',
                                        'diplome_bac' => '<i class="fas fa-certificate text-green-500 mr-2"></i>Diplôme BAC'
                                    ];
                                    echo isset($type_icons[$doc['type_document']]) ? $type_icons[$doc['type_document']] : htmlspecialchars($doc['type_document']);
                                    ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php echo htmlspecialchars($doc['nom_fichier']); ?>
                                    <div class="text-xs text-gray-400 mt-1"><?php echo number_format($doc['taille_fichier'] / 1024, 2); ?> KB</div>
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
                                        'valide' => 'Validé ✓',
                                        'rejete' => 'Rejeté ✗'
                                    ];
                                    $status_icons = [
                                        'soumis' => 'fas fa-clock',
                                        'valide' => 'fas fa-check-circle',
                                        'rejete' => 'fas fa-times-circle'
                                    ];
                                    $class = $status_classes[$doc['statut']] ?? 'bg-gray-100 text-gray-800';
                                    $label = $status_labels[$doc['statut']] ?? $doc['statut'];
                                    $icon = $status_icons[$doc['statut']] ?? 'fas fa-question';
                                    ?>
                                    <div class="flex items-center">
                                        <i class="<?php echo $icon; ?> mr-2"></i>
                                        <span class="px-3 py-1 rounded-full text-xs font-medium <?php echo $class; ?>">
                                            <?php echo $label; ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="/plateformeisti/<?php echo htmlspecialchars($doc['chemin_fichier']); ?>" target="_blank" class="text-blue-600 hover:text-blue-900 transition" title="Télécharger">
                                        <i class="fas fa-download mr-1"></i>Télécharger
                                    </a>
                                </td>
                            </tr>
                            <?php if ($doc['statut'] === 'rejete' && $doc['commentaire_validation']): ?>
                                <tr class="bg-red-50 border-l-4 border-red-500">
                                    <td colspan="5" class="px-6 py-4">
                                        <div class="flex items-start space-x-3">
                                            <i class="fas fa-exclamation-triangle text-red-600 mt-1 flex-shrink-0"></i>
                                            <div>
                                                <p class="text-sm font-medium text-red-800">Raison du rejet</p>
                                                <p class="text-sm text-red-700 mt-1"><?php echo htmlspecialchars($doc['commentaire_validation']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($doc['statut'] === 'valide' && $doc['commentaire_validation']): ?>
                                <tr class="bg-green-50">
                                    <td colspan="5" class="px-6 py-4">
                                        <div class="flex items-start space-x-3">
                                            <i class="fas fa-check-circle text-green-600 mt-1 flex-shrink-0"></i>
                                            <div>
                                                <p class="text-sm font-medium text-green-800">Commentaire</p>
                                                <p class="text-sm text-green-700 mt-1"><?php echo htmlspecialchars($doc['commentaire_validation']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </main>

    <?php include_once '../shared/includes/footer.php'; ?>
</body>
</html>
