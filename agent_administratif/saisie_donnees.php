<?php
/**
 * Saisie de données - Agent Administratif
 * Permet la saisie en masse de données administratives (étudiants, notes, etc.)
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification et des droits d'accès
if (!isLoggedIn() || !hasRole('agent_admin')) {
    redirectWithMessage('../shared/login.php', 'Vous devez être connecté en tant qu\'agent administratif pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Récupération des informations de l'utilisateur
$user_id = $_SESSION['user_id'];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = sanitize($_POST['action']);

        if ($action === 'importer_etudiants' && isset($_FILES['fichier_csv'])) {
            $file = $_FILES['fichier_csv'];

            if ($file['error'] === UPLOAD_ERR_OK) {
                $handle = fopen($file['tmp_name'], 'r');

                if ($handle !== false) {
                    $success_count = 0;
                    $error_count = 0;
                    $errors = [];

                    // Ignorer la première ligne (en-têtes)
                    fgetcsv($handle, 1000, ';');

                    while (($data = fgetcsv($handle, 1000, ';')) !== false) {
                        if (count($data) >= 5) { // Changé de 6 à 5 car le matricule n'est plus demandé
                            $nom = sanitize($data[0]);
                            $prenom = sanitize($data[1]);
                            $email = sanitize($data[2]);
                            $date_naissance = sanitize($data[3]);
                            $telephone = sanitize($data[4]);

                            // Validation basique
                            if (empty($nom) || empty($prenom) || empty($email)) {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Données manquantes";
                                continue;
                            }

                            // Vérifier si l'étudiant existe déjà
                            $check_query = "SELECT id FROM users WHERE email = :email";
                            $check_stmt = $conn->prepare($check_query);
                            $check_stmt->bindParam(':email', $email);
                            $check_stmt->execute();

                            if ($check_stmt->rowCount() > 0) {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Étudiant déjà existant ($email)";
                                continue;
                            }

                            // Générer un matricule automatique
                            $matricule = generateMatricule($conn, 'etudiant');

                            // Insérer l'étudiant
                            $insert_query = "INSERT INTO users (name, email, matricule, date_naissance, telephone, role, created_at)
                                           VALUES (:name, :email, :matricule, :date_naissance, :telephone, 'etudiant', NOW())";
                            $insert_stmt = $conn->prepare($insert_query);
                            $insert_stmt->bindParam(':name', $nom);
                            $insert_stmt->bindParam(':email', $email);
                            $insert_stmt->bindParam(':matricule', $matricule);
                            $insert_stmt->bindParam(':date_naissance', $date_naissance);
                            $insert_stmt->bindParam(':telephone', $telephone);

                            if ($insert_stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Erreur d'insertion";
                            }
                        } else {
                            $error_count++;
                            $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Nombre de colonnes incorrect";
                        }
                    }

                    fclose($handle);

                    $message = "Import terminé: $success_count étudiants importés, $error_count erreurs.";
                    if (!empty($errors)) {
                        $message .= " Erreurs: " . implode('; ', array_slice($errors, 0, 5));
                        if (count($errors) > 5) {
                            $message .= "...";
                        }
                    }
                    $message_type = $error_count > 0 ? "warning" : "success";
                } else {
                    $message = "Erreur lors de l'ouverture du fichier.";
                    $message_type = "error";
                }
            } else {
                $message = "Erreur lors du téléchargement du fichier.";
                $message_type = "error";
            }
        }

        if ($action === 'importer_notes' && isset($_FILES['fichier_notes_csv'])) {
            $file = $_FILES['fichier_notes_csv'];

            if ($file['error'] === UPLOAD_ERR_OK) {
                $handle = fopen($file['tmp_name'], 'r');

                if ($handle !== false) {
                    $success_count = 0;
                    $error_count = 0;
                    $errors = [];

                    // Ignorer la première ligne (en-têtes)
                    fgetcsv($handle, 1000, ';');

                    while (($data = fgetcsv($handle, 1000, ';')) !== false) {
                        if (count($data) >= 4) {
                            $matricule = sanitize($data[0]);
                            $matiere_id = (int)$data[1];
                            $note = (float)$data[2];
                            $annee_academique = sanitize($data[3]);

                            // Validation basique
                            if (empty($matricule) || $matiere_id <= 0 || $note < 0 || $note > 20) {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Données invalides";
                                continue;
                            }

                            // Récupérer l'ID de l'étudiant
                            $student_query = "SELECT id FROM users WHERE matricule = :matricule";
                            $student_stmt = $conn->prepare($student_query);
                            $student_stmt->bindParam(':matricule', $matricule);
                            $student_stmt->execute();
                            $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

                            if (!$student) {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Étudiant non trouvé ($matricule)";
                                continue;
                            }

                            // Insérer ou mettre à jour la note
                            $insert_query = "INSERT INTO notes (etudiant_id, enseignement_id, note, type_evaluation, date_saisie)
                                           VALUES (:etudiant_id, :enseignement_id, :note, 'devoir', NOW())
                                           ON DUPLICATE KEY UPDATE note = :note";
                            $insert_stmt = $conn->prepare($insert_query);
                            $insert_stmt->bindParam(':etudiant_id', $student['id']);
                            $insert_stmt->bindParam(':enseignement_id', $matiere_id);
                            $insert_stmt->bindParam(':note', $note);

                            if ($insert_stmt->execute()) {
                                $success_count++;
                            } else {
                                $error_count++;
                                $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Erreur d'insertion";
                            }
                        } else {
                            $error_count++;
                            $errors[] = "Ligne " . ($success_count + $error_count + 1) . ": Nombre de colonnes incorrect";
                        }
                    }

                    fclose($handle);

                    $message = "Import terminé: $success_count notes importées, $error_count erreurs.";
                    if (!empty($errors)) {
                        $message .= " Erreurs: " . implode('; ', array_slice($errors, 0, 5));
                        if (count($errors) > 5) {
                            $message .= "...";
                        }
                    }
                    $message_type = $error_count > 0 ? "warning" : "success";
                } else {
                    $message = "Erreur lors de l'ouverture du fichier.";
                    $message_type = "error";
                }
            } else {
                $message = "Erreur lors du téléchargement du fichier.";
                $message_type = "error";
            }
        }

        if ($action === 'ajouter_etudiant_manuel') {
            $nom = sanitize($_POST['nom'] ?? '');
            $prenom = sanitize($_POST['prenom'] ?? '');
            $email = sanitize($_POST['email'] ?? '');
            $date_naissance = sanitize($_POST['date_naissance'] ?? '');
            $telephone = sanitize($_POST['telephone'] ?? '');

            if (empty($nom) || empty($prenom) || empty($email)) {
                $message = "Tous les champs obligatoires doivent être remplis.";
                $message_type = "error";
            } else {
                // Vérifier si l'étudiant existe déjà
                $check_query = "SELECT id FROM users WHERE email = :email";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->bindParam(':email', $email);
                $check_stmt->execute();

                if ($check_stmt->rowCount() > 0) {
                    $message = "Un étudiant avec cet email existe déjà.";
                    $message_type = "error";
                } else {
                    // Générer automatiquement le matricule
                    $matricule = generateMatricule($conn, 'etudiant');
                    
                    $name = trim($nom . ' ' . $prenom);
                    $insert_query = "INSERT INTO users (name, email, matricule, date_naissance, telephone, role, created_at)
                                   VALUES (:name, :email, :matricule, :date_naissance, :telephone, 'etudiant', NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':name', $name);
                    $insert_stmt->bindParam(':email', $email);
                    $insert_stmt->bindParam(':matricule', $matricule);
                    $insert_stmt->bindParam(':date_naissance', $date_naissance);
                    $insert_stmt->bindParam(':telephone', $telephone);

                    if ($insert_stmt->execute()) {
                        $message = "Étudiant ajouté avec succès.";
                        $message_type = "success";
                    } else {
                        $message = "Erreur lors de l'ajout de l'étudiant.";
                        $message_type = "error";
                    }
                }
            }
        }
    }
}

// Statistiques de saisie
$stats_query = "SELECT
    (SELECT COUNT(*) FROM users WHERE role = 'etudiant') as total_etudiants,
    (SELECT COUNT(*) FROM notes) as total_notes,
    (SELECT COUNT(*) FROM inscriptions) as inscriptions_total,
    (SELECT COUNT(*) FROM notes WHERE DATE(date_saisie) = CURDATE()) as notes_aujourdhui";
$stats_stmt = $conn->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération des dernières activités
$activites_query = "SELECT 'Étudiant ajouté' as action, name as details, created_at
                   FROM users WHERE role = 'etudiant' AND DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   UNION ALL
                   SELECT 'Note saisie' as action, CONCAT('Étudiant ID: ', etudiant_id, ' - Matière ID: ', enseignement_id) as details, date_saisie as created_at
                   FROM notes WHERE DATE(date_saisie) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                   ORDER BY created_at DESC LIMIT 10";
$activites_stmt = $conn->prepare($activites_query);
$activites_stmt->execute();
$activites = $activites_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saisie de Données - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-edit text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Agent Administratif</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Agent'); ?></span>
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
                <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="inscriptions.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-user-plus mr-1"></i>Inscriptions
                </a>
                <a href="attestation_inscription.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-contract mr-1"></i>Attestations
                </a>
                <a href="certificat_scolarite.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-graduation-cap mr-1"></i>Certificats
                </a>
                <a href="releve_notes.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-chart-bar mr-1"></i>Relevés
                </a>
                <a href="documents.php" class="text-gray-600 hover:text-indigo-600">
                    <i class="fas fa-file-alt mr-1"></i>Documents
                </a>
                <a href="saisie_donnees.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2">
                    <i class="fas fa-edit mr-1"></i>Saisie
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Message de succès/erreur -->
        <?php if (isset($message)): ?>
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-400 text-<?php echo $message_type === 'success' ? 'green' : ($message_type === 'warning' ? 'yellow' : 'red'); ?>-700 px-4 py-3 rounded">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check' : ($message_type === 'warning' ? 'exclamation' : 'exclamation'); ?>-circle"></i>
                    </div>
                    <div class="ml-3">
                        <p><?php echo htmlspecialchars($message); ?></p>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Statistiques -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-users text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total étudiants</h3>
                        <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_etudiants']; ?></p>
                        <p class="text-sm text-gray-600">inscrits</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-chart-bar text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Total notes</h3>
                        <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_notes']; ?></p>
                        <p class="text-sm text-gray-600">saisies</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-user-plus text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Inscriptions</h3>
                        <p class="text-2xl font-bold text-yellow-600"><?php echo $stats['inscriptions_total']; ?></p>
                        <p class="text-sm text-gray-600">total</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-purple-100 rounded-full p-3">
                        <i class="fas fa-edit text-purple-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="font-semibold text-gray-800">Notes saisies</h3>
                        <p class="text-2xl font-bold text-purple-600"><?php echo $stats['notes_aujourdhui']; ?></p>
                        <p class="text-sm text-gray-600">aujourd'hui</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Onglets -->
        <div class="bg-white rounded-lg shadow-md">
            <div class="border-b border-gray-200">
                <nav class="-mb-px flex">
                    <button onclick="showTab('import')" id="tab-import" class="tab-button w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm
                        <?php echo (!isset($_GET['tab']) || $_GET['tab'] !== 'manuel') ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                        Import CSV
                    </button>
                    <button onclick="showTab('manuel')" id="tab-manuel" class="tab-button w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm
                        <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'manuel') ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                        Saisie manuelle
                    </button>
                    <button onclick="showTab('historique')" id="tab-historique" class="tab-button w-1/3 py-4 px-1 text-center border-b-2 font-medium text-sm
                        <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'historique') ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?>">
                        Historique
                    </button>
                </nav>
            </div>

            <div class="p-6">
                <!-- Import CSV -->
                <div id="content-import" class="tab-content <?php echo (!isset($_GET['tab']) || $_GET['tab'] !== 'manuel') ? '' : 'hidden'; ?>">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-upload mr-2"></i>Import de données CSV
                    </h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                        <!-- Import étudiants -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                                <i class="fas fa-users mr-2"></i>Importer des étudiants
                            </h3>

                            <div class="mb-4">
                                <p class="text-sm text-gray-600 mb-3">
                                    Format CSV attendu (séparateur: point-virgule):<br>
                                    <code class="bg-gray-200 px-2 py-1 rounded text-xs">Nom;Prénom;Email;Date_naissance;Téléphone</code>
                                </p>
                                <p class="text-sm text-blue-600 mb-3">
                                    <i class="fas fa-info-circle mr-1"></i>Le matricule sera généré automatiquement
                                </p>

                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="importer_etudiants">
                                    <div class="mb-4">
                                        <label for="fichier_csv" class="block text-sm font-medium text-gray-700 mb-2">
                                            Fichier CSV des étudiants
                                        </label>
                                        <input type="file" id="fichier_csv" name="fichier_csv" accept=".csv"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                                    </div>
                                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-upload mr-2"></i>Importer les étudiants
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Import notes -->
                        <div class="bg-gray-50 rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">
                                <i class="fas fa-chart-bar mr-2"></i>Importer des notes
                            </h3>

                            <div class="mb-4">
                                <p class="text-sm text-gray-600 mb-3">
                                    Format CSV attendu (séparateur: point-virgule):<br>
                                    <code class="bg-gray-200 px-2 py-1 rounded text-xs">Matricule;Enseignement_ID;Note</code>
                                </p>

                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="importer_notes">
                                    <div class="mb-4">
                                        <label for="fichier_notes_csv" class="block text-sm font-medium text-gray-700 mb-2">
                                            Fichier CSV des notes
                                        </label>
                                        <input type="file" id="fichier_notes_csv" name="fichier_notes_csv" accept=".csv"
                                               class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500" required>
                                    </div>
                                    <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md transition duration-200">
                                        <i class="fas fa-upload mr-2"></i>Importer les notes
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Saisie manuelle -->
                <div id="content-manuel" class="tab-content <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'manuel') ? '' : 'hidden'; ?>">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-plus mr-2"></i>Ajouter un étudiant manuellement
                    </h2>

                    <form method="POST" class="bg-gray-50 rounded-lg p-6">
                        <input type="hidden" name="action" value="ajouter_etudiant_manuel">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="nom" class="block text-sm font-medium text-gray-700 mb-2">
                                    Nom *
                                </label>
                                <input type="text" id="nom" name="nom" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="prenom" class="block text-sm font-medium text-gray-700 mb-2">
                                    Prénom *
                                </label>
                                <input type="text" id="prenom" name="prenom" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                                    Email *
                                </label>
                                <input type="email" id="email" name="email" required
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div class="bg-blue-50 border-l-4 border-blue-500 p-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-info-circle text-blue-500"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-blue-700">
                                            <strong>Matricule automatique :</strong> Le matricule sera généré automatiquement au format <code>ISTI-YYYY-NNNN</code> (ex: ISTI-2026-0001)
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div>
                                <label for="date_naissance" class="block text-sm font-medium text-gray-700 mb-2">
                                    Date de naissance
                                </label>
                                <input type="date" id="date_naissance" name="date_naissance"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <div>
                                <label for="telephone" class="block text-sm font-medium text-gray-700 mb-2">
                                    Téléphone
                                </label>
                                <input type="tel" id="telephone" name="telephone"
                                       class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <div class="mt-6">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-6 rounded-md transition duration-200">
                                <i class="fas fa-plus mr-2"></i>Ajouter l'étudiant
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Historique -->
                <div id="content-historique" class="tab-content <?php echo (isset($_GET['tab']) && $_GET['tab'] === 'historique') ? '' : 'hidden'; ?>">
                    <h2 class="text-xl font-bold text-gray-800 mb-6">
                        <i class="fas fa-history mr-2"></i>Historique des saisies récentes
                    </h2>

                    <?php if (empty($activites)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-history text-gray-300 text-6xl mb-4"></i>
                            <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucune activité</h3>
                            <p class="text-gray-500">Aucune activité de saisie n'a été enregistrée récemment.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($activites as $activite): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div class="flex items-center">
                                    <div class="bg-indigo-100 rounded-full p-2 mr-4">
                                        <i class="fas fa-<?php echo $activite['action'] === 'Étudiant ajouté' ? 'user-plus' : 'edit'; ?> text-indigo-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-800"><?php echo htmlspecialchars($activite['action']); ?></p>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($activite['details']); ?></p>
                                    </div>
                                </div>
                                <div class="text-sm text-gray-500">
                                    <?php echo date('d/m/Y H:i', strtotime($activite['created_at'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
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

    <script>
        function showTab(tabName) {
            // Masquer tous les contenus
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            // Désactiver tous les onglets
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('border-indigo-500', 'text-indigo-600');
                button.classList.add('border-transparent', 'text-gray-500');
            });

            // Afficher l'onglet sélectionné
            document.getElementById('content-' + tabName).classList.remove('hidden');
            document.getElementById('tab-' + tabName).classList.add('border-indigo-500', 'text-indigo-600');
            document.getElementById('tab-' + tabName).classList.remove('border-transparent', 'text-gray-500');

            // Mettre à jour l'URL
            const url = new URL(window.location);
            if (tabName === 'import') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tabName);
            }
            window.history.pushState({}, '', url);
        }
    </script>
</body>
</html>