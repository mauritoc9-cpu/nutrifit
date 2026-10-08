/* Datos y tokens del backend; QR e imágenes se procesan localmente. */
const ExportarProgreso = (()=>{
  let controller,url;
  function html(){return `<div class="card exportar-card"><h3 class="row-label">${Icon('download',{size:16})}Exportar progreso</h3><p>Descargá un informe de tus registros reales.</p><label for="exportar-periodo">Período del informe</label><select id="exportar-periodo"><option value="7">Últimos 7 días</option><option value="30">Últimos 30 días</option><option value="todo">Todo el historial</option></select><div class="exportar-actions"><button class="btn btn-primary" type="button" onclick="ExportarProgreso.descargar('pdf')">Descargar PDF</button><button class="btn btn-ghost" type="button" onclick="ExportarProgreso.descargar('csv')">Descargar CSV</button></div><p id="exportar-estado" role="status" aria-live="polite"></p><a id="exportar-disponible" hidden>Descargar informe preparado</a></div>`;}
  async function descargar(formato){
    const panel=document.querySelector('.exportar-card');if(!panel)return;
    controller?.abort();controller=new AbortController();const own=controller;
    if(url){URL.revokeObjectURL(url);url=null;}
    const estado=document.getElementById('exportar-estado'),link=document.getElementById('exportar-disponible');link.hidden=true;estado.textContent='Preparando informe…';panel.querySelectorAll('button').forEach(b=>b.disabled=true);
    const timer=setTimeout(()=>own.abort(),40000);
    try{
      const r=await fetch(API_BASE+'/progreso/exportar.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({periodo:document.getElementById('exportar-periodo').value,formato}),signal:own.signal});
      if(!r.ok){let message='No pudimos preparar el informe.';try{message=(await r.json()).message||message;}catch{}throw Error(message);}
      const blob=await r.blob();if(!panel.isConnected||own!==controller)return;
      url=URL.createObjectURL(blob);link.href=url;link.download='nutrifit-progreso.'+formato;link.hidden=false;estado.textContent='Informe listo. Podés descargarlo nuevamente desde el enlace.';link.click();
    }catch(e){if(panel.isConnected)estado.textContent=e.name==='AbortError'?'La preparación tardó demasiado o fue cancelada. Volvé a intentar.':e.message.startsWith('No pudimos')||e.message.startsWith('Período')?e.message:'No pudimos conectar para preparar el informe. Revisá tu conexión.';}
    finally{clearTimeout(timer);if(panel.isConnected)panel.querySelectorAll('button').forEach(b=>b.disabled=false);}
  }
  function salir(){controller?.abort();controller=null;if(url){URL.revokeObjectURL(url);url=null;}}
  return {html,descargar,salir};
})();

const RutinasQR=(()=>{
  let scanner,overlay,epoch=0,generatorPromise,readerPromise,previewCode='',lastFocus;
  async function api(body){
    const c=new AbortController();const timer=setTimeout(()=>c.abort(),15000);
    try{const r=await fetch(API_BASE+'/entrenamiento/compartir_rutina.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body),signal:c.signal});let j;try{j=await r.json();}catch{throw Error('No pudimos leer la respuesta. Volvé a intentar.');}if(!j.success)throw Error(j.message||'No pudimos completar la operación.');return j.data;}
    catch(e){if(e.name==='AbortError')throw Error('La operación tardó demasiado. Volvé a intentar.');if(e instanceof TypeError)throw Error('No pudimos conectar. Revisá tu conexión.');throw e;}
    finally{clearTimeout(timer);}
  }
  function script(){
    if(window.qrcode)return Promise.resolve();if(generatorPromise)return generatorPromise;
    generatorPromise=new Promise((resolve,reject)=>{const s=document.createElement('script');s.src='js/qr-libs/qrcode.js';const timer=setTimeout(()=>{s.remove();reject(Error('No se pudo cargar el generador QR.'));},10000);s.onload=()=>{clearTimeout(timer);resolve();};s.onerror=()=>{clearTimeout(timer);s.remove();reject(Error('No se pudo cargar el generador QR.'));};document.head.append(s);}).catch(e=>{generatorPromise=null;throw e;});return generatorPromise;
  }
  function reader(){if(!readerPromise){let timer;readerPromise=Promise.race([import('./qr-libs/qr-scanner.min.js').then(m=>m.default),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('reader-timeout')),10000);})]).catch(e=>{readerPromise=null;throw Error('No se pudo cargar el lector QR. Podés ingresar el código.');}).finally(()=>clearTimeout(timer));}return readerPromise;}
  function cerrar(){++epoch;scanner?.destroy();scanner=null;overlay?.remove();overlay=null;previewCode='';document.removeEventListener('keydown',teclas);lastFocus?.focus();lastFocus=null;}
  function teclas(e){if(e.key==='Escape'){cerrar();return;}if(e.key==='Tab'&&overlay){const nodes=[...overlay.querySelectorAll('button,input,select,a[href]')].filter(n=>!n.disabled&&n.offsetParent!==null);if(!nodes.length)return;const first=nodes[0],last=nodes.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last.focus();}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first.focus();}}}
  function modal(title){cerrar();lastFocus=document.activeElement;overlay=document.createElement('div');overlay.className='modal-overlay qr-overlay';overlay.innerHTML=`<div class="modal-card qr-card" role="dialog" aria-modal="true" aria-labelledby="qr-title"><div class="modal-header"><h3 id="qr-title">${escapeHtml(title)}</h3><button type="button" class="avatar-editor-close" aria-label="Cerrar">${Icon('x',{size:18})}</button></div><div id="qr-content"></div><p id="qr-estado" role="status" aria-live="polite"></p></div>`;overlay.querySelector('[aria-label="Cerrar"]').onclick=cerrar;overlay.onclick=e=>{if(e.target===overlay)cerrar();};document.body.append(overlay);document.addEventListener('keydown',teclas);overlay.querySelector('button').focus();return epoch;}
  const status=text=>{const node=document.getElementById('qr-estado');if(node)node.textContent=text;};
  async function compartir(rid){const mine=modal('Compartir rutina');status('Preparando código seguro…');
    try{await script();if(mine!==epoch)return;const data=await api({accion:'compartir',rutina_id:rid});if(mine!==epoch)return;
      const qr=qrcode(0,'M');qr.addData(data.codigo,'Byte');qr.make();const content=document.getElementById('qr-content');content.innerHTML=`<p>Compartís únicamente esta rutina. Quien tenga el código puede importarla durante 7 días.</p><div class="qr-image" role="img" aria-label="Código QR de la rutina">${qr.createSvgTag({cellSize:5,margin:20,scalable:true})}</div><label for="qr-codigo">Código de compartir</label><input id="qr-codigo" readonly><div class="qr-actions"><button id="qr-copy" type="button" class="btn btn-ghost">Copiar código</button><a id="qr-save" class="btn btn-ghost" download="nutrifit-rutina-qr.svg">Guardar QR</a><button id="qr-revoke" type="button" class="btn btn-ghost">Revocar código</button></div>`;
      document.getElementById('qr-codigo').value=data.codigo;document.getElementById('qr-save').href='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(qr.createSvgTag({cellSize:5,margin:20,scalable:true}));
      document.getElementById('qr-copy').onclick=async()=>{try{await navigator.clipboard.writeText(data.codigo);status('Código copiado.');}catch{document.getElementById('qr-codigo').select();status('Copiá el código seleccionado.');}};
      document.getElementById('qr-revoke').onclick=async()=>{const b=document.getElementById('qr-revoke');b.disabled=true;try{await api({accion:'revocar',rutina_id:rid});if(mine===epoch){content.replaceChildren();status('Código revocado. Las copias ya importadas se conservan.');}}catch(e){if(mine===epoch){b.disabled=false;status(e.message);}}};status('Vence: '+data.expira_en+' (Argentina). Generar otro QR revoca el anterior.');
    }catch(e){if(mine===epoch)status(e.message);}
  }
  function importar(){modal('Importar rutina');const content=document.getElementById('qr-content');content.innerHTML=`<p>Escaneá un QR NutriFit, subí una imagen o ingresá su código. Revisá la rutina antes de copiarla.</p><video id="qr-video" playsinline muted hidden></video><div class="qr-actions"><button id="qr-camera" class="btn btn-ghost" type="button">${Icon('camera',{size:16})}Escanear con cámara</button><label class="btn btn-ghost" for="qr-file">Leer imagen QR</label><input id="qr-file" type="file" accept="image/*" hidden></div><label for="qr-token">Código de rutina</label><input id="qr-token" maxlength="100" autocomplete="off" spellcheck="false" placeholder="NF-RUTINA-1:…"><button id="qr-preview-btn" type="button" class="btn btn-primary">Previsualizar rutina</button><div id="qr-preview"></div>`;
    document.getElementById('qr-camera').onclick=camara;document.getElementById('qr-file').onchange=imagen;document.getElementById('qr-preview-btn').onclick=preview;document.getElementById('qr-token').oninput=()=>{previewCode='';document.getElementById('qr-preview').replaceChildren();};document.getElementById('qr-token').onkeydown=e=>{if(e.key==='Enter')preview();};
  }
  async function camara(){const mine=epoch;const b=document.getElementById('qr-camera');b.disabled=true;
    if(!navigator.mediaDevices?.getUserMedia||!window.isSecureContext){status('La cámara requiere HTTPS o localhost. Ingresá el código o una imagen.');b.disabled=false;return;}
    status('Solicitando cámara…');let own,timer;
    try{const Reader=await reader();if(mine!==epoch)return;const video=document.getElementById('qr-video');video.hidden=false;
      scanner=new Reader(video,result=>{if(mine!==epoch)return;scanner?.stop();video.hidden=true;document.getElementById('qr-token').value=result.data;preview();},{preferredCamera:'environment',returnDetailedScanResult:true,maxScansPerSecond:8});own=scanner;
      await Promise.race([own.start(),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('camera-timeout')),12000);})]);
      if(mine!==epoch){own.destroy();return;}status('Apuntá al QR de NutriFit. También podés ingresar el código.');
    }catch(e){own?.destroy();if(mine===epoch){scanner=null;document.getElementById('qr-video').hidden=true;status('No pudimos acceder a la cámara. Revisá el permiso o usá código / imagen.');}}
    finally{clearTimeout(timer);if(mine===epoch)b.disabled=false;}
  }
  async function imagen(e){const mine=epoch;const file=e.target.files?.[0];e.target.value='';if(!file)return;if(file.size>10*1024*1024){status('La imagen debe pesar menos de 10 MB.');return;}scanner?.stop();status('Leyendo QR de la imagen…');
    let timer;try{const Reader=await reader();const result=await Promise.race([Reader.scanImage(file,{returnDetailedScanResult:true}),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('image-timeout')),12000);})]);if(mine!==epoch)return;document.getElementById('qr-token').value=result.data;await preview();}catch{if(mine===epoch)status('No encontramos un QR legible en esa imagen. Podés ingresar el código.');}finally{clearTimeout(timer);}
  }
  async function preview(){const mine=epoch;scanner?.stop();document.getElementById('qr-video').hidden=true;const code=document.getElementById('qr-token').value.trim();previewCode='';document.getElementById('qr-preview').replaceChildren();
    if(!/^(?:NF-RUTINA-1:)?[a-f0-9]{64}$/.test(code)){status('Código inválido. Usá un QR o código de rutina NutriFit.');return;}
    const b=document.getElementById('qr-preview-btn');b.disabled=true;status('Validando rutina…');
    try{const data=await api({accion:'preview',codigo:code});if(mine!==epoch||document.getElementById('qr-token').value.trim()!==code)return;previewCode=code;const r=data.rutina;
      document.getElementById('qr-preview').innerHTML=`<h4>${escapeHtml(r.titulo)}</h4><p>${escapeHtml(r.lugar)} · ${escapeHtml(r.nivel_dificultad)}</p><ol>${r.ejercicios.map(e=>`<li><strong>${escapeHtml(e.nombre)}</strong><span>${Number(e.series)} × ${escapeHtml(e.repeticiones)}${e.descanso_segundos==null?'':' · descanso '+Number(e.descanso_segundos)+' s'}</span></li>`).join('')}</ol><p>Se guardará una copia. La original permanece intacta. Esta rutina no está adaptada automáticamente a tu perfil.</p><button id="qr-confirm" class="btn btn-primary" type="button">Confirmar importación</button>`;document.getElementById('qr-confirm').onclick=confirmar;status('Rutina validada. Confirmá si querés incorporarla.');
    }catch(e){if(mine===epoch)status(e.message);}finally{if(mine===epoch)b.disabled=false;}
  }
  async function confirmar(){if(!previewCode)return;const mine=epoch;const b=document.getElementById('qr-confirm');b.disabled=true;status('Importando copia…');
    try{const data=await api({accion:'importar',codigo:previewCode});if(mine!==epoch)return;status(data.ya_importada?'Ya tenés esta rutina. No creamos otra copia.':'Rutina importada. Disponible en tus rutinas de Actividad.');document.getElementById('qr-preview').replaceChildren();previewCode='';await controles();}
    catch(e){if(mine===epoch){status(e.message);b.disabled=false;}}
  }
  function acciones(r){return `<div class="rutina-qr-actions">${r.usuario_id!=null?`<button class="btn btn-ghost" type="button" onclick="RutinasQR.compartir(${Number(r.id)})">${Icon('qr-code',{size:16})}Compartir rutina</button>`:''}<button class="btn btn-ghost" type="button" onclick="RutinasQR.importar()">${Icon('scan',{size:16})}Importar rutina</button></div>`;}
  async function controles(){const holder=document.querySelector('.view.active #rutinas-importadas');if(!holder)return;
    try{const data=await api({accion:'listar'});if(!holder.isConnected)return;holder.innerHTML=`<label for="rutina-seleccion">Rutina para entrenar</label><select id="rutina-seleccion"><option value="0">Mi rutina personalizada</option>${data.rutinas.map(r=>`<option value="${Number(r.id)}" ${Number(r.seleccionada)?'selected':''}>Importada: ${escapeHtml(r.titulo)}</option>`).join('')}</select><p class="muted">Elegir una copia no cambia tu rutina personalizada. Importar o cambiar de rutina no entrega XP.</p>`;holder.querySelector('select').onchange=async e=>{const sel=e.target;sel.disabled=true;try{await api({accion:'seleccionar',rutina_id:Number(sel.value)});await renderActividad();}catch(err){showToast(err.message);sel.disabled=false;}};
    }catch(e){holder.textContent='No pudimos cargar tus rutinas importadas. Volvé a entrar a Actividad.';}
  }
  return {compartir,importar,cerrar,acciones,controles};
})();
