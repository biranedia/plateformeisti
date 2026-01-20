<?php
/**
 * Gestion des nouvelles inscriptions - Agent Administratif
 * Permet de créer des inscriptions pour les étudiants dont les documents ont été validés
 */

session_start();
require_once '../config/database.php';
require_once '../config/utils.php';

if (!isLoggedIn() || !hasRole('agent_admin')) {
    redirectWithMessage('../shared/login.php', 'Accès non autorisé.', 'error');
}

$database = new Database();
$conn = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Récupération de l'utilisateur
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$messages = [];

// Traitement de la création d'une inscription
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'creer_inscription') {
        $etudiant_id = (int)$_POST['etudiant_id'];
        $classe_id = (int)$_POST['classe_id'];
        $annee_academique = sanitize($_POST['annee_academique']);
        $statut = 'inscrit';

        try {
            // Vérifier que l'étudiant n'a pas déjà une inscription pour cette année
            $check_query = "SELECT COUNT(*) as count FROM inscriptions 
                           WHERE user_id = :user_id AND annee_academique = :annee";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute([':user_id' => $etudiant_id, ':annee' => $annee_academique]);
            
            if ($check_stmt->fetch(PDO::FETCH_ASSOC)['count'] > 0) {
                $messages[] = ['type' => 'error', 'text' => 'Cet étudiant a déjà une inscription pour cette année académique.'];
            } else {
                // Créer l'inscription
                $insert_query = "INSERT INTO inscriptions (user_id, classe_id, annee_academique, statut, date_inscription)
                               VALUES (:user_id, :classe_id, :annee_academique, :statut, NOW())";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->execute([
                    ':user_id' => $etudiant_id,
                    ':classe_id' => $classe_id,
                    ':annee_academique' => $annee_academique,
                    ':statut' => $statut
                ]);

                // Mettre à jour l'inscription_id dans les documents
                $inscription_id = $conn->lastInsertId();
                $update_docs = "UPDATE documents_inscription 
                               SET inscription_id = :inscription_id 
                               WHERE user_id = :user_id AND statut = 'valide'";
                $update_stmt = $conn->prepare($update_docs);
                $update_stmt->execute([
                    ':inscription_id' => $inscription_id,
                    ':user_id' => $etudiant_id
                ]);

                $messages[] = ['type' => 'success', 'text' => 'Inscription créée avec succès !'];
            }
        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la création: ' . $e->getMessage()];
        }
    }
}

// Récupération des étudiants avec documents validés et sans inscription
$etudiants_query = "SELECT DISTINCT u.id, u.name, u.email, u.matricule, u.phone,
                           COUNT(DISTINCT d.id) as nb_docs_valides
                    FROM users u
                    JOIN user_roles ur ON u.id = ur.user_id
                    JOIN documents_inscription d ON u.id = d.user_id
                    LEFT JOIN inscriptions i ON u.id = i.user_id 
                        AND i.annee_academique = :annee_actuelle
                    WHERE ur.role = 'etudiant'
                        AND d.statut = 'valide'
                        AND i.id IS NULL
                    GROUP BY u.id
                    HAVING nb_docs_valides >= 2
                    ORDER BY u.name";

$annee_actuelle = date('Y') . '/' . (date('Y') + 1);
$etudiants_stmt = $conn->prepare($etudiants_query);
$etudiants_stmt->bindParam(':annee_actuelle', $annee_actuelle);
$etudiants_stmt->execute();
$etudiants_prets = $etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des classes disponibles
$classes_query = "SELECT c.id, c.nom_classe, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                 FROM classes c
                 JOIN filieres f ON c.filiere_id = f.id
                 JOIN departements d ON f.departement_id = d.id
                 ORDER BY d.nom, f.nom, c.niveau";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Grouper les classes par département
$classes_par_dept = [];
foreach ($classes as $classe) {
    $dept = $classe['departement_nom'];
    if (!isset($classes_par_dept[$dept])) {
        $classes_par_dept[$dept] = [];
    }
    $classes_par_dept[$dept][] = $classe;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nouvelles inscriptions - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <header class="bg-purple-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-user-tie text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Agent Administratif</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="../shared/logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="validation_documents.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-file-check mr-1"></i>Validation documents
                </a>
                <a href="nouvelles_inscriptions.php" class="text-purple-600 border-b-2 border-purple-600 pb-2">
                    <i class="fas fa-user-plus mr-1"></i>Nouvelles inscriptions
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-list mr-1"></i>Toutes les inscriptions
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php foreach ($messages as $message): ?>
            <div class="mb-4 p-4 rounded-md <?php echo $message['type'] === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?>">
                <i class="fas fa-<?php echo $message['type'] === 'success' ? 'check-circle' : 'exclamation-circle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message['text']); ?>
            </div>
        <?php endforeach; ?>

        <div class="bg-white rounded-lg shadow-md p-6 mb-6">
            <h2 class="text-2xl font-bold text-gray-800 mb-2">
                <i class="fas fa-user-check mr-2"></i>Étudiants prêts pour l'inscription
            </h2>
            <p class="text-gray-600 mb-6">
                Liste des étudiants dont les documents ont été validés et qui peuvent être inscrits à une classe.
            </p>

            <?php if (empty($etudiants_prets)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-inbox text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun étudiant en attente</h3>
                    <p class="text-gray-500">Tous les étudiants avec documents validés ont déjà été inscrits, ou aucun document n'a encore été validé.</p>
                    <a href="validation_documents.php" class="inline-block mt-4 bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-file-check mr-2"></i>Valider des documents
                    </a>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Matricule</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Nom</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Téléphone</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Docs validés</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($etudiants_prets as $etudiant): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($etudiant['matricule']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-900">
                                        <?php echo htmlspecialchars($etudiant['name']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($etudiant['email']); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm text-gray-600">
                                        <?php echo htmlspecialchars($etudiant['phone'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                            <i class="fas fa-check-circle mr-1"></i><?php echo $etudiant['nb_docs_valides']; ?> documents
                                        </span>
                                    </td>
                                    <td class="px-4 py-3 text-sm">
                                        <button onclick="openInscriptionModal(<?php echo $etudiant['id']; ?>, '<?php echo htmlspecialchars($etudiant['name'], ENT_QUOTES); ?>')"
                                                class="bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded-md text-xs transition duration-200">
                                            <i class="fas fa-user-plus mr-1"></i>Inscrire
                                        </button>
                                        <a href="validation_documents.php?user_id=<?php echo $etudiant['id']; ?>" 
                                           class="ml-2 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-md text-xs transition duration-200">
                                            <i class="fas fa-eye mr-1"></i>Voir docs
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal d'inscription -->
    <div id="inscriptionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center z-50">
        <div class="bg-white rounded-lg shadow-xl p-6 max-w-md w-full mx-4">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-user-plus mr-2"></i>Créer une inscription
                </h3>
                <button onclick="closeInscriptionModal()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="inscriptionForm">
                <input type="hidden" name="action" value="creer_inscription">
                <input type="hidden" name="etudiant_id" id="etudiant_id">

                <div class="mb-4">
                    <p class="text-gray-700 mb-2">
                        <strong>Étudiant:</strong> <span id="etudiant_nom" class="text-gray-900"></span>
                    </p>
                </div>

                <div class="mb-4">
                    <label for="classe_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Classe <span class="text-red-500">*</span>
                    </label>
                    <select name="classe_id" id="classe_id" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                        <option value="">Sélectionner une classe</option>
                        <?php foreach ($classes_par_dept as $dept => $dept_classes): ?>
                            <optgroup label="<?php echo htmlspecialchars($dept); ?>">
                                <?php foreach ($dept_classes as $classe): ?>
                                    <option value="<?php echo $classe['id']; ?>">
                                        <?php echo htmlspecialchars($classe['filiere_nom'] . ' - ' . $classe['nom_classe'] . ' (' . $classe['niveau'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-6">
                    <label for="annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                        Année académique <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="annee_academique" id="annee_academique" 
                           value="<?php echo htmlspecialchars($annee_actuelle); ?>" required
                           placeholder="Ex: 2025/2026"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-purple-500 focus:border-purple-500">
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeInscriptionModal()"
                            class="px-4 py-2 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-md transition duration-200">
                        Annuler
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-purple-600 hover:bg-purple-700 text-white rounded-md transition duration-200">
                        <i class="fas fa-check mr-2"></i>Créer l'inscription
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openInscriptionModal(etudiantId, etudiantNom) {
            document.getElementById('etudiant_id').value = etudiantId;
            document.getElementById('etudiant_nom').textContent = etudiantNom;
            document.getElementById('inscriptionModal').classList.remove('hidden');
            document.getElementById('inscriptionModal').classList.add('flex');
        }

        function closeInscriptionModal() {
            document.getElementById('inscriptionModal').classList.add('hidden');
            document.getElementById('inscriptionModal').classList.remove('flex');
            document.getElementById('inscriptionForm').reset();
        }

        // Fermer le modal en cliquant à l'extérieur
        document.getElementById('inscriptionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeInscriptionModal();
            }
        });
    </script>
</body>
</html>
