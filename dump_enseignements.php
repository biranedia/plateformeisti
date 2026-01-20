<?php
require_once __DIR__ . '/config/database.php';
$db = new Database();
$c = $db->getConnection();
$rows = $c->query("SELECT id, classe_id, enseignant_id, matiere FROM enseignements LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
print_r($rows);
