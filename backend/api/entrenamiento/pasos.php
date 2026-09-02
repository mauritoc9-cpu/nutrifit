<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

/**
 * Estimaciones estándar (no requieren wearables):
 *  - Largo de zancada ≈ altura_cm * 0.415 (fórmula biomecánica habitual).
 *  - Gasto calórico ≈ 0.0005 kcal por paso y por kg de peso corporal
 *    (equivale a ~300-400 kcal cada 10.000 pasos para un adulto promedio).
 * Si el usuario todavía no completó el onboarding, se usan valores
 * de referencia (170cm / 70kg) para no romper el cálculo.
 */
function calcularKmYKcal(PDO $db, int $usuarioId, int $pasos): array
{
    $stmt = $db->prepare('SELECT altura, peso_actual FROM perfiles_biometricos WHERE usuario_id = :uid');
    $stmt->execute(['uid' => $usuarioId]);
    $perfil = $stmt->fetch();

    $alturaCm = $perfil ? (float) $perfil['altura'] : 170.0;
    $pesoKg = $perfil ? (float) $perfil['peso_actual'] : 70.0;

    $zancadaM = $alturaCm * 0.00415;
    $km = round(($pasos * $zancadaM) / 1000, 2);
    $kcal = (int) round($pasos * $pesoKg * 0.0005);

    return ['km_recorridos' => $km, 'kcal_quemadas' => $kcal];
}

function obtenerRegistroHoy(PDO $db, int $usuarioId): array
{
    $stmt = $db->prepare('SELECT pasos, meta_pasos FROM registros_pasos WHERE usuario_id = :uid AND fecha = CURDATE()');
    $stmt->execute(['uid' => $usuarioId]);
    return $stmt->fetch() ?: ['pasos' => 0, 'meta_pasos' => 10000];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $registro = obtenerRegistroHoy($db, $usuarioId);
    respond(true, array_merge($registro, calcularKmYKcal($db, $usuarioId, (int) $registro['pasos'])));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getJsonBody();
    $cantidad = (int) ($body['cantidad'] ?? 0);

    if ($cantidad <= 0 || $cantidad > 100000) {
        respond(false, null, 'Cantidad de pasos inválida.', 422);
    }

    $registroAntes = obtenerRegistroHoy($db, $usuarioId);
    $yaHabiaCumplidoMeta = (int) $registroAntes['pasos'] >= (int) $registroAntes['meta_pasos'];

    $db->prepare(
        'INSERT INTO registros_pasos (usuario_id, fecha, pasos)
         VALUES (:uid, CURDATE(), :cantidad)
         ON DUPLICATE KEY UPDATE pasos = pasos + VALUES(pasos)'
    )->execute(['uid' => $usuarioId, 'cantidad' => $cantidad]);

    $registro = obtenerRegistroHoy($db, $usuarioId);

    $xpOtorgado = null;
    $cumplioMetaAhora = (int) $registro['pasos'] >= (int) $registro['meta_pasos'];
    if ($cumplioMetaAhora && !$yaHabiaCumplidoMeta) {
        $xpSystem = new SistemaXP($db);
        $xpOtorgado = $xpSystem->otorgarXP($usuarioId, SistemaXP::XP_PASOS);
    }

    respond(true, [
        'registro' => array_merge($registro, calcularKmYKcal($db, $usuarioId, (int) $registro['pasos'])),
        'xp' => $xpOtorgado,
    ], 'Pasos registrados.');
}

respond(false, null, 'Método no permitido.', 405);
