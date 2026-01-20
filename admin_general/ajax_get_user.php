<?php
/**
 * Script AJAX pour récupérer les détails d'un utilisateur
 * Utilisé par la page users.php pour la modification d'un utilisateur
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('admin')) {
    echo json_encode(['success' => false, 'message' => 'Accès non autorisé']);
    exit;
}

// Vérification de l'ID utilisateur
if (!isset($_GET['id']) || empty($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'ID utilisateur invalide']);
    exit;
}

$user_id = (int)$_GET['id'];

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

try {
    // Récupération des informations de l'utilisateur
    $query = "SELECT id, name, email, phone, photo_url, is_active FROM users WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Utilisateur non trouvé']);
        exit;
    }
    
    // Récupération des rôles de l'utilisateur
    $rolesQuery = "SELECT role FROM user_roles WHERE user_id = :id";
    $rolesStmt = $conn->prepare($rolesQuery);
    $rolesStmt->bindParam(':id', $user_id);
    $rolesStmt->execute();
    
    $roles = $rolesStmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Ajouter les rôles à l'objet utilisateur
    $user['roles'] = $roles;
    
    // Retourner les données en format JSON
    echo json_encode(['success' => true, 'user' => $user]);
    
} catch (PDOException $e) {
    // En cas d'erreur, retourner un message d'erreur
    echo json_encode(['success' => false, 'message' => 'Erreur de base de données: ' . $e->getMessage()]);
    exit;
}
?>