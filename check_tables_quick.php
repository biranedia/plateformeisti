<?php
require_once 'config/database.php';
$db = new Database();
$conn = $db->getConnection();
$result = $conn->query('SHOW TABLES');
$tables = $result->fetchAll(PDO::FETCH_COLUMN);
echo 'Tables: ' . implode(', ', $tables) . PHP_EOL;
?>