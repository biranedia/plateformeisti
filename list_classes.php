<?php
require_once __DIR__ . '/config/database.php';
$c=(new Database())->getConnection();
$rows=$c->query('SELECT id, nom_classe, filiere_id FROM classes')->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
