<?php
// Evidencia de interfaz con propuesta real del catálogo y respuesta LOCAL, sin simular Gemini real.
if(PHP_SAPI!=='cli')exit;
date_default_timezone_set('America/Argentina/Buenos_Aires');
require_once __DIR__.'/../backend/config/env.php';
require_once __DIR__.'/../backend/config/database.php';
require_once __DIR__.'/../backend/classes/asistente/AsistenteService.php';
$db=(new Database())->getConnection();$uid=(int)($argv[1]??0);
if(getenv('JAWSDB_URL')||$db->query('SELECT DATABASE()')->fetchColumn()!=='nutrifit_db')exit(2);
$q=$db->prepare('SELECT id FROM usuarios WHERE id=? AND email LIKE \'nf-ai-%@example.invalid\'');$q->execute([$uid]);if(!$q->fetch())exit(3);
if(($argv[2]??'')==='cleanup'){
 foreach(['registros_entrenamiento','ejercicios_completados','rutinas_ejercicios','usuarios'] as $table){$key=$table==='usuarios'?'id':'usuario_id';$db->prepare('DELETE FROM '.$table.' WHERE '.$key.'=?')->execute([$uid]);}
 echo "Cuenta temporal eliminada.\n";exit;
}
$service=new AsistenteService($db);$cid=$service->nueva($uid)['id'];$service->permisos($uid,$cid,['mensajes_gemini'=>false,'consultar_datos'=>true,'enviar_datos'=>false]);
$proposal=(new AsistenteComidaService($db))->proponer($uid,$cid,[
 ['nombre'=>'milanesa de carne','gramos'=>240,'supuesto'=>'Dos unidades de 120 g; no pesadas.','preparacion'=>'al horno'],
 ['nombre'=>'papa','gramos'=>180,'supuesto'=>'Una papa mediana; no pesada.','preparacion'=>'al horno']]);
$rid='12345678-1234-4123-8123-123456789abc';
$q=$db->prepare('INSERT INTO asistente_mensajes(conversacion_id,solicitud,rol,texto,intencion,origen) VALUES(?,?,?, ?,\'prueba_local\',\'local\')');
$q->execute([$cid,$rid,'user','Dos milanesas de carne al horno y una papa.']);
$text='Prueba local de porciones, sin llamada a Gemini. El cálculo usa alimentos del catálogo real. Revisá los gramos y la preparación antes de confirmar.';
$q->execute([$cid,$rid,'assistant',$text]);
$db->prepare('INSERT INTO asistente_respuestas(conversacion_id,solicitud,datos) VALUES(?,?,?)')->execute([$cid,$rid,json_encode(['codigo'=>'ok','propuesta'=>$proposal],JSON_UNESCAPED_UNICODE)]);
$db->prepare('UPDATE asistente_conversaciones SET titulo=\'Prueba local de porciones\' WHERE id=?')->execute([$cid]);
echo json_encode(['conversacion'=>$cid,'propuesta_disponible'=>$proposal['disponible'],'total'=>$proposal['total']??null]);
