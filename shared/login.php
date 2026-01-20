<?php
require_once '../config/database.php';
require_once '../config/utils.php';

// Initialisation des variables
$error = '';

// Vérifier si l'utilisateur est déjà connecté
if (isset($_SESSION['user_id']) && isset($_SESSION['user_roles'])) {
    $roles = $_SESSION['user_roles'];
    $redirect_url = "../dashboard.php"; // par défaut

    if (count($roles) > 1) {
        $redirect_url = "role_select.php";
    } else {
        $role = $roles[0];
        switch ($role) {
            case 'admin':
                $redirect_url = "../admin_general/dashboard.php";
                break;
            case 'resp_dept':
                $redirect_url = "../responsable_departement/dashboard.php";
                break;
            case 'resp_filiere':
                $redirect_url = "../responsable_filiere/dashboard.php";
                break;
            case 'resp_classe':
                $redirect_url = "../responsable_classe/dashboard.php";
                break;
            case 'etudiant':
                $redirect_url = "../etudiant/dashboard.php";
                break;
            case 'enseignant':
                $redirect_url = "../enseignant/dashboard.php";
                break;
            case 'agent_admin':
                $redirect_url = "../agent_administratif/dashboard.php";
                break;
        }
    }

    header("Location: $redirect_url");
    exit;
}


// Traitement du formulaire de connexion
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Validation des champs
    if (empty($email) || empty($password)) {
        $error = "Veuillez remplir tous les champs";
    } else {
        try {
            // Connexion à la base de données
            $database = new Database();
            $db = $database->getConnection();
            
            // Recherche de l'utilisateur par email
            $query = "SELECT u.id, u.name, u.email, u.password_hash, u.is_active 
                      FROM users u 
                      WHERE u.email = :email";
            $stmt = $db->prepare($query);
            $stmt->bindParam(":email", $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Vérifier si le compte est actif
                if (!$user['is_active']) {
                    $error = "Votre compte est désactivé. Veuillez contacter l'administrateur.";
                } 
                // Vérifier le mot de passe
                elseif (password_verify($password, $user['password_hash'])) {
                    // Récupérer les rôles de l'utilisateur
                    $roles_query = "SELECT role FROM user_roles WHERE user_id = :user_id";
                    $roles_stmt = $db->prepare($roles_query);
                    $roles_stmt->bindParam(":user_id", $user['id']);
                    $roles_stmt->execute();
                    
                    $roles = [];
                    while ($role_row = $roles_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $roles[] = $role_row['role'];
                    }
                    
                    // Stocker les informations de session
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_roles'] = $roles;
                    
                    // Enregistrer la session d'authentification
                    $auth_query = "INSERT INTO auth_sessions (user_id, token, ip_adresse, agent_user) 
                                   VALUES (:user_id, :token, :ip, :agent)";
                    $auth_stmt = $db->prepare($auth_query);
                    
                    // Générer un token de session
                    $token = bin2hex(random_bytes(32));
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $agent = $_SERVER['HTTP_USER_AGENT'];
                    
                    $auth_stmt->bindParam(":user_id", $user['id']);
                    $auth_stmt->bindParam(":token", $token);
                    $auth_stmt->bindParam(":ip", $ip);
                    $auth_stmt->bindParam(":agent", $agent);
                    $auth_stmt->execute();
                    
                    // Enregistrer l'action dans les logs d'audit
                    $audit_query = "INSERT INTO audit_logs (user_id, action, table_cible) 
                                    VALUES (:user_id, :action, :table_cible)";
                    $audit_stmt = $db->prepare($audit_query);
                    $action = "Connexion au système";
                    $table = "auth_sessions";
                    $audit_stmt->bindParam(":user_id", $user['id']);
                    $audit_stmt->bindParam(":action", $action);
                    $audit_stmt->bindParam(":table_cible", $table);
                    $audit_stmt->execute();
                    
                    // Rediriger vers la page appropriée selon le rôle
                    $redirect_url = "../dashboard.php";
                    
                    // Si l'utilisateur a plusieurs rôles, on le redirige vers une page de sélection de rôle
                    if (count($roles) > 1) {
                        $redirect_url = "role_select.php";
                    } 
                    // Sinon, on redirige selon le rôle unique
                    else {
                        $role = $roles[0];
                        switch ($role) {
                            case 'admin':
                                $redirect_url = "../admin_general/dashboard.php";
                                break;
                            case 'resp_dept':
                                $redirect_url = "../responsable_departement/dashboard.php";
                                break;
                            case 'resp_filiere':
                                $redirect_url = "../responsable_filiere/dashboard.php";
                                break;
                            case 'resp_classe':
                                $redirect_url = "../responsable_classe/dashboard.php";
                                break;
                            case 'etudiant':
                                $redirect_url = "../etudiant/dashboard.php";
                                break;
                            case 'enseignant':
                                $redirect_url = "../enseignant/dashboard.php";
                                break;
                            case 'agent_admin':
                                $redirect_url = "../agent_administratif/dashboard.php";
                                break;
                            default:
                                $redirect_url = "../dashboard.php";
                        }
                    }
                    
                    header("Location: " . $redirect_url);
                    exit;
                } else {
                    $error = "Mot de passe incorrect";
                }
            } else {
                $error = "Aucun compte trouvé avec cet email";
            }
        } catch (PDOException $e) {
            $error = "Erreur de base de données: " . $e->getMessage();
        }
    }
}

// Inclusion du header
include_once 'includes/header.php';
?>

<div class="max-w-md mx-auto">
    <h1 class="text-3xl font-bold text-center mb-8 text-gray-800">Connexion</h1>
    
    <?php if (!empty($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo $error; ?></p>
        </div>
    <?php endif; ?>
    
    <div class="bg-white shadow-md rounded-lg p-8">
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input type="email" name="email" id="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
            </div>
            
            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Mot de passe</label>
                <input type="password" name="password" id="password" class="mt-1 block w-full p-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
            </div>
            
            <div class="flex items-center justify-between">
                <div class="flex items-center">
                    <input id="remember_me" name="remember_me" type="checkbox" class="h-4 w-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500">
                    <label for="remember_me" class="ml-2 block text-sm text-gray-700">
                        Se souvenir de moi
                    </label>
                </div>
                
                <div class="text-sm">
                    <a href="forgot_password.php" class="font-medium text-blue-600 hover:text-blue-500">
                        Mot de passe oublié ?
                    </a>
                </div>
            </div>
            
            <div>
                <button type="submit" class="w-full flex justify-center py-3 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                    Se connecter
                </button>
            </div>
        </form>
        
        <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">
                Vous n'avez pas de compte ? 
                <a href="register.php" class="font-medium text-blue-600 hover:text-blue-500">
                    Inscrivez-vous
                </a>
            </p>
        </div>
    </div>
</div>

<?php
// Inclusion du footer
include_once 'includes/footer.php';
?>