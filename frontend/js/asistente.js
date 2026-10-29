/* Ayuda local de NutriFit. Historial privado de la sesión PHP y voz opcional. */
const Asistente = (() => {
  let cid = null, csrf = '', controller = null, busy = false, epoch = 0, recognition = null;
  let pending = null, sessionExpired = false;
  const suggestions = ['¿Qué es NutriFit?', '¿Cómo registro alimentos y agua?', '¿Cómo uso las rutinas?', '¿Cómo comparto una rutina por QR?', '¿Cómo exporto mi progreso?'];
  const $ = id => document.getElementById(id);
  const uuid = () => crypto.randomUUID ? crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const a=crypto.getRandomValues(new Uint8Array(1))[0]&15;return(c==='x'?a:(a&3|8)).toString(16);});
  function status(text) { if ($('asistente-status')) $('asistente-status').textContent = text; }
  async function api(body = null, id = null, signal = null) {
    const local = signal ? null : new AbortController();
    const timer = local ? setTimeout(() => local.abort(), 12000) : null;
    try {
      const response = await fetch(`${API_BASE}/asistente/ayuda.php${id ? '?id=' + encodeURIComponent(id) : ''}`, body ? { method: 'POST', credentials: 'include', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.fromEntries(Object.entries({ ...body, csrf }).filter(([key]) => key !== 'csrfReintentado'))), signal: signal || local.signal } : { credentials: 'include', signal: signal || local.signal });
      let res; try { res = await response.json(); } catch (e) { throw new Error('Recibimos una respuesta inesperada. Volvé a intentar.'); }
      if (response.status === 401 || res.data?.codigo === 'sesion_vencida') {
        sessionExpired = true;
        if ($('asistente-login')) $('asistente-login').hidden = false;
        controls();
        throw new Error('Tu sesión venció. Volvé a iniciar sesión para usar la ayuda.');
      }
      // Actualiza CSRF una sola vez; nunca elimina su comprobación en el servidor.
      if (!res.success && res.data?.codigo === 'csrf_invalido' && body && body.csrfReintentado !== true) {
        const fresh = await fetch(API_BASE + '/asistente/ayuda.php', {credentials:'include',signal:signal || local?.signal});
        const refreshed = await fresh.json();
        if (fresh.status === 401) {
          sessionExpired = true; if ($('asistente-login')) $('asistente-login').hidden = false; controls();
          throw new Error('Tu sesión venció. Volvé a iniciar sesión.');
        }
        if (!refreshed.success || !refreshed.data?.csrf) throw new Error('Volvé a abrir el asistente para renovar su autorización.');
        csrf = refreshed.data.csrf;
        return api({...body, csrfReintentado:true},id,signal);
      }
      if (!res.success) throw new Error(res.message || 'No pudimos completar la consulta.');
      if (res.data?.csrf) csrf = res.data.csrf;
      return res.data;
    } catch (e) { if (local && e.name === 'AbortError') throw new Error('Tiempo agotado. Volvé a intentar.'); throw e; }
    finally { if (timer) clearTimeout(timer); }
  }
  let speaking = false, voiceEpoch = 0;
  function stopVoice() {
    ++voiceEpoch;
    speaking = false;
    window.speechSynthesis?.cancel();
    if ($('asistente-stop')) $('asistente-stop').hidden = true;
  }
  function closeTools() { document.querySelectorAll('.asistente-tools details').forEach(el => { el.open = false; }); }
  function scrollChat() { const log = $('asistente-messages'); if (log) log.scrollTop = log.scrollHeight; }
  function append(rol, text, source = '', proposal = null) {
    const article = document.createElement('article'); article.className = 'asistente-message asistente-message--' + rol;
    const label = document.createElement('strong'); label.textContent = rol === 'user' ? 'Vos' : 'NutriFit';
    const content = document.createElement('p'); content.textContent = text;
    article.append(label, content);
    if (rol === 'assistant') {
      const meta = document.createElement('small'); meta.textContent = 'Ayuda NutriFit · preguntas frecuentes locales'; article.append(meta);
      // En modo ayuda no se ofrecen registros ni propuestas del historial remoto.
      if ('speechSynthesis' in window && 'SpeechSynthesisUtterance' in window) {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-secondary'; button.textContent = 'Escuchar';
        button.onclick = () => {
          stopVoice();
          const voiceToken = voiceEpoch;
          const utterance = new SpeechSynthesisUtterance(text); utterance.lang = 'es-AR';
          const voices = window.speechSynthesis.getVoices(); utterance.voice = voices.find(v => v.localService && v.lang.startsWith('es')) || null;
          if (!utterance.voice) { status('No hay una voz local en español disponible. Podés leer la respuesta.'); return; }
          status(utterance.voice && !utterance.voice.localService ? 'La voz elegida usa un servicio remoto del navegador.' : 'Escuchando respuesta.');
          utterance.onstart = () => { if (voiceToken !== voiceEpoch) return; speaking = true; if ($('asistente-stop')) $('asistente-stop').hidden = false; };
          utterance.onend = () => { if (voiceToken !== voiceEpoch) return; speaking = false; if ($('asistente-stop')) $('asistente-stop').hidden = true; status(''); }; utterance.onerror = event => { if (voiceToken !== voiceEpoch) return; speaking = false; if ($('asistente-stop')) $('asistente-stop').hidden = true; status(['canceled', 'interrupted'].includes(event.error) ? 'Lectura detenida.' : 'No pudimos reproducir esta respuesta.'); };
          window.speechSynthesis.speak(utterance);
        }; article.append(button);
      }
    }
    $('asistente-messages').append(article);
    scrollChat();
  }

  function welcome() {
    $('asistente-messages').replaceChildren();
    append('assistant', 'Hola. Soy el asistente de ayuda de NutriFit. Respondo preguntas frecuentes con reglas locales, sin IA generativa ni servicios externos. Puedo explicarte cómo usar alimentos, agua, rutinas, avatar, puntos, progreso, mapa, QR y exportaciones.');
  }
  function populate(conversations) {
    const select = $('asistente-history'); select.replaceChildren(new Option('Elegí una conversación', ''));
    conversations.forEach(c => select.append(new Option(c.titulo, String(c.id)))); select.value = cid ? String(cid) : '';
  }
  function controls() {
    ['asistente-send', 'asistente-new', 'asistente-delete', 'asistente-history', 'asistente-input', 'asistente-mic', 'asistente-retry'].forEach(id => { if ($(id)) $(id).disabled = busy || sessionExpired; });
    $('asistente-cancel').hidden = !busy;
    if ($('asistente-thinking')) $('asistente-thinking').hidden = !busy;
  }
  async function open(id) {
    if (busy || sessionExpired) return;
    closeTools(); stopVoice();
    const token = ++epoch; status('Obteniendo conversación…');
    try { const data = await api(null, id); if (token !== epoch) return; pending = null; $('asistente-retry').hidden = true; cid = id || null; populate(data.conversaciones); welcome(); if (data.mensajes.length) { $('asistente-messages').replaceChildren(); data.mensajes.forEach(m => append(m.rol, m.texto, m.origen, m.propuesta)); } status(''); }
    catch (e) { if (token === epoch) status(e.message === 'Failed to fetch' ? 'Sin conexión. Volvé a intentar.' : e.message); }
  }
  async function newChat() {
    if (busy) return; closeTools(); stopVoice(); busy = true; controls(); status('Creando conversación…');
    try { const c = await api({ accion: 'nueva' }); cid = c.id; pending = null; $('asistente-retry').hidden = true; welcome(); const data = await api(null, cid); populate(data.conversaciones); status('Conversación vacía. Escribí tu consulta sobre NutriFit.'); }
    catch (e) { status(e.message); } finally { busy = false; controls(); }
  }
  async function send(retry = false) {
    if (busy) return;
    const input = $('asistente-input'), text = retry && pending ? pending.text : input.value.trim(); if (!text || [...text].length > 2000) { status('Escribí hasta 2000 caracteres.'); return; }
    closeTools(); stopRecognition(); stopVoice(); busy = true; controls();
    const token = ++epoch; controller = new AbortController(); const timer = setTimeout(() => controller?.abort(), 35000);
    const reuse = !!pending && pending.text === text && pending.cid === cid;
    const requestId = reuse ? pending.id : uuid();
    pending = { text, id: requestId, cid }; $('asistente-retry').hidden = true;
    try {
      status('Buscando ayuda de NutriFit…');
      if (!cid) { const c = await api({ accion: 'nueva' }, null, controller.signal); cid = c.id; }
      pending.cid = cid;
      if (!reuse) append('user', text); input.value = ''; input.style.height = '';
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
  // El teclado móvil reduce el viewport visual: el panel se acomoda encima para que el campo
  // de texto y los botones nunca queden tapados.
  function ajustarTeclado() {
    const vv = window.visualViewport, panel = $('view-asistente');
    if (!vv || !panel) return;
    const aplicar = () => {
      panel.style.setProperty('--asistente-kb', Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)) + 'px');
      panel.style.setProperty('--asistente-vh', Math.round(vv.height) + 'px');
      const teclado = window.innerHeight - vv.height - vv.offsetTop > 120;
      panel.classList.toggle('asistente-teclado', teclado);
      if (teclado) requestAnimationFrame(scrollChat);
    };
    if (!ajustarTeclado.enlazado) { vv.addEventListener('resize', aplicar); vv.addEventListener('scroll', aplicar); ajustarTeclado.enlazado = true; }
    aplicar();
  }
  async function render() {
    salir(); sessionExpired = false; csrf = '';
    $('view-asistente').innerHTML = `<div class="asistente-page"><div class="asistente-header"><div><h1>Ayuda NutriFit</h1><p>Ayuda local · historial de esta sesión</p></div><button type="button" class="btn btn-secondary" id="asistente-new">Nueva charla</button></div><div class="asistente-tools"><details class="asistente-history"><summary>Recientes</summary><label for="asistente-history">Conversaciones recientes</label><select id="asistente-history"></select><div><button type="button" class="btn btn-secondary" id="asistente-delete">Eliminar conversación</button></div></details><details class="asistente-starters"><summary>Ideas</summary><div class="asistente-suggestions" id="asistente-suggestions"></div></details></div><div id="asistente-messages" class="asistente-messages" role="log" aria-live="polite"></div><p id="asistente-thinking" class="asistente-thinking" role="status" hidden>NutriFit está respondiendo<span aria-hidden="true"> ···</span></p><form class="asistente-compose" id="asistente-form"><label for="asistente-input">Tu pregunta</label><textarea id="asistente-input" maxlength="2000" rows="2" placeholder="Por ejemplo: ¿Cómo registro agua?"></textarea><div class="asistente-actions"><button type="submit" class="btn btn-primary" id="asistente-send">Enviar</button><button type="button" class="btn btn-secondary" id="asistente-mic" hidden>Dictar</button><button type="button" class="btn btn-secondary" id="asistente-stop" hidden>Detener voz</button><button type="button" class="btn btn-secondary" id="asistente-cancel" hidden>Cancelar consulta</button></div><p class="asistente-voice-note">Enter para enviar · Mayús + Enter: salto. Dictado opcional: puede usar el servicio de voz del navegador.</p><button type="button" class="btn btn-secondary" id="asistente-retry" hidden>Reintentar pregunta</button><p id="asistente-status" role="status"></p><a id="asistente-login" class="btn btn-secondary" href="./?acceso=login" hidden>Volver a iniciar sesión</a></form></div>`;
    suggestions.forEach(text => { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-secondary'; b.textContent = text; b.onclick = () => { closeTools(); $('asistente-input').value = text; $('asistente-input').focus(); }; $('asistente-suggestions').append(b); });
    $('asistente-form').onsubmit = e => { e.preventDefault(); send(); };
    $('asistente-new').onclick = newChat; $('asistente-history').onchange = e => open(Number(e.target.value) || null);
    $('asistente-delete').onclick = async () => { if (!cid || busy) return; if (!confirm('¿Eliminar esta conversación y sus mensajes?')) return; try { await api({ accion: 'eliminar', id: cid }); await open(null); } catch (e) { status(e.message); } };
    // El dictado es opcional y sólo se activa al pulsar su botón.
    $('asistente-mic').hidden = !(window.SpeechRecognition || window.webkitSpeechRecognition);
    $('asistente-mic').onclick = dictation;
    $('asistente-stop').hidden = true; $('asistente-stop').onclick = () => { stopVoice(); status('Lectura detenida.'); };
    $('asistente-cancel').onclick = () => controller?.abort();
    $('asistente-retry').onclick = () => send(true);
    $('asistente-input').addEventListener('keydown', event => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        send();
      }
    });
    $('asistente-input').addEventListener('input', e => { e.target.style.height = 'auto'; e.target.style.height = Math.min(e.target.scrollHeight, 80) + 'px'; });
    document.querySelectorAll('.asistente-tools details').forEach(detail => detail.addEventListener('toggle', () => {
      if (detail.open) document.querySelectorAll('.asistente-tools details').forEach(other => { if (other !== detail) other.open = false; });
    }));
    ajustarTeclado();
    await open(null);
  }
  function salir() { ++epoch; controller?.abort(); controller = null; busy = false; stopRecognition(); stopVoice(); }
  return { render, salir };
})();
