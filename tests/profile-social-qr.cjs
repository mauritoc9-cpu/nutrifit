const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { webcrypto, createHash } = require('node:crypto');
const hash = token => createHash('sha256').update(token).digest('hex');
const nodes = new Map();
const node = id => {
  if (!nodes.has(id)) nodes.set(id, { value:'', textContent:'', focus(){}, select(){}, remove(){}, querySelector(){return node('close');} });
  return nodes.get(id);
};
let generation = 0, token = 'a'.repeat(64), getToken = null, serverHash = hash(token);
const codes = [];
const qrcode = () => ({addData(code){codes.push(code);},make(){},createSvgTag(){return '<svg></svg>';}});
const context = vm.createContext({
  crypto:webcrypto, TextEncoder, Uint8Array, AbortController, setTimeout, clearTimeout,
  window:{qrcode}, qrcode, Icon:()=>'', escapeHtml:s=>s, API_BASE:'/test-only',
  document:{activeElement:null, createElement:()=>node('overlay'), getElementById:node,
    body:{append(){}}, addEventListener(){}, removeEventListener(){}},
  navigator:{clipboard:{writeText:async()=>{}}},
  fetch:async (url, options) => {
    if (options.method === 'POST') {
      assert.equal(JSON.parse(options.body).accion,'generar');
      token = (++generation).toString(16).repeat(64); serverHash = hash(token);
      return {json:async()=>({success:true,data:{token}})};
    }
    return {json:async()=>({success:true,data:{token_hash:serverHash,token:getToken}})};
  },
});
vm.runInContext(fs.readFileSync('frontend/js/amigos-qr.js','utf8'), context);
(async()=>{
  await vm.runInContext('AmigosQR.miQR()',context);
  assert.equal(codes.at(-1),'NF-SOCIAL-1:'+token);
  assert.notEqual(codes.at(-1),'NF-SOCIAL-1:'+serverHash);
  assert.equal(node('qr-codigo').value,codes.at(-1));
  console.log('PASS displayed and encoded QR use original token, never its hash');
  await vm.runInContext('AmigosQR.miQR()',context);
  assert.equal(generation,1);
  console.log('PASS reopening QR preserves the valid in-memory code');
  await node('qr-regen').onclick();
  assert.equal(generation,2); assert.equal(codes.at(-1),'NF-SOCIAL-1:'+token);
  console.log('PASS explicit regeneration displays exactly the newly issued code');
  serverHash=hash('f'.repeat(64));
  await vm.runInContext('AmigosQR.miQR()',context);
  assert.equal(generation,3);
  console.log('PASS external revocation or another user invalidates cached token');
  getToken='e'.repeat(64); serverHash=hash(getToken);
  await vm.runInContext('AmigosQR.miQR()',context);
  assert.equal(generation,3); assert.equal(codes.at(-1),'NF-SOCIAL-1:'+getToken);
  console.log('PASS first-issued original token is reused without another request');
})().catch(e=>{console.error(e);process.exitCode=1;});
