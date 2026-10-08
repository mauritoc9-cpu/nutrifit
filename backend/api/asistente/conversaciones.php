<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../classes/asistente/AsistenteService.php';
$uid=requireAuth();header('Cache-Control: no-store, private');
if($_SERVER['REQUEST_METHOD']==='GET'){
    if(empty($_SESSION['asistente_csrf']))$_SESSION['asistente_csrf']=bin2hex(random_bytes(32));
    $csrf=$_SESSION['asistente_csrf'];session_write_close();
    try{$s=new AsistenteService((new Database())->getConnection());$s->limpiar($uid);$id=$_GET['id']??null;if($id!==null&&(filter_var($id,FILTER_VALIDATE_INT)===false||(int)$id<1))throw new AsistenteError('solicitud_invalida');respond(true,['csrf'=>$csrf,'conversaciones'=>$s->listar($uid),'mensajes'=>$id!==null?$s->mensajes($uid,(int)$id):[],'permisos'=>$id!==null?$s->permisos($uid,(int)$id):['mensajes_gemini'=>false,'consultar_datos'=>false,'enviar_datos'=>false,'servidor_datos_habilitados'=>env('ASISTENTE_GEMINI_DATOS_HABILITADOS')==='1']]);}
    catch(AsistenteError $e){respond(false,['codigo'=>$e->codigo],'Conversación no disponible.',$e->http);}catch(Throwable $e){respond(false,null,'No pudimos cargar el asistente.',500);}
}
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,null,'Método no permitido.',405);
if((int)($_SERVER['CONTENT_LENGTH']??0)>16000)respond(false,null,'Solicitud demasiado grande.',413);
$raw=file_get_contents('php://input',false,null,0,16001);
if($raw===false||strlen($raw)>16000)respond(false,null,'Solicitud demasiado grande.',413);
$b=json_decode($raw,true);if(!is_array($b))respond(false,null,'Solicitud inválida.',422);
if(!is_string($b['csrf']??null)||!hash_equals($_SESSION['asistente_csrf']??'', $b['csrf'])||empty($_SESSION['asistente_csrf']))respond(false,null,'Solicitud no autorizada. Volvé a abrir el asistente.',403);
$origin=$_SERVER['HTTP_ORIGIN']??'';$host=$_SERVER['HTTP_HOST']??'';if($origin!==''&&parse_url($origin,PHP_URL_HOST)!==explode(':',$host)[0])respond(false,null,'Origen no autorizado.',403);
session_write_close();
try{
 $s=new AsistenteService((new Database())->getConnection());$s->limpiar($uid);
 $accion=$b['accion']??'';if(!in_array($accion,['nueva','eliminar','enviar','permisos'],true)||array_diff(array_keys($b),['accion','id','texto','solicitud','csrf','permisos']))throw new AsistenteError('solicitud_invalida');
 if($accion==='nueva')respond(true,$s->nueva($uid));
 $id=$b['id']??null;if(filter_var($id,FILTER_VALIDATE_INT)===false||(int)$id<1)throw new AsistenteError('solicitud_invalida');
 if($accion==='eliminar'){$s->eliminar($uid,(int)$id);respond(true,null);}
 if($accion==='permisos'){if(!is_array($b['permisos']??null)||array_diff(array_keys($b['permisos']),['mensajes_gemini','consultar_datos','enviar_datos']))throw new AsistenteError('solicitud_invalida');respond(true,['permisos'=>$s->permisos($uid,(int)$id,$b['permisos'])]);}
 if(!is_string($b['texto']??null)||!is_string($b['solicitud']??null))throw new AsistenteError('mensaje_invalido');
 respond(true,$s->enviar($uid,(int)$id,$b['texto'],$b['solicitud']));
}catch(AsistenteError $e){$msg=match($e->codigo){'limite_minuto'=>'Alcanzaste el límite por minuto. Esperá un momento.','ocupado','solicitud_pendiente'=>'Ya hay una solicitud en curso.','mensaje_invalido'=>'Escribí un mensaje de hasta 2000 caracteres.','mensajes_limite'=>'Esta conversación llegó al límite. Creá una nueva.','conversaciones_limite'=>'Tenés treinta conversaciones. Eliminá una para crear otra.',default=>'Solicitud o conversación no disponible.'};respond(false,['codigo'=>$e->codigo],$msg,$e->http);}
catch(Throwable $e){respond(false,null,'No pudimos completar la consulta. Volvé a intentar.',500);}
