<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/RegistroActividadRepository.php';

/** Catálogo de tipos de actividad activos, para que el frontend arme el selector dinámicamente. */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

requireAuth();
$db = (new Database())->getConnection();

respond(true, (new RegistroActividadRepository($db))->obtenerTipos());
