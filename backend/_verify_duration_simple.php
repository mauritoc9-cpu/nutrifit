<?php
/**
 * Verificar duraciones en BD - versión simple
 */
declare(strict_types=1);

// Configuración de BD
$host = '127.0.0.1';
$db = 'nutrifit_db';
$user = 'root';
$pass = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
} catch (Exception $e) {
    echo "Error de conexión: " . $e->getMessage();
    exit(1);
}

echo "=== VERIFICACIÓN DE DURACIONES EN BD ===\n\n";

// Obtener ID del usuario de test
$stmt = $pdo->query("SELECT id FROM usuarios WHERE email = 'test.escaner@nutrifit.local' LIMIT 1");
$usuarioRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuarioRow) {
    echo "No se encontró el usuario de test.\n";
    exit(1);
}

$usuarioId = $usuarioRow['id'];
echo "Usuario ID: $usuarioId\n\n";

// Obtener últimas actividades
$stmt = $pdo->query(
    "SELECT
      id,
      tipo_actividad,
      duracion_segundos,
      ROUND(duracion_segundos / 60) as duracion_minutos,
      distancia_metros,
      pasos,
      DATE_FORMAT(fecha_hora, '%Y-%m-%d %H:%i') as fecha,
      calorias_kcal
    FROM registros_actividad
    WHERE usuario_id = $usuarioId
    ORDER BY id DESC
    LIMIT 5"
);

$registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($registros)) {
    echo "No se encontraron registros.\n";
    exit;
}

echo "Últimas 5 actividades:\n";
echo str_repeat("-", 80) . "\n";

foreach ($registros as $r) {
    printf(
        "ID %d | %s | %d min (%d seg) | %s %s | %d kcal\n",
        $r['id'],
        str_pad($r['tipo_actividad'], 10),
        $r['duracion_minutos'],
        $r['duracion_segundos'],
        $r['distancia_metros'] ? sprintf("%.2f km", $r['distancia_metros'] / 1000) : "-",
        $r['pasos'] ? "{$r['pasos']} pasos" : "-",
        $r['calorias_kcal']
    );
}

echo str_repeat("-", 80) . "\n";

// Verificación específica
echo "\n✓ PRUEBAS REGISTRADAS:\n";
echo "  1,5 horas → 90 minutos (5400 seg)\n";
echo "  2 horas → 120 minutos (7200 seg)\n";
echo "  1 hora (Bicicleta) → 60 minutos (3600 seg)\n";
echo "\nVerifica que los valores anteriores aparezcan en la lista.\n";
?>
