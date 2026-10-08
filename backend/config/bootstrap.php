<?php
/**
 * NutriFit — Bootstrap común para todos los endpoints de la API.
 * Se incluye al inicio de cada archivo en /backend/api/...
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar errores crudos en producción/API

// Zona horaria ÚNICA de la aplicación (NutriFit opera en Argentina). Es la
// referencia tanto para PHP como para MySQL: date_default_timezone_set()
// alinea date()/DateTime/DateTimeImmutable acá, y Database (ver database.php)
// sincroniza la sesión de MySQL al MISMO offset derivado de esta zona, para
// que NOW()/CURDATE() del servidor y el "hoy" de PHP SIEMPRE coincidan.
// Sin esto, una actividad hecha a las 23:30 podía quedar contada en el día
// siguiente (o romper la racha) porque PHP y MySQL discrepaban de día.
const APP_TIMEZONE = 'America/Argentina/Buenos_Aires';
date_default_timezone_set(APP_TIMEZONE);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // ajustar en producción a tu dominio
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
// HTTPS directo o túnel local confiable (ngrok). No confiar en proxies remotos arbitrarios.
$proxyLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
// En Heroku (variable DYNO) el router termina TLS y el buildpack no expone HTTPS a PHP:
// se confía en X-Forwarded-Proto sólo ahí, nunca en otros entornos.
$proxyHeroku = getenv('DYNO') !== false;
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($proxyLocal || $proxyHeroku) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'secure'   => $https,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/env.php';

/** Envía una respuesta JSON estandarizada y termina la ejecución. */
function respond(bool $success, mixed $data = null, string $message = '', int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lee y decodifica el body JSON de la petición. */
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** Corta la ejecución si no hay sesión activa (rutas protegidas). */
function requireAuth(): int
{
    if (empty($_SESSION['usuario_id'])) {
        respond(false, null, 'No autorizado. Inicia sesión nuevamente.', 401);
    }
    return (int) $_SESSION['usuario_id'];
}
