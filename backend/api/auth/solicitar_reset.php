<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/bootstrap.php';
// Sin proveedor configurado no se emiten tokens. Un futuro transport debe
// entregar el secreto sólo por correo y persistir únicamente SHA-256.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false, null, 'Método no permitido.', 405);
$body = getJsonBody();
$email = trim(strtolower((string) ($body['email'] ?? '')));
// Misma respuesta independientemente de la existencia o validez del email.
respond(true, null, 'Si la cuenta existe, se enviarán instrucciones de recuperación cuando el servicio de correo esté disponible.');
