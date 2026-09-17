<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/RegistroActividadRepository.php';
require_once __DIR__ . '/../../classes/CalculadoraActividad.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

/**
 * Wrapper de compatibilidad: el frontend actual (pedómetro por
 * acelerómetro + carga manual rápida de "+500 pasos") sigue llamando a
 * este mismo endpoint con el mismo contrato de siempre. Por dentro ya no
 * usa la tabla vieja `registros_pasos` — lee y escribe en
 * `registros_actividad` (tipo "caminata", vía el upsert de
 * RegistroActividadRepository::sumarPasosLocal) para que no haya dos
 * fuentes de verdad. `registros_pasos` queda como respaldo histórico,
 * sin que nada vuelva a escribirle.
 */

$usuarioId = requireAuth();
$db = (new Database())->getConnection();
$repo = new RegistroActividadRepository($db);
$tipoCaminata = $repo->obtenerTipoPorClave('caminata');

/** Mismo shape de siempre: {pasos, meta_pasos, km_recorridos, kcal_quemadas}, mirando solo caminata+carrera (no ciclismo). */
function resumenPasosCompat(RegistroActividadRepository $repo, int $usuarioId, string $fecha): array
{
    $resumen = $repo->resumenDia($usuarioId, $fecha);
    $porTipo = $resumen['por_tipo'];
    $distanciaMetros = (float) ($porTipo['caminata']['distancia_metros'] ?? 0) + (float) ($porTipo['carrera']['distancia_metros'] ?? 0);
    $caloriasKcal = (float) ($porTipo['caminata']['calorias_kcal'] ?? 0) + (float) ($porTipo['carrera']['calorias_kcal'] ?? 0);

    return [
        'pasos' => $resumen['pasos'],
        'meta_pasos' => $repo->obtenerMetaPasos($usuarioId),
        'km_recorridos' => round($distanciaMetros / 1000, 2),
        'kcal_quemadas' => (int) round($caloriasKcal),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(true, resumenPasosCompat($repo, $usuarioId, date('Y-m-d')));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getJsonBody();
    $cantidad = (int) ($body['cantidad'] ?? 0);

    if ($cantidad <= 0 || $cantidad > 100000) {
        respond(false, null, 'Cantidad de pasos inválida.', 422);
    }

    $fecha = date('Y-m-d');
    $metaPasos = $repo->obtenerMetaPasos($usuarioId);
    $antes = resumenPasosCompat($repo, $usuarioId, $fecha);
    $yaHabiaCumplidoMeta = $antes['pasos'] >= $metaPasos;

    $alturaPeso = $repo->obtenerAlturaPeso($usuarioId);
    $distanciaAdicional = CalculadoraActividad::distanciaDesdePasos($cantidad, $alturaPeso['altura']);
    // Duración desconocida para pasos sueltos del pedómetro: se usa la
    // fórmula liviana de siempre (kcal por paso y por kg) en vez del MET
    // por duración, que necesitaría un tiempo que acá no existe.
    $caloriasAdicionales = round($cantidad * $alturaPeso['peso'] * 0.0005, 1);

    $repo->sumarPasosLocal($usuarioId, $fecha, (int) $tipoCaminata['id'], 'sensor_web', $cantidad, $distanciaAdicional, $caloriasAdicionales);

    $registro = resumenPasosCompat($repo, $usuarioId, $fecha);

    $xpOtorgado = null;
    $cumplioMetaAhora = $registro['pasos'] >= $metaPasos;
    if ($cumplioMetaAhora && !$yaHabiaCumplidoMeta) {
        $xpSystem = new SistemaXP($db);
        $xpOtorgado = $xpSystem->otorgarXP($usuarioId, SistemaXP::XP_PASOS);
    }

    respond(true, [
        'registro' => $registro,
        'xp' => $xpOtorgado,
    ], 'Pasos registrados.');
}

respond(false, null, 'Método no permitido.', 405);
