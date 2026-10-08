const assert=require('node:assert/strict'),cp=require('node:child_process'),path=require('node:path');
const root=path.resolve(__dirname,'..'),base='http://localhost/nutrifittwo/backend/api',users=[];
let checks=0;const check=(label,ok)=>{assert.ok(ok,label);console.log('PASS '+label);checks++;};
function db(code){const r=cp.spawnSync('C:/xampp/php/php.exe',['-r',"date_default_timezone_set('America/Argentina/Buenos_Aires');require 'backend/config/env.php';require 'backend/config/database.php';$db=(new Database())->getConnection();if(getenv('JAWSDB_URL')||$db->query('SELECT DATABASE()')->fetchColumn()!=='nutrifit_db')exit(2);"+code],{cwd:root,encoding:'utf8'});if(r.status)throw Error(r.stderr+r.stdout);return r.stdout.trim();}
async function req(u,ep,body){const r=await fetch(base+ep,{method:body?'POST':'GET',headers:{...(u?.cookie?{Cookie:u.cookie}:{}),...(body?{'Content-Type':'application/json'}:{})},...(body?{body:JSON.stringify(body)}:{})});if(u&&r.headers.getSetCookie().length)u.cookie=r.headers.getSetCookie().at(-1).split(';')[0];return {status:r.status,j:await r.json()};}
async function user(){const u={email:'conv-http-'+Date.now()+'-'+users.length+'@example.invalid'};let r=await req(u,'/auth/registro.php',{nombre:'Prueba chat HTTP',email:u.email,password:'Temporal-chat-2026!'});assert.ok(r.j.success);u.id=r.j.data.id;users.push(u);r=await req(u,'/asistente/conversaciones.php');u.csrf=r.j.data.csrf;r=await req(u,'/asistente/conversaciones.php',{accion:'nueva',csrf:u.csrf});u.cid=r.j.data.id;return u;}
function proposal(u,grams=180){return JSON.parse(db("require 'backend/classes/asistente/AsistenteComidaService.php';echo json_encode((new AsistenteComidaService($db))->proponer("+u.id+','+u.cid+",[['nombre'=>'papa','gramos'=>"+grams+",'supuesto'=>'Cantidad pesada de prueba','preparacion'=>'horno']]));"));}
const count=u=>Number(db('echo $db->query("SELECT COUNT(*) FROM registros_comidas WHERE usuario_id='+u.id+'")->fetchColumn();'));
async function register(u,p,extra={}){return req(u,'/nutricion/registrar_comida.php',{accion_asistente:p.token,confirmado:true,tipo_comida:'almuerzo',csrf:u.csrf,...extra});}
(async()=>{try{
 const a=await user(),b=await user(),p=proposal(a);check('propuesta obtiene ID real',p.disponible&&p.token.length===64);
 let r=await req(null,'/nutricion/registrar_comida.php',{accion_asistente:p.token,confirmado:true,tipo_comida:'almuerzo',csrf:a.csrf});check('sin sesión no registra',r.status===401);
 r=await register(a,p,{confirmado:false});check('confirmación falsa rechazada',r.status===422&&count(a)===0);
 r=await register(a,p,{confirmado:'true'});check('confirmación string rechazada',r.status===422&&count(a)===0);
 r=await register(a,p,{csrf:'invalid'});check('CSRF protege registro',r.status===403&&count(a)===0);
 r=await register(b,p);check('token de otra cuenta rechazado',r.status===404&&count(b)===0);
 r=await register(a,p,{alimento_id:999999,gramos:10000});check('no acepta reemplazar propuesta con campos de modelo',r.status===422&&count(a)===0);
 const results=await Promise.all([register(a,p),register(a,p)]);check('confirmación concurrente idempotente',results.every(r=>r.j.success)&&count(a)===1&&results.filter(r=>r.j.data.repetida).length===1);
 check('una recompensa de comida',db('echo $db->query("SELECT veces FROM xp_otorgado_diario WHERE usuario_id='+a.id+' AND categoria=\'comida\' AND fecha=CURDATE()")->fetchColumn();')==='1');
 r=await req(a,'/asistente/conversaciones.php',{accion:'permisos',id:a.cid,csrf:a.csrf,permisos:{mensajes_gemini:false,consultar_datos:false,enviar_datos:true}});check('no permite compartir sin demás permisos',r.status===422);
 r=await req(b,'/asistente/conversaciones.php',{accion:'permisos',id:a.cid,csrf:b.csrf,permisos:{mensajes_gemini:true,consultar_datos:true,enviar_datos:false}});check('permisos aislados entre cuentas',r.status===404);
 const exp=proposal(a,190);db('$db->exec("UPDATE asistente_acciones SET expira_en=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE token=\''+exp.token+'\'");');r=await register(a,exp);check('propuesta vencida no registra',r.status===422&&count(a)===1);
 r=await req(a,'/nutricion/registrar_comida.php',{alimento_id:p.items[0].alimento_id,gramos:120,tipo_comida:'cena',origen:'manual'});check('flujo manual sigue funcionando',r.status===201&&r.j.data.registro.gramos===120&&count(a)===2);
 console.log('TOTAL '+checks+' passed; HTTP real de XAMPP; proveedor Gemini no utilizado');
 }finally{for(const u of users)db('$db->exec("DELETE FROM usuarios WHERE id='+u.id+'");');}})().catch(e=>{console.error(e);process.exitCode=1;});
