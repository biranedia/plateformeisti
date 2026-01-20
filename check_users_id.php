<?php
require_once 'config/database.php';

$database = new Database();
$conn = $database->getConnection();

echo "Type de users.id:\n";
$stmt = $conn->query('DESCRIBE users');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    if($row['Field'] == 'id') {
        echo $row['Type'] . "\n";
    }
}
