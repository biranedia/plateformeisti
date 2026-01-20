<?php
/**
 * Dashboard pour les responsables de classe
 * Vue d'ensemble des informations importantes de la classe
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
$classe_query = "SELECT c.*, f.nom, d.nom_departement
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

// Statistiques générales de la classe
$stats_generales_query = "SELECT
    COUNT(DISTINCT u.id) as total_etudiants,
    COUNT(CASE WHEN i.statut = 'active' THEN 1 END) as etudiants_actifs,
    COUNT(CASE WHEN i.statut = 'inactive' THEN 1 END) as etudiants_inactifs,
    AVG(CASE WHEN n.note IS NOT NULL THEN n.note END) as moyenne_classe,
    COUNT(DISTINCT c.id) as nombre_cours,
    COUNT(DISTINCT r.id) as nombre_remontees
FROM inscriptions i
JOIN users u ON i.etudiant_id = u.id
LEFT JOIN notes n ON u.id = n.etudiant_id
LEFT JOIN cours c ON i.classe_id = c.classe_id
LEFT JOIN remontees r ON u.id = r.etudiant_id
WHERE i.classe_id = :classe_id";
$stats_generales_stmt = $conn->prepare($stats_generales_query);
$stats_generales_stmt->bindParam(':classe_id', $classe['id']);
$stats_generales_stmt->execute();
$stats_generales = $stats_generales_stmt->fetch(PDO::FETCH_ASSOC);

// Statistiques de présence
$presence_query = "SELECT
    SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) as total_presences,
    COUNT(p.id) as total_cours_presence,
    ROUND(
        CASE WHEN COUNT(p.id) > 0
        THEN (SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(p.id))
        ELSE 0 END, 1
    ) as taux_presence_global
FROM inscriptions i
JOIN users u ON i.etudiant_id = u.id
LEFT JOIN presence p ON u.id = p.etudiant_id
WHERE i.classe_id = :classe_id";
$presence_stmt = $conn->prepare($presence_query);
$presence_stmt->bindParam(':classe_id', $classe['id']);
$presence_stmt->execute();
$stats_presence = $presence_stmt->fetch(PDO::FETCH_ASSOC);

// Statistiques des feedbacks
$feedbacks_query = "SELECT
    COUNT(f.id) as total_feedbacks,
    AVG(f.note_globale) as moyenne_feedback,
    COUNT(DISTINCT f.etudiant_id) as etudiants_feedback
FROM feedback_etudiants f
JOIN users u ON f.etudiant_id = u.id
JOIN inscriptions i ON u.id = i.etudiant_id
WHERE i.classe_id = :classe_id";
$feedbacks_stmt = $conn->prepare($feedbacks_query);
$feedbacks_stmt->bindParam(':classe_id', $classe['id']);
$feedbacks_stmt->execute();
$stats_feedbacks = $feedbacks_stmt->fetch(PDO::FETCH_ASSOC);

// Remontées récentes (5 dernières)
$remontees_recentes_query = "SELECT r.*, u.name, u.matricule
                            FROM remontees r
                            JOIN users u ON r.etudiant_id = u.id
                            JOIN inscriptions i ON u.id = i.etudiant_id
                            WHERE i.classe_id = :classe_id
                            ORDER BY r.date_creation DESC
                            LIMIT 5";
$remontees_recentes_stmt = $conn->prepare($remontees_recentes_query);
$remontees_recentes_stmt->bindParam(':classe_id', $classe['id']);
$remontees_recentes_stmt->execute();
$remontees_recentes = $remontees_recentes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Documents récents (5 derniers)
$documents_query = "SELECT d.*, u.name as nom_uploader
                   FROM documents d
                   JOIN users u ON d.uploaded_by = u.id
                   WHERE d.classe_id = :classe_id AND d.type_document = 'classe' AND d.statut = 'active'
                   ORDER BY d.date_upload DESC
                   LIMIT 5";
$documents_stmt = $conn->prepare($documents_query);
$documents_stmt->bindParam(':classe_id', $classe['id']);
$documents_stmt->execute();
$documents_recents = $documents_stmt->fetchAll(PDO::FETCH_ASSOC);

// Étudiants avec les meilleures moyennes (Top 5)
$top_etudiants_query = "SELECT u.name, u.matricule,
                               AVG(n.note) as moyenne,
                               COUNT(n.id) as nombre_notes
                       FROM users u
                       JOIN inscriptions i ON u.id = i.etudiant_id
                       LEFT JOIN notes n ON u.id = n.etudiant_id
                       WHERE i.classe_id = :classe_id
                       GROUP BY u.id, u.name, u.matricule
                       HAVING COUNT(n.id) > 0
                       ORDER BY moyenne DESC
                       LIMIT 5";
$top_etudiants_stmt = $conn->prepare($top_etudiants_query);
$top_etudiants_stmt->bindParam(':classe_id', $classe['id']);
$top_etudiants_stmt->execute();
$top_etudiants = $top_etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Étudiants avec le plus faible taux de présence (5 derniers)
$presence_faible_query = "SELECT u.name, u.matricule,
                                COUNT(p.id) as total_cours,
                                SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) as presences,
                                ROUND(
                                    CASE WHEN COUNT(p.id) > 0
                                    THEN (SUM(CASE WHEN p.present = 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(p.id))
                                    ELSE 0 END, 1
                                ) as taux_presence
                         FROM users u
                         JOIN inscriptions i ON u.id = i.etudiant_id
                         LEFT JOIN presence p ON u.id = p.etudiant_id
                         WHERE i.classe_id = :classe_id
                         GROUP BY u.id, u.name, u.matricule
                         HAVING COUNT(p.id) > 0
                         ORDER BY taux_presence ASC
                         LIMIT 5";
$presence_faible_stmt = $conn->prepare($presence_faible_query);
$presence_faible_stmt->bindParam(':classe_id', $classe['id']);
$presence_faible_stmt->execute();
$etudiants_presence_faible = $presence_faible_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fonction pour obtenir la couleur selon le statut
function getStatusColor($statut) {
    switch ($statut) {
        case 'en_attente': return 'yellow';
        case 'en_cours': return 'blue';
        case 'resolue': return 'green';
        case 'fermee': return 'gray';
        default: return 'gray';
    }
}

// Fonction pour obtenir l'icône selon le type de fichier
function getFileIcon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    switch ($extension) {
        case 'pdf': return 'fas fa-file-pdf text-red-500';
        case 'doc':
        case 'docx': return 'fas fa-file-word text-blue-500';
        case 'xls':
        case 'xlsx': return 'fas fa-file-excel text-green-500';
        case 'ppt':
        case 'pptx': return 'fas fa-file-powerpoint text-orange-500';
        default: return 'fas fa-file text-gray-500';
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-tachometer-alt text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Responsable Classe</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Classe: <?php echo htmlspecialchars($classe['nom_classe']); ?> - <?php echo htmlspecialchars($classe['nom']); ?></span>
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
                <a href="dashboard.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
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
                <a href="remontees.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-exclamation-triangle mr-1"></i>Remontées
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Informations de la classe -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold text-gray-800">
                        <i class="fas fa-school mr-2"></i><?php echo htmlspecialchars($classe['nom_classe']); ?>
                    </h2>
                    <p class="text-gray-600 mt-1">
                        <?php echo htmlspecialchars($classe['nom']); ?> - <?php echo htmlspecialchars($classe['nom_departement']); ?>
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-500">Année académique</p>
                    <p class="font-semibold"><?php echo htmlspecialchars($classe['annee_academique'] ?? '2024-2025'); ?></p>
                </div>
            </div>
        </div>

        <!-- Statistiques principales -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <!-- Étudiants -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Étudiants</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_generales['total_etudiants']; ?></p>
                        <p class="text-sm text-gray-600">
                            <?php echo $stats_generales['etudiants_actifs']; ?> actifs,
                            <?php echo $stats_generales['etudiants_inactifs']; ?> inactifs
                        </p>
                    </div>
                </div>
            </div>

            <!-- Moyenne de classe -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chart-line text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Moyenne classe</h3>
                        <p class="text-2xl font-bold text-green-600">
                            <?php echo $stats_generales['moyenne_classe'] ? number_format($stats_generales['moyenne_classe'], 1) : 'N/A'; ?>/20
                        </p>
                        <p class="text-sm text-gray-600">moyenne générale</p>
                    </div>
                </div>
            </div>

            <!-- Taux de présence -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-percentage text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Présence</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats_presence['taux_presence_global']; ?>%</p>
                        <p class="text-sm text-gray-600">
                            <?php echo $stats_presence['total_presences']; ?>/<?php echo $stats_presence['total_cours_presence']; ?> cours
                        </p>
                    </div>
                </div>
            </div>

            <!-- Feedbacks -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-comments text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Feedbacks</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_feedbacks['total_feedbacks']; ?></p>
                        <p class="text-sm text-gray-600">
                            <?php echo $stats_feedbacks['etudiants_feedback']; ?> étudiants
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Graphiques et analyses -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Top 5 étudiants -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-trophy mr-2 text-yellow-500"></i>Top 5 Étudiants
                </h3>

                <?php if (empty($top_etudiants)): ?>
                    <p class="text-gray-500 text-center py-4">Aucune note disponible</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($top_etudiants as $index => $etudiant): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-yellow-100 flex items-center justify-center text-sm font-bold text-yellow-600 mr-3">
                                    <?php echo $index + 1; ?>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900">
                                        <?php echo htmlspecialchars($etudiant['name']); ?>
                                    </p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($etudiant['matricule']); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-green-600"><?php echo number_format($etudiant['moyenne'], 1); ?>/20</p>
                                <p class="text-xs text-gray-500"><?php echo $etudiant['nombre_notes']; ?> notes</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Étudiants avec faible présence -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Présence à surveiller
                </h3>

                <?php if (empty($etudiants_presence_faible)): ?>
                    <p class="text-gray-500 text-center py-4">Aucune donnée de présence disponible</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($etudiants_presence_faible as $etudiant): ?>
                        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-md">
                            <div class="flex items-center">
                                <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center mr-3">
                                    <i class="fas fa-user text-red-600"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-gray-900">
                                        <?php echo htmlspecialchars($etudiant['name']); ?>
                                    </p>
                                    <p class="text-sm text-gray-500"><?php echo htmlspecialchars($etudiant['matricule']); ?></p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="font-bold text-red-600"><?php echo $etudiant['taux_presence']; ?>%</p>
                                <p class="text-xs text-gray-500"><?php echo $etudiant['presences']; ?>/<?php echo $etudiant['total_cours']; ?> cours</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Remontées récentes et documents -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            <!-- Remontées récentes -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-exclamation-triangle mr-2"></i>Remontées récentes
                    </h3>
                    <a href="remontees.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                        Voir tout <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($remontees_recentes)): ?>
                    <p class="text-gray-500 text-center py-4">Aucune remontée récente</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($remontees_recentes as $remontee): ?>
                        <div class="border border-gray-200 rounded-md p-3">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <p class="font-medium text-gray-900 text-sm">
                                        <?php echo htmlspecialchars($remontee['name']); ?>
                                    </p>
                                    <p class="text-xs text-gray-500">
                                        <?php echo date('d/m/Y H:i', strtotime($remontee['date_creation'])); ?>
                                    </p>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-<?php echo getStatusColor($remontee['statut']); ?>-100 text-<?php echo getStatusColor($remontee['statut']); ?>-800">
                                    <?php echo ucfirst(str_replace('_', ' ', $remontee['statut'])); ?>
                                </span>
                            </div>
                            <p class="text-sm text-gray-700 line-clamp-2">
                                <?php echo htmlspecialchars(substr($remontee['description'], 0, 100)); ?>...
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Documents récents -->
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-800">
                        <i class="fas fa-file-alt mr-2"></i>Documents récents
                    </h3>
                    <a href="documents_classes.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                        Voir tout <i class="fas fa-arrow-right ml-1"></i>
                    </a>
                </div>

                <?php if (empty($documents_recents)): ?>
                    <p class="text-gray-500 text-center py-4">Aucun document récent</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($documents_recents as $document): ?>
                        <div class="flex items-center p-3 bg-gray-50 rounded-md">
                            <i class="<?php echo getFileIcon($document['fichier_path']); ?> text-xl mr-3"></i>
                            <div class="flex-1">
                                <p class="font-medium text-gray-900 text-sm">
                                    <?php echo htmlspecialchars($document['titre']); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    Par <?php echo htmlspecialchars($document['nom_uploader']); ?> •
                                    <?php echo date('d/m/Y', strtotime($document['date_upload'])); ?>
                                </p>
                            </div>
                            <a href="../uploads/documents_classe/<?php echo htmlspecialchars($document['fichier_path']); ?>"
                               target="_blank"
                               class="text-indigo-600 hover:text-indigo-800 ml-2">
                                <i class="fas fa-eye"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-bolt mr-2"></i>Actions rapides
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <a href="liste_etudiants.php" class="flex items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition duration-200">
                    <i class="fas fa-users text-blue-600 text-2xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-blue-800">Voir les étudiants</h4>
                        <p class="text-sm text-blue-600">Consulter la liste complète</p>
                    </div>
                </a>

                <a href="documents_classes.php" class="flex items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition duration-200">
                    <i class="fas fa-file-upload text-green-600 text-2xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-green-800">Ajouter un document</h4>
                        <p class="text-sm text-green-600">Partager avec la classe</p>
                    </div>
                </a>

                <a href="remontees.php" class="flex items-center p-4 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition duration-200">
                    <i class="fas fa-exclamation-triangle text-yellow-600 text-2xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-yellow-800">Traiter les remontées</h4>
                        <p class="text-sm text-yellow-600">Gérer les problèmes</p>
                    </div>
                </a>

                <a href="feedback.php" class="flex items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition duration-200">
                    <i class="fas fa-comments text-purple-600 text-2xl mr-3"></i>
                    <div>
                        <h4 class="font-semibold text-purple-800">Analyser les feedbacks</h4>
                        <p class="text-sm text-purple-600">Voir les évaluations</p>
                    </div>
                </a>
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
                        Conseils pour la gestion de classe
                    </h3>
                    <div class="mt-2 text-sm text-blue-700">
                        <ul class="list-disc list-inside space-y-1">
                            <li>Surveillez régulièrement les moyennes et présences de vos étudiants</li>
                            <li>Traitez rapidement les remontées pour maintenir la satisfaction</li>
                            <li>Encouragez les feedbacks pour améliorer la qualité des cours</li>
                            <li>Partagez régulièrement des documents utiles avec vos étudiants</li>
                            <li>Contactez les étudiants en difficulté pour un suivi personnalisé</li>
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
</body>
</html>