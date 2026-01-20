<?php
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

// Vérifier si l'utilisateur a plusieurs rôles
if (!isset($_SESSION['user_roles']) || count($_SESSION['user_roles']) <= 1) {
    // Rediriger vers le tableau de bord approprié selon le rôle unique
    $role = $_SESSION['user_roles'][0] ?? '';

    switch ($role) {
        case 'admin':
            header("Location: ../admin_general/dashboard.php");
            break;
        case 'resp_dept':
            header("Location: ../responsable_departement/dashboard.php");
            break;
        case 'resp_filiere':
            header("Location: ../responsable_filiere/dashboard.php");
            break;
        case 'resp_classe':
            header("Location: ../responsable_classe/dashboard.php");
            break;
        case 'etudiant':
            header("Location: ../etudiant/dashboard.php");
            break;
        case 'enseignant':
            header("Location: ../enseignant/dashboard.php");
            break;
        case 'agent_admin':
            header("Location: ../agent_administratif/dashboard.php");
            break;
        default:
            header("Location: ../dashboard.php");
    }
    exit;
}

// Traitement de la sélection du rôle
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['selected_role'])) {
    $selected_role = sanitize($_POST['selected_role']);
    
    // Vérifier si le rôle sélectionné fait partie des rôles de l'utilisateur
    if (in_array($selected_role, $_SESSION['user_roles'])) {
        $_SESSION['active_role'] = $selected_role;

        switch ($selected_role) {
            case 'admin':
                header("Location: ../admin_general/dashboard.php");
                break;
            case 'resp_dept':
                header("Location: ../responsable_departement/dashboard.php");
                break;
            case 'resp_filiere':
                header("Location: ../responsable_filiere/dashboard.php");
                break;
            case 'resp_classe':
                header("Location: ../responsable_classe/dashboard.php");
                break;
            case 'etudiant':
                header("Location: ../etudiant/dashboard.php");
                break;
            case 'enseignant':
                header("Location: ../enseignant/dashboard.php");
                break;
            case 'agent_admin':
                header("Location: ../agent_administratif/dashboard.php");
                break;
            default:
                $error = "Rôle non reconnu.";
        }
        exit;
    } else {
        $error = "Rôle non autorisé";
    }
}

// Tableaux d'affichage
$role_titles = [
    'admin' => 'Administrateur Général',
    'resp_dept' => 'Responsable de Département',
    'resp_filiere' => 'Responsable de Filière',
    'resp_classe' => 'Responsable de Classe',
    'etudiant' => 'Étudiant',
    'enseignant' => 'Enseignant',
    'agent_admin' => 'Agent Administratif'
];

$role_descriptions = [
    'admin' => 'Gérez tous les aspects de la plateforme, les utilisateurs, les départements et les statistiques.',
    'resp_dept' => 'Supervisez les filières de votre département et validez les documents importants.',
    'resp_filiere' => 'Gérez les classes, les enseignants et les emplois du temps de votre filière.',
    'resp_classe' => 'Représentez votre classe, signalez des problèmes et partagez des documents.',
    'etudiant' => 'Accédez à votre emploi du temps, demandez des documents et suivez votre parcours.',
    'enseignant' => 'Consultez votre planning et partagez des ressources pédagogiques.',
    'agent_admin' => 'Traitez les inscriptions et générez des documents administratifs.'
];

$role_icons = [
    'admin' => 'fa-user-shield',
    'resp_dept' => 'fa-building',
    'resp_filiere' => 'fa-graduation-cap',
    'resp_classe' => 'fa-users',
    'etudiant' => 'fa-user-graduate',
    'enseignant' => 'fa-chalkboard-teacher',
    'agent_admin' => 'fa-clipboard-list'
];

// Inclusion du header
include_once 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <h1 class="text-3xl font-bold text-center mb-8 text-gray-800">Sélectionnez votre rôle</h1>
    
    <?php if (isset($error)): ?>
        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
            <p><?php echo $error; ?></p>
        </div>
    <?php endif; ?>
    
    <div class="bg-white shadow-md rounded-lg p-8">
        <p class="text-gray-600 mb-6 text-center">
            Votre compte dispose de plusieurs rôles. Veuillez sélectionner le rôle avec lequel vous souhaitez vous connecter.
        </p>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <?php foreach ($_SESSION['user_roles'] as $role): ?>
                <div class="border rounded-lg p-6 hover:shadow-lg transition-shadow">
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <input type="hidden" name="selected_role" value="<?php echo $role; ?>">
                        
                        <div class="flex items-center mb-4">
                            <div class="bg-blue-100 p-3 rounded-full mr-4">
                                <i class="fas <?php echo $role_icons[$role] ?? 'fa-user'; ?> text-blue-600 text-xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800">
                                <?php echo $role_titles[$role] ?? ucfirst($role); ?>
                            </h3>
                        </div>
                        
                        <p class="text-gray-600 mb-4">
                            <?php echo $role_descriptions[$role] ?? 'Accédez aux fonctionnalités associées à ce rôle.'; ?>
                        </p>
                        
                        <button type="submit" class="w-full py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Continuer avec ce rôle
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <div class="mt-6 text-center">
        <a href="../shared/logout.php" class="text-sm bg-red-700 hover:bg-red-800 text-white px-3 py-1 rounded">
            <i class="fas fa-sign-out-alt mr-1"></i> Déconnexion
        </a>
    </div>
</div>

<?php
// Inclusion du footer
include_once 'includes/footer.php';
?>