<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$fecha = $_GET['fecha'] ?? date('Y-m-d');

if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    respond(false, null, 'Formato de fecha inválido. Usa YYYY-MM-DD.', 422);
}

require_once __DIR__ . '/../../classes/FiltroAlimentario.php';

$db = (new Database())->getConnection();

// Metas del usuario (definidas en el onboarding).
$stmtMeta = $db->prepare(
    'SELECT meta_calorias, meta_proteinas, meta_carbohidratos, meta_grasas
     FROM perfiles_biometricos WHERE usuario_id = :uid'
);
$stmtMeta->execute(['uid' => $usuarioId]);
$metas = $stmtMeta->fetch() ?: [
    'meta_calorias' => 2000, 'meta_proteinas' => 120, 'meta_carbohidratos' => 220, 'meta_grasas' => 60,
];

// Sugerencias de proteína acordes al tipo de dieta (evita recomendar
// pollo/pescado/huevo a usuarios vegetarianos/veganos, etc.).
$tipoDieta = FiltroAlimentario::obtenerPerfilAlimentario($db, $usuarioId)['tipo_dieta'];
$sugerenciaProteina = match ($tipoDieta) {
    'vegana'       => 'lentejas, garbanzos, tofu o tempeh',
    'vegetariana'  => 'huevo, lentejas, garbanzos o queso',
    'pescatariana' => 'pescado, salmón o huevo',
    default        => 'pollo, pescado o huevo',
};

// Comidas del día, agrupadas por tipo.
$stmtComidas = $db->prepare(
    "SELECT rc.id, rc.tipo_comida, rc.gramos, rc.calorias_totales, rc.proteinas_totales,
            rc.carbohidratos_totales, rc.grasas_totales, rc.hora_registro, a.nombre AS alimento
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

// --- Recomendación inteligente de próxima comida ---
$restanteProteina = max(0, (int) $metas['meta_proteinas'] - $totales['proteinas']);
$restanteCarbo = max(0, (int) $metas['meta_carbohidratos'] - $totales['carbohidratos']);
$restanteGrasa = max(0, (int) $metas['meta_grasas'] - $totales['grasas']);

if ($totales['carbohidratos'] > ((int) $metas['meta_carbohidratos'] * 0.6) && $restanteProteina > 20) {
    $recomendacion = "Ya llevas un buen nivel de carbohidratos hoy. Para tu próxima comida te recomendamos "
        . "priorizar proteína magra ({$sugerenciaProteina}) con vegetales de bajo índice calórico.";
} elseif ($restanteProteina > 40) {
    $recomendacion = "Aún te faltan aproximadamente {$restanteProteina}g de proteína para hoy. Considera "
        . "incluir una porción generosa de {$sugerenciaProteina} en tu próxima comida.";
} elseif ($totales['calorias'] >= (int) $metas['meta_calorias']) {
    $recomendacion = "Ya alcanzaste tu meta calórica del día. Si vas a comer algo más, opta por algo ligero "
        . "como una ensalada verde o yogur natural.";
} else {
    $recomendacion = "Vas bien encaminado. Mantén un balance entre proteína y vegetales en tu próxima comida "
        . "para cumplir tus macros del día.";
}

respond(true, [
    'fecha'  => $fecha,
    'metas'  => $metas,
    'totales_consumidos' => [
        'calorias'      => round($totales['calorias'], 1),
        'proteinas'     => round($totales['proteinas'], 1),
        'carbohidratos' => round($totales['carbohidratos'], 1),
        'grasas'        => round($totales['grasas'], 1),
    ],
    'restante' => [
        'calorias'      => max(0, (int) $metas['meta_calorias'] - (int) $totales['calorias']),
        'proteinas'     => $restanteProteina,
        'carbohidratos' => $restanteCarbo,
        'grasas'        => $restanteGrasa,
    ],
    'comidas_por_tipo' => $porTipo,
    'recomendacion_ia' => $recomendacion,
]);
