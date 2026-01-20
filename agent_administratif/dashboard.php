<?php
/**
 * Dashboard de l'agent administratif
 * Gestion des inscriptions, documents, attestations, etc.
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

// Statistiques des inscriptions
$stats_inscriptions = [
    'total' => 0,
    'inscrit' => 0,
    'reinscrit' => 0,
    'abandon' => 0
];

$inscriptions_query = "SELECT statut, COUNT(*) as count FROM inscriptions GROUP BY statut";
$inscriptions_stmt = $conn->prepare($inscriptions_query);
$inscriptions_stmt->execute();
$inscriptions_stats = $inscriptions_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($inscriptions_stats as $stat) {
    $stats_inscriptions[$stat['statut']] = $stat['count'];
    $stats_inscriptions['total'] += $stat['count'];
}

// Documents en attente de validation
$docs_pending_query = "SELECT d.*, u.name as user_name, u.email 
                      FROM documents_inscription d
                      JOIN users u ON d.user_id = u.id
                      WHERE d.statut = 'soumis'
                      ORDER BY d.date_upload DESC LIMIT 10";
$docs_pending_stmt = $conn->prepare($docs_pending_query);
$docs_pending_stmt->execute();
$docs_pending = $docs_pending_stmt->fetchAll(PDO::FETCH_ASSOC);

// Étudiants prêts pour inscription (documents validés)
$etudiants_prets_query = "SELECT COUNT(DISTINCT u.id) as count
                         FROM users u
                         JOIN user_roles ur ON u.id = ur.user_id
                         JOIN documents_inscription d ON u.id = d.user_id
                         LEFT JOIN inscriptions i ON u.id = i.user_id 
                             AND i.annee_academique = :annee_actuelle
                         WHERE ur.role = 'etudiant'
                             AND d.statut = 'valide'
                             AND i.id IS NULL
                         GROUP BY u.id
                         HAVING COUNT(DISTINCT d.id) >= 2";
$annee_actuelle = date('Y') . '/' . (date('Y') + 1);
$etudiants_prets_stmt = $conn->prepare($etudiants_prets_query);
$etudiants_prets_stmt->bindParam(':annee_actuelle', $annee_actuelle);
$etudiants_prets_stmt->execute();
$nb_etudiants_prets = $etudiants_prets_stmt->rowCount();

// Inscriptions récentes
$inscriptions_recent_query = "SELECT i.*, u.name as user_name, c.niveau, f.nom as filiere
                             FROM inscriptions i
                             JOIN users u ON i.user_id = u.id
                             JOIN classes c ON i.classe_id = c.id
                             JOIN filieres f ON c.filiere_id = f.id
                             ORDER BY i.id DESC LIMIT 10";
$inscriptions_recent_stmt = $conn->prepare($inscriptions_recent_query);
$inscriptions_recent_stmt->execute();
$inscriptions_recent = $inscriptions_recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Notifications récentes
$notif_query = "SELECT * FROM notifications WHERE type = 'admin' ORDER BY date_envoi DESC LIMIT 5";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Agent Administratif - ISTI</title>
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
                <a href="dashboard.php" class="text-purple-600 border-b-2 border-purple-600 pb-2">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-purple-600">
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
        <!-- Statistiques des inscriptions -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Total inscriptions</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats_inscriptions['total']; ?></p>
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
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats_inscriptions['inscrit']; ?></p>
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
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats_inscriptions['reinscrit']; ?></p>
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
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats_inscriptions['abandon']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <a href="nouvelles_inscriptions.php" class="flex items-center hover:bg-purple-50 transition duration-200">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-user-plus text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Prêts inscription</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $nb_etudiants_prets; ?></p>
                    </div>
                </a>
            </div>
        </div>

        <!-- Documents en attente -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-bold text-gray-800">
                    <i class="fas fa-clock mr-2"></i>Documents en attente de validation
                </h2>
                <a href="validation_documents.php" class="text-purple-600 hover:text-purple-800 font-medium">
                    Voir tout <i class="fas fa-arrow-right ml-1"></i>
                </a>
            </div>
            <?php if (empty($docs_pending)): ?>
                <p class="text-gray-600">Aucun document en attente.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($docs_pending as $doc): ?>
                    <div class="flex items-center justify-between p-4 bg-yellow-50 rounded-lg border-l-4 border-yellow-400">
                        <div>
                            <h4 class="font-semibold text-gray-800">
                                <?php 
                                $labels = ['releve_bac' => 'Relevé du BAC', 'diplome_bac' => 'Diplôme du BAC'];
                                echo htmlspecialchars($labels[$doc['type_document']] ?? $doc['type_document']); 
                                ?>
                            </h4>
                            <p class="text-sm text-gray-600">
                                Demandeur: <?php echo htmlspecialchars($doc['user_name']); ?> (<?php echo htmlspecialchars($doc['email']); ?>) | 
                                Date: <?php echo date('d/m/Y H:i', strtotime($doc['date_upload'])); ?>
                            </p>
                        </div>
                        <div>
                            <a href="validation_documents.php?user_id=<?php echo $doc['user_id']; ?>" 
                               class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded text-sm transition duration-200">
                                <i class="fas fa-eye mr-1"></i>Voir et valider
                            </a>
                        </div>
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Inscriptions récentes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-user-plus mr-2"></i>Inscriptions récentes
            </h2>
            <?php if (empty($inscriptions_recent)): ?>
                <p class="text-gray-600">Aucune inscription récente.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classe</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($inscriptions_recent as $inscription): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['user_name']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['niveau'] . ' - ' . $inscription['filiere']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($inscription['annee_academique']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm">
                                    <?php if ($inscription['statut'] == 'inscrit'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Inscrit</span>
                                    <?php elseif ($inscription['statut'] == 'reinscrit'): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">Réinscrit</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Abandon</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notifications administratives -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-bell mr-2"></i>Notifications administratives
            </h2>
            <?php if (empty($notifications)): ?>
                <p class="text-gray-600">Aucune notification administrative.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($notifications as $notif): ?>
                    <div class="p-4 bg-blue-50 rounded-lg border-l-4 border-blue-400">
                        <p class="text-gray-800"><?php echo htmlspecialchars($notif['message']); ?></p>
                        <p class="text-sm text-gray-600">Date: <?php echo htmlspecialchars($notif['date_envoi']); ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
