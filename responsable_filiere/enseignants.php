<?php
/**
 * Gestion des enseignants pour les responsables de filière
 * Permet d'assigner des enseignants aux cours et de gérer les plannings
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('responsable_filiere')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de filière pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération de la filière du responsable
$filiere_query = "SELECT f.* FROM filieres f
                 JOIN responsables_filiere rf ON f.id = rf.filiere_id
                 WHERE rf.user_id = :user_id";
$filiere_stmt = $conn->prepare($filiere_query);
$filiere_stmt->bindParam(':user_id', $user_id);
$filiere_stmt->execute();
$filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

if (!$filiere) {
    die("Erreur: Filière non trouvée pour ce responsable.");
}

// Récupération des enseignants de la filière
$enseignants_query = "SELECT u.id, u.name, u.email, e.specialite, e.grade,
                             COUNT(DISTINCT en.id) as nombre_cours
                     FROM users u
                     JOIN enseignants e ON u.id = e.user_id
                     LEFT JOIN enseignements en ON e.id = en.enseignant_id
                     LEFT JOIN cours c ON en.cours_id = c.id
                     WHERE c.filiere_id = :filiere_id OR en.id IS NULL
                     GROUP BY u.id, u.name, u.email, e.specialite, e.grade
                     ORDER BY u.name";
$enseignants_stmt = $conn->prepare($enseignants_query);
$enseignants_stmt->bindParam(':filiere_id', $filiere['id']);
$enseignants_stmt->execute();
$enseignants = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des cours de la filière
$cours_query = "SELECT c.id, c.nom_cours, c.description, c.credits,
                       COUNT(DISTINCT en.id) as nombre_enseignements,
                       GROUP_CONCAT(DISTINCT u.name SEPARATOR ', ') as enseignants
               FROM cours c
               LEFT JOIN enseignements en ON c.id = en.cours_id
               LEFT JOIN enseignants e ON en.enseignant_id = e.id
               LEFT JOIN users u ON e.user_id = u.id
               WHERE c.filiere_id = :filiere_id
               GROUP BY c.id, c.nom_cours, c.description, c.credits
               ORDER BY c.nom_cours";
$cours_stmt = $conn->prepare($cours_query);
$cours_stmt->bindParam(':filiere_id', $filiere['id']);
$cours_stmt->execute();
$cours = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des enseignements (affectations)
$enseignements_query = "SELECT en.id, c.nom_cours, cl.nom_classe, u.name,
                              en.heure_debut, en.heure_fin, en.salle, en.date_cours
                       FROM enseignements en
                       JOIN cours c ON en.cours_id = c.id
                       JOIN classes cl ON en.classe_id = cl.id
                       JOIN enseignants e ON en.enseignant_id = e.id
                       JOIN users u ON e.user_id = u.id
                       WHERE c.filiere_id = :filiere_id
                       ORDER BY en.date_cours DESC, en.heure_debut DESC";
$enseignements_stmt = $conn->prepare($enseignements_query);
$enseignements_stmt->bindParam(':filiere_id', $filiere['id']);
$enseignements_stmt->execute();
$enseignements = $enseignements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes de la filière
$classes_query = "SELECT cl.id, cl.nom_classe, cl.niveau,
                        COUNT(DISTINCT i.etudiant_id) as nombre_etudiants
                 FROM classes cl
                 LEFT JOIN inscriptions i ON cl.id = i.classe_id AND i.statut = 'active'
                 WHERE cl.filiere_id = :filiere_id
                 GROUP BY cl.id, cl.nom_classe, cl.niveau
                 ORDER BY cl.niveau, cl.nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere_id', $filiere['id']);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire d'affectation d'enseignant
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'affecter_enseignant') {
        $cours_id = sanitize($_POST['cours_id']);
        $enseignant_id = sanitize($_POST['enseignant_id']);
        $classe_id = sanitize($_POST['classe_id']);
        $heure_debut = sanitize($_POST['heure_debut']);
        $heure_fin = sanitize($_POST['heure_fin']);
        $salle = sanitize($_POST['salle']);
        $date_cours = sanitize($_POST['date_cours']);

        // Validation
        $errors = [];
        if (empty($cours_id) || empty($enseignant_id) || empty($classe_id)) {
            $errors[] = 'Tous les champs obligatoires doivent être remplis.';
        }
        if (strtotime($heure_debut) >= strtotime($heure_fin)) {
            $errors[] = 'L\'heure de fin doit être postérieure à l\'heure de début.';
        }

        if (empty($errors)) {
            try {
                // Vérifier les conflits d'horaire pour l'enseignant
                $conflit_query = "SELECT COUNT(*) as count FROM enseignements
                                 WHERE enseignant_id = :enseignant_id
                                 AND date_cours = :date_cours
                                 AND ((heure_debut <= :heure_debut AND heure_fin > :heure_debut)
                                      OR (heure_debut < :heure_fin AND heure_fin >= :heure_fin)
                                      OR (heure_debut >= :heure_debut AND heure_fin <= :heure_fin))";
                $conflit_stmt = $conn->prepare($conflit_query);
                $conflit_stmt->bindParam(':enseignant_id', $enseignant_id);
                $conflit_stmt->bindParam(':date_cours', $date_cours);
                $conflit_stmt->bindParam(':heure_debut', $heure_debut);
                $conflit_stmt->bindParam(':heure_fin', $heure_fin);
                $conflit_stmt->execute();
                $conflit = $conflit_stmt->fetch(PDO::FETCH_ASSOC);

                if ($conflit['count'] > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Conflit d\'horaire détecté pour cet enseignant.'];
                } else {
                    $insert_query = "INSERT INTO enseignements (cours_id, enseignant_id, classe_id,
                                   heure_debut, heure_fin, salle, date_cours)
                                   VALUES (:cours_id, :enseignant_id, :classe_id,
                                   :heure_debut, :heure_fin, :salle, :date_cours)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':cours_id', $cours_id);
                    $insert_stmt->bindParam(':enseignant_id', $enseignant_id);
                    $insert_stmt->bindParam(':classe_id', $classe_id);
                    $insert_stmt->bindParam(':heure_debut', $heure_debut);
                    $insert_stmt->bindParam(':heure_fin', $heure_fin);
                    $insert_stmt->bindParam(':salle', $salle);
                    $insert_stmt->bindParam(':date_cours', $date_cours);
                    $insert_stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Enseignant affecté avec succès au cours.'];

                    // Recharger les données
                    $enseignants_stmt->execute();
                    $enseignants = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);
                    $cours_stmt->execute();
                    $cours = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);
                    $enseignements_stmt->execute();
                    $enseignements = $enseignements_stmt->fetchAll(PDO::FETCH_ASSOC);
                }

            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'affectation: ' . $e->getMessage()];
            }
        } else {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
            }
        }
    } elseif ($_POST['action'] === 'supprimer_affectation') {
        $enseignement_id = sanitize($_POST['enseignement_id']);

        try {
            $delete_query = "DELETE FROM enseignements WHERE id = :id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':id', $enseignement_id);
            $delete_stmt->execute();

            $messages[] = ['type' => 'success', 'text' => 'Affectation supprimée avec succès.'];

            // Recharger les données
            $enseignants_stmt->execute();
            $enseignants = $enseignants_stmt->fetchAll(PDO::FETCH_ASSOC);
            $cours_stmt->execute();
            $cours = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);
            $enseignements_stmt->execute();
            $enseignements = $enseignements_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Gestion Enseignants - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-purple-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-user-tie text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Filière</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Filière: <?php echo htmlspecialchars($filiere['nom_filiere']); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-users mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-purple-600 border-b-2 border-purple-600 pb-2">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded <?php echo $message['type'] === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700'; ?> border">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Affecter un enseignant -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-user-plus mr-2"></i>Affecter un enseignant
                    </h2>

                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="affecter_enseignant">

                        <div>
                            <label for="cours_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Cours *
                            </label>
                            <select name="cours_id" id="cours_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                                <option value="">Sélectionnez un cours</option>
                                <?php foreach ($cours as $c): ?>
                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nom_cours']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="enseignant_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Enseignant *
                            </label>
                            <select name="enseignant_id" id="enseignant_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                                <option value="">Sélectionnez un enseignant</option>
                                <?php foreach ($enseignants as $ens): ?>
                                    <option value="<?php echo $ens['id']; ?>">
                                        <?php echo htmlspecialchars($ens['name'] . ' (' . $ens['specialite'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="classe_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Classe *
                            </label>
                            <select name="classe_id" id="classe_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                                <option value="">Sélectionnez une classe</option>
                                <?php foreach ($classes as $cl): ?>
                                    <option value="<?php echo $cl['id']; ?>">
                                        <?php echo htmlspecialchars($cl['nom_classe'] . ' (' . $cl['nombre_etudiants'] . ' étudiants)'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="heure_debut" class="block text-sm font-medium text-gray-700 mb-1">
                                    Heure début *
                                </label>
                                <input type="time" name="heure_debut" id="heure_debut" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            <div>
                                <label for="heure_fin" class="block text-sm font-medium text-gray-700 mb-1">
                                    Heure fin *
                                </label>
                                <input type="time" name="heure_fin" id="heure_fin" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label for="salle" class="block text-sm font-medium text-gray-700 mb-1">
                                    Salle
                                </label>
                                <input type="text" name="salle" id="salle" placeholder="Ex: A101"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                            </div>
                            <div>
                                <label for="date_cours" class="block text-sm font-medium text-gray-700 mb-1">
                                    Date *
                                </label>
                                <input type="date" name="date_cours" id="date_cours" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-user-plus mr-2"></i>Affecter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Statistiques -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-chart-bar mr-2"></i>Statistiques
                    </h2>

                    <div class="space-y-4">
                        <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-chalkboard-teacher text-blue-600 text-2xl mr-3"></i>
                                <div>
                                    <p class="font-semibold text-gray-800">Enseignants</p>
                                    <p class="text-sm text-gray-600">affectés à la filière</p>
                                </div>
                            </div>
                            <span class="text-2xl font-bold text-blue-600"><?php echo count($enseignants); ?></span>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-green-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-book text-green-600 text-2xl mr-3"></i>
                                <div>
                                    <p class="font-semibold text-gray-800">Cours</p>
                                    <p class="text-sm text-gray-600">dans la filière</p>
                                </div>
                            </div>
                            <span class="text-2xl font-bold text-green-600"><?php echo count($cours); ?></span>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-yellow-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt text-yellow-600 text-2xl mr-3"></i>
                                <div>
                                    <p class="font-semibold text-gray-800">Séances</p>
                                    <p class="text-sm text-gray-600">de cours planifiées</p>
                                </div>
                            </div>
                            <span class="text-2xl font-bold text-yellow-600"><?php echo count($enseignements); ?></span>
                        </div>

                        <div class="flex items-center justify-between p-4 bg-purple-50 rounded-lg">
                            <div class="flex items-center">
                                <i class="fas fa-users text-purple-600 text-2xl mr-3"></i>
                                <div>
                                    <p class="font-semibold text-gray-800">Classes</p>
                                    <p class="text-sm text-gray-600">dans la filière</p>
                                </div>
                            </div>
                            <span class="text-2xl font-bold text-purple-600"><?php echo count($classes); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Liste des enseignements -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list mr-2"></i>Affectations actuelles
            </h2>

            <?php if (empty($enseignements)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar-times text-gray-300 text-4xl mb-4"></i>
                    <p class="text-gray-600">Aucune affectation d'enseignant pour le moment.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Cours
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Enseignant
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Classe
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Horaire
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Salle
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($enseignements as $ens): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($ens['nom_cours']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="flex-shrink-0 h-8 w-8">
                                            <div class="h-8 w-8 rounded-full bg-gray-300 flex items-center justify-center">
                                                <i class="fas fa-user text-gray-600"></i>
                                            </div>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($ens['nom'] . ' ' . $ens['prenom']); ?>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($ens['nom_classe']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <div><?php echo htmlspecialchars(date('d/m/Y', strtotime($ens['date_cours']))); ?></div>
                                    <div class="text-gray-500"><?php echo htmlspecialchars($ens['heure_debut'] . ' - ' . $ens['heure_fin']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($ens['salle'] ?? 'Non spécifiée'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <form method="POST" class="inline"
                                          onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette affectation ?')">
                                        <input type="hidden" name="action" value="supprimer_affectation">
                                        <input type="hidden" name="enseignement_id" value="<?php echo $ens['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 text-sm underline">
                                            <i class="fas fa-trash mr-1"></i>Supprimer
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Liste des enseignants -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-users mr-2"></i>Enseignants disponibles
            </h2>

            <?php if (empty($enseignants)): ?>
                <div class="text-center py-8">
                    <i class="fas fa-user-slash text-gray-300 text-4xl mb-4"></i>
                    <p class="text-gray-600">Aucun enseignant disponible.</p>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($enseignants as $ens): ?>
                    <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                        <div class="flex items-center mb-3">
                            <div class="flex-shrink-0 h-12 w-12">
                                <div class="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                                    <i class="fas fa-user-tie text-blue-600 text-lg"></i>
                                </div>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-800">
                                    <?php echo htmlspecialchars($ens['nom'] . ' ' . $ens['prenom']); ?>
                                </h3>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($ens['grade'] ?? 'Enseignant'); ?></p>
                            </div>
                        </div>

                        <div class="space-y-2 text-sm text-gray-600">
                            <p><i class="fas fa-envelope mr-2"></i><?php echo htmlspecialchars($ens['email']); ?></p>
                            <?php if ($ens['specialite']): ?>
                                <p><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($ens['specialite']); ?></p>
                            <?php endif; ?>
                            <p><i class="fas fa-book mr-2"></i><?php echo $ens['nombre_cours']; ?> cours affecté(s)</p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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