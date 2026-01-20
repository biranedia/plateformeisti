<?php
/**
 * Gestion de la présence pour les enseignants
 * Permet de marquer la présence des étudiants aux cours
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

// Récupération des cours de l'enseignant pour aujourd'hui
$today = date('Y-m-d');
$cours_today_query = "SELECT c.id, c.nom_cours, cl.nom_classe, fi.nom,
                             e.heure_debut, e.heure_fin, e.salle
                      FROM cours c
                      JOIN enseignements e ON c.id = e.cours_id
                      JOIN classes cl ON e.classe_id = cl.id
                      JOIN filieres fi ON cl.filiere_id = fi.id
                      WHERE e.enseignant_id = :enseignant_id
                      AND DATE(e.date_cours) = :today
                      ORDER BY e.heure_debut";
$cours_today_stmt = $conn->prepare($cours_today_query);
$cours_today_stmt->bindParam(':enseignant_id', $user_id);
$cours_today_stmt->bindParam(':today', $today);
$cours_today_stmt->execute();
$cours_today = $cours_today_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des cours passés pour consultation
$cours_passes_query = "SELECT DISTINCT c.id, c.nom_cours, cl.nom_classe, fi.nom,
                              DATE(e.date_cours) as date_cours, e.heure_debut, e.heure_fin
                       FROM cours c
                       JOIN enseignements e ON c.id = e.cours_id
                       JOIN classes cl ON e.classe_id = cl.id
                       JOIN filieres fi ON cl.filiere_id = fi.id
                       WHERE e.enseignant_id = :enseignant_id
                       AND DATE(e.date_cours) < :today
                       ORDER BY e.date_cours DESC, e.heure_debut DESC
                       LIMIT 10";
$cours_passes_stmt = $conn->prepare($cours_passes_query);
$cours_passes_stmt->bindParam(':enseignant_id', $user_id);
$cours_passes_stmt->bindParam(':today', $today);
$cours_passes_stmt->execute();
$cours_passes = $cours_passes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire de présence
$messages = [];
$selected_cours = null;
$etudiants = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'charger_presence') {
        $cours_id = sanitize($_POST['cours_id']);
        $date_cours = sanitize($_POST['date_cours']);

        // Récupération des étudiants inscrits à ce cours
        $etudiants_query = "SELECT u.id, u.name, u.matricule,
                                   CASE WHEN p.present = 1 THEN 'present'
                                        WHEN p.present = 0 THEN 'absent'
                                        ELSE 'non-marque' END as statut_presence
                            FROM users u
                            JOIN inscriptions i ON u.id = i.etudiant_id
                            JOIN enseignements e ON i.classe_id = e.classe_id
                            LEFT JOIN presence p ON u.id = p.etudiant_id AND p.cours_id = e.cours_id AND DATE(p.date_cours) = :date_cours
                            WHERE e.cours_id = :cours_id AND e.enseignant_id = :enseignant_id AND i.statut = 'active'
                            ORDER BY u.name";
        $etudiants_stmt = $conn->prepare($etudiants_query);
        $etudiants_stmt->bindParam(':cours_id', $cours_id);
        $etudiants_stmt->bindParam(':date_cours', $date_cours);
        $etudiants_stmt->bindParam(':enseignant_id', $user_id);
        $etudiants_stmt->execute();
        $etudiants = $etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupération des infos du cours sélectionné
        $cours_info_query = "SELECT c.nom_cours, cl.nom_classe, fi.nom, e.heure_debut, e.heure_fin, e.salle
                            FROM cours c
                            JOIN enseignements e ON c.id = e.cours_id
                            JOIN classes cl ON e.classe_id = cl.id
                            JOIN filieres fi ON cl.filiere_id = fi.id
                            WHERE c.id = :cours_id AND e.enseignant_id = :enseignant_id
                            LIMIT 1";
        $cours_info_stmt = $conn->prepare($cours_info_query);
        $cours_info_stmt->bindParam(':cours_id', $cours_id);
        $cours_info_stmt->bindParam(':enseignant_id', $user_id);
        $cours_info_stmt->execute();
        $selected_cours = $cours_info_stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($_POST['action'] === 'sauvegarder_presence') {
        $cours_id = sanitize($_POST['cours_id']);
        $date_cours = sanitize($_POST['date_cours']);
        $presences = $_POST['presence'] ?? [];

        try {
            // Supprimer les présences existantes pour ce cours et cette date
            $delete_query = "DELETE FROM presence WHERE cours_id = :cours_id AND DATE(date_cours) = :date_cours";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':cours_id', $cours_id);
            $delete_stmt->bindParam(':date_cours', $date_cours);
            $delete_stmt->execute();

            // Insérer les nouvelles présences
            $insert_query = "INSERT INTO presence (etudiant_id, cours_id, date_cours, present, enseignant_id)
                           VALUES (:etudiant_id, :cours_id, :date_cours, :present, :enseignant_id)";
            $insert_stmt = $conn->prepare($insert_query);

            $total_etudiants = 0;
            $presents = 0;

            foreach ($presences as $etudiant_id => $statut) {
                $present = ($statut === 'present') ? 1 : 0;
                $total_etudiants++;
                if ($present) $presents++;

                $insert_stmt->bindParam(':etudiant_id', $etudiant_id);
                $insert_stmt->bindParam(':cours_id', $cours_id);
                $insert_stmt->bindParam(':date_cours', $date_cours);
                $insert_stmt->bindParam(':present', $present);
                $insert_stmt->bindParam(':enseignant_id', $user_id);
                $insert_stmt->execute();
            }

            $messages[] = ['type' => 'success', 'text' => "Présence enregistrée avec succès ! $presents/$total_etudiants étudiants présents."];

            // Recharger les étudiants pour afficher les changements
            $etudiants_query = "SELECT u.id, u.name, u.matricule,
                                       CASE WHEN p.present = 1 THEN 'present'
                                            WHEN p.present = 0 THEN 'absent'
                                            ELSE 'non-marque' END as statut_presence
                                FROM users u
                                JOIN inscriptions i ON u.id = i.etudiant_id
                                JOIN enseignements e ON i.classe_id = e.classe_id
                                LEFT JOIN presence p ON u.id = p.etudiant_id AND p.cours_id = e.cours_id AND DATE(p.date_cours) = :date_cours
                                WHERE e.cours_id = :cours_id AND e.enseignant_id = :enseignant_id AND i.statut = 'active'
                                ORDER BY u.name";
            $etudiants_stmt = $conn->prepare($etudiants_query);
            $etudiants_stmt->bindParam(':cours_id', $cours_id);
            $etudiants_stmt->bindParam(':date_cours', $date_cours);
            $etudiants_stmt->bindParam(':enseignant_id', $user_id);
            $etudiants_stmt->execute();
            $etudiants = $etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la sauvegarde: ' . $e->getMessage()];
        }
    }
}

// Statistiques de présence
$stats_query = "SELECT
    COUNT(DISTINCT p.etudiant_id) as total_etudiants,
    SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) as total_presents,
    COUNT(p.id) as total_presences
FROM presence p
WHERE p.enseignant_id = :enseignant_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':enseignant_id', $user_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$taux_presence = $stats['total_presences'] > 0 ? ($stats['total_presents'] / $stats['total_presences']) * 100 : 0;
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion Présence - ISTI</title>
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
                <a href="presence.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-user-check mr-1"></i>Présence
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="ressources.php" class="text-gray-600 hover:text-green-600">
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
                <i class="fas fa-chart-bar mr-2"></i>Statistiques de présence
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-users text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Étudiants suivis</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_etudiants'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">étudiants uniques</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total présences</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_presents'] ?? 0; ?></p>
                    <p class="text-sm text-gray-600">sur <?php echo $stats['total_presences'] ?? 0; ?> marquages</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-percentage text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Taux de présence</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($taux_presence, 1); ?>%</p>
                    <p class="text-sm text-gray-600">moyenne générale</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Cours d'aujourd'hui -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-calendar-day mr-2"></i>Cours d'aujourd'hui
                    </h2>

                    <?php if (empty($cours_today)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-calendar-times text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucun cours prévu aujourd'hui.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($cours_today as $cours): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($cours['nom_cours']); ?></h3>
                                    <span class="px-2 py-1 bg-green-100 text-green-800 text-xs font-semibold rounded-full">
                                        Aujourd'hui
                                    </span>
                                </div>
                                <div class="text-sm text-gray-600 space-y-1">
                                    <p><i class="fas fa-users mr-2"></i><?php echo htmlspecialchars($cours['nom_classe']); ?> - <?php echo htmlspecialchars($cours['nom']); ?></p>
                                    <p><i class="fas fa-clock mr-2"></i><?php echo htmlspecialchars($cours['heure_debut']); ?> - <?php echo htmlspecialchars($cours['heure_fin']); ?></p>
                                    <p><i class="fas fa-map-marker-alt mr-2"></i>Salle <?php echo htmlspecialchars($cours['salle']); ?></p>
                                </div>
                                <div class="mt-3">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="charger_presence">
                                        <input type="hidden" name="cours_id" value="<?php echo $cours['id']; ?>">
                                        <input type="hidden" name="date_cours" value="<?php echo $today; ?>">
                                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-1 px-3 rounded-md transition duration-200">
                                            <i class="fas fa-user-check mr-1"></i>Marquer présence
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cours passés récents -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-history mr-2"></i>Cours passés récents
                    </h2>

                    <?php if (empty($cours_passes)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-history text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucun cours passé trouvé.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($cours_passes as $cours): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($cours['nom_cours']); ?></h3>
                                    <span class="px-2 py-1 bg-gray-100 text-gray-800 text-xs font-semibold rounded-full">
                                        <?php echo htmlspecialchars(date('d/m', strtotime($cours['date_cours']))); ?>
                                    </span>
                                </div>
                                <div class="text-sm text-gray-600 space-y-1">
                                    <p><i class="fas fa-users mr-2"></i><?php echo htmlspecialchars($cours['nom_classe']); ?> - <?php echo htmlspecialchars($cours['nom']); ?></p>
                                    <p><i class="fas fa-clock mr-2"></i><?php echo htmlspecialchars($cours['heure_debut']); ?> - <?php echo htmlspecialchars($cours['heure_fin']); ?></p>
                                </div>
                                <div class="mt-3">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="charger_presence">
                                        <input type="hidden" name="cours_id" value="<?php echo $cours['id']; ?>">
                                        <input type="hidden" name="date_cours" value="<?php echo $cours['date_cours']; ?>">
                                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium py-1 px-3 rounded-md transition duration-200">
                                            <i class="fas fa-eye mr-1"></i>Voir présence
                                        </button>
                                    </form>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Gestion de la présence -->
        <?php if (!empty($etudiants)): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-user-check mr-2"></i>Gestion de la présence
                </h2>
                <?php if ($selected_cours): ?>
                <div class="text-sm text-gray-600">
                    <strong><?php echo htmlspecialchars($selected_cours['nom_cours']); ?></strong> -
                    <?php echo htmlspecialchars($selected_cours['nom_classe']); ?> -
                    <?php echo htmlspecialchars($selected_cours['heure_debut']); ?>-<?php echo htmlspecialchars($selected_cours['heure_fin']); ?>
                </div>
                <?php endif; ?>
            </div>

            <form method="POST">
                <input type="hidden" name="action" value="sauvegarder_presence">
                <input type="hidden" name="cours_id" value="<?php echo $_POST['cours_id'] ?? ''; ?>">
                <input type="hidden" name="date_cours" value="<?php echo $_POST['date_cours'] ?? ''; ?>">

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Étudiant
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Matricule
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($etudiants as $etudiant): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-10 w-10">
                                            <div class="h-10 w-10 rounded-full bg-gray-300 flex items-center justify-center">
                                                <i class="fas fa-user text-gray-600"></i>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($etudiant['name']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($etudiant['matricule'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php echo $etudiant['statut_presence'] === 'present' ? 'bg-green-100 text-green-800' :
                                                 ($etudiant['statut_presence'] === 'absent' ? 'bg-red-100 text-red-800' :
                                                 'bg-gray-100 text-gray-800'); ?>">
                                        <?php echo $etudiant['statut_presence'] === 'present' ? 'Présent' :
                                                 ($etudiant['statut_presence'] === 'absent' ? 'Absent' :
                                                 'Non marqué'); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="presence[<?php echo $etudiant['id']; ?>]" value="present"
                                                   <?php echo $etudiant['statut_presence'] === 'present' ? 'checked' : ''; ?>
                                                   class="form-radio h-4 w-4 text-green-600">
                                            <span class="ml-2 text-sm text-gray-700">Présent</span>
                                        </label>
                                        <label class="inline-flex items-center">
                                            <input type="radio" name="presence[<?php echo $etudiant['id']; ?>]" value="absent"
                                                   <?php echo $etudiant['statut_presence'] === 'absent' ? 'checked' : ''; ?>
                                                   class="form-radio h-4 w-4 text-red-600">
                                            <span class="ml-2 text-sm text-gray-700">Absent</span>
                                        </label>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mt-6 flex justify-end space-x-3">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-save mr-2"></i>Sauvegarder la présence
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <!-- Informations sur la présence -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Gestion de la présence
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Marquez la présence au début de chaque cours pour un suivi précis</li>
                            <li>Vous pouvez modifier la présence après coup si nécessaire</li>
                            <li>Les statistiques de présence sont visibles par les étudiants et l'administration</li>
                            <li>Un taux de présence faible peut nécessiter une intervention pédagogique</li>
                            <li>En cas d'absence justifiée, notez-le dans les commentaires du cours</li>
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
</body>
</html>