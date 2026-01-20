<?php
/**
 * Documents historiques - Agent Administratif
 * Permet de consulter l'historique des documents et leur évolution
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

// Filtres
$etudiant_filter = isset($_GET['etudiant']) ? sanitize($_GET['etudiant']) : '';
$date_debut = isset($_GET['date_debut']) ? sanitize($_GET['date_debut']) : '';
$date_fin = isset($_GET['date_fin']) ? sanitize($_GET['date_fin']) : '';
$type_filter = isset($_GET['type']) ? sanitize($_GET['type']) : '';
$statut_filter = isset($_GET['statut']) ? sanitize($_GET['statut']) : '';

// Construction de la requête avec filtres
$query = "SELECT d.*, SUBSTRING_INDEX(d.fichier_url, '/', -1) AS nom_fichier,
                 u.name, u.matricule, u.email,
                 c.nom_classe, f.nom, dep.nom,
                 v.name as valideur_nom
          FROM documents d
          JOIN users u ON d.user_id = u.id
          LEFT JOIN inscriptions i ON u.id = i.user_id
          LEFT JOIN classes c ON i.classe_id = c.id
          LEFT JOIN filieres f ON c.filiere_id = f.id
          LEFT JOIN departements dep ON f.departement_id = dep.id
          LEFT JOIN users v ON d.valide_par = v.id
          WHERE 1=1";

$params = [];

if ($etudiant_filter) {
    $query .= " AND (u.name LIKE :etudiant OR u.matricule LIKE :etudiant OR u.email LIKE :etudiant)";
    $params[':etudiant'] = '%' . $etudiant_filter . '%';
}

if ($date_debut) {
    $query .= " AND DATE(d.date_creation) >= :date_debut";
    $params[':date_debut'] = $date_debut;
}

if ($date_fin) {
    $query .= " AND DATE(d.date_creation) <= :date_fin";
    $params[':date_fin'] = $date_fin;
}

if ($type_filter) {
    $query .= " AND d.type_document = :type";
    $params[':type'] = $type_filter;
}

if ($statut_filter) {
    $query .= " AND d.statut = :statut";
    $params[':statut'] = $statut_filter;
}

$query .= " ORDER BY d.date_creation DESC LIMIT 200";

$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistiques historiques
$stats_query = "SELECT
    COUNT(*) as total_historique,
    COUNT(CASE WHEN statut = 'valide' THEN 1 END) as total_valides,
    COUNT(CASE WHEN statut = 'rejete' THEN 1 END) as total_rejetes,
    COUNT(CASE WHEN DATE(date_creation) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as dernier_mois,
    NULL as delai_moyen_validation
FROM documents";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Évolution mensuelle des documents
$evolution_query = "SELECT
    DATE_FORMAT(date_creation, '%Y-%m') as mois,
    COUNT(*) as total,
    COUNT(CASE WHEN statut = 'valide' THEN 1 END) as valides,
    COUNT(CASE WHEN statut = 'rejete' THEN 1 END) as rejetes
FROM documents
WHERE date_creation >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
GROUP BY DATE_FORMAT(date_creation, '%Y-%m')
ORDER BY mois DESC";
$evolution_stmt = $conn->prepare($evolution_query);
$evolution_stmt->execute();
$evolution = $evolution_stmt->fetchAll(PDO::FETCH_ASSOC);

// Types de documents disponibles
$types_documents = [
    'carte_identite' => 'Carte d\'identité',
    'diplome_bac' => 'Diplôme BAC',
    'releve_notes_bac' => 'Relevé de notes BAC',
    'certificat_medical' => 'Certificat médical',
    'photo_identite' => 'Photo d\'identité',
    'autre' => 'Autre'
];

// Top 10 des étudiants avec le plus de documents
$top_etudiants_query = "SELECT u.name, u.matricule, COUNT(d.id) as nb_documents,
                              COUNT(CASE WHEN d.statut = 'valide' THEN 1 END) as nb_valides,
                              COUNT(CASE WHEN d.statut = 'rejete' THEN 1 END) as nb_rejetes
                       FROM users u
                       JOIN documents d ON u.id = d.user_id
                       WHERE u.role = 'etudiant'
                       GROUP BY u.id, u.name, u.matricule
                       ORDER BY nb_documents DESC LIMIT 10";
$top_etudiants_stmt = $conn->prepare($top_etudiants_query);
$top_etudiants_stmt->execute();
$top_etudiants = $top_etudiants_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents Historiques - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-history text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Agent Administratif</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?></span>
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
                <a href="inscriptions.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="attestation_inscription.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-contract mr-1"></i>Attestations
                </a>
                <a href="certificat_scolarite.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Certificats
                </a>
                <a href="releve_notes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chart-bar mr-1"></i>Relevés
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="documents_historiques.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-history mr-1"></i>Historique
                </a>
                <a href="saisie_donnees.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-edit mr-1"></i>Saisie
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Statistiques générales -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-file-alt text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total historique</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_historique']; ?></p>
                        <p class="text-sm text-gray-600">documents</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-check text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Validés</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_valides']; ?></p>
                        <p class="text-sm text-gray-600">approuvés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-red-100 rounded-full p-3">
                        <i class="fas fa-times text-red-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Rejetés</h3>
                        <p class="text-2xl font-bold text-red-600"><?php echo $stats['total_rejetes']; ?></p>
                        <p class="text-sm text-gray-600">refusés</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-calendar-alt text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Dernier mois</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['dernier_mois']; ?></p>
                        <p class="text-sm text-gray-600">documents</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-clock text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Délai moyen</h3>
                        <p class="text-2xl font-bold text-purple-600">
                            <?php echo $stats['delai_moyen_validation'] ? round($stats['delai_moyen_validation'], 1) : 'N/A'; ?>
                        </p>
                        <p class="text-sm text-gray-600">jours</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Évolution mensuelle -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-chart-line mr-2"></i>Évolution mensuelle des documents (12 derniers mois)
            </h2>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Mois
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Total
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Validés
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Rejetés
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Taux de validation
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($evolution as $mois): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                <?php
                                $date = DateTime::createFromFormat('Y-m', $mois['mois']);
                                echo $date ? $date->format('M Y') : $mois['mois'];
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $mois['total']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                <?php echo $mois['valides']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                <?php echo $mois['rejetes']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php
                                $taux = $mois['total'] > 0 ? round(($mois['valides'] / $mois['total']) * 100, 1) : 0;
                                echo $taux . '%';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top 10 des étudiants -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-trophy mr-2"></i>Top 10 - Étudiants avec le plus de documents
            </h2>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Étudiant
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Total documents
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Validés
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Rejetés
                            </th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                Taux de succès
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($top_etudiants as $etudiant): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($etudiant['name']); ?>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo htmlspecialchars($etudiant['matricule']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php echo $etudiant['nb_documents']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600">
                                <?php echo $etudiant['nb_valides']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600">
                                <?php echo $etudiant['nb_rejetes']; ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                <?php
                                $taux = $etudiant['nb_documents'] > 0 ? round(($etudiant['nb_valides'] / $etudiant['nb_documents']) * 100, 1) : 0;
                                echo $taux . '%';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Filtres et recherche -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-filter mr-2"></i>Filtres de recherche historique
            </h2>

            <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                <div>
                    <label for="etudiant" class="block text-sm font-medium text-gray-700 mb-2">
                        Étudiant
                    </label>
                    <input type="text" id="etudiant" name="etudiant" value="<?php echo htmlspecialchars($etudiant_filter); ?>"
                           placeholder="Nom, prénom, matricule..." class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-2">
                        Date début
                    </label>
                    <input type="date" id="date_debut" name="date_debut" value="<?php echo htmlspecialchars($date_debut); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-2">
                        Date fin
                    </label>
                    <input type="date" id="date_fin" name="date_fin" value="<?php echo htmlspecialchars($date_fin); ?>"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <div>
                    <label for="type" class="block text-sm font-medium text-gray-700 mb-2">
                        Type de document
                    </label>
                    <select id="type" name="type" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les types</option>
                        <?php foreach ($types_documents as $key => $value): ?>
                            <option value="<?php echo $key; ?>" <?php echo $type_filter === $key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($value); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-2">
                        Statut
                    </label>
                    <select id="statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tous les statuts</option>
                        <option value="en_attente" <?php echo $statut_filter === 'en_attente' ? 'selected' : ''; ?>>En attente</option>
                        <option value="valide" <?php echo $statut_filter === 'valide' ? 'selected' : ''; ?>>Validé</option>
                        <option value="rejete" <?php echo $statut_filter === 'rejete' ? 'selected' : ''; ?>>Rejeté</option>
                    </select>
                </div>

                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-search mr-1"></i>Filtrer
                    </button>
                    <a href="documents_historiques.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-times mr-1"></i>Réinitialiser
                    </a>
                </div>
            </form>
        </div>

        <!-- Liste historique des documents -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-list mr-2"></i>Historique détaillé des documents
            </h2>

            <?php if (empty($documents)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-history text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun document trouvé</h3>
                    <p class="text-gray-500">Aucun document ne correspond aux critères de recherche.</p>
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
                                    Document
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Upload
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Validation
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Statut
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Validé par
                                </th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Délai
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($documents as $document): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($document['name']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($document['matricule']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($document['nom_classe'] ?? 'N/A'); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($document['nom_fichier']); ?>
                                    </div>
                                    <div class="text-sm text-gray-500">-</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($types_documents[$document['type_document']] ?? $document['type_document']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                     <?php echo date('d/m/Y H:i', strtotime($document['date_creation'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php
                                        switch ($document['statut']) {
                                            case 'en_attente': echo 'bg-yellow-100 text-yellow-800'; break;
                                            case 'valide': echo 'bg-green-100 text-green-800'; break;
                                            case 'rejete': echo 'bg-red-100 text-red-800'; break;
                                            default: echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php
                                        switch ($document['statut']) {
                                            case 'en_attente': echo 'En attente'; break;
                                            case 'valide': echo 'Validé'; break;
                                            case 'rejete': echo 'Rejeté'; break;
                                            default: echo ucfirst($document['statut']);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $document['valideur_nom'] ? htmlspecialchars($document['valideur_nom']) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-gray-900">
                                        echo '-';
                                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Export -->
                <div class="mt-6 flex justify-end">
                    <button onclick="exporterHistorique()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                        <i class="fas fa-download mr-2"></i>Exporter l'historique (CSV)
                    </button>
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

    <script>
        function exporterHistorique() {
            // Simulation de l'export (en production, générer un vrai CSV)
            alert('Export de l\'historique CSV simulé (fonctionnalité à implémenter)');
        }
    </script>
</body>
</html>