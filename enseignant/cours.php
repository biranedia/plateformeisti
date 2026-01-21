<?php
/**
 * Liste des cours/enseignements de l'enseignant
 */
session_start();
require_once '../config/database.php';
require_once '../config/utils.php';

if (!isLoggedIn() || !hasRole('enseignant')) {
    redirectWithMessage('../shared/login.php', "Accès non autorisé.", 'error');
}

$database = new Database();
$conn = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Récupérer les enseignements de l'enseignant avec infos classe/filière
$query = "SELECT e.id, e.matiere, e.volume_horaire,
                 c.id AS classe_id, c.nom_classe, c.niveau,
                 f.nom AS filiere_nom
          FROM enseignements e
          JOIN classes c ON e.classe_id = c.id
          JOIN filieres f ON c.filiere_id = f.id
          WHERE e.enseignant_id = :uid
          ORDER BY f.nom, c.niveau, c.nom_classe, e.matiere";
$stmt = $conn->prepare($query);
$stmt->bindParam(':uid', $user_id, PDO::PARAM_INT);
$stmt->execute();
$enseignements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total = count($enseignements);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes cours - Enseignant</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<header class="bg-green-600 text-white shadow-lg">
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

<nav class="bg-white shadow-sm">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex space-x-8 py-3">
            <a href="dashboard.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-tachometer-alt mr-1"></i>Dashboard</a>
            <a href="cours.php" class="text-green-600 border-b-2 border-green-600 pb-2"><i class="fas fa-book-open mr-1"></i>Cours</a>
            <a href="emploi_du_temps.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-calendar-alt mr-1"></i>Emploi du temps</a>
            <a href="seances_zoom.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-video mr-1"></i>Visio Zoom</a>
            <a href="ressources.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-book mr-1"></i>Ressources</a>
            <a href="presence.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-clipboard-check mr-1"></i>Présence</a>
            <a href="notes.php" class="text-gray-600 hover:text-green-600"><i class="fas fa-file-chart mr-1"></i>Notes</a>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Mes cours</h2>
            <p class="text-gray-500 text-sm"><?php echo $total; ?> cours assignés</p>
        </div>
        <a href="seances_zoom.php" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md text-sm font-medium">
            <i class="fas fa-video mr-2"></i>Planifier une visio
        </a>
    </div>

    <div class="bg-white rounded-lg shadow-md overflow-hidden">
        <?php if (empty($enseignements)): ?>
            <div class="px-6 py-12 text-center">
                <i class="fas fa-book-open text-5xl text-gray-300 mb-4"></i>
                <p class="text-gray-600 text-lg">Aucun cours assigné pour le moment.</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-200">
                <?php foreach ($enseignements as $ens): ?>
                    <div class="px-6 py-4 hover:bg-gray-50 transition duration-200">
                        <div class="flex items-start justify-between">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($ens['matiere']); ?></h3>
                                <p class="text-sm text-gray-600 mt-1">
                                    <i class="fas fa-graduation-cap text-green-600 mr-1"></i>
                                    <?php echo htmlspecialchars($ens['filiere_nom'] . ' - ' . $ens['niveau'] . ' - ' . $ens['nom_classe']); ?>
                                </p>
                                <p class="text-sm text-gray-500 mt-1">Volume horaire: <?php echo htmlspecialchars($ens['volume_horaire']); ?>h</p>
                            </div>
                            <div class="flex gap-2 flex-wrap justify-end">
                                <a href="presence.php?cours_id=<?php echo $ens['id']; ?>" class="text-xs bg-blue-50 text-blue-700 hover:bg-blue-100 px-3 py-1 rounded border border-blue-200">
                                    <i class="fas fa-user-check mr-1"></i>Présence
                                </a>
                                <a href="ressources.php?cours_id=<?php echo $ens['id']; ?>" class="text-xs bg-yellow-50 text-yellow-700 hover:bg-yellow-100 px-3 py-1 rounded border border-yellow-200">
                                    <i class="fas fa-book mr-1"></i>Ressources
                                </a>
                                <a href="seances_zoom.php?cours_id=<?php echo $ens['id']; ?>" class="text-xs bg-indigo-50 text-indigo-700 hover:bg-indigo-100 px-3 py-1 rounded border border-indigo-200">
                                    <i class="fas fa-video mr-1"></i>Visio
                                </a>
                                <a href="notes.php?cours_id=<?php echo $ens['id']; ?>" class="text-xs bg-green-50 text-green-700 hover:bg-green-100 px-3 py-1 rounded border border-green-200">
                                    <i class="fas fa-file-alt mr-1"></i>Notes
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
