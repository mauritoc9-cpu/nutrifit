<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../classes/asistente/AyudaSesionService.php';
header('Cache-Control: no-store, private');
if(empty($_SESSION['usuario_id']))respond(false,['codigo'=>'sesion_vencida'],'Tu sesión venció. Volvé a iniciar sesión.',401);
$uid=requireAuth();
$s=new AyudaSesionService($_SESSION,$uid);
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $id=$_GET['id']??null;
        if($id!==null&&(filter_var($id,FILTER_VALIDATE_INT)===false||(int)$id<1))throw new AyudaSesionError('solicitud_invalida');
        respond(true,$s->estado($id!==null?(int)$id:null));
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,null,'Método no permitido.',405);
    if((int)($_SERVER['CONTENT_LENGTH']??0)>16000)respond(false,null,'Solicitud demasiado grande.',413);
    if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))respond(false,null,'Se requiere JSON.',415);
    $origin=$_SERVER['HTTP_ORIGIN']??'';
    $expected=strtolower($_SERVER['HTTP_HOST']??'');
    $originHost=strtolower((string)parse_url($origin,PHP_URL_HOST));
    $originPort=parse_url($origin,PHP_URL_PORT);
    if($originPort!==null)$originHost.=':'.$originPort;
    if(($origin!==''&&$originHost!==$expected)||($_SERVER['HTTP_SEC_FETCH_SITE']??'')==='cross-site')respond(false,['codigo'=>'origen_no_autorizado'],'Origen no autorizado.',403);
    $raw=file_get_contents('php://input',false,null,0,16001);
    if($raw===false||strlen($raw)>16000)respond(false,null,'Solicitud demasiado grande.',413);
    $b=json_decode($raw,true);
    if(!is_array($b)||array_diff(array_keys($b),['csrf','accion','id','texto','solicitud']))throw new AyudaSesionError('solicitud_invalida');
    if(!$s->autorizar($b['csrf']??null))respond(false,['codigo'=>'csrf_invalido'],'La autorización del asistente venció. Volvé a abrirlo.',403);
    if(($b['accion']??'')==='nueva')respond(true,$s->nueva());
    if(filter_var($b['id']??null,FILTER_VALIDATE_INT)===false||(int)$b['id']<1)throw new AyudaSesionError('solicitud_invalida');
    if(($b['accion']??'')==='eliminar'){$s->eliminar((int)$b['id']);respond(true,null);}
    if(($b['accion']??'')!=='enviar'||!is_string($b['texto']??null)||!is_string($b['solicitud']??null))throw new AyudaSesionError('solicitud_invalida');
    respond(true,$s->enviar((int)$b['id'],$b['texto'],$b['solicitud']));
}catch(AyudaSesionError $e){
    $message=match($e->codigo){
        'mensaje_invalido'=>'Escribí una pregunta de hasta 2000 caracteres.',
        'mensajes_limite'=>'Esta charla llegó al límite. Iniciá una nueva.',
        'conversaciones_limite'=>'Tenés diez charlas en esta sesión. Eliminá una para iniciar otra.',
        'limite_minuto'=>'Esperá un momento antes de enviar más preguntas.',
        'conversacion_no_disponible'=>'Esta conversación no está disponible en tu sesión.',
        default=>'Solicitud inválida.',
    };
    respond(false,['codigo'=>$e->codigo],$message,$e->http);
}
