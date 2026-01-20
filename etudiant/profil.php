<?php
/**
 * Profil de l'étudiant
 * Permet de consulter et modifier les informations personnelles
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('etudiant')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'étudiant pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération de l'inscription actuelle
$inscription_query = "SELECT i.*, c.niveau, f.nom as filiere_nom, d.nom as departement_nom
                     FROM inscriptions i
                     JOIN classes c ON i.classe_id = c.id
                     JOIN filieres f ON c.filiere_id = f.id
                     JOIN departements d ON f.departement_id = d.id
                     WHERE i.user_id = :user_id AND i.statut IN ('inscrit', 'reinscrit')
                     ORDER BY i.annee_academique DESC LIMIT 1";
$inscription_stmt = $conn->prepare($inscription_query);
$inscription_stmt->bindParam(':user_id', $user_id);
$inscription_stmt->execute();
$inscription = $inscription_stmt->fetch(PDO::FETCH_ASSOC);

// Traitement du formulaire de modification
$messages = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_profile') {
        $name = sanitize($_POST['name']);
        $phone = sanitize($_POST['phone']);
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        $errors = [];

        if (empty($name)) {
            $errors[] = 'Le nom est obligatoire.';
        }

        if (empty($phone)) {
            $errors[] = 'Le numéro de téléphone est obligatoire.';
        } elseif (!isValidPhone($phone)) {
            $errors[] = 'Le numéro de téléphone n\'est pas valide.';
        }

        if (!empty($new_password)) {
            if (empty($current_password)) {
                $errors[] = 'Le mot de passe actuel est requis pour changer de mot de passe.';
            } elseif (!password_verify($current_password, $user['password_hash'])) {
                $errors[] = 'Le mot de passe actuel est incorrect.';
            } elseif (strlen($new_password) < 8) {
                $errors[] = 'Le nouveau mot de passe doit contenir au moins 8 caractères.';
            } elseif ($new_password !== $confirm_password) {
                $errors[] = 'Les mots de passe ne correspondent pas.';
            }
        }

        if (empty($errors)) {
            try {
                $update_fields = ['name' => $name, 'phone' => $phone];
                $update_query = "UPDATE users SET name = :name, phone = :phone";

                if (!empty($new_password)) {
                    $update_fields['password'] = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_query .= ", password_hash = :password";
                }

                $update_query .= " WHERE id = :user_id";
                $update_stmt = $conn->prepare($update_query);

                foreach ($update_fields as $field => $value) {
                    $update_stmt->bindValue(':' . $field, $value);
                }
                $update_stmt->bindParam(':user_id', $user_id);
                $update_stmt->execute();

                $messages[] = ['type' => 'success', 'text' => 'Profil mis à jour avec succès.'];

                // Recharger les informations
                $user_stmt->execute();
                $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

            } catch (PDOException $e) {
                $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la mise à jour: ' . $e->getMessage()];
            }
        } else {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
            }
        }
    } elseif ($_POST['action'] === 'upload_photo') {
        $errors = [];
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Veuillez sélectionner une photo.';
        } else {
            // Validation du type de fichier
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_mime = mime_content_type($_FILES['photo']['tmp_name']);
            
            if (!in_array($file_mime, $allowed_types)) {
                $errors[] = 'Le fichier doit être une image (JPEG, PNG ou GIF).';
            } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) { // 5MB max
                $errors[] = 'L\'image ne doit pas dépasser 5 MB.';
            } else {
                // Créer le dossier s'il n'existe pas
                $upload_dir = '../uploads/profils/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                // Générer un nom de fichier unique
                $file_extension = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
                $new_filename = 'profil_' . $user_id . '_' . time() . '.' . strtolower($file_extension);
                $upload_path = $upload_dir . $new_filename;
                
                // Supprimer l'ancienne photo
                if ($user['photo_url'] && file_exists($user['photo_url'])) {
                    unlink($user['photo_url']);
                }
                
                // Uploader la nouvelle photo
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                    $photo_url = 'uploads/profils/' . $new_filename;
                    
                    // Mettre à jour la base de données
                    try {
                        $photo_query = "UPDATE users SET photo_url = :photo_url WHERE id = :user_id";
                        $photo_stmt = $conn->prepare($photo_query);
                        $photo_stmt->bindParam(':photo_url', $photo_url);
                        $photo_stmt->bindParam(':user_id', $user_id);
                        $photo_stmt->execute();
                        
                        $messages[] = ['type' => 'success', 'text' => 'Photo de profil mise à jour avec succès.'];
                        
                        // Recharger les informations
                        $user_stmt->execute();
                        $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $messages[] = ['type' => 'error', 'text' => 'Erreur lors de la mise à jour: ' . $e->getMessage()];
                    }
                } else {
                    $errors[] = 'Erreur lors du téléchargement de la photo.';
                }
            }
        }
        
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $messages[] = ['type' => 'error', 'text' => $error];
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Profil - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-graduation-cap text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Étudiant</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($user['name']); ?></span>
                    <a href="../shared/logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Navigation -->
    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="profil.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-user mr-1"></i>Profil
                </a>
                <a href="emploi_du_temps.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-calendar-alt mr-1"></i>Emploi du temps
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-chart-line mr-1"></i>Notes
                </a>
                <a href="demandes_documents.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="inscription.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscription
                </a>
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages -->
        <?php if (!empty($messages)): ?>
            <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded <?php echo $message['type'] === 'success' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-red-100 border-red-500 text-red-700'; ?> border">
                    <?php echo htmlspecialchars($message['text']); ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Informations générales -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-user mr-2"></i>Informations générales
                    </h2>

                    <div class="space-y-4">
                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Nom complet:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($user['name']); ?></div>
                        </div>

                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Email:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($user['email']); ?></div>
                        </div>

                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Téléphone:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($user['phone']); ?></div>
                        </div>

                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Statut:</div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Étudiant</span>
                        </div>

                        <?php if ($inscription): ?>
                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Classe:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($inscription['niveau'] . ' - ' . $inscription['filiere_nom']); ?></div>
                        </div>

                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Département:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($inscription['departement_nom']); ?></div>
                        </div>

                        <div class="flex items-center">
                            <div class="w-32 text-sm font-medium text-gray-600">Année:</div>
                            <div class="text-gray-900"><?php echo htmlspecialchars($inscription['annee_academique']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Formulaire de modification -->
                <div class="bg-white rounded-lg shadow-md p-6 mt-6">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-edit mr-2"></i>Modifier mes informations
                    </h2>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="action" value="update_profile">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nom complet *</label>
                                <input type="text" name="name" id="name" required
                                       value="<?php echo htmlspecialchars($user['name']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>

                            <div>
                                <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone *</label>
                                <input type="tel" name="phone" id="phone" required
                                       value="<?php echo htmlspecialchars($user['phone']); ?>"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            </div>
                        </div>

                        <div class="border-t pt-6">
                            <h3 class="text-lg font-medium text-gray-800 mb-4">Changer de mot de passe</h3>
                            <p class="text-sm text-gray-600 mb-4">Laissez vide si vous ne souhaitez pas changer de mot de passe.</p>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label for="current_password" class="block text-sm font-medium text-gray-700 mb-1">Mot de passe actuel</label>
                                    <input type="password" name="current_password" id="current_password"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>

                                <div>
                                    <label for="new_password" class="block text-sm font-medium text-gray-700 mb-1">Nouveau mot de passe</label>
                                    <input type="password" name="new_password" id="new_password" minlength="8"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>

                                <div>
                                    <label for="confirm_password" class="block text-sm font-medium text-gray-700 mb-1">Confirmer</label>
                                    <input type="password" name="confirm_password" id="confirm_password" minlength="8"
                                           class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                <i class="fas fa-save mr-2"></i>Mettre à jour
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sidebar -->
            <div>
                <!-- Photo de profil -->
                <div class="bg-white rounded-lg shadow-md p-6 mb-6">
                    <h3 class="text-lg font-medium text-gray-800 mb-4">Photo de profil</h3>
                    <div class="text-center">
                        <div class="w-24 h-24 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4 overflow-hidden">
                            <?php if ($user['photo_url'] && file_exists($user['photo_url'])): ?>
                                <img src="../<?php echo htmlspecialchars($user['photo_url']); ?>" alt="Photo de profil" class="w-full h-full object-cover">
                            <?php else: ?>
                                <i class="fas fa-user text-blue-600 text-3xl"></i>
                            <?php endif; ?>
                        </div>
                        <p class="text-sm text-gray-600 mb-3">
                            <?php echo $user['photo_url'] ? 'Photo définie' : 'Photo non disponible'; ?>
                        </p>
                        
                        <!-- Formulaire d'upload de photo -->
                        <form method="POST" enctype="multipart/form-data" class="space-y-2">
                            <input type="hidden" name="action" value="upload_photo">
                            <div class="flex items-center justify-center">
                                <label class="cursor-pointer">
                                    <input type="file" name="photo" accept="image/*" class="hidden" id="photo_input" required>
                                    <span class="text-blue-600 hover:text-blue-800 text-sm">
                                        <i class="fas fa-camera mr-1"></i>Changer la photo
                                    </span>
                                </label>
                            </div>
                            <input type="file" name="photo" accept="image/*" class="block w-full text-sm text-gray-500 file:mr-2 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100" required>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 text-sm">
                                <i class="fas fa-upload mr-2"></i>Télécharger
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Statistiques -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-lg font-medium text-gray-800 mb-4">Statistiques</h3>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Membre depuis:</span>
                            <span class="text-sm font-medium"><?php echo date('M Y', strtotime($user['created_at'] ?? 'now')); ?></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Dernière connexion:</span>
                            <span class="text-sm font-medium">Aujourd'hui</span>
                        </div>
                        <?php if ($inscription): ?>
                        <div class="flex justify-between">
                            <span class="text-sm text-gray-600">Statut inscription:</span>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full
                                <?php echo $inscription['statut'] === 'inscrit' ? 'bg-green-100 text-green-800' : 'bg-blue-100 text-blue-800'; ?>">
                                <?php echo ucfirst($inscription['statut']); ?>
                            </span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
            <div class="text-center">
                <p>&copy; 2024 Institut Supérieur de Technologie et d'Informatique. Tous droits réservés.</p>
            </div>
        </div>
    </footer>
</body>
</html>