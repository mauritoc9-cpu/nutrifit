<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Muestra tanto las solicitudes que YO envié como las que ME enviaron
    // (antes sólo se veían las enviadas, así que nadie podía aceptar nada).
    // Con PDO::ATTR_EMULATE_PREPARES=false, MySQL no permite reutilizar el
    // mismo parámetro con nombre más de una vez: se repite con alias distintos.
    $stmt = $db->prepare(
        'SELECT ar.id AS relacion_id, ar.estado,
                (ar.usuario_id = :uid1) AS enviada_por_mi,
                u.id AS amigo_id, u.nombre, u.nivel, u.xp_total
         FROM amigos_ranking ar
         JOIN usuarios u ON u.id = IF(ar.usuario_id = :uid2, ar.amigo_id, ar.usuario_id)
         WHERE ar.usuario_id = :uid3 OR ar.amigo_id = :uid4
         ORDER BY ar.estado ASC, u.xp_total DESC'
    );
    $stmt->execute(['uid1' => $usuarioId, 'uid2' => $usuarioId, 'uid3' => $usuarioId, 'uid4' => $usuarioId]);
    respond(true, $stmt->fetchAll());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getJsonBody();
    $accion = $body['accion'] ?? '';

    if ($accion === 'agregar') {
        $emailAmigo = trim(strtolower($body['email'] ?? ''));
        $stmtBuscar = $db->prepare('SELECT id FROM usuarios WHERE email = :email');
        $stmtBuscar->execute(['email' => $emailAmigo]);
        $amigo = $stmtBuscar->fetch();

        if (!$amigo) {
            respond(false, null, 'No se encontró un usuario con ese email.', 404);
        }
        if ((int) $amigo['id'] === $usuarioId) {
            respond(false, null, 'No puedes agregarte a ti mismo.', 422);
        }

        $db->prepare(
            'INSERT IGNORE INTO amigos_ranking (usuario_id, amigo_id, estado) VALUES (:uid, :aid, "pendiente")'
        )->execute(['uid' => $usuarioId, 'aid' => $amigo['id']]);

        respond(true, null, 'Solicitud de amistad enviada.', 201);
    }

    if ($accion === 'aceptar') {
        $relacionId = (int) ($body['relacion_id'] ?? 0);
        $stmt = $db->prepare('UPDATE amigos_ranking SET estado = "aceptado" WHERE id = :id AND amigo_id = :uid');
        $stmt->execute(['id' => $relacionId, 'uid' => $usuarioId]);
        if ($stmt->rowCount() === 0) {
            respond(false, null, 'No se encontró esa solicitud.', 404);
        }
        respond(true, null, 'Solicitud aceptada.');
    }

    if ($accion === 'rechazar') {
        $relacionId = (int) ($body['relacion_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM amigos_ranking WHERE id = :id AND amigo_id = :uid AND estado = "pendiente"');
        $stmt->execute(['id' => $relacionId, 'uid' => $usuarioId]);
        if ($stmt->rowCount() === 0) {
            respond(false, null, 'No se encontró esa solicitud.', 404);
        }
        respond(true, null, 'Solicitud rechazada.');
    }

    respond(false, null, 'Acción no reconocida.', 422);
}

respond(false, null, 'Método no permitido.', 405);
