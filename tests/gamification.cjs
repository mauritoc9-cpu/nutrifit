const cp=require('node:child_process');
const path=require('node:path');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'..');
const php=process.env.NUTRIFIT_PHP||'C:/xampp/php/php.exe';
const base=process.env.NUTRIFIT_TEST_BASE||'http://localhost/nutrifittwo';
const pre="date_default_timezone_set('America/Argentina/Buenos_Aires');require 'backend/config/database.php';$db=(new Database())->getConnection();";
function db(code){const o=cp.spawnSync(php,['-r',pre+code],{cwd:root,encoding:'utf8'});if(o.status!==0)throw Error(o.stderr);return o.stdout.trim();}
let count=0;function check(label,ok){assert.ok(ok,label);count++;console.log('PASS '+label);}
const c={email:'nf-gam-'+Date.now()+'@example.invalid'},password='NutriFit-gam-2026!';
async function req(ep,body){const r=await fetch(base+'/backend/api/'+ep,{method:body?'POST':'GET',headers:{...(c.cookie?{Cookie:c.cookie}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});const cookies=r.headers.getSetCookie();if(cookies.length)c.cookie=cookies.at(-1).split(';')[0];return {status:r.status,json:await r.json()};}
async function main(){let id;try{
 let r=await req('auth/registro.php',{nombre:'Prueba Gamificación',email:c.email,password});id=r.json.data.id;check('registro temporal',r.json.success);
 r=await req('auth/onboarding.php',{edad:30,genero:'masculino',peso_actual:70,altura:170,objetivo:'mantener_peso',nivel_actividad:'moderado',lugar_entreno:'casa',tipo_dieta:'omnivora',alergias:['ninguna']});check('onboarding',r.json.success);
 // Precondición de prueba: seis días reales previos, la acción de hoy completa siete.
 db('$db->exec("UPDATE usuarios SET racha_dias=6,ultima_actividad=DATE_SUB(CURDATE(),INTERVAL 1 DAY),xp_total=90,nivel=1,nivel_maximo_alcanzado=1 WHERE id='+id+'");');
 if(process.argv.includes('--ui')){console.log(JSON.stringify({id,email:c.email,password}));return;}
 const food=Number(db('echo $db->query("SELECT id FROM alimentos WHERE fuente=\'local\' LIMIT 1")->fetchColumn();'));
 r=await req('nutricion/registrar_comida.php',{alimento_id:food,tipo_comida:'almuerzo',gramos:100});const g=r.json.data.gamificacion;
 check('logros inmediatos',g.logros_nuevos.length===3&&g.logros_nuevos.some(x=>x.clave==='primera_comida'));
 check('reto y logro en misma acción',g.retos_nuevos.length===1&&g.retos_nuevos[0].clave==='sem_racha');
 check('XP real incluye multiplicador',g.logros_nuevos.find(x=>x.clave==='racha_7').xp.xp_ganado===85);
 check('estado final y coins',g.estado.xp_total===90+g.xp_otorgado&&g.estado.nivel===3&&g.coins_otorgadas===200);
 const before=g.estado.xp_total;
 await req('gamificacion/logros.php');await req('gamificacion/retos.php');let session=(await req('auth/sesion.php')).json.data;
 check('reload de listados no premia',session.xp_total===before);
 r=await req('nutricion/registrar_comida.php',{alimento_id:food,tipo_comida:'almuerzo',gramos:100});
 check('acción repetida sin medallas repetidas',r.json.data.gamificacion.logros_nuevos.length===0&&r.json.data.gamificacion.retos_nuevos.length===0&&r.json.data.gamificacion.xp_otorgado===r.json.data.xp.xp_ganado);
 const shop=(await req('gamificacion/tienda.php')).json.data;const tint=shop.find(x=>Number(x.costo_coins)===150);
 r=await req('gamificacion/tienda.php',{accion:'canjear',item_id:tint.id});check('feedback comprado',r.json.data.resultado==='comprado');const coins=r.json.data.estado.nutri_coins;
 r=await req('gamificacion/tienda.php',{accion:'equipar',item_id:tint.id});check('equipar',r.json.data.resultado==='equipado'&&r.json.data.estado.equipados.ropa_avatar===tint.nombre);
 session=(await req('auth/sesion.php')).json.data;check('persistencia equipamiento',session.equipados.ropa_avatar===tint.nombre);
 r=await req('gamificacion/tienda.php',{accion:'canjear',item_id:tint.id});check('recompra sin cobro',r.json.data.resultado==='ya_poseido'&&r.json.data.estado.nutri_coins===coins);
 r=await req('gamificacion/tienda.php',{accion:'equipar',item_id:tint.id});check('desequipar',r.json.data.resultado==='desequipado'&&!r.json.data.estado.equipados.ropa_avatar);
 const coupon=shop.find(x=>x.tipo==='cupon');db('$db->exec("INSERT INTO inventario_usuario(usuario_id,item_id,equipado) VALUES ('+id+','+coupon.id+',0)");');
 r=await req('gamificacion/tienda.php',{accion:'equipar',item_id:coupon.id});check('cupón no equipable',r.status===422&&r.json.data.codigo==='no_equipable');
 const aura=shop.find(x=>x.tipo==='aura');r=await req('gamificacion/tienda.php',{accion:'canjear',item_id:aura.id});check('nivel insuficiente preciso',r.json.data.codigo==='nivel_insuficiente');
 db('$db->exec("UPDATE usuarios SET nivel=20,nivel_maximo_alcanzado=20,nutri_coins=0,xp_total=0 WHERE id='+id+'");');
 r=await req('gamificacion/tienda.php',{accion:'canjear',item_id:aura.id});check('coins insuficientes precisas',r.json.data.codigo==='coins_insuficientes');
 const title=shop.find(x=>x.tipo==='titulo');r=await req('gamificacion/tienda.php',{accion:'canjear',item_id:title.id});check('XP insuficiente preciso',r.json.data.codigo==='xp_insuficiente');
 console.log('TOTAL '+count+' passed');
 }finally{if(id&&!process.argv.includes('--ui'))db('$db->exec("DELETE FROM usuarios WHERE id='+id+'");');}}
main().catch(e=>{console.error(e.message);process.exitCode=1});
