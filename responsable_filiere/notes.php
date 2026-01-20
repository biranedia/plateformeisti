<?php
/**
 * Gestion des notes - Responsable de filière
 * Permet de saisir, modifier et consulter les notes des étudiants de la filière
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('resp_filiere')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de filière pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération de la filière gérée
$filiere_query = "SELECT f.*, d.nom as departement_nom
                 FROM filieres f
                 JOIN departements d ON f.departement_id = d.id
                 WHERE f.responsable_id = :user_id";
$filiere_stmt = $conn->prepare($filiere_query);
$filiere_stmt->bindParam(':user_id', $user_id);
$filiere_stmt->execute();
$filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

if (!$filiere) {
    redirectWithMessage('dashboard.php', 'Aucune filière assignée.', 'error');
}

$message = '';
$message_type = '';

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'add_note' && isset($_POST['etudiant_id'], $_POST['enseignement_id'], $_POST['note'], $_POST['type_evaluation'])) {
            $etudiant_id = (int)$_POST['etudiant_id'];
            $enseignement_id = (int)$_POST['enseignement_id'];
            $note = (float)$_POST['note'];
            $type_evaluation = sanitize($_POST['type_evaluation']);
            $commentaire = isset($_POST['commentaire']) ? sanitize($_POST['commentaire']) : '';

            // Validation
            if ($note < 0 || $note > 20) {
                $message = "La note doit être entre 0 et 20.";
                $message_type = "error";
            } else {
                // Vérifier que l'enseignement appartient à la filière
                $check_query = "SELECT e.id FROM enseignements e
                               JOIN classes c ON e.classe_id = c.id
                               WHERE e.id = :enseignement_id AND c.filiere_id = :filiere_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->execute([':enseignement_id' => $enseignement_id, ':filiere_id' => $filiere['id']]);
                
                if ($check_stmt->fetch()) {
                    // Insérer la note
                    $insert_query = "INSERT INTO notes (etudiant_id, enseignement_id, note, type_evaluation, commentaire) 
                                    VALUES (:etudiant_id, :enseignement_id, :note, :type_evaluation, :commentaire)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->execute([
                        ':etudiant_id' => $etudiant_id,
                        ':enseignement_id' => $enseignement_id,
                        ':note' => $note,
                        ':type_evaluation' => $type_evaluation,
                        ':commentaire' => $commentaire
                    ]);
                    $message = "Note ajoutée avec succès.";
                    $message_type = "success";
                } else {
                    $message = "Enseignement non trouvé dans votre filière.";
                    $message_type = "error";
                }
            }
        }

        if ($action === 'update_note' && isset($_POST['note_id'], $_POST['note'])) {
            $note_id = (int)$_POST['note_id'];
            $note = (float)$_POST['note'];
            $commentaire = isset($_POST['commentaire']) ? sanitize($_POST['commentaire']) : '';

            if ($note < 0 || $note > 20) {
                $message = "La note doit être entre 0 et 20.";
                $message_type = "error";
            } else {
                // Vérifier que la note appartient à la filière
                $check_query = "SELECT n.id FROM notes n
                               JOIN enseignements e ON n.enseignement_id = e.id
                               JOIN classes c ON e.classe_id = c.id
                               WHERE n.id = :note_id AND c.filiere_id = :filiere_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->execute([':note_id' => $note_id, ':filiere_id' => $filiere['id']]);
                
                if ($check_stmt->fetch()) {
                    $update_query = "UPDATE notes SET note = :note, commentaire = :commentaire WHERE id = :note_id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->execute([':note' => $note, ':commentaire' => $commentaire, ':note_id' => $note_id]);
                    $message = "Note modifiée avec succès.";
                    $message_type = "success";
                } else {
                    $message = "Note non trouvée dans votre filière.";
                    $message_type = "error";
                }
            }
        }

        if ($action === 'delete_note' && isset($_POST['note_id'])) {
            $note_id = (int)$_POST['note_id'];
            
            // Vérifier que la note appartient à la filière
            $check_query = "SELECT n.id FROM notes n
                           JOIN enseignements e ON n.enseignement_id = e.id
                           JOIN classes c ON e.classe_id = c.id
                           WHERE n.id = :note_id AND c.filiere_id = :filiere_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute([':note_id' => $note_id, ':filiere_id' => $filiere['id']]);
            
            if ($check_stmt->fetch()) {
                $delete_query = "DELETE FROM notes WHERE id = :note_id";
                $delete_stmt = $conn->prepare($delete_query);
                $delete_stmt->execute([':note_id' => $note_id]);
                $message = "Note supprimée avec succès.";
                $message_type = "success";
            } else {
                $message = "Note non trouvée dans votre filière.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des classes de la filière
$classes_query = "SELECT id, nom_classe, niveau FROM classes WHERE filiere_id = :filiere_id ORDER BY niveau, nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->bindParam(':filiere_id', $filiere['id']);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtres
$classe_filter = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$enseignement_filter = isset($_GET['enseignement_id']) ? (int)$_GET['enseignement_id'] : 0;

// Récupération des enseignements de la classe sélectionnée
$enseignements = [];
if ($classe_filter) {
    $ens_query = "SELECT e.id, e.matiere, u.name as enseignant_nom
                  FROM enseignements e
                  JOIN users u ON e.enseignant_id = u.id
                  WHERE e.classe_id = :classe_id
                  ORDER BY e.matiere";
    $ens_stmt = $conn->prepare($ens_query);
    $ens_stmt->bindParam(':classe_id', $classe_filter);
    $ens_stmt->execute();
    $enseignements = $ens_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupération des notes selon les filtres
$notes = [];
if ($classe_filter) {
    $notes_query = "SELECT n.*, u.name as etudiant_nom, u.matricule, e.matiere, n.type_evaluation,
                          ens.name as enseignant_nom
                   FROM notes n
                   JOIN users u ON n.etudiant_id = u.id
                   JOIN enseignements e ON n.enseignement_id = e.id
                   JOIN users ens ON e.enseignant_id = ens.id
                   JOIN inscriptions i ON u.id = i.user_id AND i.classe_id = e.classe_id
                   WHERE e.classe_id = :classe_id";
    
    $params = [':classe_id' => $classe_filter];
    
    if ($enseignement_filter) {
        $notes_query .= " AND e.id = :enseignement_id";
        $params[':enseignement_id'] = $enseignement_filter;
    }
    
    $notes_query .= " ORDER BY u.name, e.matiere, n.type_evaluation";
    
    $notes_stmt = $conn->prepare($notes_query);
    $notes_stmt->execute($params);
    $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Récupération des étudiants de la classe pour l'ajout de notes
$etudiants = [];
if ($classe_filter) {
    $etud_query = "SELECT DISTINCT u.id, u.name, u.matricule
                   FROM users u
                   JOIN inscriptions i ON u.id = i.user_id
                   WHERE i.classe_id = :classe_id AND i.statut = 'inscrit'
                   ORDER BY u.name";
    $etud_stmt = $conn->prepare($etud_query);
    $etud_stmt->bindParam(':classe_id', $classe_filter);
    $etud_stmt->execute();
    $etudiants = $etud_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des Notes - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-orange-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-chart-bar text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable de Filière</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Filière: <?php echo htmlspecialchars($filiere['nom']); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-users mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="notes.php" class="text-orange-600 border-b-2 border-orange-600 pb-2">
                    <i class="fas fa-chart-bar mr-1"></i>Notes
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-calendar mr-1"></i>Emploi du temps
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Message de succès/erreur -->
        <?php if ($message): ?>
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : 'exclamation'; ?>-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-filter mr-2"></i>Filtrer les notes
            </h2>
            
            <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="classe_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Classe
                    </label>
                    <select name="classe_id" id="classe_id" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500">
                        <option value="0">Sélectionner une classe</option>
                        <?php foreach ($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $classe_filter == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom_classe']); ?> (<?php echo htmlspecialchars($classe['niveau']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <?php if ($classe_filter): ?>
                <div>
                    <label for="enseignement_id" class="block text-sm font-medium text-gray-700 mb-2">
                        Matière (optionnel)
                    </label>
                    <select name="enseignement_id" id="enseignement_id" onchange="this.form.submit()"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500">
                        <option value="0">Toutes les matières</option>
                        <?php foreach ($enseignements as $ens): ?>
                            <option value="<?php echo $ens['id']; ?>" <?php echo $enseignement_filter == $ens['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ens['matiere']); ?> - <?php echo htmlspecialchars($ens['enseignant_nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="button" onclick="document.getElementById('addNoteModal').classList.remove('hidden')"
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-plus mr-2"></i>Ajouter une note
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Liste des notes -->
        <?php if ($classe_filter): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list mr-2"></i>Notes enregistrées
            </h2>

            <?php if (empty($notes)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-chart-bar text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune note</h3>
                    <p class="text-gray-500">Aucune note n'a encore été saisie pour cette sélection.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Étudiant
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Matière
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Note
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Commentaire
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($notes as $note): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($note['etudiant_nom']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($note['matricule']); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($note['matiere']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                        <?php echo ucfirst($note['type_evaluation']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="text-lg font-bold <?php echo $note['note'] >= 10 ? 'text-green-600' : 'text-red-600'; ?>">
                                        <?php echo number_format($note['note'], 2); ?>/20
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <?php echo htmlspecialchars($note['commentaire'] ?? '-'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <button onclick="editNote(<?php echo $note['id']; ?>, <?php echo $note['note']; ?>, '<?php echo htmlspecialchars($note['commentaire'] ?? ''); ?>')"
                                            class="text-indigo-600 hover:text-indigo-900 text-sm mr-3">
                                        <i class="fas fa-edit mr-1"></i>Modifier
                                    </button>
                                    <button onclick="deleteNote(<?php echo $note['id']; ?>)"
                                            class="text-red-600 hover:text-red-900 text-sm">
                                        <i class="fas fa-trash mr-1"></i>Supprimer
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow-md p-12 text-center">
            <i class="fas fa-info-circle text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-600 mb-2">Sélectionnez une classe</h3>
            <p class="text-gray-500">Veuillez sélectionner une classe pour afficher et gérer les notes.</p>
        </div>
        <?php endif; ?>
    </main>

    <!-- Modal d'ajout de note -->
    <div id="addNoteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-plus mr-2 text-orange-600"></i>Ajouter une note
                        </h3>
                        <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_note">

                        <div class="mb-4">
                            <label for="add_etudiant_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Étudiant *
                            </label>
                            <select name="etudiant_id" id="add_etudiant_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500">
                                <option value="">Sélectionner un étudiant</option>
                                <?php foreach ($etudiants as $etud): ?>
                                    <option value="<?php echo $etud['id']; ?>">
                                        <?php echo htmlspecialchars($etud['name']); ?> (<?php echo htmlspecialchars($etud['matricule']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="add_enseignement_id" class="block text-sm font-medium text-gray-700 mb-2">
                                Matière *
                            </label>
                            <select name="enseignement_id" id="add_enseignement_id" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500">
                                <option value="">Sélectionner une matière</option>
                                <?php foreach ($enseignements as $ens): ?>
                                    <option value="<?php echo $ens['id']; ?>">
                                        <?php echo htmlspecialchars($ens['matiere']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="add_type_evaluation" class="block text-sm font-medium text-gray-700 mb-2">
                                Type d'évaluation *
                            </label>
                            <select name="type_evaluation" id="add_type_evaluation" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500">
                                <option value="devoir">Devoir</option>
                                <option value="examen">Examen</option>
                                <option value="tp">TP</option>
                                <option value="projet">Projet</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="add_note" class="block text-sm font-medium text-gray-700 mb-2">
                                Note (sur 20) *
                            </label>
                            <input type="number" step="0.01" min="0" max="20" name="note" id="add_note" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500"
                                   placeholder="Ex: 15.5">
                        </div>

                        <div class="mb-6">
                            <label for="add_commentaire" class="block text-sm font-medium text-gray-700 mb-2">
                                Commentaire (optionnel)
                            </label>
                            <textarea name="commentaire" id="add_commentaire" rows="2"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-orange-500 focus:border-orange-500"
                                      placeholder="Observation sur la note..."></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeAddModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de modification -->
    <div id="editNoteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-edit mr-2 text-indigo-600"></i>Modifier la note
                        </h3>
                        <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_note">
                        <input type="hidden" name="note_id" id="edit_note_id">

                        <div class="mb-4">
                            <label for="edit_note" class="block text-sm font-medium text-gray-700 mb-2">
                                Note (sur 20) *
                            </label>
                            <input type="number" step="0.01" min="0" max="20" name="note" id="edit_note" required
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        </div>

                        <div class="mb-6">
                            <label for="edit_commentaire" class="block text-sm font-medium text-gray-700 mb-2">
                                Commentaire (optionnel)
                            </label>
                            <textarea name="commentaire" id="edit_commentaire" rows="2"
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeEditModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de suppression -->
    <div id="deleteNoteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Confirmer la suppression
                        </h3>
                        <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <p class="text-gray-600 mb-6">
                        Êtes-vous sûr de vouloir supprimer cette note ? Cette action est irréversible.
                    </p>

                    <form method="POST" action="">
                        <input type="hidden" name="action" value="delete_note">
                        <input type="hidden" name="note_id" id="delete_note_id">

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeDeleteModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-trash mr-2"></i>Supprimer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closeAddModal() {
            document.getElementById('addNoteModal').classList.add('hidden');
        }

        function closeEditModal() {
            document.getElementById('editNoteModal').classList.add('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteNoteModal').classList.add('hidden');
        }

        function editNote(id, note, commentaire) {
            document.getElementById('edit_note_id').value = id;
            document.getElementById('edit_note').value = note;
            document.getElementById('edit_commentaire').value = commentaire;
            document.getElementById('editNoteModal').classList.remove('hidden');
        }

        function deleteNote(id) {
            document.getElementById('delete_note_id').value = id;
            document.getElementById('deleteNoteModal').classList.remove('hidden');
        }

        // Fermer les modals en cliquant en dehors
        document.getElementById('addNoteModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddModal();
        });

        document.getElementById('editNoteModal').addEventListener('click', function(e) {
            if (e.target === this) closeEditModal();
        });

        document.getElementById('deleteNoteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    </script>
</body>
</html>
