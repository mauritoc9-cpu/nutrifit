<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$body = getJsonBody();
$rutinaId = (int) ($body['rutina_id'] ?? 0);

if ($rutinaId <= 0) {
    respond(false, null, 'rutina_id es obligatorio.', 422);
}

$db = (new Database())->getConnection();

$stmtCheck = $db->prepare('SELECT id FROM registros_entrenamiento WHERE usuario_id = :uid AND rutina_id = :rid AND fecha = CURDATE()');
$stmtCheck->execute(['uid' => $usuarioId, 'rid' => $rutinaId]);
if ($stmtCheck->fetch()) {
    respond(false, null, 'Ya completaste esta rutina hoy.', 409);
}

$db->prepare(
    'INSERT INTO registros_entrenamiento (usuario_id, rutina_id, fecha, xp_ganado) VALUES (:uid, :rid, CURDATE(), :xp)'
)->execute(['uid' => $usuarioId, 'rid' => $rutinaId, 'xp' => SistemaXP::XP_ENTRENAMIENTO]);

$xpSystem = new SistemaXP($db);
$resultadoXP = $xpSystem->otorgarXP($usuarioId, SistemaXP::XP_ENTRENAMIENTO);

respond(true, ['xp' => $resultadoXP], '¡Entrenamiento completado!');
