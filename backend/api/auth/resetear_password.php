<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Método no permitido.', 405);
$body = getJsonBody();
$token = (string) ($body['token'] ?? '');
$password = (string) ($body['password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/D', $token) || strlen($password) < 8) respond(false, null, 'Token inválido o contraseña demasiado corta (mínimo 8 caracteres).', 422);
$db = (new Database())->getConnection();
require_once __DIR__ . '/../../classes/PasswordResetTokens.php';
if (!(new PasswordResetTokens($db))->consumir($token, password_hash($password, PASSWORD_BCRYPT))) respond(false, null, 'El link de recuperación es inválido o ya expiró.', 422);
$_SESSION = [];
session_regenerate_id(true);
respond(true, null, 'Contraseña actualizada. Ya podés iniciar sesión.');
