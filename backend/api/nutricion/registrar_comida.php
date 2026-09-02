<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';

$usuarioId = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $body = getJsonBody();
    $registroId = (int) ($body['registro_id'] ?? 0);

    $db = (new Database())->getConnection();
    $stmt = $db->prepare('DELETE FROM registros_comidas WHERE id = :id AND usuario_id = :uid');
    $stmt->execute(['id' => $registroId, 'uid' => $usuarioId]);

    if ($stmt->rowCount() === 0) {
        respond(false, null, 'No se encontró ese registro.', 404);
    }

    respond(true, null, 'Registro eliminado.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$body = getJsonBody();

$alimentoId = (int) ($body['alimento_id'] ?? 0);
$tipoComida = (string) ($body['tipo_comida'] ?? '');
$gramos = (float) ($body['gramos'] ?? 0);
$origen = in_array($body['origen'] ?? 'manual', ['manual', 'camara_ia'], true) ? $body['origen'] : 'manual';

$tiposValidos = ['desayuno', 'almuerzo', 'merienda', 'cena', 'snack'];
if ($alimentoId <= 0 || !in_array($tipoComida, $tiposValidos, true) || $gramos <= 0) {
    respond(false, null, 'Datos de la comida inválidos.', 422);
}

$db = (new Database())->getConnection();

$stmt = $db->prepare('SELECT * FROM alimentos WHERE id = :id');
$stmt->execute(['id' => $alimentoId]);
$alimento = $stmt->fetch();

if (!$alimento) {
    respond(false, null, 'Alimento no encontrado.', 404);
}

$factor = $gramos / 100;
$calorias = round($alimento['calorias_por_100g'] * $factor, 2);
$proteinas = round($alimento['proteinas'] * $factor, 2);
$carbohidratos = round($alimento['carbohidratos'] * $factor, 2);
$grasas = round($alimento['grasas'] * $factor, 2);

$insert = $db->prepare(
    'INSERT INTO registros_comidas
     (usuario_id, alimento_id, tipo_comida, gramos, calorias_totales,
      proteinas_totales, carbohidratos_totales, grasas_totales, origen, fecha)
     VALUES (:uid, :aid, :tipo, :gramos, :cal, :prot, :carb, :gras, :origen, CURDATE())'
);
$insert->execute([
    'uid'    => $usuarioId,
    'aid'    => $alimentoId,
    'tipo'   => $tipoComida,
    'gramos' => $gramos,
    'cal'    => $calorias,
    'prot'   => $proteinas,
    'carb'   => $carbohidratos,
    'gras'   => $grasas,
    'origen' => $origen,
]);

$xpSystem = new SistemaXP($db);
$resultadoXP = $xpSystem->otorgarXP($usuarioId, SistemaXP::XP_COMIDA);

respond(true, [
    'registro' => [
        'alimento'       => $alimento['nombre'],
        'gramos'         => $gramos,
        'calorias'       => $calorias,
        'proteinas'      => $proteinas,
        'carbohidratos'  => $carbohidratos,
        'grasas'         => $grasas,
    ],
    'xp' => $resultadoXP,
], 'Comida registrada correctamente.', 201);
