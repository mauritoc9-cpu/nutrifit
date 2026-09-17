<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/entrenamiento/EjercicioCatalogoRepository.php';

/**
 * GET /api/entrenamiento/ejercicio.php?id=N   (id del catálogo)
 * GET /api/entrenamiento/ejercicio.php?clave=sentadillas
 *
 * Devuelve CÓMO se hace un ejercicio: instrucciones paso a paso, errores
 * comunes, series/repeticiones/descanso sugeridos y el equipamiento que
 * requiere. Read-only.
 *
 * `recurso` viene en null mientras no haya GIF/vídeo propio cargado: el
 * modelo ya está preparado (recurso_tipo/recurso_url en el catálogo),
 * pero no se incrustan URLs externas frágiles.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

requireAuth();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$clave = isset($_GET['clave']) ? trim((string) $_GET['clave']) : '';

if ($id <= 0 && $clave === '') {
    respond(false, null, 'Indicá el id o la clave del ejercicio.', 422);
}

$db = (new Database())->getConnection();
$repo = new EjercicioCatalogoRepository($db);

$ejercicio = $id > 0 ? $repo->porId($id) : $repo->porClave($clave);

if ($ejercicio === null) {
    respond(false, null, 'Ejercicio no encontrado.', 404);
}

respond(true, [
    'id' => $ejercicio['id'],
    'clave' => $ejercicio['clave'],
    'nombre' => $ejercicio['nombre'],
    'grupo_muscular' => $ejercicio['grupo_muscular'],
    'tipo' => $ejercicio['tipo'],
    'nivel' => $ejercicio['nivel'],
    'equipamiento' => $ejercicio['equipamiento'],
    'lugar' => $ejercicio['lugar'],
    'sugerencia' => [
        'series' => $ejercicio['series_sugeridas'],
        'repeticiones' => $ejercicio['repeticiones_sugeridas'],
        'descanso_segundos' => $ejercicio['descanso_segundos'],
    ],
    'instrucciones' => $ejercicio['instrucciones'],
    'errores_comunes' => $ejercicio['errores_comunes'],
    'recurso' => $ejercicio['recurso_url'] !== null
        ? ['tipo' => $ejercicio['recurso_tipo'], 'url' => $ejercicio['recurso_url']]
        : null,
]);
