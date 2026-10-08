<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Obtener token del usuario autenticado
    $stmt = $db->prepare(
        'SELECT token_hash FROM perfiles_comparticiones WHERE usuario_id = :uid'
    );
    $stmt->execute(['uid' => $usuarioId]);
    $row = $stmt->fetch();

    if (!$row) {
        // Generar por primera vez
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        try {
            $stmt = $db->prepare(
                'INSERT INTO perfiles_comparticiones (usuario_id, token_hash) VALUES (:uid, :hash)'
            );
            $stmt->execute(['uid' => $usuarioId, 'hash' => $tokenHash]);
            $row = ['token_hash' => $tokenHash, 'token' => $token];
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                // Ya existe, re-fetch
                $stmt = $db->prepare(
                    'SELECT token_hash FROM perfiles_comparticiones WHERE usuario_id = :uid'
                );
                $stmt->execute(['uid' => $usuarioId]);
                $row = $stmt->fetch();
                $row['token'] = null; // No retornar token si no somos nosotros quien lo regeneró
            } else {
                respond(false, null, 'No se pudo generar el código QR.', 500);
            }
        }
    } else {
        $row['token'] = null;
    }

    respond(true, $row);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = getJsonBody();
    $accion = $body['accion'] ?? '';

    if ($accion === 'generar') {
        // Regenerar token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);

        try {
            $stmt = $db->prepare(
                'INSERT INTO perfiles_comparticiones (usuario_id, token_hash)
                 VALUES (:uid, :hash)
                 ON DUPLICATE KEY UPDATE token_hash = :hash_actualizado, actualizada_en = NOW()'
            );
            $stmt->execute(['uid' => $usuarioId, 'hash' => $tokenHash, 'hash_actualizado' => $tokenHash]);
            respond(true, ['token' => $token], 'Código QR regenerado.', 201);
        } catch (PDOException $e) {
            respond(false, null, 'No se pudo regenerar el código QR.', 500);
        }
    }

    if ($accion === 'preview') {
        $codigo = $body['codigo'] ?? '';
        if (!is_string($codigo) || strlen($codigo) > 100) {
            respond(false, null, 'Código inválido.', 422);
        }
        $codigo = trim($codigo);

        // Resolver token a usuario
        if (!preg_match('/^(?:NF-SOCIAL-1:)?([a-f0-9]{64})$/D', $codigo, $m)) {
            respond(false, null, 'Código de perfil inválido.', 422);
        }

        $tokenHash = hash('sha256', $m[1]);

        $stmt = $db->prepare(
            'SELECT u.id, u.nombre, u.nivel, u.xp_total
             FROM perfiles_comparticiones pc
             JOIN usuarios u ON u.id = pc.usuario_id
             WHERE pc.token_hash = :hash'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            respond(false, null, 'El código no existe o es inválido.', 404);
        }

        if ((int)$usuario['id'] === $usuarioId) {
            respond(false, null, 'No puedes escanear tu propio código QR.', 422);
        }

        // Retornar solo info pública del usuario
        respond(true, [
            'usuario_id' => (int)$usuario['id'],
            'nombre' => $usuario['nombre'],
            'nivel' => (int)$usuario['nivel'],
        ]);
    }

    respond(false, null, 'Acción no reconocida.', 422);
}

respond(false, null, 'Método no permitido.', 405);
