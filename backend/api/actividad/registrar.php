<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/RegistroActividadRepository.php';
require_once __DIR__ . '/../../classes/CalculadoraActividad.php';
require_once __DIR__ . '/../../classes/CriteriosActividadXP.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

/**
 * Registra UNA sesión de actividad física (caminata/carrera/ciclismo hoy;
 * gimnasio/fútbol/pádel/etc. el día que se agreguen a `tipos_actividad`,
 * sin tocar este archivo). Es el endpoint genérico que también va a usar
 * la futura app React Native/Expo para sincronizar sesiones reales de
 * Health Connect/HealthKit — por eso el payload ya contempla `fuente` y
 * `fuente_registro_id` desde ahora, aunque hoy solo se use `manual`.
 *
 * Body esperado:
 *   tipo                 string  obligatorio  clave de tipos_actividad ("caminata"|"carrera"|"ciclismo")
 *   duracion_segundos    int     obligatorio  > 0
 *   distancia_metros     float   opcional     si el tipo la usa
 *   pasos                int     opcional     solo si el tipo usa pasos (se ignora si no aplica)
 *   fecha                string  opcional     "YYYY-MM-DD", default hoy
 *   hora_inicio          string  opcional     "YYYY-MM-DD HH:MM:SS"
 *   fuente               string  opcional     'manual' (default) | 'sensor_web' | 'health_connect' | 'healthkit'
 *   fuente_registro_id   string  requerido SI fuente es health_connect/healthkit (para deduplicar al sincronizar)
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$db = (new Database())->getConnection();
$repo = new RegistroActividadRepository($db);

$body = getJsonBody();

$claveTipo = trim((string) ($body['tipo'] ?? ''));
$tipo = $claveTipo !== '' ? $repo->obtenerTipoPorClave($claveTipo) : null;
if ($tipo === null) {
    respond(false, null, 'Tipo de actividad inválido o inactivo.', 422);
}

$duracionSegundos = (int) ($body['duracion_segundos'] ?? 0);
if ($duracionSegundos <= 0 || $duracionSegundos > 86400) {
    respond(false, null, 'La duración es obligatoria y debe ser un valor razonable (en segundos).', 422);
}

$fecha = (string) ($body['fecha'] ?? date('Y-m-d'));
if (!DateTime::createFromFormat('Y-m-d', $fecha)) {
    respond(false, null, 'Formato de fecha inválido. Usá YYYY-MM-DD.', 422);
}

$fuente = (string) ($body['fuente'] ?? 'manual');
if (!in_array($fuente, ['manual', 'sensor_web', 'health_connect', 'healthkit'], true)) {
    respond(false, null, 'Fuente inválida.', 422);
}

$fuenteRegistroId = trim((string) ($body['fuente_registro_id'] ?? ''));
if (in_array($fuente, ['health_connect', 'healthkit'], true) && $fuenteRegistroId === '') {
    // Sin id externo no hay forma de deduplicar una sincronización repetida.
    respond(false, null, 'fuente_registro_id es obligatorio para sesiones sincronizadas desde el celular.', 422);
}

// Idempotencia: si esta sesión externa ya se guardó antes (reintento de
// sync, doble tap, lo que sea), se responde con la que ya existe en vez
// de insertar de nuevo o de otorgar XP dos veces.
if ($fuenteRegistroId !== '') {
    $existente = $repo->buscarPorFuenteExterna($usuarioId, $fuente, $fuenteRegistroId);
    if ($existente !== null) {
        respond(true, ['registro' => $existente, 'xp' => null, 'ya_existia' => true], 'Esta sesión ya estaba registrada.');
    }
}

$distanciaMetros = isset($body['distancia_metros']) && is_numeric($body['distancia_metros']) ? (float) $body['distancia_metros'] : null;
$pasos = ((bool) $tipo['usa_pasos']) && isset($body['pasos']) && is_numeric($body['pasos']) ? max(0, (int) $body['pasos']) : null;

// Si el tipo usa pasos y no vino distancia pero sí pasos, se estima la
// distancia — igual criterio que ya usaba `pasos.php`. Nunca se inventan
// pasos para un tipo que no los usa (ej. ciclismo).
if ($distanciaMetros === null && $pasos !== null && (bool) $tipo['usa_distancia']) {
    $alturaPeso = $repo->obtenerAlturaPeso($usuarioId);
    $distanciaMetros = CalculadoraActividad::distanciaDesdePasos($pasos, $alturaPeso['altura']);
}

$alturaPeso = $repo->obtenerAlturaPeso($usuarioId);
$caloriasKcal = CalculadoraActividad::calcularCalorias((float) $tipo['met_valor'], $alturaPeso['peso'], $duracionSegundos);

$velocidadMediaKmh = ((bool) $tipo['usa_velocidad'] && $distanciaMetros !== null)
    ? CalculadoraActividad::velocidadMediaKmh($distanciaMetros, $duracionSegundos)
    : null;

$ritmoSegPorKm = ((bool) $tipo['usa_ritmo'] && $distanciaMetros !== null)
    ? CalculadoraActividad::ritmoSegPorKm($distanciaMetros, $duracionSegundos)
    : null;

// Aggregate de pasos ANTES de insertar esta sesión, para detectar si la
// meta diaria se cruza recién con esta sesión (evita re-otorgar XP si ya
// se había cumplido antes hoy, sea por acá o por el pedómetro/pasos.php).
$metaPasos = $repo->obtenerMetaPasos($usuarioId);
$resumenAntes = $repo->resumenDia($usuarioId, $fecha);
$yaHabiaCumplidoMetaPasos = $resumenAntes['pasos'] >= $metaPasos;

try {
    $registroId = $repo->insertarSesion([
        'usuario_id' => $usuarioId,
        'tipo_actividad_id' => $tipo['id'],
        'fecha' => $fecha,
        'hora_inicio' => $body['hora_inicio'] ?? null,
        'duracion_segundos' => $duracionSegundos,
        'pasos' => $pasos,
        'distancia_metros' => $distanciaMetros,
        'velocidad_media_kmh' => $velocidadMediaKmh,
        'ritmo_seg_por_km' => $ritmoSegPorKm,
        'calorias_kcal' => $caloriasKcal,
        'fuente' => $fuente,
        'fuente_registro_id' => $fuenteRegistroId !== '' ? $fuenteRegistroId : null,
    ]);
} catch (PDOException $e) {
    // Carrera contra otro request con el mismo id externo (doble tap muy
    // rápido) — la UNIQUE key de la tabla es la última línea de defensa.
    if ((int) $e->getCode() === 23000 && $fuenteRegistroId !== '') {
        $existente = $repo->buscarPorFuenteExterna($usuarioId, $fuente, $fuenteRegistroId);
        if ($existente !== null) {
            respond(true, ['registro' => $existente, 'xp' => null, 'ya_existia' => true], 'Esta sesión ya estaba registrada.');
        }
    }
    error_log('[actividad/registrar] Error al insertar sesión: ' . $e->getMessage());
    respond(false, null, 'No se pudo registrar la actividad.', 500);
}

$xpSistema = new SistemaXP($db);
$xpResultado = null;

// XP #1: sesión válida (duración/distancia mínima por tipo, y tope de
// sesiones-con-XP por día para que dividir una actividad en sesiones
// chicas no sirva para juntar XP de más).
$sesionesConXPHoy = $repo->contarSesionesConXPHoy($usuarioId, $fecha);
if (
    CriteriosActividadXP::esSesionValida($tipo['clave'], $duracionSegundos, $distanciaMetros)
    && $sesionesConXPHoy < CriteriosActividadXP::MAX_SESIONES_XP_POR_DIA
) {
    $xpResultado = $xpSistema->otorgarXP($usuarioId, SistemaXP::XP_ACTIVIDAD_SESION);
    $repo->marcarXPOtorgado($registroId);
}

// XP #2: meta diaria de pasos — solo si esta sesión aporta pasos y recién
// ahora se cruza la meta (no se había cruzado antes hoy).
if ($pasos !== null) {
    $resumenDespues = $repo->resumenDia($usuarioId, $fecha);
    if ($resumenDespues['pasos'] >= $metaPasos && !$yaHabiaCumplidoMetaPasos) {
        $xpPasos = $xpSistema->otorgarXP($usuarioId, SistemaXP::XP_PASOS);
        // Si también hubo XP de sesión en esta misma llamada, se informan
        // los dos por separado en vez de perder uno.
        $xpResultado = $xpResultado === null ? $xpPasos : ['sesion' => $xpResultado, 'meta_pasos' => $xpPasos];
    }
}

respond(true, [
    'registro' => [
        'id' => $registroId,
        'tipo' => $tipo['clave'],
        'fecha' => $fecha,
        'duracion_segundos' => $duracionSegundos,
        'pasos' => $pasos,
        'distancia_metros' => $distanciaMetros,
        'velocidad_media_kmh' => $velocidadMediaKmh,
        'ritmo_seg_por_km' => $ritmoSegPorKm,
        'calorias_kcal' => $caloriasKcal,
        'fuente' => $fuente,
    ],
    'xp' => $xpResultado,
], 'Actividad registrada.', 201);
