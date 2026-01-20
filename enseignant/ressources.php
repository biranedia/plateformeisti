<?php
/**
 * Gestion des ressources pédagogiques pour les enseignants
 * Permet de partager des documents, supports de cours, etc.
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('enseignant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'enseignant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération des ressources de l'enseignant (fichiers pédagogiques)
$ressources_query = "SELECT fp.*, e.matiere as nom_cours, cl.nom_classe, fi.nom as nom_filiere
                    FROM fichiers_pedagogiques fp
                    JOIN enseignements e ON fp.enseignement_id = e.id
                    JOIN classes cl ON e.classe_id = cl.id
                    JOIN filieres fi ON cl.filiere_id = fi.id
                    WHERE e.enseignant_id = :enseignant_id
                    ORDER BY fp.date_upload DESC";
$ressources_stmt = $conn->prepare($ressources_query);
$ressources_stmt->bindParam(':enseignant_id', $user_id);
$ressources_stmt->execute();
$ressources = $ressources_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des enseignements (matières) de l'enseignant pour le formulaire
$cours_query = "SELECT DISTINCT e.id, e.matiere as nom_cours
               FROM enseignements e
               WHERE e.enseignant_id = :enseignant_id
               ORDER BY e.matiere";
$cours_stmt = $conn->prepare($cours_query);
$cours_stmt->bindParam(':enseignant_id', $user_id);
$cours_stmt->execute();
$cours_list = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes de l'enseignant
$classes_query = "SELECT DISTINCT cl.id, cl.nom_classe, fi.nom as nom_filiere
                 FROM classes cl
                 JOIN filieres fi ON cl.filiere_id = fi.id
                 JOIN enseignements e ON cl.id = e.classe_id
                 WHERE e.enseignant_id = :enseignant_id
                 ORDER BY fi.nom, cl.nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':enseignant_id', $user_id);
$classes_stmt->execute();
$classes_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de ressources
$types_ressources = [
    'cours' => 'Support de cours',
    'tp' => 'Travaux pratiques',
    'td' => 'Travaux dirigés',
    'examen' => 'Sujet d\'examen',
    'correction' => 'Correction',
    'autre' => 'Autre'
];

// Traitement du formulaire d'ajout de ressource
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter_ressource') {
        $titre = sanitize($_POST['titre']);
        $description = sanitize($_POST['description']);
        $type_ressource = sanitize($_POST['type_ressource']);
        $enseignement_id = !empty($_POST['cours_id']) ? sanitize($_POST['cours_id']) : null;
        $classe_id = !empty($_POST['classe_id']) ? sanitize($_POST['classe_id']) : null;
        $visibilite = sanitize($_POST['visibilite']); // 'public', 'classe', 'prive'

        // Validation
        $errors = [];
        if (empty($titre)) $errors[] = 'Le titre est obligatoire.';
        if (empty($enseignement_id)) $errors[] = 'Une matière/cours doit être sélectionné(e).';

        // Gestion du fichier
        $fichier_url = null;
        if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/ressources/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['fichier']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'zip', 'rar'];

            if (!in_array($file_extension, $allowed_extensions)) {
                $errors[] = 'Type de fichier non autorisé. Extensions acceptées: ' . implode(', ', $allowed_extensions);
            } else {
                $new_filename = uniqid('ressource_') . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['fichier']['tmp_name'], $upload_path)) {
                    $fichier_url = 'uploads/ressources/' . $new_filename;
                } else {
                    $errors[] = 'Erreur lors du téléchargement du fichier.';
                }
            }
        } elseif (empty($_POST['url_externe'])) {
            $errors[] = 'Vous devez soit télécharger un fichier, soit fournir une URL externe.';
        } else {
            $fichier_url = sanitize($_POST['url_externe']);
        }

        if (empty($errors)) {
            try {
                $insert_query = "INSERT INTO fichiers_pedagogiques (titre, fichier_url, enseignement_id)
                               VALUES (:titre, :fichier_url, :enseignement_id)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':titre', $titre);
                $insert_stmt->bindParam(':fichier_url', $fichier_url);
                $insert_stmt->bindParam(':enseignement_id', $enseignement_id);
                $insert_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Ressource ajoutée avec succès !'];

                // Recharger les ressources
                $ressources_stmt->execute();
                $ressources = $ressources_stmt->fetchAll(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'ajout: ' . $e->getMessage()];
            }
        } else {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
            }
        }
    } elseif ($_POST['action'] === 'supprimer_ressource') {
        $ressource_id = sanitize($_POST['ressource_id']);

        try {
            // Récupérer l'URL du fichier avant suppression
            $select_query = "SELECT fp.fichier_url FROM fichiers_pedagogiques fp
                            JOIN enseignements e ON fp.enseignement_id = e.id
                            WHERE fp.id = :id AND e.enseignant_id = :enseignant_id";
            $select_stmt = $conn->prepare($select_query);
            $select_stmt->bindParam(':id', $ressource_id);
            $select_stmt->bindParam(':enseignant_id', $user_id);
            $select_stmt->execute();
            $ressource = $select_stmt->fetch(PDO::FETCH_ASSOC);

            if ($ressource) {
                // Supprimer le fichier physique si c'est un upload local
                if ($ressource['fichier_url'] && strpos($ressource['fichier_url'], 'uploads/ressources/') === 0) {
                    $file_path = '../' . $ressource['fichier_url'];
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }

                // Supprimer de la base de données
                $delete_query = "DELETE fp FROM fichiers_pedagogiques fp
                                JOIN enseignements e ON fp.enseignement_id = e.id
                                WHERE fp.id = :id AND e.enseignant_id = :enseignant_id";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bindParam(':id', $ressource_id);
                $delete_stmt->bindParam(':enseignant_id', $user_id);
                $delete_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Ressource supprimée avec succès.'];

                // Recharger les ressources
                $ressources_stmt->execute();
                $ressources = $ressources_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Ressource introuvable ou accès non autorisé.'];
            }

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la suppression: ' . $e->getMessage()];
        }
    }
}

// Statistiques des ressources
$stats_query = "SELECT COUNT(*) as total FROM fichiers_pedagogiques fp
                JOIN enseignements e ON fp.enseignement_id = e.id
                WHERE e.enseignant_id = :enseignant_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':enseignant_id', $user_id);
$stats_stmt->execute();
$stats_total = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ressources Pédagogiques - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-green-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chalkboard-teacher text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Enseignant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Enseignant'); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="cours.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-book mr-1"></i>Cours
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="presence.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-user-check mr-1"></i>Présence
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="ressources.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-folder-open mr-1"></i>Ressources
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded <?php echo $message['type'] === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700'; ?> border">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des ressources
            </h2>

            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                <?php foreach ($types_ressources as $key => $label): ?>
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-12 h-12 flex items-center justify-center mx-auto mb-2">
                        <i class="fas fa-file-alt text-blue-600 text-lg"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 text-sm mb-1"><?php echo htmlspecialchars($label); ?></h3>
                    <p class="text-xl font-bold text-blue-600"><?php echo $stats_by_type[$key] ?? 0; ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Ajouter une ressource -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus-circle mr-2"></i>Ajouter une ressource
                    </h2>

                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="ajouter_ressource">

                        <div>
                            <label for="titre" class="block text-sm font-medium text-gray-700 mb-1">
                                Titre *
                            </label>
                            <input type="text" name="titre" id="titre" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                                Description
                            </label>
                            <textarea name="description" id="description" rows="3"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500"></textarea>
                        </div>

                        <div>
                            <label for="type_ressource" class="block text-sm font-medium text-gray-700 mb-1">
                                Type de ressource *
                            </label>
                            <select name="type_ressource" id="type_ressource" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                <option value="">Sélectionnez un type</option>
                                <?php foreach ($types_ressources as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="cours_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Cours associé (optionnel)
                            </label>
                            <select name="cours_id" id="cours_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                <option value="">Aucun cours spécifique</option>
                                <?php foreach ($cours_list as $cours): ?>
                                    <option value="<?php echo $cours['id']; ?>"><?php echo htmlspecialchars($cours['nom_cours']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="visibilite" class="block text-sm font-medium text-gray-700 mb-1">
                                Visibilité *
                            </label>
                            <select name="visibilite" id="visibilite" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                <option value="prive">Privé (vous seul)</option>
                                <option value="classe">Classe spécifique</option>
                                <option value="public">Public (tous les étudiants)</option>
                            </select>
                        </div>

                        <div id="classe_selection" style="display: none;">
                            <label for="classe_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Classe *
                            </label>
                            <select name="classe_id" id="classe_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                <option value="">Sélectionnez une classe</option>
                                <?php foreach ($classes_list as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo htmlspecialchars($classe['nom_filiere'] . ' - ' . $classe['nom_classe']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fichier ou URL
                            </label>
                            <div class="space-y-2">
                                <input type="file" name="fichier" id="fichier"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                <div class="text-center text-gray-500">OU</div>
                                <input type="url" name="url_externe" id="url_externe" placeholder="https://..."
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Formats acceptés: PDF, DOC, PPT, XLS, TXT, ZIP (max 10MB)
                            </p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-upload mr-2"></i>Ajouter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Liste des ressources -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-folder-open mr-2"></i>Mes ressources
                    </h2>

                    <?php if (empty($ressources)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-folder-open text-gray-300 text-6xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune ressource</h3>
                            <p class="text-gray-500">Vous n'avez pas encore ajouté de ressources pédagogiques.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($ressources as $ressource): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($ressource['titre']); ?></h3>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                                <?php echo $ressource['visibilite'] === 'public' ? 'bg-green-100 text-green-800' :
                                                         ($ressource['visibilite'] === 'classe' ? 'bg-blue-100 text-blue-800' :
                                                         'bg-gray-100 text-gray-800'); ?>">
                                                <?php echo $ressource['visibilite'] === 'public' ? 'Public' :
                                                         ($ressource['visibilite'] === 'classe' ? 'Classe' :
                                                         'Privé'); ?>
                                            </span>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-purple-100 text-purple-800">
                                                <?php echo htmlspecialchars($types_ressources[$ressource['type_ressource']] ?? $ressource['type_ressource']); ?>
                                            </span>
                                        </div>
                                        <?php if ($ressource['description']): ?>
                                            <p class="text-sm text-gray-600 mb-2"><?php echo htmlspecialchars($ressource['description']); ?></p>
                                        <?php endif; ?>
                                        <div class="text-xs text-gray-500 space-y-1">
                                            <?php if ($ressource['nom_cours']): ?>
                                                <p><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($ressource['nom_cours']); ?></p>
                                            <?php endif; ?>
                                            <?php if ($ressource['nom_classe']): ?>
                                                <p><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($ressource['nom_classe']); ?> - <?php echo htmlspecialchars($ressource['nom_filiere']); ?></p>
                                            <?php endif; ?>
                                            <p><i class="fas fa-calendar-alt mr-1"></i>Ajouté le <?php echo htmlspecialchars(date('d/m/Y', strtotime($ressource['date_creation']))); ?></p>
                                        </div>
                                    </div>
                                    <div class="ml-4 flex flex-col space-y-2">
                                        <?php if ($ressource['fichier_url']): ?>
                                            <a href="<?php echo strpos($ressource['fichier_url'], 'http') === 0 ? htmlspecialchars($ressource['fichier_url']) : '../' . htmlspecialchars($ressource['fichier_url']); ?>"
                                               target="_blank"
                                               class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-1 px-3 rounded-md transition duration-200 text-center">
                                                <i class="fas fa-download mr-1"></i>Voir
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" class="inline"
                                              onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette ressource ?')">
                                            <input type="hidden" name="action" value="supprimer_ressource">
                                            <input type="hidden" name="ressource_id" value="<?php echo $ressource['id']; ?>">
                                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium py-1 px-3 rounded-md transition duration-200">
                                                <i class="fas fa-trash mr-1"></i>Suppr.
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Informations sur les ressources -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-lightbulb text-green-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-green-800">
                        Bonnes pratiques pour les ressources pédagogiques
                    </h3>
                    <div class="mt-2 text-sm text-green-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Utilisez des noms de fichiers descriptifs pour faciliter la recherche</li>
                            <li>Pour les documents volumineux, préférez les liens externes (Google Drive, etc.)</li>
                            <li>Les corrections d'examens ne devraient être visibles qu'après les résultats</li>
                            <li>Variez les types de ressources pour maintenir l'intérêt des étudiants</li>
                            <li>Vérifiez régulièrement que vos liens externes sont toujours accessibles</li>
                        </ul>
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
        // Gestion de la visibilité
        document.getElementById('visibilite').addEventListener('change', function() {
            const classeSelection = document.getElementById('classe_selection');
            const classeSelect = document.getElementById('classe_id');

            if (this.value === 'classe') {
                classeSelection.style.display = 'block';
                classeSelect.required = true;
            } else {
                classeSelection.style.display = 'none';
                classeSelect.required = false;
            }
        });

        // Gestion des champs fichier/URL
        document.getElementById('fichier').addEventListener('change', function() {
            if (this.files.length > 0) {
                document.getElementById('url_externe').value = '';
                document.getElementById('url_externe').required = false;
            }
        });

        document.getElementById('url_externe').addEventListener('input', function() {
            if (this.value.trim() !== '') {
                document.getElementById('fichier').value = '';
            }
        });
    </script>
</body>
</html>