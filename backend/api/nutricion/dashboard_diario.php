<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
require_once __DIR__ . '/../../classes/personalizacion/BalanceNutricionalDiario.php';
require_once __DIR__ . '/../../classes/personalizacion/RecomendadorNutricional.php';

/**
 * Resumen nutricional del día.
 *
 * Desde el paso 1 del Motor de Personalización, este endpoint ya no
 * contiene reglas: arma el contexto del usuario, calcula el balance del
 * día y delega la decisión en RecomendadorNutricional. Si hay que
 * cambiar una regla, se cambia allá — acá no quedó ninguna copia.
 */

$usuarioId = requireAuth();
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    respond(false, null, 'Formato de fecha inválido. Usa YYYY-MM-DD.', 422);
}

$db = (new Database())->getConnection();

// Perfil + metas + restricciones, en una sola lectura y con los defaults
// centralizados (ver PerfilDefaults) en vez de repetidos acá.
$contexto = (new ContextoUsuarioRepository($db))->obtener($usuarioId);

// Comidas del día, agrupadas por tipo.
$stmtComidas = $db->prepare(
    "SELECT rc.id, rc.tipo_comida, rc.gramos, rc.calorias_totales, rc.proteinas_totales,
            rc.carbohidratos_totales, rc.grasas_totales, rc.hora_registro,
            COALESCE(a.nombre_mostrado, a.nombre) AS alimento
     FROM registros_comidas rc
     JOIN alimentos a ON a.id = rc.alimento_id
     WHERE rc.usuario_id = :uid AND rc.fecha = :fecha
     ORDER BY rc.hora_registro ASC"
);
$stmtComidas->execute(['uid' => $usuarioId, 'fecha' => $fecha]);
$comidas = $stmtComidas->fetchAll();

$totales = [
    'calorias' => 0.0, 'proteinas' => 0.0, 'carbohidratos' => 0.0, 'grasas' => 0.0,
];
$porTipo = ['desayuno' => [], 'almuerzo' => [], 'merienda' => [], 'cena' => [], 'snack' => []];

foreach ($comidas as $c) {
    $totales['calorias'] += (float) $c['calorias_totales'];
    $totales['proteinas'] += (float) $c['proteinas_totales'];
    $totales['carbohidratos'] += (float) $c['carbohidratos_totales'];
    $totales['grasas'] += (float) $c['grasas_totales'];
    $porTipo[$c['tipo_comida']][] = $c;
}

$balance = BalanceNutricionalDiario::calcular($contexto, $totales);
$recomendacion = (new RecomendadorNutricional())->evaluar($contexto, $balance);

respond(true, [
    'fecha'  => $fecha,
    'metas'  => $contexto->metasComoArray(),
    'totales_consumidos' => [
        'calorias'      => round($totales['calorias'], 1),
        'proteinas'     => round($totales['proteinas'], 1),
        'carbohidratos' => round($totales['carbohidratos'], 1),
        'grasas'        => round($totales['grasas'], 1),
    ],
    'restante' => $balance->comoArrayRestante(),
    'comidas_por_tipo' => $porTipo,
    // ALIAS LEGACY: se llama `recomendacion_ia` por compatibilidad con el
    // frontend actual, pero la recomendación es 100% determinista (reglas
    // sobre los datos del propio usuario, ver RecomendadorNutricional).
    // No hay IA involucrada. Renombrarlo requiere tocar el frontend, así
    // que queda pendiente para una etapa posterior.
    'recomendacion_ia' => $recomendacion->mensaje,
]);
