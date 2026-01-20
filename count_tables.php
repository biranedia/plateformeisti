<?php
require_once __DIR__ . '/config/database.php';
$db = new Database();
$c = $db->getConnection();

$tables = ['enseignements', 'emplois_du_temps'];
foreach ($tables as $t) {
    $count = $c->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    echo "$t: $count\n";
}
