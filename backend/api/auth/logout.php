<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Método no permitido.', 405);
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
respond(true, null, 'Sesión cerrada.');
