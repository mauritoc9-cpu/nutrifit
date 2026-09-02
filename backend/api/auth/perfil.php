<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$stmt = $db->prepare('SELECT * FROM perfiles_biometricos WHERE usuario_id = :uid');
$stmt->execute(['uid' => $usuarioId]);
$perfil = $stmt->fetch();

if (!$perfil) {
    respond(false, null, 'Todavía no completaste el onboarding.', 404);
}

$perfil['alergias'] = json_decode($perfil['alergias'] ?? '[]', true) ?: [];

respond(true, $perfil);
