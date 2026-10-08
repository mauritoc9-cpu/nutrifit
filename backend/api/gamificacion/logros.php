<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/gamificacion/LogrosService.php';

/**
 * GET /api/gamificacion/logros.php
 *
 * Devuelve el catálogo de logros con el estado del usuario. Evalúa y
 * desbloquea (de forma idempotente) los que correspondan antes de
 * responder — incluye desbloqueo retroactivo. La recompensa de XP de
 * cada logro se otorga una única vez (ver LogrosService).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$servicio = new LogrosService($db);
$logros = $servicio->listarConEstado($usuarioId);

$desbloqueados = array_values(array_filter($logros, static fn ($l) => $l['desbloqueado']));

respond(true, [
    'logros' => $logros,
    'total' => count($logros),
    'desbloqueados' => count($desbloqueados),
]);
