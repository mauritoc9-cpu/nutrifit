<?php
/**
 * Verificar que las duraciones se registraron correctamente en BD
 * (minutos convertidos de horas)
 */
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';

$db = new PDO(
    'mysql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NAME'),
    getenv('DB_USER'),
    getenv('DB_PASS')
);

echo "=== VERIFICACIÓN DE DURACIONES REGISTRADAS ===\n\n";

// Obtener las últimas actividades del usuario de test (usuario_id = 1 aproximadamente)
$query = "
  SELECT
    id,
    usuario_id,
    tipo_actividad,
    duracion_segundos,
    ROUND(duracion_segundos / 60) as duracion_minutos,
    distancia_metros,
    pasos,
    DATE_FORMAT(fecha_hora, '%Y-%m-%d %H:%i:%s') as fecha,
    calorias_kcal
  FROM registros_actividad
  WHERE usuario_id IN (SELECT id FROM usuarios WHERE email = 'test.escaner@nutrifit.local')
  ORDER BY fecha DESC
  LIMIT 10
";

$stmt = $db->query($query);
$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($registros)) {
    echo "No se encontraron registros para este usuario.\n";
    exit;
}

echo "Últimas 10 actividades del usuario de test:\n\n";

foreach ($registros as $r) {
    echo "ID: {$r['id']}\n";
    echo "  Tipo: {$r['tipo_actividad']}\n";
    echo "  Duración: {$r['duracion_minutos']} minutos ({$r['duracion_segundos']} segundos)\n";
    if ($r['distancia_metros']) {
        echo "  Distancia: " . ($r['distancia_metros'] / 1000) . " km\n";
    }
    if ($r['pasos']) {
        echo "  Pasos: {$r['pasos']}\n";
    }
    echo "  Calorías: {$r['calorias_kcal']} kcal\n";
    echo "  Fecha: {$r['fecha']}\n";
    echo "\n";
}

// Resumen
echo "=== RESUMEN ===\n";
echo "Total de registros: " . count($registros) . "\n";
echo "Tipos de actividades:\n";
foreach ($registros as $r) {
    echo "  - {$r['tipo_actividad']}: {$r['duracion_minutos']} min\n";
}
?>
