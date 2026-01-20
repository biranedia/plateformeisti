<?php
/**
 * Consultation des emplois du temps - Admin général
 */
session_start();
require_once '../config/database.php';
require_once '../config/utils.php';

if (!isLoggedIn() || !hasRole('admin')) {
    redirectWithMessage('../shared/login.php', 'Accès non autorisé.', 'error');
}

$database = new Database();
$conn = $database->getConnection();

// Jours de la semaine
$jours_semaine = [
    1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi'
];

// Filtres
$filiere_filter = isset($_GET['filiere_id']) ? (int)$_GET['filiere_id'] : 0;
$classe_filter  = isset($_GET['classe']) ? (int)$_GET['classe'] : 0;
$annee_filter   = isset($_GET['annee']) ? sanitize($_GET['annee']) : date('Y') . '/' . (date('Y') + 1);

// Filieres
$filieres_stmt = $conn->prepare("SELECT id, nom FROM filieres ORDER BY nom");
$filieres_stmt->execute();
$filieres = $filieres_stmt->fetchAll(PDO::FETCH_ASSOC);

// Classes pour la filiere
$classes = [];
if ($filiere_filter) {
    $classes_stmt = $conn->prepare("SELECT id, nom_classe, niveau FROM classes WHERE filiere_id = :fid ORDER BY niveau, nom_classe");
    $classes_stmt->execute([':fid' => $filiere_filter]);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Emploi du temps
$emploi_du_temps = [];
if ($classe_filter) {
    $edt_query = "SELECT e.*, c.nom_classe, f.nom as filiere_nom, u.name as enseignant_nom
                  FROM emplois_du_temps e
                  JOIN classes c ON e.classe_id = c.id
                  JOIN filieres f ON c.filiere_id = f.id
                  JOIN users u ON e.enseignant_id = u.id
                  WHERE e.classe_id = :classe_id AND e.annee_academique = :annee
                  ORDER BY e.jour_semaine, e.creneau_horaire";
    $edt_stmt = $conn->prepare($edt_query);
    $edt_stmt->execute([
        ':classe_id' => $classe_filter,
        ':annee' => $annee_filter
    ]);
    $emploi_du_temps = $edt_stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emploi du Temps - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-gray-100">
<header class="bg-indigo-600 text-white shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
            <div class="flex items-center space-x-3">
                <i class="fas fa-calendar text-2xl"></i>
                <h1 class="text-xl font-bold">Emploi du Temps</h1>
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
            <a href="dashboard.php" class="text-gray-600 hover:text-indigo-600"><i class="fas fa-tachometer-alt mr-1"></i>Dashboard</a>
            <a href="classes.php" class="text-gray-600 hover:text-indigo-600"><i class="fas fa-users mr-1"></i>Classes</a>
            <a href="filieres.php" class="text-gray-600 hover:text-indigo-600"><i class="fas fa-graduation-cap mr-1"></i>Filières</a>
            <a href="emplois_du_temps.php" class="text-indigo-600 border-b-2 border-indigo-600 pb-2"><i class="fas fa-calendar mr-1"></i>Emplois du temps</a>
        </div>
    </div>
</nav>

<main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="bg-white rounded-lg shadow-md p-6 mb-8">
        <h2 class="text-xl font-bold text-gray-800 mb-6"><i class="fas fa-filter mr-2"></i>Filtres</h2>
        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Filière</label>
                <select name="filiere_id" onchange="this.form.submit()" class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="0">Toutes</option>
                    <?php foreach ($filieres as $f): ?>
                        <option value="<?php echo $f['id']; ?>" <?php echo $filiere_filter == $f['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['nom']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($filiere_filter): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Classe</label>
                <select name="classe" onchange="this.form.submit()" class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="0">Sélectionner</option>
                    <?php foreach ($classes as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo $classe_filter == $c['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['nom_classe']); ?> (<?php echo htmlspecialchars($c['niveau']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Année académique</label>
                <input type="text" name="annee" value="<?php echo htmlspecialchars($annee_filter); ?>" placeholder="Ex: 2025/2026" class="w-full px-3 py-2 border rounded-md focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="flex items-end">
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-md">
                    <i class="fas fa-search mr-2"></i>Rechercher
                </button>
            </div>
        </form>
    </div>

    <?php if ($classe_filter): ?>
    <div class="bg-white rounded-lg shadow-md p-6">
        <h2 class="text-xl font-bold text-gray-800 mb-6"><i class="fas fa-calendar-alt mr-2"></i>Emploi du temps</h2>
        <?php if (empty($emploi_du_temps)): ?>
            <div class="text-center py-12">
                <i class="fas fa-calendar text-gray-300 text-6xl mb-4"></i>
                <h3 class="text-xl font-semibold text-gray-600 mb-2">Aucun cours planifié</h3>
                <p class="text-gray-500">Aucun cours n'a été planifié pour cette classe.</p>
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
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($emploi_du_temps as $cours): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $jours_semaine[$cours['jour_semaine']] ?? $cours['jour_semaine']; ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($cours['creneau_horaire']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($cours['matiere_nom']); ?></td>
                            <td class="px-6 py-4 text-sm text-gray-900"><?php echo htmlspecialchars($cours['enseignant_nom']); ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($cours['salle']); ?></td>
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
        <h3 class="text-xl font-semibold text-gray-600 mb-2">Sélectionnez une filière puis une classe</h3>
        <p class="text-gray-500">Choisissez une classe pour afficher son emploi du temps.</p>
    </div>
    <?php endif; ?>
</main>
</body>
</html>
