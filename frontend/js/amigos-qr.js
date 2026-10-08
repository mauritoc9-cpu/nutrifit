/* QR Social: compartir perfil y agregar amigos por código. */

const AmigosQR = (() => {
  let scanner, overlay, epoch = 0, generatorPromise, readerPromise, previewCode = '', previewUsuario = null, lastFocus;
  let codigoPropio = null;

  async function recordarCodigo(token) {
    if (!/^[a-f0-9]{64}$/.test(token || '')) throw Error('No pudimos preparar un código válido.');
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(token));
    codigoPropio = { token, hash: Array.from(new Uint8Array(digest), b => b.toString(16).padStart(2, '0')).join('') };
  }

  async function api(body) {
    const c = new AbortController();
    const timer = setTimeout(() => c.abort(), 15000);
    try {
      const r = await fetch(API_BASE + '/gamificacion/amigos_qr.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
        signal: c.signal
      });
      let j;
      try {
        j = await r.json();
      } catch {
        throw Error('No pudimos leer la respuesta. Volvé a intentar.');
      }
      if (!j.success) throw Error(j.message || 'No pudimos completar la operación.');
      return j.data;
    } catch (e) {
      if (e.name === 'AbortError') throw Error('La operación tardó demasiado. Volvé a intentar.');
      if (e instanceof TypeError) throw Error('No pudimos conectar. Revisá tu conexión.');
      throw e;
    } finally {
      clearTimeout(timer);
    }
  }

  async function apiGet() {
    const c = new AbortController();
    const timer = setTimeout(() => c.abort(), 8000);
    try {
      const r = await fetch(API_BASE + '/gamificacion/amigos_qr.php', {
        method: 'GET',
        credentials: 'same-origin',
        signal: c.signal
      });
      let j;
      try {
        j = await r.json();
      } catch {
        throw Error('No pudimos leer la respuesta. Volvé a intentar.');
      }
      if (!j.success) throw Error(j.message || 'No pudimos obtener tu código QR.');
      return j.data;
    } catch (e) {
      if (e.name === 'AbortError') throw Error('La operación tardó demasiado. Volvé a intentar.');
      if (e instanceof TypeError) throw Error('No pudimos conectar. Revisá tu conexión.');
      throw e;
    } finally {
      clearTimeout(timer);
    }
  }

  function script() {
    if (window.qrcode) return Promise.resolve();
    if (generatorPromise) return generatorPromise;
    generatorPromise = new Promise((resolve, reject) => {
      const s = document.createElement('script');
      s.src = 'js/qr-libs/qrcode.js';
      const timer = setTimeout(() => {
        s.remove();
        reject(Error('No se pudo cargar el generador QR.'));
      }, 10000);
      s.onload = () => {
        clearTimeout(timer);
        resolve();
      };
      s.onerror = () => {
        clearTimeout(timer);
        s.remove();
        reject(Error('No se pudo cargar el generador QR.'));
      };
      document.head.append(s);
    }).catch(e => {
      generatorPromise = null;
      throw e;
    });
    return generatorPromise;
  }

  function reader() {
    if (!readerPromise) {
      let timer;
      readerPromise = Promise.race([
        import('./qr-libs/qr-scanner.min.js').then(m => m.default),
        new Promise((_, reject) => {
          timer = setTimeout(() => reject(Error('reader-timeout')), 10000);
        })
      ]).catch(e => {
        readerPromise = null;
        throw Error('No se pudo cargar el lector QR. Podés ingresar el código.');
      }).finally(() => clearTimeout(timer));
    }
    return readerPromise;
  }

  function cerrar() {
    ++epoch;
    scanner?.destroy();
    scanner = null;
    overlay?.remove();
    overlay = null;
    previewCode = '';
    previewUsuario = null;
    document.removeEventListener('keydown', teclas);
    lastFocus?.focus();
    lastFocus = null;
  }

  function teclas(e) {
    if (e.key === 'Escape') {
      cerrar();
      return;
    }
    if (e.key === 'Tab' && overlay) {
      const nodes = [...overlay.querySelectorAll('button,input,select,a[href]')].filter(n => !n.disabled && n.offsetParent !== null);
      if (!nodes.length) return;
      const first = nodes[0], last = nodes.at(-1);
      if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
      }
    }
  }

  function modal(title) {
    cerrar();
    lastFocus = document.activeElement;
    overlay = document.createElement('div');
    overlay.className = 'modal-overlay qr-overlay';
    overlay.innerHTML = `<div class="modal-card qr-card" role="dialog" aria-modal="true" aria-labelledby="qr-title"><div class="modal-header"><h3 id="qr-title">${escapeHtml(title)}</h3><button type="button" class="avatar-editor-close" aria-label="Cerrar">${Icon('x', { size: 18 })}</button></div><div id="qr-content"></div><p id="qr-estado" role="status" aria-live="polite"></p></div>`;
    overlay.querySelector('[aria-label="Cerrar"]').onclick = cerrar;
    overlay.onclick = e => {
      if (e.target === overlay) cerrar();
    };
    document.body.append(overlay);
    document.addEventListener('keydown', teclas);
    overlay.querySelector('button').focus();
    return epoch;
  }

  const status = text => {
    const node = document.getElementById('qr-estado');
    if (node) node.textContent = text;
  };

  async function miQR() {
    const mine = modal('Mi código QR de amigos');
    status('Preparando tu código seguro…');
    try {
      await script();
      if (mine !== epoch) return;
      const data = await apiGet();
      if (mine !== epoch) return;
      if (data.token) await recordarCodigo(data.token);
      else if (!codigoPropio || codigoPropio.hash !== data.token_hash) {
        const nuevo = await api({ accion: 'generar' });
        await recordarCodigo(nuevo.token);
      }
      if (mine !== epoch) return;
      const codigo = 'NF-SOCIAL-1:' + codigoPropio.token;

      const qr = qrcode(0, 'M');
      qr.addData(codigo, 'Byte');
      qr.make();

      const content = document.getElementById('qr-content');
      content.innerHTML = `<p>Mostrá este código para que te agreguen en NutriFit.</p><div class="qr-image" role="img" aria-label="Tu código QR social">${qr.createSvgTag({ cellSize: 5, margin: 20, scalable: true })}</div><label for="qr-codigo">Tu código QR</label><input id="qr-codigo" readonly><div class="qr-actions"><button id="qr-copy" type="button" class="btn btn-ghost">Copiar código</button><a id="qr-save" class="btn btn-ghost" download="nutrifit-mi-qr.svg">Guardar QR</a><button id="qr-regen" type="button" class="btn btn-ghost">Regenerar código</button></div>`;

      document.getElementById('qr-codigo').value = codigo;
      document.getElementById('qr-save').href = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(qr.createSvgTag({ cellSize: 5, margin: 20, scalable: true }));

      document.getElementById('qr-copy').onclick = async () => {
        try {
          await navigator.clipboard.writeText(codigo);
          status('Código copiado.');
        } catch {
          document.getElementById('qr-codigo').select();
          status('Copiá el código seleccionado.');
        }
      };

      document.getElementById('qr-regen').onclick = async () => {
        const b = document.getElementById('qr-regen');
        b.disabled = true;
        try {
          const newData = await api({ accion: 'generar' });
          await recordarCodigo(newData.token);
          if (mine === epoch) {
            await miQR();
            status('Código regenerado. El anterior ya no funciona.');
          }
        } catch (e) {
          if (mine === epoch) {
            b.disabled = false;
            status(e.message);
          }
        }
      };

      status('Este código identifica tu perfil de forma segura.');
    } catch (e) {
      if (mine === epoch) status(e.message);
    }
  }

  function escanear() {
    modal('Escanear QR de amigos');
    const content = document.getElementById('qr-content');
    content.innerHTML = `<p>Escaneá un QR NutriFit, subí una imagen o ingresá su código.</p><video id="qr-video" playsinline muted hidden></video><div class="qr-actions"><button id="qr-camera" class="btn btn-ghost" type="button">${Icon('camera', { size: 16 })}Escanear con cámara</button><label class="btn btn-ghost" for="qr-file">Leer imagen QR</label><input id="qr-file" type="file" accept="image/*" hidden></div><label for="qr-token">Código de amigo</label><input id="qr-token" maxlength="100" autocomplete="off" spellcheck="false" placeholder="NF-SOCIAL-1:…"><button id="qr-preview-btn" type="button" class="btn btn-primary">Buscar usuario</button><div id="qr-preview"></div>`;
    document.getElementById('qr-camera').onclick = camara;
    document.getElementById('qr-file').onchange = imagen;
    document.getElementById('qr-preview-btn').onclick = preview;
    document.getElementById('qr-token').oninput = () => {
      previewCode = '';
      previewUsuario = null;
      document.getElementById('qr-preview').replaceChildren();
    };
    document.getElementById('qr-token').onkeydown = e => {
      if (e.key === 'Enter') preview();
    };
  }

  async function camara() {
    const mine = epoch;
    const b = document.getElementById('qr-camera');
    b.disabled = true;

    if (!navigator.mediaDevices?.getUserMedia || !window.isSecureContext) {
      status('La cámara requiere HTTPS o localhost. Ingresá el código o una imagen.');
      b.disabled = false;
      return;
    }

    status('Solicitando cámara…');
    let own, timer;
    try {
      const Reader = await reader();
      if (mine !== epoch) return;
      const video = document.getElementById('qr-video');
      video.hidden = false;
      scanner = new Reader(video, result => {
        if (mine !== epoch) return;
        scanner?.stop();
        video.hidden = true;
        document.getElementById('qr-token').value = result.data;
        preview();
      }, { preferredCamera: 'environment', returnDetailedScanResult: true, maxScansPerSecond: 8 });
      own = scanner;
      await Promise.race([own.start(), new Promise((_, reject) => {
        timer = setTimeout(() => reject(Error('camera-timeout')), 12000);
      })]);
      if (mine !== epoch) {
        own.destroy();
        return;
      }
      status('Apuntá al QR de otro usuario. También podés ingresar el código.');
    } catch (e) {
      own?.destroy();
      if (mine === epoch) {
        scanner = null;
        document.getElementById('qr-video').hidden = true;
        status('No pudimos acceder a la cámara. Revisá el permiso o usá código / imagen.');
      }
    } finally {
      clearTimeout(timer);
      if (mine === epoch) b.disabled = false;
    }
  }

  async function imagen(e) {
    const mine = epoch;
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    if (file.size > 10 * 1024 * 1024) {
      status('La imagen debe pesar menos de 10 MB.');
      return;
    }
    scanner?.stop();
    status('Leyendo QR de la imagen…');
    let timer;
    try {
      const Reader = await reader();
      const result = await Promise.race([
        Reader.scanImage(file, { returnDetailedScanResult: true }),
        new Promise((_, reject) => {
          timer = setTimeout(() => reject(Error('image-timeout')), 12000);
        })
      ]);
      if (mine !== epoch) return;
      document.getElementById('qr-token').value = result.data;
      await preview();
    } catch {
      if (mine === epoch) status('No encontramos un QR legible en esa imagen. Podés ingresar el código.');
    } finally {
      clearTimeout(timer);
    }
  }

  async function preview() {
    const mine = epoch;
    scanner?.stop();
    document.getElementById('qr-video').hidden = true;
    const code = document.getElementById('qr-token').value.trim();
    previewCode = '';
    previewUsuario = null;
    document.getElementById('qr-preview').replaceChildren();

    if (!/^(?:NF-SOCIAL-1:)?[a-f0-9]{64}$/.test(code)) {
      status('Código inválido. Usá un QR de amigo NutriFit.');
      return;
    }

    const b = document.getElementById('qr-preview-btn');
    b.disabled = true;
    status('Validando usuario…');
    try {
      const data = await api({ accion: 'preview', codigo: code });
      if (mine !== epoch || document.getElementById('qr-token').value.trim() !== code) return;
      previewCode = code;
      previewUsuario = data;

      document.getElementById('qr-preview').innerHTML = `<h4>${escapeHtml(data.nombre)}</h4><p>Nivel ${Number(data.nivel)}</p><p>Esta información es pública en NutriFit.</p><button id="qr-confirm" class="btn btn-primary" type="button">Enviar solicitud de amistad</button>`;
      document.getElementById('qr-confirm').onclick = enviarSolicitud;
      status('Usuario encontrado. Confirmá si querés agregarlo.');
    } catch (e) {
      if (mine === epoch) status(e.message);
    } finally {
      if (mine === epoch) b.disabled = false;
    }
  }

  async function enviarSolicitud() {
    if (!previewUsuario) return;
    const mine = epoch;
    const b = document.getElementById('qr-confirm');
    b.disabled = true;
    status('Enviando solicitud…');

    try {
      const r = await fetch(API_BASE + '/gamificacion/amigos.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ accion: 'agregar-por-qr', usuario_id: previewUsuario.usuario_id })
      });
      let j;
      try {
        j = await r.json();
      } catch {
        throw Error('No pudimos leer la respuesta. Volvé a intentar.');
      }
      if (!j.success) throw Error(j.message || 'No pudimos enviar la solicitud.');

      if (mine === epoch) {
        status(j.message || 'Solicitud enviada.');
        document.getElementById('qr-preview').replaceChildren();
        previewCode = '';
        previewUsuario = null;
        setTimeout(() => {
          if (mine === epoch) cerrar();
        }, 2000);
      }
    } catch (e) {
      if (mine === epoch) {
        status(e.message);
        b.disabled = false;
      }
    }
  }

  return { miQR, escanear, cerrar };
})();
