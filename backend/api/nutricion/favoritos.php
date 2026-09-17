<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/ComidaFavoritaRepository.php';

/**
 * GET /api/nutricion/favoritos.php
 * POST /api/nutricion/favoritos.php
 * PUT /api/nutricion/favoritos.php?id=N
 * DELETE /api/nutricion/favoritos.php?id=N
 *
 * Gestión de comidas favoritas del usuario autenticado.
 */

$metodo = $_SERVER['REQUEST_METHOD'];
$usuarioId = requireAuth();
$db = (new Database())->getConnection();
$repo = new ComidaFavoritaRepository($db);

if ($metodo === 'GET') {
    // Listar favoritos del usuario
    $favoritos = $repo->obtenerPorUsuario($usuarioId);

    // Decodificar metadata JSON
    foreach ($favoritos as &$fav) {
        if ($fav['metadata']) {
            $fav['metadata'] = json_decode($fav['metadata'], true);
        }
        $fav['es_compuesto'] = (bool) $fav['es_compuesto'];
        $fav['veces_usado'] = (int) $fav['veces_usado'];
    }

    respond(true, ['favoritos' => $favoritos]);
}
elseif ($metodo === 'POST') {
    // Crear nuevo favorito
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $nombrePersonalizado = trim($data['nombre_personalizado'] ?? '');
    if (!$nombrePersonalizado || strlen($nombrePersonalizado) > 120) {
        respond(false, null, 'Nombre personalizado inválido.', 422);
    }

    if ($repo->existeDuplicado($usuarioId, $nombrePersonalizado)) {
        respond(false, null, 'Ya existe un favorito con ese nombre.', 409);
    }

    $alimentoId = isset($data['alimento_id']) ? (int) $data['alimento_id'] : null;
    $gramosBase = (float) ($data['gramos_base'] ?? 100);
    $unidad = trim($data['unidad'] ?? 'g');
    $caloriasBase = (float) ($data['calorias_base'] ?? 0);
    $proteinasBase = (float) ($data['proteinas_base'] ?? 0);
    $carbohidratosBase = (float) ($data['carbohidratos_base'] ?? 0);
    $grasasBase = (float) ($data['grasas_base'] ?? 0);
    $esCompuesto = (bool) ($data['es_compuesto'] ?? false);
    $origen = trim($data['origen'] ?? 'manual');
    $metadata = $data['metadata'] ?? null;

    if ($gramosBase <= 0 || $caloriasBase < 0) {
        respond(false, null, 'Valores nutricionales inválidos.', 422);
    }

    try {
        $favoritoId = $repo->crear(
            $usuarioId,
            $nombrePersonalizado,
            $alimentoId,
            $gramosBase,
            $unidad,
            $caloriasBase,
            $proteinasBase,
            $carbohidratosBase,
            $grasasBase,
            $esCompuesto,
            $origen,
            $metadata
        );

        respond(true, ['id' => $favoritoId, 'mensaje' => 'Favorito guardado.'], 'Favorito guardado.', 201);
    } catch (Exception $e) {
        respond(false, null, 'Error al guardar el favorito.', 500);
    }
}
elseif ($metodo === 'PUT') {
    // Actualizar favorito existente
    $favoritoId = (int) ($_GET['id'] ?? 0);
    if (!$favoritoId) {
        respond(false, null, 'ID de favorito requerido.', 400);
    }

    $favorito = $repo->obtenerPorId($favoritoId, $usuarioId);
    if (!$favorito) {
        respond(false, null, 'Favorito no encontrado.', 404);
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $nombrePersonalizado = trim($data['nombre_personalizado'] ?? $favorito['nombre_personalizado']);
    if (!$nombrePersonalizado || strlen($nombrePersonalizado) > 120) {
        respond(false, null, 'Nombre personalizado inválido.', 422);
    }

    if ($nombrePersonalizado !== $favorito['nombre_personalizado'] && $repo->existeDuplicado($usuarioId, $nombrePersonalizado)) {
        respond(false, null, 'Ya existe un favorito con ese nombre.', 409);
    }

    $gramosBase = (float) ($data['gramos_base'] ?? $favorito['gramos_base']);
    $caloriasBase = (float) ($data['calorias_base'] ?? $favorito['calorias_base']);
    $proteinasBase = (float) ($data['proteinas_base'] ?? $favorito['proteinas_base']);
    $carbohidratosBase = (float) ($data['carbohidratos_base'] ?? $favorito['carbohidratos_base']);
    $grasasBase = (float) ($data['grasas_base'] ?? $favorito['grasas_base']);

    if ($gramosBase <= 0 || $caloriasBase < 0) {
        respond(false, null, 'Valores nutricionales inválidos.', 422);
    }

    $repo->actualizar(
        $favoritoId,
        $usuarioId,
        $nombrePersonalizado,
        $gramosBase,
        $caloriasBase,
        $proteinasBase,
        $carbohidratosBase,
        $grasasBase
    );

    respond(true, ['mensaje' => 'Favorito actualizado.']);
}
elseif ($metodo === 'DELETE') {
    // Eliminar favorito
    $favoritoId = (int) ($_GET['id'] ?? 0);
    if (!$favoritoId) {
        respond(false, null, 'ID de favorito requerido.', 400);
    }

    $favorito = $repo->obtenerPorId($favoritoId, $usuarioId);
    if (!$favorito) {
        respond(false, null, 'Favorito no encontrado.', 404);
    }

    $repo->eliminar($favoritoId, $usuarioId);
    respond(true, ['mensaje' => 'Favorito eliminado.']);
}
else {
    respond(false, null, 'Método no permitido.', 405);
}
