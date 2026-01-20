<?php
/**
 * Gestion des utilisateurs - Administration ISTI
 * Permet de créer, modifier et supprimer des utilisateurs et gérer leurs rôles
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

// Filtre par rôle (optionnel)
$filteredRole = isset($_GET['role']) ? $_GET['role'] : null;

// Traitement des actions (création, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Ajout d'un nouvel utilisateur
    if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = trim($_POST['password']);
        $phone = trim($_POST['phone']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $roles = isset($_POST['roles']) ? $_POST['roles'] : [];
        
        if (empty($name) || empty($email) || empty($password)) {
            $messages[] = ['type' => 'error', 'text' => 'Le nom, l\'email et le mot de passe sont obligatoires.'];
        } else {
            try {
                // Vérifier si l'email existe déjà
                $checkQuery = "SELECT COUNT(*) FROM users WHERE email = :email";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':email', $email);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Cet email est déjà utilisé par un autre utilisateur.'];
                } else {
                    // Hachage du mot de passe
                    $password_hash = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Photo par défaut
                    $photo_url = '../assets/img/default-avatar.png';
                    
                    // Transaction pour ajouter l'utilisateur et ses rôles
                    $conn->beginTransaction();
                    
                    $query = "INSERT INTO users (name, email, password_hash, phone, photo_url, is_active) 
                             VALUES (:name, :email, :password_hash, :phone, :photo_url, :is_active)";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':password_hash', $password_hash);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':photo_url', $photo_url);
                    $stmt->bindParam(':is_active', $is_active);
                    
                    if ($stmt->execute()) {
                        $user_id = $conn->lastInsertId();
                        
                        // Ajouter les rôles sélectionnés
                        $success = true;
                        if (!empty($roles)) {
                            foreach ($roles as $role) {
                                $roleQuery = "INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role)";
                                $roleStmt = $conn->prepare($roleQuery);
                                $roleStmt->bindParam(':user_id', $user_id);
                                $roleStmt->bindParam(':role', $role);
                                
                                if (!$roleStmt->execute()) {
                                    $success = false;
                                    break;
                                }
                            }
                        }
                        
                        if ($success) {
                            $conn->commit();
                            $messages[] = ['type' => 'success', 'text' => 'L\'utilisateur a été ajouté avec succès.'];
                            
                            // Ajout dans le journal d'audit
                            addAuditLog($conn, $_SESSION['user_id'], "Création de l'utilisateur: $name (ID: $user_id)", "users");
                        } else {
                            $conn->rollBack();
                            $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'attribution des rôles.'];
                        }
                    } else {
                        $conn->rollBack();
                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'ajout de l\'utilisateur.'];
                    }
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Modification d'un utilisateur
    else if (isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $roles = isset($_POST['roles']) ? $_POST['roles'] : [];
        $password = trim($_POST['password']);
        
        if (empty($name) || empty($email) || $id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Données invalides pour la modification.'];
        } else {
            try {
                // Vérifier si l'email existe déjà pour un autre utilisateur
                $checkQuery = "SELECT COUNT(*) FROM users WHERE email = :email AND id != :id";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':email', $email);
                $checkStmt->bindParam(':id', $id);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Cet email est déjà utilisé par un autre utilisateur.'];
                } else {
                    // Transaction pour modifier l'utilisateur et ses rôles
                    $conn->beginTransaction();
                    
                    // Mise à jour des informations de base
                    if (!empty($password)) {
                        // Si un nouveau mot de passe est fourni, le hacher et le mettre à jour
                        $password_hash = password_hash($password, PASSWORD_DEFAULT);
                        $query = "UPDATE users SET name = :name, email = :email, password_hash = :password_hash, 
                                 phone = :phone, is_active = :is_active WHERE id = :id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':password_hash', $password_hash);
                    } else {
                        // Sinon, mettre à jour sans changer le mot de passe
                        $query = "UPDATE users SET name = :name, email = :email, phone = :phone, 
                                 is_active = :is_active WHERE id = :id";
                        $stmt = $conn->prepare($query);
                    }
                    
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':email', $email);
                    $stmt->bindParam(':phone', $phone);
                    $stmt->bindParam(':is_active', $is_active);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        // Supprimer tous les rôles actuels
                        $deleteRoles = "DELETE FROM user_roles WHERE user_id = :user_id";
                        $deleteStmt = $conn->prepare($deleteRoles);
                        $deleteStmt->bindParam(':user_id', $id);
                        $deleteStmt->execute();
                        
                        // Ajouter les nouveaux rôles sélectionnés
                        $success = true;
                        if (!empty($roles)) {
                            foreach ($roles as $role) {
                                $roleQuery = "INSERT INTO user_roles (user_id, role) VALUES (:user_id, :role)";
                                $roleStmt = $conn->prepare($roleQuery);
                                $roleStmt->bindParam(':user_id', $id);
                                $roleStmt->bindParam(':role', $role);
                                
                                if (!$roleStmt->execute()) {
                                    $success = false;
                                    break;
                                }
                            }
                        }
                        
                        if ($success) {
                            $conn->commit();
                            $messages[] = ['type' => 'success', 'text' => 'L\'utilisateur a été modifié avec succès.'];
                            
                            // Ajout dans le journal d'audit
                            addAuditLog($conn, $_SESSION['user_id'], "Modification de l'utilisateur ID: $id", "users");
                        } else {
                            $conn->rollBack();
                            $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la mise à jour des rôles.'];
                        }
                    } else {
                        $conn->rollBack();
                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification de l\'utilisateur.'];
                    }
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Suppression d'un utilisateur
    else if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $id = (int)$_POST['id'];
        
        if ($id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'ID d\'utilisateur invalide.'];
        } else {
            try {
                // Vérifier si l'utilisateur est un responsable (département, filière, classe)
                $checkQuery = "SELECT COUNT(*) FROM departements WHERE responsable_id = :id";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':id', $id);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cet utilisateur car il est responsable d\'un ou plusieurs départements.'];
                } else {
                    $checkQuery = "SELECT COUNT(*) FROM filieres WHERE responsable_id = :id";
                    $checkStmt = $conn->prepare($checkQuery);
                    $checkStmt->bindParam(':id', $id);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cet utilisateur car il est responsable d\'une ou plusieurs filières.'];
                    } else {
                        $checkQuery = "SELECT COUNT(*) FROM classes WHERE responsable_id = :id";
                        $checkStmt = $conn->prepare($checkQuery);
                        $checkStmt->bindParam(':id', $id);
                        $checkStmt->execute();
                        
                        if ($checkStmt->fetchColumn() > 0) {
                            $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cet utilisateur car il est responsable d\'une ou plusieurs classes.'];
                        } else {
                            // Vérifier si l'utilisateur a des inscriptions, enseignements, documents, etc.
                            $checkQuery = "SELECT COUNT(*) FROM inscriptions WHERE user_id = :id";
                            $checkStmt = $conn->prepare($checkQuery);
                            $checkStmt->bindParam(':id', $id);
                            $checkStmt->execute();
                            
                            if ($checkStmt->fetchColumn() > 0) {
                                $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cet utilisateur car il a des inscriptions enregistrées.'];
                            } else {
                                $checkQuery = "SELECT COUNT(*) FROM enseignements WHERE enseignant_id = :id";
                                $checkStmt = $conn->prepare($checkQuery);
                                $checkStmt->bindParam(':id', $id);
                                $checkStmt->execute();
                                
                                if ($checkStmt->fetchColumn() > 0) {
                                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cet utilisateur car il a des enseignements assignés.'];
                                } else {
                                    // Transaction pour supprimer l'utilisateur et ses rôles
                                    $conn->beginTransaction();
                                    
                                    // Supprimer d'abord les rôles
                                    $deleteRoles = "DELETE FROM user_roles WHERE user_id = :id";
                                    $deleteStmt = $conn->prepare($deleteRoles);
                                    $deleteStmt->bindParam(':id', $id);
                                    
                                    if ($deleteStmt->execute()) {
                                        // Ensuite supprimer l'utilisateur
                                        $deleteUser = "DELETE FROM users WHERE id = :id";
                                        $deleteUserStmt = $conn->prepare($deleteUser);
                                        $deleteUserStmt->bindParam(':id', $id);
                                        
                                        if ($deleteUserStmt->execute()) {
                                            $conn->commit();
                                            $messages[] = ['type' => 'success', 'text' => 'L\'utilisateur a été supprimé avec succès.'];
                                            
                                            // Ajout dans le journal d'audit
                                            addAuditLog($conn, $_SESSION['user_id'], "Suppression de l'utilisateur ID: $id", "users");
                                        } else {
                                            $conn->rollBack();
                                            $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression de l\'utilisateur.'];
                                        }
                                    } else {
                                        $conn->rollBack();
                                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression des rôles.'];
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $conn->rollBack();
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
}

// Fonction pour obtenir tous les utilisateurs avec leurs rôles
function getAllUsers($conn, $role = null) {
    try {
        $params = [];
        
        $query = "SELECT u.id, u.name, u.email, u.phone, u.photo_url, u.is_active, 
                 (SELECT GROUP_CONCAT(ur.role) FROM user_roles ur WHERE ur.user_id = u.id) as roles,
                 (SELECT COUNT(*) FROM inscriptions WHERE user_id = u.id) as nb_inscriptions,
                 (SELECT COUNT(*) FROM enseignements WHERE enseignant_id = u.id) as nb_enseignements,
                 (SELECT COUNT(*) FROM documents WHERE user_id = u.id) as nb_documents
                 FROM users u";
        
        if ($role) {
            $query .= " JOIN user_roles ur ON u.id = ur.user_id WHERE ur.role = :role";
            $params[':role'] = $role;
        }
        
        $query .= " ORDER BY u.name";
        
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

// Fonction pour obtenir un utilisateur spécifique avec ses rôles
function getUserById($conn, $id) {
    try {
        $query = "SELECT u.id, u.name, u.email, u.phone, u.photo_url, u.is_active FROM users u WHERE u.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            // Récupérer les rôles de l'utilisateur
            $rolesQuery = "SELECT role FROM user_roles WHERE user_id = :id";
            $rolesStmt = $conn->prepare($rolesQuery);
            $rolesStmt->bindParam(':id', $id);
            $rolesStmt->execute();
            $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $user['roles'] = $roles;
        }
        
        return $user;
    } catch (PDOException $e) {
        return null;
    }
}

// Récupération du rôle filtré (si spécifié)
$roleDisplayName = null;
if ($filteredRole) {
    switch ($filteredRole) {
        case 'admin':
            $roleDisplayName = 'Administrateurs';
            break;
        case 'resp_dept':
            $roleDisplayName = 'Responsables de département';
            break;
        case 'resp_filiere':
            $roleDisplayName = 'Responsables de filière';
            break;
        case 'resp_classe':
            $roleDisplayName = 'Responsables de classe';
            break;
        case 'etudiant':
            $roleDisplayName = 'Étudiants';
            break;
        case 'enseignant':
            $roleDisplayName = 'Enseignants';
            break;
        case 'agent_admin':
            $roleDisplayName = 'Agents administratifs';
            break;
        default:
            $filteredRole = null;
    }
}

// Récupération des données
$users = getAllUsers($conn, $filteredRole);

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
    <title>Gestion des utilisateurs - Administration ISTI</title>
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
        .role-badge {
            font-size: 70%;
            padding: 0.2em 0.5em;
            margin: 0.1em;
            border-radius: 0.25rem;
            display: inline-block;
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
                            <li><a href="filieres.php" class="block px-4 py-2 hover:bg-gray-100">🧩 Filières</a></li>
                            <li><a href="classes.php" class="block px-4 py-2 hover:bg-gray-100">🏫 Classes</a></li>
                            <li><a href="users.php" class="block px-4 py-2 hover:bg-gray-100 bg-blue-50">👥 Utilisateurs</a></li>
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
                    <h2 class="text-3xl font-bold text-gray-800">Gestion des utilisateurs</h2>
                    <?php if ($roleDisplayName): ?>
                        <p class="text-gray-600">Filtré par rôle: <span class="font-semibold"><?php echo htmlspecialchars($roleDisplayName); ?></span>
                            <a href="users.php" class="text-blue-600 hover:text-blue-800 text-sm ml-2">(Voir tous les utilisateurs)</a>
                        </p>
                    <?php else: ?>
                        <p class="text-gray-600">Créer, modifier et supprimer des utilisateurs et gérer leurs rôles</p>
                    <?php endif; ?>
                </div>
                <button id="btnAddUser" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded flex items-center">
                    <i class="fas fa-plus mr-2"></i> Nouvel utilisateur
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
                    <!-- Filtrage par rôle -->
                    <div class="w-full md:w-1/3">
                        <label for="filterRole" class="block text-sm font-medium text-gray-700 mb-1">Filtrer par rôle:</label>
                        <div class="relative">
                            <select id="filterRole" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md border">
                                <option value="">Tous les rôles</option>
                                <option value="admin" <?php echo ($filteredRole == 'admin') ? 'selected' : ''; ?>>Administrateurs</option>
                                <option value="resp_dept" <?php echo ($filteredRole == 'resp_dept') ? 'selected' : ''; ?>>Responsables de département</option>
                                <option value="resp_filiere" <?php echo ($filteredRole == 'resp_filiere') ? 'selected' : ''; ?>>Responsables de filière</option>
                                <option value="resp_classe" <?php echo ($filteredRole == 'resp_classe') ? 'selected' : ''; ?>>Responsables de classe</option>
                                <option value="etudiant" <?php echo ($filteredRole == 'etudiant') ? 'selected' : ''; ?>>Étudiants</option>
                                <option value="enseignant" <?php echo ($filteredRole == 'enseignant') ? 'selected' : ''; ?>>Enseignants</option>
                                <option value="agent_admin" <?php echo ($filteredRole == 'agent_admin') ? 'selected' : ''; ?>>Agents administratifs</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Recherche -->
                    <div class="w-full md:w-1/3">
                        <label for="searchUser" class="block text-sm font-medium text-gray-700 mb-1">Rechercher:</label>
                        <div class="relative">
                            <input type="text" id="searchUser" placeholder="Nom, email, téléphone..." class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-border-blue-500 sm:text-sm">
    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
        <i class="fas fa-search text-gray-400"></i>
    </div>
</div>
</div>

<!-- Compteurs -->
<div class="w-full md:w-1/3 text-right">
    <p class="text-sm text-gray-600">Nombre total d'utilisateurs: <span class="font-bold"><?php echo count($users); ?></span></p>
</div>
</div>
</div>

<!-- Tableau des utilisateurs -->
<div class="bg-white rounded-lg shadow-md overflow-x-auto">
<table class="min-w-full divide-y divide-gray-200">
<thead class="bg-gray-50">
    <tr>
        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Utilisateur</th>
        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rôles</th>
        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statistiques</th>
        <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
        <th scope="col" class="px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider text-center">Actions</th>
    </tr>
</thead>
<tbody class="bg-white divide-y divide-gray-200" id="usersTableBody">
    <?php if (empty($users)): ?>
    <tr>
        <td colspan="6" class="px-6 py-4 text-center text-gray-500">Aucun utilisateur trouvé</td>
    </tr>
    <?php else: ?>
        <?php foreach ($users as $user): ?>
        <tr class="table-row-hover">
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                    <div class="h-10 w-10 flex-shrink-0">
                        <img class="h-10 w-10 rounded-full" src="<?php echo htmlspecialchars($user['photo_url']) ?: '../assets/img/default-avatar.png'; ?>" alt="Photo de profil">
                    </div>
                    <div class="ml-4">
                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($user['name']); ?></div>
                        <div class="text-sm text-gray-500">ID: <?php echo $user['id']; ?></div>
                    </div>
                </div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($user['email']); ?></div>
                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($user['phone'] ?: 'Non renseigné'); ?></div>
            </td>
            <td class="px-6 py-4">
                <?php 
                $roles = explode(',', $user['roles'] ?? '');
                foreach ($roles as $role): 
                    $roleColor = '';
                    $roleName = '';
                    
                    switch ($role) {
                        case 'admin':
                            $roleColor = 'bg-red-100 text-red-800';
                            $roleName = 'Admin';
                            break;
                        case 'resp_dept':
                            $roleColor = 'bg-purple-100 text-purple-800';
                            $roleName = 'Resp. Dépt.';
                            break;
                        case 'resp_filiere':
                            $roleColor = 'bg-indigo-100 text-indigo-800';
                            $roleName = 'Resp. Filière';
                            break;
                        case 'resp_classe':
                            $roleColor = 'bg-blue-100 text-blue-800';
                            $roleName = 'Resp. Classe';
                            break;
                        case 'etudiant':
                            $roleColor = 'bg-green-100 text-green-800';
                            $roleName = 'Étudiant';
                            break;
                        case 'enseignant':
                            $roleColor = 'bg-yellow-100 text-yellow-800';
                            $roleName = 'Enseignant';
                            break;
                        case 'agent_admin':
                            $roleColor = 'bg-gray-100 text-gray-800';
                            $roleName = 'Agent Admin';
                            break;
                        default:
                            $roleColor = 'bg-gray-100 text-gray-800';
                            $roleName = $role;
                    }
                    
                    if (!empty($role)):
                ?>
                    <span class="role-badge <?php echo $roleColor; ?>"><?php echo $roleName; ?></span>
                <?php 
                    endif;
                endforeach; 
                ?>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-500">
                    <div>Inscriptions: <span class="font-medium"><?php echo $user['nb_inscriptions']; ?></span></div>
                    <div>Enseignements: <span class="font-medium"><?php echo $user['nb_enseignements']; ?></span></div>
                    <div>Documents: <span class="font-medium"><?php echo $user['nb_documents']; ?></span></div>
                </div>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
                <?php if ($user['is_active']): ?>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Actif</span>
                <?php else: ?>
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactif</span>
                <?php endif; ?>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-center">
                <button class="edit-user-btn text-blue-600 hover:text-blue-900 mx-1" data-id="<?php echo $user['id']; ?>">
                    <i class="fas fa-edit"></i>
                </button>
                <button class="delete-user-btn text-red-600 hover:text-red-900 mx-1" data-id="<?php echo $user['id']; ?>">
                    <i class="fas fa-trash-alt"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
</tbody>
</table>
</div>
</main>

<!-- Pied de page -->
<footer class="bg-gray-800 text-white mt-auto">
<div class="container mx-auto px-4 py-4">
<div class="flex flex-col md:flex-row justify-between items-center">
    <div class="mb-4 md:mb-0">
        <p>&copy; <?php echo date('Y'); ?> Institut Supérieur des Technologies de l'Information</p>
    </div>
    <div>
        <p>Développé par la Direction des Systèmes d'Information</p>
    </div>
</div>
</div>
</footer>

<!-- Modal Ajout/Modification d'utilisateur -->
<div id="userModal" class="fixed inset-0 bg-gray-600 bg-opacity-75 flex items-center justify-center hidden modal z-50">
<div class="bg-white rounded-lg shadow-xl max-w-2xl w-full mx-4 max-h-screen overflow-y-auto">
<div class="px-6 py-4 border-b">
    <div class="flex items-center justify-between">
        <h3 class="text-xl font-semibold text-gray-900" id="modalTitle">Ajouter un utilisateur</h3>
        <button id="closeModal" class="text-gray-400 hover:text-gray-500 focus:outline-none">
            <i class="fas fa-times"></i>
        </button>
    </div>
</div>
<div class="px-6 py-4">
    <form id="userForm" method="POST" action="">
        <input type="hidden" name="action" id="formAction" value="add_user">
        <input type="hidden" name="id" id="userId" value="">
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <!-- Informations principales -->
            <div>
                <div class="mb-4">
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom complet</label>
                    <input type="text" name="name" id="name" required class="w-full border border-gray-300 px-3 py-2 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="mb-4">
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="email" required class="w-full border border-gray-300 px-3 py-2 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="mb-4">
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe <span id="passwordNote" class="text-gray-500 text-xs">(requis)</span></label>
                    <input type="password" name="password" id="password" class="w-full border border-gray-300 px-3 py-2 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="mb-4">
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input type="text" name="phone" id="phone" class="w-full border border-gray-300 px-3 py-2 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div class="mb-4">
                    <label class="flex items-center">
                        <input type="checkbox" name="is_active" id="is_active" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" checked>
                        <span class="ml-2 text-sm text-gray-700">Compte actif</span>
                    </label>
                </div>
            </div>
            
            <!-- Rôles -->
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Rôles</label>
                <div class="bg-gray-50 p-3 rounded-md border border-gray-200">
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="admin" id="role_admin" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Administrateur</span>
                        </label>
                        <p class="text-xs text-gray-500 ml-6">Accès complet au système</p>
                    </div>
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="resp_dept" id="role_resp_dept" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Responsable de département</span>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="resp_filiere" id="role_resp_filiere" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Responsable de filière</span>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="resp_classe" id="role_resp_classe" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Responsable de classe</span>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="etudiant" id="role_etudiant" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Étudiant</span>
                        </label>
                    </div>
                    <div class="mb-2">
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="enseignant" id="role_enseignant" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Enseignant</span>
                        </label>
                    </div>
                    <div>
                        <label class="flex items-center">
                            <input type="checkbox" name="roles[]" value="agent_admin" id="role_agent_admin" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded">
                            <span class="ml-2 text-sm text-gray-700">Agent administratif</span>
                        </label>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-6 flex justify-end">
            <button type="button" id="cancelBtn" class="bg-gray-200 text-gray-800 py-2 px-4 rounded mr-2 hover:bg-gray-300 transition">Annuler</button>
            <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded hover:bg-blue-700 transition">Enregistrer</button>
        </div>
    </form>
</div>
</div>
</div>

<!-- Scripts JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Variables
    const userModal = document.getElementById('userModal');
    const userForm = document.getElementById('userForm');
    const formAction = document.getElementById('formAction');
    const modalTitle = document.getElementById('modalTitle');
    const userId = document.getElementById('userId');
    const passwordNote = document.getElementById('passwordNote');
    const btnAddUser = document.getElementById('btnAddUser');
    const closeModal = document.getElementById('closeModal');
    const cancelBtn = document.getElementById('cancelBtn');
    const filterRole = document.getElementById('filterRole');
    const searchUser = document.getElementById('searchUser');
    
    // Filtrage par rôle
    filterRole.addEventListener('change', function() {
        const role = this.value;
        if (role) {
            window.location.href = 'users.php?role=' + role;
        } else {
            window.location.href = 'users.php';
        }
    });
    
    // Recherche d'utilisateurs
    searchUser.addEventListener('input', function() {
        const searchValue = this.value.toLowerCase();
        const tableRows = document.querySelectorAll('#usersTableBody tr');
        
        tableRows.forEach(row => {
            const name = row.querySelector('td:nth-child(1) .text-gray-900')?.textContent.toLowerCase() || '';
            const email = row.querySelector('td:nth-child(2) .text-gray-900')?.textContent.toLowerCase() || '';
            const phone = row.querySelector('td:nth-child(2) .text-gray-500')?.textContent.toLowerCase() || '';
            
            if (name.includes(searchValue) || email.includes(searchValue) || phone.includes(searchValue)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
    
    // Ouvrir la modal pour ajouter un utilisateur
    btnAddUser.addEventListener('click', function() {
        modalTitle.textContent = 'Ajouter un utilisateur';
        formAction.value = 'add_user';
        userId.value = '';
        userForm.reset();
        passwordNote.textContent = '(requis)';
        document.getElementById('password').required = true;
        userModal.classList.remove('hidden');
    });
    
    // Fermer la modal
    function closeModalFunction() {
        userModal.classList.add('hidden');
    }
    
    closeModal.addEventListener('click', closeModalFunction);
    cancelBtn.addEventListener('click', closeModalFunction);
    
    // Fermer la modal lorsqu'on clique en dehors
    userModal.addEventListener('click', function(event) {
        if (event.target === userModal) {
            closeModalFunction();
        }
    });
    
    // Boutons d'édition d'utilisateur
    document.querySelectorAll('.edit-user-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            
            // Récupérer les détails de l'utilisateur via AJAX
            fetch('ajax_get_user.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const user = data.user;
                        
                        // Remplir le formulaire avec les données
                        modalTitle.textContent = 'Modifier un utilisateur';
                        formAction.value = 'edit_user';
                        userId.value = user.id;
                        document.getElementById('name').value = user.name;
                        document.getElementById('email').value = user.email;
                        document.getElementById('phone').value = user.phone || '';
                        document.getElementById('is_active').checked = user.is_active == 1;
                        
                        // Mot de passe optionnel en modification
                        document.getElementById('password').required = false;
                        passwordNote.textContent = '(laisser vide pour ne pas modifier)';
                        
                        // Décocher tous les rôles puis cocher ceux de l'utilisateur
                        document.querySelectorAll('input[name="roles[]"]').forEach(checkbox => {
                            checkbox.checked = false;
                        });
                        
                        if (user.roles) {
                            user.roles.forEach(role => {
                                const checkbox = document.getElementById('role_' + role);
                                if (checkbox) {
                                    checkbox.checked = true;
                                }
                            });
                        }
                        
                        userModal.classList.remove('hidden');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Erreur',
                            text: 'Impossible de récupérer les informations de l\'utilisateur'
                        });
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Erreur',
                        text: 'Une erreur est survenue lors de la récupération des données'
                    });
                });
        });
    });
    
    // Boutons de suppression d'utilisateur
    document.querySelectorAll('.delete-user-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const id = this.getAttribute('data-id');
            
            Swal.fire({
                title: 'Êtes-vous sûr?',
                text: "Cette action est irréversible!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Oui, supprimer',
                cancelButtonText: 'Annuler'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Créer un formulaire pour la suppression
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.style.display = 'none';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_user';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'id';
                    idInput.value = id;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        });
    });
});
</script>
</div>
</body>
</html>