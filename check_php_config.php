<?php
/**
 * Vérification de la configuration PHP pour l'upload de fichiers
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Vérification Configuration PHP</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded shadow">
        <h1 class="text-2xl font-bold mb-6">Configuration PHP pour Upload de Fichiers</h1>
        
        <div class="space-y-4">
            <div class="border-l-4 border-blue-500 p-4 bg-blue-50">
                <h2 class="font-bold mb-2">Paramètres d'upload de fichiers</h2>
                <table class="w-full text-sm">
                    <tr>
                        <td class="py-1 font-medium">file_uploads:</td>
                        <td class="py-1">
                            <span class="px-2 py-1 rounded <?php echo ini_get('file_uploads') ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ini_get('file_uploads') ? 'Activé ✓' : 'Désactivé ✗'; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">upload_max_filesize:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('upload_max_filesize'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">post_max_size:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('post_max_size'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">max_file_uploads:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('max_file_uploads'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">upload_tmp_dir:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('upload_tmp_dir') ?: 'Défaut du système'; ?></td>
                    </tr>
                </table>
            </div>
            
            <div class="border-l-4 border-green-500 p-4 bg-green-50">
                <h2 class="font-bold mb-2">Limites de mémoire et temps</h2>
                <table class="w-full text-sm">
                    <tr>
                        <td class="py-1 font-medium">memory_limit:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('memory_limit'); ?></td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">max_execution_time:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('max_execution_time'); ?> secondes</td>
                    </tr>
                    <tr>
                        <td class="py-1 font-medium">max_input_time:</td>
                        <td class="py-1 font-mono"><?php echo ini_get('max_input_time'); ?> secondes</td>
                    </tr>
                </table>
            </div>
            
            <div class="border-l-4 border-purple-500 p-4 bg-purple-50">
                <h2 class="font-bold mb-2">Test de création de répertoire</h2>
                <?php
                $test_dir = __DIR__ . '/documents/inscriptions/test_' . time();
                if (mkdir($test_dir, 0777, true)) {
                    echo '<p class="text-green-700">✓ Répertoire de test créé avec succès : ' . $test_dir . '</p>';
                    rmdir($test_dir);
                    echo '<p class="text-green-700">✓ Répertoire de test supprimé avec succès</p>';
                } else {
                    echo '<p class="text-red-700">✗ Impossible de créer le répertoire de test</p>';
                }
                ?>
            </div>
            
            <div class="border-l-4 border-yellow-500 p-4 bg-yellow-50">
                <h2 class="font-bold mb-2">Codes d'erreur d'upload PHP</h2>
                <table class="w-full text-xs">
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_OK (0)</td><td>Pas d'erreur</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_INI_SIZE (1)</td><td>Fichier dépasse upload_max_filesize</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_FORM_SIZE (2)</td><td>Fichier dépasse MAX_FILE_SIZE du formulaire</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_PARTIAL (3)</td><td>Fichier partiellement uploadé</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_NO_FILE (4)</td><td>Aucun fichier uploadé</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_NO_TMP_DIR (6)</td><td>Répertoire temporaire manquant</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_CANT_WRITE (7)</td><td>Échec d'écriture sur disque</td></tr>
                    <tr><td class="py-1 font-mono">UPLOAD_ERR_EXTENSION (8)</td><td>Extension PHP a arrêté l'upload</td></tr>
                </table>
            </div>
            
            <div class="border-l-4 border-red-500 p-4 bg-red-50">
                <h2 class="font-bold mb-2">⚠️ Recommandations</h2>
                <ul class="list-disc ml-5 text-sm space-y-1">
                    <li>upload_max_filesize devrait être au minimum 5M pour votre application</li>
                    <li>post_max_size devrait être supérieur à upload_max_filesize (au moins 10M)</li>
                    <li>Assurez-vous que le répertoire documents/inscriptions existe et est accessible en écriture</li>
                    <li>Vérifiez les logs PHP (<?php echo ini_get('error_log') ?: 'Non défini'; ?>)</li>
                </ul>
            </div>
        </div>
        
        <div class="mt-6 flex space-x-4">
            <a href="test_upload.php" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                Tester l'upload simple
            </a>
            <a href="shared/register.php" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                Page d'inscription
            </a>
        </div>
    </div>
</body>
</html>
