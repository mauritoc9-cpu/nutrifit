<?php
declare(strict_types=1);

// BD config
$pdo = new PDO("mysql:host=127.0.0.1;dbname=nutrifit_db;charset=utf8mb4", "root", "");

echo "=== VERIFICACIÓN DE DURACIONES EN BD ===\n\n";

// Usuario de test
$stmt = $pdo->query("SELECT id FROM usuarios WHERE email = 'test.escaner@nutrifit.local' LIMIT 1");
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { echo "Usuario no encontrado.\n"; exit(1); }

$uid = $user['id'];
echo "Usuario: test.escaner@nutrifit.local (ID: $uid)\n\n";

// Últimos registros
$stmt = $pdo->query(
  "SELECT
     r.id,
     r.tipo_actividad_id,
     r.duracion_segundos,
     ROUND(r.duracion_segundos/60) as minutos,
     r.distancia_metros,
     r.pasos,
     r.calorias_kcal,
     DATE_FORMAT(r.creado_en, '%H:%i:%s') as hora
   FROM registros_actividad r
   WHERE r.usuario_id = $uid
   ORDER BY r.id DESC LIMIT 10"
);

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "Sin registros.\n";
    exit;
}

echo "Últimos registros:\n";
echo str_repeat("-", 70) . "\n";

$tipoMap = [1 => 'Caminata', 2 => 'Carrera', 3 => 'Bicicleta'];

foreach ($rows as $r) {
    $tipo = $tipoMap[$r['tipo_actividad_id']] ?? 'Tipo ' . $r['tipo_actividad_id'];
    printf(
        "ID%-3d | %-10s | %3d min (%5d seg) | %s%s | %d kcal\n",
        $r['id'],
        $tipo,
        $r['minutos'],
        $r['duracion_segundos'],
        $r['distancia_metros'] ? sprintf("%.1fkm", $r['distancia_metros']/1000) : "     ",
        $r['pasos'] ? " + {$r['pasos']} pasos" : "",
        $r['calorias_kcal']
    );
}

echo str_repeat("-", 70) . "\n";
echo "\n✓ Pruebas esperadas:\n";
echo "  - 1,5 horas (Caminata) → 90 min (5400 seg)\n";
echo "  - 2 horas (Caminata) → 120 min (7200 seg)\n";
echo "  - 1 hora (Bicicleta) → 60 min (3600 seg)\n";
?>
