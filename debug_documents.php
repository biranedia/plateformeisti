<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "<h2>Documents en base de données:</h2>";

$query = "SELECT id, user_id, nom_fichier, chemin_fichier, statut FROM documents_inscription LIMIT 5";
$stmt = $conn->prepare($query);
$stmt->execute();
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($documents as $doc) {
    echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
    echo "<strong>ID:</strong> " . $doc['id'] . "<br>";
    echo "<strong>User ID:</strong> " . $doc['user_id'] . "<br>";
    echo "<strong>Fichier:</strong> " . htmlspecialchars($doc['nom_fichier']) . "<br>";
    echo "<strong>Chemin en BD:</strong> " . htmlspecialchars($doc['chemin_fichier']) . "<br>";
    
    // Tester les différents chemins
    $paths = [
        '../' . $doc['chemin_fichier'],
        '../../' . $doc['chemin_fichier'],
        $doc['chemin_fichier'],
        __DIR__ . '/' . $doc['chemin_fichier'],
    ];
    
    echo "<strong>Test des chemins:</strong><br>";
    foreach ($paths as $path) {
        $exists = file_exists($path);
        $status = $exists ? "✓ EXISTE" : "✗ N'EXISTE PAS";
        echo "  $path - $status<br>";
    }
    
    echo "</div>";
}
?>
