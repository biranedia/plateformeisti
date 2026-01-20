<?php
/**
 * Gestion des filières - Responsable de département
 * Consultation et gestion des filières du département
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_dept')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de département pour accéder à cette page.', 'error');
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

// Récupération du département géré
$dept_query = "SELECT * FROM departements WHERE responsable_id = :user_id";
$dept_stmt = $conn->prepare($dept_query);
$dept_stmt->bindParam(':user_id', $user_id);
$dept_stmt->execute();
$departement = $dept_stmt->fetch(PDO::FETCH_ASSOC);

// Si pas de département assigné
if (!$departement) {
    echo "<div class='max-w-4xl mx-auto mt-10 p-6 bg-yellow-100 border border-yellow-400 text-yellow-700 rounded'>
            <h2 class='text-xl font-bold mb-2'>Aucun département assigné</h2>
            <p>Vous n'êtes pas encore assigné à un département. Veuillez contacter l'administration.</p>
            <a href='../shared/logout.php' class='mt-4 inline-block bg-blue-500 text-white px-4 py-2 rounded'>Retour</a>
          </div>";
    exit;
}

// Messages de succès ou d'erreur
$messages = [];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'add_filiere') {
            $nom_filiere = sanitize($_POST['nom_filiere']);
            $code_filiere = sanitize($_POST['code_filiere']);
            $description = sanitize($_POST['description']);
            $niveau = sanitize($_POST['niveau']);
            $duree_formation = (int)$_POST['duree_formation'];

            // Validation
            if (empty($nom_filiere) || empty($code_filiere)) {
                $messages[] = ['type' => 'error', 'text' => 'Le nom et le code de la filière sont obligatoires.'];
            } else {
                try {
                    // Vérifier si le code existe déjà
                    $check_query = "SELECT id FROM filieres WHERE code_filiere = :code AND departement_id = :dept_id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':code', $code_filiere);
                    $check_stmt->bindParam(':dept_id', $departement['id']);
                    $check_stmt->execute();

                    if ($check_stmt->rowCount() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Ce code de filière existe déjà dans votre département.'];
                    } else {
                        // Ajouter la filière
                        $query = "INSERT INTO filieres (nom_filiere, code_filiere, description, niveau, duree_formation, departement_id, created_at)
                                 VALUES (:nom, :code, :description, :niveau, :duree, :dept_id, NOW())";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':nom', $nom_filiere);
                        $stmt->bindParam(':code', $code_filiere);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':niveau', $niveau);
                        $stmt->bindParam(':duree', $duree_formation);
                        $stmt->bindParam(':dept_id', $departement['id']);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Filière ajoutée avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Ajout de la filière: $nom_filiere", "filieres");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'edit_filiere') {
            $filiere_id = (int)$_POST['filiere_id'];
            $nom_filiere = sanitize($_POST['nom_filiere']);
            $code_filiere = sanitize($_POST['code_filiere']);
            $description = sanitize($_POST['description']);
            $niveau = sanitize($_POST['niveau']);
            $duree_formation = (int)$_POST['duree_formation'];

            // Validation
            if (empty($nom_filiere) || empty($code_filiere)) {
                $messages[] = ['type' => 'error', 'text' => 'Le nom et le code de la filière sont obligatoires.'];
            } else {
                try {
                    // Vérifier si le code existe déjà (sauf pour cette filière)
                    $check_query = "SELECT id FROM filieres WHERE code_filiere = :code AND departement_id = :dept_id AND id != :id";
                    $check_stmt = $conn->prepare($check_query);
                    $check_stmt->bindParam(':code', $code_filiere);
                    $check_stmt->bindParam(':dept_id', $departement['id']);
                    $check_stmt->bindParam(':id', $filiere_id);
                    $check_stmt->execute();

                    if ($check_stmt->rowCount() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Ce code de filière existe déjà dans votre département.'];
                    } else {
                        // Mettre à jour la filière
                        $query = "UPDATE filieres SET nom_filiere = :nom, code_filiere = :code, description = :description,
                                 niveau = :niveau, duree_formation = :duree, updated_at = NOW()
                                 WHERE id = :id AND departement_id = :dept_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':nom', $nom_filiere);
                        $stmt->bindParam(':code', $code_filiere);
                        $stmt->bindParam(':description', $description);
                        $stmt->bindParam(':niveau', $niveau);
                        $stmt->bindParam(':duree', $duree_formation);
                        $stmt->bindParam(':id', $filiere_id);
                        $stmt->bindParam(':dept_id', $departement['id']);
                        $stmt->execute();

                        $messages[] = ['type' => 'success', 'text' => 'Filière mise à jour avec succès.'];

                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $user_id, "Modification de la filière: $nom_filiere", "filieres");
                    }
                } catch (Exception $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
                }
            }
        }

        if ($action === 'delete_filiere') {
            $filiere_id = (int)$_POST['filiere_id'];

            try {
                // Vérifier s'il y a des classes associées
                $check_query = "SELECT COUNT(*) as count FROM classes WHERE filiere_id = :id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':id', $filiere_id);
                $check_stmt->execute();
                $classes_count = $check_stmt->fetch(PDO::FETCH_ASSOC)['count'];

                if ($classes_count > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette filière car elle contient des classes.'];
                } else {
                    // Récupérer le nom avant suppression
                    $name_query = "SELECT nom_filiere FROM filieres WHERE id = :id AND departement_id = :dept_id";
                    $name_stmt = $conn->prepare($name_query);
                    $name_stmt->bindParam(':id', $filiere_id);
                    $name_stmt->bindParam(':dept_id', $departement['id']);
                    $name_stmt->execute();
                    $filiere_name = $name_stmt->fetch(PDO::FETCH_ASSOC)['nom_filiere'];

                    // Supprimer la filière
                    $query = "DELETE FROM filieres WHERE id = :id AND departement_id = :dept_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':id', $filiere_id);
                    $stmt->bindParam(':dept_id', $departement['id']);
                    $stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Filière supprimée avec succès.'];

                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $user_id, "Suppression de la filière: $filiere_name", "filieres");
                }
            } catch (Exception $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur: ' . $e->getMessage()];
            }
        }
    }
}

// Récupération des filières du département
$filieres_query = "SELECT f.*, COUNT(c.id) as classes_count
                   FROM filieres f
                   LEFT JOIN classes c ON f.id = c.filiere_id
                   WHERE f.departement_id = :dept_id
                   GROUP BY f.id
                   ORDER BY f.nom_filiere";
$filieres_stmt = $conn->prepare($filieres_query);
$filieres_stmt->bindParam(':dept_id', $departement['id']);
$filieres_stmt->execute();
$filieres = $filieres_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des filières
$stats_filieres = [
    'total' => count($filieres),
    'avec_classes' => count(array_filter($filieres, function($f) { return $f['classes_count'] > 0; })),
    'sans_classes' => count(array_filter($filieres, function($f) { return $f['classes_count'] == 0; }))
];
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Filières - ISTI</title>
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Département</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Département: <?php echo htmlspecialchars($departement['nom_departement']); ?></span>
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Resp. Dept'); ?></span>
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
                <a href="filieres.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-graduation-cap mr-1"></i>Filières
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="documents_a_valider.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents à valider
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback étudiants
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-8 bg-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message['type'] === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
                        </div>
                        <div class="ml-3">
                            <p><?php echo htmlspecialchars($message['text']); ?></p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-graduation-cap text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total filières</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_filieres['total']; ?></p>
                        <p class="text-sm text-gray-600">dans le département</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chalkboard text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Avec classes</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_filieres['avec_classes']; ?></p>
                        <p class="text-sm text-gray-600">filières actives</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-plus-circle text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Sans classes</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_filieres['sans_classes']; ?></p>
                        <p class="text-sm text-gray-600">filières à développer</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Actions -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex justify-between items-center">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-graduation-cap mr-2"></i>Gestion des filières
                </h2>
                <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                    <i class="fas fa-plus mr-2"></i>Ajouter une filière
                </button>
            </div>
        </div>

        <!-- Liste des filières -->
        <div class="bg-white rounded-lg shadow-md overflow-hidden">
            <?php if (empty($filieres)): ?>
                <div class="p-8 text-center">
                    <i class="fas fa-graduation-cap text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Aucune filière trouvée</h3>
                    <p class="text-gray-500 mb-4">Vous n'avez pas encore créé de filières dans votre département.</p>
                    <button onclick="openAddModal()" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Créer la première filière
                    </button>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filière</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niveau</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Durée</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classes</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($filieres as $filiere): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-10 w-10">
                                                <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                                                    <i class="fas fa-graduation-cap text-blue-600"></i>
                                                </div>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($filiere['nom_filiere']); ?>
                                                </div>
                                                <?php if ($filiere['description']): ?>
                                                    <div class="text-sm text-gray-500 truncate max-w-xs">
                                                        <?php echo htmlspecialchars(substr($filiere['description'], 0, 50)); ?>...
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                            <?php echo htmlspecialchars($filiere['code_filiere']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo htmlspecialchars($filiere['niveau']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $filiere['duree_formation']; ?> ans
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php echo $filiere['classes_count'] > 0 ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                            <?php echo $filiere['classes_count']; ?> classe<?php echo $filiere['classes_count'] > 1 ? 's' : ''; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="openEditModal(<?php echo $filiere['id']; ?>, '<?php echo addslashes($filiere['nom_filiere']); ?>', '<?php echo addslashes($filiere['code_filiere']); ?>', '<?php echo addslashes($filiere['description']); ?>', '<?php echo addslashes($filiere['niveau']); ?>', <?php echo $filiere['duree_formation']; ?>)"
                                                    class="text-indigo-600 hover:text-indigo-900">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <?php if ($filiere['classes_count'] == 0): ?>
                                                <button onclick="confirmDelete(<?php echo $filiere['id']; ?>, '<?php echo addslashes($filiere['nom_filiere']); ?>')"
                                                        class="text-red-600 hover:text-red-900">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal Ajouter Filière -->
    <div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-plus mr-2"></i>Ajouter une filière
                    </h3>
                    <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_filiere">

                    <div>
                        <label for="add_nom_filiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Nom de la filière *
                        </label>
                        <input type="text" id="add_nom_filiere" name="nom_filiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="add_code_filiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Code de la filière *
                        </label>
                        <input type="text" id="add_code_filiere" name="code_filiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="add_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description
                        </label>
                        <textarea id="add_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>

                    <div>
                        <label for="add_niveau" class="block text-sm font-medium text-gray-700 mb-2">
                            Niveau
                        </label>
                        <select id="add_niveau" name="niveau"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="Licence">Licence</option>
                            <option value="Master">Master</option>
                            <option value="Doctorat">Doctorat</option>
                            <option value="BTS">BTS</option>
                            <option value="DUT">DUT</option>
                        </select>
                    </div>

                    <div>
                        <label for="add_duree_formation" class="block text-sm font-medium text-gray-700 mb-2">
                            Durée de formation (années)
                        </label>
                        <input type="number" id="add_duree_formation" name="duree_formation" min="1" max="6" value="3" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closeAddModal()"
                                class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                            Ajouter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifier Filière -->
    <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-medium text-gray-900">
                        <i class="fas fa-edit mr-2"></i>Modifier la filière
                    </h3>
                    <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="edit_filiere">
                    <input type="hidden" id="edit_filiere_id" name="filiere_id">

                    <div>
                        <label for="edit_nom_filiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Nom de la filière *
                        </label>
                        <input type="text" id="edit_nom_filiere" name="nom_filiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_code_filiere" class="block text-sm font-medium text-gray-700 mb-2">
                            Code de la filière *
                        </label>
                        <input type="text" id="edit_code_filiere" name="code_filiere" required
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-2">
                            Description
                        </label>
                        <textarea id="edit_description" name="description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                    </div>

                    <div>
                        <label for="edit_niveau" class="block text-sm font-medium text-gray-700 mb-2">
                            Niveau
                        </label>
                        <select id="edit_niveau" name="niveau"
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="Licence">Licence</option>
                            <option value="Master">Master</option>
                            <option value="Doctorat">Doctorat</option>
                            <option value="BTS">BTS</option>
                            <option value="DUT">DUT</option>
                        </select>
                    </div>

                    <div>
                        <label for="edit_duree_formation" class="block text-sm font-medium text-gray-700 mb-2">
                            Durée de formation (années)
                        </label>
                        <input type="number" id="edit_duree_formation" name="duree_formation" min="1" max="6" required
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
        function openAddModal() {
            document.getElementById('addModal').classList.remove('hidden');
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.add('hidden');
            document.getElementById('add_nom_filiere').value = '';
            document.getElementById('add_code_filiere').value = '';
            document.getElementById('add_description').value = '';
            document.getElementById('add_niveau').selectedIndex = 0;
            document.getElementById('add_duree_formation').value = '3';
        }

        function openEditModal(id, nom, code, description, niveau, duree) {
            document.getElementById('edit_filiere_id').value = id;
            document.getElementById('edit_nom_filiere').value = nom;
            document.getElementById('edit_code_filiere').value = code;
            document.getElementById('edit_description').value = description;
            document.getElementById('edit_niveau').value = niveau;
            document.getElementById('edit_duree_formation').value = duree;
            document.getElementById('editModal').classList.remove('hidden');
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.add('hidden');
        }

        function confirmDelete(id, nom) {
            if (confirm('Êtes-vous sûr de vouloir supprimer la filière "' + nom + '" ? Cette action est irréversible.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_filiere">
                    <input type="hidden" name="filiere_id" value="${id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>