<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $db->prepare('SELECT vasos, meta_vasos FROM registros_hidratacion WHERE usuario_id = :uid AND fecha = CURDATE()');
    $stmt->execute(['uid' => $usuarioId]);
    $registro = $stmt->fetch() ?: ['vasos' => 0, 'meta_vasos' => 8];
    respond(true, $registro);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Inserta o incrementa el contador de vasos de hoy (upsert atómico).
    $db->prepare(
        'INSERT INTO registros_hidratacion (usuario_id, fecha, vasos)
         VALUES (:uid, CURDATE(), 1)
         ON DUPLICATE KEY UPDATE vasos = vasos + 1'
    )->execute(['uid' => $usuarioId]);

    $stmt = $db->prepare('SELECT vasos, meta_vasos FROM registros_hidratacion WHERE usuario_id = :uid AND fecha = CURDATE()');
    $stmt->execute(['uid' => $usuarioId]);
    $registro = $stmt->fetch();

    $xpOtorgado = null;
    // Cada 3 vasos completados suma +5 XP (según la sección 5 del brief).
    if ($registro['vasos'] % 3 === 0) {
        $xpSystem = new SistemaXP($db);
        $xpOtorgado = $xpSystem->otorgarXP($usuarioId, SistemaXP::XP_HIDRATACION);
    }

    respond(true, ['registro' => $registro, 'xp' => $xpOtorgado], 'Vaso de agua registrado.');
}

respond(false, null, 'Método no permitido.', 405);
