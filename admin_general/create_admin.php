<?php
/**
 * Script pour créer un administrateur du système
 * À exécuter directement depuis la ligne de commande ou depuis un navigateur avec authentification
 * 
 * ATTENTION: Ce script ne doit être accessible qu'aux personnes autorisées
 * Il est recommandé de le supprimer ou de le protéger après utilisation
 */

// Protection par mot de passe si accès via navigateur
if (php_sapi_name() !== 'cli') {
    // Pour accès navigateur, nécessite authentification
    if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])
        || $_SERVER['PHP_AUTH_USER'] !== 'setup' || $_SERVER['PHP_AUTH_PW'] !== 'poiuy') {
        header('WWW-Authenticate: Basic realm="Admin Setup"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Authentification requise pour cette page';
        exit;
    }
}

require_once '../config/database.php';
require_once '../config/utils.php';

// Fonction pour créer un administrateur en ligne de commande ou via formulaire
function createAdmin($name, $email, $password, $phone = null) {
    try {
        // Validation de base
        if (empty($name) || empty($email) || empty($password)) {
            return ["success" => false, "message" => "Tous les champs requis doivent être remplis"];
        }
        
        if (!isValidEmail($email)) {
            return ["success" => false, "message" => "L'email n'est pas valide"];
        }
        
        if (strlen($password) < 8) {
            return ["success" => false, "message" => "Le mot de passe doit contenir au moins 8 caractères"];
        }
        
        // Connexion à la base de données
        $database = new Database();
        $db = $database->getConnection();
        
        // Vérifier si l'email existe déjà
        $check_query = "SELECT id FROM users WHERE email = :email";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(":email", $email);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            return ["success" => false, "message" => "Cet email est déjà utilisé"];
        }
        
        // Hachage du mot de passe
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        // Démarrer une transaction
        $db->beginTransaction();
        
        try {
            // Insertion de l'utilisateur dans la base de données
            $user_query = "INSERT INTO users (name, email, password_hash, phone, is_active) 
                           VALUES (:name, :email, :password_hash, :phone, true)";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindParam(":name", $name);
            $user_stmt->bindParam(":email", $email);
            $user_stmt->bindParam(":password_hash", $password_hash);
            $user_stmt->bindParam(":phone", $phone);
            
            if ($user_stmt->execute()) {
                $user_id = $db->lastInsertId();
                
                // Ajout du rôle administrateur
                $role_query = "INSERT INTO user_roles (user_id, role) VALUES (:user_id, 'admin')";
                $role_stmt = $db->prepare($role_query);
                $role_stmt->bindParam(":user_id", $user_id);
                
                if ($role_stmt->execute()) {
                    // Enregistrement dans les logs d'audit
                    $audit_query = "INSERT INTO audit_logs (user_id, action, table_cible) 
                                   VALUES (:user_id, :action, :table_cible)";
                    $audit_stmt = $db->prepare($audit_query);
                    $action = "Création de compte administrateur";
                    $table = "users, user_roles";
                    $audit_stmt->bindParam(":user_id", $user_id);
                    $audit_stmt->bindParam(":action", $action);
                    $audit_stmt->bindParam(":table_cible", $table);
                    $audit_stmt->execute();
                    
                    // Valider la transaction
                    $db->commit();
                    
                    return [
                        "success" => true, 
                        "message" => "Compte administrateur créé avec succès", 
                        "user_id" => $user_id
                    ];
                } else {
                    $db->rollBack();
                    return ["success" => false, "message" => "Erreur lors de l'attribution du rôle admin"];
                }
            } else {
                $db->rollBack();
                return ["success" => false, "message" => "Erreur lors de la création du compte"];
            }
        } catch (Exception $e) {
            $db->rollBack();
            return ["success" => false, "message" => "Erreur lors de la transaction: " . $e->getMessage()];
        }
    } catch (PDOException $e) {
        return ["success" => false, "message" => "Erreur de base de données: " . $e->getMessage()];
    }
}

// Mode CLI (ligne de commande)
if (php_sapi_name() === 'cli') {
    echo "=== Création d'un compte administrateur ===\n\n";
    
    // Saisie des informations via la ligne de commande
    echo "Nom complet: ";
    $name = trim(fgets(STDIN));
    
    echo "Email: ";
    $email = trim(fgets(STDIN));
    
    echo "Téléphone (optionnel): ";
    $phone = trim(fgets(STDIN));
    
    echo "Mot de passe: ";
    // Pour masquer le mot de passe lors de la saisie sur Unix/Linux
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n"; // Pour que le prochain output soit sur une nouvelle ligne
    } else {
        // Sur Windows, pas de masquage simple du mot de passe
        $password = trim(fgets(STDIN));
    }
    
    // Confirmer le mot de passe
    echo "Confirmer le mot de passe: ";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $confirm_password = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $confirm_password = trim(fgets(STDIN));
    }
    
    // Vérifier que les mots de passe correspondent
    if ($password !== $confirm_password) {
        echo "ERREUR: Les mots de passe ne correspondent pas.\n";
        exit(1);
    }
    
    // Créer l'administrateur
    $result = createAdmin($name, $email, $password, $phone);
    
    if ($result["success"]) {
        echo "SUCCÈS: " . $result["message"] . " (ID: " . $result["user_id"] . ")\n";
        exit(0);
    } else {
        echo "ERREUR: " . $result["message"] . "\n";
        exit(1);
    }
}
// Mode navigateur web
else {
    $message = '';
    $status = '';
    
    // Traitement du formulaire
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $name = sanitize($_POST['name'] ?? '');
        $email = sanitize($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $phone = sanitize($_POST['phone'] ?? '');
        
        // Vérifier que les mots de passe correspondent
        if ($password !== $confirm_password) {
            $message = "Les mots de passe ne correspondent pas";
            $status = "error";
        } else {
            // Créer l'administrateur
            $result = createAdmin($name, $email, $password, $phone);
            
            if ($result["success"]) {
                $message = $result["message"];
                $status = "success";
            } else {
                $message = $result["message"];
                $status = "error";
            }
        }
    }
    
    // Affichage du formulaire
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Création d'un compte administrateur</title>
        <!-- Tailwind CSS via CDN -->
        <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    </head>
    <body class="bg-gray-100">
        <div class="max-w-md mx-auto py-12">
            <div class="bg-white shadow-md rounded-lg p-8">
                <h1 class="text-2xl font-bold text-center mb-6 text-gray-800">Création d'un administrateur</h1>
                
                <?php if (!empty($message)): ?>
                    <div class="<?php echo $status === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700'; ?> border-l-4 p-4 mb-6" role="alert">
                        <p><?php echo $message; ?></p>
                    </div>
                <?php endif; ?>
                
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Nom complet</label>
                        <input type="text" name="name" id="name" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm" required>
                    </div>
                    
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                        <input type="email" name="email" id="email" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm" required>
                    </div>
                    
                    <div>
                        <label for="phone" class="block text-sm font-medium text-gray-700">Téléphone (optionnel)</label>
                        <input type="tel" name="phone" id="phone" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm">
                    </div>
                    
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">Mot de passe</label>
                        <input type="password" name="password" id="password" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm" required>
                        <p class="text-xs text-gray-500 mt-1">Le mot de passe doit contenir au moins 8 caractères.</p>
                    </div>
                    
                    <div>
                        <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirmer le mot de passe</label>
                        <input type="password" name="confirm_password" id="confirm_password" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm" required>
                    </div>
                    
                    <div>
                        <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700">
                            Créer l'administrateur
                        </button>
                    </div>
                </form>
                
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-600">
                        <strong class="text-red-500">IMPORTANT:</strong> Supprimez ce fichier après utilisation pour des raisons de sécurité.
                    </p>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>