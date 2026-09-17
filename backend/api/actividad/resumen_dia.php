<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/RegistroActividadRepository.php';

/** Resumen agregado de actividad de un día (default hoy) — usado por Home y por el wrapper de pasos.php. */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$fecha = (string) ($_GET['fecha'] ?? date('Y-m-d'));

if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    respond(false, null, 'Formato de fecha inválido. Usá YYYY-MM-DD.', 422);
}

$db = (new Database())->getConnection();
$repo = new RegistroActividadRepository($db);

$resumen = $repo->resumenDia($usuarioId, $fecha);
$resumen['meta_pasos'] = $repo->obtenerMetaPasos($usuarioId);
$resumen['fecha'] = $fecha;

respond(true, $resumen);
