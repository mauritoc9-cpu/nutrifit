/* Chat conversacional: permisos separados, propuestas revisables y voz opcional. */
const Asistente = (() => {
  let cid = null, csrf = '', controller = null, busy = false, epoch = 0, recognition = null;
  let pending = null, permissions = { mensajes_gemini: false, consultar_datos: false, enviar_datos: false };
  const suggestions = ['Tengo pollo, arroz y huevo. ¿Qué puedo cocinar?', '¿Qué puedo comer después de entrenar?', 'Tengo 20 minutos para cocinar', '¿Cuántas calorías consumí hoy?'];
  const $ = id => document.getElementById(id);
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const a=crypto.getRandomValues(new Uint8Array(1))[0]&15;return(c==='x'?a:(a&3|8)).toString(16);});
  function status(text) { if ($('asistente-status')) $('asistente-status').textContent = text; }
  async function api(body = null, id = null, signal = null) {
    const local = signal ? null : new AbortController();
    const timer = local ? setTimeout(() => local.abort(), 12000) : null;
    try {
      const response = await fetch(`${API_BASE}/asistente/conversaciones.php${id ? '?id=' + encodeURIComponent(id) : ''}`, body ? { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ...body, csrf }), signal: signal || local.signal } : { credentials: 'include', signal: signal || local.signal });
      let res; try { res = await response.json(); } catch (e) { throw new Error('Recibimos una respuesta inesperada. Volvé a intentar.'); }
      if (!res.success) throw new Error(res.message || 'No pudimos completar la consulta.');
      if (res.data?.csrf) csrf = res.data.csrf;
      return res.data;
    } catch (e) { if (local && e.name === 'AbortError') throw new Error('Tiempo agotado. Volvé a intentar.'); throw e; }
    finally { if (timer) clearTimeout(timer); }
  }
  function scrollChat() { const log = $('asistente-messages'); if (log) log.scrollTop = log.scrollHeight; }
  function append(rol, text, source = '', proposal = null) {
    const article = document.createElement('article'); article.className = 'asistente-message asistente-message--' + rol;
    const label = document.createElement('strong'); label.textContent = rol === 'user' ? 'Vos' : 'NutriFit';
    const content = document.createElement('p'); content.textContent = text;
    article.append(label, content);
    if (rol === 'assistant') {
      const meta = document.createElement('small'); meta.textContent = source === 'gemini' ? 'Conversación con Gemini · cifras calculadas por NutriFit' : 'NutriFit · respuesta local'; article.append(meta);
      if (proposal?.disponible) article.append(proposalCard(proposal));
      if ('speechSynthesis' in window && 'SpeechSynthesisUtterance' in window) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-secondary'; button.textContent = 'Escuchar';
        button.onclick = () => {
          window.speechSynthesis.cancel();
          const utterance = new SpeechSynthesisUtterance(text); utterance.lang = 'es-AR';
          const voices = window.speechSynthesis.getVoices(); utterance.voice = voices.find(v => v.localService && v.lang.startsWith('es')) || null;
          if (!utterance.voice) { status('No hay una voz local en español disponible. Podés leer la respuesta.'); return; }
          status(utterance.voice && !utterance.voice.localService ? 'La voz elegida usa un servicio remoto del navegador.' : 'Escuchando respuesta.');
          utterance.onend = () => status(''); utterance.onerror = event => status(['canceled', 'interrupted'].includes(event.error) ? 'Lectura detenida.' : 'No pudimos reproducir esta respuesta.');
          window.speechSynthesis.speak(utterance);
        }; article.append(button);
      }
    }
    $('asistente-messages').append(article);
    scrollChat();
  }

  function proposalCard(p) {
    const card = document.createElement('div'); card.className = 'asistente-proposal';
    const title = document.createElement('strong'); title.textContent = 'Revisá la porción total'; card.append(title);
    p.items.forEach(i => { const row = document.createElement('p'); row.textContent = `${i.nombre}: ${i.gramos} g · ${i.preparacion || 'preparación no indicada'}${i.supuesto ? '\nSupuesto: ' + i.supuesto : ''}\n${i.aporte.calorias} kcal · P ${i.aporte.proteinas} g · C ${i.aporte.carbohidratos} g · G ${i.aporte.grasas} g`; card.append(row); });
    const total = document.createElement('p'); total.className = 'asistente-total'; total.textContent = `Total aproximado: ${p.total.calorias} kcal · P ${p.total.proteinas} g · C ${p.total.carbohidratos} g · G ${p.total.grasas} g`; card.append(total);
    const note = document.createElement('small'); note.textContent = [...(p.advertencias || []), p.incertidumbre].filter(Boolean).join('\n'); card.append(note);
    const label = document.createElement('label'); label.textContent = 'Tipo de comida'; const select = document.createElement('select'); select.setAttribute('aria-label', 'Tipo de comida para esta propuesta');
    [['almuerzo', 'Almuerzo'], ['cena', 'Cena'], ['desayuno', 'Desayuno'], ['merienda', 'Merienda'], ['snack', 'Snack']].forEach(([v, t]) => select.append(new Option(t, v))); label.append(select); card.append(label);
    if (p.tipo_comida) select.value = p.tipo_comida; select.disabled = !!p.registrada || p.vigente === false;
    const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-primary'; button.textContent = p.registrada ? 'Ya registrada' : p.vigente === false ? 'Propuesta vencida' : 'Confirmar y registrar en mi día'; button.disabled = !!p.registrada || p.vigente === false; card.append(button);
    button.onclick = async () => {
      if (busy || button.disabled) return; const token = epoch; busy = true; controls(); button.disabled = true; status('Registrando la comida confirmada…');
      const actionController = new AbortController(); controller = actionController; const timer = setTimeout(() => actionController.abort(), 25000);
      try {
        const r = await fetch(`${API_BASE}/nutricion/registrar_comida.php`, { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ accion_asistente: p.token, confirmado: true, tipo_comida: select.value, csrf }), signal: actionController.signal });
        const res = await r.json(); if (!res.success) throw new Error(res.message || 'No pudimos registrar la comida.');
        p.registrada = true; select.disabled = true; button.textContent = 'Ya registrada'; if (token === epoch) {
          status(res.message || 'Comida registrada en tu diario.');
          if (!res.data?.repetida && typeof handleGamificationResponse === 'function') handleGamificationResponse(res.data?.gamificacion, res.data?.xp);
        }
        // El próximo acceso a Nutrición obtiene el diario real; el mismo token nunca duplica comida/XP.
      } catch (e) { if (token === epoch) status(e.name === 'AbortError' ? 'Registro cancelado o sin respuesta. Volvé a confirmar para comprobar el resultado sin duplicarlo.' : e.message === 'Failed to fetch' ? 'Sin conexión. Podés confirmar otra vez: esta propuesta no se duplica.' : e.message); button.disabled = false; }
      finally { clearTimeout(timer); if (token === epoch) { controller = null; busy = false; controls(); } }
    };
    return card;
  }
  function welcome() {
    $('asistente-messages').replaceChildren();
    append('assistant', 'Hola. Contame qué ingredientes tenés, qué querés cocinar o qué duda tenés sobre entrenamiento. Puedo ayudarte a estimar una comida y ofrecer registrarla: siempre tendrás que revisar y confirmar.');
  }
  function populate(conversations) {
    const select = $('asistente-history'); select.replaceChildren(new Option('Elegí una conversación', ''));
    conversations.forEach(c => select.append(new Option(c.titulo, String(c.id)))); select.value = cid ? String(cid) : '';
  }
  function controls() {
    ['asistente-send', 'asistente-new', 'asistente-delete', 'asistente-history', 'asistente-input', 'asistente-mic', 'asistente-gemini', 'asistente-datos', 'asistente-enviar-datos', 'asistente-retry'].forEach(id => { if ($(id)) $(id).disabled = busy || (id === 'asistente-enviar-datos' && !permissions.servidor_datos_habilitados); });
    $('asistente-cancel').hidden = !busy;
    if ($('asistente-thinking')) $('asistente-thinking').hidden = !busy;
  }
  function displayPermissions(p) {
    permissions = p || { mensajes_gemini: false, consultar_datos: false, enviar_datos: false };
    $('asistente-gemini').checked = !!permissions.mensajes_gemini; $('asistente-datos').checked = !!permissions.consultar_datos; $('asistente-enviar-datos').checked = !!permissions.enviar_datos;
    $('asistente-enviar-datos').disabled = !permissions.servidor_datos_habilitados; $('asistente-privacy-note').textContent = permissions.servidor_datos_habilitados ? 'Compartir registros requiere también consultar datos y usar Gemini. Podés revocar estos permisos.' : 'El servidor bloquea compartir registros personales con Gemini. Podés consultarlos dentro de NutriFit.';
  }
  async function savePermissions() {
    if (busy) return; const next = { mensajes_gemini: $('asistente-gemini').checked, consultar_datos: $('asistente-datos').checked, enviar_datos: $('asistente-enviar-datos').checked && $('asistente-gemini').checked && $('asistente-datos').checked };
    busy = true; controls();
    try { if (!cid) { const c = await api({ accion: 'nueva' }); cid = c.id; } const res = await api({ accion: 'permisos', id: cid, permisos: next }); displayPermissions(res.permisos); populate((await api()).conversaciones); status('Permisos guardados para esta conversación.'); }
    catch (e) { displayPermissions(permissions); status(e.message); } finally { busy = false; controls(); }
  }
  async function open(id) {
    if (busy) return;
    const token = ++epoch; status('Obteniendo conversación…');
    try { const data = await api(null, id); if (token !== epoch) return; pending = null; $('asistente-retry').hidden = true; cid = id || null; displayPermissions(data.permisos); populate(data.conversaciones); welcome(); if (data.mensajes.length) { $('asistente-messages').replaceChildren(); data.mensajes.forEach(m => append(m.rol, m.texto, m.origen, m.propuesta)); } status(''); }
    catch (e) { if (token === epoch) status(e.message === 'Failed to fetch' ? 'Sin conexión. Volvé a intentar.' : e.message); }
  }
  async function newChat() {
    if (busy) return; busy = true; controls(); status('Creando conversación…');
    try { const c = await api({ accion: 'nueva' }); cid = c.id; pending = null; $('asistente-retry').hidden = true; welcome(); const data = await api(null, cid); displayPermissions(data.permisos); populate(data.conversaciones); status('Conversación vacía. Elegí tus permisos y escribí.'); }
    catch (e) { status(e.message); } finally { busy = false; controls(); }
  }
  async function send(retry = false) {
    if (busy) return;
    const input = $('asistente-input'), text = retry && pending ? pending.text : input.value.trim(); if (!text || [...text].length > 2000) { status('Escribí hasta 2000 caracteres.'); return; }
    stopRecognition(); window.speechSynthesis?.cancel(); busy = true; controls();
    const token = ++epoch; controller = new AbortController(); const timer = setTimeout(() => controller?.abort(), 35000);
    const reuse = !!pending && pending.text === text && pending.cid === cid;
    const requestId = reuse ? pending.id : uuid();
    pending = { text, id: requestId, cid }; $('asistente-retry').hidden = true;
    try {
      status('Obteniendo datos / pensando…');
      if (!cid) { const c = await api({ accion: 'nueva' }, null, controller.signal); cid = c.id; }
      pending.cid = cid;
      if (!reuse) append('user', text); input.value = '';
      const res = await api({ accion: 'enviar', id: cid, texto: text, solicitud: requestId }, null, controller.signal);
      if (token !== epoch) return; append('assistant', res.texto, res.origen, res.propuesta); status('');
      if (['timeout', 'conexion', 'cuota', 'proveedor', 'respuesta_invalida', 'configuracion'].includes(res.codigo)) { pending.id = uuid(); $('asistente-retry').hidden = false; } else pending = null;
      populate((await api(null, null, controller.signal)).conversaciones);
    } catch (e) { if (token === epoch) { $('asistente-retry').hidden = false; status(e.name === 'AbortError' ? 'Consulta cancelada o tiempo agotado. Reintentar recupera la misma solicitud si el servidor ya terminó.' : e.message === 'Failed to fetch' ? 'Sin conexión. Podés reintentar tu pregunta.' : e.message); if (!input.value) input.value = text; } }
    finally { clearTimeout(timer); if (token === epoch) { controller = null; busy = false; controls(); } }
  }
  function stopRecognition() { if (recognition) { recognition.onend = null; recognition.abort(); recognition = null; } if ($('asistente-mic')) $('asistente-mic').textContent = 'Dictar'; }
  function dictation() {
    if (recognition) { stopRecognition(); status('Dictado cancelado. Podés editar el texto.'); return; }
    const Constructor = window.SpeechRecognition || window.webkitSpeechRecognition; if (!Constructor) { status('Dictado no disponible en este navegador.'); return; }
    try {
      recognition = new Constructor(); recognition.lang = 'es-AR'; recognition.continuous = false; recognition.interimResults = false;
      const original = $('asistente-input').value;
      recognition.onresult = event => { const spoken = Array.from(event.results).map(r => r[0].transcript).join(' '); $('asistente-input').value = (original + ' ' + spoken).trim().slice(0, 2000); status('Transcripción lista. Revisala y enviá manualmente.'); };
      recognition.onerror = event => { status(event.error === 'not-allowed' ? 'Permiso de micrófono denegado. Podés escribir.' : 'No pudimos transcribir. Podés escribir.'); stopRecognition(); };
      recognition.onend = () => { recognition = null; if ($('asistente-mic')) $('asistente-mic').textContent = 'Dictar'; };
      status('El dictado puede enviar audio al servicio del navegador. Tocá Dictar otra vez para cancelar.'); $('asistente-mic').textContent = 'Cancelar dictado'; recognition.start();
    } catch (e) { stopRecognition(); status('El dictado no está disponible. Podés escribir.'); }
  }
  async function render() {
    salir();
    $('view-asistente').innerHTML = `<div class="asistente-page"><span class="section-label">CONVERSÁ CON NUTRIFIT</span><h1>NutriFit IA</h1><p>Ideas, recetas y porciones. Los registros siempre requieren tu confirmación. Historial: hasta 30 días sin actividad.</p><details class="asistente-privacy"><summary>Privacidad y permisos de esta conversación</summary><label><input type="checkbox" id="asistente-gemini"> Usar Gemini para mis mensajes</label><p>Envía tu texto y hasta 12 mensajes recientes a Google Gemini. No incluyas secretos ni datos sensibles. Los registros de tu cuenta requieren permisos separados.</p><label><input type="checkbox" id="asistente-datos"> Consultar mis datos en NutriFit</label><label><input type="checkbox" id="asistente-enviar-datos" disabled> Compartir los registros relevantes con Gemini</label><p id="asistente-privacy-note"></p></details><details class="asistente-history"><summary>Conversaciones recientes</summary><label for="asistente-history">Conversaciones recientes</label><select id="asistente-history"></select><div><button type="button" class="btn btn-secondary" id="asistente-new">Nueva conversación</button><button type="button" class="btn btn-secondary" id="asistente-delete">Eliminar conversación</button></div></details><details class="asistente-starters"><summary>Ideas para empezar</summary><div class="asistente-suggestions" id="asistente-suggestions"></div></details><div id="asistente-messages" class="asistente-messages" role="log" aria-live="polite"></div><p id="asistente-thinking" class="asistente-thinking" role="status" hidden>NutriFit está respondiendo<span aria-hidden="true"> ···</span></p><form class="asistente-compose" id="asistente-form"><label for="asistente-input">Tu pregunta</label><textarea id="asistente-input" maxlength="2000" rows="3" placeholder="Contame qué tenés para cocinar o preguntame lo que quieras…"></textarea><div class="asistente-actions"><button type="submit" class="btn btn-primary" id="asistente-send">Enviar</button><button type="button" class="btn btn-secondary" id="asistente-mic" hidden>Dictar</button><button type="button" class="btn btn-secondary" id="asistente-stop" hidden>Detener voz</button><button type="button" class="btn btn-secondary" id="asistente-cancel" hidden>Cancelar consulta</button></div><p class="asistente-voice-note">El dictado puede usar servidores del navegador. No guardamos audio. Revisá la transcripción antes de enviar.</p><button type="button" class="btn btn-secondary" id="asistente-retry" hidden>Reintentar pregunta</button><p id="asistente-status" role="status"></p></form></div>`;
    suggestions.forEach(text => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-secondary'; b.textContent = text; b.onclick = () => { $('asistente-input').value = text; $('asistente-input').focus(); }; $('asistente-suggestions').append(b); });
    $('asistente-form').onsubmit = e => { e.preventDefault(); send(); };
    $('asistente-new').onclick = newChat; $('asistente-history').onchange = e => open(Number(e.target.value) || null);
    $('asistente-delete').onclick = async () => { if (!cid || busy) return; if (!confirm('¿Eliminar esta conversación y sus mensajes?')) return; try { await api({ accion: 'eliminar', id: cid }); await open(null); } catch (e) { status(e.message); } };
    $('asistente-mic').hidden = !(window.SpeechRecognition || window.webkitSpeechRecognition); $('asistente-mic').onclick = dictation;
    $('asistente-stop').hidden = !('speechSynthesis' in window); $('asistente-stop').onclick = () => { window.speechSynthesis?.cancel(); status('Lectura detenida.'); };
    $('asistente-cancel').onclick = () => controller?.abort();
    ['asistente-gemini', 'asistente-datos', 'asistente-enviar-datos'].forEach(id => { $(id).onchange = savePermissions; });
    $('asistente-retry').onclick = () => send(true);
    await open(null);
  }
  function salir() { ++epoch; controller?.abort(); controller = null; busy = false; stopRecognition(); window.speechSynthesis?.cancel(); }
  return { render, salir };
})();
