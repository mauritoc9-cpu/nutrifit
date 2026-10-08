<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../classes/entrenamiento/RutinasCompartidasService.php';
$uid=requireAuth();
header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,null,'Método no permitido.',405);
$b=getJsonBody();$accion=$b['accion']??'';
if(!is_string($accion)||!in_array($accion,['compartir','preview','importar','revocar','listar','seleccionar'],true))respond(false,null,'Solicitud inválida.',422);
if(in_array($accion,['preview','importar'],true)&&(!is_string($b['codigo']??null)||strlen($b['codigo'])>100))respond(false,null,'Código inválido.',422);
if(in_array($accion,['compartir','revocar','seleccionar'],true)&&(!isset($b['rutina_id'])||filter_var($b['rutina_id'],FILTER_VALIDATE_INT)===false||(int)$b['rutina_id']<($accion==='seleccionar'?0:1)))respond(false,null,'Rutina inválida.',422);
// No token en URLs ni logs. Límite de solicitudes por sesión para códigos al azar.
$now=time();$limite=$_SESSION['rutina_qr_limite']??['inicio'=>$now,'cantidad'=>0];
if($now-$limite['inicio']>=60)$limite=['inicio'=>$now,'cantidad'=>0];
if(++$limite['cantidad']>60)respond(false,null,'Esperá un momento antes de volver a intentar.',429);
$_SESSION['rutina_qr_limite']=$limite;session_write_close();
try{
    $s=new RutinasCompartidasService((new Database())->getConnection());
    $data=match($accion){
        'compartir'=>$s->compartir($uid,(int)$b['rutina_id']),
        'preview'=>$s->preview($b['codigo']),
        'importar'=>$s->importar($uid,$b['codigo']),
        'listar'=>['rutinas'=>$s->listar($uid)],
        'revocar'=>(function()use($s,$uid,$b){$s->revocar($uid,(int)$b['rutina_id']);return null;})(),
        'seleccionar'=>(function()use($s,$uid,$b){$s->seleccionar($uid,(int)$b['rutina_id']);return null;})(),
    };
    respond(true,$data);
}catch(DomainException $e){respond(false,null,$e->getMessage(),404);}
catch(Throwable $e){respond(false,null,'No pudimos completar la operación de rutina. Volvé a intentar.',500);}
