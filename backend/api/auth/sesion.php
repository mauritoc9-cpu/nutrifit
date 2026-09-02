<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/Usuario.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

$usuarioId = requireAuth();

$db = (new Database())->getConnection();
$usuarioModel = new Usuario($db);
$usuario = $usuarioModel->obtenerPorId($usuarioId);

if (!$usuario) {
    respond(false, null, 'Usuario no encontrado.', 404);
}

$xpSystem = new SistemaXP($db);
$usuario['progreso_nivel'] = $xpSystem->progresoNivelActual((int) $usuario['xp_total']);

$stmtPerfil = $db->prepare('SELECT id FROM perfiles_biometricos WHERE usuario_id = :id LIMIT 1');
$stmtPerfil->execute(['id' => $usuarioId]);
$usuario['onboarding_completo'] = (bool) $stmtPerfil->fetch();

// Ítems de la tienda actualmente equipados, para pintar el avatar (uno por tipo).
$stmtEquipo = $db->prepare(
    'SELECT ti.tipo, ti.nombre FROM inventario_usuario iu
     JOIN tienda_items ti ON ti.id = iu.item_id
     WHERE iu.usuario_id = :id AND iu.equipado = 1'
);
$stmtEquipo->execute(['id' => $usuarioId]);
$usuario['equipados'] = $stmtEquipo->fetchAll(PDO::FETCH_KEY_PAIR); // tipo => nombre

respond(true, $usuario);
