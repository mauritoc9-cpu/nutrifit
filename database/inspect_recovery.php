<?php
declare(strict_types=1);

// CLI only. Reads schema metadata; never executes the generated repair SQL.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = is_file(__DIR__ . '/../backend/config/database.php') ? dirname(__DIR__) : getcwd();
require_once $root . '/backend/config/database.php';
$db = (new Database())->getConnection();
$tables = ['usuarios', 'alimentos', 'rutinas_ejercicios', 'ejercicios', 'comidas_favoritas', 'ejercicios_catalogo', 'ejercicios_completados', 'rutina_comparticiones', 'rutina_importaciones'];
$report = ['server_version' => $db->query('SELECT VERSION()')->fetchColumn(), 'tables' => [], 'blockers' => [], 'proposed_sql' => []];
foreach ($tables as $table) {
    $q = $db->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $q->execute([$table]);
    $engine = $q->fetchColumn();
    if (!$engine) { $report['tables'][$table] = null; continue; }
    $q = $db->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
    $q->execute([$table]);
    $columns = [];
    foreach ($q->fetchAll() as $column) { $columns[$column['COLUMN_NAME']] = $column; }
    $report['tables'][$table] = ['engine' => $engine, 'columns' => $columns, 'create_sql' => $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1]];
}
foreach (['alimentos' => ['nombre_mostrado' => 'VARCHAR(150) NULL'], 'rutinas_ejercicios' => ['generada_en' => 'DATETIME NULL']] as $table => $required) {
    if (!$report['tables'][$table]) { $report['blockers'][] = "Missing parent table: $table"; continue; }
    foreach ($required as $column => $definition) {
        if (!isset($report['tables'][$table]['columns'][$column])) {
            $report['proposed_sql'][] = "ALTER TABLE `$table` ADD COLUMN `$column` $definition;";
        }
    }
}
foreach (['rutinas_ejercicios' => ['usuario_id','titulo','lugar','objetivo','nivel_dificultad','descripcion','origen','plan_hash'], 'ejercicios' => ['catalogo_id','descanso_segundos']] as $table => $columns) {
    foreach ($columns as $column) {
        if (!isset($report['tables'][$table]['columns'][$column])) { $report['blockers'][] = "Review migration 009: missing $table.$column"; }
    }
}
if (!$report['tables']['comidas_favoritas']) {
    $compatible = true;
    foreach (['usuarios','alimentos'] as $parent) {
        $info = $report['tables'][$parent];
        $type = strtolower($info['columns']['id']['COLUMN_TYPE'] ?? '');
        if (!$info || strcasecmp($info['engine'], 'InnoDB') !== 0 || !preg_match('/^int(?:\(\d+\))? unsigned$/', $type) || !preg_match('/PRIMARY KEY\s*\(`id`\)/i', $info['create_sql'])) {
            $compatible = false;
            $report['blockers'][] = "Review foreign key compatibility: $parent.id must be INT UNSIGNED primary key, engine InnoDB";
        }
    }
    if ($compatible) {
        $migration = $root . '/database/migrations/008_comidas_favoritas.sql';
        if (is_file($migration)) { $report['proposed_sql'][] = file_get_contents($migration); }
        else { $report['blockers'][] = 'Migration 008 file unavailable; inspect local migration before creating favorites'; }
    }
}
$report['execution_policy'] = 'READ ONLY. Review actual production schema, blockers and a verified restorable backup before applying SQL. DDL is not transactionally reversible. Rerun inspector before retrying; never replay a stale plan.';
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
