<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
require_once __DIR__ . '/../../classes/personalizacion/MotorPersonalizacion.php';

/**
 * GET /api/personalizacion/plan.php
 *
 * Devuelve el PlanPersonalizacion vigente del usuario autenticado.
 * Es READ-ONLY: no escribe nada, no otorga XP y no muta estado.
 *
 * Es el contrato que van a consumir tanto el frontend web como el futuro
 * cliente React Native: toda la decisión vive acá, no en el cliente.
 *
 * Parámetro opcional `dias` (1-90, default 7): ventana del historial
 * reciente que se usa para ajustar el plan.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$dias = max(1, min(90, (int) ($_GET['dias'] ?? 7)));

$db = (new Database())->getConnection();

$contexto = (new ContextoUsuarioRepository($db))->obtenerConHistorial($usuarioId, $dias);
$plan = (new MotorPersonalizacion())->generar($contexto);

respond(true, $plan->comoArray());
