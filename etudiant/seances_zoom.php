<?php
/**
 * Consultation des séances Zoom pour les étudiants
 * Affiche les séances Zoom disponibles et permet de les consulter
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('etudiant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'étudiant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération de la classe de l'étudiant
$classe_query = "SELECT i.classe_id FROM inscriptions i WHERE i.user_id = :user_id AND i.statut IN ('inscrit', 'reinscrit') LIMIT 1";
$classe_stmt = $conn->prepare($classe_query);
$classe_stmt->bindParam(':user_id', $user_id);
$classe_stmt->execute();
$classe = $classe_stmt->fetch(PDO::FETCH_ASSOC);
$classe_id = $classe ? $classe['classe_id'] : null;

// Récupération des séances Zoom de la classe
$seances_query = "SELECT sz.*, e.name as enseignant_nom, c.nom_cours,
                         CASE WHEN uv.user_id IS NOT NULL THEN 1 ELSE 0 END as vu
                  FROM seances_zoom sz
                  LEFT JOIN users e ON sz.enseignant_id = e.id
                  LEFT JOIN cours c ON sz.cours_id = c.id
                  LEFT JOIN user_vues_zoom uv ON sz.id = uv.seance_id AND uv.user_id = :user_id
                  WHERE sz.classe_id = :classe_id
                  ORDER BY sz.date_seance DESC";

$seances_stmt = $conn->prepare($seances_query);
$seances_stmt->bindParam(':user_id', $user_id);
$seances_stmt->bindParam(':classe_id', $classe_id ?? 0);
$seances_stmt->execute();
$seances = $seances_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération d'une séance spécifique si demandée
$seance = null;
if (isset($_GET['id'])) {
    $seance_id = intval($_GET['id']);
    $detail_query = "SELECT sz.*, e.name as enseignant_nom, c.nom_cours
                     FROM seances_zoom sz
                     LEFT JOIN users e ON sz.enseignant_id = e.id
                     LEFT JOIN cours c ON sz.cours_id = c.id
                     WHERE sz.id = :id AND sz.classe_id = :classe_id";
    $detail_stmt = $conn->prepare($detail_query);
    $detail_stmt->bindParam(':id', $seance_id);
    $detail_stmt->bindParam(':classe_id', $classe_id ?? 0);
    $detail_stmt->execute();
    $seance = $detail_stmt->fetch(PDO::FETCH_ASSOC);

    // Enregistrer la vue
    if ($seance) {
        $view_check = "SELECT id FROM user_vues_zoom WHERE seance_id = :seance_id AND user_id = :user_id";
        $view_stmt = $conn->prepare($view_check);
        $view_stmt->bindParam(':seance_id', $seance_id);
        $view_stmt->bindParam(':user_id', $user_id);
        $view_stmt->execute();

        if ($view_stmt->rowCount() === 0) {
            $insert_view = "INSERT INTO user_vues_zoom (seance_id, user_id, date_vue) VALUES (:seance_id, :user_id, NOW())";
            $insert_stmt = $conn->prepare($insert_view);
            $insert_stmt->bindParam(':seance_id', $seance_id);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->execute();
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
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Étudiant</h1>
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
            <div class="flex space-x-8 overflow-x-auto py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="profil.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-user mr-1"></i>Profil
                </a>
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-file-signature mr-1"></i>Inscription
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-calendar mr-1"></i>Emploi du temps
                </a>
                <a href="seances_zoom.php" class="text-blue-600 border-b-2 border-blue-600 pb-2 whitespace-nowrap">
                    <i class="fas fa-video mr-1"></i>Zoom
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-file mr-1"></i>Documents
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-blue-600 whitespace-nowrap">
                    <i class="fas fa-file-chart mr-1"></i>Notes
                </a>
            </div>
        </div>
    </nav>

    <!-- Contenu principal -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($seance): ?>
            <!-- Vue détail d'une séance -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 bg-blue-600 text-white">
                    <a href="seances_zoom.php" class="text-white hover:underline text-sm">
                        <i class="fas fa-arrow-left mr-1"></i>Retour
                    </a>
                    <h1 class="text-2xl font-bold mt-2">
                        <i class="fas fa-video mr-2"></i><?php echo htmlspecialchars($seance['titre']); ?>
                    </h1>
                </div>

                <div class="px-6 py-6">
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                        <!-- Contenu principal -->
                        <div class="lg:col-span-2">
                            <?php if ($seance['video_url'] && file_exists('../' . $seance['video_url'])): ?>
                                <div class="mb-6">
                                    <h2 class="text-lg font-semibold text-gray-800 mb-4">
                                        <i class="fas fa-play mr-2"></i>Vidéo enregistrée
                                    </h2>
                                    <video width="100%" height="400" controls class="rounded-lg bg-black">
                                        <source src="../<?php echo htmlspecialchars($seance['video_url']); ?>" type="video/mp4">
                                        Votre navigateur ne supporte pas la lecture de vidéo.
                                    </video>
                                </div>
                            <?php endif; ?>

                            <div class="mb-6">
                                <h2 class="text-lg font-semibold text-gray-800 mb-2">Description</h2>
                                <p class="text-gray-700 whitespace-pre-wrap"><?php echo htmlspecialchars($seance['description'] ?? 'Aucune description'); ?></p>
                            </div>

                            <div class="flex gap-4 flex-wrap">
                                <a href="<?php echo htmlspecialchars($seance['zoom_url']); ?>" target="_blank" class="inline-block text-white bg-blue-600 hover:bg-blue-700 px-6 py-3 rounded-md font-medium transition duration-200">
                                    <i class="fas fa-link mr-2"></i>Accéder à la séance Zoom
                                </a>
                                <?php if ($seance['zoom_password']): ?>
                                    <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4">
                                        <p class="text-sm text-gray-700">
                                            <strong>Mot de passe:</strong> <code class="bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($seance['zoom_password']); ?></code>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Sidebar avec infos -->
                        <div>
                            <div class="bg-gray-50 rounded-lg p-6">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Infos de la séance</h3>
                                
                                <div class="space-y-4 text-sm">
                                    <div>
                                        <p class="text-gray-600">Enseignant</p>
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($seance['enseignant_nom']); ?></p>
                                    </div>

                                    <?php if ($seance['nom_cours']): ?>
                                        <div>
                                            <p class="text-gray-600">Cours</p>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($seance['nom_cours']); ?></p>
                                        </div>
                                    <?php endif; ?>

                                    <div>
                                        <p class="text-gray-600">Date</p>
                                        <p class="font-medium text-gray-900"><?php echo date('d/m/Y', strtotime($seance['date_seance'])); ?></p>
                                    </div>

                                    <div>
                                        <p class="text-gray-600">Heure</p>
                                        <p class="font-medium text-gray-900"><?php echo $seance['heure_debut']; ?></p>
                                    </div>

                                    <div>
                                        <p class="text-gray-600">Durée</p>
                                        <p class="font-medium text-gray-900"><?php echo $seance['duree_minutes']; ?> minutes</p>
                                    </div>

                                    <div>
                                        <p class="text-gray-600">ID Zoom</p>
                                        <p class="font-medium text-gray-900"><code class="bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($seance['zoom_id']); ?></code></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Liste des séances -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 bg-blue-600 text-white">
                    <h1 class="text-2xl font-bold">
                        <i class="fas fa-video mr-2"></i>Mes séances Zoom
                    </h1>
                </div>

                <div class="divide-y divide-gray-200">
                    <?php if (empty($seances)): ?>
                        <div class="px-6 py-12 text-center">
                            <i class="fas fa-video text-5xl text-gray-300 mb-4"></i>
                            <p class="text-gray-600 text-lg">Aucune séance Zoom disponible</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($seances as $s): ?>
                            <div class="px-6 py-4 hover:bg-gray-50 transition duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <h3 class="text-lg font-semibold text-gray-900">
                                            <i class="fas fa-video text-blue-600 mr-2"></i><?php echo htmlspecialchars($s['titre']); ?>
                                            <?php if ($s['vu']): ?>
                                                <span class="ml-2 inline-block bg-green-100 text-green-800 text-xs px-2 py-1 rounded">
                                                    <i class="fas fa-check mr-1"></i>Vu
                                                </span>
                                            <?php endif; ?>
                                        </h3>
                                        <p class="text-gray-600 mt-1"><?php echo htmlspecialchars(substr($s['description'], 0, 100)); ?></p>
                                        
                                        <div class="flex flex-wrap gap-4 mt-3 text-sm text-gray-700">
                                            <span>
                                                <i class="fas fa-user-tie mr-1 text-blue-600"></i>
                                                <?php echo htmlspecialchars($s['enseignant_nom']); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-calendar mr-1 text-blue-600"></i>
                                                <?php echo date('d/m/Y', strtotime($s['date_seance'])); ?>
                                            </span>
                                            <span>
                                                <i class="fas fa-clock mr-1 text-blue-600"></i>
                                                <?php echo $s['heure_debut']; ?> (<?php echo $s['duree_minutes']; ?> min)
                                            </span>
                                        </div>

                                        <a href="seances_zoom.php?id=<?php echo $s['id']; ?>" class="inline-block mt-4 text-white bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded transition duration-200 text-sm font-medium">
                                            <i class="fas fa-eye mr-1"></i>Consulter
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
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
