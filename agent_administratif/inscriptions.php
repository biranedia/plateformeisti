<?php
/**
 * Gestion des inscriptions - Agent Administratif
 * Permet de gérer les inscriptions des étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('agent_admin')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'agent administratif pour accéder à cette page.', 'error');
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

// Filtres
$filtre_annee = isset($_GET['annee']) ? $_GET['annee'] : date('Y') . '-' . (date('Y') + 1);
$filtre_classe = isset($_GET['classe']) ? $_GET['classe'] : '';
$filtre_statut = isset($_GET['statut']) ? $_GET['statut'] : '';

// Récupération des années académiques disponibles
$annees_query = "SELECT DISTINCT annee_academique FROM inscriptions ORDER BY annee_academique DESC";
$annees_stmt = $conn->prepare($annees_query);
$annees_stmt->execute();
$annees = $annees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes disponibles
$classes_query = "SELECT c.id, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                 FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN departements d ON f.departement_id = d.id
                 ORDER BY d.nom, f.nom, c.niveau";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Construction de la requête des inscriptions
$query = "SELECT i.*, u.name as etudiant_nom, u.email, u.phone,
                 c.niveau, f.nom as filiere_nom, d.nom as departement_nom
          FROM inscriptions i
          JOIN users u ON i.user_id = u.id
          JOIN classes c ON i.classe_id = c.id
          JOIN filieres f ON c.filiere_id = f.id
          JOIN departements d ON f.departement_id = d.id
          WHERE 1=1";

$params = [];

if ($filtre_annee) {
    $query .= " AND i.annee_academique = :annee";
    $params[':annee'] = $filtre_annee;
}

if ($filtre_classe) {
    $query .= " AND i.classe_id = :classe";
    $params[':classe'] = $filtre_classe;
}

if ($filtre_statut) {
    $query .= " AND i.statut = :statut";
    $params[':statut'] = $filtre_statut;
}

$query .= " ORDER BY i.date_inscription DESC";

$inscriptions_stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $inscriptions_stmt->bindValue($key, $value);
}
$inscriptions_stmt->execute();
$inscriptions = $inscriptions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques
$stats = [
    'total' => 0,
    'inscrit' => 0,
    'reinscrit' => 0,
    'abandon' => 0
];

foreach ($inscriptions as $inscription) {
    $stats['total']++;
    if (isset($stats[$inscription['statut']])) {
        $stats[$inscription['statut']]++;
    }
}

// Traitement des actions
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $inscription_id = sanitize($_POST['inscription_id']);

    if ($_POST['action'] === 'changer_statut') {
        $nouveau_statut = sanitize($_POST['statut']);

        try {
            $update_query = "UPDATE inscriptions SET statut = :statut WHERE id = :id";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':statut', $nouveau_statut);
            $update_stmt->bindParam(':id', $inscription_id);
            $update_stmt->execute();

            $messages[] = ['type' => 'success', 'text' => 'Le statut de l\'inscription a été mis à jour.'];

            // Recharger les données
            $inscriptions_stmt->execute();
            $inscriptions = $inscriptions_stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la mise à jour: ' . $e->getMessage()];
        }
    } elseif ($_POST['action'] === 'supprimer') {
        try {
            $delete_query = "DELETE FROM inscriptions WHERE id = :id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':id', $inscription_id);
            $delete_stmt->execute();

            $messages[] = ['type' => 'success', 'text' => 'L\'inscription a été supprimée.'];

            // Recharger les données
            $inscriptions_stmt->execute();
            $inscriptions = $inscriptions_stmt->fetchAll(PDO::FETCH_ASSOC);

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
    <title>Gestion des Inscriptions - ISTI</title>
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
                    <h1 class="text-xl font-bold">Plateforme ISTI - Agent Administratif</h1>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="inscriptions.php" class="text-purple-600 border-b-2 border-purple-600 pb-2">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="attestation_inscription.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-certificate mr-1"></i>Attestations
                </a>
                <a href="certificat_scolarite.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Certificats
                </a>
                <a href="releve_notes.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-chart-line mr-1"></i>Relevés
                </a>
                <a href="saisie_donnees.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-database mr-1"></i>Saisie données
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

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Total</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-user-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Inscrits</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['inscrit']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-redo text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Réinscrits</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['reinscrit']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-user-times text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Abandons</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['abandon']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-filter mr-2"></i>Filtres
            </h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="annee" class="block text-sm font-medium text-gray-700 mb-1">Année académique</label>
                    <select name="annee" id="annee"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                        <option value="">Toutes les années</option>
                        <?php foreach ($annees as $annee): ?>
                            <option value="<?php echo htmlspecialchars($annee['annee_academique']); ?>" <?php echo $filtre_annee === $annee['annee_academique'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['annee_academique']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="classe" class="block text-sm font-medium text-gray-700 mb-1">Classe</label>
                    <select name="classe" id="classe"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                        <option value="">Toutes les classes</option>
                        <?php
                        $current_dept = '';
                        foreach ($classes as $classe):
                            if ($current_dept !== $classe['departement_nom']):
                                if ($current_dept !== '') echo '</optgroup>';
                                echo '<optgroup label="' . htmlspecialchars($classe['departement_nom']) . '">';
                                $current_dept = $classe['departement_nom'];
                            endif;
                        ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $filtre_classe === (string)$classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['niveau'] . ' - ' . $classe['filiere_nom']); ?>
                            </option>
                        <?php endforeach; ?>
                        </optgroup>
                    </select>
                </div>

                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select name="statut" id="statut"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                        <option value="">Tous les statuts</option>
                        <option value="inscrit" <?php echo $filtre_statut === 'inscrit' ? 'selected' : ''; ?>>Inscrit</option>
                        <option value="reinscrit" <?php echo $filtre_statut === 'reinscrit' ? 'selected' : ''; ?>>Réinscrit</option>
                        <option value="abandon" <?php echo $filtre_statut === 'abandon' ? 'selected' : ''; ?>>Abandon</option>
                    </select>
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                    <a href="inscriptions.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md transition duration-200">
                        <i class="fas fa-times mr-2"></i>Effacer
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste des inscriptions -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-list mr-2"></i>Liste des inscriptions
            </h2>

            <?php if (empty($inscriptions)): ?>
                <p class="text-gray-600">Aucune inscription trouvée avec les filtres actuels.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($inscriptions as $inscription): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($inscription['etudiant_nom']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($inscription['email']); ?></div>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($inscription['niveau'] . ' - ' . $inscription['filiere_nom']); ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['annee_academique']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <?php
                                    $statut_classes = [
                                        'inscrit' => 'bg-green-100 text-green-800',
                                        'reinscrit' => 'bg-blue-100 text-blue-800',
                                        'abandon' => 'bg-red-100 text-red-800'
                                    ];
                                    $statut_class = isset($statut_classes[$inscription['statut']]) ? $statut_classes[$inscription['statut']] : 'bg-gray-100 text-gray-800';
                                    ?>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $statut_class; ?>">
                                        <?php echo htmlspecialchars(ucfirst($inscription['statut'])); ?>
                                    </span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['date_inscription']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm font-medium">
                                    <div class="flex space-x-2">
                                        <!-- Bouton changer statut -->
                                        <button onclick="openStatusModal(<?php echo $inscription['id']; ?>, '<?php echo htmlspecialchars($inscription['statut']); ?>')"
                                                class="text-purple-600 hover:text-purple-900">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <!-- Bouton supprimer -->
                                        <form method="POST" class="inline" onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette inscription ?')">
                                            <input type="hidden" name="action" value="supprimer">
                                            <input type="hidden" name="inscription_id" value="<?php echo $inscription['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-900">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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

    <!-- Modal pour changer le statut -->
    <div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <h3 class="text-lg font-medium text-gray-900 mb-4">Changer le statut de l'inscription</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="changer_statut">
                    <input type="hidden" name="inscription_id" id="modal_inscription_id">

                    <div class="mb-4">
                        <label for="modal_statut" class="block text-sm font-medium text-gray-700 mb-2">Nouveau statut</label>
                        <select name="statut" id="modal_statut" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-purple-500 focus:border-purple-500">
                            <option value="inscrit">Inscrit</option>
                            <option value="reinscrit">Réinscrit</option>
                            <option value="abandon">Abandon</option>
                        </select>
                    </div>

                    <div class="flex justify-end space-x-2">
                        <button type="button" onclick="closeStatusModal()"
                                class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md transition duration-200">
                            Annuler
                        </button>
                        <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-md transition duration-200">
                            Confirmer
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
        function openStatusModal(inscriptionId, currentStatus) {
            document.getElementById('modal_inscription_id').value = inscriptionId;
            document.getElementById('modal_statut').value = currentStatus;
            document.getElementById('statusModal').classList.remove('hidden');
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.add('hidden');
        }
    </script>
</body>
</html>