<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/gamificacion/RetosService.php';

/**
 * GET /api/gamificacion/retos.php
 *
 * Retos semanales con progreso calculado en vivo desde datos reales.
 * Reconcilia (otorga la recompensa, una vez por semana) los completados.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$servicio = new RetosService($db);
respond(true, $servicio->listar($usuarioId));
