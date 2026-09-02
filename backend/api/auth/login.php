<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/Usuario.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();
$email = trim(strtolower($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($email === '' || $password === '') {
    respond(false, null, 'Email y contraseña son obligatorios.', 422);
}

$db = (new Database())->getConnection();
$usuarioModel = new Usuario($db);

$usuario = $usuarioModel->verificarCredenciales($email, $password);

if (!$usuario) {
    respond(false, null, 'Email o contraseña incorrectos.', 401);
}

// Verifica si el usuario ya completó el onboarding (tiene perfil biométrico).
$stmt = $db->prepare('SELECT id FROM perfiles_biometricos WHERE usuario_id = :id LIMIT 1');
$stmt->execute(['id' => $usuario['id']]);
$onboardingCompleto = (bool) $stmt->fetch();

$_SESSION['usuario_id'] = $usuario['id'];
$_SESSION['usuario_nombre'] = $usuario['nombre'];

respond(true, [
    'usuario' => $usuario,
    'onboarding_completo' => $onboardingCompleto,
], 'Sesión iniciada correctamente.');
