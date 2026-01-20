<?php
/**
 * Gestion des remontées pour les responsables de classe
 * Permet de consulter et traiter les problèmes signalés par les étudiants
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('responsable_classe')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant que responsable de classe pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Récupération de la classe du responsable
$classe_query = "SELECT c.*, f.nom_filiere, d.nom_departement
                FROM classes c
                JOIN filieres f ON c.filiere_id = f.id
                JOIN departements d ON f.departement_id = d.id
                JOIN responsables_classe rc ON c.id = rc.classe_id
                WHERE rc.user_id = :user_id";
$classe_stmt = $conn->prepare($classe_query);
$classe_stmt->bindParam(':user_id', $user_id);
$classe_stmt->execute();
$classe = $classe_stmt->fetch(PDO::FETCH_ASSOC);

if (!$classe) {
    die("Erreur: Classe non trouvée pour ce responsable.");
}

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'update_status' && isset($_POST['remontee_id'], $_POST['status'])) {
            $remontee_id = (int)$_POST['remontee_id'];
            $status = sanitize($_POST['status']);
            $commentaire = isset($_POST['commentaire']) ? sanitize($_POST['commentaire']) : '';

            // Vérification que la remontée appartient à un étudiant de la classe
            $check_query = "SELECT r.id FROM remontees r
                           JOIN users u ON r.etudiant_id = u.id
                           JOIN inscriptions i ON u.id = i.etudiant_id
                           WHERE r.id = :remontee_id AND i.classe_id = :classe_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':remontee_id', $remontee_id);
            $check_stmt->bindParam(':classe_id', $classe['id']);
            $check_stmt->execute();

            if ($check_stmt->fetch()) {
                $update_query = "UPDATE remontees SET statut = :status, date_traitement = NOW(),
                               commentaire_responsable = :commentaire WHERE id = :id";
                $update_stmt = $conn->prepare($update_query);
                $update_stmt->bindParam(':status', $status);
                $update_stmt->bindParam(':commentaire', $commentaire);
                $update_stmt->bindParam(':id', $remontee_id);

                if ($update_stmt->execute()) {
                    $message = "Statut de la remontée mis à jour avec succès.";
                    $message_type = "success";
                } else {
                    $message = "Erreur lors de la mise à jour du statut.";
                    $message_type = "error";
                }
            } else {
                $message = "Remontée non trouvée ou accès non autorisé.";
                $message_type = "error";
            }
        }
    }
}

// Récupération des remontées de la classe
$remontees_query = "SELECT r.*, u.name, u.matricule, u.email,
                          c.nom_cours, e.name as nom_enseignant
                   FROM remontees r
                   JOIN users u ON r.etudiant_id = u.id
                   JOIN inscriptions i ON u.id = i.etudiant_id
                   LEFT JOIN cours c ON r.cours_id = c.id
                   LEFT JOIN users e ON c.enseignant_id = e.id
                   WHERE i.classe_id = :classe_id
                   ORDER BY r.date_creation DESC";
$remontees_stmt = $conn->prepare($remontees_query);
$remontees_stmt->bindParam(':classe_id', $classe['id']);
$remontees_stmt->execute();
$remontees = $remontees_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques des remontées
$stats_query = "SELECT
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'en_attente' THEN 1 END) as en_attente,
    COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) as en_cours,
    COUNT(CASE WHEN statut = 'resolue' THEN 1 END) as resolues,
    COUNT(CASE WHEN statut = 'fermee' THEN 1 END) as fermees
FROM remontees r
JOIN users u ON r.etudiant_id = u.id
JOIN inscriptions i ON u.id = i.etudiant_id
WHERE i.classe_id = :classe_id";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->bindParam(':classe_id', $classe['id']);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Filtrage
$filtre_statut = isset($_GET['statut']) ? sanitize($_GET['statut']) : '';
$filtre_type = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$recherche = isset($_GET['recherche']) ? sanitize($_GET['recherche']) : '';

if ($filtre_statut || $filtre_type || $recherche) {
    $remontees = array_filter($remontees, function($remontee) use ($filtre_statut, $filtre_type, $recherche) {
        $match_statut = !$filtre_statut || $remontee['statut'] === $filtre_statut;
        $match_type = !$filtre_type || $remontee['type_probleme'] === $filtre_type;
        $match_recherche = !$recherche ||
            stripos($remontee['nom'], $recherche) !== false ||
            stripos($remontee['prenom'], $recherche) !== false ||
            stripos($remontee['matricule'], $recherche) !== false ||
            stripos($remontee['description'], $recherche) !== false;
        return $match_statut && $match_type && $match_recherche;
    });
}

// Fonction pour obtenir la couleur du statut
function getStatusColor($statut) {
    switch ($statut) {
        case 'en_attente': return 'yellow';
        case 'en_cours': return 'blue';
        case 'resolue': return 'green';
        case 'fermee': return 'gray';
        default: return 'gray';
    }
}

// Fonction pour obtenir le libellé du statut
function getStatusLabel($statut) {
    switch ($statut) {
        case 'en_attente': return 'En attente';
        case 'en_cours': return 'En cours';
        case 'resolue': return 'Résolue';
        case 'fermee': return 'Fermée';
        default: return 'Inconnu';
    }
}

// Fonction pour obtenir le libellé du type de problème
function getTypeLabel($type) {
    switch ($type) {
        case 'note': return 'Problème de note';
        case 'absence': return 'Problème d\'absence';
        case 'cours': return 'Problème de cours';
        case 'administratif': return 'Problème administratif';
        case 'technique': return 'Problème technique';
        case 'autre': return 'Autre';
        default: return 'Non spécifié';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Remontées - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-exclamation-triangle text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Classe</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Classe: <?php echo htmlspecialchars($classe['nom_classe']); ?> - <?php echo htmlspecialchars($classe['nom_filiere']); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="liste_etudiants.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-users mr-1"></i>Étudiants
                </a>
                <a href="documents_classes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
                <a href="remontees.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-exclamation-triangle mr-1"></i>Remontées
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Message de succès/erreur -->
        <?php if (isset($message)): ?>
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

        <!-- Statistiques -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-bar mr-2"></i>Statistiques des remontées
            </h2>

            <div class="grid grid-cols-1 md:grid-cols-5 gap-6">
                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-list text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Total</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total']; ?></p>
                    <p class="text-sm text-gray-600">remontées</p>
                </div>

                <div class="text-center">
                    <div class="bg-yellow-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clock text-yellow-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">En attente</h3>
                    <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['en_attente']; ?></p>
                    <p class="text-sm text-gray-600">remontées</p>
                </div>

                <div class="text-center">
                    <div class="bg-blue-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-cog text-blue-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">En cours</h3>
                    <p class="text-2xl font-bold text-blue-600"><?php echo $stats['en_cours']; ?></p>
                    <p class="text-sm text-gray-600">remontées</p>
                </div>

                <div class="text-center">
                    <div class="bg-green-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check text-green-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Résolues</h3>
                    <p class="text-2xl font-bold text-green-600"><?php echo $stats['resolues']; ?></p>
                    <p class="text-sm text-gray-600">remontées</p>
                </div>

                <div class="text-center">
                    <div class="bg-gray-100 rounded-full w-16 h-16 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-times text-gray-600 text-2xl"></i>
                    </div>
                    <h3 class="font-semibold text-gray-800 mb-2">Fermées</h3>
                    <p class="text-2xl font-bold text-gray-600"><?php echo $stats['fermees']; ?></p>
                    <p class="text-sm text-gray-600">remontées</p>
                </div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-wrap items-center gap-4">
                <div>
                    <label for="recherche" class="block text-sm font-medium text-gray-700 mb-1">
                        Rechercher
                    </label>
                    <input type="text" id="recherche" placeholder="Étudiant, description..."
                           class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">
                        Statut
                    </label>
                    <select id="statut"
                            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous</option>
                        <option value="en_attente">En attente</option>
                        <option value="en_cours">En cours</option>
                        <option value="resolue">Résolue</option>
                        <option value="fermee">Fermée</option>
                    </select>
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-1">
                        Type de problème
                    </label>
                    <select id="type"
                            class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous</option>
                        <option value="note">Problème de note</option>
                        <option value="absence">Problème d'absence</option>
                        <option value="cours">Problème de cours</option>
                        <option value="administratif">Administratif</option>
                        <option value="technique">Technique</option>
                        <option value="autre">Autre</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button onclick="appliquerFiltres()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-2"></i>Filtrer
                    </button>
                </div>
            </div>
        </div>

        <!-- Liste des remontées -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-exclamation-triangle mr-2"></i>Remontées de la classe
            </h2>

            <?php if (empty($remontees)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-check-circle text-green-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune remontée</h3>
                    <p class="text-gray-500">Il n'y a pas de remontées correspondant aux critères de recherche.</p>
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($remontees as $remontee): ?>
                    <div class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition duration-200">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex items-start space-x-4">
                                <div class="flex-shrink-0">
                                    <div class="w-12 h-12 rounded-full bg-indigo-100 flex items-center justify-center">
                                        <i class="fas fa-user text-indigo-600"></i>
                                    </div>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-900">
                                        <?php echo htmlspecialchars($remontee['name']); ?>
                                        <span class="text-sm text-gray-500 font-normal">
                                            (<?php echo htmlspecialchars($remontee['matricule']); ?>)
                                        </span>
                                    </h3>
                                    <p class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($remontee['email']); ?> •
                                        <?php echo date('d/m/Y à H:i', strtotime($remontee['date_creation'])); ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center space-x-2">
                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-<?php echo getStatusColor($remontee['statut']); ?>-100 text-<?php echo getStatusColor($remontee['statut']); ?>-800">
                                    <?php echo getStatusLabel($remontee['statut']); ?>
                                </span>
                                <span class="px-3 py-1 text-xs font-semibold rounded-full bg-gray-100 text-gray-800">
                                    <?php echo getTypeLabel($remontee['type_probleme']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <h4 class="font-medium text-gray-900 mb-2">
                                <?php if ($remontee['nom_cours']): ?>
                                    Cours: <?php echo htmlspecialchars($remontee['nom_cours']); ?>
                                    <?php if ($remontee['nom_enseignant']): ?>
                                        (<?php echo htmlspecialchars($remontee['nom_enseignant']); ?>)
                                    <?php endif; ?>
                                <?php else: ?>
                                    Problème général
                                <?php endif; ?>
                            </h4>
                            <p class="text-gray-700 bg-gray-50 p-3 rounded-md">
                                <?php echo nl2br(htmlspecialchars($remontee['description'])); ?>
                            </p>
                        </div>

                        <?php if ($remontee['commentaire_etudiant']): ?>
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-900 mb-2">Commentaire de l'étudiant:</h4>
                            <p class="text-gray-600 bg-blue-50 p-3 rounded-md italic">
                                "<?php echo nl2br(htmlspecialchars($remontee['commentaire_etudiant'])); ?>"
                            </p>
                        </div>
                        <?php endif; ?>

                        <?php if ($remontee['commentaire_responsable']): ?>
                        <div class="mb-4">
                            <h4 class="font-medium text-gray-900 mb-2">Votre réponse:</h4>
                            <p class="text-gray-700 bg-green-50 p-3 rounded-md">
                                <?php echo nl2br(htmlspecialchars($remontee['commentaire_responsable'])); ?>
                            </p>
                            <?php if ($remontee['date_traitement']): ?>
                                <p class="text-xs text-gray-500 mt-1">
                                    Traité le <?php echo date('d/m/Y à H:i', strtotime($remontee['date_traitement'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-500">
                                Priorité: <span class="font-medium"><?php echo ucfirst($remontee['priorite']); ?></span>
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($remontee['statut'] !== 'fermee'): ?>
                                <button onclick="repondreRemontee(<?php echo $remontee['id']; ?>)"
                                        class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-reply mr-1"></i>Répondre
                                </button>
                                <?php endif; ?>
                                <button onclick="changerStatut(<?php echo $remontee['id']; ?>, '<?php echo $remontee['statut']; ?>')"
                                        class="bg-gray-600 hover:bg-gray-700 text-white text-sm font-medium py-2 px-4 rounded-md transition duration-200">
                                    <i class="fas fa-edit mr-1"></i>Changer statut
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Informations sur la gestion des remontées -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-6 mt-8">
            <div class="flex">
                <div class="flex-shrink-0">
                    <i class="fas fa-info-circle text-blue-400"></i>
                </div>
                <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">
                        Gestion des remontées
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Traitez rapidement les remontées en attente pour maintenir la satisfaction des étudiants</li>
                            <li>Utilisez les filtres pour prioriser les problèmes urgents</li>
                            <li>Communiquez clairement avec les étudiants via les commentaires</li>
                            <li>Les problèmes de notes et d'absences nécessitent souvent une attention particulière</li>
                            <li>Archivez les remontées résolues pour garder un historique</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Modal de réponse -->
    <div id="reponseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-reply mr-2"></i>Répondre à la remontée
                        </h3>
                        <button onclick="closeReponseModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <form id="reponseForm" method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" id="reponseRemonteeId" name="remontee_id">
                        <input type="hidden" id="reponseStatus" name="status" value="en_cours">

                        <div class="mb-4">
                            <label for="reponseCommentaire" class="block text-sm font-medium text-gray-700 mb-2">
                                Votre réponse *
                            </label>
                            <textarea id="reponseCommentaire" name="commentaire" rows="4"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="Expliquez les actions que vous allez prendre..." required></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeReponseModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-paper-plane mr-2"></i>Envoyer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de changement de statut -->
    <div id="statutModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-lg w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-edit mr-2"></i>Changer le statut
                        </h3>
                        <button onclick="closeStatutModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <form id="statutForm" method="POST">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" id="statutRemonteeId" name="remontee_id">

                        <div class="mb-4">
                            <label for="nouveauStatut" class="block text-sm font-medium text-gray-700 mb-2">
                                Nouveau statut *
                            </label>
                            <select id="nouveauStatut" name="status"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                                <option value="en_attente">En attente</option>
                                <option value="en_cours">En cours</option>
                                <option value="resolue">Résolue</option>
                                <option value="fermee">Fermée</option>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label for="statutCommentaire" class="block text-sm font-medium text-gray-700 mb-2">
                                Commentaire (optionnel)
                            </label>
                            <textarea id="statutCommentaire" name="commentaire" rows="3"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500"
                                    placeholder="Ajoutez un commentaire sur ce changement..."></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeStatutModal()"
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

    <!-- Footer -->
    <footer class="bg-gray-800 text-white mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center">
                <p>&copy; 2024 Institut Supérieur de Technologie et d'Informatique. Tous droits réservés.</p>
            </div>
        </div>
    </footer>

    <script>
        function appliquerFiltres() {
            const recherche = document.getElementById('recherche').value;
            const statut = document.getElementById('statut').value;
            const type = document.getElementById('type').value;

            let url = window.location.pathname;
            const params = new URLSearchParams();

            if (recherche) params.append('recherche', recherche);
            if (statut) params.append('statut', statut);
            if (type) params.append('type', type);

            if (params.toString()) {
                url += '?' + params.toString();
            }

            window.location.href = url;
        }

        function repondreRemontee(remonteeId) {
            document.getElementById('reponseRemonteeId').value = remonteeId;
            document.getElementById('reponseModal').classList.remove('hidden');
            document.getElementById('reponseCommentaire').focus();
        }

        function closeReponseModal() {
            document.getElementById('reponseModal').classList.add('hidden');
            document.getElementById('reponseForm').reset();
        }

        function changerStatut(remonteeId, currentStatus) {
            document.getElementById('statutRemonteeId').value = remonteeId;
            document.getElementById('nouveauStatut').value = currentStatus;
            document.getElementById('statutModal').classList.remove('hidden');
        }

        function closeStatutModal() {
            document.getElementById('statutModal').classList.add('hidden');
            document.getElementById('statutForm').reset();
        }

        // Fermer les modals en cliquant en dehors
        document.getElementById('reponseModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeReponseModal();
            }
        });

        document.getElementById('statutModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeStatutModal();
            }
        });

        // Recherche en temps réel
        document.getElementById('recherche').addEventListener('keyup', function(e) {
            if (e.key === 'Enter') {
                appliquerFiltres();
            }
        });
    </script>
</body>
</html>