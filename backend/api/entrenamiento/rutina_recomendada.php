<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$stmt = $db->prepare('SELECT objetivo, lugar_entreno FROM perfiles_biometricos WHERE usuario_id = :uid');
$stmt->execute(['uid' => $usuarioId]);
$perfil = $stmt->fetch();

if (!$perfil) {
    respond(false, null, 'Completa primero el cuestionario de onboarding.', 404);
}

$stmtRutina = $db->prepare(
    'SELECT * FROM rutinas_ejercicios WHERE objetivo = :obj AND lugar = :lugar LIMIT 1'
);
$stmtRutina->execute(['obj' => $perfil['objetivo'], 'lugar' => $perfil['lugar_entreno']]);
$rutina = $stmtRutina->fetch();

// Si no hay coincidencia exacta de objetivo, cae a cualquier rutina del lugar preferido.
if (!$rutina) {
    $stmtFallback = $db->prepare('SELECT * FROM rutinas_ejercicios WHERE lugar = :lugar LIMIT 1');
    $stmtFallback->execute(['lugar' => $perfil['lugar_entreno']]);
    $rutina = $stmtFallback->fetch();
}

if (!$rutina) {
    respond(false, null, 'No se encontraron rutinas disponibles.', 404);
}

$stmtEjercicios = $db->prepare('SELECT * FROM ejercicios WHERE rutina_id = :rid ORDER BY orden ASC');
$stmtEjercicios->execute(['rid' => $rutina['id']]);
$rutina['ejercicios'] = $stmtEjercicios->fetchAll();

// Indica si ya se completó hoy.
$stmtHoy = $db->prepare('SELECT id FROM registros_entrenamiento WHERE usuario_id = :uid AND rutina_id = :rid AND fecha = CURDATE()');
$stmtHoy->execute(['uid' => $usuarioId, 'rid' => $rutina['id']]);
$rutina['completada_hoy'] = (bool) $stmtHoy->fetch();

respond(true, $rutina);
