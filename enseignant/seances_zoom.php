<?php
/**
 * Gestion des séances Zoom pour les enseignants
 * Permet de planifier et partager des vidéos Zoom avec les étudiants
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

// Récupération des séances Zoom de l'enseignant
$seances_query = "SELECT sz.*, cl.nom_classe, fi.nom_filiere, c.nom_cours,
                         COUNT(DISTINCT uv.user_id) as vues_count
                  FROM seances_zoom sz
                  LEFT JOIN classes cl ON sz.classe_id = cl.id
                  LEFT JOIN filieres fi ON cl.filiere_id = fi.id
                  LEFT JOIN cours c ON sz.cours_id = c.id
                  LEFT JOIN user_vues_zoom uv ON sz.id = uv.seance_id
                  WHERE sz.enseignant_id = :enseignant_id
                  GROUP BY sz.id
                  ORDER BY sz.date_seance DESC";
$seances_stmt = $conn->prepare($seances_query);
$seances_stmt->bindParam(':enseignant_id', $user_id);
$seances_stmt->execute();
$seances = $seances_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des cours de l'enseignant
$cours_query = "SELECT DISTINCT c.id, c.nom_cours
               FROM cours c
               JOIN enseignements e ON c.id = e.cours_id
               WHERE e.enseignant_id = :enseignant_id
               ORDER BY c.nom_cours";
$cours_stmt = $conn->prepare($cours_query);
$cours_stmt->bindParam(':enseignant_id', $user_id);
$cours_stmt->execute();
$cours_list = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes de l'enseignant
$classes_query = "SELECT DISTINCT cl.id, cl.nom_classe, fi.nom_filiere
                 FROM classes cl
                 JOIN filieres fi ON cl.filiere_id = fi.id
                 JOIN enseignements e ON cl.id = e.classe_id
                 WHERE e.enseignant_id = :enseignant_id
                 ORDER BY fi.nom_filiere, cl.nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':enseignant_id', $user_id);
$classes_stmt->execute();
$classes_list = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire d'ajout de séance Zoom
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'ajouter_seance') {
        $titre = sanitize($_POST['titre']);
        $description = sanitize($_POST['description']);
        $date_seance = sanitize($_POST['date_seance']);
        $heure_debut = sanitize($_POST['heure_debut']);
        $duree_minutes = intval(sanitize($_POST['duree_minutes']));
        $zoom_url = sanitize($_POST['zoom_url']);
        $zoom_id = sanitize($_POST['zoom_id']);
        $zoom_password = sanitize($_POST['zoom_password'] ?? '');
        $classe_id = !empty($_POST['classe_id']) ? intval(sanitize($_POST['classe_id'])) : null;
        $cours_id = !empty($_POST['cours_id']) ? intval(sanitize($_POST['cours_id'])) : null;

        $errors = [];

        if (empty($titre)) $errors[] = 'Le titre est obligatoire.';
        if (empty($zoom_url)) $errors[] = 'L\'URL Zoom est obligatoire.';
        if (empty($zoom_id)) $errors[] = 'L\'ID Zoom est obligatoire.';
        if (empty($date_seance)) $errors[] = 'La date de la séance est obligatoire.';
        if (empty($heure_debut)) $errors[] = 'L\'heure de début est obligatoire.';
        if ($duree_minutes <= 0) $errors[] = 'La durée doit être supérieure à 0 minutes.';
        
        // Validation de la date
        $date_obj = DateTime::createFromFormat('Y-m-d', $date_seance);
        if (!$date_obj || $date_obj < new DateTime()) {
            $errors[] = 'La date doit être supérieure à aujourd\'hui.';
        }

        // Gestion du fichier vidéo (enregistrement Zoom)
        $video_url = null;
        if (isset($_FILES['video_zoom']) && $_FILES['video_zoom']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/zoom/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            $file_extension = strtolower(pathinfo($_FILES['video_zoom']['name'], PATHINFO_EXTENSION));
            $allowed_extensions = ['mp4', 'mov', 'avi', 'mkv', 'webm'];
            $max_size = 500 * 1024 * 1024; // 500 MB

            if (!in_array($file_extension, $allowed_extensions)) {
                $errors[] = 'Type de fichier vidéo non autorisé. Extensions acceptées: ' . implode(', ', $allowed_extensions);
            } elseif ($_FILES['video_zoom']['size'] > $max_size) {
                $errors[] = 'La vidéo ne doit pas dépasser 500 MB.';
            } else {
                $new_filename = uniqid('zoom_') . '.' . $file_extension;
                $upload_path = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES['video_zoom']['tmp_name'], $upload_path)) {
                    $video_url = 'uploads/zoom/' . $new_filename;
                } else {
                    $errors[] = 'Erreur lors du téléchargement de la vidéo.';
                }
            }
        }

        if (empty($errors)) {
            try {
                $insert_query = "INSERT INTO seances_zoom 
                               (titre, description, date_seance, heure_debut, duree_minutes, 
                                zoom_url, zoom_id, zoom_password, video_url, classe_id, cours_id, 
                                enseignant_id, date_creation)
                               VALUES 
                               (:titre, :description, :date_seance, :heure_debut, :duree_minutes,
                                :zoom_url, :zoom_id, :zoom_password, :video_url, :classe_id, :cours_id,
                                :enseignant_id, NOW())";
                
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':titre', $titre);
                $insert_stmt->bindParam(':description', $description);
                $insert_stmt->bindParam(':date_seance', $date_seance);
                $insert_stmt->bindParam(':heure_debut', $heure_debut);
                $insert_stmt->bindParam(':duree_minutes', $duree_minutes);
                $insert_stmt->bindParam(':zoom_url', $zoom_url);
                $insert_stmt->bindParam(':zoom_id', $zoom_id);
                $insert_stmt->bindParam(':zoom_password', $zoom_password);
                $insert_stmt->bindParam(':video_url', $video_url);
                $insert_stmt->bindParam(':classe_id', $classe_id);
                $insert_stmt->bindParam(':cours_id', $cours_id);
                $insert_stmt->bindParam(':enseignant_id', $user_id);
                $insert_stmt->execute();

                $seance_id = $conn->lastInsertId();

                // Notifier les étudiants de la classe
                if ($classe_id) {
                    $students_query = "SELECT i.user_id FROM inscriptions i WHERE i.classe_id = :classe_id AND i.statut IN ('inscrit', 'reinscrit')";
                    $students_stmt = $conn->prepare($students_query);
                    $students_stmt->bindParam(':classe_id', $classe_id);
                    $students_stmt->execute();
                    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($students as $student) {
                        $notification_query = "INSERT INTO notifications (user_id, titre, message, type, lien) 
                                              VALUES (:user_id, :titre, :message, :type, :lien)";
                        $notification_stmt = $conn->prepare($notification_query);
                        $notification_stmt->bindParam(':user_id', $student['user_id']);
                        $notification_stmt->bindValue(':titre', 'Nouvelle séance Zoom');
                        $notification_stmt->bindValue(':message', "Une nouvelle séance Zoom \"$titre\" a été programmée");
                        $notification_stmt->bindValue(':type', 'zoom');
                        $notification_stmt->bindValue(':lien', '../etudiant/seances_zoom.php?id=' . $seance_id);
                        $notification_stmt->execute();
                    }
                }

                $messages[] = ['type' => 'success', 'text' => 'Séance Zoom créée avec succès !'];

                // Recharger les séances
                $seances_stmt->execute();
                $seances = $seances_stmt->fetchAll(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la création: ' . $e->getMessage()];
            }
        } else {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
            }
        }
    } elseif ($_POST['action'] === 'supprimer_seance') {
        $seance_id = intval(sanitize($_POST['seance_id']));

        try {
            // Récupérer les infos de la séance
            $seance_query = "SELECT video_url FROM seances_zoom WHERE id = :id AND enseignant_id = :enseignant_id";
            $seance_stmt = $conn->prepare($seance_query);
            $seance_stmt->bindParam(':id', $seance_id);
            $seance_stmt->bindParam(':enseignant_id', $user_id);
            $seance_stmt->execute();
            $seance = $seance_stmt->fetch(PDO::FETCH_ASSOC);

            if ($seance) {
                // Supprimer le fichier vidéo
                if ($seance['video_url'] && file_exists($seance['video_url'])) {
                    unlink($seance['video_url']);
                }

                // Supprimer la séance
                $delete_query = "DELETE FROM seances_zoom WHERE id = :id AND enseignant_id = :enseignant_id";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->bindParam(':id', $seance_id);
                $delete_stmt->bindParam(':enseignant_id', $user_id);
                $delete_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Séance Zoom supprimée avec succès.'];

                // Recharger les séances
                $seances_stmt->execute();
                $seances = $seances_stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Séance non trouvée.'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la suppression: ' . $e->getMessage()];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Séances Zoom - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chalkboard-user text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Enseignant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
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
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar mr-1"></i>Emploi du temps
                </a>
                <a href="seances_zoom.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-video mr-1"></i>Séances Zoom
                </a>
                <a href="ressources.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-book mr-1"></i>Ressources
                </a>
                <a href="presence.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-clipboard-check mr-1"></i>Présence
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-chart mr-1"></i>Notes
                </a>
            </div>
        </div>
    </nav>

    <!-- Messages d'alerte -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
        <?php foreach ($messages as $msg): ?>
            <?php echo alert($msg['text'], $msg['type']); ?>
        <?php endforeach; ?>
    </div>

    <!-- Contenu principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Formulaire d'ajout -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-lg shadow-md p-6 sticky top-4">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-plus mr-2"></i>Nouvelle séance Zoom
                    </h2>
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="ajouter_seance">
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Titre *</label>
                            <input type="text" name="titre" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="ex: Cours de mathématiques">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <textarea name="description" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" rows="3" placeholder="Contenu de la séance..."></textarea>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Cours</label>
                            <select name="cours_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Sélectionner un cours --</option>
                                <?php foreach ($cours_list as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom_cours']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Classe</label>
                            <select name="classe_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">-- Sélectionner une classe --</option>
                                <?php foreach ($classes_list as $cl): ?>
                                    <option value="<?php echo $cl['id']; ?>"><?php echo htmlspecialchars($cl['nom_filiere'] . ' - ' . $cl['nom_classe']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Date *</label>
                            <input type="date" name="date_seance" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Heure de début *</label>
                            <input type="time" name="heure_debut" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Durée (minutes) *</label>
                            <input type="number" name="duree_minutes" required min="1" value="60" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">URL Zoom *</label>
                            <input type="url" name="zoom_url" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="https://zoom.us/j/...">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">ID Zoom *</label>
                            <input type="text" name="zoom_id" required class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="ex: 123456789">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Mot de passe Zoom (optionnel)</label>
                            <input type="text" name="zoom_password" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500" placeholder="Mot de passe si requis">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Vidéo enregistrée (optionnel)</label>
                            <input type="file" name="video_zoom" accept="video/*" class="w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100">
                            <p class="text-xs text-gray-500 mt-1">Max 500 MB (mp4, mov, avi, mkv, webm)</p>
                        </div>

                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-plus mr-2"></i>Créer la séance
                        </button>
                    </form>
                </div>
            </div>

            <!-- Liste des séances -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md overflow-hidden">
                    <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
                        <h2 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-list mr-2"></i>Mes séances Zoom (<?php echo count($seances); ?>)
                        </h2>
                    </div>

                    <div class="divide-y divide-gray-200">
                        <?php if (empty($seances)): ?>
                            <div class="px-6 py-8 text-center">
                                <i class="fas fa-video text-4xl text-gray-300 mb-4"></i>
                                <p class="text-gray-600">Aucune séance Zoom programmée</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($seances as $seance): ?>
                                <div class="px-6 py-4 hover:bg-gray-50 transition duration-200">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h3 class="text-base font-semibold text-gray-900">
                                                <i class="fas fa-video text-blue-600 mr-2"></i><?php echo htmlspecialchars($seance['titre']); ?>
                                            </h3>
                                            <p class="text-sm text-gray-600 mt-1">
                                                <?php echo htmlspecialchars($seance['description']); ?>
                                            </p>
                                            <div class="flex flex-wrap gap-4 mt-3 text-sm text-gray-700">
                                                <span>
                                                    <i class="fas fa-calendar mr-1 text-blue-600"></i>
                                                    <?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?>
                                                </span>
                                                <span>
                                                    <i class="fas fa-clock mr-1 text-blue-600"></i>
                                                    <?php echo $seance['heure_debut']; ?> (<?php echo $seance['duree_minutes']; ?> min)
                                                </span>
                                                <?php if ($seance['nom_classe']): ?>
                                                    <span>
                                                        <i class="fas fa-users mr-1 text-blue-600"></i>
                                                        <?php echo htmlspecialchars($seance['nom_classe']); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <span>
                                                    <i class="fas fa-eye mr-1 text-blue-600"></i>
                                                    <?php echo $seance['vues_count']; ?> vues
                                                </span>
                                            </div>
                                            <div class="flex gap-2 mt-4">
                                                <a href="<?php echo htmlspecialchars($seance['zoom_url']); ?>" target="_blank" class="text-sm text-white bg-blue-600 hover:bg-blue-700 px-3 py-1 rounded transition duration-200">
                                                    <i class="fas fa-external-link-alt mr-1"></i>Ouvrir Zoom
                                                </a>
                                                <?php if ($seance['video_url']): ?>
                                                    <a href="../<?php echo htmlspecialchars($seance['video_url']); ?>" target="_blank" class="text-sm text-white bg-green-600 hover:bg-green-700 px-3 py-1 rounded transition duration-200">
                                                        <i class="fas fa-play mr-1"></i>Vidéo
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <form method="POST" onsubmit="return confirm('Êtes-vous sûr ?');" class="ml-4">
                                            <input type="hidden" name="action" value="supprimer_seance">
                                            <input type="hidden" name="seance_id" value="<?php echo $seance['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 transition duration-200">
                                                <i class="fas fa-trash-alt text-lg"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
</body>
</html>
