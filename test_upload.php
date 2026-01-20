<?php
/**
 * Test de l'upload de fichiers
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<h2>État de \$_FILES:</h2>";
    echo "<pre>";
    print_r($_FILES);
    echo "</pre>";
    
    echo "<h2>État de \$_POST:</h2>";
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
    
    // Test des conditions
    echo "<h2>Tests de validation:</h2>";
    
    echo "<p><strong>releve_bac:</strong></p>";
    echo "isset: " . (isset($_FILES['releve_bac']) ? 'OUI' : 'NON') . "<br>";
    if (isset($_FILES['releve_bac'])) {
        echo "error: " . $_FILES['releve_bac']['error'] . "<br>";
        echo "UPLOAD_ERR_NO_FILE: " . UPLOAD_ERR_NO_FILE . "<br>";
        echo "Égal à NO_FILE: " . ($_FILES['releve_bac']['error'] === UPLOAD_ERR_NO_FILE ? 'OUI' : 'NON') . "<br>";
    }
    
    echo "<p><strong>diplome_bac:</strong></p>";
    echo "isset: " . (isset($_FILES['diplome_bac']) ? 'OUI' : 'NON') . "<br>";
    if (isset($_FILES['diplome_bac'])) {
        echo "error: " . $_FILES['diplome_bac']['error'] . "<br>";
        echo "UPLOAD_ERR_NO_FILE: " . UPLOAD_ERR_NO_FILE . "<br>";
        echo "Égal à NO_FILE: " . ($_FILES['diplome_bac']['error'] === UPLOAD_ERR_NO_FILE ? 'OUI' : 'NON') . "<br>";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Test Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-2xl mx-auto bg-white p-6 rounded shadow">
        <h1 class="text-2xl font-bold mb-4">Test Upload de Documents</h1>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Rôle</label>
                <select name="role" class="w-full px-3 py-2 border rounded">
                    <option value="">Sélectionner</option>
                    <option value="etudiant">Étudiant</option>
                    <option value="enseignant">Enseignant</option>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Relevé BAC</label>
                <input type="file" name="releve_bac" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-3 py-2 border rounded">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Diplôme BAC</label>
                <input type="file" name="diplome_bac" accept=".pdf,.jpg,.jpeg,.png" class="w-full px-3 py-2 border rounded">
            </div>
            
            <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Tester l'upload
            </button>
        </form>
    </div>
</body>
</html>
