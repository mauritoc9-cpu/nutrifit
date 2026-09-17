<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/NutricionResolver.php';
require_once __DIR__ . '/../../classes/FiltroAlimentario.php';

$usuarioId = requireAuth();

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    respond(true, []);
}

$db = (new Database())->getConnection();
$perfil = FiltroAlimentario::obtenerPerfilAlimentario($db, $usuarioId);

// El motor ya devuelve resultados en español, ordenados por relevancia,
// deduplicados y con datos nutricionales validados — acá solo se aplica
// el filtro de dieta/alergias del usuario encima de esos candidatos.
//
// Red de seguridad: una falla inesperada en traducción/USDA/OFF (o
// cualquier otra parte del motor) NUNCA debe traducirse en "sin
// resultados" si el catálogo local sí tiene algo — por eso, ante
// cualquier excepción, se degrada a una búsqueda puramente local en vez
// de dejar caer la petición entera.
try {
    require_once __DIR__ . '/../../classes/NutricionResolver.php';
    $candidatos = (new NutricionResolver($db))->buscarParaUsuario($query, 10);
} catch (Throwable $e) {
    error_log('[buscar_alimentos] NutricionResolver falló, degradando a búsqueda local: ' . $e->getMessage());
    require_once __DIR__ . '/../../classes/AlimentoRepository.php';
    $candidatos = array_map(fn ($a) => [
        'id' => (int) $a['id'],
        'nombre_mostrado' => $a['nombre_mostrado'] ?? $a['nombre'],
        'categoria' => $a['categoria'],
        'calorias_por_100g' => (float) $a['calorias_por_100g'],
        'proteinas' => (float) $a['proteinas'],
        'carbohidratos' => (float) $a['carbohidratos'],
        'grasas' => (float) $a['grasas'],
    ], (new AlimentoRepository($db))->buscarLocal($query, 20));
}

$resultados = array_values(array_filter(
    $candidatos,
    fn ($a) => FiltroAlimentario::esCompatible($a['nombre_mostrado'], $a['categoria'], $perfil['tipo_dieta'], $perfil['alergias'])
));

respond(true, array_slice($resultados, 0, 10));
