<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Structure de la table documents_inscription:\n";
echo str_repeat("=", 50) . "\n";

$query = "DESCRIBE documents_inscription";
$stmt = $conn->query($query);
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $column) {
    echo sprintf("%-30s: %s\n", $column['Field'], $column['Type']);
}

echo "\n\nDocuments actuels:\n";
echo str_repeat("=", 50) . "\n";

$docs_query = "SELECT d.id, d.user_id, u.name, u.email, d.type_document, d.statut, d.date_upload
               FROM documents_inscription d
               JOIN users u ON d.user_id = u.id
               ORDER BY d.date_upload DESC";
$docs_stmt = $conn->query($docs_query);
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($documents)) {
    echo "Aucun document trouvé\n";
} else {
    foreach ($documents as $doc) {
        echo sprintf("ID: %d | User: %s (%s) | Type: %s | Statut: %s | Date: %s\n",
            $doc['id'],
            $doc['name'],
            $doc['email'],
            $doc['type_document'],
            $doc['statut'],
            $doc['date_upload']
        );
    }
}
