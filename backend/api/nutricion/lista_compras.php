<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/FiltroAlimentario.php';

/**
 * Genera una lista de compras semanal a partir del catálogo de alimentos,
 * escalando cantidades según la meta calórica diaria del usuario.
 * Es una heurística simple (no un plan de comidas optimizado), pensada
 * para dar un punto de partida útil. Excluye alimentos no aptos según
 * el tipo de dieta y las alergias/intolerancias del onboarding.
 */

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$stmt = $db->prepare('SELECT meta_calorias, objetivo FROM perfiles_biometricos WHERE usuario_id = :uid');
$stmt->execute(['uid' => $usuarioId]);
$perfil = $stmt->fetch();
$perfilAlimentario = FiltroAlimentario::obtenerPerfilAlimentario($db, $usuarioId);

$metaCalorias = $perfil['meta_calorias'] ?? 2000;
$factorEscala = max(0.7, min(2.0, $metaCalorias / 2000)); // escala razonable entre 0.7x y 2x

// Sólo el catálogo curado (no lo cacheado desde el buscador externo):
// una lista de compras no debería mezclar decenas de productos de marcas
// random que fueron apareciendo en búsquedas puntuales.
$alimentos = $db->query(
    "SELECT nombre, categoria, calorias_por_100g FROM alimentos WHERE fuente = 'local' ORDER BY categoria, nombre"
)->fetchAll();
$alimentos = array_filter(
    $alimentos,
    fn ($a) => FiltroAlimentario::esCompatible($a['nombre'], $a['categoria'], $perfilAlimentario['tipo_dieta'], $perfilAlimentario['alergias'])
);

$lista = [];
foreach ($alimentos as $a) {
    // Base semanal aproximada por categoría (gramos), escalada por la meta calórica.
    $baseGramos = match ($a['categoria']) {
        'proteína'  => 700,
        'cereal'    => 500,
        'vegetal'   => 600,
        'fruta'     => 500,
        'legumbre'  => 400,
        'lácteo'    => 500,
        default     => 300,
    };

    $lista[] = [
        'alimento'       => $a['nombre'],
        'categoria'      => $a['categoria'],
        'cantidad_estimada_g' => (int) round($baseGramos * $factorEscala),
    ];
}

respond(true, [
    'meta_calorias_referencia' => (int) $metaCalorias,
    'lista_compras' => $lista,
], 'Lista de compras generada para la semana.');
