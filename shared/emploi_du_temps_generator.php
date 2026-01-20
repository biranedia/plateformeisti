<?php
/**
 * Générateur d'emploi du temps - ISTI Platform
 * Système automatisé de génération d'emplois du temps
 */

// Démarrage de la session
session_start();

// Inclusion des fichiers de configuration
require_once '../config/database.php';
require_once '../config/utils.php';

// Vérification de l'authentification
if (!isLoggedIn()) {
    redirectWithMessage('login.php', 'Vous devez être connecté pour accéder à cette page.', 'error');
}

// Vérification des permissions (seulement admin_general et responsable_filiere peuvent générer les emplois du temps)
$user_role = $_SESSION['user_role'] ?? '';
if (!in_array($user_role, ['admin_general', 'responsable_filiere'])) {
    redirectWithMessage('dashboard.php', 'Vous n\'avez pas les permissions pour accéder à cette page.', 'error');
}

// Initialisation de la connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Variables
$errors = [];
$success = '';
$generated_schedule = null;
$conflicts = [];
$stats = [];

// Paramètres de génération
$selected_filiere = (int)($_POST['filiere'] ?? $_GET['filiere'] ?? 0);
$selected_annee = (int)($_POST['annee_academique'] ?? $_GET['annee_academique'] ?? 0);
$selected_semestre = sanitize($_POST['semestre'] ?? $_GET['semestre'] ?? 'all');

// Récupération des filières pour le responsable_filiere
$filieres = [];
if ($user_role === 'responsable_filiere') {
    try {
        $query = "SELECT f.* FROM filieres f
                  JOIN responsable_filiere rf ON f.id = rf.filiere_id
                  WHERE rf.user_id = :user_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->execute();
        $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $errors[] = 'Erreur lors du chargement des filières.';
    }
} else {
    // Admin voit toutes les filières
    try {
        $query = "SELECT * FROM filieres ORDER BY nom";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $filieres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $errors[] = 'Erreur lors du chargement des filières.';
    }
}

// Récupération des années académiques
$annees_academiques = [];
try {
    $query = "SELECT * FROM annees_academiques ORDER BY annee_debut DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $annees_academiques = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $errors[] = 'Erreur lors du chargement des années académiques.';
}

// Traitement de la génération d'emploi du temps
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_schedule'])) {
    if (empty($selected_filiere) || empty($selected_annee)) {
        $errors[] = 'Veuillez sélectionner une filière et une année académique.';
    } else {
        try {
            // Génération de l'emploi du temps
            $result = generateSchedule($conn, $selected_filiere, $selected_annee, $selected_semestre);

            if ($result['success']) {
                $success = $result['message'];
                $generated_schedule = $result['schedule'];
                $stats = $result['stats'];

                // Ajout dans le journal d'audit
                addAuditLog($conn, $_SESSION['user_id'], "Génération d'emploi du temps pour filière $selected_filiere, année $selected_annee", "emploi_du_temps");
            } else {
                $errors = array_merge($errors, $result['errors']);
                $conflicts = $result['conflicts'] ?? [];
            }
        } catch (Exception $e) {
            $errors[] = 'Erreur lors de la génération: ' . $e->getMessage();
        }
    }
}

// Traitement de la sauvegarde de l'emploi du temps
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_schedule'])) {
    if (empty($generated_schedule)) {
        $errors[] = 'Aucun emploi du temps généré à sauvegarder.';
    } else {
        try {
            $saved_count = 0;
            $conn->beginTransaction();

            // Supprimer les anciens emplois du temps pour cette filière et année
            $delete_query = "DELETE FROM emploi_du_temps WHERE filiere_id = :filiere_id AND annee_academique_id = :annee_id";
            if ($selected_semestre !== 'all') {
                $delete_query .= " AND semestre = :semestre";
            }
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':filiere_id', $selected_filiere);
            $delete_stmt->bindParam(':annee_id', $selected_annee);
            if ($selected_semestre !== 'all') {
                $delete_stmt->bindParam(':semestre', $selected_semestre);
            }
            $delete_stmt->execute();

            // Insérer les nouveaux emplois du temps
            $insert_query = "INSERT INTO emploi_du_temps (filiere_id, classe_id, matiere_id, enseignant_id, salle, jour_semaine, heure_debut, heure_fin, semestre, annee_academique_id, created_by)
                           VALUES (:filiere_id, :classe_id, :matiere_id, :enseignant_id, :salle, :jour_semaine, :heure_debut, :heure_fin, :semestre, :annee_academique_id, :created_by)";

            $insert_stmt = $conn->prepare($insert_query);

            foreach ($generated_schedule as $entry) {
                $insert_stmt->bindParam(':filiere_id', $entry['filiere_id']);
                $insert_stmt->bindParam(':classe_id', $entry['classe_id']);
                $insert_stmt->bindParam(':matiere_id', $entry['matiere_id']);
                $insert_stmt->bindParam(':enseignant_id', $entry['enseignant_id']);
                $insert_stmt->bindParam(':salle', $entry['salle']);
                $insert_stmt->bindParam(':jour_semaine', $entry['jour_semaine']);
                $insert_stmt->bindParam(':heure_debut', $entry['heure_debut']);
                $insert_stmt->bindParam(':heure_fin', $entry['heure_fin']);
                $insert_stmt->bindParam(':semestre', $entry['semestre']);
                $insert_stmt->bindParam(':annee_academique_id', $entry['annee_academique_id']);
                $insert_stmt->bindParam(':created_by', $_SESSION['user_id']);
                $insert_stmt->execute();
                $saved_count++;
            }

            $conn->commit();
            $success = "Emploi du temps sauvegardé avec succès ($saved_count séances créées).";

            // Ajout dans le journal d'audit
            addAuditLog($conn, $_SESSION['user_id'], "Sauvegarde d'emploi du temps: $saved_count séances pour filière $selected_filiere", "emploi_du_temps");

            // Réinitialiser les variables après sauvegarde
            $generated_schedule = null;
            $conflicts = [];
            $stats = [];

        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = 'Erreur lors de la sauvegarde: ' . $e->getMessage();
        }
    }
}

// Fonction de génération d'emploi du temps
function generateSchedule($conn, $filiere_id, $annee_id, $semestre = 'all') {
    $result = [
        'success' => false,
        'message' => '',
        'schedule' => [],
        'conflicts' => [],
        'errors' => [],
        'stats' => []
    ];

    try {
        // Récupération des données nécessaires
        $classes = getClassesByFiliere($conn, $filiere_id);
        $matieres = getMatieresByFiliere($conn, $filiere_id, $semestre);
        $enseignants = getEnseignantsByFiliere($conn, $filiere_id);
        $salles = getAvailableSalles($conn);

        if (empty($classes)) {
            $result['errors'][] = 'Aucune classe trouvée pour cette filière.';
            return $result;
        }

        if (empty($matieres)) {
            $result['errors'][] = 'Aucune matière trouvée pour cette filière.';
            return $result;
        }

        // Configuration des créneaux horaires
        $time_slots = [
            ['debut' => '08:00', 'fin' => '09:30'],
            ['debut' => '09:45', 'fin' => '11:15'],
            ['debut' => '11:30', 'fin' => '13:00'],
            ['debut' => '14:00', 'fin' => '15:30'],
            ['debut' => '15:45', 'fin' => '17:15'],
            ['debut' => '17:30', 'fin' => '19:00']
        ];

        $jours_semaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

        $schedule = [];
        $conflicts = [];
        $stats = [
            'total_classes' => count($classes),
            'total_matieres' => count($matieres),
            'total_enseignants' => count($enseignants),
            'total_salles' => count($salles),
            'generated_sessions' => 0
        ];

        // Algorithme de génération simple
        foreach ($classes as $classe) {
            $classe_schedule = [];

            // Pour chaque matière de la classe
            foreach ($matieres as $matiere) {
                if ($matiere['classe_id'] != $classe['id']) continue;

                $heures_requises = $matiere['heures_semaine'] ?? 2; // Par défaut 2h par semaine
                $seances_creees = 0;

                // Créer des séances pour cette matière
                for ($i = 0; $i < $heures_requises && $seances_creees < $heures_requises; $i++) {
                    $placed = false;

                    // Essayer de placer la séance
                    foreach ($jours_semaine as $jour) {
                        if ($placed) break;

                        foreach ($time_slots as $slot) {
                            if ($placed) break;

                            // Vérifier les conflits
                            $conflict = checkConflicts($schedule, $classe['id'], $matiere['enseignant_id'], $salles[array_rand($salles)]['id'], $jour, $slot['debut'], $slot['fin']);

                            if (!$conflict) {
                                // Ajouter la séance
                                $entry = [
                                    'filiere_id' => $filiere_id,
                                    'classe_id' => $classe['id'],
                                    'matiere_id' => $matiere['id'],
                                    'enseignant_id' => $matiere['enseignant_id'],
                                    'salle' => $salles[array_rand($salles)]['nom'],
                                    'jour_semaine' => $jour,
                                    'heure_debut' => $slot['debut'],
                                    'heure_fin' => $slot['fin'],
                                    'semestre' => $matiere['semestre'] ?? 'S1',
                                    'annee_academique_id' => $annee_id
                                ];

                                $schedule[] = $entry;
                                $seances_creees++;
                                $stats['generated_sessions']++;
                                $placed = true;
                            } else {
                                $conflicts[] = $conflict;
                            }
                        }
                    }

                    // Si on n'a pas pu placer après avoir essayé tous les créneaux, on passe à la matière suivante
                    if (!$placed) {
                        $result['errors'][] = "Impossible de placer toutes les séances pour {$matiere['nom']} (Classe: {$classe['nom']})";
                        break;
                    }
                }
            }
        }

        $result['success'] = true;
        $result['message'] = 'Emploi du temps généré avec succès.';
        $result['schedule'] = $schedule;
        $result['conflicts'] = $conflicts;
        $result['stats'] = $stats;

    } catch (Exception $e) {
        $result['errors'][] = 'Erreur lors de la génération: ' . $e->getMessage();
    }

    return $result;
}

// Fonctions auxiliaires
function getClassesByFiliere($conn, $filiere_id) {
    $query = "SELECT c.* FROM classes c WHERE c.filiere_id = :filiere_id ORDER BY c.nom";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':filiere_id', $filiere_id);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getMatieresByFiliere($conn, $filiere_id, $semestre = 'all') {
    $query = "SELECT m.*, cm.classe_id, cm.enseignant_id, cm.heures_semaine, cm.semestre
              FROM matieres m
              JOIN classe_matiere cm ON m.id = cm.matiere_id
              JOIN classes c ON cm.classe_id = c.id
              WHERE c.filiere_id = :filiere_id";

    if ($semestre !== 'all') {
        $query .= " AND cm.semestre = :semestre";
    }

    $query .= " ORDER BY m.nom";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':filiere_id', $filiere_id);
    if ($semestre !== 'all') {
        $stmt->bindParam(':semestre', $semestre);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getEnseignantsByFiliere($conn, $filiere_id) {
    $query = "SELECT DISTINCT u.id, u.nom, u.prenom
              FROM users u
              JOIN classe_matiere cm ON u.id = cm.enseignant_id
              JOIN classes c ON cm.classe_id = c.id
              WHERE c.filiere_id = :filiere_id AND u.role = 'enseignant'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':filiere_id', $filiere_id);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getAvailableSalles($conn) {
    $query = "SELECT * FROM salles WHERE disponible = 1 ORDER BY nom";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function checkConflicts($schedule, $classe_id, $enseignant_id, $salle_id, $jour, $heure_debut, $heure_fin) {
    foreach ($schedule as $entry) {
        if ($entry['jour_semaine'] === $jour &&
            (($entry['heure_debut'] < $heure_fin && $entry['heure_fin'] > $heure_debut))) {

            // Conflit de classe
            if ($entry['classe_id'] == $classe_id) {
                return [
                    'type' => 'Classe occupée',
                    'message' => "La classe est déjà occupée le $jour de $heure_debut à $heure_fin",
                    'entry' => $entry
                ];
            }

            // Conflit d'enseignant
            if ($entry['enseignant_id'] == $enseignant_id) {
                return [
                    'type' => 'Enseignant occupé',
                    'message' => "L'enseignant est déjà occupé le $jour de $heure_debut à $heure_fin",
                    'entry' => $entry
                ];
            }

            // Conflit de salle
            if ($entry['salle'] == $salle_id) {
                return [
                    'type' => 'Salle occupée',
                    'message' => "La salle est déjà occupée le $jour de $heure_debut à $heure_fin",
                    'entry' => $entry
                ];
            }
        }
    }
    return false;
}

// Fonction pour formater l'affichage de l'emploi du temps
function formatScheduleForDisplay($schedule) {
    $formatted = [];
    $jours_semaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];

    foreach ($jours_semaine as $jour) {
        $formatted[$jour] = [];
        foreach ($schedule as $entry) {
            if ($entry['jour_semaine'] === $jour) {
                $formatted[$jour][] = $entry;
            }
        }
        // Trier par heure de début
        usort($formatted[$jour], function($a, $b) {
            return strcmp($a['heure_debut'], $b['heure_debut']);
        });
    }

    return $formatted;
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Générateur d'emploi du temps - ISTI</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-blue-600 text-white shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center">
                    <i class="fas fa-calendar-alt text-2xl mr-3"></i>
                    <h1 class="text-xl font-bold">Plateforme ISTI - Générateur d'emploi du temps</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm">Bienvenue, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Utilisateur'); ?></span>
                    <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-arrow-left mr-1"></i>Retour
                    </a>
                    <a href="logout.php" class="bg-red-500 hover:bg-red-600 px-3 py-1 rounded text-sm transition duration-200">
                        <i class="fas fa-sign-out-alt mr-1"></i>Déconnexion
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Messages d'erreur et de succès -->
        <?php if (!empty($errors)): ?>
            <div class="mb-8 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded">
                <ul class="list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="mb-8 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- Formulaire de génération -->
        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-6">
                <i class="fas fa-cogs mr-2"></i>Génération d'emploi du temps
            </h2>

            <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label for="filiere" class="block text-sm font-medium text-gray-700 mb-2">
                        Filière *
                    </label>
                    <select id="filiere" name="filiere" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner une filière...</option>
                        <?php foreach ($filieres as $filiere): ?>
                            <option value="<?php echo $filiere['id']; ?>" <?php echo $selected_filiere == $filiere['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filiere['nom']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="annee_academique" class="block text-sm font-medium text-gray-700 mb-2">
                        Année académique *
                    </label>
                    <select id="annee_academique" name="annee_academique" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner une année...</option>
                        <?php foreach ($annees_academiques as $annee): ?>
                            <option value="<?php echo $annee['id']; ?>" <?php echo $selected_annee == $annee['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($annee['annee_debut'] . '-' . $annee['annee_fin']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="semestre" class="block text-sm font-medium text-gray-700 mb-2">
                        Semestre
                    </label>
                    <select id="semestre" name="semestre"
                            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="all" <?php echo $selected_semestre === 'all' ? 'selected' : ''; ?>>Tous les semestres</option>
                        <option value="S1" <?php echo $selected_semestre === 'S1' ? 'selected' : ''; ?>>Semestre 1</option>
                        <option value="S2" <?php echo $selected_semestre === 'S2' ? 'selected' : ''; ?>>Semestre 2</option>
                    </select>
                </div>

                <div class="flex items-end">
                    <button type="submit" name="generate_schedule"
                            class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 w-full">
                        <i class="fas fa-magic mr-2"></i>Générer
                    </button>
                </div>
            </form>
        </div>

        <!-- Statistiques de génération -->
        <?php if (!empty($stats)): ?>
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-800">Classes</h3>
                            <p class="text-2xl font-bold text-blue-600"><?php echo $stats['total_classes']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-book text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-800">Matières</h3>
                            <p class="text-2xl font-bold text-green-600"><?php echo $stats['total_matieres']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="bg-orange-100 rounded-full p-3">
                            <i class="fas fa-chalkboard-teacher text-orange-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-800">Enseignants</h3>
                            <p class="text-2xl font-bold text-orange-600"><?php echo $stats['total_enseignants']; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow-md p-6">
                    <div class="flex items-center">
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-calendar-check text-purple-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <h3 class="font-semibold text-gray-800">Séances générées</h3>
                            <p class="text-2xl font-bold text-purple-600"><?php echo $stats['generated_sessions']; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Conflits détectés -->
        <?php if (!empty($conflicts)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">
                    <i class="fas fa-exclamation-triangle text-red-600 mr-2"></i>Conflits détectés
                </h3>
                <div class="space-y-2">
                    <?php foreach ($conflicts as $conflict): ?>
                        <div class="bg-red-50 border border-red-200 rounded-md p-4">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <i class="fas fa-exclamation-circle text-red-400"></i>
                                </div>
                                <div class="ml-3">
                                    <h4 class="text-sm font-medium text-red-800">
                                        <?php echo htmlspecialchars($conflict['type']); ?>
                                    </h4>
                                    <div class="mt-2 text-sm text-red-700">
                                        <p><?php echo htmlspecialchars($conflict['message']); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Emploi du temps généré -->
        <?php if (!empty($generated_schedule)): ?>
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-lg font-semibold text-gray-800">
                        <i class="fas fa-calendar-alt mr-2"></i>Emploi du temps généré
                    </h3>
                    <form method="POST" class="inline">
                        <input type="hidden" name="filiere" value="<?php echo $selected_filiere; ?>">
                        <input type="hidden" name="annee_academique" value="<?php echo $selected_annee; ?>">
                        <input type="hidden" name="semestre" value="<?php echo $selected_semestre; ?>">
                        <button type="submit" name="save_schedule"
                                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200"
                                onclick="return confirm('Êtes-vous sûr de vouloir sauvegarder cet emploi du temps ? Cela remplacera l\'emploi du temps existant.')">
                            <i class="fas fa-save mr-2"></i>Sauvegarder
                        </button>
                    </form>
                </div>

                <?php
                $formatted_schedule = formatScheduleForDisplay($generated_schedule);
                $jours_semaine = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
                ?>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Horaire
                                </th>
                                <?php foreach ($jours_semaine as $jour): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        <?php echo htmlspecialchars($jour); ?>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php
                            $time_slots = [
                                ['debut' => '08:00', 'fin' => '09:30'],
                                ['debut' => '09:45', 'fin' => '11:15'],
                                ['debut' => '11:30', 'fin' => '13:00'],
                                ['debut' => '14:00', 'fin' => '15:30'],
                                ['debut' => '15:45', 'fin' => '17:15'],
                                ['debut' => '17:30', 'fin' => '19:00']
                            ];

                            foreach ($time_slots as $slot):
                            ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($slot['debut'] . ' - ' . $slot['fin']); ?>
                                    </td>
                                    <?php foreach ($jours_semaine as $jour): ?>
                                        <td class="px-6 py-4">
                                            <?php
                                            $found = false;
                                            foreach ($formatted_schedule[$jour] ?? [] as $entry) {
                                                if ($entry['heure_debut'] === $slot['debut']) {
                                                    $found = true;
                                                    ?>
                                                    <div class="bg-blue-50 border border-blue-200 rounded-md p-3">
                                                        <div class="text-sm font-medium text-blue-900">
                                                            <?php echo htmlspecialchars($entry['matiere_nom'] ?? 'Matière'); ?>
                                                        </div>
                                                        <div class="text-xs text-blue-700 mt-1">
                                                            <div>Classe: <?php echo htmlspecialchars($entry['classe_nom'] ?? 'Classe'); ?></div>
                                                            <div>Enseignant: <?php echo htmlspecialchars($entry['enseignant_nom'] ?? 'Enseignant'); ?></div>
                                                            <div>Salle: <?php echo htmlspecialchars($entry['salle']); ?></div>
                                                        </div>
                                                    </div>
                                                    <?php
                                                    break;
                                                }
                                            }
                                            if (!$found):
                                            ?>
                                                <div class="text-gray-400 text-sm">-</div>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
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