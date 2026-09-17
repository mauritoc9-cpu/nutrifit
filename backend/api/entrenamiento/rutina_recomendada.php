<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
require_once __DIR__ . '/../../classes/personalizacion/MotorPersonalizacion.php';
require_once __DIR__ . '/../../classes/entrenamiento/GeneradorRutinasPersonalizadas.php';

/**
 * Rutina recomendada del día.
 *
 * Evolución (no reemplazo) del comportamiento anterior:
 *   1. Se arma el PlanPersonalizacion del usuario.
 *   2. Se intenta generar/reutilizar una rutina PERSONALIZADA a partir
 *      del catálogo (objetivo + lugar + nivel + condiciones).
 *   3. Si el catálogo no alcanza, se cae a la plantilla estática de
 *      siempre (match por objetivo+lugar, con fallback por lugar).
 *
 * El contrato de respuesta se mantiene: id, titulo, ejercicios[] con
 * {id, nombre, series, repeticiones} y completada_hoy. Se agregan campos
 * nuevos (personalizada, enfoque, nivel, descanso, metadata de catálogo)
 * que el frontend actual simplemente ignora.
 */

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$contexto = (new ContextoUsuarioRepository($db))->obtenerConHistorial($usuarioId, 7);
$plan = (new MotorPersonalizacion())->generar($contexto);

// Sin onboarding no hay rutina: se conserva EXACTAMENTE la respuesta
// histórica. Generar un plan personalizado para alguien que todavía no
// declaró objetivo ni lugar sería inventarle un perfil, y además el
// frontend ya muestra el mensaje de "completá el onboarding" ante este
// mismo 404.
if (!$contexto->perfilCompleto) {
    respond(false, null, 'Completa primero el cuestionario de onboarding.', 404);
}

$personalizada = false;

// --- 1. Rutina personalizada desde el catálogo ---
$rutina = (new GeneradorRutinasPersonalizadas($db))->obtenerOGenerar($usuarioId, $plan);
if ($rutina !== null) {
    $personalizada = true;
}

// --- 2. Fallback: plantillas estáticas (comportamiento histórico) ---
if ($rutina === null) {
    $lugar = $contexto->lugarEntreno ?? 'casa';

    $stmtRutina = $db->prepare(
        "SELECT * FROM rutinas_ejercicios
         WHERE objetivo = :obj AND lugar = :lugar AND (usuario_id IS NULL OR usuario_id = :uid)
         ORDER BY usuario_id IS NULL DESC LIMIT 1"
    );
    $stmtRutina->execute(['obj' => $contexto->objetivo, 'lugar' => $lugar, 'uid' => $usuarioId]);
    $rutina = $stmtRutina->fetch();

    if (!$rutina) {
        $stmtFallback = $db->prepare(
            "SELECT * FROM rutinas_ejercicios
             WHERE lugar = :lugar AND usuario_id IS NULL LIMIT 1"
        );
        $stmtFallback->execute(['lugar' => $lugar]);
        $rutina = $stmtFallback->fetch();
    }

    if (!$rutina) {
        respond(false, null, 'No se encontraron rutinas disponibles.', 404);
    }

    $stmtEjercicios = $db->prepare(
        'SELECT e.*, c.clave AS catalogo_clave, c.grupo_muscular, c.equipamiento, c.tipo
         FROM ejercicios e
         LEFT JOIN ejercicios_catalogo c ON c.id = e.catalogo_id
         WHERE e.rutina_id = :rid ORDER BY e.orden ASC'
    );
    $stmtEjercicios->execute(['rid' => $rutina['id']]);
    $rutina['ejercicios'] = $stmtEjercicios->fetchAll();
}

// Indica si ya se completó hoy (idéntico a antes).
$stmtHoy = $db->prepare('SELECT id FROM registros_entrenamiento WHERE usuario_id = :uid AND rutina_id = :rid AND fecha = CURDATE()');
$stmtHoy->execute(['uid' => $usuarioId, 'rid' => $rutina['id']]);
$rutina['completada_hoy'] = (bool) $stmtHoy->fetch();

// --- Campos nuevos (aditivos) ---
$rutina['personalizada'] = $personalizada;
$rutina['enfoque'] = $plan->entrenamiento['enfoque'];
$rutina['nivel_sugerido'] = $plan->entrenamiento['nivel_sugerido'];
$rutina['frecuencia_semanal_sugerida'] = $plan->entrenamiento['frecuencia_semanal_sugerida'];
$rutina['intensidad_conservadora'] = $plan->entrenamiento['intensidad_conservadora'];
$rutina['motivo_personalizacion'] = $personalizada
    ? 'generada_desde_catalogo_segun_plan'
    : 'plantilla_base_sin_personalizacion';

respond(true, $rutina);
