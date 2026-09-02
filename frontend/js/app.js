/**
 * NutriFit — Lógica de la aplicación principal (SPA de 4 pestañas).
 */

const VIEWS = ['progreso', 'nutricion', 'escaner', 'ranking'];
let cameraStream = null;
let ultimoAnalisisFoto = null;

// =====================================================================
// Recordatorios (notificaciones del navegador mientras la app está abierta)
// =====================================================================
// Limitación: usan la Notification API del navegador, así que sólo llegan
// mientras NutriFit está abierto en una pestaña — no son push notifications
// reales (eso requeriría un service worker + backend con VAPID keys).
const RECORDATORIOS_KEY = 'nutrifit_recordatorios_activos';
const RECORDATORIOS_LOG_KEY = 'nutrifit_recordatorios_log';

function recordatoriosActivos() {
  return localStorage.getItem(RECORDATORIOS_KEY) === '1';
}

async function toggleRecordatorios() {
  if (!('Notification' in window)) { showToast('Tu navegador no soporta notificaciones.'); return; }

  if (recordatoriosActivos()) {
    localStorage.setItem(RECORDATORIOS_KEY, '0');
    showToast('Recordatorios desactivados.');
    actualizarBotonRecordatorios();
    return;
  }

  const permiso = await Notification.requestPermission();
  if (permiso !== 'granted') { showToast('No se activaron los permisos de notificación.'); return; }

  localStorage.setItem(RECORDATORIOS_KEY, '1');
  showToast('Recordatorios activados (mientras tengas NutriFit abierto).');
  actualizarBotonRecordatorios();
  chequearRecordatorios();
}

function actualizarBotonRecordatorios() {
  const btn = document.getElementById('btn-recordatorios');
  if (btn) btn.innerHTML = recordatoriosActivos() ? '<span class="icon">🔔</span> Recordatorios activados' : '<span class="icon">🔕</span> Activar recordatorios';
}

function logRecordatoriosDeHoy() {
  const hoy = new Date().toISOString().slice(0, 10);
  const guardado = JSON.parse(localStorage.getItem(RECORDATORIOS_LOG_KEY) || '{}');
  return guardado.fecha === hoy ? guardado : { fecha: hoy };
}

async function chequearRecordatorios() {
  if (!recordatoriosActivos() || Notification.permission !== 'granted') return;

  const horaActual = new Date().getHours();
  const log = logRecordatoriosDeHoy();

  if (!log.hidratacion && horaActual >= 14) {
    const hidratacion = await Api.hidratacionGet();
    if (hidratacion.success && hidratacion.data.vasos < Math.ceil(hidratacion.data.meta_vasos / 2)) {
      new Notification('💧 NutriFit', {
        body: `Llevás ${hidratacion.data.vasos} de ${hidratacion.data.meta_vasos} vasos hoy. ¡No te olvides de hidratarte!`,
      });
      log.hidratacion = true;
      localStorage.setItem(RECORDATORIOS_LOG_KEY, JSON.stringify(log));
    }
  }

  if (!log.racha && horaActual >= 20) {
    const sesion = await Api.sesion();
    const hoy = new Date().toISOString().slice(0, 10);
    if (sesion.success && sesion.data.ultima_actividad !== hoy) {
      new Notification('🔥 NutriFit', {
        body: `Tu racha de ${sesion.data.racha_dias} día${sesion.data.racha_dias === 1 ? '' : 's'} está en riesgo. ¡Registrá algo antes de medianoche!`,
      });
      log.racha = true;
      localStorage.setItem(RECORDATORIOS_LOG_KEY, JSON.stringify(log));
    }
  }
}

// ---------- Navegación entre pestañas (sin recargar página) ----------
async function loadView(viewName) {
  if (!VIEWS.includes(viewName)) return;

  document.querySelectorAll('.view').forEach((el) => el.classList.remove('active'));
  document.getElementById(`view-${viewName}`).classList.add('active');

  document.querySelectorAll('.nav-item[data-view]').forEach((el) => {
    el.classList.toggle('active', el.dataset.view === viewName);
  });

  stopCamera();

  if (viewName === 'progreso') await renderProgreso();
  if (viewName === 'nutricion') await renderNutricion();
  if (viewName === 'escaner') renderEscaner();
  if (viewName === 'ranking') await renderRanking();
}

function showToast(text) {
  const toast = document.getElementById('toast');
  toast.textContent = text;
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 2600);
}

function launchConfetti() {
  const colors = ['#22C55E', '#0EA5E9', '#8B5CF6', '#F59E0B'];
  for (let i = 0; i < 26; i++) {
    const piece = document.createElement('div');
    piece.className = 'confetti-piece';
    piece.style.left = `${Math.random() * 100}vw`;
    piece.style.background = colors[Math.floor(Math.random() * colors.length)];
    piece.style.animationDelay = `${Math.random() * 0.3}s`;
    document.body.appendChild(piece);
    setTimeout(() => piece.remove(), 2200);
  }
}

async function handleXPResult(xpResult) {
  if (!xpResult) return;
  showToast(`+${xpResult.xp_ganado} XP ganado${xpResult.multiplicador > 1 ? ` (x${xpResult.multiplicador} racha)` : ''}`);
  if (xpResult.subio_de_nivel) {
    launchConfetti();
    setTimeout(() => showToast(`🎉 ¡Subiste a nivel ${xpResult.nivel}! +${xpResult.coins_ganados} 🪙 NutriCoins`), 700);
  }
  const sesion = await Api.sesion();
  if (sesion.success) renderUserChip(sesion.data);
}

// =====================================================================
// PESTAÑA 1 — Mi Progreso & Avatar 3D
// =====================================================================
async function renderProgreso() {
  const container = document.getElementById('view-progreso');
  const sesion = await Api.sesion();
  if (!sesion.success) return;
  const u = sesion.data;
  const progreso = u.progreso_nivel;

  const pesoData = await Api.progresoPesoGet();
  const metas = pesoData.success ? pesoData.data.metas : null;
  const rutinaData = await Api.rutinaRecomendada();
  const pasosData = await Api.pasosGet();

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Bienvenido de vuelta</span><h1>${u.nombre.split(' ')[0]} 👋</h1></div>
      <div class="streak-badge">🔥 ${u.racha_dias} día${u.racha_dias === 1 ? '' : 's'} de racha</div>
    </div>

    <div class="grid-2">
      <div class="card">
        ${renderAvatarHTML(u.equipados || {})}
        <div style="margin-top:16px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
            <span style="font-weight:600;font-size:13.5px;">Nivel ${progreso.nivel}</span>
            <span class="mono" style="font-size:12px;color:var(--text-secondary);">${progreso.xp_en_nivel} / ${progreso.xp_para_siguiente} XP</span>
          </div>
          <div class="xp-bar-track"><div class="xp-bar-fill" style="width:${progreso.porcentaje}%;"></div></div>
        </div>
      </div>

      <div class="card">
        <h3 style="margin-bottom:14px;font-size:15px;">Peso y objetivo</h3>
        ${metas ? `
          <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
            <div><p style="font-size:12px;">Peso actual</p><p class="mono" style="font-size:20px;font-weight:700;color:var(--text-primary);">${metas.peso_actual} kg</p></div>
            <div style="text-align:right;"><p style="font-size:12px;">Objetivo</p><p class="mono" style="font-size:20px;font-weight:700;color:var(--color-nutricion);">${metas.peso_objetivo ?? '—'} kg</p></div>
          </div>
        ` : '<p>Aún no registraste tu progreso de peso.</p>'}
        <div class="field" style="margin-top:12px;">
          <label>Actualizar peso de hoy (kg)</label>
          <div style="display:flex;gap:8px;">
            <input type="number" step="0.1" id="input-peso-hoy" placeholder="Ej: 72.5" />
            <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="submitPesoHoy()">Guardar</button>
          </div>
        </div>
        <p style="margin-top:14px;">🏅 Insignias: ${u.nivel >= 5 ? '⭐ Constancia' : ''} ${u.racha_dias >= 7 ? '🔥 Racha de fuego' : ''} ${(u.nivel < 5 && u.racha_dias < 7) ? 'Sigue avanzando para desbloquear insignias.' : ''}</p>
      </div>
    </div>

    <div class="card" style="margin-top:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="font-size:15px;">Rutina recomendada de hoy</h3>
        ${rutinaData.success ? `<span style="font-size:12px;color:var(--text-secondary);text-transform:capitalize;">${rutinaData.data.lugar} · ${rutinaData.data.nivel_dificultad}</span>` : ''}
      </div>
      <div id="rutina-container">${renderRutinaHTML(rutinaData)}</div>
    </div>

    <div class="card" style="margin-top:18px;" id="card-pasos">
      ${renderPasosHTML(pasosData)}
    </div>
  `;
}

function renderPasosHTML(pasosData) {
  const d = pasosData.success ? pasosData.data : { pasos: 0, meta_pasos: 10000, km_recorridos: 0, kcal_quemadas: 0 };
  const pct = d.meta_pasos > 0 ? Math.min(100, (d.pasos / d.meta_pasos) * 100) : 0;

  return `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 style="font-size:15px;">🚶 Pasos diarios</h3>
      <span class="mono" style="font-size:12px;color:var(--text-secondary);">${d.pasos.toLocaleString('es-AR')} / ${d.meta_pasos.toLocaleString('es-AR')}</span>
    </div>
    <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
      ${crearRingProgreso({ tamano: 120, grosor: 11, color: 'var(--color-lima)', porcentaje: pct, valorTexto: `${Math.round(pct)}%` })}
      <div style="display:flex;gap:24px;flex:1;min-width:160px;">
        <div><p style="font-size:12px;">Distancia</p><p class="mono" style="font-size:18px;font-weight:700;">${d.km_recorridos} km</p></div>
        <div><p style="font-size:12px;">Calorías</p><p class="mono" style="font-size:18px;font-weight:700;color:var(--color-nutricion);">${d.kcal_quemadas} kcal</p></div>
      </div>
    </div>
    <div class="modal-quick-add" style="margin-top:18px;">
      <button type="button" class="chip-btn" onclick="sumarPasos(500)">+500</button>
      <button type="button" class="chip-btn" onclick="sumarPasos(1000)">+1.000</button>
      <button type="button" class="chip-btn" onclick="sumarPasos(2000)">+2.000</button>
    </div>
    <div style="display:flex;gap:8px;margin-top:10px;">
      <input type="number" id="input-pasos-manual" placeholder="Cantidad exacta" min="1" />
      <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="sumarPasosManual()">Agregar</button>
    </div>
  `;
}

function crearRingProgreso({ tamano, grosor, color, porcentaje, valorTexto }) {
  const radio = (tamano - grosor) / 2;
  const centro = tamano / 2;
  const circunferencia = 2 * Math.PI * radio;
  const offset = circunferencia - (porcentaje / 100) * circunferencia;
  return `
    <svg width="${tamano}" height="${tamano}" viewBox="0 0 ${tamano} ${tamano}" style="flex-shrink:0;">
      <circle cx="${centro}" cy="${centro}" r="${radio}" fill="none" stroke="var(--bg-inset)" stroke-width="${grosor}" />
      <circle cx="${centro}" cy="${centro}" r="${radio}" fill="none" stroke="${color}" stroke-width="${grosor}"
        stroke-linecap="round" stroke-dasharray="${circunferencia}" stroke-dashoffset="${offset}"
        transform="rotate(-90 ${centro} ${centro})" style="transition:stroke-dashoffset var(--transition-base);" />
      <text x="${centro}" y="${centro}" text-anchor="middle" dominant-baseline="central"
        font-family="var(--font-display)" font-weight="700" font-size="20" fill="var(--text-primary)">${valorTexto}</text>
    </svg>
  `;
}

async function sumarPasos(cantidad) {
  const res = await Api.pasosSumar(cantidad);
  if (!res.success) { showToast(res.message); return; }
  document.getElementById('card-pasos').innerHTML = renderPasosHTML({ success: true, data: res.data.registro });
  await handleXPResult(res.data.xp);
}

async function sumarPasosManual() {
  const valor = parseInt(document.getElementById('input-pasos-manual').value, 10);
  if (!valor || valor <= 0) { showToast('Ingresá una cantidad de pasos válida.'); return; }
  await sumarPasos(valor);
}

// ---------- Avatar: refleja lo equipado en la tienda ----------
const PALETA_ROPA = {
  verde: ['#22C55E', '#16A34A'], azul: ['#38BDF8', '#0284C7'], rojo: ['#F87171', '#DC2626'],
  rosa: ['#F9A8D4', '#DB2777'], dorado: ['#FDE68A', '#D97706'], oro: ['#FDE68A', '#D97706'],
  morado: ['#C4B5FD', '#7C3AED'], violeta: ['#C4B5FD', '#7C3AED'], negro: ['#475569', '#1E293B'],
};
const PALETA_AURA = {
  hidratación: '#0EA5E9', hidratacion: '#0EA5E9', azul: '#0EA5E9', diamante: '#67E8F9',
  fuego: '#F97316', rojo: '#EF4444', dorado: '#F5B942', oro: '#F5B942',
};
const PALETA_MARCO = { dorado: '#F5B942', oro: '#F5B942' };

function colorDesdeNombre(nombre, paleta) {
  const texto = (nombre || '').toLowerCase();
  for (const [palabra, valor] of Object.entries(paleta)) {
    if (texto.includes(palabra)) return valor;
  }
  return null;
}

function renderAvatarHTML(equipados) {
  const [ropaClara, ropaOscura] = colorDesdeNombre(equipados.ropa_avatar, PALETA_ROPA)
    ? PALETA_ROPA[Object.keys(PALETA_ROPA).find((p) => (equipados.ropa_avatar || '').toLowerCase().includes(p))]
    : ['var(--color-nutricion)', '#16A34A'];
  const colorAura = colorDesdeNombre(equipados.aura, PALETA_AURA) || 'rgba(139,92,246,0.6)';
  const colorMarco = equipados.marco_perfil ? (colorDesdeNombre(equipados.marco_perfil, PALETA_MARCO) || '#CBD5E1') : null;
  const tieneAccesorio = !!equipados.accesorio_avatar;

  return `
    <div class="avatar-stage" style="${colorMarco ? `box-shadow: inset 0 0 0 3px ${colorMarco};` : ''}">
      <div class="avatar-aura" style="background: radial-gradient(circle, ${colorAura}, transparent 65%);"></div>
      <div class="avatar-3d">
        <div class="head">${tieneAccesorio ? '<span class="accesorio">🕶️</span>' : ''}</div>
        <div class="arm arm-left"></div>
        <div class="arm arm-right"></div>
        <div class="torso" style="background: linear-gradient(160deg, ${ropaClara}, ${ropaOscura});"></div>
        <div class="legs"></div>
      </div>
    </div>
  `;
}

function renderRutinaHTML(rutinaData) {
  if (!rutinaData.success) {
    return `<p class="empty-state">Completa el onboarding para recibir una rutina personalizada.</p>`;
  }
  const r = rutinaData.data;
  const ejerciciosHTML = r.ejercicios.map((e) => `
    <div class="exercise-card">
      <div><div style="font-weight:600;font-size:13.5px;">${e.nombre}</div><div class="meta">${e.series} series × ${e.repeticiones}</div></div>
    </div>
  `).join('');

  return `
    <h4 style="font-size:14px;margin-bottom:10px;">${r.titulo}</h4>
    ${ejerciciosHTML}
    <button class="btn btn-primary" style="margin-top:8px;" ${r.completada_hoy ? 'disabled style="opacity:.5;"' : ''}
      onclick="marcarEntrenamiento(${r.id})">
      ${r.completada_hoy ? '✅ Ya entrenaste hoy' : 'Marcar como Entrenado (+25 XP)'}
    </button>
  `;
}

async function marcarEntrenamiento(rutinaId) {
  const res = await Api.completarEntrenamiento(rutinaId);
  if (!res.success) { showToast(res.message); return; }
  await handleXPResult(res.data.xp);
  await renderProgreso();
}

async function submitPesoHoy() {
  const valor = document.getElementById('input-peso-hoy').value;
  if (!valor || parseFloat(valor) <= 0) { showToast('Ingresa un peso válido.'); return; }
  const res = await Api.progresoPesoPost(parseFloat(valor));
  if (res.success) { showToast('Peso actualizado.'); await renderProgreso(); }
  else showToast(res.message);
}

// =====================================================================
// PESTAÑA 2 — Calorías & Nutrición Diaria
// =====================================================================
async function renderNutricion() {
  const container = document.getElementById('view-nutricion');
  const hoy = new Date().toISOString().slice(0, 10);
  const res = await Api.dashboardDiario(hoy);
  const hidratacion = await Api.hidratacionGet();

  if (!res.success) {
    container.innerHTML = `<p class="empty-state">${res.message}</p>`;
    return;
  }

  const d = res.data;
  const pctCal = d.metas.meta_calorias > 0
    ? Math.min(100, (d.totales_consumidos.calorias / d.metas.meta_calorias) * 100)
    : 0;
  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (pctCal / 100) * circumference;

  const vasos = hidratacion.success ? hidratacion.data.vasos : 0;
  const metaVasos = hidratacion.success ? hidratacion.data.meta_vasos : 8;
  const glasses = Array.from({ length: metaVasos }, (_, i) =>
    `<div class="water-glass ${i < vasos ? 'filled' : ''}"></div>`
  ).join('');

  const tipos = { desayuno: 'Desayuno', almuerzo: 'Almuerzo', merienda: 'Merienda', cena: 'Cena', snack: 'Snacks' };
  const comidasHTML = Object.entries(tipos).map(([key, label]) => {
    const items = d.comidas_por_tipo[key] || [];
    const itemsHTML = items.length
      ? items.map((i) => `
          <div class="meal-item">
            <span>${i.alimento} · ${i.gramos}g</span>
            <span style="display:flex;align-items:center;gap:10px;">
              <span class="cal">${Math.round(i.calorias_totales)} kcal</span>
              <button type="button" onclick="eliminarRegistroComida(${i.id})" title="Eliminar" style="color:var(--color-danger);font-size:15px;line-height:1;">✕</button>
            </span>
          </div>
        `).join('')
      : `<div class="empty-state">Sin registros aún</div>`;
    return `
      <div class="meal-section">
        <div class="meal-section-title"><h4 style="font-size:13.5px;">${label}</h4></div>
        ${itemsHTML}
      </div>
    `;
  }).join('');

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Hoy</span><h1>Nutrición Diaria</h1></div>
    </div>

    <div class="grid-2">
      <div class="card">
        <div class="calorie-ring-wrap">
          <svg class="calorie-ring" width="180" height="180" viewBox="0 0 180 180">
            <circle class="track" cx="90" cy="90" r="${radius}"></circle>
            <circle class="fill" cx="90" cy="90" r="${radius}"
              stroke-dasharray="${circumference}" stroke-dashoffset="${offset}"></circle>
          </svg>
        </div>
        <div class="calorie-ring-center">
          <div class="value mono">${Math.round(d.totales_consumidos.calorias)}</div>
          <div class="label">de ${d.metas.meta_calorias} kcal</div>
        </div>

        <div class="macro-row">
          <div class="macro-label"><span>Proteínas</span><span class="mono">${Math.round(d.totales_consumidos.proteinas)}g / ${d.metas.meta_proteinas}g</span></div>
          <div class="macro-track"><div class="macro-fill proteina" style="width:${Math.min(100, (d.totales_consumidos.proteinas / d.metas.meta_proteinas) * 100)}%;"></div></div>
        </div>
        <div class="macro-row">
          <div class="macro-label"><span>Carbohidratos</span><span class="mono">${Math.round(d.totales_consumidos.carbohidratos)}g / ${d.metas.meta_carbohidratos}g</span></div>
          <div class="macro-track"><div class="macro-fill carbo" style="width:${Math.min(100, (d.totales_consumidos.carbohidratos / d.metas.meta_carbohidratos) * 100)}%;"></div></div>
        </div>
        <div class="macro-row">
          <div class="macro-label"><span>Grasas</span><span class="mono">${Math.round(d.totales_consumidos.grasas)}g / ${d.metas.meta_grasas}g</span></div>
          <div class="macro-track"><div class="macro-fill grasa" style="width:${Math.min(100, (d.totales_consumidos.grasas / d.metas.meta_grasas) * 100)}%;"></div></div>
        </div>

        <div style="margin-top:16px;padding:12px 14px;background:var(--color-nutricion-soft);border-radius:var(--radius-sm);">
          <p style="font-size:12.5px;color:var(--text-primary);">💡 ${d.recomendacion_ia}</p>
        </div>
      </div>

      <div class="card">
        <h3 style="font-size:15px;margin-bottom:12px;">💧 Hidratación</h3>
        <p style="font-size:13px;margin-bottom:8px;">${vasos} de ${metaVasos} vasos hoy</p>
        <div class="water-glass-row">${glasses}</div>
        <button class="btn btn-primary" onclick="sumarVasoAgua()">+ Agregar vaso de agua</button>

        <h3 style="font-size:15px;margin:22px 0 12px;">Agregar comida rápido</h3>
        <div class="field">
          <label>Buscar alimento</label>
          <input type="text" id="input-buscar-alimento" placeholder="Ej: pollo, arroz, huevo..." oninput="buscarAlimentosDebounced(this.value)" />
        </div>
        <div id="resultados-alimentos"></div>
      </div>
    </div>

    <div class="card" style="margin-top:18px;">
      <h3 style="font-size:15px;margin-bottom:16px;">Diario de comidas</h3>
      ${comidasHTML}
    </div>
  `;
}

let debounceTimer = null;
function buscarAlimentosDebounced(query) {
  clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => buscarAlimentos(query), 300);
}

async function buscarAlimentos(query) {
  const container = document.getElementById('resultados-alimentos');
  if (!query || query.length < 2) { container.innerHTML = ''; return; }

  const res = await Api.buscarAlimentos(query);
  if (!res.success || res.data.length === 0) {
    container.innerHTML = `<div class="empty-state">Sin resultados</div>`;
    return;
  }

  container.innerHTML = res.data.map((a) => `
    <div class="meal-item" style="cursor:pointer;" onclick="abrirModalRegistroComida(${a.id}, '${a.nombre.replace(/'/g, "\\'")}', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
      <span>${a.nombre}</span>
      <span class="cal">${a.calorias_por_100g} kcal/100g</span>
    </div>
  `).join('');
}

const TIPOS_COMIDA = [
  { valor: 'desayuno', label: 'Desayuno' },
  { valor: 'almuerzo', label: 'Almuerzo' },
  { valor: 'merienda', label: 'Merienda' },
  { valor: 'cena', label: 'Cena' },
  { valor: 'snack', label: 'Snack' },
];

let macrosPor100gModal = null;

function abrirModalRegistroComida(alimentoId, nombre, caloriasPor100g, proteinas, carbohidratos, grasas) {
  cerrarModalRegistroComida();

  macrosPor100gModal = {
    calorias: parseFloat(caloriasPor100g) || 0,
    proteinas: parseFloat(proteinas) || 0,
    carbohidratos: parseFloat(carbohidratos) || 0,
    grasas: parseFloat(grasas) || 0,
  };

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-registro-comida';
  overlay.innerHTML = `
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-icon">🍽️</div>
        <div>
          <h3>${nombre}</h3>
          <p class="modal-subtitle">${macrosPor100gModal.calorias} kcal por cada 100g</p>
        </div>
      </div>

      <div class="field">
        <label for="modal-input-gramos">Gramos</label>
        <input type="number" id="modal-input-gramos" value="150" min="1" step="1" inputmode="numeric" oninput="actualizarPreviewMacrosModal()" />
        <div class="modal-quick-add">
          <button type="button" class="chip-btn" onclick="incrementarGramosModal(50)">+50g</button>
          <button type="button" class="chip-btn" onclick="incrementarGramosModal(100)">+100g</button>
          <button type="button" class="chip-btn" onclick="incrementarGramosModal(200)">+200g</button>
        </div>
      </div>

      <div class="field">
        <label>Tipo de comida</label>
        <div class="modal-tipos">
          ${TIPOS_COMIDA.map((t, i) => `
            <div class="option-card${i === 1 ? ' selected' : ''}" data-tipo="${t.valor}" onclick="seleccionarTipoComidaModal('${t.valor}')">${t.label}</div>
          `).join('')}
        </div>
      </div>

      <div class="macro-preview" id="modal-macro-preview"></div>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="cerrarModalRegistroComida()">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="confirmarRegistroComidaModal(${alimentoId})">Agregar a mi registro</button>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrarModalRegistroComida(); });
  document.body.appendChild(overlay);
  actualizarPreviewMacrosModal();
  document.getElementById('modal-input-gramos').focus();
}

function incrementarGramosModal(delta) {
  const input = document.getElementById('modal-input-gramos');
  input.value = Math.max(1, (parseFloat(input.value) || 0) + delta);
  actualizarPreviewMacrosModal();
}

function actualizarPreviewMacrosModal() {
  if (!macrosPor100gModal) return;
  const gramos = parseFloat(document.getElementById('modal-input-gramos').value) || 0;
  const factor = gramos / 100;
  const preview = document.getElementById('modal-macro-preview');

  const calcular = (valor) => Math.round(valor * factor * 10) / 10;

  preview.innerHTML = `
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-nutricion);">${calcular(macrosPor100gModal.calorias)}</span>
      <span class="label">kcal</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-xp);">${calcular(macrosPor100gModal.proteinas)}g</span>
      <span class="label">Proteínas</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-carbohidratos);">${calcular(macrosPor100gModal.carbohidratos)}g</span>
      <span class="label">Carbos</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-grasas);">${calcular(macrosPor100gModal.grasas)}g</span>
      <span class="label">Grasas</span>
    </div>
  `;
}

function seleccionarTipoComidaModal(tipo) {
  document.querySelectorAll('#modal-registro-comida .modal-tipos .option-card').forEach((el) => {
    el.classList.toggle('selected', el.dataset.tipo === tipo);
  });
}

function cerrarModalRegistroComida() {
  document.getElementById('modal-registro-comida')?.remove();
  macrosPor100gModal = null;
}

function confirmarRegistroComidaModal(alimentoId) {
  const gramos = parseFloat(document.getElementById('modal-input-gramos').value);
  if (!gramos || gramos <= 0) { showToast('Ingresá una cantidad de gramos válida.'); return; }

  const tipo = document.querySelector('#modal-registro-comida .modal-tipos .option-card.selected')?.dataset.tipo;
  if (!tipo) { showToast('Elegí un tipo de comida.'); return; }

  cerrarModalRegistroComida();
  registrarComida(alimentoId, tipo, gramos, 'manual');
}

async function registrarComida(alimentoId, tipo, gramos, origen) {
  const res = await Api.registrarComida({ alimento_id: alimentoId, tipo_comida: tipo, gramos, origen });
  if (!res.success) { showToast(res.message); return; }
  await handleXPResult(res.data.xp);
  document.getElementById('input-buscar-alimento').value = '';
  document.getElementById('resultados-alimentos').innerHTML = '';
  await renderNutricion();
}

async function eliminarRegistroComida(registroId) {
  const res = await Api.eliminarComida(registroId);
  if (!res.success) { showToast(res.message); return; }
  showToast('Registro eliminado.');
  await renderNutricion();
}

async function sumarVasoAgua() {
  const res = await Api.hidratacionSumar();
  if (!res.success) { showToast(res.message); return; }
  if (res.data.xp) await handleXPResult(res.data.xp);
  await renderNutricion();
}

// =====================================================================
// PESTAÑA 3 — Escáner / Cámara IA Nutricional
// =====================================================================
function renderEscaner() {
  const container = document.getElementById('view-escaner');
  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Visión computacional</span><h1>Escáner Nutricional IA</h1></div>
    </div>

    <div class="card">
      <div class="scanner-frame" id="scanner-frame">
        <span style="font-size:13px;color:var(--text-secondary);">Activa la cámara para escanear tu plato</span>
      </div>
      <div style="display:flex;gap:10px;margin-top:14px;">
        <button class="btn btn-primary" onclick="startCamera()">📷 Activar cámara</button>
        <button class="btn btn-ghost" onclick="simularCapturaIA()">✨ Analizar plato (IA)</button>
      </div>
      <div id="resultado-ia" style="margin-top:18px;"></div>
    </div>

    <div class="card" style="margin-top:18px;">
      <h3 style="font-size:15px;margin-bottom:12px;">Buscador de respaldo</h3>
      <div class="field">
        <input type="text" id="input-buscar-alimento-escaner" placeholder="Buscar alimento manualmente..." oninput="buscarAlimentosEscanerDebounced(this.value)" />
      </div>
      <div id="resultados-alimentos-escaner"></div>
    </div>
  `;
}

async function startCamera() {
  const frame = document.getElementById('scanner-frame');
  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    frame.innerHTML = `<video autoplay playsinline></video><div class="scan-line"></div>`;
    frame.querySelector('video').srcObject = cameraStream;
  } catch (err) {
    showToast('No se pudo acceder a la cámara. Usa el buscador manual.');
  }
}

function stopCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach((t) => t.stop());
    cameraStream = null;
  }
}

async function simularCapturaIA() {
  const resultBox = document.getElementById('resultado-ia');
  resultBox.innerHTML = `<p style="font-size:13px;">Analizando plato con IA...</p>`;

  const res = await Api.analizarFoto();
  if (!res.success) { resultBox.innerHTML = `<p class="empty-state">${res.message}</p>`; return; }

  ultimoAnalisisFoto = res.data;
  const a = res.data.alimento_detectado;

  resultBox.innerHTML = `
    <div class="card" style="background:var(--bg-inset);">
      <p style="font-size:12px;color:var(--color-nutricion);font-weight:600;">Detectado con ${res.data.confianza_porcentaje}% de confianza</p>
      <h4 style="font-size:16px;margin:6px 0;">${a.nombre}</h4>
      <div class="field">
        <label>Ajustar gramos estimados</label>
        <input type="number" id="input-gramos-ia" value="${res.data.gramos_estimados}" />
      </div>
      <div class="field">
        <label>Tipo de comida</label>
        <select id="select-tipo-ia">
          <option value="desayuno">Desayuno</option>
          <option value="almuerzo" selected>Almuerzo</option>
          <option value="merienda">Merienda</option>
          <option value="cena">Cena</option>
          <option value="snack">Snack</option>
        </select>
      </div>
      <button class="btn btn-primary" onclick="confirmarRegistroIA(${a.id})">Confirmar y registrar (+10 XP)</button>
    </div>
  `;
}

async function confirmarRegistroIA(alimentoId) {
  const gramos = parseFloat(document.getElementById('input-gramos-ia').value);
  const tipo = document.getElementById('select-tipo-ia').value;
  if (!gramos || gramos <= 0) { showToast('Gramos inválidos.'); return; }

  await registrarComidaDesdeEscaner(alimentoId, tipo, gramos);
}

async function registrarComidaDesdeEscaner(alimentoId, tipo, gramos) {
  const res = await Api.registrarComida({ alimento_id: alimentoId, tipo_comida: tipo, gramos, origen: 'camara_ia' });
  if (!res.success) { showToast(res.message); return; }
  await handleXPResult(res.data.xp);
  document.getElementById('resultado-ia').innerHTML = `<p style="color:var(--color-nutricion);font-weight:600;font-size:13.5px;">✅ Registrado en tu diario de comidas.</p>`;
}

let debounceTimerEscaner = null;
function buscarAlimentosEscanerDebounced(query) {
  clearTimeout(debounceTimerEscaner);
  debounceTimerEscaner = setTimeout(() => buscarAlimentosEscaner(query), 300);
}

async function buscarAlimentosEscaner(query) {
  const container = document.getElementById('resultados-alimentos-escaner');
  if (!query || query.length < 2) { container.innerHTML = ''; return; }
  const res = await Api.buscarAlimentos(query);
  if (!res.success || res.data.length === 0) { container.innerHTML = `<div class="empty-state">Sin resultados</div>`; return; }

  container.innerHTML = res.data.map((a) => `
    <div class="meal-item" style="cursor:pointer;" onclick="abrirModalRegistroComida(${a.id}, '${a.nombre.replace(/'/g, "\\'")}', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
      <span>${a.nombre}</span><span class="cal">${a.calorias_por_100g} kcal/100g</span>
    </div>
  `).join('');
}

// =====================================================================
// PESTAÑA 4 — Ranking, Amigos & Tienda
// =====================================================================
let ligaActiva = 'bronce';

async function renderRanking() {
  const container = document.getElementById('view-ranking');
  const [rankingRes, tiendaRes, sesionRes] = await Promise.all([Api.ranking(), Api.tiendaGet(), Api.sesion()]);
  const coins = sesionRes.success ? sesionRes.data.nutri_coins : 0;

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Comunidad</span><h1>Ranking & Tienda</h1></div>
    </div>

    <div class="card">
      <h3 style="font-size:15px;margin-bottom:14px;">🏆 Ligas NutriFit</h3>
      <div class="league-tabs" id="league-tabs"></div>
      <div id="leaderboard-list"></div>
    </div>

    <div class="card" style="margin-top:18px;">
      <h3 style="font-size:15px;margin-bottom:14px;">👥 Amigos</h3>
      <div style="display:flex;gap:8px;margin-bottom:14px;">
        <input type="email" id="input-email-amigo" placeholder="Email de tu amigo" />
        <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="agregarAmigo()">Agregar</button>
      </div>
      <div id="lista-amigos"></div>
    </div>

    <div class="card" style="margin-top:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 style="font-size:15px;">🛍️ Tienda NutriFit</h3>
        <span class="mono" style="font-size:13.5px;font-weight:700;color:var(--color-xp);">🪙 ${coins.toLocaleString('es-AR')} NutriCoins</span>
      </div>
      <p style="font-size:12px;margin-bottom:14px;">Ganás 100 NutriCoins cada vez que subís de nivel.</p>
      <div class="grid-3" id="tienda-grid"></div>
    </div>
  `;

  if (rankingRes.success) {
    ligaActiva = rankingRes.data.mi_liga;
    renderLeagueTabs(rankingRes.data);
  }
  if (tiendaRes.success) renderTienda(tiendaRes.data);
  renderAmigos();
}

function renderLeagueTabs(data) {
  const tabsEl = document.getElementById('league-tabs');
  const ligas = ['diamante', 'oro', 'plata', 'bronce'];
  const iconos = { diamante: '💎', oro: '🥇', plata: '🥈', bronce: '🥉' };

  tabsEl.innerHTML = ligas.map((l) => `
    <button class="league-tab ${l === ligaActiva ? 'active' : ''}" onclick="switchLiga('${l}')">
      ${iconos[l]} ${l.charAt(0).toUpperCase() + l.slice(1)}
    </button>
  `).join('');

  window._rankingData = data;
  renderLeaderboardList(data.ligas[ligaActiva]);
}

function switchLiga(liga) {
  ligaActiva = liga;
  document.querySelectorAll('.league-tab').forEach((t) => t.classList.remove('active'));
  event.target.classList.add('active');
  renderLeaderboardList(window._rankingData.ligas[liga]);
}

function renderLeaderboardList(usuarios) {
  const list = document.getElementById('leaderboard-list');
  if (!usuarios || usuarios.length === 0) {
    list.innerHTML = `<div class="empty-state">Aún no hay usuarios en esta liga.</div>`;
    return;
  }
  list.innerHTML = usuarios.map((u, idx) => `
    <div class="leaderboard-row ${window.NutriFitUser && u.id === window.NutriFitUser.id ? 'me' : ''}" style="background:var(--bg-inset);">
      <span class="rank">#${idx + 1}</span>
      <span class="name">${u.nombre}</span>
      <span class="xp">${u.xp_total} XP · Nv.${u.nivel}</span>
    </div>
  `).join('');
}

async function renderAmigos() {
  const res = await Api.amigosGet();
  const container = document.getElementById('lista-amigos');
  if (!res.success || res.data.length === 0) {
    container.innerHTML = `<div class="empty-state">Aún no agregaste amigos.</div>`;
    return;
  }
  container.innerHTML = res.data.map((a) => {
    const esSolicitudRecibida = a.estado === 'pendiente' && !Number(a.enviada_por_mi);
    const estadoLabel = a.estado === 'pendiente'
      ? (esSolicitudRecibida ? '<span style="color:var(--color-lima);font-size:11px;">te invitó</span>' : '<span style="color:var(--color-warning);font-size:11px;">pendiente</span>')
      : '';
    return `
      <div class="leaderboard-row" style="background:var(--bg-inset);">
        <span class="name">${a.nombre} ${estadoLabel}</span>
        ${esSolicitudRecibida ? `
          <span style="display:flex;gap:6px;">
            <button class="btn btn-primary" style="width:auto;padding:6px 12px;font-size:12px;" onclick="responderAmigo(${a.relacion_id}, true)">Aceptar</button>
            <button class="btn btn-ghost" style="width:auto;padding:6px 12px;font-size:12px;" onclick="responderAmigo(${a.relacion_id}, false)">Rechazar</button>
          </span>
        ` : `<span class="xp">Nv.${a.nivel}</span>`}
      </div>
    `;
  }).join('');
}

async function responderAmigo(relacionId, aceptar) {
  const res = aceptar ? await Api.amigosAceptar(relacionId) : await Api.amigosRechazar(relacionId);
  showToast(res.message);
  if (res.success) await renderAmigos();
}

async function agregarAmigo() {
  const email = document.getElementById('input-email-amigo').value.trim();
  if (!email) return;
  const res = await Api.amigosAgregar(email);
  showToast(res.message);
  if (res.success) {
    document.getElementById('input-email-amigo').value = '';
    await renderAmigos();
  }
}

function renderTienda(items) {
  const grid = document.getElementById('tienda-grid');
  const iconosPorTipo = {
    ropa_avatar: '👕', accesorio_avatar: '🕶️', aura: '✨', marco_perfil: '🖼️', titulo: '🏷️', cupon: '🎟️',
  };

  grid.innerHTML = items.map((item) => {
    let boton;
    if (!item.poseido) {
      boton = `<button class="btn btn-primary" onclick="canjearItem(${item.id})">Canjear</button>`;
    } else if (item.tipo === 'cupon' || item.tipo === 'titulo') {
      // Cupones/títulos no se "equipan" en el avatar, sólo se poseen.
      boton = `<button class="btn btn-ghost" disabled>Ya lo tienes</button>`;
    } else {
      boton = `<button class="btn ${item.equipado ? 'btn-primary' : 'btn-ghost'}" onclick="equiparItem(${item.id})">
        ${item.equipado ? '✓ Equipado' : 'Equipar'}
      </button>`;
    }
    return `
      <div class="shop-item card">
        <div class="icon-box">${iconosPorTipo[item.tipo] || '🎁'}</div>
        <div class="name">${item.nombre}</div>
        <div class="price">${item.costo_coins > 0 ? `${item.costo_coins} coins` : `${item.costo_xp} XP`}</div>
        ${boton}
      </div>
    `;
  }).join('');
}

async function canjearItem(itemId) {
  const res = await Api.tiendaCanjear(itemId);
  showToast(res.message);
  if (res.success) {
    const tiendaRes = await Api.tiendaGet();
    if (tiendaRes.success) renderTienda(tiendaRes.data);
  }
}

async function equiparItem(itemId) {
  const res = await Api.tiendaEquipar(itemId);
  showToast(res.message);
  if (res.success) {
    const tiendaRes = await Api.tiendaGet();
    if (tiendaRes.success) renderTienda(tiendaRes.data);
  }
}

// =====================================================================
// Editar perfil (después del onboarding)
// =====================================================================
const EP_OBJETIVOS = [
  ['perder_grasa', 'Perder peso'], ['mantener_peso', 'Mantener peso'], ['ganar_musculo', 'Ganar músculo'],
  ['monitorear_salud', 'Monitorear por condición de salud'], ['mejorar_habitos', 'Mejorar hábitos'],
];
const EP_ACTIVIDADES = [
  ['sedentario', 'Sedentario'], ['ligero', 'Ligero'], ['moderado', 'Moderado'], ['muy_activo', 'Muy activo'],
];
const EP_DIETAS = [
  ['omnivora', 'Normal / Omnívora'], ['vegetariana', 'Vegetariana'], ['vegana', 'Vegana'],
  ['pescatariana', 'Pescatariana'], ['keto', 'Keto'], ['sin_gluten', 'Sin Gluten'],
];
const EP_ALERGIAS = [
  ['celiaco', 'Celíaco / Sin TACC'], ['lactosa', 'Intolerancia a la Lactosa'], ['frutos_secos', 'Frutos Secos'],
  ['mariscos', 'Mariscos'], ['huevo', 'Huevo'], ['diabetes', 'Diabetes'], ['hipertension', 'Hipertensión'],
];

async function abrirModalEditarPerfil() {
  const res = await Api.perfilGet();
  if (!res.success) { showToast(res.message); return; }
  const p = res.data;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-editar-perfil';
  overlay.innerHTML = `
    <div class="modal-card" style="max-width:420px;">
      <h3 style="margin-bottom:16px;">Editar perfil</h3>

      <div class="field"><label>Edad</label><input type="number" id="ep-edad" value="${p.edad}" min="12" max="100" /></div>
      <div class="field">
        <label>Género</label>
        <select id="ep-genero">
          <option value="masculino" ${p.genero === 'masculino' ? 'selected' : ''}>Masculino</option>
          <option value="femenino" ${p.genero === 'femenino' ? 'selected' : ''}>Femenino</option>
          <option value="otro" ${p.genero === 'otro' ? 'selected' : ''}>Otro</option>
        </select>
      </div>
      <div class="field"><label>Peso actual (kg)</label><input type="number" step="0.1" id="ep-peso" value="${p.peso_actual}" /></div>
      <div class="field"><label>Peso objetivo (kg)</label><input type="number" step="0.1" id="ep-peso-objetivo" value="${p.peso_objetivo ?? ''}" /></div>
      <div class="field"><label>Altura (cm)</label><input type="number" step="0.1" id="ep-altura" value="${p.altura}" /></div>

      <div class="field">
        <label>Objetivo</label>
        <select id="ep-objetivo">${EP_OBJETIVOS.map(([v, l]) => `<option value="${v}" ${p.objetivo === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
      <div class="field">
        <label>Nivel de actividad</label>
        <select id="ep-actividad">${EP_ACTIVIDADES.map(([v, l]) => `<option value="${v}" ${p.nivel_actividad === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
      <div class="field">
        <label>Lugar de entrenamiento</label>
        <select id="ep-lugar">
          <option value="casa" ${p.lugar_entreno === 'casa' ? 'selected' : ''}>Casa (sin equipo)</option>
          <option value="gimnasio" ${p.lugar_entreno === 'gimnasio' ? 'selected' : ''}>Gimnasio</option>
        </select>
      </div>
      <div class="field">
        <label>Tipo de dieta</label>
        <select id="ep-dieta">${EP_DIETAS.map(([v, l]) => `<option value="${v}" ${p.tipo_dieta === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
      </div>
      <div class="field">
        <label>Alergias / condiciones</label>
        <div class="ob-options-multi" id="ep-alergias">
          ${EP_ALERGIAS.map(([v, l]) => `
            <label class="ob-check-chip"><input type="checkbox" value="${v}" ${p.alergias.includes(v) ? 'checked' : ''}><span>${l}</span></label>
          `).join('')}
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-editar-perfil').remove()">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="guardarEdicionPerfil()">Guardar cambios</button>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

async function guardarEdicionPerfil() {
  const alergiasSeleccionadas = Array.from(document.querySelectorAll('#ep-alergias input:checked')).map((el) => el.value);

  const payload = {
    edad: document.getElementById('ep-edad').value,
    genero: document.getElementById('ep-genero').value,
    peso_actual: document.getElementById('ep-peso').value,
    peso_objetivo: document.getElementById('ep-peso-objetivo').value || null,
    altura: document.getElementById('ep-altura').value,
    objetivo: document.getElementById('ep-objetivo').value,
    nivel_actividad: document.getElementById('ep-actividad').value,
    lugar_entreno: document.getElementById('ep-lugar').value,
    tipo_dieta: document.getElementById('ep-dieta').value,
    alergias: alergiasSeleccionadas,
  };

  const res = await Api.onboarding(payload);
  if (!res.success) { showToast(res.message); return; }

  document.getElementById('modal-editar-perfil').remove();
  showToast('Perfil actualizado correctamente.');
  await renderProgreso();
}
