<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "<h1>Debug - Documents en Base de Données</h1>";

$query = "SELECT id, user_id, nom_fichier, chemin_fichier, statut FROM documents_inscription LIMIT 10";
$stmt = $conn->prepare($query);
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background-color: #f0f0f0;'>";
echo "<th>ID</th><th>Chemin en BD</th><th>Fichier Existe?</th><th>Chemin Réel</th>";
echo "</tr>";

foreach ($documents as $doc) {
    $chemin_bd = $doc['chemin_fichier'];
    $chemin_abs = __DIR__ . '/' . $chemin_bd;
    $existe = file_exists($chemin_abs) ? "✓ OUI" : "✗ NON";
    
    echo "<tr>";
    echo "<td>" . $doc['id'] . "</td>";
    echo "<td><code>" . htmlspecialchars($chemin_bd) . "</code></td>";
    echo "<td>" . $existe . "</td>";
    echo "<td><code style='font-size: 11px;'>" . htmlspecialchars($chemin_abs) . "</code></td>";
    echo "</tr>";
}

echo "</table>";

echo "<h2>Structure des dossiers</h2>";
$dir = __DIR__ . '/documents/inscriptions';
if (is_dir($dir)) {
    echo "<p>Le dossier <code>" . htmlspecialchars($dir) . "</code> existe ✓</p>";
    
    $subdirs = array_diff(scandir($dir), ['.', '..']);
    echo "<p>Contenu:</p>";
    echo "<ul>";
    foreach ($subdirs as $subdir) {
        $full_path = $dir . '/' . $subdir;
        if (is_dir($full_path)) {
            $files = array_diff(scandir($full_path), ['.', '..']);
            echo "<li><strong>" . htmlspecialchars($subdir) . "</strong> (" . count($files) . " fichiers)";
            if (count($files) > 0) {
                echo "<ul>";
                foreach (array_slice($files, 0, 5) as $file) {
                    echo "<li>" . htmlspecialchars($file) . "</li>";
                }
                if (count($files) > 5) {
                    echo "<li>... et " . (count($files) - 5) . " autres fichiers</li>";
                }
                echo "</ul>";
            }
            echo "</li>";
        }
    }
    echo "</ul>";
} else {
    echo "<p>❌ Le dossier n'existe pas!</p>";
}
?>
