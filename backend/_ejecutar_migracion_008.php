<?php
require_once __DIR__ . '/config/bootstrap.php';

$db = (new Database())->getConnection();
$sql = file_get_contents(__DIR__ . '/../database/migrations/008_comidas_favoritas.sql');

try {
    $db->exec($sql);
    echo "✓ Migración 008 ejecutada correctamente.\n";
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
