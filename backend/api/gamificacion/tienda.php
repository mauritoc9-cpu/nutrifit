<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = $db->query('SELECT * FROM tienda_items ORDER BY nivel_requerido ASC, costo_coins ASC')->fetchAll();

    $stmtInventario = $db->prepare('SELECT item_id, equipado FROM inventario_usuario WHERE usuario_id = :uid');
    $stmtInventario->execute(['uid' => $usuarioId]);
    $inventario = $stmtInventario->fetchAll(PDO::FETCH_KEY_PAIR); // item_id => equipado

    foreach ($items as &$item) {
        $item['poseido'] = array_key_exists($item['id'], $inventario);
        $item['equipado'] = (bool) ($inventario[$item['id']] ?? false);
    }

    respond(true, $items);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();
$accion = $body['accion'] ?? 'canjear';
$itemId = (int) ($body['item_id'] ?? 0);

if ($accion === 'canjear') {
    $stmtItem = $db->prepare('SELECT * FROM tienda_items WHERE id = :id');
    $stmtItem->execute(['id' => $itemId]);
    $item = $stmtItem->fetch();

    if (!$item) {
        respond(false, null, 'Ítem no encontrado.', 404);
    }

    $stmtUsuario = $db->prepare('SELECT nivel, nutri_coins, xp_total FROM usuarios WHERE id = :uid');
    $stmtUsuario->execute(['uid' => $usuarioId]);
    $usuario = $stmtUsuario->fetch();

    if ((int) $usuario['nivel'] < (int) $item['nivel_requerido']) {
        respond(false, null, "Necesitas nivel {$item['nivel_requerido']} para desbloquear este ítem.", 403);
    }
    if ((int) $usuario['nutri_coins'] < (int) $item['costo_coins']) {
        respond(false, null, 'No tienes suficientes NutriCoins.', 403);
    }
    if ((int) $usuario['xp_total'] < (int) $item['costo_xp']) {
        respond(false, null, 'No tienes suficiente XP para este ítem.', 403);
    }

    $db->beginTransaction();
    try {
        $db->prepare('UPDATE usuarios SET nutri_coins = nutri_coins - :coins WHERE id = :uid')
           ->execute(['coins' => $item['costo_coins'], 'uid' => $usuarioId]);

        $db->prepare(
            'INSERT IGNORE INTO inventario_usuario (usuario_id, item_id, equipado) VALUES (:uid, :iid, 0)'
        )->execute(['uid' => $usuarioId, 'iid' => $itemId]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, null, 'No se pudo completar el canje.', 500);
    }

    respond(true, null, 'Ítem canjeado correctamente.', 201);
}

if ($accion === 'equipar') {
    $stmt = $db->prepare(
        'SELECT ti.tipo, iu.equipado FROM inventario_usuario iu
         JOIN tienda_items ti ON ti.id = iu.item_id
         WHERE iu.usuario_id = :uid AND iu.item_id = :iid'
    );
    $stmt->execute(['uid' => $usuarioId, 'iid' => $itemId]);
    $poseido = $stmt->fetch();

    if (!$poseido) {
        respond(false, null, 'No posees este ítem.', 404);
    }

    $db->beginTransaction();
    try {
        if ((bool) $poseido['equipado']) {
            // Ya estaba equipado: lo desequipa.
            $db->prepare('UPDATE inventario_usuario SET equipado = 0 WHERE usuario_id = :uid AND item_id = :iid')
               ->execute(['uid' => $usuarioId, 'iid' => $itemId]);
        } else {
            // Sólo un ítem equipado por tipo a la vez (una remera, un aura, un marco).
            $db->prepare(
                'UPDATE inventario_usuario iu
                 JOIN tienda_items ti ON ti.id = iu.item_id
                 SET iu.equipado = 0
                 WHERE iu.usuario_id = :uid AND ti.tipo = :tipo'
            )->execute(['uid' => $usuarioId, 'tipo' => $poseido['tipo']]);

            $db->prepare('UPDATE inventario_usuario SET equipado = 1 WHERE usuario_id = :uid AND item_id = :iid')
               ->execute(['uid' => $usuarioId, 'iid' => $itemId]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        respond(false, null, 'No se pudo actualizar el equipamiento.', 500);
    }

    respond(true, null, 'Equipamiento actualizado.');
}

respond(false, null, 'Acción no reconocida.', 422);
