<?php
/**
 * Dashboard de l'étudiant
 * Affiche les informations personnelles, emploi du temps, notes, etc.
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
                     WHERE i.user_id = :user_id AND i.statut = 'inscrit'
                     ORDER BY i.annee_academique DESC LIMIT 1";
$inscription_stmt = $conn->prepare($inscription_query);
$inscription_stmt->bindParam(':user_id', $user_id);
$inscription_stmt->execute();
$inscription = $inscription_stmt->fetch(PDO::FETCH_ASSOC);

// Récupération de l'emploi du temps
$edt_query = "SELECT e.* FROM emplois_du_temps e
              JOIN classes c ON e.classe_id = c.id
              JOIN inscriptions i ON c.id = i.classe_id
              WHERE i.user_id = :user_id AND i.statut = 'inscrit'
              ORDER BY e.jour_semaine, e.creneau_horaire";
$edt_stmt = $conn->prepare($edt_query);
$edt_stmt->bindParam(':user_id', $user_id);
$edt_stmt->execute();
$emploi_du_temps = $edt_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des notes récentes
$notes_query = "SELECT n.* FROM notes n
                JOIN enseignements e ON n.enseignement_id = e.id
                WHERE n.etudiant_id = :user_id
                ORDER BY n.date_saisie DESC LIMIT 10";
$notes_stmt = $conn->prepare($notes_query);
$notes_stmt->bindParam(':user_id', $user_id);
$notes_stmt->execute();
$notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des documents demandés (documents classiques)
$docs_query = "SELECT * FROM documents WHERE user_id = :user_id ORDER BY date_creation DESC LIMIT 5";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':user_id', $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des documents d'inscription et pièces complémentaires
$insc_query = "SELECT * FROM documents_inscription WHERE user_id = :user_id ORDER BY date_upload DESC LIMIT 5";
$insc_stmt = $conn->prepare($insc_query);
$insc_stmt->bindParam(':user_id', $user_id);
$insc_stmt->execute();
$documents_inscription = $insc_stmt->fetchAll(PDO::FETCH_ASSOC);

$upload_errors = [];
$upload_messages = [];

// Upload direct depuis le dashboard (mêmes règles que la page Mes Documents)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_document_dashboard') {
    $titre = sanitize($_POST['titre'] ?? 'Document demandé');
    $file = $_FILES['document_admin'] ?? null;

    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
        $upload_errors[] = "Veuillez sélectionner un fichier.";
    } else {
        $accepted_types = ['application/pdf', 'image/jpeg', 'image/png'];
        $max_size = 5 * 1024 * 1024; // 5MB

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $accepted_types)) {
            $upload_errors[] = "Type de fichier non accepté (PDF, JPG ou PNG seulement).";
        }

        if ($file['size'] > $max_size) {
            $upload_errors[] = "Fichier trop volumineux (max 5MB).";
        }

        if (empty($upload_errors)) {
            $user_dir = __DIR__ . '/../documents/inscriptions/user_' . $user_id;
            if (!is_dir($user_dir)) {
                mkdir($user_dir, 0777, true);
            }

            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $safe_filename = 'doc_admin_' . time() . '.' . $extension;
            $filepath = $user_dir . '/' . $safe_filename;

            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $insert = "INSERT INTO documents_inscription (user_id, type_document, nom_fichier, chemin_fichier, type_mime, taille_fichier, statut, commentaire_validation)
                           VALUES (:user_id, 'autre', :nom_fichier, :chemin_fichier, :type_mime, :taille_fichier, 'soumis', :commentaire)";
                $stmt_insert = $conn->prepare($insert);
                $relative_path = str_replace(__DIR__ . '/../', '', $filepath);
                $nom_fichier = $titre ?: $file['name'];
                $taille_fichier = (int) $file['size'];

                $stmt_insert->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $stmt_insert->bindParam(':nom_fichier', $nom_fichier, PDO::PARAM_STR);
                $stmt_insert->bindParam(':chemin_fichier', $relative_path, PDO::PARAM_STR);
                $stmt_insert->bindParam(':type_mime', $mime_type, PDO::PARAM_STR);
                $stmt_insert->bindParam(':taille_fichier', $taille_fichier, PDO::PARAM_INT);
                $stmt_insert->bindValue(':commentaire', null, PDO::PARAM_NULL);

                if ($stmt_insert->execute()) {
                    $upload_messages[] = ['type' => 'success', 'text' => 'Document envoyé. Il est en attente de validation.'];
                    $insc_stmt->execute();
                    $documents_inscription = $insc_stmt->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $upload_errors[] = "Erreur lors de l'enregistrement du document.";
                }
            } else {
                $upload_errors[] = "Erreur lors du transfert du fichier.";
            }
        }
    }
}

// Récupération des notifications
$notif_query = "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY date_envoi DESC LIMIT 5";
$notif_stmt = $conn->prepare($notif_query);
$notif_stmt->bindParam(':user_id', $user_id);
$notif_stmt->execute();
$notifications = $notif_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Étudiant - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div id="toast-container" class="fixed top-4 right-4 z-50 space-y-3"></div>
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
                <a href="dashboard.php" class="text-blue-600 border-b-2 border-blue-600 pb-2">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="profil.php" class="text-gray-600 hover:text-blue-600">
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
                <a href="feedback.php" class="text-gray-600 hover:text-blue-600">
                    <i class="fas fa-comments mr-1"></i>Feedback
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Informations personnelles -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-blue-100 rounded-full p-3">
                        <i class="fas fa-user text-blue-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Informations personnelles</h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($user['email']); ?></p>
                        <p class="text-gray-600"><?php echo htmlspecialchars($user['phone']); ?></p>
                    </div>
                </div>
            </div>

            <?php if ($inscription): ?>
            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-green-100 rounded-full p-3">
                        <i class="fas fa-school text-green-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Classe actuelle</h3>
                        <p class="text-gray-600"><?php echo htmlspecialchars($inscription['niveau']); ?> - <?php echo htmlspecialchars($inscription['filiere_nom']); ?></p>
                        <p class="text-gray-600"><?php echo htmlspecialchars($inscription['departement_nom']); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow-md p-6">
                <div class="flex items-center">
                    <div class="bg-yellow-100 rounded-full p-3">
                        <i class="fas fa-bell text-yellow-600 text-xl"></i>
                    </div>
                    <div class="ml-4">
                        <h3 class="text-lg font-semibold text-gray-800">Notifications</h3>
                        <p class="text-gray-600"><?php echo count($notifications); ?> notification(s)</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload rapide documents d'inscription -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4 mb-4">
                <div>
                    <h2 class="text-xl font-bold text-gray-800">
                        <i class="fas fa-upload mr-2"></i>Envoyer un document demandé
                    </h2>
                    <p class="text-sm text-gray-600">PDF, JPG ou PNG (max 5MB). Vous pouvez aussi passer par la page Mes Documents.</p>
                </div>
                <a href="documents.php#upload_form" class="inline-flex items-center text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                    <i class="fas fa-arrow-right mr-2"></i>Ouvrir la page Mes Documents
                </a>
            </div>

            <?php if (!empty($upload_errors)): ?>
                <div class="bg-red-50 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <p class="font-semibold text-sm mb-1">Erreurs :</p>
                    <ul class="list-disc ml-4 text-sm">
                        <?php foreach ($upload_errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($upload_messages)): ?>
                <?php foreach ($upload_messages as $msg): ?>
                    <div class="bg-green-50 border-l-4 border-green-500 text-green-700 p-4 mb-4 text-sm">
                        <?php echo htmlspecialchars($msg['text']); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <form method="POST" enctype="multipart/form-data" class="space-y-4">
                    <input type="hidden" name="action" value="upload_document_dashboard">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Titre du document (optionnel)</label>
                        <input type="text" name="titre" class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500" placeholder="Ex: Pièce complémentaire">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Fichier</label>
                        <input type="file" name="document_admin" accept=".pdf,.jpg,.jpeg,.png" required class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md text-sm font-medium">
                            <i class="fas fa-paper-plane mr-2"></i>Envoyer
                        </button>
                    </div>
                </form>

                <div class="bg-gray-50 border border-dashed border-gray-200 rounded-md p-4">
                    <h3 class="text-sm font-semibold text-gray-800 mb-3">Derniers envois</h3>
                    <?php if (empty($documents_inscription)): ?>
                        <p class="text-sm text-gray-500">Aucun document soumis pour le moment.</p>
                    <?php else: ?>
                        <div class="space-y-3 max-h-48 overflow-y-auto pr-1">
                            <?php foreach ($documents_inscription as $doc): ?>
                                <?php
                                    $status_label = $doc['statut'] === 'valide' ? 'Validé' : ($doc['statut'] === 'rejete' ? 'Rejeté' : 'En attente');
                                    $status_color = $doc['statut'] === 'valide' ? 'bg-green-100 text-green-800' : ($doc['statut'] === 'rejete' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800');
                                ?>
                                <div class="p-3 bg-white rounded shadow-sm border border-gray-100 flex items-start justify-between">
                                    <div class="text-sm text-gray-800">
                                        <p class="font-medium"><?php echo htmlspecialchars($doc['nom_fichier']); ?></p>
                                        <p class="text-xs text-gray-500">Envoyé le <?php echo htmlspecialchars(date('d/m/Y H:i', strtotime($doc['date_upload']))); ?></p>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_color; ?>"><?php echo $status_label; ?></span>
                                </div>
                            <?php endforeach; ?>
                            </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Emploi du temps de la semaine -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-calendar-week mr-2"></i>Emploi du temps cette semaine
            </h2>
            <?php if (empty($emploi_du_temps)): ?>
                <p class="text-gray-600">Aucun emploi du temps disponible pour le moment.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full table-auto">
                        <thead>
                            <tr class="bg-gray-50">
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Jour</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Heure</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Matière</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Enseignant</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Salle</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($emploi_du_temps as $cours): ?>
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['jour']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['heure_debut'] . ' - ' . $cours['heure_fin']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['matiere']); ?></td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                    <?php
                                    $enseignant_query = "SELECT name FROM users WHERE id = :id";
                                    $enseignant_stmt = $conn->prepare($enseignant_query);
                                    $enseignant_stmt->bindParam(':id', $cours['enseignant_id']);
                                    $enseignant_stmt->execute();
                                    $enseignant = $enseignant_stmt->fetch(PDO::FETCH_ASSOC);
                                    echo htmlspecialchars($enseignant['name'] ?? 'N/A');
                                    ?>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['salle']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Notes récentes -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-chart-line mr-2"></i>Notes récentes
            </h2>
            <?php if (empty($notes)): ?>
                <p class="text-gray-600">Aucune note disponible pour le moment.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($notes as $note): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($note['matiere']); ?></h4>
                            <p class="text-sm text-gray-600">Type: <?php echo htmlspecialchars($note['type_evaluation']); ?> | Date: <?php echo htmlspecialchars($note['date_saisie']); ?></p>
                        </div>
                        <div class="text-right">
                            <span class="text-2xl font-bold text-blue-600"><?php echo htmlspecialchars($note['note']); ?>/20</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Documents demandés -->
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4">
                <i class="fas fa-file-alt mr-2"></i>Demandes de documents récentes
            </h2>
            <?php if (empty($documents)): ?>
                <p class="text-gray-600">Aucune demande de document pour le moment.</p>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($documents as $doc): ?>
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                        <div>
                            <h4 class="font-semibold text-gray-800"><?php echo htmlspecialchars($doc['type_document']); ?></h4>
                            <p class="text-sm text-gray-600">Statut: <?php echo htmlspecialchars($doc['statut']); ?> | Date: <?php echo htmlspecialchars($doc['date_creation']); ?></p>
                        </div>
                        <div class="text-right">
                            <?php if ($doc['statut'] == 'valide'): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Validé</span>
                            <?php elseif ($doc['statut'] == 'en_attente'): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">En attente</span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Rejeté</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
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
        function showToast(message, type = 'info') {
            const container = document.getElementById('toast-container');
            if (!container) return;

            const baseClasses = 'px-4 py-3 rounded shadow-lg text-sm flex items-start space-x-2';
            const variants = {
                success: 'bg-green-50 border border-green-200 text-green-800',
                error: 'bg-red-50 border border-red-200 text-red-800',
                info: 'bg-blue-50 border border-blue-200 text-blue-800'
            };

            const toast = document.createElement('div');
            toast.className = `${baseClasses} ${variants[type] || variants.info}`;

            const icon = document.createElement('i');
            icon.className = type === 'success' ? 'fas fa-check-circle mt-0.5' : (type === 'error' ? 'fas fa-exclamation-circle mt-0.5' : 'fas fa-info-circle mt-0.5');

            const text = document.createElement('span');
            text.textContent = message;

            toast.appendChild(icon);
            toast.appendChild(text);
            container.appendChild(toast);

            setTimeout(() => {
                toast.classList.add('opacity-0', 'transition', 'duration-300');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        (function bootDashboardToasts() {
            const queued = <?php
                $toastQueue = [];
                foreach ($upload_errors as $err) {
                    $toastQueue[] = ['type' => 'error', 'text' => $err];
                }
                foreach ($upload_messages as $msg) {
                    $toastQueue[] = ['type' => 'success', 'text' => $msg['text']];
                }
                echo json_encode($toastQueue);
            ?>;

            queued.forEach(item => showToast(item.text, item.type));
        })();
    </script>
</body>
</html>