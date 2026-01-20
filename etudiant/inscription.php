<?php
/**
 * Page d'inscription pour les étudiants
 * Permet de s'inscrire à une classe ou de changer de classe
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
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération de l'inscription actuelle
$current_inscription_query = "SELECT i.*, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                             FROM inscriptions i
                             JOIN classes c ON i.classe_id = c.id
                             JOIN filieres f ON c.filiere_id = f.id
                             JOIN departements d ON f.departement_id = d.id
                             WHERE i.user_id = :user_id AND i.statut IN ('inscrit', 'reinscrit')
                             ORDER BY i.annee_academique DESC LIMIT 1";
$current_inscription_stmt = $conn->prepare($current_inscription_query);
$current_inscription_stmt->bindParam(':user_id', $user_id);
$current_inscription_stmt->execute();
$current_inscription = $current_inscription_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des classes disponibles pour inscription
$available_classes_query = "SELECT c.*, f.nom as filiere_nom, d.nom as departement_nom,
                           COUNT(i.id) as nb_inscrits
                           FROM classes c
                           JOIN filieres f ON c.filiere_id = f.id
                           JOIN departements d ON f.departement_id = d.id
                           LEFT JOIN inscriptions i ON c.id = i.classe_id AND i.statut IN ('inscrit', 'reinscrit')
                           GROUP BY c.id
                           ORDER BY d.nom, f.nom, c.niveau";
$available_classes_stmt = $conn->prepare($available_classes_query);
$available_classes_stmt->execute();
$available_classes = $available_classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire d'inscription
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'inscrire') {
        $classe_id = sanitize($_POST['classe_id']);
        $annee_academique = date('Y') . '-' . (date('Y') + 1);

        // Vérifier si l'étudiant n'est pas déjà inscrit cette année
        $check_query = "SELECT COUNT(*) as count FROM inscriptions
                       WHERE user_id = :user_id AND annee_academique = :annee";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':user_id', $user_id);
        $check_stmt->bindParam(':annee', $annee_academique);
        $check_stmt->execute();
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing['count'] > 0) {
            $messages[] = ['type' => 'error', 'text' => 'Vous êtes déjà inscrit pour cette année académique.'];
        } else {
            try {
                $insert_query = "INSERT INTO inscriptions (user_id, classe_id, annee_academique, statut)
                               VALUES (:user_id, :classe_id, :annee, 'inscrit')";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bindParam(':user_id', $user_id);
                $insert_stmt->bindParam(':classe_id', $classe_id);
                $insert_stmt->bindParam(':annee', $annee_academique);
                $insert_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Votre inscription a été enregistrée avec succès.'];

                // Recharger les données
                $current_inscription_stmt->execute();
                $current_inscription = $current_inscription_stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'inscription: ' . $e->getMessage()];
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
    <title>Inscription - ISTI</title>
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
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($user['name']); ?></span>
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
                <a href="profil.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user mr-1"></i>Profil
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="inscription.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
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

        <!-- Inscription actuelle -->
        <?php if ($current_inscription): ?>
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-info-circle mr-2"></i>Votre inscription actuelle
            </h2>
            <div class="bg-blue-50 p-4 rounded-lg">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <h3 class="font-semibold text-gray-800">Classe</h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($current_inscription['niveau']); ?> - <?php echo htmlspecialchars($current_inscription['filiere_nom']); ?></p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Département</h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($current_inscription['departement_nom']); ?></p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Année académique</h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($current_inscription['annee_academique']); ?></p>
                    </div>
                    <div>
                        <h3 class="font-semibold text-gray-800">Statut</h3>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                            <?php echo $current_inscription['statut'] === 'inscrit' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                            <?php echo htmlspecialchars(ucfirst($current_inscription['statut'])); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Formulaire d'inscription -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-user-plus mr-2"></i>S'inscrire à une classe
            </h2>

            <?php if (empty($available_classes)): ?>
                <p class="text-gray-600">Aucune classe disponible pour le moment.</p>
            <?php else: ?>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="inscrire">

                    <div>
                        <label for="classe_id" class="block text-sm font-medium text-gray-700 mb-2">
                            Choisir une classe
                        </label>
                        <select name="classe_id" id="classe_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Sélectionnez une classe</option>
                            <?php
                            $current_dept = '';
                            foreach ($available_classes as $classe):
                                if ($current_dept !== $classe['departement_nom']):
                                    if ($current_dept !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($classe['departement_nom']) . '">';
                                    $current_dept = $classe['departement_nom'];
                                endif;
                            ?>
                                <option value="<?php echo $classe['id']; ?>">
                                    <?php echo htmlspecialchars($classe['niveau'] . ' - ' . $classe['filiere_nom'] . ' (' . $classe['nb_inscrits'] . ' étudiants)'); ?>
                                </option>
                            <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="bg-yellow-50 p-4 rounded-lg">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <i class="fas fa-info-circle text-yellow-400"></i>
                            </div>
                            <div class="ml-3">
                                <h3 class="text-sm font-medium text-yellow-800">
                                    Information importante
                                </h3>
                                <div class="mt-2 text-sm text-yellow-700">
                                    <p>
                                        L'inscription sera effectuée pour l'année académique <?php echo date('Y') . '-' . (date('Y') + 1); ?>.
                                        Assurez-vous de choisir la classe appropriée à votre niveau d'études.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            <i class="fas fa-paper-plane mr-2"></i>Confirmer l'inscription
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Classes disponibles -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-list mr-2"></i>Classes disponibles
            </h2>

            <?php if (empty($available_classes)): ?>
                <p class="text-gray-600">Aucune classe disponible pour le moment.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Département</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filière</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niveau</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiants inscrits</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($available_classes as $classe): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($classe['departement_nom']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($classe['filiere_nom']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($classe['niveau']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($classe['nb_inscrits']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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