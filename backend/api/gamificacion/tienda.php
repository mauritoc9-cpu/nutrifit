<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';
require_once __DIR__ . '/../../classes/gamificacion/GamificacionService.php';

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = $db->query('SELECT * FROM tienda_items ORDER BY nivel_requerido ASC, costo_coins ASC')->fetchAll();

    $stmtInventario = $db->prepare('SELECT item_id, equipado FROM inventario_usuario WHERE usuario_id = :uid');
    $stmtInventario->execute(['uid' => $usuarioId]);
    $inventario = $stmtInventario->fetchAll(PDO::FETCH_KEY_PAIR); // item_id => equipado

    foreach ($items as &$item) {
        $item['poseido'] = array_key_exists($item['id'], $inventario);
        $item['equipado'] = in_array($item['tipo'], ['ropa_avatar', 'aura', 'marco_perfil', 'titulo'], true) && (bool) ($inventario[$item['id']] ?? false);
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

    $db->beginTransaction();
    try {
        $stmtUsuario = $db->prepare('SELECT nivel, nutri_coins, xp_total FROM usuarios WHERE id = :uid FOR UPDATE');
        $stmtUsuario->execute(['uid' => $usuarioId]);
        $usuario = $stmtUsuario->fetch();
        $stmtPoseido = $db->prepare('SELECT item_id FROM inventario_usuario WHERE usuario_id = :uid AND item_id = :iid');
        $stmtPoseido->execute(['uid' => $usuarioId, 'iid' => $itemId]);
        if ($stmtPoseido->fetch()) { $db->commit(); respond(true, ['resultado' => 'ya_poseido', 'estado' => (new GamificacionService($db))->estado($usuarioId)], 'Ya posees este ítem. No se realizó ningún cobro.'); }
        foreach ([['nivel', 'nivel_requerido', 'nivel_insuficiente', 'Necesitás un nivel mayor para este objeto.'], ['nutri_coins', 'costo_coins', 'coins_insuficientes', 'No tenés suficientes NutriCoins.'], ['xp_total', 'costo_xp', 'xp_insuficiente', 'No tenés suficiente XP.']] as [$saldo, $costo, $codigo, $mensaje]) {
            if ((int) $usuario[$saldo] < (int) $item[$costo]) { $db->rollBack(); respond(false, ['codigo' => $codigo], $mensaje, 403); }
        }
        $nuevoXpTotal = (int) $usuario['xp_total'] - (int) $item['costo_xp'];
        $nuevoNivel = (new SistemaXP($db))->calcularNivelDesdeXP($nuevoXpTotal);
        $db->prepare('UPDATE usuarios SET nutri_coins = nutri_coins - :coins, xp_total = :xp, nivel = :nivel WHERE id = :uid')->execute(['coins' => $item['costo_coins'], 'xp' => $nuevoXpTotal, 'nivel' => $nuevoNivel, 'uid' => $usuarioId]);
        $db->prepare('INSERT INTO inventario_usuario (usuario_id, item_id, equipado) VALUES (:uid, :iid, 0)')->execute(['uid' => $usuarioId, 'iid' => $itemId]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        respond(false, null, 'No se pudo completar el canje.', 500);
    }

    respond(true, ['resultado' => 'comprado', 'estado' => (new GamificacionService($db))->estado($usuarioId)], 'Objeto comprado. Ya está en tu colección.', 201);
}

if ($accion === 'equipar') {
    // Tipos que producen un efecto visual REAL hoy (ver frontend):
    //   ropa_avatar  → tiñe el modelo 3D
    //   aura         → glow CSS alrededor del avatar
    //   marco_perfil → marco CSS del avatar
    //   titulo       → título mostrado bajo el nombre
    // Se excluyen a propósito:
    //   cupon            → no es cosmético del avatar (va a Beneficios, a futuro)
    //   accesorio_avatar → requiere geometría 3D que el GLB actual (malla
    //                      única) no tiene; pendiente hasta tener ese asset.
    $equipables = ['ropa_avatar', 'aura', 'marco_perfil', 'titulo'];

    $db->beginTransaction();
    $lock = $db->prepare('SELECT id FROM usuarios WHERE id = :uid FOR UPDATE');
    $lock->execute(['uid' => $usuarioId]);
    $stmt = $db->prepare(
        'SELECT ti.tipo, iu.equipado FROM inventario_usuario iu
         JOIN tienda_items ti ON ti.id = iu.item_id
         WHERE iu.usuario_id = :uid AND iu.item_id = :iid'
    );
    $stmt->execute(['uid' => $usuarioId, 'iid' => $itemId]);
    $poseido = $stmt->fetch();

    if (!$poseido) {
        $db->rollBack();
        respond(false, null, 'No posees este ítem.', 404);
    }
    if (!in_array($poseido['tipo'], $equipables, true)) {
        $db->rollBack();
        respond(false, ['codigo' => 'no_equipable'], $poseido['tipo'] === 'accesorio_avatar' ? 'Accesorios 3D: próximamente. Este objeto no se puede equipar.' : 'Este objeto no se puede equipar en el avatar.', 422);
    }

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

    respond(true, ['resultado' => $poseido['equipado'] ? 'desequipado' : 'equipado', 'estado' => (new GamificacionService($db))->estado($usuarioId)], $poseido['equipado'] ? 'Objeto desequipado.' : 'Objeto equipado.');
}

respond(false, null, 'Acción no reconocida.', 422);
