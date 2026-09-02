<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();
$token = trim((string) ($body['token'] ?? ''));
$passwordNueva = (string) ($body['password'] ?? '');

if ($token === '' || strlen($passwordNueva) < 8) {
    respond(false, null, 'Token inválido o contraseña demasiado corta (mínimo 8 caracteres).', 422);
}

$db = (new Database())->getConnection();

$stmt = $db->prepare(
    'SELECT id FROM usuarios WHERE reset_token = :token AND reset_token_expira > NOW()'
);
$stmt->execute(['token' => $token]);
$usuario = $stmt->fetch();

if (!$usuario) {
    respond(false, null, 'El link de recuperación es inválido o ya expiró. Solicitá uno nuevo.', 422);
}

$hash = password_hash($passwordNueva, PASSWORD_BCRYPT);

$db->prepare(
    'UPDATE usuarios SET password = :password, reset_token = NULL, reset_token_expira = NULL WHERE id = :id'
)->execute(['password' => $hash, 'id' => $usuario['id']]);

respond(true, null, 'Contraseña actualizada. Ya podés iniciar sesión.');
