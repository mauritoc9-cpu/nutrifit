const cp = require('node:child_process');
const assert = require('node:assert/strict');
for (const endpoint of ['entrenamiento/rutina_recomendada.php','nutricion/dashboard_diario.php','nutricion/recomendaciones.php','nutricion/favoritos.php']) {
  const r = cp.spawnSync('C:/xampp/php/php.exe', ['tests/recovery-endpoint-local.php', endpoint], {encoding:'utf8'});
  assert.equal(r.status, 0, endpoint + ': PHP failed');
  const body = JSON.parse(r.stdout);
  assert.equal(body.success, true, endpoint + ': ' + body.message);
  assert.match(r.stderr, /STATUS:200$/, endpoint);
  console.log('PASS restored MySQL 8.4: PHP endpoint returns success JSON and status 200: ' + endpoint);
}
