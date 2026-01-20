<?php
// Démarrer la session si elle n'est pas déjà démarrée
if (session_status() === PHP_SESSION_NONE) {
    session_start();
} 
/**
 * Fonctions utilitaires pour la plateforme ISTI
 */

/**
 * Nettoie et sécurise les données entrées par l'utilisateur
 * @param string $data Données à nettoyer
 * @return string Données nettoyées
 */
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Vérifie si l'email est valide
 * @param string $email Email à vérifier
 * @return bool True si l'email est valide, false sinon
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Vérifie si un numéro de téléphone est valide
 * @param string $phone Numéro de téléphone à vérifier
 * @return bool True si le numéro est valide, false sinon
 */
function isValidPhone($phone) {
    // Format simple pour numéro sénégalais: commence par 7 et suivi de 8 chiffres
    return preg_match('/^7[0-9]{8}$/', $phone);
}

/**
 * Génère un message d'alerte formaté
 * @param string $message Le message à afficher
 * @param string $type Le type d'alerte (success, error, warning, info)
 * @return string HTML formaté pour l'alerte
 */
function alert($message, $type = 'error') {
    $colors = [
        'success' => 'bg-green-100 border-green-500 text-green-700',
        'error' => 'bg-red-100 border-red-500 text-red-700',
        'warning' => 'bg-yellow-100 border-yellow-500 text-yellow-700',
        'info' => 'bg-blue-100 border-blue-500 text-blue-700'
    ];
    
    $colorClass = isset($colors[$type]) ? $colors[$type] : $colors['info'];
    
    return "<div class=\"{$colorClass} px-4 py-3 rounded relative mb-4 border\" role=\"alert\">
                <span class=\"block sm:inline\">{$message}</span>
            </div>";
}

/**
 * Redirection avec un message
 * @param string $url URL de destination
 * @param string $message Message à afficher
 * @param string $type Type d'alerte
 */
function redirectWithMessage($url, $message, $type = 'info') {
    $_SESSION['alert_message'] = $message;
    $_SESSION['alert_type'] = $type;
    header("Location: {$url}");
    exit;
}

/**
 * Vérifie si l'utilisateur est connecté
 * @return bool True si l'utilisateur est connecté, false sinon
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Vérifie si l'utilisateur possède un rôle spécifique
 * @param string $role Le rôle à vérifier
 * @return bool True si l'utilisateur a le rôle, false sinon
 */
function hasRole($role) {
    if (!isLoggedIn() || !isset($_SESSION['user_roles'])) {
        return false;
    }
    
    return in_array($role, $_SESSION['user_roles']);
}

/**
 * Récupère la valeur d'un paramètre système
 * @param string $key Clé du paramètre
 * @param mixed $default Valeur par défaut si le paramètre n'existe pas
 * @param bool $forceReload Forcer le rechargement depuis la base de données
 * @return mixed Valeur du paramètre ou valeur par défaut
 */
function getSetting($key, $default = null, $forceReload = false) {
    static $settings = null;
    static $lastLoad = 0;
    
    // Recharger depuis la base si demandé ou si cache trop vieux (5 minutes)
    if ($settings === null || $forceReload || (time() - $lastLoad) > 300) {
        try {
            require_once 'database.php';
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare('SELECT setting_key, setting_value, setting_type FROM settings');
            $stmt->execute();
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $settings = [];
            foreach ($result as $row) {
                $value = $row['setting_value'];
                
                // Convertir selon le type
                switch ($row['setting_type']) {
                    case 'integer':
                        $value = (int) $value;
                        break;
                    case 'boolean':
                        $value = in_array(strtolower($value), ['true', '1', 'yes', 'on']);
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
                
                $settings[$row['setting_key']] = $value;
            }
            
            $lastLoad = time();
            
        } catch (Exception $e) {
            // En cas d'erreur, retourner un tableau vide
            $settings = [];
        }
    }
    
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Définit la valeur d'un paramètre système
 * @param string $key Clé du paramètre
 * @param mixed $value Nouvelle valeur
 * @param string $type Type de la valeur (string, integer, boolean, json)
 * @return bool True si la mise à jour a réussi, false sinon
 */
function setSetting($key, $value, $type = 'string') {
    try {
        require_once 'config/database.php';
        $db = new Database();
        $conn = $db->getConnection();
        
        // Convertir la valeur selon le type pour le stockage
        $storedValue = $value;
        if ($type === 'boolean') {
            $storedValue = $value ? 'true' : 'false';
        } elseif ($type === 'json') {
            $storedValue = json_encode($value);
        }
        
        $stmt = $conn->prepare('
            INSERT INTO settings (setting_key, setting_value, setting_type, updated_at) 
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE 
            setting_value = VALUES(setting_value), 
            setting_type = VALUES(setting_type),
            updated_at = CURRENT_TIMESTAMP
        ');
        
        $result = $stmt->execute([$key, $storedValue, $type]);
        
        // Forcer le rechargement du cache
        if ($result) {
            getSetting('', null, true); // Force reload
        }
        
        return $result;
        
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Récupère tous les paramètres système organisés par catégorie
 * @return array Paramètres organisés par catégorie
 */
function getAllSettings() {
    try {
        require_once 'config/database.php';
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare('SELECT * FROM settings ORDER BY category, setting_key');
        $stmt->execute();
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($result as $row) {
            $value = $row['setting_value'];
            
            // Convertir selon le type
            switch ($row['setting_type']) {
                case 'integer':
                    $value = (int) $value;
                    break;
                case 'boolean':
                    $value = in_array(strtolower($value), ['true', '1', 'yes', 'on']);
                    break;
                case 'json':
                    $value = json_decode($value, true);
                    break;
            }
            
            $settings[$row['category']][$row['setting_key']] = [
                'value' => $value,
                'type' => $row['setting_type'],
                'description' => $row['description'],
                'is_system' => (bool) $row['is_system']
            ];
        }
        
        return $settings;
        
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Ajoute une entrée dans le journal d'audit
 */
function addAuditLog($conn, $userId, $action, $table) {
    try {
        $query = "INSERT INTO audit_logs (user_id, action, table_cible, date_action) VALUES (:user_id, :action, :table, NOW())";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':table', $table);
        $stmt->execute();
    } catch (PDOException $e) {
        // Silently fail to avoid breaking the main functionality
        error_log("Audit log error: " . $e->getMessage());
    }
}

/**
 * Génère un matricule unique pour un étudiant
 * Format: ISTI-YYYY-NNNN (ex: ISTI-2026-0001)
 * 
 * @param PDO $conn Connexion à la base de données
 * @param string $role Rôle de l'utilisateur
 * @return string Le matricule généré
 */
function generateMatricule($conn, $role = 'etudiant') {
    $year = date('Y');
    
    // Récupérer le dernier matricule de l'année en cours
    $query = "SELECT matricule FROM users 
              WHERE matricule LIKE :pattern 
              ORDER BY matricule DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $pattern = "ISTI-{$year}-%";
    $stmt->bindParam(':pattern', $pattern);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Extraire le numéro et incrémenter
        preg_match('/ISTI-\d{4}-(\d{4})/', $result['matricule'], $matches);
        $numero = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
    } else {
        // Premier matricule de l'année
        $numero = 1;
    }
    
    // Formater le matricule: ISTI-YYYY-NNNN
    $matricule = sprintf("ISTI-%s-%04d", $year, $numero);
    
    // Vérifier l'unicité (au cas où)
    $check_query = "SELECT id FROM users WHERE matricule = :matricule";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':matricule', $matricule);
    $check_stmt->execute();
    
    // Si le matricule existe déjà (très rare), réessayer avec le suivant
    if ($check_stmt->rowCount() > 0) {
        $numero++;
        $matricule = sprintf("ISTI-%s-%04d", $year, $numero);
    }
    
    return $matricule;
}

/**
 * Génère un matricule unique pour un enseignant
 * Format: ENS-YYYY-NNNN (ex: ENS-2026-0001)
 * 
 * @param PDO $conn Connexion à la base de données
 * @return string Le matricule généré
 */
function generateMatriculeEnseignant($conn) {
    $year = date('Y');
    
    // Récupérer le dernier matricule enseignant de l'année en cours
    $query = "SELECT matricule FROM users 
              WHERE matricule LIKE :pattern 
              ORDER BY matricule DESC LIMIT 1";
    $stmt = $conn->prepare($query);
    $pattern = "ENS-{$year}-%";
    $stmt->bindParam(':pattern', $pattern);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        // Extraire le numéro et incrémenter
        preg_match('/ENS-\d{4}-(\d{4})/', $result['matricule'], $matches);
        $numero = isset($matches[1]) ? intval($matches[1]) + 1 : 1;
    } else {
        // Premier matricule de l'année
        $numero = 1;
    }
    
    // Formater le matricule: ENS-YYYY-NNNN
    $matricule = sprintf("ENS-%s-%04d", $year, $numero);
    
    // Vérifier l'unicité
    $check_query = "SELECT id FROM users WHERE matricule = :matricule";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':matricule', $matricule);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        $numero++;
        $matricule = sprintf("ENS-%s-%04d", $year, $numero);
    }
    
    return $matricule;
}