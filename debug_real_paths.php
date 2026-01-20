<?php
// Pour vérifier comment accéder aux fichiers

echo "<h1>Analyse des chemins pour documents</h1>";

// Dossier courant
$current_dir = __DIR__;
echo "<p><strong>Dossier courant (__DIR__):</strong> " . htmlspecialchars($current_dir) . "</p>";

// Dossier documents
$doc_dir = __DIR__ . '/../documents/inscriptions';
echo "<p><strong>Chemin absolu documents:</strong> " . htmlspecialchars($doc_dir) . "</p>";
echo "<p><strong>Existe:</strong> " . (is_dir($doc_dir) ? "✓ OUI" : "✗ NON") . "</p>";

// Lister les fichiers
if (is_dir($doc_dir)) {
    $subdirs = array_diff(scandir($doc_dir), ['.', '..']);
    
    foreach ($subdirs as $subdir) {
        $user_path = $doc_dir . '/' . $subdir;
        if (is_dir($user_path)) {
            $files = array_diff(scandir($user_path), ['.', '..']);
            
            foreach ($files as $file) {
                $full_path = $user_path . '/' . $file;
                $relative_from_root = str_replace(__DIR__ . '/../', '', $full_path);
                $relative_from_web = str_replace('C:\\xampp\\htdocs\\', '', $full_path);
                
                echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
                echo "<strong>Fichier:</strong> " . htmlspecialchars($file) . "<br>";
                echo "<strong>Chemin en BD (expected):</strong> <code>" . htmlspecialchars($relative_from_root) . "</code><br>";
                echo "<strong>Chemin web (for browser):</strong> <code>" . htmlspecialchars($relative_from_web) . "</code><br>";
                echo "<strong>URL complète:</strong> <code>/plateformeisti/" . htmlspecialchars($relative_from_web) . "</code><br>";
                echo "<strong>Fichier existe:</strong> " . (file_exists($full_path) ? "✓ OUI" : "✗ NON") . "<br>";
                echo "</div>";
            }
        }
    }
}
?>
