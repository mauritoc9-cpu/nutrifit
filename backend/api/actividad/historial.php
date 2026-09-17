<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/RegistroActividadRepository.php';

/** Historial de sesiones de actividad del usuario, más recientes primero. */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$limite = min(100, max(1, (int) ($_GET['limite'] ?? 30)));

$db = (new Database())->getConnection();
respond(true, (new RegistroActividadRepository($db))->historial($usuarioId, $limite));
