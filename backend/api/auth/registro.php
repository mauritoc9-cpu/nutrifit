<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/Usuario.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();
$nombre = trim($body['nombre'] ?? '');
$email = trim(strtolower($body['email'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($nombre === '' || $email === '' || $password === '') {
    respond(false, null, 'Nombre, email y contraseña son obligatorios.', 422);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, null, 'El email no tiene un formato válido.', 422);
}

if (strlen($password) < 8) {
    respond(false, null, 'La contraseña debe tener al menos 8 caracteres.', 422);
}

$db = (new Database())->getConnection();
$usuarioModel = new Usuario($db);

if ($usuarioModel->emailExiste($email)) {
    respond(false, null, 'Ese email ya está registrado.', 409);
}

try {
    $id = $usuarioModel->registrar($nombre, $email, $password);

    // Otorga y equipa la remera oficial de NutriFit (ítem gratuito inicial).
    $db->prepare(
        "INSERT IGNORE INTO inventario_usuario (usuario_id, item_id, equipado)
         SELECT :uid, id, 1 FROM tienda_items WHERE nombre = 'Remera NutriFit' LIMIT 1"
    )->execute(['uid' => $id]);

    $_SESSION['usuario_id'] = $id;
    $_SESSION['usuario_nombre'] = $nombre;

    respond(true, ['id' => $id, 'nombre' => $nombre, 'email' => $email], 'Cuenta creada correctamente.', 201);
} catch (Throwable $e) {
    respond(false, null, 'No se pudo crear la cuenta.', 500);
}
