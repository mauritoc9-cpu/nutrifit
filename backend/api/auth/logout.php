<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
header('Cache-Control: no-store, private');
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
$port = parse_url($origin, PHP_URL_PORT);
if ($port !== null) $originHost .= ':' . $port;
if (($origin !== '' && $originHost !== strtolower($_SERVER['HTTP_HOST'] ?? '')) || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') respond(false, null, 'Origen no autorizado.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Método no permitido.', 405);
$_SESSION = [];
$params = session_get_cookie_params();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $params['path'], 'domain' => $params['domain'], 'secure' => $params['secure'], 'httponly' => true, 'samesite' => 'Lax']);
session_destroy();
respond(true, null, 'Sesión cerrada.');
