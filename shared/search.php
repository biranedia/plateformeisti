<?php
/**
 * Système de recherche global - ISTI Platform
 * Recherche dans tous les modules selon le rôle de l'utilisateur
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification
if (!isLoggedIn()) {
    redirectWithMessage('login.php', 'Vous devez être connecté pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'] ?? '';

// Variables de recherche
$query = sanitize($_GET['q'] ?? '');
$category = sanitize($_GET['category'] ?? 'all');
$limit = (int)($_GET['limit'] ?? 50);
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $limit;

// Résultats de recherche
$results = [];
$total_results = 0;
$search_time = 0;

// Traitement de la recherche
if (!empty($query) && strlen($query) >= 2) {
    $start_time = microtime(true);

    try {
        // Recherche selon le rôle de l'utilisateur
        switch ($user_role) {
            case 'admin_general':
                $results = searchAsAdminGeneral($conn, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsAdminGeneral($conn, $query, $category);
                break;

            case 'agent_administratif':
                $results = searchAsAgentAdministratif($conn, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsAgentAdministratif($conn, $query, $category);
                break;

            case 'resp_filiere':
                $results = searchAsResponsableFiliere($conn, $user_id, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsResponsableFiliere($conn, $user_id, $query, $category);
                break;

            case 'resp_departement':
                $results = searchAsResponsableDepartement($conn, $user_id, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsResponsableDepartement($conn, $user_id, $query, $category);
                break;

            case 'enseignant':
                $results = searchAsEnseignant($conn, $user_id, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsEnseignant($conn, $user_id, $query, $category);
                break;

            case 'etudiant':
                $results = searchAsEtudiant($conn, $user_id, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsEtudiant($conn, $user_id, $query, $category);
                break;

            case 'resp_classe':
                $results = searchAsResponsableClasse($conn, $user_id, $query, $category, $limit, $offset);
                $total_results = countSearchResultsAsResponsableClasse($conn, $user_id, $query, $category);
                break;

            default:
                $results = [];
                $total_results = 0;
        }

        $search_time = round((microtime(true) - $start_time) * 1000, 2); // en millisecondes

        // Ajout dans le journal d'audit
        addAuditLog($conn, $user_id, "Recherche: '$query' dans catégorie '$category' - $total_results résultats", "search");

    } catch (Exception $e) {
        $results = [];
        $total_results = 0;
        $search_error = 'Erreur lors de la recherche: ' . $e->getMessage();
    }
}

// Pagination
$total_pages = ceil($total_results / $limit);

// Fonctions de recherche par rôle
function searchAsAdminGeneral($conn, $query, $category, $limit, $offset) {
    $results = [];

    if ($category === 'all' || $category === 'users') {
        // Recherche dans les utilisateurs
        $sql = "SELECT u.id, u.name, u.email, u.role, u.created_at,
                       'Utilisateur' as type, 'users' as category
                FROM users u
                WHERE (u.name LIKE :query OR u.email LIKE :query)
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($category === 'all' || $category === 'classes') {
        // Recherche dans les classes
        $sql = "SELECT c.id, c.nom_classe, f.nom_filiere, c.created_at,
                       'Classe' as type, 'classes' as category
                FROM classes c
                JOIN filieres f ON c.filiere_id = f.id
                WHERE c.nom_classe LIKE :query
                ORDER BY c.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($category === 'all' || $category === 'filieres') {
        // Recherche dans les filières
        $sql = "SELECT f.id, f.nom_filiere, f.code_filiere, d.nom_departement, f.created_at,
                       'Filière' as type, 'filieres' as category
                FROM filieres f
                JOIN departements d ON f.departement_id = d.id
                WHERE f.nom_filiere LIKE :query OR f.code_filiere LIKE :query
                ORDER BY f.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function countSearchResultsAsAdminGeneral($conn, $query, $category) {
    $count = 0;

    if ($category === 'all' || $category === 'users') {
        $sql = "SELECT COUNT(*) as count FROM users u
                WHERE (u.name LIKE :query OR u.email LIKE :query)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($category === 'all' || $category === 'classes') {
        $sql = "SELECT COUNT(*) as count FROM classes c WHERE c.nom_classe LIKE :query";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($category === 'all' || $category === 'filieres') {
        $sql = "SELECT COUNT(*) as count FROM filieres f
                WHERE f.nom_filiere LIKE :query OR f.code_filiere LIKE :query";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    return $count;
}

function searchAsResponsableFiliere($conn, $user_id, $query, $category, $limit, $offset) {
    $results = [];

    // Récupération de la filière du responsable
    $filiere_sql = "SELECT id FROM filieres WHERE responsable_id = :user_id";
    $filiere_stmt = $conn->prepare($filiere_sql);
    $filiere_stmt->bindValue(':user_id', $user_id);
    $filiere_stmt->execute();
    $filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$filiere) return $results;

    $filiere_id = $filiere['id'];

    if ($category === 'all' || $category === 'etudiants') {
        // Recherche dans les étudiants de la filière
        $sql = "SELECT u.id, u.name, u.email, c.nom_classe, u.created_at,
                       'Étudiant' as type, 'etudiants' as category
                FROM users u
                JOIN etudiants_classes ec ON u.id = ec.etudiant_id
                JOIN classes c ON ec.classe_id = c.id
                WHERE c.filiere_id = :filiere_id
                AND (u.name LIKE :query OR u.email LIKE :query)
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($category === 'all' || $category === 'classes') {
        // Recherche dans les classes de la filière
        $sql = "SELECT c.id, c.nom_classe, c.capacite, c.created_at,
                       'Classe' as type, 'classes' as category
                FROM classes c
                WHERE c.filiere_id = :filiere_id AND c.nom_classe LIKE :query
                ORDER BY c.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($category === 'all' || $category === 'enseignants') {
        // Recherche dans les enseignants de la filière
        $sql = "SELECT u.id, u.name, u.email, u.created_at,
                       'Enseignant' as type, 'enseignants' as category
                FROM users u
                JOIN enseignants_filieres ef ON u.id = ef.enseignant_id
                WHERE ef.filiere_id = :filiere_id AND u.role = 'enseignant'
                AND (u.name LIKE :query OR u.email LIKE :query)
                ORDER BY u.created_at DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function countSearchResultsAsResponsableFiliere($conn, $user_id, $query, $category) {
    $count = 0;

    // Récupération de la filière du responsable
    $filiere_sql = "SELECT id FROM filieres WHERE responsable_id = :user_id";
    $filiere_stmt = $conn->prepare($filiere_sql);
    $filiere_stmt->bindValue(':user_id', $user_id);
    $filiere_stmt->execute();
    $filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$filiere) return 0;

    $filiere_id = $filiere['id'];

    if ($category === 'all' || $category === 'etudiants') {
        $sql = "SELECT COUNT(*) as count FROM users u
                JOIN etudiants_classes ec ON u.id = ec.etudiant_id
                JOIN classes c ON ec.classe_id = c.id
                WHERE c.filiere_id = :filiere_id
                AND (u.name LIKE :query OR u.email LIKE :query)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($category === 'all' || $category === 'classes') {
        $sql = "SELECT COUNT(*) as count FROM classes c
                WHERE c.filiere_id = :filiere_id AND c.nom_classe LIKE :query";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($category === 'all' || $category === 'enseignants') {
        $sql = "SELECT COUNT(*) as count FROM users u
                JOIN enseignants_filieres ef ON u.id = ef.enseignant_id
                WHERE ef.filiere_id = :filiere_id AND u.role = 'enseignant'
                AND (u.name LIKE :query OR u.email LIKE :query)";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':filiere_id', $filiere_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    return $count;
}

function searchAsEtudiant($conn, $user_id, $query, $category, $limit, $offset) {
    $results = [];

    if ($category === 'all' || $category === 'cours') {
        // Recherche dans les cours de l'étudiant
        $sql = "SELECT DISTINCT m.nom_matiere, u.name as enseignant_nom,
                       c.nom_classe, edt.jour_semaine, edt.creneau_horaire, edt.salle,
                       'Cours' as type, 'cours' as category
                FROM emploi_du_temps edt
                JOIN matieres m ON edt.matiere_id = m.id
                JOIN users u ON edt.enseignant_id = u.id
                JOIN classes c ON edt.classe_id = c.id
                JOIN etudiants_classes ec ON c.id = ec.classe_id
                WHERE ec.etudiant_id = :user_id
                AND m.nom_matiere LIKE :query
                ORDER BY edt.jour_semaine, edt.creneau_horaire
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($category === 'all' || $category === 'notes') {
        // Recherche dans les notes de l'étudiant
        $sql = "SELECT n.valeur_note, n.type_note, m.nom_matiere, n.date_evaluation,
                       'Note' as type, 'notes' as category
                FROM notes n
                JOIN matieres m ON n.matiere_id = m.id
                WHERE n.etudiant_id = :user_id AND m.nom_matiere LIKE :query
                ORDER BY n.date_evaluation DESC
                LIMIT :limit OFFSET :offset";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $results = array_merge($results, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    return $results;
}

function countSearchResultsAsEtudiant($conn, $user_id, $query, $category) {
    $count = 0;

    if ($category === 'all' || $category === 'cours') {
        $sql = "SELECT COUNT(DISTINCT m.id) as count
                FROM emploi_du_temps edt
                JOIN matieres m ON edt.matiere_id = m.id
                JOIN classes c ON edt.classe_id = c.id
                JOIN etudiants_classes ec ON c.id = ec.classe_id
                WHERE ec.etudiant_id = :user_id AND m.nom_matiere LIKE :query";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    if ($category === 'all' || $category === 'notes') {
        $sql = "SELECT COUNT(*) as count FROM notes n
                JOIN matieres m ON n.matiere_id = m.id
                WHERE n.etudiant_id = :user_id AND m.nom_matiere LIKE :query";
        $stmt = $conn->prepare($sql);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->execute();
        $count += $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    }

    return $count;
}

// Fonctions similaires pour les autres rôles (simplifiées pour l'exemple)
function searchAsAgentAdministratif($conn, $query, $category, $limit, $offset) {
    return searchAsAdminGeneral($conn, $query, $category, $limit, $offset);
}

function countSearchResultsAsAgentAdministratif($conn, $query, $category) {
    return countSearchResultsAsAdminGeneral($conn, $query, $category);
}

function searchAsResponsableDepartement($conn, $user_id, $query, $category, $limit, $offset) {
    return searchAsAdminGeneral($conn, $query, $category, $limit, $offset);
}

function countSearchResultsAsResponsableDepartement($conn, $user_id, $query, $category) {
    return countSearchResultsAsAdminGeneral($conn, $query, $category);
}

function searchAsEnseignant($conn, $user_id, $query, $category, $limit, $offset) {
    return searchAsResponsableFiliere($conn, $user_id, $query, $category, $limit, $offset);
}

function countSearchResultsAsEnseignant($conn, $user_id, $query, $category) {
    return countSearchResultsAsResponsableFiliere($conn, $user_id, $query, $category);
}

function searchAsResponsableClasse($conn, $user_id, $query, $category, $limit, $offset) {
    return searchAsEtudiant($conn, $user_id, $query, $category, $limit, $offset);
}

function countSearchResultsAsResponsableClasse($conn, $user_id, $query, $category) {
    return countSearchResultsAsEtudiant($conn, $user_id, $query, $category);
}

// Catégories de recherche selon le rôle
$search_categories = [];
switch ($user_role) {
    case 'admin_general':
        $search_categories = [
            'all' => 'Tout',
            'users' => 'Utilisateurs',
            'classes' => 'Classes',
            'filieres' => 'Filières'
        ];
        break;
    case 'agent_administratif':
        $search_categories = [
            'all' => 'Tout',
            'users' => 'Utilisateurs',
            'classes' => 'Classes',
            'filieres' => 'Filières'
        ];
        break;
    case 'resp_filiere':
        $search_categories = [
            'all' => 'Tout',
            'etudiants' => 'Étudiants',
            'enseignants' => 'Enseignants',
            'classes' => 'Classes'
        ];
        break;
    case 'resp_departement':
        $search_categories = [
            'all' => 'Tout',
            'users' => 'Utilisateurs',
            'classes' => 'Classes',
            'filieres' => 'Filières'
        ];
        break;
    case 'enseignant':
        $search_categories = [
            'all' => 'Tout',
            'etudiants' => 'Étudiants',
            'classes' => 'Classes'
        ];
        break;
    case 'etudiant':
        $search_categories = [
            'all' => 'Tout',
            'cours' => 'Cours',
            'notes' => 'Notes'
        ];
        break;
    case 'resp_classe':
        $search_categories = [
            'all' => 'Tout',
            'cours' => 'Cours',
            'notes' => 'Notes'
        ];
        break;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recherche - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-search text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Recherche</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Formulaire de recherche -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <form method="GET" class="space-y-4">
                <div class="flex flex-col md:flex-row md:items-end space-y-4 md:space-y-0 md:space-x-4">
                    <div class="flex-1">
                        <label for="q" class="block text-sm font-medium text-gray-700 mb-2">
                            <i class="fas fa-search mr-1"></i>Rechercher
                        </label>
                        <input type="text" id="q" name="q" value="<?php echo htmlspecialchars($query); ?>"
                               placeholder="Entrez votre recherche..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                               minlength="2" required>
                    </div>

                    <div>
                        <label for="category" class="block text-sm font-medium text-gray-700 mb-2">
                            Catégorie
                        </label>
                        <select id="category" name="category"
                                class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <?php foreach ($search_categories as $key => $label): ?>
                                <option value="<?php echo $key; ?>" <?php echo $category === $key ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <button type="submit"
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                            <i class="fas fa-search mr-2"></i>Rechercher
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($query)): ?>
            <!-- Résultats de recherche -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <div class="flex justify-between items-center">
                        <h2 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-list mr-2"></i>Résultats de recherche
                        </h2>
                        <div class="text-sm text-gray-600">
                            <?php echo $total_results; ?> résultat(s) trouvé(s) en <?php echo $search_time; ?>ms
                        </div>
                    </div>
                    <p class="text-sm text-gray-600 mt-1">
                        Recherche: "<?php echo htmlspecialchars($query); ?>" dans "<?php echo htmlspecialchars($search_categories[$category] ?? $category); ?>"
                    </p>
                </div>

                <?php if (isset($search_error)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-exclamation-triangle text-4xl text-red-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-red-900 mb-2">Erreur de recherche</h3>
                        <p class="text-red-600"><?php echo htmlspecialchars($search_error); ?></p>
                    </div>
                <?php elseif (empty($results)): ?>
                    <div class="p-8 text-center">
                        <i class="fas fa-search text-4xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Aucun résultat trouvé</h3>
                        <p class="text-gray-500 mb-4">
                            Aucun élément ne correspond à votre recherche "<?php echo htmlspecialchars($query); ?>".
                        </p>
                        <div class="space-y-2 text-sm text-gray-600">
                            <p><strong>Conseils de recherche :</strong></p>
                            <ul class="list-disc list-inside space-y-1">
                                <li>Vérifiez l'orthographe des termes</li>
                                <li>Essayez des termes plus généraux</li>
                                <li>Utilisez différents mots-clés</li>
                                <li>Changez de catégorie si nécessaire</li>
                            </ul>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($results as $result): ?>
                            <div class="p-6 hover:bg-gray-50">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <?php
                                            $icon_class = 'fas fa-user';
                                            $color_class = 'text-blue-600';
                                            switch ($result['category']) {
                                                case 'users':
                                                case 'etudiants':
                                                case 'enseignants':
                                                    $icon_class = 'fas fa-user';
                                                    $color_class = 'text-blue-600';
                                                    break;
                                                case 'classes':
                                                    $icon_class = 'fas fa-chalkboard';
                                                    $color_class = 'text-green-600';
                                                    break;
                                                case 'filieres':
                                                    $icon_class = 'fas fa-graduation-cap';
                                                    $color_class = 'text-purple-600';
                                                    break;
                                                case 'cours':
                                                    $icon_class = 'fas fa-book';
                                                    $color_class = 'text-orange-600';
                                                    break;
                                                case 'notes':
                                                    $icon_class = 'fas fa-star';
                                                    $color_class = 'text-yellow-600';
                                                    break;
                                            }
                                            ?>
                                            <i class="<?php echo $icon_class; ?> <?php echo $color_class; ?>"></i>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full
                                                <?php
                                                switch ($result['category']) {
                                                    case 'users':
                                                    case 'etudiants':
                                                    case 'enseignants':
                                                        echo 'bg-blue-100 text-blue-800';
                                                        break;
                                                    case 'classes':
                                                        echo 'bg-green-100 text-green-800';
                                                        break;
                                                    case 'filieres':
                                                        echo 'bg-purple-100 text-purple-800';
                                                        break;
                                                    case 'cours':
                                                        echo 'bg-orange-100 text-orange-800';
                                                        break;
                                                    case 'notes':
                                                        echo 'bg-yellow-100 text-yellow-800';
                                                        break;
                                                    default:
                                                        echo 'bg-gray-100 text-gray-800';
                                                }
                                                ?>">
                                                <?php echo htmlspecialchars($result['type']); ?>
                                            </span>
                                        </div>

                                        <div class="space-y-1">
                                            <?php if (isset($result['nom']) && isset($result['prenom'])): ?>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($result['nom'] . ' ' . $result['prenom']); ?>
                                                </h3>
                                                <?php if (isset($result['email'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-envelope mr-1"></i><?php echo htmlspecialchars($result['email']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['role'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-user-tag mr-1"></i><?php echo htmlspecialchars($result['role']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            <?php elseif (isset($result['nom_classe'])): ?>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($result['nom_classe']); ?>
                                                </h3>
                                                <?php if (isset($result['nom_filiere'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-graduation-cap mr-1"></i><?php echo htmlspecialchars($result['nom_filiere']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['capacite'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-users mr-1"></i>Capacité: <?php echo htmlspecialchars($result['capacite']); ?> étudiants
                                                    </p>
                                                <?php endif; ?>
                                            <?php elseif (isset($result['nom_filiere'])): ?>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($result['nom_filiere']); ?>
                                                </h3>
                                                <?php if (isset($result['code_filiere'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-hashtag mr-1"></i><?php echo htmlspecialchars($result['code_filiere']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['nom_departement'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-building mr-1"></i><?php echo htmlspecialchars($result['nom_departement']); ?>
                                                    </p>
                                                <?php endif; ?>
                                            <?php elseif (isset($result['nom_matiere'])): ?>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($result['nom_matiere']); ?>
                                                </h3>
                                                <?php if (isset($result['enseignant_nom'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-chalkboard-teacher mr-1"></i>
                                                        <?php echo htmlspecialchars($result['enseignant_nom'] . ' ' . $result['enseignant_prenom']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['nom_classe'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($result['nom_classe']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['jour_semaine']) && isset($result['creneau_horaire'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-calendar mr-1"></i>
                                                        <?php
                                                        $jours = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                                                        echo htmlspecialchars($jours[$result['jour_semaine'] - 1] . ' ' . $result['creneau_horaire']);
                                                        ?>
                                                        <?php if (isset($result['salle'])): ?>
                                                            (Salle: <?php echo htmlspecialchars($result['salle']); ?>)
                                                        <?php endif; ?>
                                                    </p>
                                                <?php endif; ?>
                                            <?php elseif (isset($result['valeur_note'])): ?>
                                                <h3 class="text-lg font-medium text-gray-900">
                                                    Note: <?php echo htmlspecialchars($result['valeur_note']); ?>/20
                                                </h3>
                                                <?php if (isset($result['type_note'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($result['type_note']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['nom_matiere'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($result['nom_matiere']); ?>
                                                    </p>
                                                <?php endif; ?>
                                                <?php if (isset($result['date_evaluation'])): ?>
                                                    <p class="text-sm text-gray-600">
                                                        <i class="fas fa-calendar mr-1"></i><?php echo htmlspecialchars(date('d/m/Y', strtotime($result['date_evaluation']))); ?>
                                                    </p>
                                                <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if (isset($result['created_at'])): ?>
                                                <p class="text-xs text-gray-500 mt-2">
                                                    Créé le <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($result['created_at']))); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="ml-4">
                                        <?php
                                        $view_url = '#';
                                        switch ($result['category']) {
                                            case 'users':
                                            case 'etudiants':
                                            case 'enseignants':
                                                // Redirection selon le rôle de l'utilisateur actuel
                                                switch ($user_role) {
                                                    case 'admin_general':
                                                        $view_url = '../admin_general/users.php';
                                                        break;
                                                    case 'resp_filiere':
                                                        $view_url = '../responsable_filiere/classes.php';
                                                        break;
                                                    default:
                                                        $view_url = '#';
                                                }
                                                break;
                                            case 'classes':
                                                switch ($user_role) {
                                                    case 'admin_general':
                                                        $view_url = '../admin_general/classes.php';
                                                        break;
                                                    case 'resp_filiere':
                                                        $view_url = '../responsable_filiere/classes.php';
                                                        break;
                                                    default:
                                                        $view_url = '#';
                                                }
                                                break;
                                            case 'filieres':
                                                $view_url = '../admin_general/filieres.php';
                                                break;
                                            case 'cours':
                                                switch ($user_role) {
                                                    case 'etudiant':
                                                        $view_url = '../etudiant/emploi_du_temps.php';
                                                        break;
                                                    case 'enseignant':
                                                        $view_url = '../enseignant/emploi_du_temps.php';
                                                        break;
                                                    default:
                                                        $view_url = '#';
                                                }
                                                break;
                                            case 'notes':
                                                switch ($user_role) {
                                                    case 'etudiant':
                                                        $view_url = '../etudiant/notes.php';
                                                        break;
                                                    case 'enseignant':
                                                        $view_url = '../enseignant/notes.php';
                                                        break;
                                                    default:
                                                        $view_url = '#';
                                                }
                                                break;
                                        }
                                        ?>
                                        <?php if ($view_url !== '#'): ?>
                                            <a href="<?php echo $view_url; ?>"
                                               class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition duration-200">
                                                <i class="fas fa-eye mr-1"></i>Voir
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="px-6 py-4 border-t border-gray-200 bg-gray-50">
                            <div class="flex items-center justify-between">
                                <div class="text-sm text-gray-700">
                                    Affichage de <?php echo min(($page - 1) * $limit + 1, $total_results); ?> à <?php echo min($page * $limit, $total_results); ?> sur <?php echo $total_results; ?> résultats
                                </div>
                                <div class="flex space-x-1">
                                    <?php if ($page > 1): ?>
                                        <a href="?q=<?php echo urlencode($query); ?>&category=<?php echo urlencode($category); ?>&page=<?php echo $page - 1; ?>&limit=<?php echo $limit; ?>"
                                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                            <i class="fas fa-chevron-left mr-1"></i>Précédent
                                        </a>
                                    <?php endif; ?>

                                    <?php
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    if ($start_page > 1): ?>
                                        <a href="?q=<?php echo urlencode($query); ?>&category=<?php echo urlencode($category); ?>&page=1&limit=<?php echo $limit; ?>"
                                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">1</a>
                                        <?php if ($start_page > 2): ?>
                                            <span class="px-2 py-1 text-gray-500">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                        <a href="?q=<?php echo urlencode($query); ?>&category=<?php echo urlencode($category); ?>&page=<?php echo $i; ?>&limit=<?php echo $limit; ?>"
                                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50 border-blue-500' : 'text-gray-700 bg-white hover:bg-gray-50'; ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <span class="px-2 py-1 text-gray-500">...</span>
                                        <?php endif; ?>
                                        <a href="?q=<?php echo urlencode($query); ?>&category=<?php echo urlencode($category); ?>&page=<?php echo $total_pages; ?>&limit=<?php echo $limit; ?>"
                                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50"><?php echo $total_pages; ?></a>
                                    <?php endif; ?>

                                    <?php if ($page < $total_pages): ?>
                                        <a href="?q=<?php echo urlencode($query); ?>&category=<?php echo urlencode($category); ?>&page=<?php echo $page + 1; ?>&limit=<?php echo $limit; ?>"
                                           class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                            Suivant<i class="fas fa-chevron-right ml-1"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
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