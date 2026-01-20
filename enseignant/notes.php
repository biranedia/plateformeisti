<?php
/**
 * Gestion des notes - Enseignant
 * Permet de saisir et consulter les notes des étudiants
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
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des enseignements de l'enseignant
$enseignements_query = "SELECT e.*, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                       FROM enseignements e
                       JOIN classes c ON e.classe_id = c.id
                       JOIN filieres f ON c.filiere_id = f.id
                       JOIN departements d ON f.departement_id = d.id
                       WHERE e.enseignant_id = :user_id
                       ORDER BY e.matiere";
$enseignements_stmt = $conn->prepare($enseignements_query);
$enseignements_stmt->bindParam(':user_id', $user_id);
$enseignements_stmt->execute();
$enseignements = $enseignements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtre par enseignement
$filtre_enseignement = isset($_GET['enseignement']) ? (int)$_GET['enseignement'] : null;

// Récupération des étudiants pour l'enseignement sélectionné
$etudiants = [];
$notes_existantes = [];
if ($filtre_enseignement) {
    // Vérifier que l'enseignement appartient bien à l'enseignant
    $check_enseignement = array_filter($enseignements, function($e) use ($filtre_enseignement) {
        return $e['id'] == $filtre_enseignement;
    });

    if (!empty($check_enseignement)) {
        $enseignement = reset($check_enseignement);

        // Récupération des étudiants inscrits dans la classe
        $etudiants_query = "SELECT u.id, u.name, u.email
                           FROM users u
                           JOIN inscriptions i ON u.id = i.user_id
                           WHERE i.classe_id = :classe_id AND i.statut IN ('inscrit', 'reinscrit')
                           ORDER BY u.name";
        $etudiants_stmt = $conn->prepare($etudiants_query);
        $etudiants_stmt->bindParam(':classe_id', $enseignement['classe_id']);
        $etudiants_stmt->execute();
        $etudiants = $etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupération des notes existantes pour cet enseignement
        $notes_query = "SELECT * FROM notes WHERE enseignement_id = :enseignement_id";
        $notes_stmt = $conn->prepare($notes_query);
        $notes_stmt->bindParam(':enseignement_id', $filtre_enseignement);
        $notes_stmt->execute();
        $notes_brutes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Organiser les notes par étudiant
        foreach ($notes_brutes as $note) {
            $notes_existantes[$note['etudiant_id']][] = $note;
        }
    } else {
        $filtre_enseignement = null;
    }
}

// Traitement du formulaire de saisie des notes
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'saisir_notes' && $filtre_enseignement) {
        $type_evaluation = sanitize($_POST['type_evaluation']);
        $date_saisie = sanitize($_POST['date_saisie']);
        $notes = $_POST['notes'] ?? [];
        $commentaires = $_POST['commentaires'] ?? [];

        $success_count = 0;
        $error_count = 0;

        foreach ($notes as $etudiant_id => $note_value) {
            if (!empty($note_value) && is_numeric($note_value)) {
                $note_value = floatval($note_value);
                if ($note_value >= 0 && $note_value <= 20) {
                    $commentaire = isset($commentaires[$etudiant_id]) ? sanitize($commentaires[$etudiant_id]) : '';

                    try {
                        $insert_query = "INSERT INTO notes (etudiant_id, enseignement_id, note, type_evaluation, date_saisie, commentaire)
                                       VALUES (:etudiant_id, :enseignement_id, :note, :type, :date, :commentaire)
                                       ON DUPLICATE KEY UPDATE
                                       note = VALUES(note), commentaire = VALUES(commentaire), date_saisie = VALUES(date_saisie)";
                        $insert_stmt = $conn->prepare($insert_query);
                        $insert_stmt->bindParam(':etudiant_id', $etudiant_id);
                        $insert_stmt->bindParam(':enseignement_id', $filtre_enseignement);
                        $insert_stmt->bindParam(':note', $note_value);
                        $insert_stmt->bindParam(':type', $type_evaluation);
                        $insert_stmt->bindParam(':date', $date_saisie);
                        $insert_stmt->bindParam(':commentaire', $commentaire);
                        $insert_stmt->execute();

                        $success_count++;
                    } catch (PDOException $e) {
                        $error_count++;
                    }
                }
            }
        }

        if ($success_count > 0) {
            $messages[] = ['type' => 'success', 'text' => "$success_count note(s) saisie(s) avec succès."];
        }
        if ($error_count > 0) {
            $messages[] = ['type' => 'error', 'text' => "$error_count erreur(s) lors de la saisie."];
        }

        // Recharger les notes
        if ($filtre_enseignement) {
            $notes_stmt->execute();
            $notes_brutes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
            $notes_existantes = [];
            foreach ($notes_brutes as $note) {
                $notes_existantes[$note['etudiant_id']][] = $note;
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
    <title>Gestion des Notes - ISTI</title>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-green-600 border-b-2 border-green-600 pb-2">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="presence.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-user-check mr-1"></i>Présence
                </a>
                <a href="ressources.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-book mr-1"></i>Ressources
                </a>
                <a href="feedback_etudiants.php" class="text-gray-600 hover:text-green-600">
                    <i class="fas fa-comments mr-1"></i>Feedbacks
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

        <!-- Sélection de l'enseignement -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-book mr-2"></i>Sélectionner un enseignement
            </h2>

            <?php if (empty($enseignements)): ?>
                <p class="text-gray-600">Aucun enseignement ne vous est assigné.</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($enseignements as $enseignement): ?>
                    <a href="?enseignement=<?php echo $enseignement['id']; ?>"
                       class="block p-4 border rounded-lg hover:shadow-md transition duration-200 <?php echo $filtre_enseignement == $enseignement['id'] ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-green-300'; ?>">
                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($enseignement['matiere']); ?></h3>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($enseignement['niveau'] . ' - ' . $enseignement['filiere_nom']); ?></p>
                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($enseignement['departement_nom']); ?></p>
                        <p class="text-sm text-blue-600">Volume: <?php echo htmlspecialchars($enseignement['volume_horaire']); ?>h</p>
                    </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($filtre_enseignement && !empty($etudiants)): ?>
        <!-- Formulaire de saisie des notes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-edit mr-2"></i>Saisir les notes - <?php echo htmlspecialchars($enseignement['matiere']); ?>
            </h2>

            <form method="POST" class="space-y-6">
                <input type="hidden" name="action" value="saisir_notes">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="type_evaluation" class="block text-sm font-medium text-gray-700 mb-1">Type d'évaluation *</label>
                        <select name="type_evaluation" id="type_evaluation" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                            <option value="">Sélectionner un type</option>
                            <option value="devoir">Devoir</option>
                            <option value="examen">Examen</option>
                            <option value="tp">TP</option>
                            <option value="projet">Projet</option>
                        </select>
                    </div>

                    <div>
                        <label for="date_saisie" class="block text-sm font-medium text-gray-700 mb-1">Date de saisie *</label>
                        <input type="date" name="date_saisie" id="date_saisie" required
                               value="<?php echo date('Y-m-d'); ?>"
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                    </div>
                </div>

                <div class="bg-yellow-50 p-4 rounded-lg">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-info-circle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-sm font-medium text-yellow-800">
                                Information
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700">
                                <p>Seules les notes entre 0 et 20 seront acceptées. Laissez le champ vide pour les étudiants absents.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Note (/20)</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Commentaire</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Dernière note</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($etudiants as $etudiant): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($etudiant['name']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo htmlspecialchars($etudiant['email']); ?></div>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <input type="number" name="notes[<?php echo $etudiant['id']; ?>]" min="0" max="20" step="0.25"
                                           class="w-20 px-2 py-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <input type="text" name="commentaires[<?php echo $etudiant['id']; ?>]" maxlength="255"
                                           class="w-full px-2 py-1 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                    $dernieres_notes = isset($notes_existantes[$etudiant['id']]) ? $notes_existantes[$etudiant['id']] : [];
                                    if (!empty($dernieres_notes)) {
                                        $derniere_note = end($dernieres_notes);
                                        echo htmlspecialchars($derniere_note['note'] . '/20 (' . $derniere_note['type_evaluation'] . ')');
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-save mr-2"></i>Enregistrer les notes
                    </button>
                </div>
            </form>
        </div>

        <!-- Historique des notes -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-history mr-2"></i>Historique des notes
            </h2>

            <?php
            $toutes_notes = [];
            foreach ($notes_existantes as $etudiant_notes) {
                $toutes_notes = array_merge($toutes_notes, $etudiant_notes);
            }
            usort($toutes_notes, function($a, $b) {
                return strtotime($b['date_saisie']) - strtotime($a['date_saisie']);
            });
            ?>

            <?php if (empty($toutes_notes)): ?>
                <p class="text-gray-600">Aucune note saisie pour cet enseignement.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Note</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Commentaire</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($toutes_notes as $note): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['date_saisie']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                    $etudiant_info = array_filter($etudiants, function($e) use ($note) {
                                        return $e['id'] == $note['etudiant_id'];
                                    });
                                    $etudiant_nom = !empty($etudiant_info) ? reset($etudiant_info)['name'] : 'N/A';
                                    echo htmlspecialchars($etudiant_nom);
                                    ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($note['type_evaluation']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm font-medium
                                    <?php echo $note['note'] >= 16 ? 'text-green-600' :
                                             ($note['note'] >= 14 ? 'text-blue-600' :
                                             ($note['note'] >= 12 ? 'text-yellow-600' :
                                             ($note['note'] >= 10 ? 'text-orange-600' : 'text-red-600'))); ?>">
                                    <?php echo htmlspecialchars($note['note']); ?>/20
                                </td>
                                <td class="px-4 py-2 text-sm text-gray-900"><?php echo htmlspecialchars($note['commentaire'] ?: '-'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
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