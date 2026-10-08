<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';
require_once __DIR__ . '/../../classes/gamificacion/GamificacionService.php';
require_once __DIR__ . '/../../classes/personalizacion/ContextoUsuarioRepository.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Método no permitido.', 405);
$uid = requireAuth();
$rid = (int) (getJsonBody()['rutina_id'] ?? 0);
$db = (new Database())->getConnection();
$ctx = (new ContextoUsuarioRepository($db))->obtenerConHistorial($uid, 7);
$stmt = $db->prepare('SELECT * FROM rutinas_ejercicios WHERE id = :id');
$stmt->execute(['id' => $rid]);
$rutina = $stmt->fetch();
if (!$ctx->perfilCompleto || !$rutina || ($rutina['usuario_id'] !== null && (int) $rutina['usuario_id'] !== $uid)
    || ($rutina['usuario_id'] === null && $rutina['lugar'] !== $ctx->lugarEntreno)) {
    respond(false, null, 'Rutina no disponible para tu usuario.', 403);
}
// Marcar ejercicios es progreso individual sin XP. Completar confirma una
// sesión del usuario; no exige marcar todos. Sólo una sesión/día premia XP.
$db->beginTransaction();
try {
    $stmt = $db->prepare('SELECT id FROM usuarios WHERE id = :uid FOR UPDATE');
    $stmt->execute(['uid' => $uid]);
    $stmt = $db->prepare('SELECT id FROM registros_entrenamiento WHERE usuario_id = :uid AND rutina_id = :rid AND fecha = CURDATE()');
    $stmt->execute(['uid' => $uid, 'rid' => $rid]);
    if ($stmt->fetch()) { $db->rollBack(); respond(false, null, 'Ya completaste esta rutina hoy.', 409); }
    $stmt = $db->prepare('SELECT COUNT(*) FROM registros_entrenamiento WHERE usuario_id = :uid AND fecha = CURDATE()');
    $stmt->execute(['uid' => $uid]);
    $yaPremiado = (int) $stmt->fetchColumn() > 0;
    $xp = $yaPremiado ? null : (new SistemaXP($db))->otorgarXPConLimite($uid, SistemaXP::XP_ENTRENAMIENTO, 'entrenamiento', 1);
    $db->prepare('INSERT INTO registros_entrenamiento (usuario_id, rutina_id, fecha, xp_ganado) VALUES (:uid, :rid, CURDATE(), :xp)')->execute(['uid' => $uid, 'rid' => $rid, 'xp' => $xp['xp_ganado'] ?? 0]);
    $gamificacion = (new GamificacionService($db))->reconciliar($uid, $xp);
    $db->commit();
    respond(true, ['xp' => $xp, 'gamificacion' => $gamificacion], $xp === null ? 'Entrenamiento registrado. Ya recibiste el XP diario.' : '¡Entrenamiento completado!');
} catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); respond(false, null, 'No se pudo registrar el entrenamiento.', 500); }
