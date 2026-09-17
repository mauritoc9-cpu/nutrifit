<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
require_once __DIR__ . '/../../classes/nutricion/GeneradorRecetasIA.php';

/**
 * GET /api/nutricion/receta.php[?momento=almuerzo][&regenerar=1]
 *
 * Receta personalizada y compatible con la dieta/restricciones del
 * usuario. La IA (Gemini) redacta la combinación y los pasos; los
 * ingredientes se validan contra el catálogo y los macros los calcula el
 * backend. Si la IA no está disponible, responde igual con una receta
 * determinista (`origen = deterministica`).
 *
 * No otorga XP ni registra comidas: generar o ver una receta no es una
 * acción de salud.
 *
 * La API key vive sólo en el servidor (.env) y nunca viaja al cliente.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();

$momentosValidos = ['desayuno', 'almuerzo', 'merienda', 'cena'];
$momento = (string) ($_GET['momento'] ?? '');
if (!in_array($momento, $momentosValidos, true)) {
    $hora = (int) date('G');
    $momento = $hora < 11 ? 'desayuno' : ($hora < 15 ? 'almuerzo' : ($hora < 19 ? 'merienda' : 'cena'));
}

$regenerar = ($_GET['regenerar'] ?? '') === '1';

$db = (new Database())->getConnection();
$contexto = (new ContextoUsuarioRepository($db))->obtener($usuarioId);

$generador = new GeneradorRecetasIA($db, env('GEMINI_API_KEY'));
$receta = $generador->generar($contexto, $momento, $regenerar);

if (($receta['disponible'] ?? false) === false) {
    respond(false, $receta, $receta['mensaje'] ?? 'No se pudo generar una receta.', 422);
}

respond(true, $receta);
