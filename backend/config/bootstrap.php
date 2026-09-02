<?php
/**
 * NutriFit — Bootstrap común para todos los endpoints de la API.
 * Se incluye al inicio de cada archivo en /backend/api/...
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // nunca mostrar errores crudos en producción/API

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // ajustar en producción a tu dominio
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

require_once __DIR__ . '/database.php';

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
