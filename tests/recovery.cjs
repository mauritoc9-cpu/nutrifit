const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const cp = require('node:child_process');
const auth = fs.readFileSync('frontend/js/auth.js', 'utf8');
const context = vm.createContext({});
for (const name of ['calcularIMC', 'validarBiometria']) {
  const start = auth.indexOf('function ' + name);
  const end = auth.indexOf('\n}', start) + 2;
  vm.runInContext(auth.slice(start, end), context);
}
assert.ok(context.validarBiometria({edad:30,peso:66,altura:170,pesoObjetivo:10}).pesoObjetivo);
assert.equal(Object.keys(context.validarBiometria({edad:30,peso:66,altura:170,pesoObjetivo:65})).length, 0);
console.log('PASS frontend bloquea 10 kg y admite 65 kg');
const api = fs.readFileSync('frontend/js/api.js', 'utf8');
for (const [src, expected] of [
  ['http://localhost/nutrifittwo/frontend/js/api.js','/nutrifittwo/backend/api'],
  ['https://example.invalid/frontend/js/api.js','/backend/api']
]) {
  const c = vm.createContext({URL,document:{currentScript:{src}}});
  vm.runInContext(api, c);
  assert.equal(vm.runInContext('API_BASE', c), expected);
}
console.log('PASS rutas API local y raíz de producción');
const base = process.env.NUTRIFIT_TEST_BASE || 'http://localhost/nutrifittwo';
let cookie, id, secondId;
async function req(path, body) {
  const r = await fetch(base + '/backend/api/' + path, {method:body?'POST':'GET',headers:{...(cookie?{Cookie:cookie}:{}),...(body?{'Content-Type':'application/json'}:{})},body:body?JSON.stringify(body):undefined});
  if (r.headers.getSetCookie().length) cookie = r.headers.getSetCookie().at(-1).split(';')[0];
  return {status:r.status,json:await r.json()};
}
(async()=>{
  try {
    const r = await req('auth/registro.php',{nombre:'NF recovery test',email:`nf-recovery-${Date.now()}@example.invalid`,password:'NF-Test-2026!'});
    assert.ok(r.json.success); id=r.json.data.id;
    const body={edad:30,genero:'masculino',peso_actual:66,peso_objetivo:10,altura:170,objetivo:'perder_grasa',nivel_actividad:'moderado',lugar_entreno:'casa',tipo_dieta:'omnivora'};
    assert.equal((await req('auth/onboarding.php',body)).status,422);
    assert.ok((await req('auth/onboarding.php',{...body,peso_objetivo:65})).json.success);
    const profile=(await req('auth/perfil.php')).json.data;
    assert.equal(Number(profile.peso_objetivo),65);
    assert.equal((await req('auth/onboarding.php',body)).status,422);
    assert.equal(Number((await req('auth/perfil.php')).json.data.peso_objetivo),65);
    console.log('PASS backend rechaza meta absurda en onboarding/edición y conserva meta válida');
    for (const endpoint of ['entrenamiento/rutina_recomendada.php', 'nutricion/dashboard_diario.php', 'nutricion/recomendaciones.php', 'nutricion/favoritos.php']) {
      const response = await req(endpoint);
      assert.equal(response.status, 200, endpoint);
      assert.equal(response.json.success, true, endpoint);
      console.log('PASS HTTP 200 y JSON válido: ' + endpoint);
    }
    const cookieA=cookie;
    const qr=(await req('gamificacion/amigos_qr.php')).json.data.token;
    assert.ok(qr);
    assert.equal((await req('gamificacion/amigos_qr.php',{accion:'preview',codigo:'NF-SOCIAL-1:'+qr})).status,422);
    cookie=null;
    const registration=await req('auth/registro.php',{nombre:'NF QR recovery B',email:`nf-qr-recovery-${Date.now()}@example.invalid`,password:'NF-Test-2026!'});
    assert.ok(registration.json.success); secondId=registration.json.data.id;
    const cookieB=cookie;
    const preview=await req('gamificacion/amigos_qr.php',{accion:'preview',codigo:'NF-SOCIAL-1:'+qr});
    assert.equal(preview.json.data.usuario_id,Number(id));
    assert.ok((await req('gamificacion/amigos.php',{accion:'agregar-por-qr',usuario_id:id})).json.success);
    assert.equal((await req('gamificacion/amigos.php',{accion:'agregar-por-qr',usuario_id:id})).status,409);
    cookie=cookieA;
    let pending=(await req('gamificacion/amigos.php')).json.data;
    assert.equal(pending[0].estado,'pendiente');
    assert.ok((await req('gamificacion/amigos.php',{accion:'rechazar',relacion_id:pending[0].relacion_id})).json.success);
    cookie=cookieB;
    assert.ok((await req('gamificacion/amigos.php',{accion:'agregar-por-qr',usuario_id:id})).json.success);
    cookie=cookieA;
    pending=(await req('gamificacion/amigos.php')).json.data;
    assert.ok((await req('gamificacion/amigos.php',{accion:'aceptar',relacion_id:pending[0].relacion_id})).json.success);
    const rotate=await req('gamificacion/amigos_qr.php',{accion:'generar'});
    assert.ok(rotate.json.success); assert.notEqual(rotate.json.data.token,qr);
    cookie=cookieB;
    assert.equal((await req('gamificacion/amigos_qr.php',{accion:'preview',codigo:'NF-SOCIAL-1:'+qr})).status,404);
    assert.equal((await req('gamificacion/amigos_qr.php',{accion:'preview',codigo:[]})).status,422);
    console.log('PASS QR Social: preview, solicitud, aceptar/rechazar, autoamistad, duplicados, regeneración y revocación');
  } finally {
    if(id) {
      const ids=[id,secondId].filter(Boolean).map(Number).join(',');
      const r=cp.spawnSync('C:/xampp/php/php.exe',['-r',`require 'backend/config/database.php';$db=(new Database())->getConnection();$db->exec('DELETE FROM progreso_peso WHERE usuario_id IN (${ids})');$db->exec('DELETE FROM usuarios WHERE id IN (${ids})');`],{encoding:'utf8'});
      assert.equal(r.status,0,r.stderr);
    }
  }
})().catch(e=>{console.error(e);process.exitCode=1;});
