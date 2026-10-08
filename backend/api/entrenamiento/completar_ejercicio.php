<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

/**
 * POST   /api/entrenamiento/completar_ejercicio.php   {ejercicio_id, rutina_id}
 * DELETE /api/entrenamiento/completar_ejercicio.php    {ejercicio_id}
 *
 * Marca/desmarca UN ejercicio puntual de la rutina de hoy como hecho,
 * independiente del resto — antes esto vivía solo en un Set de JS que se
 * perdía al recargar y, por un selector roto, ni siquiera actualizaba la
 * fila tocada (ver ejercicios_completados en database/migrations).
 *
 * NO otorga XP. El XP de entrenamiento sigue siendo exclusivo de
 * completar_entrenamiento.php (rutina completa) — así que marcar y
 * desmarcar un ejercicio repetidamente no es una vía de farming, y el
 * INSERT IGNORE / UNIQUE KEY (usuario_id, ejercicio_id, fecha) hace que
 * marcarlo dos veces el mismo día no duplique nada igual.
 */

$usuarioId = requireAuth();
$db = (new Database())->getConnection();
$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'POST') {
    $body = getJsonBody();
    $ejercicioId = (int) ($body['ejercicio_id'] ?? 0);
    $rutinaId = (int) ($body['rutina_id'] ?? 0);

    if ($ejercicioId <= 0 || $rutinaId <= 0) {
        respond(false, null, 'ejercicio_id y rutina_id son obligatorios.', 422);
    }

    // El ejercicio tiene que pertenecer realmente a la rutina indicada
    // (evita que se puedan marcar ids arbitrarios que no son de hoy).
    $stmt = $db->prepare('SELECT e.id FROM ejercicios e JOIN rutinas_ejercicios r ON r.id = e.rutina_id WHERE e.id = :eid AND e.rutina_id = :rid AND (r.usuario_id IS NULL OR r.usuario_id = :uid)');
    $stmt->execute(['eid' => $ejercicioId, 'rid' => $rutinaId, 'uid' => $usuarioId]);
    if (!$stmt->fetch()) {
        respond(false, null, 'Ese ejercicio no pertenece a esa rutina.', 404);
    }

    $db->prepare(
        'INSERT IGNORE INTO ejercicios_completados (usuario_id, rutina_id, ejercicio_id, fecha)
         VALUES (:uid, :rid, :eid, CURDATE())'
    )->execute(['uid' => $usuarioId, 'rid' => $rutinaId, 'eid' => $ejercicioId]);

    respond(true, null, 'Ejercicio marcado.');
}

if ($metodo === 'DELETE') {
    $body = getJsonBody();
    $ejercicioId = (int) ($body['ejercicio_id'] ?? 0);

    if ($ejercicioId <= 0) {
        respond(false, null, 'ejercicio_id es obligatorio.', 422);
    }

    $db->prepare(
        'DELETE FROM ejercicios_completados WHERE usuario_id = :uid AND ejercicio_id = :eid AND fecha = CURDATE()'
    )->execute(['uid' => $usuarioId, 'eid' => $ejercicioId]);

    respond(true, null, 'Ejercicio desmarcado.');
}

respond(false, null, 'Método no permitido.', 405);
