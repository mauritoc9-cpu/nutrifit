<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/ResumenSemanalService.php';

/**
 * Resumen agregado para Mi Progreso. Por defecto, últimos 7 días
 * (incluye hoy). Acepta `dias` (entero) o `desde`/`hasta` explícitos.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();

if (isset($_GET['desde']) || isset($_GET['hasta'])) {
    $desde = (string) ($_GET['desde'] ?? date('Y-m-d', strtotime('-6 days')));
    $hasta = (string) ($_GET['hasta'] ?? date('Y-m-d'));
} else {
    $dias = max(1, min(90, (int) ($_GET['dias'] ?? 7)));
    $hasta = date('Y-m-d');
    $desde = date('Y-m-d', strtotime("-" . ($dias - 1) . " days"));
}

if (!DateTime::createFromFormat('Y-m-d', $desde) || !DateTime::createFromFormat('Y-m-d', $hasta) || $desde > $hasta) {
    respond(false, null, 'Rango de fechas inválido.', 422);
}

$db = (new Database())->getConnection();
$servicio = new ResumenSemanalService($db);

respond(true, $servicio->generar($usuarioId, $desde, $hasta));
