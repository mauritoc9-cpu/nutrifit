<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/SistemaXP.php';
require_once __DIR__ . '/../../classes/gamificacion/GamificacionService.php';

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

// El asistente usa el mismo endpoint, con propuesta del servidor y confirmación.
require_once __DIR__.'/../../classes/nutricion/ComidaRegistroService.php';
try {
    $accion=$body['accion_asistente']??null;
    if($accion!==null){
        if(!is_string($accion)||array_diff(array_keys($body),['accion_asistente','confirmado','tipo_comida','csrf']))throw new ComidaRegistroError('Solicitud inválida.');
        if(empty($_SESSION['asistente_csrf'])||!is_string($body['csrf']??null)||!hash_equals($_SESSION['asistente_csrf'],$body['csrf']))throw new ComidaRegistroError('Solicitud no autorizada.',403);
        $origin=$_SERVER['HTTP_ORIGIN']??'';
        if($origin!==''&&parse_url($origin,PHP_URL_HOST)!==explode(':',$_SERVER['HTTP_HOST']??'')[0])throw new ComidaRegistroError('Origen no autorizado.',403);
        if(($body['confirmado']??false)!==true)throw new ComidaRegistroError('Falta confirmar explícitamente la comida.');
    }
    session_write_close();
    $db=(new Database())->getConnection();
    $r=(new ComidaRegistroService($db))->registrar($usuarioId,(string)($body['tipo_comida']??''),
        [['alimento_id'=>$body['alimento_id']??0,'gramos'=>$body['gramos']??0]],
        (string)($body['origen']??'manual'),$accion,($body['confirmado']??false)===true);
    respond(true,$r,!empty($r['repetida'])?'Esta comida ya estaba registrada.':'Comida registrada correctamente.',!empty($r['repetida'])?200:201);
} catch(ComidaRegistroError $e){respond(false,null,$e->getMessage(),$e->http);}
catch(Throwable $e){respond(false,null,'No se pudo registrar la comida.',500);}