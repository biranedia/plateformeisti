<?php
/**
 * Demandes de documents pour les étudiants
 * Permet de demander des attestations, relevés de notes, etc.
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

// Récupération des demandes de documents
$docs_query = "SELECT * FROM documents WHERE user_id = :user_id ORDER BY date_creation DESC";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':user_id', $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de documents disponibles
$types_documents = [
    'attestation_scolarite' => 'Attestation de scolarité',
    'releve_notes' => 'Relevé de notes',
    'certificat_reussite' => 'Certificat de réussite'
];

// Traitement du formulaire de demande
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'demander_document') {
        $type_document = sanitize($_POST['type_document']);

        if (!array_key_exists($type_document, $types_documents)) {
            $messages[] = ['type' => 'error', 'text' => 'Type de document invalide.'];
        } else {
            // Vérifier si une demande similaire est déjà en cours
            $check_query = "SELECT COUNT(*) as count FROM documents
                           WHERE user_id = :user_id AND type_document = :type AND statut IN ('en_attente', 'valide')";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':user_id', $user_id);
            $check_stmt->bindParam(':type', $type_document);
            $check_stmt->execute();
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing['count'] > 0) {
                $messages[] = ['type' => 'error', 'text' => 'Une demande pour ce type de document est déjà en cours ou validée.'];
            } else {
                try {
                    $insert_query = "INSERT INTO documents (user_id, type_document, statut, date_creation)
                                   VALUES (:user_id, :type, 'en_attente', NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':user_id', $user_id);
                    $insert_stmt->bindParam(':type', $type_document);
                    $insert_stmt->execute();

                    $messages[] = ['type' => 'success', 'text' => 'Votre demande de document a été enregistrée et sera traitée prochainement.'];

                    // Recharger les documents
                    $docs_stmt->execute();
                    $documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

                } catch (PDOException $e) {
                    $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la demande: ' . $e->getMessage()];
                }
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
    <title>Demandes de Documents - ISTI</title>
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
                <a href="demandes_documents.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
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
            <!-- Nouvelle demande -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus-circle mr-2"></i>Demander un document
                    </h2>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="demander_document">

                        <div>
                            <label for="type_document" class="block text-sm font-medium text-gray-700 mb-2">
                                Type de document *
                            </label>
                            <select name="type_document" id="type_document" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Sélectionnez un type de document</option>
                                <?php foreach ($types_documents as $key => $label): ?>
                                    <option value="<?php echo $key; ?>"><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="bg-blue-50 p-4 rounded-lg">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-info-circle text-blue-400"></i>
                                </div>
                                <div class="ml-3">
                                    <h3 class="text-sm font-medium text-blue-800">
                                        Informations importantes
                                    </h3>
                                    <div class="mt-2 text-sm text-blue-700">
                                        <ul class="list-disc list-inside space-y-1">
                                            <li>Les demandes sont traitées dans un délai de 2-3 jours ouvrés</li>
                                            <li>Vous recevrez une notification une fois le document prêt</li>
                                            <li>Certains documents peuvent nécessiter une validation supplémentaire</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-paper-plane mr-2"></i>Envoyer la demande
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Mes demandes -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-history mr-2"></i>Mes demandes
                    </h2>

                    <?php if (empty($documents)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-file-alt text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucune demande de document pour le moment.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($documents as $doc): ?>
                            <div class="border rounded-lg p-4 hover:shadow-md transition duration-200">
                                <div class="flex items-center justify-between mb-2">
                                    <h3 class="font-semibold text-gray-800">
                                        <?php echo htmlspecialchars($types_documents[$doc['type_document']] ?? $doc['type_document']); ?>
                                    </h3>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?php echo $doc['statut'] === 'valide' ? 'bg-green-100 text-green-800' :
                                                 ($doc['statut'] === 'en_attente' ? 'bg-yellow-100 text-yellow-800' :
                                                 'bg-red-100 text-red-800'); ?>">
                                        <?php echo $doc['statut'] === 'valide' ? 'Validé' :
                                                 ($doc['statut'] === 'en_attente' ? 'En attente' :
                                                 'Rejeté'); ?>
                                    </span>
                                </div>
                                <div class="text-sm text-gray-600 space-y-1">
                                    <p>Date de demande: <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['date_creation']))); ?></p>
                                    <?php if ($doc['statut'] === 'valide' && $doc['fichier_url']): ?>
                                        <p>
                                            <a href="<?php echo htmlspecialchars($doc['fichier_url']); ?>" target="_blank"
                                               class="text-blue-600 hover:text-blue-800 underline">
                                                <i class="fas fa-download mr-1"></i>Télécharger le document
                                            </a>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Informations sur les documents -->
        <div class="bg-white rounded-lg shadow-md p-6 mt-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-info-circle mr-2"></i>Informations sur les documents
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-graduation-cap text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Attestation de scolarité</h3>
                    <p class="text-sm text-gray-600">Confirme votre inscription et votre statut d'étudiant à l'ISTI pour une année donnée.</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-chart-line text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Relevé de notes</h3>
                    <p class="text-sm text-gray-600">Détaille vos résultats académiques par matière et période d'évaluation.</p>
                </div>

                <div class="text-center">
                    <div class="bg-purple-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-certificate text-purple-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Certificat de réussite</h3>
                    <p class="text-sm text-gray-600">Atteste de votre réussite à un examen ou à une année académique.</p>
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