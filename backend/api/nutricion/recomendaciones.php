<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
require_once __DIR__ . '/../../classes/personalizacion/BalanceNutricionalDiario.php';
require_once __DIR__ . '/../../classes/nutricion/RecomendadorAlimentos.php';

/**
 * GET /api/nutricion/recomendaciones.php
 *
 * Qué conviene comer ahora, según lo que al usuario le falta HOY y su
 * objetivo. Read-only: no registra comidas ni otorga XP.
 *
 * Devuelve dos listas:
 *   para_mi → personalizada por balance del día + objetivo
 *   top     → catálogo curado compatible con dieta/restricciones
 *
 * Todos los macros salen de la tabla `alimentos` (el mismo catálogo del
 * buscador y la cámara). No se estiman ni se generan con IA.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    respond(false, null, 'Formato de fecha inválido. Usa YYYY-MM-DD.', 422);
}

$db = (new Database())->getConnection();

$contexto = (new ContextoUsuarioRepository($db))->obtener($usuarioId);

// Consumo del día: misma fuente que dashboard_diario.
$stmt = $db->prepare(
    'SELECT COALESCE(SUM(calorias_totales),0) c, COALESCE(SUM(proteinas_totales),0) p,
            COALESCE(SUM(carbohidratos_totales),0) ch, COALESCE(SUM(grasas_totales),0) g
     FROM registros_comidas WHERE usuario_id = :uid AND fecha = :fecha'
);
$stmt->execute(['uid' => $usuarioId, 'fecha' => $fecha]);
$fila = $stmt->fetch();

$balance = BalanceNutricionalDiario::calcular($contexto, [
    'calorias' => (float) $fila['c'],
    'proteinas' => (float) $fila['p'],
    'carbohidratos' => (float) $fila['ch'],
    'grasas' => (float) $fila['g'],
]);

$recomendaciones = (new RecomendadorAlimentos($db))->recomendar($contexto, $balance);
$recomendaciones['fecha'] = $fecha;

respond(true, $recomendaciones);
