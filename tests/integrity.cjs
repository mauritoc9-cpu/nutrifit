const fs = require('node:fs');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const php = process.env.NUTRIFIT_PHP || 'C:/xampp/php/php.exe';
const base = process.env.NUTRIFIT_TEST_BASE || 'http://localhost/nutrifittwo';
const prefix = "date_default_timezone_set('America/Argentina/Buenos_Aires');require 'backend/config/database.php';$db=(new Database())->getConnection();";
function db(code) { const o = cp.spawnSync(php, ['-r', prefix + code], {cwd:root, encoding:'utf8'}); if(o.status!==0) throw Error(o.stderr); return o.stdout.trim(); }
let passed=0; function check(label, condition) { assert.ok(condition,label); passed++; console.log('PASS '+label); }
async function req(client, endpoint, method='GET', body) { const r=await fetch(base+'/backend/api/'+endpoint,{method,headers:{...(client.cookie?{Cookie:client.cookie}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined}); const cookies=r.headers.getSetCookie(); if(cookies.length)client.cookie=cookies[cookies.length-1].split(';')[0]; return {status:r.status,json:await r.json()}; }
const uid=[]; const run=Date.now(); const password='NutriFit-test-2026!';
const a={email:'nf-test-'+run+'-a@example.invalid'}, b={email:'nf-test-'+run+'-b@example.invalid'};
const htmlName='<img src=x onerror="document.body.dataset.nfXss=1">';
async function main(){
 try {
  for(const p of ['.env','.git/config','database/nutrifit_db.sql','database/migrations/015_integridad_xp_reset.sql','backend/config/database.php','documentacion/NutriFit_Documentacion.docx']){const r=await fetch(base+'/'+p,{method:'HEAD'});check('HTTP protegido '+p,[403,404].includes(r.status));}
  check('frontend', (await fetch(base+'/frontend/',{method:'HEAD'})).status===200);
  const idx=await fetch(base+'/',{method:'HEAD',redirect:'manual'});check('index relativo',idx.status===302&&idx.headers.get('location')==='frontend/');
  await req(a,'auth/sesion.php');const anon=a.cookie;
  let r=await req(a,'auth/registro.php','POST',{nombre:htmlName,email:a.email,password});check('registro y regeneración',r.json.success&&a.cookie!==anon);uid.push(r.json.data.id);a.id=r.json.data.id;
  r=await req(b,'auth/registro.php','POST',{nombre:'NF prueba B',email:b.email,password});check('registro B',r.json.success);uid.push(r.json.data.id);b.id=r.json.data.id;
  for(const c of [a,b]){r=await req(c,'auth/onboarding.php','POST',{edad:30,genero:'masculino',peso_actual:70,altura:170,objetivo:'mantener_peso',nivel_actividad:'moderado',lugar_entreno:'casa',tipo_dieta:'omnivora',alergias:['ninguna']});check('onboarding',r.json.success);}
  const secure=await fetch(base+'/backend/api/auth/sesion.php',{headers:{'X-Forwarded-Proto':'https'}});
  check('cookie HTTPS en proxy local',secure.headers.getSetCookie().some(x=>/; secure/i.test(x)));
  const old=a.cookie;await req(a,'auth/logout.php','POST');check('logout', (await req(a,'auth/sesion.php')).status===401);r=await req(a,'auth/login.php','POST',{email:a.email,password});check('login y regeneración',r.json.success&&old!==a.cookie);
  const a2={};await req(a2,'auth/login.php','POST',{email:a.email,password});
  const state=()=>JSON.parse(db('$s=$db->query("SELECT xp_total,nivel,nivel_maximo_alcanzado,nutri_coins,racha_dias,ultima_actividad FROM usuarios WHERE id='+a.id+'")->fetch();echo json_encode($s);'));
  r=await req(a,'auth/solicitar_reset.php','POST',{email:a.email});const unknown=await req(a,'auth/solicitar_reset.php','POST',{email:'missing@example.invalid'});check('reset genérico y sin token',JSON.stringify(r.json)===JSON.stringify(unknown.json)&&r.json.data===null);check('reset no emite tokens',db('echo (int)$db->query("SELECT reset_token IS NULL AND reset_token_hash IS NULL FROM usuarios WHERE id='+a.id+'")->fetchColumn();')==='1');
  const ra=(await req(a,'entrenamiento/rutina_recomendada.php')).json.data;const rb=(await req(b,'entrenamiento/rutina_recomendada.php')).json.data;check('rutinas reales',ra?.ejercicios.length>0&&rb?.ejercicios.length>0);
  r=await req(a,'entrenamiento/completar_entrenamiento.php','POST',{rutina_id:rb.id});check('rutina ajena',r.status===403);
  r=await req(a,'entrenamiento/completar_entrenamiento.php','POST',{rutina_id:2147483647});check('rutina inexistente',r.status===403);
  r=await req(a,'entrenamiento/completar_ejercicio.php','POST',{rutina_id:ra.id,ejercicio_id:ra.ejercicios[0].id});check('marcado individual',r.json.success);check('marcado persistente',(await req(a,'entrenamiento/rutina_recomendada.php')).json.data.ejercicios_completados_hoy.includes(Number(ra.ejercicios[0].id)));
  r=await req(a,'entrenamiento/completar_ejercicio.php','DELETE',{ejercicio_id:ra.ejercicios[0].id});check('desmarcado',r.json.success);
  r=await req(a,'entrenamiento/completar_entrenamiento.php','POST',{rutina_id:ra.id});check('completar entrenamiento',r.json.success&&r.json.data.xp.xp_ganado>0);const xp=state().xp_total;r=await req(a2,'entrenamiento/completar_entrenamiento.php','POST',{rutina_id:ra.id});check('repetir no premia',r.status===409&&state().xp_total===xp);
  const second=Number(db('$db->exec("INSERT INTO rutinas_ejercicios(usuario_id,titulo,lugar,objetivo) VALUES ('+a.id+',\'NF segunda\',\'casa\',\'mejorar_habitos\')");echo $db->lastInsertId();'));
  r=await req(a,'entrenamiento/completar_entrenamiento.php','POST',{rutina_id:second});check('segunda rutina sin XP',r.json.success&&r.json.data.xp===null&&state().xp_total===xp);
  for(const fecha of ['2099-01-01','2026-02-30','2026-2-01','basura']){r=await req(a,'actividad/registrar.php','POST',{tipo:'caminata',duracion_segundos:600,fecha});check('fecha inválida '+fecha,r.status===422);}
  const token='nf-test-'+run;const body={tipo:'caminata',duracion_segundos:600,distancia_metros:700,fuente:'manual',fuente_registro_id:token};const rr=await Promise.all([req(a,'actividad/registrar.php','POST',body),req(a2,'actividad/registrar.php','POST',body)]);check('actividad idempotente concurrente',rr.every(r=>r.json.success)&&rr.filter(r=>r.json.data.ya_existia).length===1);
  const activityAwards=[];
  for(let n=0;n<4;n++){r=await req(a,'actividad/registrar.php','POST',{...body,fuente_registro_id:token+'-limit-'+n});activityAwards.push(r.json.data.xp);}
  check('actividad respeta tres premios diarios',activityAwards.filter(Boolean).length===2);
  const histxp=state().xp_total;r=await req(a,'actividad/registrar.php','POST',{...body,fecha:'2026-01-01',fuente_registro_id:token+'-hist'});check('histórica sin XP',r.json.success&&r.json.data.xp===null&&state().xp_total===histxp);
  db('$db->exec("UPDATE usuarios SET xp_total=2000,nivel=5,nivel_maximo_alcanzado=5,nutri_coins=1000 WHERE id='+a.id+'");');
  const item=Number(db('echo $db->query("SELECT id FROM tienda_items WHERE costo_coins=150 LIMIT 1")->fetchColumn();'));
  const buy=await Promise.all([req(a,'gamificacion/tienda.php','POST',{accion:'canjear',item_id:item}),req(a2,'gamificacion/tienda.php','POST',{accion:'canjear',item_id:item})]);check('compra concurrente cobra una vez',buy.every(r=>r.json.success)&&Number(state().nutri_coins)===850);await req(a,'gamificacion/tienda.php','POST',{accion:'canjear',item_id:item});check('recompra no cobra',Number(state().nutri_coins)===850);
  const expensive=Number(db('echo $db->query("SELECT id FROM tienda_items WHERE costo_coins=1200 LIMIT 1")->fetchColumn();'));
  r=await req(a,'gamificacion/tienda.php','POST',{accion:'canjear',item_id:expensive});check('saldo insuficiente no cambia saldo',r.status===403&&Number(state().nutri_coins)===850);
  const cycle=JSON.parse(db('require "backend/classes/SistemaXP.php";$db->beginTransaction();$db->exec("UPDATE usuarios SET xp_total=80,nivel=1,nivel_maximo_alcanzado=1,nutri_coins=0,racha_dias=0,ultima_actividad=NULL WHERE id='+a.id+'");$s=new SistemaXP($db);$uno=$s->otorgarXP('+a.id+',25);$dos=$s->otorgarXP('+a.id+',800);echo json_encode([$uno,$dos]);$db->commit();'));check('subida premia coins',cycle[0].coins_ganados===100&&cycle[1].coins_ganados>0);
  const before=state();const title=Number(db('echo $db->query("SELECT id FROM tienda_items WHERE costo_xp=500 LIMIT 1")->fetchColumn();'));r=await req(a,'gamificacion/tienda.php','POST',{accion:'canjear',item_id:title});check('gastar XP baja nivel',r.json.success&&Number(state().nivel)<Number(before.nivel));const up=JSON.parse(db('require "backend/classes/SistemaXP.php";echo json_encode((new SistemaXP($db))->otorgarXP('+a.id+',500));'));check('volver a subir no repremia coins',up.nivel===Number(before.nivel)&&up.coins_ganados===0&&Number(state().nutri_coins)===Number(before.nutri_coins));
  db('$db->exec("UPDATE usuarios SET racha_dias=7,ultima_actividad=DATE_SUB(CURDATE(),INTERVAL 3 DAY) WHERE id='+b.id+'");');let l=(await req(b,'gamificacion/logros.php')).json.data;let retos=(await req(b,'gamificacion/retos.php')).json.data;check('racha vencida no desbloquea',l.logros.filter(x=>['racha_3','racha_7'].includes(x.clave)).every(x=>!x.desbloqueado)&&retos.retos.find(x=>x.clave==='sem_racha').estado==='en_progreso');
  r=await req(a,'gamificacion/amigos.php','POST',{accion:'agregar',email:b.email});check('solicitud amistad',r.json.success);r=await req(b,'gamificacion/amigos.php','POST',{accion:'agregar',email:a.email});check('amistad inversa rechazada',r.status===409);let rel=(await req(b,'gamificacion/amigos.php')).json.data.find(x=>Number(x.amigo_id)===a.id);r=await req(b,'gamificacion/amigos.php','POST',{accion:'aceptar',relacion_id:rel.relacion_id});check('aceptar amistad',r.json.success);check('ranking amigos',(await req(a,'gamificacion/ranking.php?scope=amigos')).json.data.tabla.length===2);
  for(let n=0;n<3;n++)await req(a,'nutricion/hidratacion.php','POST');
  const waterXP=state().xp_total;
  await req(a,'nutricion/hidratacion.php','DELETE');r=await req(a,'nutricion/hidratacion.php','POST');check('deshacer agua no repremia el mismo umbral',r.json.data.xp===null&&state().xp_total===waterXP);
  for(let n=0;n<3;n++)r=await req(a,'nutricion/hidratacion.php','POST');check('nuevo umbral de agua sí premia',r.json.data.xp!==null);
  const privateToken=db('require "backend/classes/PasswordResetTokens.php";echo (new PasswordResetTokens($db))->emitirParaEntregaPrivada('+b.id+');');
  check('token guardado sólo como hash',db('echo (int)$db->query("SELECT reset_token IS NULL AND LENGTH(reset_token_hash)=64 FROM usuarios WHERE id='+b.id+'")->fetchColumn();')==='1');
  db('$db->exec("UPDATE usuarios SET reset_token_expira=DATE_SUB(NOW(),INTERVAL 1 MINUTE) WHERE id='+b.id+'");');
  r=await req(b,'auth/resetear_password.php','POST',{token:privateToken,password});check('token expirado rechazado',r.status===422);
  const usableToken=db('require "backend/classes/PasswordResetTokens.php";echo (new PasswordResetTokens($db))->emitirParaEntregaPrivada('+b.id+');');
  r=await req(b,'auth/resetear_password.php','POST',{token:usableToken,password});check('reset válido',r.json.success);
  r=await req(b,'auth/resetear_password.php','POST',{token:usableToken,password});check('token de uso único',r.status===422);
  r=await req(b,'auth/login.php','POST',{email:b.email,password});check('login después de reset',r.json.success&&!('reset_token_hash' in r.json.data.usuario));
  for(const ep of ['auth/perfil.php','auth/sesion.php','personalizacion/plan.php','nutricion/dashboard_diario.php','nutricion/hidratacion.php','nutricion/favoritos.php','entrenamiento/rutina_recomendada.php','actividad/resumen_dia.php','actividad/historial.php','progreso/resumen_semanal.php','gamificacion/logros.php','gamificacion/retos.php','gamificacion/ranking.php','gamificacion/tienda.php'])check('API '+ep,(await req(a,ep)).json.success);
  console.log('TOTAL '+passed+' passed');
  if(process.argv.includes('--keep'))console.log(JSON.stringify({testEmail:a.email,password,id:a.id,otherId:b.id}));
 } finally {
  if(!process.argv.includes('--keep')&&uid.length) {const ids=uid.join(',');db('$db->exec("DELETE FROM registros_entrenamiento WHERE usuario_id IN ('+ids+')");$db->exec("DELETE FROM ejercicios_completados WHERE usuario_id IN ('+ids+')");$db->exec("DELETE e FROM ejercicios e JOIN rutinas_ejercicios r ON r.id=e.rutina_id WHERE r.usuario_id IN ('+ids+')");$db->exec("DELETE FROM rutinas_ejercicios WHERE usuario_id IN ('+ids+')");$db->exec("DELETE FROM usuarios WHERE id IN ('+ids+')");');}
 }
}
main().catch(e=>{console.error(e.message);process.exitCode=1});
