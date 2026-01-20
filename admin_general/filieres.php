<?php
/**
 * Gestion des filières - Administration ISTI
 * Permet de créer, modifier et supprimer des filières
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('admin')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'administrateur pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Messages de succès ou d'erreur
$messages = [];

// Filtre par département (optionnel)
$filteredDept = isset($_GET['dept']) ? (int)$_GET['dept'] : null;

// Traitement des actions (création, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Ajout d'une nouvelle filière
    if (isset($_POST['action']) && $_POST['action'] === 'add_filiere') {
        $nom = trim($_POST['nom']);
        $departement_id = (int)$_POST['departement_id'];
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($nom) || $departement_id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Le nom de la filière et le département sont obligatoires.'];
        } else {
            try {
                $query = "INSERT INTO filieres (nom, departement_id, responsable_id) VALUES (:nom, :departement_id, :responsable_id)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':nom', $nom);
                $stmt->bindParam(':departement_id', $departement_id);
                $stmt->bindParam(':responsable_id', $responsable_id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'La filière a été ajoutée avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Création de la filière: $nom", "filieres");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'ajout de la filière.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Modification d'une filière
    else if (isset($_POST['action']) && $_POST['action'] === 'edit_filiere') {
        $id = (int)$_POST['id'];
        $nom = trim($_POST['nom']);
        $departement_id = (int)$_POST['departement_id'];
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($nom) || $id <= 0 || $departement_id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Données invalides pour la modification.'];
        } else {
            try {
                $query = "UPDATE filieres SET nom = :nom, departement_id = :departement_id, responsable_id = :responsable_id WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':nom', $nom);
                $stmt->bindParam(':departement_id', $departement_id);
                $stmt->bindParam(':responsable_id', $responsable_id);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'La filière a été modifiée avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Modification de la filière ID: $id", "filieres");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification de la filière.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Suppression d'une filière
    else if (isset($_POST['action']) && $_POST['action'] === 'delete_filiere') {
        $id = (int)$_POST['id'];
        
        if ($id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'ID de filière invalide.'];
        } else {
            try {
                // Vérifier si la filière a des classes associées
                $checkQuery = "SELECT COUNT(*) FROM classes WHERE filiere_id = :id";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':id', $id);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette filière car elle contient des classes.'];
                } else {
                    $query = "DELETE FROM filieres WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'La filière a été supprimée avec succès.'];
                        
                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $_SESSION['user_id'], "Suppression de la filière ID: $id", "filieres");
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression de la filière.'];
                    }
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
}

// Fonction pour obtenir toutes les filières
function getAllFilieres($conn, $departmentId = null) {
    try {
        $params = [];
        $query = "SELECT f.id, f.nom, f.departement_id, f.responsable_id, 
                 d.nom as departement_nom, u.name as responsable_nom,
                 (SELECT COUNT(*) FROM classes WHERE filiere_id = f.id) as nb_classes
                 FROM filieres f
                 LEFT JOIN departements d ON f.departement_id = d.id
                 LEFT JOIN users u ON f.responsable_id = u.id";
        
        if ($departmentId) {
            $query .= " WHERE f.departement_id = :dept_id";
            $params[':dept_id'] = $departmentId;
        }
        
        $query .= " ORDER BY d.nom, f.nom";
        
        $stmt = $conn->prepare($query);
        
        if (!empty($params)) {
            foreach ($params as $param => $value) {
                $stmt->bindValue($param, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fonction pour obtenir une filière spécifique
function getFiliereById($conn, $id) {
    try {
        $query = "SELECT f.id, f.nom, f.departement_id, f.responsable_id, 
                 d.nom as departement_nom, u.name as responsable_nom
                 FROM filieres f
                 LEFT JOIN departements d ON f.departement_id = d.id
                 LEFT JOIN users u ON f.responsable_id = u.id
                 WHERE f.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Fonction pour obtenir les départements
function getAllDepartements($conn) {
    try {
        $query = "SELECT id, nom FROM departements ORDER BY nom";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fonction pour obtenir les utilisateurs pouvant être responsables de filière
function getPotentialResponsables($conn) {
    try {
        $query = "SELECT u.id, u.name
                 FROM users u
                 JOIN user_roles ur ON u.id = ur.user_id
                 WHERE ur.role = 'resp_filiere' AND u.is_active = true
                 ORDER BY u.name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Récupération du département filtré (si spécifié)
$selectedDepartement = null;
if ($filteredDept) {
    try {
        $query = "SELECT id, nom FROM departements WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $filteredDept);
        $stmt->execute();
        $selectedDepartement = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Continue sans filtrage si erreur
    }
}

// Récupération des données
$filieres = getAllFilieres($conn, $filteredDept);
$departements = getAllDepartements($conn);
$responsables = getPotentialResponsables($conn);

// Récupération du nombre de notifications non lues (pour l'en-tête)
function getUnreadNotifications($conn) {
    $query = "SELECT COUNT(*) as count 
              FROM notifications 
              WHERE statut = 'non_lu'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['count'];
}
$unreadNotifications = getUnreadNotifications($conn);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestion des filières - Administration ISTI</title>
    <!-- Tailwind CSS via CDN -->
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <!-- Font Awesome pour les icônes -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <!-- SweetAlert2 pour les boîtes de dialogue -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .table-row-hover:hover {
            background-color: #f9fafb;
        }
        .modal {
            transition: opacity 0.25s ease;
        }
        .modal-active {
            overflow-y: visible !important;
        }
        .badge {
            display: inline-block;
            padding: 0.25em 0.6em;
            font-size: 75%;
            font-weight: 700;
            line-height: 1;
            text-align: center;
            white-space: nowrap;
            vertical-align: baseline;
            border-radius: 0.375rem;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="min-h-screen flex flex-col">
        <!-- En-tête (Header) -->
        <header class="bg-blue-800 text-white shadow-lg">
            <div class="container mx-auto px-4 py-4 flex justify-between items-center">
                <!-- Logo et titre -->
                <div class="flex items-center">
                    <h1 class="text-2xl font-bold">ISTI Admin</h1>
                    <span class="ml-2 px-3 py-1 bg-blue-700 rounded-full text-sm">Administrateur Général</span>
                </div>

                <!-- Icônes et profil -->
                <div class="flex items-center space-x-4">
                    <!-- Notifications -->
                    <div class="relative">
                        <button class="p-2 rounded-full hover:bg-blue-700">
                            <i class="fas fa-bell"></i>
                            <?php if ($unreadNotifications > 0): ?>
                                <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full">
                                    <?php echo $unreadNotifications; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    </div>

                    <!-- Menu Admin (Dropdown) -->
                    <div class="relative group">
                        <button class="bg-blue-700 hover:bg-blue-600 px-3 py-1 rounded text-sm">📁 Gestion</button>
                        <ul class="absolute right-0 mt-2 w-72 bg-white text-gray-800 rounded shadow-lg opacity-0 group-hover:opacity-100 transition duration-200 z-50 text-sm divide-y divide-gray-200">
                            <li><a href="dashboard.php" class="block px-4 py-2 hover:bg-gray-100">📊 Vue d'ensemble</a></li>
                            <li><a href="departements.php" class="block px-4 py-2 hover:bg-gray-100">🏛️ Départements</a></li>
                            <li><a href="filieres.php" class="block px-4 py-2 hover:bg-gray-100 bg-blue-50">🧩 Filières</a></li>
                            <li><a href="classes.php" class="block px-4 py-2 hover:bg-gray-100">🏫 Classes</a></li>
                            <li><a href="users.php" class="block px-4 py-2 hover:bg-gray-100">👥 Utilisateurs</a></li>
                            <li><a href="annees_academiques.php" class="block px-4 py-2 hover:bg-gray-100">📅 Années / Semestres</a></li>
                            <li><a href="stats.php" class="block px-4 py-2 hover:bg-gray-100">📈 Statistiques</a></li>
                            <li><a href="audit_log.php" class="block px-4 py-2 hover:bg-gray-100">📋 Journalisation</a></li>
                            <li><a href="settings.php" class="block px-4 py-2 hover:bg-gray-100">⚙️ Paramètres</a></li>
                        </ul>
                    </div>

                    <!-- Profil -->
                    <div class="flex items-center space-x-2">
                        <span class="hidden md:inline-block"><?php echo $_SESSION['user_name'] ?? 'Administrateur'; ?></span>
                        <img class="h-8 w-8 rounded-full border-2 border-white" src="<?php echo $_SESSION['user_photo'] ?? '../assets/img/default-avatar.png'; ?>" alt="Photo de profil">
                    </div>

                    <!-- Déconnexion -->
                    <a href="../shared/logout.php" class="text-sm bg-red-700 hover:bg-red-800 px-3 py-1 rounded">Déconnexion</a>
                </div>
            </div>
        </header>

        <!-- Contenu principal -->
        <main class="flex-grow container mx-auto px-4 py-6">
            <!-- Titre de la page -->
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-3xl font-bold text-gray-800">Gestion des filières</h2>
                    <?php if ($selectedDepartement): ?>
                        <p class="text-gray-600">Filtré par département: <span class="font-semibold"><?php echo htmlspecialchars($selectedDepartement['nom']); ?></span> 
                            <a href="filieres.php" class="text-blue-600 hover:text-blue-800 text-sm ml-2">(Voir toutes les filières)</a>
                        </p>
                    <?php else: ?>
                        <p class="text-gray-600">Créer, modifier et supprimer des filières</p>
                    <?php endif; ?>
                </div>
                <button id="btnAddFiliere" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded flex items-center">
                    <i class="fas fa-plus mr-2"></i> Nouvelle filière
                </button>
            </div>

            <!-- Messages de notification -->
            <?php if (!empty($messages)): ?>
                <?php foreach ($messages as $message): ?>
                    <div class="mb-4 p-4 rounded
                        <?php echo $message['type'] === 'success' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                        <?php echo $message['text']; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Filtres et recherche -->
            <div class="bg-white rounded-lg shadow-md mb-6 p-4">
                <div class="flex flex-col md:flex-row justify-between items-center space-y-4 md:space-y-0">
                    <!-- Filtrage par département -->
                    <div class="w-full md:w-1/3">
                        <label for="filterDepartement" class="block text-sm font-medium text-gray-700 mb-1">Filtrer par département:</label>
                        <div class="relative">
                            <select id="filterDepartement" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md border">
                                <option value="">Tous les départements</option>
                                <?php foreach ($departements as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>" <?php echo ($filteredDept == $dept['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Recherche -->
                    <div class="w-full md:w-1/3">
                        <label for="searchFiliere" class="block text-sm font-medium text-gray-700 mb-1">Rechercher:</label>
                        <div class="relative">
                            <input type="text" id="searchFiliere" placeholder="Nom de filière, département..." class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tableau des filières -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-4 border-b">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Liste des filières</h3>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                            <?php echo count($filieres); ?> filière(s)
                        </span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom de la filière</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Département</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Responsable</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Classes</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="filiereTableBody">
                            <?php if (empty($filieres)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500">Aucune filière trouvée</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filieres as $filiere): ?>
                                    <tr class="table-row-hover">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $filiere['id']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($filiere['nom']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <a href="filieres.php?dept=<?php echo $filiere['departement_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                <?php echo htmlspecialchars($filiere['departement_nom']); ?>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $filiere['responsable_id'] ? htmlspecialchars($filiere['responsable_nom']) : '<span class="text-yellow-600">Non assigné</span>'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo $filiere['nb_classes']; ?> classe(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button 
                                                    data-id="<?php echo $filiere['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($filiere['nom']); ?>"
                                                    data-departement="<?php echo $filiere['departement_id']; ?>"
                                                    data-responsable="<?php echo $filiere['responsable_id']; ?>"
                                                    class="btnEditFiliere text-blue-600 hover:text-blue-900"
                                                    title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button 
                                                    data-id="<?php echo $filiere['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($filiere['nom']); ?>"
                                                    data-classes="<?php echo $filiere['nb_classes']; ?>"
                                                    class="btnDeleteFiliere text-red-600 hover:text-red-900"
                                                    title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <a href="classes.php?filiere=<?php echo $filiere['id']; ?>" class="text-green-600 hover:text-green-900" title="Voir les classes">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="bg-gray-50 px-4 py-3 flex justify-between items-center">
                    <div>
                        <span class="text-sm text-gray-700">
                            Total: <span class="font-medium"><?php echo count($filieres); ?></span> filière(s)
                        </span>
                    </div>
                </div>
            </div>
        </main>

        <!-- Pied de page -->
        <footer class="bg-blue-900 text-white py-4">
            <div class="container mx-auto px-4 text-center">
                <p>© <?php echo date('Y'); ?> Plateforme ISTI - Tous droits réservés</p>
            </div>
        </footer>
    </div>

    <!-- Modal pour ajouter une filière -->
    <div id="addFiliereModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold">Ajouter une filière</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Formulaire d'ajout -->
                <form id="addFiliereForm" method="POST" action="">
                    <input type="hidden" name="action" value="add_filiere">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="add_nom">
                            Nom de la filière *
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                               id="add_nom" 
                               name="nom" 
                               type="text" 
                               placeholder="Ex: Génie Logiciel" 
                               required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="add_departement_id">
                            Département *
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="add_departement_id" 
                                name="departement_id"
                                required>
                            <option value="">-- Sélectionner un département --</option>
                            <?php foreach ($departements as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="add_responsable_id">
                            Responsable
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="add_responsable_id" 
                                name="responsable_id">
                            <option value="">-- Aucun responsable assigné --</option>
                            <?php foreach ($responsables as $resp): ?>
                                <option value="<?php echo $resp['id']; ?>">
                                    <?php echo htmlspecialchars($resp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-center justify-between pt-4">
                        <button class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="button">
                            Annuler
                        </button>
                        <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="submit">
                            Ajouter
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal pour éditer une filière -->
    <div id="editFiliereModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold">Modifier une filière</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Formulaire d'édition -->
                <form id="editFiliereForm" method="POST" action="">
                    <input type="hidden" name="action" value="edit_filiere">
                    <input type="hidden" name="id" id="edit_id" value="">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_nom">
                            Nom de la filière *
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                               id="edit_nom" 
                               name="nom" 
                               type="text" 
                               placeholder="Ex: Génie Logiciel" 
                               required>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_departement_id">
                            Département *
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="edit_departement_id" 
                                name="departement_id"
                                required>
                            <option value="">-- Sélectionner un département --</option>
                            <?php foreach ($departements as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>">
                                    <?php echo htmlspecialchars($dept['nom']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_responsable_id">
                            Responsable
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="edit_responsable_id" 
                                name="responsable_id">
                            <option value="">-- Aucun responsable assigné --</option>
                            <?php foreach ($responsables as $resp): ?>
                                <option value="<?php echo $resp['id']; ?>">
                                    <?php echo htmlspecialchars($resp['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex items-center justify-between pt-4">
                        <button class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="button">
                            Annuler
                        </button>
                        <button class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="submit">
                            Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="deleteFiliereModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold text-red-600">Supprimer une filière</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Contenu du modal -->
                <div class="my-4">
                    <p class="text-gray-800">Êtes-vous sûr de vouloir supprimer cette filière ? Cette action est irréversible.</p>
                    <p class="text-gray-600 mt-2">Filière : <span id="delete_nom" class="font-semibold"></span></p>
                    <p class="text-gray-600 mt-1 hidden" id="delete_warning">
                        <i class="fas fa-exclamation-triangle text-yellow-600 mr-1"></i>
                        Cette filière contient <span id="delete_classes_count" class="font-semibold"></span> classe(s). 
                        Elles devront être réassignées ou supprimées avant de pouvoir supprimer cette filière.
                    </p>
                </div>

                <!-- Formulaire de suppression -->
                <form id="deleteFiliereForm" method="POST" action="">
                    <input type="hidden" name="action" value="delete_filiere">
                    <input type="hidden" name="id" id="delete_id" value="">
                    
                    <div class="flex items-center justify-between pt-4">
                        <button class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="button">
                            Annuler
                        </button>
                        <button id="confirmDelete" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline" type="submit">
                            Supprimer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Gestion des modals
            const modals = ['addFiliereModal', 'editFiliereModal', 'deleteFiliereModal'];
            const openModalButtons = {
                'addFiliereModal': document.getElementById('btnAddFiliere'),
                'editFiliereModal': document.querySelectorAll('.btnEditFiliere'),
                'deleteFiliereModal': document.querySelectorAll('.btnDeleteFiliere')
            };
            const closeModalButtons = document.querySelectorAll('.closeModal');
            
            // Bouton pour ouvrir le modal d'ajout
            if (openModalButtons['addFiliereModal']) {
                openModalButtons['addFiliereModal'].addEventListener('click', function() {
                    toggleModal('addFiliereModal');
                });
            }
            
            // Boutons pour ouvrir le modal d'édition
            openModalButtons['editFiliereModal'].forEach(function(button) {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const nom = this.getAttribute('data-nom');
                    const departement = this.getAttribute('data-departement');
                    const responsable = this.getAttribute('data-responsable');
                    
                    document.getElementById('edit_id').value = id;
                    document.getElementById('edit_nom').value = nom;
                    document.getElementById('edit_departement_id').value = departement;
                    document.getElementById('edit_responsable_id').value = responsable || '';
                    
                    toggleModal('editFiliereModal');
                });
            });
            
            // Boutons pour ouvrir le modal de suppression
            openModalButtons['deleteFiliereModal'].forEach(function(button) {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const nom = this.getAttribute('data-nom');
                    const classes = parseInt(this.getAttribute('data-classes'));
                    
                    document.getElementById('delete_id').value = id;
                    document.getElementById('delete_nom').textContent = nom;
                    
                    if (classes > 0) {
                        document.getElementById('delete_warning').classList.remove('hidden');
                        document.getElementById('delete_classes_count').textContent = classes;
                        document.getElementById('confirmDelete').disabled = true;
                        document.getElementById('confirmDelete').classList.add('opacity-50', 'cursor-not-allowed');
                    } else {
                        document.getElementById('delete_warning').classList.add('hidden');
                        document.getElementById('confirmDelete').disabled = false;
                        document.getElementById('confirmDelete').classList.remove('opacity-50', 'cursor-not-allowed');
                    }
                    
                    toggleModal('deleteFiliereModal');
                });
            });
            
            // Boutons pour fermer les modals
            closeModalButtons.forEach(function(button) {
                button.addEventListener('click', function() {
                    modals.forEach(function(modal) {
                        document.getElementById(modal).classList.add('opacity-0', 'pointer-events-none');
                    });
                });
            });
            
            // Fonction pour basculer l'affichage du modal
            function toggleModal(modalId) {
                const modal = document.getElementById(modalId);
                modal.classList.toggle('opacity-0');
                modal.classList.toggle('pointer-events-none');
            }
            
            // Recherche et filtrage pour le tableau des filières
            const searchInput = document.getElementById('searchFiliere');
            const filterDepartement = document.getElementById('filterDepartement');
            
            searchInput.addEventListener('input', filterFilieres);
            filterDepartement.addEventListener('change', function() {
                if (this.value) {
                    window.location.href = 'filieres.php?dept=' + this.value;
                } else {
                    window.location.href = 'filieres.php';
                }
            });
            
            function filterFilieres() {
                const searchTerm = searchInput.value.toLowerCase();
                const rows = document.querySelectorAll('#filiereTableBody tr');
                
                rows.forEach(function(row) {
                    const nom = row.cells[1].textContent.toLowerCase();
                    const departement = row.cells[2].textContent.toLowerCase();
                    const responsable = row.cells[3].textContent.toLowerCase();
                    
                    if (nom.includes(searchTerm) || departement.includes(searchTerm) || responsable.includes(searchTerm)) {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>