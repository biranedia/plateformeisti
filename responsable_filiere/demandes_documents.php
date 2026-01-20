<?php
/**
 * Gestion des demandes de documents pour les responsables de filière
 * Permet de valider ou rejeter les demandes de documents des étudiants
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

// Récupération des demandes de documents en attente pour la filière
$demandes_query = "SELECT d.*, u.name, u.matricule, c.nom_classe,
                         f.nom_filiere, dep.nom_departement
                  FROM documents d
                  JOIN users u ON d.user_id = u.id
                  JOIN inscriptions i ON u.id = i.user_id AND i.statut = 'active'
                  JOIN classes c ON i.classe_id = c.id
                  JOIN filieres f ON c.filiere_id = f.id
                  JOIN departements dep ON f.departement_id = dep.id
                  WHERE f.id = :filiere_id AND d.statut = 'en_attente'
                  ORDER BY d.date_creation DESC";
$demandes_stmt = $conn->prepare($demandes_query);
$demandes_stmt->bindParam(':filiere_id', $filiere['id']);
$demandes_stmt->execute();
$demandes = $demandes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des documents validés récemment
$valides_query = "SELECT d.*, u.name, u.matricule, c.nom_classe,
                        f.nom_filiere
                 FROM documents d
                 JOIN users u ON d.user_id = u.id
                 JOIN inscriptions i ON u.id = i.user_id AND i.statut = 'active'
                 JOIN classes c ON i.classe_id = c.id
                 JOIN filieres f ON c.filiere_id = f.id
                 WHERE f.id = :filiere_id AND d.statut = 'valide'
                 ORDER BY d.date_validation DESC LIMIT 10";
$valides_stmt = $conn->prepare($valides_query);
$valides_stmt->bindParam(':filiere_id', $filiere['id']);
$valides_stmt->execute();
$documents_valides = $valides_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de documents
$types_documents = [
    'attestation_scolarite' => 'Attestation de scolarité',
    'releve_notes' => 'Relevé de notes',
    'certificat_reussite' => 'Certificat de réussite'
];

// Statistiques
$stats_query = "SELECT
    COUNT(CASE WHEN statut = 'en_attente' THEN 1 END) as en_attente,
    COUNT(CASE WHEN statut = 'valide' THEN 1 END) as valides,
    COUNT(CASE WHEN statut = 'rejete' THEN 1 END) as rejetes,
    COUNT(*) as total
FROM documents d
JOIN users u ON d.user_id = u.id
JOIN inscriptions i ON u.id = i.etudiant_id AND i.statut = 'active'
JOIN classes c ON i.classe_id = c.id
WHERE c.filiere_id = :filiere_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':filiere_id', $filiere['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Traitement des actions sur les demandes
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $document_id = sanitize($_POST['document_id']);

    if ($_POST['action'] === 'valider_document') {
        $fichier_url = sanitize($_POST['fichier_url']);

        try {
            $update_query = "UPDATE documents SET statut = 'valide', fichier_url = :fichier_url,
                           date_validation = NOW(), valide_par = :valide_par
                           WHERE id = :id AND statut = 'en_attente'";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':fichier_url', $fichier_url);
            $update_stmt->bindParam(':valide_par', $user_id);
            $update_stmt->bindParam(':id', $document_id);
            $result = $update_stmt->execute();

            if ($result) {
                $messages[] = ['type' => 'success', 'text' => 'Document validé avec succès.'];

                // Créer une notification pour l'étudiant
                $notif_query = "INSERT INTO notifications (user_id, titre, message, type, date_creation)
                               VALUES ((SELECT user_id FROM documents WHERE id = :doc_id),
                               'Document validé',
                               'Votre demande de " . $types_documents[$_POST['type_document']] . " a été validée et est maintenant disponible.',
                               'success', NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                $notif_stmt->bindParam(':doc_id', $document_id);
                $notif_stmt->execute();

                // Recharger les données
                $demandes_stmt->execute();
                $demandes = $demandes_stmt->fetchAll(PDO::FETCH_ASSOC);
                $valides_stmt->execute();
                $documents_valides = $valides_stmt->fetchAll(PDO::FETCH_ASSOC);
                $stats_stmt->execute();
                $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la validation du document.'];
            }

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la validation: ' . $e->getMessage()];
        }

    } elseif ($_POST['action'] === 'rejeter_document') {
        $motif_rejet = sanitize($_POST['motif_rejet']);

        try {
            $update_query = "UPDATE documents SET statut = 'rejete', motif_rejet = :motif_rejet,
                           date_validation = NOW(), valide_par = :valide_par
                           WHERE id = :id AND statut = 'en_attente'";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':motif_rejet', $motif_rejet);
            $update_stmt->bindParam(':valide_par', $user_id);
            $update_stmt->bindParam(':id', $document_id);
            $result = $update_stmt->execute();

            if ($result) {
                $messages[] = ['type' => 'success', 'text' => 'Document rejeté.'];

                // Créer une notification pour l'étudiant
                $notif_query = "INSERT INTO notifications (user_id, titre, message, type, date_creation)
                               VALUES ((SELECT user_id FROM documents WHERE id = :doc_id),
                               'Document rejeté',
                               'Votre demande de " . $types_documents[$_POST['type_document']] . " a été rejetée. Motif: " . $motif_rejet . "',
                               'error', NOW())";
                $notif_stmt = $conn->prepare($notif_query);
                $notif_stmt->bindParam(':doc_id', $document_id);
                $notif_stmt->execute();

                // Recharger les données
                $demandes_stmt->execute();
                $demandes = $demandes_stmt->fetchAll(PDO::FETCH_ASSOC);
                $stats_stmt->execute();
                $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors du rejet du document.'];
            }

        } catch (PDOException $e) {
            $messages[] = ['type' => 'error', 'text' => 'Erreur lors du rejet: ' . $e->getMessage()];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Demandes Documents - ISTI</title>
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
                <a href="enseignants.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-purple-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="demandes_documents.php" class="text-purple-600 border-b-2 border-purple-600 pb-2">
                    <i class="fas fa-file-alt mr-1"></i>Documents
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
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des demandes
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">En attente</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['en_attente']; ?></p>
                    <p class="text-sm text-gray-600">demandes</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Validées</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['valides']; ?></p>
                    <p class="text-sm text-gray-600">demandes</p>
                </div>

                <div class="text-center">
                    <div class="bg-red-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-times-circle text-red-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Rejetées</h3>
                    <p class="text-2xl font-bold text-red-600"><?php echo $stats['rejetes']; ?></p>
                    <p class="text-sm text-gray-600">demandes</p>
                </div>

                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-file-alt text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                    <p class="text-sm text-gray-600">demandes</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Demandes en attente -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-clock mr-2"></i>Demandes en attente
                    </h2>

                    <?php if (empty($demandes)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-check-circle text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucune demande en attente.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($demandes as $demande): ?>
                            <div class="border rounded-lg p-4 bg-yellow-50 border-yellow-200">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($demande['name']); ?>
                                            </h3>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($types_documents[$demande['type_document']] ?? $demande['type_document']); ?>
                                            </span>
                                        </div>
                                        <div class="text-sm text-gray-600 space-y-1">
                                            <p><i class="fas fa-id-card mr-2"></i><?php echo htmlspecialchars($demande['matricule']); ?></p>
                                            <p><i class="fas fa-users mr-2"></i><?php echo htmlspecialchars($demande['nom_classe']); ?> - <?php echo htmlspecialchars($demande['nom_filiere']); ?></p>
                                            <p><i class="fas fa-calendar-alt mr-2"></i>Demandé le <?php echo htmlspecialchars(date('d/m/Y à H:i', strtotime($demande['date_creation']))); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex space-x-2 mt-4">
                                    <!-- Bouton Valider -->
                                    <button onclick="openValidationModal(<?php echo $demande['id']; ?>, '<?php echo addslashes($demande['type_document']); ?>')"
                                            class="bg-green-600 hover:bg-green-700 text-white text-sm font-medium py-2 px-3 rounded-md transition duration-200">
                                        <i class="fas fa-check mr-1"></i>Valider
                                    </button>

                                    <!-- Bouton Rejeter -->
                                    <button onclick="openRejectionModal(<?php echo $demande['id']; ?>, '<?php echo addslashes($demande['type_document']); ?>')"
                                            class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium py-2 px-3 rounded-md transition duration-200">
                                        <i class="fas fa-times mr-1"></i>Rejeter
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Documents validés récemment -->
            <div>
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-check-circle mr-2"></i>Documents validés récemment
                    </h2>

                    <?php if (empty($documents_valides)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-file-alt text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-600">Aucun document validé récemment.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($documents_valides as $doc): ?>
                            <div class="border rounded-lg p-4 bg-green-50 border-green-200">
                                <div class="flex items-start justify-between mb-3">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-1">
                                            <h3 class="font-semibold text-gray-800">
                                                <?php echo htmlspecialchars($doc['name']); ?>
                                            </h3>
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo htmlspecialchars($types_documents[$doc['type_document']] ?? $doc['type_document']); ?>
                                            </span>
                                        </div>
                                        <div class="text-sm text-gray-600 space-y-1">
                                            <p><i class="fas fa-users mr-2"></i><?php echo htmlspecialchars($doc['nom_classe']); ?> - <?php echo htmlspecialchars($doc['nom_filiere']); ?></p>
                                            <p><i class="fas fa-calendar-check mr-2"></i>Validé le <?php echo htmlspecialchars(date('d/m/Y', strtotime($doc['date_validation']))); ?></p>
                                        </div>
                                    </div>
                                </div>

                                <?php if ($doc['fichier_url']): ?>
                                <div class="mt-3">
                                    <a href="<?php echo strpos($doc['fichier_url'], 'http') === 0 ? htmlspecialchars($doc['fichier_url']) : '../' . htmlspecialchars($doc['fichier_url']); ?>"
                                       target="_blank"
                                       class="text-blue-600 hover:text-blue-800 text-sm underline">
                                        <i class="fas fa-external-link-alt mr-1"></i>Voir le document
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Informations importantes -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Rôles et responsabilités
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Vérifiez l'identité de l'étudiant avant de valider tout document officiel</li>
                            <li>Assurez-vous que l'étudiant est bien inscrit dans votre filière</li>
                            <li>Pour les relevés de notes, vérifiez que toutes les notes sont saisies</li>
                            <li>Les attestations de scolarité sont valables pour l'année académique en cours</li>
                            <li>Conservez une trace de toutes les validations dans le système</li>
                            <li>En cas de rejet, fournissez toujours un motif clair et justifié</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de validation -->
    <div id="validationModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-check-circle mr-2 text-green-600"></i>Valider le document
                    </h3>

                    <form method="POST">
                        <input type="hidden" name="action" value="valider_document">
                        <input type="hidden" name="document_id" id="validation_document_id">
                        <input type="hidden" name="type_document" id="validation_type_document">

                        <div class="mb-4">
                            <label for="fichier_url" class="block text-sm font-medium text-gray-700 mb-2">
                                URL du document généré *
                            </label>
                            <input type="url" name="fichier_url" id="fichier_url" required
                                   placeholder="https://..."
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500">
                            <p class="text-xs text-gray-500 mt-1">
                                Lien vers le document PDF généré ou déposé
                            </p>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeValidationModal()"
                                    class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-check mr-2"></i>Valider
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de rejet -->
    <div id="rejectionModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-times-circle mr-2 text-red-600"></i>Rejeter le document
                    </h3>

                    <form method="POST">
                        <input type="hidden" name="action" value="rejeter_document">
                        <input type="hidden" name="document_id" id="rejection_document_id">
                        <input type="hidden" name="type_document" id="rejection_type_document">

                        <div class="mb-4">
                            <label for="motif_rejet" class="block text-sm font-medium text-gray-700 mb-2">
                                Motif du rejet *
                            </label>
                            <textarea name="motif_rejet" id="motif_rejet" rows="3" required
                                      placeholder="Expliquez pourquoi la demande est rejetée..."
                                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-red-500 focus:border-red-500"></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeRejectionModal()"
                                    class="bg-gray-600 hover:bg-gray-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-times mr-2"></i>Rejeter
                            </button>
                        </div>
                    </form>
                </div>
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
        function openValidationModal(documentId, typeDocument) {
            document.getElementById('validation_document_id').value = documentId;
            document.getElementById('validation_type_document').value = typeDocument;
            document.getElementById('validationModal').classList.remove('hidden');
        }

        function closeValidationModal() {
            document.getElementById('validationModal').classList.add('hidden');
            document.getElementById('fichier_url').value = '';
        }

        function openRejectionModal(documentId, typeDocument) {
            document.getElementById('rejection_document_id').value = documentId;
            document.getElementById('rejection_type_document').value = typeDocument;
            document.getElementById('rejectionModal').classList.remove('hidden');
        }

        function closeRejectionModal() {
            document.getElementById('rejectionModal').classList.add('hidden');
            document.getElementById('motif_rejet').value = '';
        }

        // Fermer les modals en cliquant en dehors
        document.getElementById('validationModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeValidationModal();
            }
        });

        document.getElementById('rejectionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeRejectionModal();
            }
        });
    </script>
</body>
</html>