<?php
/**
 * Gestion de l'emploi du temps - Responsable de filière
 * Version simplifiée et fonctionnelle
 */

session_start();
require_once '../config/database.php';
require_once '../config/utils.php';
require_once '../config/email.php';

if (!isLoggedIn() || !hasRole('resp_filiere')) {
    redirectWithMessage('../shared/login.php', 'Accès non autorisé.', 'error');
}

$database = new Database();
$conn = $database->getConnection();
$user_id = $_SESSION['user_id'];

// Récupération de la filière gérée
$filiere_query = "SELECT f.*, d.nom as departement_nom
                 FROM filieres f
                 JOIN departements d ON f.departement_id = d.id
                 WHERE f.responsable_id = :user_id";
$filiere_stmt = $conn->prepare($filiere_query);
$filiere_stmt->bindParam(':user_id', $user_id);
$filiere_stmt->execute();
$filiere = $filiere_stmt->fetch(PDO::FETCH_ASSOC);

if (!$filiere) {
    redirectWithMessage('dashboard.php', 'Aucune filière assignée.', 'error');
}

$message = '';
$message_type = '';

// Jours et créneaux
$jours_semaine = [
    1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 
    4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'
];

$creneaux = [
    '08:00-09:30', '09:30-11:00', '11:00-12:30',
    '13:00-14:30', '14:30-16:00', '16:00-17:30'
];

// Traitement des actions POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = sanitize($_POST['action']);

    if ($action === 'add_cours') {
        $classe_id = (int)$_POST['classe_id'];
        $enseignement_id = isset($_POST['enseignement_id']) ? (int)$_POST['enseignement_id'] : 0;
        $jour_semaine = (int)$_POST['jour_semaine'];
        $creneau = sanitize($_POST['creneau']);
        $salle = sanitize($_POST['salle']);
        $annee_academique = sanitize($_POST['annee_academique']);
        $new_matiere = trim($_POST['new_matiere'] ?? '');
        $new_enseignant_id = isset($_POST['enseignant_id']) ? (int)$_POST['enseignant_id'] : 0;

        // Si aucune matière sélectionnée mais saisie libre fournie, créer un enseignement à la volée
        if (!$enseignement_id && $new_matiere !== '' && $new_enseignant_id > 0) {
            $insert_ens = $conn->prepare("INSERT INTO enseignements (enseignant_id, classe_id, matiere, volume_horaire)
                                          VALUES (:ens_id, :classe_id, :matiere, :vh)");
            $insert_ens->execute([
                ':ens_id' => $new_enseignant_id,
                ':classe_id' => $classe_id,
                ':matiere' => $new_matiere,
                ':vh' => 30
            ]);
            $enseignement_id = (int)$conn->lastInsertId();
        }

        // Vérifier que l'enseignement appartient à la classe et à la filière
        $check_query = "SELECT e.id, e.matiere, u.name as enseignant_nom, e.enseignant_id
                       FROM enseignements e
                       JOIN classes c ON e.classe_id = c.id
                       JOIN users u ON e.enseignant_id = u.id
                       WHERE e.id = :ens_id AND c.id = :classe_id AND c.filiere_id = :filiere_id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([
            ':ens_id' => $enseignement_id,
            ':classe_id' => $classe_id,
            ':filiere_id' => $filiere['id']
        ]);
        $enseignement = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$enseignement) {
            $message = "Enseignement non trouvé ou non autorisé. Ajoutez une matière ou sélectionnez un enseignant.";
            $message_type = "error";
        } else {
            // Vérifier les conflits
            $conflict_query = "SELECT COUNT(*) as count FROM emplois_du_temps e
                              JOIN enseignements ens ON e.enseignant_id = ens.enseignant_id
                              JOIN classes c ON e.classe_id = c.id
                              WHERE e.jour_semaine = :jour AND e.creneau_horaire = :creneau
                              AND e.annee_academique = :annee
                              AND (e.enseignant_id = (SELECT enseignant_id FROM enseignements WHERE id = :ens_id)
                                   OR e.classe_id = :classe_id
                                   OR e.salle = :salle)";
            $conflict_stmt = $conn->prepare($conflict_query);
            $conflict_stmt->execute([
                ':jour' => $jour_semaine,
                ':creneau' => $creneau,
                ':annee' => $annee_academique,
                ':ens_id' => $enseignement_id,
                ':classe_id' => $classe_id,
                ':salle' => $salle
            ]);
            
            if ($conflict_stmt->fetch()['count'] > 0) {
                $message = "Conflit d'horaire détecté (enseignant, classe ou salle déjà occupé).";
                $message_type = "error";
            } else {
                // Récupérer l'enseignant_id depuis enseignements
                $enseignant_id = (int)$enseignement['enseignant_id'];

                // Insérer le cours
                $insert_query = "INSERT INTO emplois_du_temps 
                                (classe_id, enseignant_id, matiere_nom, jour_semaine, creneau_horaire, salle, annee_academique, heure_debut, heure_fin)
                                VALUES (:classe_id, :enseignant_id, :matiere, :jour, :creneau, :salle, :annee, :debut, :fin)";
                $times = explode('-', $creneau);
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->execute([
                    ':classe_id' => $classe_id,
                    ':enseignant_id' => $enseignant_id,
                    ':matiere' => $enseignement['matiere'],
                    ':jour' => $jour_semaine,
                    ':creneau' => $creneau,
                    ':salle' => $salle,
                    ':annee' => $annee_academique,
                    ':debut' => $times[0],
                    ':fin' => $times[1]
                ]);

                // Notifications enseignant et étudiants de la classe
                $edt_id = (int)$conn->lastInsertId();
                $classe_nom = '';
                $classe_nom_stmt = $conn->prepare("SELECT nom_classe FROM classes WHERE id = :cid");
                $classe_nom_stmt->execute([':cid' => $classe_id]);
                $classe_nom = (string)$classe_nom_stmt->fetchColumn();

                $titre_notif = "Nouvel horaire ajouté";
                $texte_notif = "Cours " . $enseignement['matiere'] . " pour la classe " . $classe_nom . " le " . ($jours_semaine[$jour_semaine] ?? $jour_semaine) . " " . $creneau . (trim($salle) ? " salle " . $salle : "") . " (" . $annee_academique . ")";

                $notif_stmt = $conn->prepare("INSERT INTO notifications (user_id, titre, message, type, lien) VALUES (:uid, :titre, :msg, :type, :lien)");
                // Notifier l'enseignant concerné
                $notif_stmt->execute([
                    ':uid' => $enseignant_id,
                    ':titre' => $titre_notif,
                    ':msg' => $texte_notif,
                    ':type' => 'edt',
                    ':lien' => '../enseignant/emploi_du_temps.php'
                ]);

                // Envoyer email à l'enseignant
                $prof_info = $conn->prepare("SELECT name, email FROM users WHERE id = :id");
                $prof_info->execute([':id' => $enseignant_id]);
                $prof = $prof_info->fetch(PDO::FETCH_ASSOC);
                if ($prof && !empty($prof['email'])) {
                    $coursDetails = [
                        'matiere' => $enseignement['matiere'],
                        'classe' => $classe_nom,
                        'jour' => $jours_semaine[$jour_semaine] ?? $jour_semaine,
                        'creneau' => $creneau,
                        'salle' => $salle,
                        'annee' => $annee_academique
                    ];
                    sendEdtNotification($prof['email'], $prof['name'], $coursDetails);
                }

                // Notifier les étudiants inscrits de la classe
                $eleves_stmt = $conn->prepare("SELECT u.id, u.name, u.email FROM inscriptions i JOIN users u ON i.user_id = u.id WHERE i.classe_id = :cid AND i.statut IN ('inscrit', 'reinscrit')");
                $eleves_stmt->execute([':cid' => $classe_id]);
                $eleves = $eleves_stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($eleves as $etu) {
                    $notif_stmt->execute([
                        ':uid' => $etu['id'],
                        ':titre' => $titre_notif,
                        ':msg' => $texte_notif,
                        ':type' => 'edt',
                        ':lien' => '../etudiant/emploi_du_temps.php'
                    ]);
                    
                    // Envoyer email à l'étudiant
                    if (!empty($etu['email'])) {
                        $coursDetails = [
                            'matiere' => $enseignement['matiere'],
                            'classe' => $classe_nom,
                            'jour' => $jours_semaine[$jour_semaine] ?? $jour_semaine,
                            'creneau' => $creneau,
                            'salle' => $salle,
                            'annee' => $annee_academique
                        ];
                        sendEdtNotification($etu['email'], $etu['name'], $coursDetails);
                    }
                }
                
                $message = "Cours ajouté avec succès.";
                $message_type = "success";
            }
        }
    }

    if ($action === 'delete_cours') {
        $cours_id = (int)$_POST['cours_id'];
        
        // Vérifier que le cours appartient à la filière
        $check_query = "SELECT e.id FROM emplois_du_temps e
                       JOIN classes c ON e.classe_id = c.id
                       WHERE e.id = :id AND c.filiere_id = :filiere_id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->execute([':id' => $cours_id, ':filiere_id' => $filiere['id']]);
        
        if ($check_stmt->fetch()) {
            $delete_query = "DELETE FROM emplois_du_temps WHERE id = :id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->execute([':id' => $cours_id]);
            
            $message = "Cours supprimé avec succès.";
            $message_type = "success";
        } else {
            $message = "Cours non trouvé ou non autorisé.";
            $message_type = "error";
        }
    }
}

// Récupération des classes et année académique
$classe_filter = isset($_GET['classe_id']) ? (int)$_GET['classe_id'] : 0;
$annee_filter = isset($_GET['annee']) ? sanitize($_GET['annee']) : date('Y') . '/' . (date('Y') + 1);

$classes_query = "SELECT id, nom_classe, niveau FROM classes WHERE filiere_id = :filiere_id ORDER BY niveau, nom_classe";
$classes_stmt = $conn->prepare($classes_query);
$classes_stmt->execute([':filiere_id' => $filiere['id']]);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération des enseignements de la classe sélectionnée
$enseignements = [];
if ($classe_filter) {
    $ens_query = "SELECT e.id, e.matiere, u.name as enseignant_nom
                  FROM enseignements e
                  JOIN users u ON e.enseignant_id = u.id
                  WHERE e.classe_id = :classe_id
                  ORDER BY e.matiere";
    $ens_stmt = $conn->prepare($ens_query);
    $ens_stmt->execute([':classe_id' => $classe_filter]);
    $enseignements = $ens_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Liste des enseignants (pour créer un enseignement à la volée)
$enseignants_filiere = [];
$ens_filiere_query = "SELECT id, name FROM users WHERE role = 'enseignant' ORDER BY name";
$ens_filiere_stmt = $conn->prepare($ens_filiere_query);
$ens_filiere_stmt->execute();
$enseignants_filiere = $ens_filiere_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupération de l'emploi du temps
$emploi_du_temps = [];
if ($classe_filter) {
    $edt_query = "SELECT e.*, c.nom_classe, u.name as enseignant_nom
                  FROM emplois_du_temps e
                  JOIN classes c ON e.classe_id = c.id
                  JOIN users u ON e.enseignant_id = u.id
                  WHERE e.classe_id = :classe_id AND e.annee_academique = :annee
                  ORDER BY e.jour_semaine, e.creneau_horaire";
    $edt_stmt = $conn->prepare($edt_query);
    $edt_stmt->execute([':classe_id' => $classe_filter, ':annee' => $annee_filter]);
    $emploi_du_temps = $edt_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emploi du Temps - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <header class="bg-orange-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-calendar text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Emploi du Temps - <?php echo htmlspecialchars($filiere['nom']); ?></h1>
                </div>
                <a href="../shared/logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm">
                    <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                </a>
            </div>
        </div>
    </header>

    <nav class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex space-x-8 py-3">
                <a href="dashboard.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-tachometer-alt mr-1"></i>Dashboard
                </a>
                <a href="classes.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-users mr-1"></i>Classes
                </a>
                <a href="enseignants.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>Enseignants
                </a>
                <a href="notes.php" class="text-gray-600 hover:text-orange-600">
                    <i class="fas fa-chart-bar mr-1"></i>Notes
                </a>
                <a href="emploi_du_temps.php" class="text-orange-600 border-b-2 border-orange-600 pb-2">
                    <i class="fas fa-calendar mr-1"></i>Emploi du temps
                </a>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <?php if ($message): ?>
            <div class="mb-8 bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-100 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-400 text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-700 px-4 py-3 rounded">
                <p><?php echo htmlspecialchars($message); ?></p>
            </div>
        <?php endif; ?>

        <!-- Filtres -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-filter mr-2"></i>Sélectionner une classe
            </h2>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Classe</label>
                    <select name="classe_id" onchange="this.form.submit()" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                        <option value="0">Sélectionner une classe</option>
                        <?php foreach ($classes as $classe): ?>
                            <option value="<?php echo $classe['id']; ?>" <?php echo $classe_filter == $classe['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($classe['nom_classe']); ?> (<?php echo htmlspecialchars($classe['niveau']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Année académique</label>
                    <input type="text" name="annee" value="<?php echo htmlspecialchars($annee_filter); ?>" 
                           placeholder="Ex: 2025/2026"
                           class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                </div>

                <?php if ($classe_filter): ?>
                <div class="flex items-end">
                    <button type="button" onclick="document.getElementById('addCoursModal').classList.remove('hidden')"
                            class="w-full bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md">
                        <i class="fas fa-plus mr-2"></i>Ajouter un cours
                    </button>
                </div>
                <?php endif; ?>
            </form>
        </div>

        <!-- Emploi du temps -->
        <?php if ($classe_filter): ?>
        <div class="bg-white rounded-lg shadow-md p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-calendar-alt mr-2"></i>Emploi du temps
            </h2>

            <?php if (empty($emploi_du_temps)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-calendar text-gray-300 text-6xl mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun cours planifié</h3>
                    <p class="text-gray-500">Ajoutez des cours pour créer l'emploi du temps.</p>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Jour</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Horaire</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Matière</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Enseignant</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Salle</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($emploi_du_temps as $cours): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo $jours_semaine[$cours['jour_semaine']]; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($cours['creneau_horaire']); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($cours['matiere_nom']); ?>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($cours['enseignant_nom']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($cours['salle']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <button onclick="deleteCours(<?php echo $cours['id']; ?>)"
                                            class="text-red-600 hover:text-red-900 text-sm">
                                        <i class="fas fa-trash mr-1"></i>Supprimer
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow-md p-12 text-center">
            <i class="fas fa-info-circle text-gray-300 text-6xl mb-4"></i>
            <h3 class="text-xl font-semibold text-gray-600 mb-2">Sélectionnez une classe</h3>
            <p class="text-gray-500">Choisissez une classe pour afficher et gérer son emploi du temps.</p>
        </div>
        <?php endif; ?>
    </main>

    <!-- Modal d'ajout -->
    <div id="addCoursModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-plus mr-2"></i>Ajouter un cours
                        </h3>
                        <button onclick="closeAddModal()" class="text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times text-xl"></i>
                        </button>
                    </div>

                    <form method="POST">
                        <input type="hidden" name="action" value="add_cours">
                        <input type="hidden" name="classe_id" value="<?php echo $classe_filter; ?>">
                        <input type="hidden" name="annee_academique" value="<?php echo htmlspecialchars($annee_filter); ?>">

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Matière (existante)</label>
                            <select name="enseignement_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                                <option value="">Sélectionner une matière existante</option>
                                <?php foreach ($enseignements as $ens): ?>
                                    <option value="<?php echo $ens['id']; ?>">
                                        <?php echo htmlspecialchars($ens['matiere']); ?> - <?php echo htmlspecialchars($ens['enseignant_nom']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Ou créez une nouvelle matière ci-dessous.</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Nouvelle matière (optionnel)</label>
                            <input type="text" name="new_matiere" placeholder="Ex: Mathématiques" 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Enseignant (pour nouvelle matière)</label>
                            <select name="enseignant_id"
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                                <option value="">Sélectionner un enseignant</option>
                                <?php foreach ($enseignants_filiere as $ensf): ?>
                                    <option value="<?php echo $ensf['id']; ?>"><?php echo htmlspecialchars($ensf['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">Obligatoire seulement si vous créez une nouvelle matière.</p>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Jour *</label>
                            <select name="jour_semaine" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                                <?php foreach ($jours_semaine as $num => $nom): ?>
                                    <option value="<?php echo $num; ?>"><?php echo $nom; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-4">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Créneau horaire *</label>
                            <select name="creneau" required
                                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                                <?php foreach ($creneaux as $creneau): ?>
                                    <option value="<?php echo $creneau; ?>"><?php echo $creneau; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 mb-2">Salle</label>
                            <input type="text" name="salle" placeholder="Ex: A101"
                                   class="w-full px-3 py-2 border border-gray-300 rounded-md focus:ring-orange-500 focus:border-orange-500">
                        </div>

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeAddModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md">
                                <i class="fas fa-save mr-2"></i>Enregistrer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal de suppression -->
    <div id="deleteCoursModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
        <div class="flex items-center justify-center min-h-screen p-4">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                <div class="p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Confirmer la suppression
                    </h3>
                    <p class="text-gray-600 mb-6">Êtes-vous sûr de vouloir supprimer ce cours ?</p>

                    <form method="POST">
                        <input type="hidden" name="action" value="delete_cours">
                        <input type="hidden" name="cours_id" id="delete_cours_id">

                        <div class="flex justify-end space-x-3">
                            <button type="button" onclick="closeDeleteModal()"
                                    class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-medium py-2 px-4 rounded-md">
                                Annuler
                            </button>
                            <button type="submit"
                                    class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-md">
                                <i class="fas fa-trash mr-2"></i>Supprimer
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script>
        function closeAddModal() {
            document.getElementById('addCoursModal').classList.add('hidden');
        }

        function closeDeleteModal() {
            document.getElementById('deleteCoursModal').classList.add('hidden');
        }

        function deleteCours(id) {
            document.getElementById('delete_cours_id').value = id;
            document.getElementById('deleteCoursModal').classList.remove('hidden');
        }

        document.getElementById('addCoursModal').addEventListener('click', function(e) {
            if (e.target === this) closeAddModal();
        });

        document.getElementById('deleteCoursModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });
    </script>
</body>
</html>
