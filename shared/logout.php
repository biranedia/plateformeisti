<?php
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérifier si l'utilisateur est connecté
if (isset($_SESSION['user_id'])) {
    try {
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Enregistrer la déconnexion dans les logs d'audit
        $audit_query = "INSERT INTO audit_logs (user_id, action, table_cible) 
                        VALUES (:user_id, :action, :table_cible)";
        $audit_stmt = $db->prepare($audit_query);
        $action = "Déconnexion du système";
        $table = "auth_sessions";
        $audit_stmt->bindParam(":user_id", $_SESSION['user_id']);
        $audit_stmt->bindParam(":action", $action);
        $audit_stmt->bindParam(":table_cible", $table);
        $audit_stmt->execute();
        
        // Invalidation du token de session dans la base de données
        if (isset($_SESSION['token'])) {
            $update_query = "UPDATE auth_sessions SET date_fin = NOW() 
                            WHERE user_id = :user_id AND token = :token";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(":user_id", $_SESSION['user_id']);
            $update_stmt->bindParam(":token", $_SESSION['token']);
            $update_stmt->execute();
        }
    } catch (PDOException $e) {
        // En cas d'erreur, on continue quand même la déconnexion
        error_log("Erreur lors de la déconnexion: " . $e->getMessage());
    }
}

// Destruction de la session
session_unset();
session_destroy();

// Redirection vers la page de connexion avec un message
session_start();
$_SESSION['alert_message'] = "Vous avez été déconnecté avec succès.";
$_SESSION['alert_type'] = "success";
header("Location: login.php");
exit;