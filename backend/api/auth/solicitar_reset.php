<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

/**
 * Solicita un reset de contraseña.
 *
 * IMPORTANTE — limitación conocida: este proyecto no tiene un servicio de
 * envío de emails configurado (necesitaría SMTP real: Gmail/SendGrid/etc.
 * con credenciales propias). Por eso, en vez de mandar un mail, esta ruta
 * devuelve el link de reset directo en la respuesta (como ya se hace con
 * el "análisis de foto" simulado en nutricion/analizar_foto.php). Antes de
 * llevar esto a producción hay que reemplazar el bloque marcado abajo por
 * un envío real (ej. PHPMailer + SMTP, o una API tipo Resend/SendGrid).
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();
$email = trim(strtolower($body['email'] ?? ''));

if ($email === '') {
    respond(false, null, 'Ingresá tu email.', 422);
}

$db = (new Database())->getConnection();

$stmt = $db->prepare('SELECT id FROM usuarios WHERE email = :email');
$stmt->execute(['email' => $email]);
$usuario = $stmt->fetch();

// No revela si el email existe o no (evita que alguien use esto para
// averiguar qué emails están registrados).
if (!$usuario) {
    respond(true, null, 'Si el email existe, vas a poder resetear tu contraseña.');
}

$token = bin2hex(random_bytes(32));

$db->prepare(
    'UPDATE usuarios SET reset_token = :token, reset_token_expira = DATE_ADD(NOW(), INTERVAL 30 MINUTE)
     WHERE id = :id'
)->execute(['token' => $token, 'id' => $usuario['id']]);

// --- INICIO bloque a reemplazar por un envío de email real en producción ---
$linkReset = "frontend/index.html?reset_token={$token}";
error_log("[NutriFit] Link de reset para {$email}: {$linkReset}");
// --- FIN bloque simulado ---

respond(true, [
    'link_reset_dev' => $linkReset,
    'nota' => 'Modo desarrollo: no hay email configurado, así que el link se devuelve acá directamente.',
], 'Si el email existe, vas a poder resetear tu contraseña.');
