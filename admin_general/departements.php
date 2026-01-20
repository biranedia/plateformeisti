<?php
/**
 * Gestion des départements - Administration ISTI
 * Permet de créer, modifier et supprimer des départements
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

// Traitement des actions (création, modification, suppression)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Ajout d'un nouveau département
    if (isset($_POST['action']) && $_POST['action'] === 'add_departement') {
        $nom = trim($_POST['nom']);
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($nom)) {
            $messages[] = ['type' => 'error', 'text' => 'Le nom du département est obligatoire.'];
        } else {
            try {
                $query = "INSERT INTO departements (nom, responsable_id) VALUES (:nom, :responsable_id)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':nom', $nom);
                $stmt->bindParam(':responsable_id', $responsable_id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'Le département a été ajouté avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Création du département: $nom", "departements");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de l\'ajout du département.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Modification d'un département
    else if (isset($_POST['action']) && $_POST['action'] === 'edit_departement') {
        $id = (int)$_POST['id'];
        $nom = trim($_POST['nom']);
        $responsable_id = !empty($_POST['responsable_id']) ? (int)$_POST['responsable_id'] : null;
        
        if (empty($nom) || $id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'Données invalides pour la modification.'];
        } else {
            try {
                $query = "UPDATE departements SET nom = :nom, responsable_id = :responsable_id WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':nom', $nom);
                $stmt->bindParam(':responsable_id', $responsable_id);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                    $messages[] = ['type' => 'success', 'text' => 'Le département a été modifié avec succès.'];
                    
                    // Ajout dans le journal d'audit
                    addAuditLog($conn, $_SESSION['user_id'], "Modification du département ID: $id", "departements");
                } else {
                    $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la modification du département.'];
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
    
    // Suppression d'un département
    else if (isset($_POST['action']) && $_POST['action'] === 'delete_departement') {
        $id = (int)$_POST['id'];
        
        if ($id <= 0) {
            $messages[] = ['type' => 'error', 'text' => 'ID de département invalide.'];
        } else {
            try {
                // Vérifier si le département a des filières associées
                $checkQuery = "SELECT COUNT(*) FROM filieres WHERE departement_id = :id";
                $checkStmt = $conn->prepare($checkQuery);
                $checkStmt->bindParam(':id', $id);
                $checkStmt->execute();
                
                if ($checkStmt->fetchColumn() > 0) {
                    $messages[] = ['type' => 'error', 'text' => 'Impossible de supprimer ce département car il contient des filières.'];
                } else {
                    $query = "DELETE FROM departements WHERE id = :id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':id', $id);
                    
                    if ($stmt->execute()) {
                        $messages[] = ['type' => 'success', 'text' => 'Le département a été supprimé avec succès.'];
                        
                        // Ajout dans le journal d'audit
                        addAuditLog($conn, $_SESSION['user_id'], "Suppression du département ID: $id", "departements");
                    } else {
                        $messages[] = ['type' => 'error', 'text' => 'Une erreur est survenue lors de la suppression du département.'];
                    }
                }
            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur de base de données: ' . $e->getMessage()];
            }
        }
    }
}

// Fonction pour obtenir tous les départements
function getAllDepartements($conn) {
    try {
        $query = "SELECT d.id, d.nom, d.responsable_id, u.name as responsable_name,
                 (SELECT COUNT(*) FROM filieres WHERE departement_id = d.id) as nb_filieres
                 FROM departements d
                 LEFT JOIN users u ON d.responsable_id = u.id
                 ORDER BY d.nom";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Fonction pour obtenir un département spécifique
function getDepartementById($conn, $id) {
    try {
        $query = "SELECT d.id, d.nom, d.responsable_id, u.name as responsable_name
                 FROM departements d
                 LEFT JOIN users u ON d.responsable_id = u.id
                 WHERE d.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return null;
    }
}

// Fonction pour obtenir les utilisateurs pouvant être responsables
function getPotentialResponsables($conn) {
    try {
        $query = "SELECT u.id, u.name
                 FROM users u
                 JOIN user_roles ur ON u.id = ur.user_id
                 WHERE ur.role = 'resp_dept' AND u.is_active = true
                 ORDER BY u.name";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

// Récupération des données
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
    <title>Gestion des départements - Administration ISTI</title>
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
                            <li><a href="departements.php" class="block px-4 py-2 hover:bg-gray-100 bg-blue-50">🏛️ Départements</a></li>
                            <li><a href="filieres.php" class="block px-4 py-2 hover:bg-gray-100">🧩 Filières</a></li>
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
                    <h2 class="text-3xl font-bold text-gray-800">Gestion des départements</h2>
                    <p class="text-gray-600">Créer, modifier et supprimer des départements</p>
                </div>
                <button id="btnAddDepartement" class="bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded flex items-center">
                    <i class="fas fa-plus mr-2"></i> Nouveau département
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

            <!-- Tableau des départements -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-4 border-b">
                    <div class="flex justify-between items-center">
                        <h3 class="text-xl font-semibold text-gray-800">Liste des départements</h3>
                        <div class="relative">
                            <input type="text" id="searchDepartement" placeholder="Rechercher..." class="border rounded px-4 py-2 focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom du département</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Responsable</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Filières</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200" id="departementTableBody">
                            <?php if (empty($departements)): ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-4 text-center text-gray-500">Aucun département trouvé</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($departements as $dept): ?>
                                    <tr class="table-row-hover">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $dept['id']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($dept['nom']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo $dept['responsable_id'] ? htmlspecialchars($dept['responsable_name']) : '<span class="text-yellow-600">Non assigné</span>'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo $dept['nb_filieres']; ?> filière(s)
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button 
                                                    data-id="<?php echo $dept['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($dept['nom']); ?>"
                                                    data-responsable="<?php echo $dept['responsable_id']; ?>"
                                                    class="btnEditDepartement text-blue-600 hover:text-blue-900"
                                                    title="Modifier">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button 
                                                    data-id="<?php echo $dept['id']; ?>"
                                                    data-nom="<?php echo htmlspecialchars($dept['nom']); ?>"
                                                    data-filieres="<?php echo $dept['nb_filieres']; ?>"
                                                    class="btnDeleteDepartement text-red-600 hover:text-red-900"
                                                    title="Supprimer">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <a href="filieres.php?dept=<?php echo $dept['id']; ?>" class="text-green-600 hover:text-green-900" title="Voir les filières">
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
                            Total: <span class="font-medium"><?php echo count($departements); ?></span> département(s)
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

    <!-- Modal pour ajouter un département -->
    <div id="addDepartementModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold">Ajouter un département</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Formulaire d'ajout -->
                <form id="addDepartementForm" method="POST" action="">
                    <input type="hidden" name="action" value="add_departement">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="add_nom">
                            Nom du département *
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                               id="add_nom" 
                               name="nom" 
                               type="text" 
                               placeholder="Ex: Informatique" 
                               required>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="add_responsable_id">
                            Responsable
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="add_responsable_id" 
                                name="responsable_id">
                            <option value="">-- Sélectionner un responsable --</option>
                            <?php foreach ($responsables as $resp): ?>
                                <option value="<?php echo $resp['id']; ?>"><?php echo htmlspecialchars($resp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="button" class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline mr-2">
                            Annuler
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                            Enregistrer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal pour modifier un département -->
    <div id="editDepartementModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold">Modifier un département</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Formulaire de modification -->
                <form id="editDepartementForm" method="POST" action="">
                    <input type="hidden" name="action" value="edit_departement">
                    <input type="hidden" name="id" id="edit_id" value="">
                    
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_nom">
                            Nom du département *
                        </label>
                        <input class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                               id="edit_nom" 
                               name="nom" 
                               type="text" 
                               placeholder="Ex: Informatique" 
                               required>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_responsable_id">
                            Responsable
                        </label>
                        <select class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" 
                                id="edit_responsable_id" 
                                name="responsable_id">
                            <option value="">-- Sélectionner un responsable --</option>
                            <?php foreach ($responsables as $resp): ?>
                                <option value="<?php echo $resp['id']; ?>"><?php echo htmlspecialchars($resp['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="flex justify-end">
                        <button type="button" class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline mr-2">
                            Annuler
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                            Mettre à jour
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal pour confirmer la suppression -->
    <div id="deleteDepartementModal" class="modal opacity-0 pointer-events-none fixed w-full h-full top-0 left-0 flex items-center justify-center z-50">
        <div class="modal-overlay absolute w-full h-full bg-gray-900 opacity-50"></div>
        
        <div class="modal-container bg-white w-11/12 md:max-w-md mx-auto rounded shadow-lg z-50 overflow-y-auto">
            <div class="modal-content py-4 text-left px-6">
                <!-- Titre du modal -->
                <div class="flex justify-between items-center pb-3">
                    <p class="text-2xl font-bold">Confirmer la suppression</p>
                    <div class="cursor-pointer z-50 closeModal">
                        <i class="fas fa-times text-gray-500 hover:text-gray-800"></i>
                    </div>
                </div>

                <!-- Message de confirmation -->
                <div class="mb-4">
                    <p class="text-gray-700">Êtes-vous sûr de vouloir supprimer le département <span id="delete_dept_name" class="font-bold"></span> ?</p>
                    <p id="delete_warning" class="text-red-600 mt-2 hidden">Attention: Ce département contient des filières. Vous devez d'abord supprimer ou déplacer ces filières.</p>
                </div>

                <!-- Formulaire de suppression -->
                <form id="deleteDepartementForm" method="POST" action="">
                    <input type="hidden" name="action" value="delete_departement">
                    <input type="hidden" name="id" id="delete_id" value="">
                    
                    <div class="flex justify-end">
                        <button type="button" class="closeModal bg-gray-500 hover:bg-gray-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline mr-2">
                        Annuler
                    </button>
                    <button type="submit" id="confirmDeleteBtn" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                        Supprimer
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts JavaScript -->
<script>
    // Fonctions pour gérer les modals
    function openModal(modalId) {
        document.body.classList.add('modal-active');
        document.getElementById(modalId).classList.remove('opacity-0', 'pointer-events-none');
    }
    
    function closeModal() {
        document.body.classList.remove('modal-active');
        document.querySelectorAll('.modal').forEach(modal => {
            modal.classList.add('opacity-0', 'pointer-events-none');
        });
    }
    
    // Écouteurs d'événements pour les modals
    document.addEventListener('DOMContentLoaded', function() {
        // Ouvrir modal d'ajout
        document.getElementById('btnAddDepartement').addEventListener('click', function() {
            openModal('addDepartementModal');
        });
        
        // Ouvrir modal de modification
        document.querySelectorAll('.btnEditDepartement').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const nom = this.getAttribute('data-nom');
                const responsable = this.getAttribute('data-responsable');
                
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_nom').value = nom;
                document.getElementById('edit_responsable_id').value = responsable || '';
                
                openModal('editDepartementModal');
            });
        });
        
        // Ouvrir modal de suppression
        document.querySelectorAll('.btnDeleteDepartement').forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const nom = this.getAttribute('data-nom');
                const filieres = parseInt(this.getAttribute('data-filieres'));
                
                document.getElementById('delete_id').value = id;
                document.getElementById('delete_dept_name').textContent = nom;
                
                // Vérifier si le département contient des filières
                const warningElement = document.getElementById('delete_warning');
                const confirmBtn = document.getElementById('confirmDeleteBtn');
                
                if (filieres > 0) {
                    warningElement.classList.remove('hidden');
                    confirmBtn.disabled = true;
                    confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
                } else {
                    warningElement.classList.add('hidden');
                    confirmBtn.disabled = false;
                    confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
                
                openModal('deleteDepartementModal');
            });
        });
        
        // Fermer les modals
        document.querySelectorAll('.closeModal, .modal-overlay').forEach(element => {
            element.addEventListener('click', closeModal);
        });
        
        // Filtrage des départements par recherche
        document.getElementById('searchDepartement').addEventListener('keyup', function() {
            const searchValue = this.value.toLowerCase();
            const tableRows = document.querySelectorAll('#departementTableBody tr');
            
            tableRows.forEach(row => {
                const departementName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                const responsableName = row.querySelector('td:nth-child(3)').textContent.toLowerCase();
                
                if (departementName.includes(searchValue) || responsableName.includes(searchValue)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });
    
    // Fonction pour afficher des messages d'alerte avec SweetAlert2
    function showAlert(title, message, type) {
        Swal.fire({
            title: title,
            text: message,
            icon: type,
            confirmButtonColor: '#3085d6',
            confirmButtonText: 'OK'
        });
    }
    
    <?php if (!empty($messages)): ?>
        <?php foreach ($messages as $message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                showAlert(
                    '<?php echo $message['type'] === 'success' ? 'Succès' : 'Erreur'; ?>', 
                    '<?php echo addslashes($message['text']); ?>', 
                    '<?php echo $message['type'] === 'success' ? 'success' : 'error'; ?>'
                );
            });
        <?php endforeach; ?>
    <?php endif; ?>
</script>
</body>
</html>