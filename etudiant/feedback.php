<?php
/**
 * Feedback des étudiants
 * Permet de donner des avis sur les cours et les enseignants
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

// Récupération des cours de l'étudiant (via emploi du temps)
$cours_query = "SELECT DISTINCT e.id, e.matiere_nom, e.enseignant_id,
                       u.name as enseignant_nom,
                       c.nom_classe, f.nom as filiere_nom, d.nom as departement_nom
                FROM emplois_du_temps e
                JOIN classes c ON e.classe_id = c.id
                JOIN filieres f ON c.filiere_id = f.id
                JOIN departements d ON f.departement_id = d.id
                JOIN inscriptions i ON c.id = i.classe_id
                JOIN users u ON e.enseignant_id = u.id
                WHERE i.user_id = :user_id AND i.statut = 'inscrit'
                ORDER BY e.matiere_nom";
$cours_stmt = $conn->prepare($cours_query);
$cours_stmt->bindParam(':user_id', $user_id);
$cours_stmt->execute();
$cours = $cours_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des feedbacks existants
$feedbacks_query = "SELECT f.*, e.matiere_nom, u.name as enseignant_nom
                   FROM feedback_etudiants f
                   LEFT JOIN emplois_du_temps e ON f.cours_id = e.id
                   LEFT JOIN users u ON f.enseignant_id = u.id
                   WHERE f.etudiant_id = :user_id
                   ORDER BY f.date_creation DESC";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->bindParam(':user_id', $user_id);
$feedbacks_stmt->execute();
$feedbacks_existants = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire de feedback
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'soumettre_feedback') {
        $cours_id = sanitize($_POST['cours_id']);
        $enseignant_id = sanitize($_POST['enseignant_id']);
        $note_cours = (int)$_POST['note_cours'];
        $note_enseignant = (int)$_POST['note_enseignant'];
        $commentaire_cours = sanitize($_POST['commentaire_cours']);
        $commentaire_enseignant = sanitize($_POST['commentaire_enseignant']);
        $anonyme = isset($_POST['anonyme']) ? 1 : 0;

        // Validation
        $errors = [];
        if ($note_cours < 1 || $note_cours > 5) $errors[] = 'La note du cours doit être entre 1 et 5.';
        if ($note_enseignant < 1 || $note_enseignant > 5) $errors[] = 'La note de l\'enseignant doit être entre 1 et 5.';
        if (empty($commentaire_cours) && empty($commentaire_enseignant)) $errors[] = 'Au moins un commentaire est requis.';

        if (empty($errors)) {
            // Vérifier si un feedback existe déjà pour ce cours
            $check_query = "SELECT COUNT(*) as count FROM feedbacks
                           WHERE etudiant_id = :etudiant_id AND cours_id = :cours_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':etudiant_id', $user_id);
            $check_stmt->bindParam(':cours_id', $cours_id);
            $check_stmt->execute();
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing['count'] > 0) {
                $messages[] = ['type' => 'error', 'text' => 'Vous avez déjà donné votre feedback pour ce cours.'];
            } else {
                try {
                    $insert_query = "INSERT INTO feedbacks (etudiant_id, cours_id, enseignant_id, note_cours,
                                   note_enseignant, commentaire_cours, commentaire_enseignant, anonyme, date_creation)
                                   VALUES (:etudiant_id, :cours_id, :enseignant_id, :note_cours, :note_enseignant,
                                   :commentaire_cours, :commentaire_enseignant, :anonyme, NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':etudiant_id', $user_id);
                    $insert_stmt->bindParam(':cours_id', $cours_id);
                    $insert_stmt->bindParam(':enseignant_id', $enseignant_id);
                    $insert_stmt->bindParam(':note_cours', $note_cours);
                    $insert_stmt->bindParam(':note_enseignant', $note_enseignant);
                    $insert_stmt->bindParam(':commentaire_cours', $commentaire_cours);
                    $insert_stmt->bindParam(':commentaire_enseignant', $commentaire_enseignant);
                    $insert_stmt->bindParam(':anonyme', $anonyme);
                    $insert_stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Votre feedback a été enregistré avec succès. Merci pour votre contribution !'];

                    // Recharger les feedbacks
                    $feedbacks_stmt->execute();
                    $feedbacks_existants = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (PDOException $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur lors de l\'enregistrement: ' . $e->getMessage()];
                }
            }
        } else {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
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
    <title>Feedback - ISTI</title>
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
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Étudiant'); ?></span>
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
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-comments mr-1"></i>Feedback
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

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Donner un feedback -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-star mr-2"></i>Donner un feedback
                    </h2>

                    <?php if (empty($cours)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-book text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucun cours disponible pour donner un feedback.</p>
                        </div>
                    <?php else: ?>
                        <form method="POST" class="space-y-6">
                            <input type="hidden" name="action" value="soumettre_feedback">

                            <div>
                                <label for="cours_id" class="block text-sm font-medium text-gray-700 mb-2">
                                    Sélectionnez un cours *
                                </label>
                                <select name="cours_id" id="cours_id" required
                                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Choisissez un cours</option>
                                    <?php foreach ($cours as $c): ?>
                                        <option value="<?php echo $c['id']; ?>" data-enseignant="<?php echo htmlspecialchars($c['enseignant_nom']); ?>" data-enseignant-id="<?php echo $c['enseignant_id'] ?? ''; ?>">
                                            <?php echo htmlspecialchars($c['matiere_nom'] . ' - ' . $c['enseignant_nom']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="enseignant_id" id="enseignant_id">
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Note du cours (1-5) *
                                    </label>
                                    <div class="flex space-x-1">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <label class="flex items-center">
                                                <input type="radio" name="note_cours" value="<?php echo $i; ?>" class="sr-only">
                                                <i class="fas fa-star text-gray-300 hover:text-yellow-400 cursor-pointer text-xl star-rating" data-rating="<?php echo $i; ?>"></i>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">
                                        Note de l'enseignant (1-5) *
                                    </label>
                                    <div class="flex space-x-1">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <label class="flex items-center">
                                                <input type="radio" name="note_enseignant" value="<?php echo $i; ?>" class="sr-only">
                                                <i class="fas fa-star text-gray-300 hover:text-yellow-400 cursor-pointer text-xl teacher-star-rating" data-rating="<?php echo $i; ?>"></i>
                                            </label>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label for="commentaire_cours" class="block text-sm font-medium text-gray-700 mb-2">
                                    Commentaire sur le cours
                                </label>
                                <textarea name="commentaire_cours" id="commentaire_cours" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="Partagez votre avis sur le contenu du cours, la difficulté, les supports pédagogiques..."></textarea>
                            </div>

                            <div>
                                <label for="commentaire_enseignant" class="block text-sm font-medium text-gray-700 mb-2">
                                    Commentaire sur l'enseignant
                                </label>
                                <textarea name="commentaire_enseignant" id="commentaire_enseignant" rows="3"
                                          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                          placeholder="Partagez votre avis sur la pédagogie, la disponibilité, l'accompagnement..."></textarea>
                            </div>

                            <div class="flex items-center">
                                <input type="checkbox" name="anonyme" id="anonyme" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                                <label for="anonyme" class="ml-2 block text-sm text-gray-700">
                                    Soumettre anonymement
                                </label>
                            </div>

                            <div class="flex justify-end">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-paper-plane mr-2"></i>Envoyer le feedback
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Mes feedbacks -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-history mr-2"></i>Mes feedbacks
                    </h2>

                    <?php if (empty($feedbacks_existants)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-comments text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Vous n'avez pas encore donné de feedback.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($feedbacks_existants as $feedback): ?>
                            <div class="border rounded-lg p-4">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800">
                                        <?php echo htmlspecialchars($feedback['matiere_nom']); ?>
                                    </h3>
                                    <span class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars(date('d/m/Y', strtotime($feedback['date_creation']))); ?>
                                    </span>
                                </div>

                                <div class="mb-2">
                                    <span class="text-sm text-gray-600">Enseignant: </span>
                                    <span class="text-sm font-medium"><?php echo htmlspecialchars($feedback['enseignant_nom'] . ' ' . $feedback['enseignant_prenom']); ?></span>
                                </div>

                                <div class="flex items-center space-x-4 mb-2">
                                    <div class="flex items-center">
                                        <span class="text-sm text-gray-600 mr-2">Cours:</span>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $feedback['note_cours'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    <div class="flex items-center">
                                        <span class="text-sm text-gray-600 mr-2">Enseignant:</span>
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star <?php echo $i <= $feedback['note_enseignant'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                </div>

                                <?php if ($feedback['commentaire_cours'] || $feedback['commentaire_enseignant']): ?>
                                <div class="text-sm text-gray-700">
                                    <?php if ($feedback['commentaire_cours']): ?>
                                        <p><strong>Cours:</strong> <?php echo htmlspecialchars($feedback['commentaire_cours']); ?></p>
                                    <?php endif; ?>
                                    <?php if ($feedback['commentaire_enseignant']): ?>
                                        <p><strong>Enseignant:</strong> <?php echo htmlspecialchars($feedback['commentaire_enseignant']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <?php if ($feedback['anonyme']): ?>
                                <div class="mt-2">
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-gray-100 text-gray-800">
                                        <i class="fas fa-user-secret mr-1"></i>Anonyme
                                    </span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Informations sur le feedback -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        À propos du feedback
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Votre feedback contribue à l'amélioration continue des cours et de l'enseignement</li>
                            <li>Vous pouvez choisir de rester anonyme si vous le souhaitez</li>
                            <li>Un seul feedback par cours est autorisé pour garantir l'objectivité</li>
                            <li>Les commentaires constructifs sont particulièrement appréciés</li>
                            <li>Votre avis est pris en compte par les responsables pédagogiques</li>
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

    <script>
        // Gestion de la sélection du cours
        document.getElementById('cours_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const enseignantId = selectedOption.getAttribute('data-enseignant-id');
            document.getElementById('enseignant_id').value = enseignantId || '';
        });

        // Gestion des étoiles pour la note du cours
        document.querySelectorAll('.star-rating').forEach(star => {
            star.addEventListener('click', function() {
                const rating = this.getAttribute('data-rating');
                document.querySelector('input[name="note_cours"][value="' + rating + '"]').checked = true;

                // Mettre à jour l'affichage des étoiles
                document.querySelectorAll('.star-rating').forEach(s => {
                    const starRating = s.getAttribute('data-rating');
                    if (starRating <= rating) {
                        s.classList.remove('text-gray-300');
                        s.classList.add('text-yellow-400');
                    } else {
                        s.classList.remove('text-yellow-400');
                        s.classList.add('text-gray-300');
                    }
                });
            });
        });

        // Gestion des étoiles pour la note de l'enseignant
        document.querySelectorAll('.teacher-star-rating').forEach(star => {
            star.addEventListener('click', function() {
                const rating = this.getAttribute('data-rating');
                document.querySelector('input[name="note_enseignant"][value="' + rating + '"]').checked = true;

                // Mettre à jour l'affichage des étoiles
                document.querySelectorAll('.teacher-star-rating').forEach(s => {
                    const starRating = s.getAttribute('data-rating');
                    if (starRating <= rating) {
                        s.classList.remove('text-gray-300');
                        s.classList.add('text-yellow-400');
                    } else {
                        s.classList.remove('text-yellow-400');
                        s.classList.add('text-gray-300');
                    }
                });
            });
        });
    </script>
</body>
</html>