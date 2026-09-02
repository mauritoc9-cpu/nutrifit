<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT peso_registrado, fecha FROM progreso_peso WHERE usuario_id = :uid ORDER BY fecha ASC');
    $stmt->execute(['uid' => $usuarioId]);
    $historial = $stmt->fetchAll();

    $stmtObjetivo = $db->prepare('SELECT peso_actual, peso_objetivo FROM perfiles_biometricos WHERE usuario_id = :uid');
    $stmtObjetivo->execute(['uid' => $usuarioId]);
    $metas = $stmtObjetivo->fetch();

    respond(true, ['historial' => $historial, 'metas' => $metas]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getJsonBody();
    $peso = (float) ($body['peso'] ?? 0);

    if ($peso <= 0) {
        respond(false, null, 'Peso inválido.', 422);
    }

    $db->prepare(
        'INSERT INTO progreso_peso (usuario_id, peso_registrado, fecha) VALUES (:uid, :peso, CURDATE())
         ON DUPLICATE KEY UPDATE peso_registrado = :peso2'
    )->execute(['uid' => $usuarioId, 'peso' => $peso, 'peso2' => $peso]);

    $db->prepare('UPDATE perfiles_biometricos SET peso_actual = :peso WHERE usuario_id = :uid')
       ->execute(['peso' => $peso, 'uid' => $usuarioId]);

    respond(true, null, 'Peso actualizado correctamente.', 201);
}

respond(false, null, 'Método no permitido.', 405);
