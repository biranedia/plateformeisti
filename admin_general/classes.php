<?php
/**
 * Gestion des classes - Administration ISTI
 * Permet de créer, modifier et supprimer des classes
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

// Filtre par filière (optionnel)
$filteredFiliere = isset($_GET['filiere']) ? (int)$_GET['filiere'] : null;

// Traitement des actions (création, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Ajout d'une nouvelle classe
    if (isset($_POST['action']) && $_POST['action'] === 'add_classe') {
        $niveau = trim($_POST['niveau']);
        $filiere_id = (int)$_POST['filiere_id'];
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($niveau) || $filiere_id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Le niveau et la filière sont obligatoires.'];
        } else {
            try {
                $query = "INSERT INTO classes (niveau, filiere_id, responsable_id) VALUES (:niveau, :filiere_id, :responsable_id)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':niveau', $niveau);
                $stmt->bindParam(':filiere_id', $filiere_id);
                $stmt->bindParam(':responsable_id', $responsable_id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'La classe a été ajoutée avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Création de la classe: $niveau", "classes");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'ajout de la classe.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Modification d'une classe
    else if (isset($_POST['action']) && $_POST['action'] === 'edit_classe') {
        $id = (int)$_POST['id'];
        $niveau = trim($_POST['niveau']);
        $filiere_id = (int)$_POST['filiere_id'];
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($niveau) || $id <= 0 || $filiere_id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Données invalides pour la modification.'];
        } else {
            try {
                $query = "UPDATE classes SET niveau = :niveau, filiere_id = :filiere_id, responsable_id = :responsable_id WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':niveau', $niveau);
                $stmt->bindParam(':filiere_id', $filiere_id);
                $stmt->bindParam(':responsable_id', $responsable_id);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'La classe a été modifiée avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Modification de la classe ID: $id", "classes");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification de la classe.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Suppression d'une classe
    else if (isset($_POST['action']) && $_POST['action'] === 'delete_classe') {
        $id = (int)$_POST['id'];
        
        if ($id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'ID de classe invalide.'];
        } else {
            try {
                // Vérifier si la classe a des inscriptions associées
                $checkQuery = "SELECT COUNT(*) FROM inscriptions WHERE classe_id = :id";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':id', $id);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette classe car elle contient des inscriptions.'];
                } else {
                    // Vérifier si la classe a des enseignements associés
                    $checkQuery = "SELECT COUNT(*) FROM enseignements WHERE classe_id = :id";
                    $checkStmt = $conn->prepare($checkQuery);
                    $checkStmt->bindParam(':id', $id);
                    $checkStmt->execute();
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette classe car elle contient des enseignements.'];
                    } else {
                        // Vérifier si la classe a des emplois du temps associés
                        $checkQuery = "SELECT COUNT(*) FROM emplois_du_temps WHERE classe_id = :id";
                        $checkStmt = $conn->prepare($checkQuery);
                        $checkStmt->bindParam(':id', $id);
                        $checkStmt->execute();
                        
                        if ($checkStmt->fetchColumn() > 0) {
                            $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette classe car elle a des emplois du temps associés.'];
                        } else {
                            // Vérifier si la classe a des événements associés
                            $checkQuery = "SELECT COUNT(*) FROM evenements WHERE classe_id = :id";
                            $checkStmt = $conn->prepare($checkQuery);
                            $checkStmt->bindParam(':id', $id);
                            $checkStmt->execute();
                            
                            if ($checkStmt->fetchColumn() > 0) {
                                $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer cette classe car elle a des événements associés.'];
                            } else {
                                $query = "DELETE FROM classes WHERE id = :id";
                                $stmt = $conn->prepare($query);
                                $stmt->bindParam(':id', $id);
                                
                                if ($stmt->execute()) {
                                    $messages[] = ['type' => 'success', 'text' => 'La classe a été supprimée avec succès.'];
                                    
                                    // Ajout dans le journal d'audit
                                    addAuditLog($conn, $_SESSION['user_id'], "Suppression de la classe ID: $id", "classes");
                                } else {
                                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression de la classe.'];
                                }
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
}

// Fonction pour obtenir toutes les classes
function getAllClasses($conn, $filiereId = null) {
    try {
        $params = [];
        $query = "SELECT c.id, c.niveau, c.filiere_id, c.responsable_id, 
                 f.nom as filiere_nom, d.nom as departement_nom, u.name as responsable_nom,
                 (SELECT COUNT(*) FROM inscriptions WHERE classe_id = c.id) as nb_etudiants,
                 (SELECT COUNT(*) FROM enseignements WHERE classe_id = c.id) as nb_matieres
                 FROM classes c
                 LEFT JOIN filieres f ON c.filiere_id = f.id
                 LEFT JOIN departements d ON f.departement_id = d.id
                 LEFT JOIN users u ON c.responsable_id = u.id";
        
        if ($filiereId) {
            $query .= " WHERE c.filiere_id = :filiere_id";
            $params[':filiere_id'] = $filiereId;
        }
        
        $query .= " ORDER BY d.nom, f.nom, c.niveau";
        
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

// Fonction pour obtenir une classe spécifique
function getClasseById($conn, $id) {
    try {
        $query = "SELECT c.id, c.niveau, c.filiere_id, c.responsable_id, 
                 f.nom as filiere_nom, d.nom as departement_nom, u.name as responsable_nom
                 FROM classes c
                 LEFT JOIN filieres f ON c.filiere_id = f.id
                 LEFT JOIN departements d ON f.departement_id = d.id
                 LEFT JOIN users u ON c.responsable_id = u.id
                 WHERE c.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Fonction pour obtenir toutes les filières
function getAllFilieres($conn) {
    try {
        $query = "SELECT f.id, f.nom, d.nom as departement_nom 
                 FROM filieres f 
                 LEFT JOIN departements d ON f.departement_id = d.id 
                 ORDER BY d.nom, f.nom";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fonction pour obtenir les utilisateurs pouvant être responsables de classe
function getPotentialResponsables($conn) {
    try {
        $query = "SELECT u.id, u.name
                 FROM users u
                 JOIN user_roles ur ON u.id = ur.user_id
                 WHERE ur.role = 'resp_classe' AND u.is_active = true
                 ORDER BY u.name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Récupération de la filière filtrée (si spécifiée)
$selectedFiliere = null;
if ($filteredFiliere) {
    try {
        $query = "SELECT f.id, f.nom, d.nom as departement_nom 
                 FROM filieres f 
                 LEFT JOIN departements d ON f.departement_id = d.id 
                 WHERE f.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $filteredFiliere);
        $stmt->execute();
        $selectedFiliere = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Continue sans filtrage si erreur
    }
}

// Récupération des données
$classes = getAllClasses($conn, $filteredFiliere);
$filieres = getAllFilieres($conn);
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
    <title>Gestion des classes - Administration ISTI</title>
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
                            <li><a href="filieres.php" class="block px-4 py-2 hover:bg-gray-100">🧩 Filières</a></li>
                            <li><a href="classes.php" class="block px-4 py-2 hover:bg-gray-100 bg-blue-50">🏫 Classes</a></li>
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
                    <h2 class="text-3xl font-bold text-gray-800">Gestion des classes</h2>
                    <?php if ($selectedFiliere): ?>
                        <p class="text-gray-600">Filtré par filière: <span class="font-semibold"><?php echo htmlspecialchars($selectedFiliere['nom']); ?></span> 
                            <span class="text-gray-500">(Département: <?php echo htmlspecialchars($selectedFiliere['departement_nom']); ?>)</span>
                            <a href="classes.php" class="text-blue-600 hover:text-blue-800 text-sm ml-2">(Voir toutes les classes)</a>
                        </p>
                    <?php else: ?>
                        <p class="text-gray-600">Créer, modifier et supprimer des classes</p>
                    <?php endif; ?>
                </div>
                <button id="btnAddClasse" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded flex items-center">
                    <i class="fas fa-plus mr-2"></i> Nouvelle classe
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
                    <!-- Filtrage par filière -->
                    <div class="w-full md:w-1/3">
                        <label for="filterFiliere" class="block text-sm font-medium text-gray-700 mb-1">Filtrer par filière:</label>
                        <div class="relative">
                            <select id="filterFiliere" class="block w-full pl-3 pr-10 py-2 text-base border-gray-300 focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm rounded-md border">
                                <option value="">Toutes les filières</option>
                                <?php foreach ($filieres as $filiere): ?>
                                    <option value="<?php echo $filiere['id']; ?>" <?php echo ($filteredFiliere == $filiere['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($filiere['nom']); ?> (<?php echo htmlspecialchars($filiere['departement_nom']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Recherche -->
                    <div class="w-full md:w-1/3">
                        <label for="searchClasse" class="block text-sm font-medium text-gray-700 mb-1">Rechercher:</label>
                        <div class="relative">
                            <input type="text" id="searchClasse" placeholder="Niveau, filière, département..." class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                <i class="fas fa-search text-gray-400"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tableau des classes -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-4 border-b">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Liste des classes</h3>
                        <span class="px-3 py-1 bg-blue-100 text-blue-800 rounded-full text-sm">
                            <?php echo count($classes); ?> classe(s)
                        </span>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Niveau</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filière</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Département</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Responsable</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Étudiants</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matières</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="classeTableBody">
                            <?php if (empty($classes)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">Aucune classe trouvée</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($classes as $classe): ?>
                                    <tr class="table-row-hover">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $classe['id']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($classe['niveau']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <a href="classes.php?filiere=<?php echo $classe['filiere_id']; ?>" class="text-blue-600 hover:text-blue-900">
                                                <?php echo htmlspecialchars($classe['filiere_nom']); ?>
                                            </a>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($classe['departement_nom']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $classe['responsable_id'] ? htmlspecialchars($classe['responsable_nom']) : '<span class="text-yellow-600">Non assigné</span>'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo $classe['nb_etudiants']; ?> étudiant(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                <?php echo $classe['nb_matieres']; ?> matière(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button 
                                                    data-id="<?php echo $classe['id']; ?>"
                                                    data-niveau="<?php echo htmlspecialchars($classe['niveau']); ?>"
                                                    data-filiere="<?php echo $classe['filiere_id']; ?>"
                                                    data-responsable="<?php echo $classe['responsable_id']; ?>"
                                                    class="btnEditClasse text-blue-600 hover:text-blue-900"
                                                    title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button 
                                                    data-id="<?php echo $classe['id']; ?>"
                                                    data-niveau="<?php echo htmlspecialchars($classe['niveau']); ?>"
                                                    data-filiere="<?php echo htmlspecialchars($classe['filiere_nom']); ?>"
                                                    data-etudiants="<?php echo $classe['nb_etudiants']; ?>"
                                                    data-matieres="<?php echo $classe['nb_matieres']; ?>"
                                                    class="btnDeleteClasse text-red-600 hover:text-red-900"
                                                    title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <a href="classe_details.php?id=<?php echo $classe['id']; ?>" class="text-green-600 hover:text-green-900" title="Voir détails">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="emplois_du_temps.php?classe=<?php echo $classe['id']; ?>" class="text-purple-600 hover:text-purple-900" title="Emploi du temps">
                                                    <i class="fas fa-calendar-alt"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>

        <!-- Pied de page -->
        <footer class="bg-gray-800 text-white p-4">
            <div class="container mx-auto text-center">
                <p>© <?php echo date('Y'); ?> Institut Supérieur de Technologie ISTI - Tous droits réservés</p>
                <p class="text-gray-400 text-sm mt-1">Version 1.0.2 - Dernière mise à jour: 12/05/2023</p>
            </div>
        </footer>
    </div>

    <!-- Modal d'ajout de classe -->
    <div id="modalAddClasse" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden z-50 modal">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b">
                <h3 class="text-xl font-semibold text-gray-800">Ajouter une nouvelle classe</h3>
            </div>
            <form id="formAddClasse" method="POST" action="classes.php">
                <input type="hidden" name="action" value="add_classe">
                <div class="p-6">
                    <div class="mb-4">
                        <label for="niveau" class="block text-sm font-medium text-gray-700 mb-1">Niveau</label>
                        <select id="niveau" name="niveau" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                            <option value="">Sélectionner un niveau</option>
                            <option value="L1">Licence 1</option>
                            <option value="L2">Licence 2</option>
                            <option value="L3">Licence 3</option>
                            <option value="M1">Master 1</option>
                            <option value="M2">Master 2</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="filiere_id" class="block text-sm font-medium text-gray-700 mb-1">Filière</label>
                        <select id="filiere_id" name="filiere_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                            <option value="">Sélectionner une filière</option>
                            <?php foreach ($filieres as $filiere): ?>
                                <option value="<?php echo $filiere['id']; ?>">
                                    <?php echo htmlspecialchars($filiere['nom']); ?> (<?php echo htmlspecialchars($filiere['departement_nom']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="responsable_id" class="block text-sm font-medium text-gray-700 mb-1">Responsable de classe</label>
                        <select id="responsable_id" name="responsable_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="">Sélectionner un responsable (optionnel)</option>
                            <?php foreach ($responsables as $responsable): ?>
                                <option value="<?php echo $responsable['id']; ?>">
                                    <?php echo htmlspecialchars($responsable['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t text-right">
                    <button type="button" class="btnCancelModal px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 mr-2">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Ajouter la classe
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de modification de classe -->
    <div id="modalEditClasse" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden z-50 modal">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b">
                <h3 class="text-xl font-semibold text-gray-800">Modifier une classe</h3>
            </div>
            <form id="formEditClasse" method="POST" action="classes.php">
                <input type="hidden" name="action" value="edit_classe">
                <input type="hidden" name="id" id="edit_id">
                <div class="p-6">
                    <div class="mb-4">
                        <label for="edit_niveau" class="block text-sm font-medium text-gray-700 mb-1">Niveau</label>
                        <select id="edit_niveau" name="niveau" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                            <option value="">Sélectionner un niveau</option>
                            <option value="L1">Licence 1</option>
                            <option value="L2">Licence 2</option>
                            <option value="L3">Licence 3</option>
                            <option value="M1">Master 1</option>
                            <option value="M2">Master 2</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="edit_filiere_id" class="block text-sm font-medium text-gray-700 mb-1">Filière</label>
                        <select id="edit_filiere_id" name="filiere_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm" required>
                            <option value="">Sélectionner une filière</option>
                            <?php foreach ($filieres as $filiere): ?>
                                <option value="<?php echo $filiere['id']; ?>">
                                    <?php echo htmlspecialchars($filiere['nom']); ?> (<?php echo htmlspecialchars($filiere['departement_nom']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label for="edit_responsable_id" class="block text-sm font-medium text-gray-700 mb-1">Responsable de classe</label>
                        <select id="edit_responsable_id" name="responsable_id" class="block w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500 sm:text-sm">
                            <option value="">Sélectionner un responsable (optionnel)</option>
                            <?php foreach ($responsables as $responsable): ?>
                                <option value="<?php echo $responsable['id']; ?>">
                                    <?php echo htmlspecialchars($responsable['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="px-6 py-4 bg-gray-50 border-t text-right">
                    <button type="button" class="btnCancelModal px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 mr-2">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                        Enregistrer les modifications
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de confirmation de suppression -->
    <div id="modalDeleteClasse" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden z-50 modal">
        <div class="bg-white rounded-lg shadow-lg w-full max-w-md mx-4">
            <div class="px-6 py-4 border-b">
                <h3 class="text-xl font-semibold text-red-600">Confirmation de suppression</h3>
            </div>
            <div class="p-6">
                <p class="text-gray-700">Êtes-vous sûr de vouloir supprimer cette classe ?</p>
                <div class="mt-4 bg-gray-100 p-3 rounded">
                    <p class="font-medium" id="deleteClasse_info"></p>
                    <p class="text-sm text-gray-500 mt-1" id="deleteClasse_details"></p>
                </div>
                <div class="mt-4">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mr-2"></i>
                        <p class="text-sm text-gray-600">Cette action est irréversible et supprimera définitivement les données.</p>
                    </div>
                </div>
            </div>
            <form id="formDeleteClasse" method="POST" action="classes.php">
                <input type="hidden" name="action" value="delete_classe">
                <input type="hidden" name="id" id="delete_id">
                <div class="px-6 py-4 bg-gray-50 border-t text-right">
                    <button type="button" class="btnCancelModal px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 mr-2">
                        Annuler
                    </button>
                    <button type="submit" class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500">
                        Confirmer la suppression
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts JavaScript -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Bouton pour ouvrir le modal d'ajout
            document.getElementById('btnAddClasse').addEventListener('click', function() {
                document.getElementById('modalAddClasse').classList.remove('hidden');
            });

            // Boutons pour fermer les modals
            document.querySelectorAll('.btnCancelModal').forEach(function(button) {
                button.addEventListener('click', function() {
                    document.querySelectorAll('.modal').forEach(function(modal) {
                        modal.classList.add('hidden');
                    });
                });
            });

            // Filtrage par filière
            document.getElementById('filterFiliere').addEventListener('change', function() {
                if (this.value) {
                    window.location.href = 'classes.php?filiere=' + this.value;
                } else {
                    window.location.href = 'classes.php';
                }
            });

            // Recherche dans le tableau
            document.getElementById('searchClasse').addEventListener('keyup', function() {
                const searchTerm = this.value.toLowerCase();
                const rows = document.getElementById('classeTableBody').getElementsByTagName('tr');
                
                for (let row of rows) {
                    const cells = row.getElementsByTagName('td');
                    let found = false;
                    
                    for (let cell of cells) {
                        if (cell.textContent.toLowerCase().indexOf(searchTerm) > -1) {
                            found = true;
                            break;
                        }
                    }
                    
                    row.style.display = found ? '' : 'none';
                }
            });
            
            // Boutons pour éditer une classe
            document.querySelectorAll('.btnEditClasse').forEach(function(button) {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const niveau = this.getAttribute('data-niveau');
                    const filiere = this.getAttribute('data-filiere');
                    const responsable = this.getAttribute('data-responsable');
                    
                    document.getElementById('edit_id').value = id;
                    document.getElementById('edit_niveau').value = niveau;
                    document.getElementById('edit_filiere_id').value = filiere;
                    document.getElementById('edit_responsable_id').value = responsable || '';
                    
                    document.getElementById('modalEditClasse').classList.remove('hidden');
                });
            });
            
            // Boutons pour supprimer une classe
            document.querySelectorAll('.btnDeleteClasse').forEach(function(button) {
                button.addEventListener('click', function() {
                    const id = this.getAttribute('data-id');
                    const niveau = this.getAttribute('data-niveau');
                    const filiere = this.getAttribute('data-filiere');
                    const etudiants = this.getAttribute('data-etudiants');
                    const matieres = this.getAttribute('data-matieres');
                    
                    document.getElementById('delete_id').value = id;
                    document.getElementById('deleteClasse_info').textContent = niveau + ' - ' + filiere;
                    document.getElementById('deleteClasse_details').textContent = 'ID: ' + id + ' | Étudiants: ' + etudiants + ' | Matières: ' + matieres;
                    
                    document.getElementById('modalDeleteClasse').classList.remove('hidden');
                });
            });
        });
    </script>
</body>
</html>