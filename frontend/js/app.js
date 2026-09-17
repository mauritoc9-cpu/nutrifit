/**
 * NutriFit — Lógica de la aplicación principal (SPA de 4 pestañas).
 * DEBUG: v2026-09-16-test-1 (verificar caché)
 */

const VIEWS = ['progreso', 'actividad', 'mi_progreso', 'nutricion', 'escaner', 'ranking', 'mi_perfil'];
let cameraStream = null;

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
  if (!btn) return;
  btn.innerHTML = recordatoriosActivos()
    ? `<span class="icon">${Icon('bell')}</span> Recordatorios activados`
    : `<span class="icon">${Icon('bell-off')}</span> Activar recordatorios`;
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
      new Notification('NutriFit', {
        body: `Llevás ${hidratacion.data.vasos} de ${hidratacion.data.meta_vasos} vasos hoy. ¡No te olvides de hidratarte!`,
        icon: 'assets/img/logo/nutrifit-logo-icon.png',
      });
      log.hidratacion = true;
      localStorage.setItem(RECORDATORIOS_LOG_KEY, JSON.stringify(log));
    }
  }

  if (!log.racha && horaActual >= 20) {
    const sesion = await Api.sesion();
    const hoy = new Date().toISOString().slice(0, 10);
    if (sesion.success && sesion.data.ultima_actividad !== hoy) {
      new Notification('NutriFit', {
        body: `Tu racha de ${sesion.data.racha_dias} día${sesion.data.racha_dias === 1 ? '' : 's'} está en riesgo. ¡Registrá algo antes de medianoche!`,
        icon: 'assets/img/logo/nutrifit-logo-icon.png',
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
  if (viewName !== 'mi_perfil') destroyPerfilRobot();

  if (viewName === 'progreso') await renderProgreso();
  if (viewName === 'actividad') await renderActividad();
  if (viewName === 'mi_progreso') await renderMiProgreso();
  if (viewName === 'nutricion') await renderNutricion();
  if (viewName === 'escaner') renderEscaner();
  if (viewName === 'ranking') await renderRanking();
  if (viewName === 'mi_perfil') await renderMiPerfil();
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

async function handleXPResult(xpResult, { skipRobotReaction = false } = {}) {
  if (!xpResult) return;
  showToast(`+${xpResult.xp_ganado} XP ganado${xpResult.multiplicador > 1 ? ` (x${xpResult.multiplicador} racha)` : ''}`);
  if (xpResult.subio_de_nivel) {
    if (!skipRobotReaction) reaccionarRobotAvatar('levelup');
    launchConfetti();
    setTimeout(() => showToast(`¡Subiste a nivel ${xpResult.nivel}! +${xpResult.coins_ganados} NutriCoins`), 700);
  } else if (!skipRobotReaction) {
    reaccionarRobotAvatar('xp');
  }
  const sesion = await Api.sesion();
  if (sesion.success) renderUserChip(sesion.data);
}

/** Reacciona con la instancia del robot del modal "Personalizar Avatar",
 *  si está abierto en este momento (Home ya no tiene su propio robot). */
function reaccionarRobotAvatar(tipo) {
  const target = robotAvatarModal;
  if (!target) return;
  if (tipo === 'levelup') target.celebrate();
  else if (tipo === 'celebration') target.playAnimation?.('celebration');
  else target.pulseHappy();
}

// =====================================================================
// PESTAÑA 1 — Mi Progreso & Avatar 3D
// =====================================================================
function saludoSegunHora() {
  const hora = new Date().getHours();
  if (hora < 12) return 'Buenos días';
  if (hora < 20) return 'Buenas tardes';
  return 'Buenas noches';
}

/** Variación de peso desde una fecha de referencia (por defecto, el 1° de
 *  este mes — así Home/llamadas viejas que no pasan `fechaDesde` siguen
 *  viendo exactamente "este mes") a partir del historial real de
 *  progreso_peso (nunca inventa datos: si no hay un registro anterior
 *  con el que comparar, devuelve null). La reusa tanto el resumen
 *  mensual como Mi Progreso (rango semanal) — un solo cálculo, dos
 *  fechas de referencia distintas. */
function calcularVariacionPeso(historial, pesoActual, fechaDesde) {
  if (!historial || historial.length === 0 || pesoActual == null) return null;

  const hoy = new Date();
  const desde = fechaDesde || `${hoy.getFullYear()}-${String(hoy.getMonth() + 1).padStart(2, '0')}-01`;

  // historial viene ASC por fecha desde el backend. Preferimos comparar
  // contra el último registro anterior al rango (punto de partida real);
  // si no hay ninguno, usamos el primer registro DENTRO del rango
  // (siempre que haya al menos dos, si no hay nada que comparar).
  const anteriores = historial.filter((h) => h.fecha < desde);
  let base = anteriores.length > 0 ? anteriores[anteriores.length - 1] : null;

  if (!base) {
    const delRango = historial.filter((h) => h.fecha >= desde);
    if (delRango.length >= 2) base = delRango[0];
  }
  if (!base) return null;

  const delta = Math.round((pesoActual - parseFloat(base.peso_registrado)) * 10) / 10;
  return { delta, direccion: delta === 0 ? 'igual' : (delta < 0 ? 'baja' : 'sube') };
}

// =====================================================================
// HOME (pestaña "Inicio", id de vista "progreso" — se mantiene el id para
// no romper loadView/CSS existentes). SIN avatar 3D: el robot vive
// únicamente en Mi Perfil, Home no lo inicializa ni reserva su lugar.
// Encabezado limpio + un único bloque "Tu día" (métricas + UNA
// recomendación contextual) + rutina de hoy como lista directa.
// =====================================================================
async function renderProgreso() {
  const container = document.getElementById('view-progreso');
  const hoy = new Date().toISOString().slice(0, 10);

  // Api.* nunca rechaza la promesa (apiRequest atrapa errores de red y
  // siempre resuelve con {success:false,...}), así que Promise.all es
  // seguro: si una fuente falla, las demás igual llegan.
  const [sesion, pesoData, actividadHoy, nutricionData, hidratacionData, rutinaData, planData] = await Promise.all([
    Api.sesion(),
    Api.progresoPesoGet(),
    Api.actividadResumenDia(hoy),
    Api.dashboardDiario(hoy),
    Api.hidratacionGet(),
    Api.rutinaRecomendada(),
    Api.planPersonalizacion(),
  ]);
  if (!sesion.success) return;
  const u = sesion.data;

  planPersonalizacionCache = planData.success ? planData.data : null;
  const estado = calcularEstadoDelDia({ nutricionData, actividadHoy, hidratacionData, rutinaData });

  container.innerHTML = `
    <div class="home-stack">
      <div class="home-header-clean">
        <span class="eyebrow">Hoy</span>
        <h1>${saludoSegunHora()}, ${u.nombre.split(' ')[0]}</h1>
        <p class="home-fecha">${fechaLargaHoy()}</p>
        <p class="home-subtitulo">¿Cómo viene tu día?</p>
      </div>

      <div class="card tu-dia-card">
        <h3 class="tu-dia-block-title">Tu día</h3>
        ${renderMetricasSalud(estado)}
        ${renderRecomendacionDelDia(calcularPrioridadDeHoy(estado, planPersonalizacionCache))}
      </div>

      <div class="card entrenamiento-hoy-card">
        ${renderEntrenamientoHoy(rutinaData)}
      </div>

      ${renderProgresoMiniHome(pesoData, u)}

      <div class="card home-actions-card">
        ${renderAccionesRapidas()}
      </div>
    </div>
  `;
}

/** "Domingo 14 de septiembre" — Intl en es-AR da el día/mes en minúscula,
 *  se capitaliza la primera letra nada más. */
function fechaLargaHoy() {
  const f = new Date().toLocaleDateString('es-AR', { weekday: 'long', day: 'numeric', month: 'long' });
  return f.charAt(0).toUpperCase() + f.slice(1);
}

/** Junta lo mínimo necesario (de datos ya pedidos, sin llamados nuevos)
 *  para decidir métricas y la recomendación del día en un solo lugar. */
function calcularEstadoDelDia({ nutricionData, actividadHoy, hidratacionData, rutinaData }) {
  const cal = nutricionData.success ? nutricionData.data.totales_consumidos.calorias : 0;
  const calMeta = nutricionData.success ? nutricionData.data.metas.meta_calorias : 0;

  const pasos = actividadHoy.success ? actividadHoy.data.pasos : 0;
  const pasosMeta = actividadHoy.success ? actividadHoy.data.meta_pasos : 10000;

  const vasos = hidratacionData.success ? hidratacionData.data.vasos : 0;
  const vasosMeta = hidratacionData.success ? hidratacionData.data.meta_vasos : 8;

  const entrenoDisponible = rutinaData.success;
  const entrenoCompletado = entrenoDisponible && rutinaData.data.completada_hoy;
  const entrenoTitulo = entrenoDisponible ? rutinaData.data.titulo : null;

  return {
    cal, calMeta, calPct: calMeta > 0 ? Math.min(100, (cal / calMeta) * 100) : 0,
    pasos, pasosMeta, pasosPct: pasosMeta > 0 ? Math.min(100, (pasos / pasosMeta) * 100) : 0,
    vasos, vasosMeta, aguaPct: vasosMeta > 0 ? Math.min(100, (vasos / vasosMeta) * 100) : 0,
    entrenoDisponible, entrenoCompletado, entrenoTitulo,
  };
}

/** "Tu día" como UN bloque — columnas con divisores sutiles y barras
 *  chicas, no cuatro tarjetas grandes independientes. */
function renderMetricasSalud(estado) {
  const { cal, calMeta, calPct, pasos, pasosMeta, pasosPct, vasos, vasosMeta, aguaPct, entrenoDisponible, entrenoCompletado, entrenoTitulo } = estado;

  return `
    <div class="tu-dia-cols">
      <div class="tu-dia-col" role="button" tabindex="0" onclick="loadView('actividad')">
        <span class="tu-dia-col-label">Pasos</span>
        <span class="tu-dia-col-value mono">${pasos.toLocaleString('es-AR')}<small> / ${pasosMeta.toLocaleString('es-AR')}</small></span>
        <div class="tu-dia-bar"><div style="width:${pasosPct}%;background:var(--color-lima);"></div></div>
      </div>

      <div class="tu-dia-col" role="button" tabindex="0" onclick="loadView('mi_progreso')">
        <span class="tu-dia-col-label">Agua</span>
        <span class="tu-dia-col-value mono">${vasos}<small> / ${vasosMeta}</small></span>
        <div class="tu-dia-bar"><div style="width:${aguaPct}%;background:var(--color-hidratacion);"></div></div>
      </div>

      <div class="tu-dia-col" role="button" tabindex="0" onclick="loadView('mi_progreso')">
        <span class="tu-dia-col-label">Nutrición</span>
        <span class="tu-dia-col-value mono">${Math.round(cal)}<small> / ${calMeta || '—'}</small></span>
        <div class="tu-dia-bar"><div style="width:${calPct}%;background:var(--color-nutricion);"></div></div>
      </div>

      <div class="tu-dia-col" role="button" tabindex="0" onclick="document.querySelector('.entrenamiento-hoy-card')?.scrollIntoView({behavior:'smooth'})">
        <span class="tu-dia-col-label">Entrenamiento</span>
        <span class="tu-dia-col-value${entrenoCompletado ? ' ok' : ''}">${!entrenoDisponible ? 'Sin rutina' : (entrenoCompletado ? 'Completado' : 'Pendiente')}</span>
        ${entrenoTitulo ? `<span class="tu-dia-col-sub">${entrenoTitulo}</span>` : ''}
      </div>
    </div>
  `;
}

// =====================================================================
// "Tu prioridad de hoy"
// ---------------------------------------------------------------------
// El ORDEN de importancia lo decide el backend (PerfilObjetivo →
// PlanPersonalizacion.prioridades, ordenadas para el objetivo de ESTE
// usuario). El frontend NO decide qué importa más: solo mira qué de eso
// todavía está pendiente hoy y lo escribe en castellano.
//
// Este mapa es puramente de presentación: traduce el código interno del
// plan al área de la app que lo resuelve. Sin él, el usuario vería
// literales como "adherencia_nutricional".
// =====================================================================
let planPersonalizacionCache = null;

const AREA_POR_PRIORIDAD = {
  adherencia_nutricional: 'nutricion',
  equilibrio_nutricional: 'nutricion',
  proteina_diaria: 'nutricion',
  superavit_calorico: 'nutricion',
  constancia_comidas: 'nutricion',
  registro_alimentacion: 'nutricion',
  registro_constante: 'nutricion',
  actividad_diaria: 'actividad',
  movimiento_diario: 'actividad',
  movimiento_suave: 'actividad',
  entrenamiento_fuerza: 'entrenamiento',
  hidratacion: 'hidratacion',
  // 'constancia' y 'seguimiento_profesional' no se traducen a una acción
  // concreta del día: se omiten a propósito en vez de inventar una.
};

/** ¿Qué queda pendiente hoy en cada área, y con qué acción se resuelve? */
function pendientePorArea(estado, area) {
  const { vasos, vasosMeta, aguaPct, cal, pasos, pasosMeta, pasosPct, entrenoDisponible, entrenoCompletado } = estado;

  if (area === 'hidratacion' && aguaPct < 100) {
    const faltan = vasosMeta - vasos;
    return { texto: `Te faltan ${faltan} vaso${faltan === 1 ? '' : 's'} para completar tu hidratación.`, accionLabel: 'Agregar agua', accion: 'agregarAguaDesdeHome()' };
  }
  if (area === 'nutricion' && cal <= 0) {
    return { texto: 'Todavía no registraste comidas hoy.', accionLabel: 'Registrar comida', accion: 'irARegistrarComida()' };
  }
  if (area === 'actividad' && pasosPct < 100) {
    const faltan = pasosMeta - pasos;
    return { texto: `Te faltan ${faltan.toLocaleString('es-AR')} pasos para tu objetivo.`, accionLabel: 'Ver actividad', accion: "loadView('actividad')" };
  }
  if (area === 'entrenamiento' && entrenoDisponible && !entrenoCompletado) {
    return { texto: 'Tu entrenamiento de hoy está pendiente.', accionLabel: 'Ver entrenamiento', accion: "document.querySelector('.entrenamiento-hoy-card')?.scrollIntoView({behavior:'smooth'})" };
  }
  return null;
}

/**
 * Recorre las prioridades EN EL ORDEN QUE MANDÓ EL BACKEND y devuelve la
 * primera que siga pendiente hoy. Si el plan no está disponible (sin
 * onboarding, error de red), se usa la recomendación de siempre para no
 * dejar el bloque vacío.
 */
function calcularPrioridadDeHoy(estado, plan) {
  const prioridades = Array.isArray(plan?.prioridades) ? plan.prioridades : [];
  if (prioridades.length === 0) return calcularRecomendacionDelDia(estado);

  for (const p of prioridades) {
    const area = AREA_POR_PRIORIDAD[p.codigo];
    if (!area) continue;
    const pendiente = pendientePorArea(estado, area);
    if (pendiente) {
      return { ...pendiente, motivoObjetivo: plan?.objetivo?.etiqueta || null };
    }
  }

  return { texto: 'Completaste tus objetivos de hoy.', accionLabel: null, accion: null, motivoObjetivo: plan?.objetivo?.etiqueta || null };
}

/** Respaldo cuando todavía no hay plan (perfil incompleto o falla de red).
 *  Mismo orden fijo que usaba Home antes de conectar el motor. */
function calcularRecomendacionDelDia(estado) {
  const { vasos, vasosMeta, aguaPct, cal, pasos, pasosMeta, pasosPct, entrenoDisponible, entrenoCompletado } = estado;

  if (aguaPct < 100) {
    const faltan = vasosMeta - vasos;
    return { texto: `Te faltan ${faltan} vaso${faltan === 1 ? '' : 's'} para completar tu hidratación.`, accionLabel: 'Agregar agua', accion: 'agregarAguaDesdeHome()' };
  }
  if (cal <= 0) {
    return { texto: 'Todavía no registraste comidas hoy.', accionLabel: 'Registrar comida', accion: 'irARegistrarComida()' };
  }
  if (pasosPct < 100) {
    const faltan = pasosMeta - pasos;
    return { texto: `Te faltan ${faltan.toLocaleString('es-AR')} pasos para tu objetivo.`, accionLabel: 'Ver actividad', accion: "loadView('actividad')" };
  }
  if (entrenoDisponible && !entrenoCompletado) {
    return { texto: 'Tu entrenamiento está pendiente.', accionLabel: 'Comenzar entrenamiento', accion: "document.querySelector('.entrenamiento-hoy-card')?.scrollIntoView({behavior:'smooth'})" };
  }
  return { texto: 'Completaste tus objetivos de hoy.', accionLabel: null, accion: null };
}

function renderRecomendacionDelDia(rec) {
  return `
    <div class="tu-dia-recomendacion">
      <div class="tu-dia-prioridad-texto">
        <span class="tu-dia-prioridad-label">Tu prioridad de hoy${rec.motivoObjetivo ? ` · ${rec.motivoObjetivo}` : ''}</span>
        <p>${rec.accionLabel ? '' : `${Icon('circle-check', { size: 15 })} `}${rec.texto}</p>
      </div>
      ${rec.accionLabel ? `<button type="button" class="btn btn-primary" onclick="${rec.accion}">${rec.accionLabel}</button>` : ''}
    </div>
  `;
}

/** Mismo endpoint que ya usa Nutrición (Api.hidratacionSumar) — acá solo
 *  refresca Home en vez de la pantalla de Nutrición. */
async function agregarAguaDesdeHome() {
  const res = await Api.hidratacionSumar();
  if (!res.success) { showToast(res.message); return; }
  if (res.data.xp) await handleXPResult(res.data.xp);
  await renderProgreso();
}

/** Entrenamiento de hoy — el componente de rutina de siempre
 *  (renderRutinaHTML, mismo que usa Actividad), SIN <details>/acordeón:
 *  se ve directo. */
function renderEntrenamientoHoy(rutinaData) {
  return `
    <h3 class="home-section-title row-label">${Icon('dumbbell', { size: 16 })}Entrenamiento de hoy</h3>
    ${renderRutinaHTML(rutinaData)}
  `;
}

/** Mini-resumen de progreso de salud (peso/objetivo) con acceso a
 *  Mi Progreso — NO repite la pantalla completa, solo lo esencial para
 *  decidir si vale la pena entrar a ver el detalle. */
function renderProgresoMiniHome(pesoData, u) {
  const metas = pesoData.success ? pesoData.data.metas : null;
  const pesoActual = metas ? parseFloat(metas.peso_actual) : null;
  const pesoObjetivo = metas && metas.peso_objetivo != null ? parseFloat(metas.peso_objetivo) : null;

  let pesoHTML = `<span class="peso-compacto-label">Peso: <b>sin registrar</b></span>`;
  if (pesoActual != null && pesoObjetivo != null) {
    const diff = Math.round((pesoActual - pesoObjetivo) * 10) / 10;
    pesoHTML = diff === 0
      ? `<span class="peso-compacto-label">Peso actual: <b class="mono">${pesoActual} kg</b></span><span class="peso-compacto-delta">${Icon('circle-check', { size: 13 })} Objetivo alcanzado</span>`
      : `<span class="peso-compacto-label">Peso actual: <b class="mono">${pesoActual} kg</b></span><span class="peso-compacto-delta">Faltan ${Math.abs(diff)} kg para ${pesoObjetivo} kg</span>`;
  } else if (pesoActual != null) {
    pesoHTML = `<span class="peso-compacto-label">Peso actual: <b class="mono">${pesoActual} kg</b></span>`;
  }

  return `
    <div class="card home-peso-row" role="button" tabindex="0" onclick="loadView('mi_progreso')">
      <span class="row-icon">${Icon('scale', { size: 16 })}</span>
      <div class="peso-compacto-resumen">${pesoHTML}</div>
      <span class="chev">Mi Progreso${Icon('chevrons-right', { size: 13 })}</span>
    </div>
  `;
}

function renderAccionesRapidas() {
  return `
    <h3 class="home-section-title">Acciones rápidas</h3>
    <div class="quick-actions">
      <button type="button" class="quick-action-btn" onclick="irARegistrarComida()">${Icon('utensils', { size: 20 })}Registrar comida</button>
      <button type="button" class="quick-action-btn" onclick="irAEscanear()">${Icon('scan', { size: 20 })}Escanear alimento</button>
      <button type="button" class="quick-action-btn" onclick="loadView('actividad')">${Icon('dumbbell', { size: 20 })}Registrar actividad</button>
      <button type="button" class="quick-action-btn" onclick="loadView('ranking')">${Icon('trophy', { size: 20 })}Ranking y Tienda</button>
    </div>
  `;
}

async function irARegistrarComida() {
  await loadView('nutricion');
  document.getElementById('input-buscar-alimento')?.focus();
}

async function irAEscanear() {
  await loadView('escaner');
}

// =====================================================================
// ACTIVIDAD — "¿qué actividad hago/registro?": acciones (caminar/correr/
// bicicleta, entrenamiento de hoy, podómetro) + historial reciente. Antes
// vivía disperso/colapsado dentro de Mi Progreso; es la MISMA data y los
// MISMOS endpoints (Api.actividad*, Api.pasosGet/Sumar, Api.rutinaRecomendada,
// Api.completarEntrenamiento) — no hay backend nuevo para esta vista.
// =====================================================================
const NOMBRES_TIPO_ACTIVIDAD = { caminata: 'Caminata', carrera: 'Carrera', ciclismo: 'Bicicleta' };
const ICONO_TIPO_ACTIVIDAD = { caminata: 'footprints', carrera: 'zap', ciclismo: 'bike' };
let historialActividadLimite = 10;

async function renderActividad() {
  const container = document.getElementById('view-actividad');
  container.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Cargando tu actividad…</span></div>`;

  historialActividadLimite = 10;
  const [resumenHoyRes, rutinaData, historialRes] = await Promise.all([
    Api.actividadResumenDia(),
    Api.rutinaRecomendada(),
    Api.actividadHistorial(historialActividadLimite),
  ]);

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Tu movimiento de hoy</span><h1>Actividad</h1></div>
    </div>

    <div class="card act-hero" id="act-hero-hoy" style="margin-bottom:18px;">
      ${renderActividadHeroHoy(resumenHoyRes)}
    </div>

    <div class="card" style="margin-bottom:18px;">
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('dumbbell', { size: 16 })}Registrar actividad</h3>
      <div id="form-actividad-demo"><p class="empty-state-sm">Cargando...</p></div>
    </div>

    <div class="card" style="margin-bottom:18px;">
      ${renderEntrenamientoHoy(rutinaData)}
    </div>

    <div class="card" style="margin-bottom:18px;">
      <details class="mp-secondary-action">
        <summary>${Icon('footprints', { size: 14 })}Podómetro / carga manual de pasos</summary>
        <div id="card-pasos" style="margin-top:12px;"></div>
      </details>
    </div>

    <div class="card" id="card-historial-actividad">
      ${renderHistorialActividadHTML(historialRes, historialActividadLimite)}
    </div>
  `;

  renderFormActividadDemo();
  Api.pasosGet().then((res) => {
    const cont = document.getElementById('card-pasos');
    if (cont) cont.innerHTML = renderPasosHTML(res);
  });
}

/** Resumen del día — una métrica principal (pasos, con progreso hacia la
 *  meta diaria si existe) y dos secundarias (distancia, kcal activas).
 *  Mismo endpoint que ya usa Home/pasos.php (Api.actividadResumenDia), sin
 *  recalcular nada acá. */
function renderActividadHeroHoy(resumenHoyRes) {
  if (!resumenHoyRes.success) return `<p class="empty-state-sm">No se pudo cargar tu actividad de hoy.</p>`;
  const d = resumenHoyRes.data;
  const meta = d.meta_pasos || 10000;
  const pct = meta > 0 ? Math.min(100, (d.pasos / meta) * 100) : 0;

  return `
    <div class="act-hero-row">
      ${crearRingProgreso({ tamano: 108, grosor: 10, color: 'var(--color-lima)', porcentaje: pct, valorTexto: `${Math.round(pct)}%` })}
      <div class="act-hero-info">
        <div class="act-hero-primary"><span class="mono">${d.pasos.toLocaleString('es-AR')}</span><span class="act-hero-meta">/ ${meta.toLocaleString('es-AR')} pasos</span></div>
        <div class="act-hero-secondary">
          <span>${(d.distancia_metros / 1000).toFixed(1)} km</span>
          <span>${Math.round(d.calorias_kcal)} kcal activas</span>
        </div>
      </div>
    </div>
  `;
}

function actualizarActividadHeroSiVisible() {
  const el = document.getElementById('act-hero-hoy');
  if (!el) return;
  Api.actividadResumenDia().then((res) => { el.innerHTML = renderActividadHeroHoy(res); });
}

function formatearFechaRelativa(fecha) {
  const hoy = new Date().toISOString().slice(0, 10);
  const ayerDt = new Date();
  ayerDt.setDate(ayerDt.getDate() - 1);
  const ayer = ayerDt.toISOString().slice(0, 10);
  if (fecha === hoy) return 'Hoy';
  if (fecha === ayer) return 'Ayer';
  return new Date(`${fecha}T00:00:00`).toLocaleDateString('es-AR', { day: '2-digit', month: 'short' });
}

/** Historial compacto — duración/distancia/pasos según lo que tenga cada
 *  sesión (bicicleta nunca tiene pasos, por ejemplo). "Ver más" pide el
 *  mismo endpoint con un límite mayor: no existe todavía una vista de
 *  historial completo paginada, así que esto es lo más cercano sin crear
 *  un endpoint nuevo. */
function renderHistorialActividadHTML(historialRes, limite) {
  const historial = historialRes.success ? historialRes.data : [];
  if (historial.length === 0) {
    return `
      <h3 class="row-label" style="font-size:15px;margin-bottom:10px;">${Icon('calendar', { size: 16 })}Actividad reciente</h3>
      <p class="empty-state-sm">Todavía no registraste actividades.</p>
    `;
  }

  const itemsHTML = historial.map((h) => {
    const partes = [];
    if (h.duracion_segundos) partes.push(`${Math.round(h.duracion_segundos / 60)} min`);
    if (h.distancia_metros) partes.push(`${(h.distancia_metros / 1000).toFixed(1)} km`);
    if (h.pasos) partes.push(`${Number(h.pasos).toLocaleString('es-AR')} pasos`);
    return `
      <div class="meal-item">
        <div class="meal-item-info">
          <span class="meal-item-name">${formatearFechaRelativa(h.fecha)} · ${h.tipo_nombre}</span>
          <span class="meal-item-gramos mono">${partes.join(' · ') || h.fuente}</span>
        </div>
        <span class="cal mono">${h.calorias_kcal ? Math.round(h.calorias_kcal) : '—'} kcal</span>
      </div>
    `;
  }).join('');

  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:10px;">${Icon('calendar', { size: 16 })}Actividad reciente</h3>
    <div>${itemsHTML}</div>
    ${historial.length >= limite ? `<button type="button" class="btn-link" style="margin-top:10px;" onclick="cargarMasHistorialActividad()">Ver más</button>` : ''}
  `;
}

async function cargarMasHistorialActividad() {
  historialActividadLimite += 15;
  const res = await Api.actividadHistorial(historialActividadLimite);
  const cont = document.getElementById('card-historial-actividad');
  if (cont) cont.innerHTML = renderHistorialActividadHTML(res, historialActividadLimite);
}

// =====================================================================
// MI PROGRESO — "¿estoy mejorando?": evolución y tendencias, nunca
// acciones/registro (eso es Actividad, o Nutrición para comidas/agua).
// Usa Api.resumenRango (mismo backend que Api.resumenSemanal, ahora con
// fechas explícitas para poder comparar el período elegido contra el
// inmediatamente anterior) + los mismos endpoints de siempre (peso).
// =====================================================================
const PERIODOS_MI_PROGRESO = [
  { dias: 7, label: '7 días' },
  { dias: 30, label: '30 días' },
  { dias: 90, label: '3 meses' },
];
let miProgresoPeriodoDias = 7;

function fmtFechaISO(d) { return d.toISOString().slice(0, 10); }

/** offsetDias=0 → período vigente (termina hoy). offsetDias=dias → el
 *  período inmediatamente anterior, de igual longitud, sin superposición. */
function calcularRangoPeriodo(dias, offsetDias = 0) {
  const hasta = new Date();
  hasta.setDate(hasta.getDate() - offsetDias);
  const desde = new Date(hasta);
  desde.setDate(desde.getDate() - (dias - 1));
  return { desde: fmtFechaISO(desde), hasta: fmtFechaISO(hasta) };
}

function cambiarPeriodoMiProgreso(dias) {
  if (miProgresoPeriodoDias === dias) return;
  miProgresoPeriodoDias = dias;
  renderMiProgreso();
}

async function renderMiProgreso() {
  const container = document.getElementById('view-mi_progreso');
  container.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Cargando tu progreso…</span></div>`;

  const dias = miProgresoPeriodoDias;
  const actual = calcularRangoPeriodo(dias);
  const previo = calcularRangoPeriodo(dias, dias);

  const [resumenRes, resumenPrevioRes, pesoRes] = await Promise.all([
    Api.resumenRango(actual.desde, actual.hasta),
    Api.resumenRango(previo.desde, previo.hasta),
    Api.progresoPesoGet(),
  ]);

  if (!resumenRes.success) {
    container.innerHTML = `<p class="empty-state">No se pudo cargar tu progreso. Probá de nuevo en un momento.</p>`;
    return;
  }
  const r = resumenRes.data;
  const rPrevio = resumenPrevioRes.success ? resumenPrevioRes.data : null;

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">¿Estoy mejorando?</span><h1>Mi Progreso</h1></div>
    </div>

    <div class="segmented" style="margin-bottom:18px;">
      ${PERIODOS_MI_PROGRESO.map((p) => `<button type="button" class="segmented-btn${p.dias === dias ? ' active' : ''}" onclick="cambiarPeriodoMiProgreso(${p.dias})">${p.label}</button>`).join('')}
    </div>

    <div class="today-grid" style="margin-bottom:18px;">
      ${renderResumenProgresoTiles(r, rPrevio, pesoRes, actual.desde)}
    </div>

    <div class="card" id="seccion-peso" style="margin-bottom:18px;">
      ${renderSeccionPeso(pesoRes, actual.desde)}
    </div>

    <div class="card" id="seccion-actividad" style="margin-bottom:18px;">
      ${renderSeccionActividadProgreso(r.actividad, dias)}
    </div>

    <div class="card" style="margin-bottom:18px;">
      ${renderSeccionNutricionProgreso(r.nutricion)}
    </div>

    <div class="card">
      ${renderSeccionHidratacionProgreso(r.hidratacion)}
    </div>
  `;
}

/** 4 mini-tarjetas (peso/actividad/nutrición/hidratación). Solo peso y
 *  actividad muestran flecha ↑/↓: son las únicas dos donde comparar contra
 *  el período anterior tiene un significado directo y sin ambigüedad. Si
 *  el período anterior no tiene datos (0 pasos, por ejemplo), no se
 *  inventa un porcentaje sobre una base cero: se omite la flecha. */
function renderResumenProgresoTiles(r, rPrevio, pesoRes, actualDesde) {
  const metas = pesoRes.success ? pesoRes.data.metas : null;
  const pesoActual = metas ? parseFloat(metas.peso_actual) : null;
  const historial = pesoRes.success ? pesoRes.data.historial : [];
  const variacionPeso = pesoActual != null ? calcularVariacionPeso(historial, pesoActual, actualDesde) : null;
  const pesoDeltaHTML = variacionPeso && variacionPeso.direccion !== 'igual'
    ? `<span class="mp-delta ${variacionPeso.direccion === 'baja' ? 'down' : 'up'}">${Icon(variacionPeso.direccion === 'baja' ? 'trending-down' : 'trending-up', { size: 12 })} ${Math.abs(variacionPeso.delta)} kg</span>`
    : '';

  const pasosActual = r.actividad.pasos_totales;
  const pasosPrevio = rPrevio ? rPrevio.actividad.pasos_totales : null;
  let actividadDeltaHTML = '';
  if (pasosPrevio) {
    const pct = Math.round(((pasosActual - pasosPrevio) / pasosPrevio) * 100);
    if (pct !== 0) actividadDeltaHTML = `<span class="mp-delta ${pct > 0 ? 'up' : 'down'}">${Icon(pct > 0 ? 'trending-up' : 'trending-down', { size: 12 })} ${Math.abs(pct)}%</span>`;
  }

  const n = r.nutricion;
  const nutricionHTML = (n.dias_con_registro > 0 && n.meta_calorias)
    ? `<div class="today-tile-value mono">${Math.round((n.calorias_promedio / n.meta_calorias) * 100)}%</div><div class="today-tile-goal">del objetivo calórico</div>`
    : `<div class="today-tile-value mono">—</div><div class="today-tile-goal">${n.dias_con_registro > 0 ? 'Sin objetivo configurado' : 'Sin registros'}</div>`;

  const h = r.hidratacion;
  const hidratacionHTML = h.dias_con_registro > 0
    ? `<div class="today-tile-value mono">${(h.ml_promedio / 1000).toFixed(1)} L</div><div class="today-tile-goal">promedio diario</div>`
    : `<div class="today-tile-value mono">—</div><div class="today-tile-goal">Sin registros</div>`;

  return `
    <div class="today-tile" role="button" tabindex="0" onclick="document.getElementById('seccion-peso')?.scrollIntoView({behavior:'smooth'});">
      <div class="today-tile-head">${Icon('scale', { size: 15 })}<span>Peso</span></div>
      <div class="today-tile-value mono">${pesoActual != null ? `${pesoActual} kg` : '—'}</div>
      <div class="today-tile-goal">${pesoDeltaHTML || (pesoActual != null ? 'Sin cambios en el período' : 'Sin registros')}</div>
    </div>
    <div class="today-tile" role="button" tabindex="0" onclick="loadView('actividad')">
      <div class="today-tile-head">${Icon('footprints', { size: 15 })}<span>Actividad</span></div>
      <div class="today-tile-value mono">${pasosActual.toLocaleString('es-AR')}</div>
      <div class="today-tile-goal">${actividadDeltaHTML || 'pasos en el período'}</div>
    </div>
    <div class="today-tile">
      <div class="today-tile-head">${Icon('utensils', { size: 15 })}<span>Nutrición</span></div>
      ${nutricionHTML}
    </div>
    <div class="today-tile">
      <div class="today-tile-head">${Icon('droplets', { size: 15 })}<span>Hidratación</span></div>
      ${hidratacionHTML}
    </div>
  `;
}

/** Peso: inicial del período / actual / objetivo + gráfico simple cuando
 *  hay ≥2 registros en el período + "Actualizar peso" como acción
 *  secundaria (colapsada), no como formulario protagonista. */
function renderSeccionPeso(pesoRes, rangoDesde) {
  const metas = pesoRes.success ? pesoRes.data.metas : null;
  const historial = pesoRes.success ? pesoRes.data.historial : [];
  const pesoActual = metas ? parseFloat(metas.peso_actual) : null;
  const pesoObjetivo = metas && metas.peso_objetivo != null ? parseFloat(metas.peso_objetivo) : null;

  if (pesoActual == null) {
    return `
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('scale', { size: 16 })}Evolución de peso</h3>
      <p class="empty-state-sm">Todavía no registraste tu peso.</p>
      ${renderActualizarPesoToggle()}
    `;
  }

  const delPeriodo = historial.filter((h) => h.fecha >= rangoDesde);
  // Con un solo registro dentro del período, "inicial" y "actual" serían el
  // mismo punto — mostrarlo igual sugeriría una evolución que todavía no se
  // puede ver; con ≥2 sí hay una evolución real dentro de la ventana.
  const pesoInicial = delPeriodo.length >= 2 ? parseFloat(delPeriodo[0].peso_registrado) : null;

  const variacion = calcularVariacionPeso(historial, pesoActual, rangoDesde);
  let deltaHTML = '';
  if (variacion && variacion.direccion !== 'igual') {
    deltaHTML = `<span class="peso-compacto-delta">${variacion.direccion === 'baja' ? '↓' : '↑'} ${Math.abs(variacion.delta)} kg en este período</span>`;
  } else if (variacion) {
    deltaHTML = `<span class="peso-compacto-delta">Sin cambios en este período</span>`;
  }

  let objetivoHTML = '';
  if (pesoObjetivo != null) {
    const diff = Math.round((pesoActual - pesoObjetivo) * 10) / 10;
    objetivoHTML = diff === 0
      ? `<span class="peso-compacto-delta">${Icon('circle-check', { size: 13 })} Objetivo alcanzado (${pesoObjetivo} kg)</span>`
      : `<span class="peso-compacto-delta">Faltan ${Math.abs(diff)} kg para tu objetivo (${pesoObjetivo} kg)</span>`;
  }

  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:4px;">${Icon('scale', { size: 16 })}Evolución de peso</h3>
    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:6px;">${deltaHTML}${objetivoHTML}</div>
    <div class="macro-preview" style="margin:14px 0;">
      ${pesoInicial != null ? `<div class="macro-preview-item"><span class="valor mono">${pesoInicial}</span><span class="label">Inicial del período</span></div>` : ''}
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-lima);">${pesoActual}</span><span class="label">Actual</span></div>
      ${pesoObjetivo != null ? `<div class="macro-preview-item"><span class="valor mono">${pesoObjetivo}</span><span class="label">Objetivo</span></div>` : ''}
    </div>
    ${delPeriodo.length >= 2 ? renderSparklinePeso(delPeriodo, pesoObjetivo) : ''}
    ${renderActualizarPesoToggle()}
  `;
}

function renderActualizarPesoToggle() {
  return `
    <details class="mp-secondary-action">
      <summary>${Icon('plus', { size: 13 })}Actualizar peso de hoy</summary>
      <div style="display:flex;gap:8px;margin-top:10px;">
        <input type="number" step="0.1" id="input-peso-hoy" placeholder="Ej: 72.5" />
        <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="submitPesoHoy()">Guardar</button>
      </div>
    </details>
  `;
}

/** Línea de tendencia liviana en SVG (sin librería): viewBox fijo chico +
 *  preserveAspectRatio="none" para que estire al 100% del ancho del card
 *  vía CSS. Línea punteada opcional con el objetivo, si existe. */
function renderSparklinePeso(puntos, objetivo) {
  const valores = puntos.map((p) => parseFloat(p.peso_registrado));
  const min = Math.min(...valores, objetivo ?? valores[0]);
  const max = Math.max(...valores, objetivo ?? valores[0]);
  const rango = max - min || 1;
  const w = 100, h = 36, pad = 4;
  const puntosSVG = valores.map((v, i) => {
    const x = puntos.length > 1 ? (i / (puntos.length - 1)) * (w - pad * 2) + pad : w / 2;
    const y = h - pad - ((v - min) / rango) * (h - pad * 2);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
  const objetivoY = objetivo != null ? h - pad - ((objetivo - min) / rango) * (h - pad * 2) : null;

  return `
    <svg viewBox="0 0 ${w} ${h}" preserveAspectRatio="none" class="mp-sparkline">
      ${objetivoY != null ? `<line x1="0" y1="${objetivoY.toFixed(1)}" x2="${w}" y2="${objetivoY.toFixed(1)}" stroke="var(--border-subtle)" stroke-width="1" stroke-dasharray="3,3" />` : ''}
      <polyline points="${puntosSVG}" fill="none" stroke="var(--color-lima)" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  `;
}

/** Actividad como HISTORIAL/ESTADÍSTICA únicamente — sin formulario, sin
 *  podómetro, sin rutina: esas acciones viven en Actividad (loadView). */
function renderSeccionActividadProgreso(a, dias) {
  const porTipoEntries = Object.entries(a.por_tipo || {});
  const porTipoHTML = porTipoEntries.length
    ? porTipoEntries.map(([clave, d]) => `
        <div class="today-tile">
          <div class="today-tile-head"><span>${NOMBRES_TIPO_ACTIVIDAD[clave] || clave}</span></div>
          <div class="today-tile-value mono">${d.sesiones} sesi${d.sesiones == 1 ? 'ón' : 'ones'}</div>
          <div class="today-tile-goal">${Math.round(d.calorias_kcal)} kcal · ${Math.round(d.duracion_segundos / 60)} min</div>
        </div>
      `).join('')
    : '';

  return `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3 class="row-label" style="font-size:15px;">${Icon('footprints', { size: 16 })}Actividad</h3>
      <button type="button" class="btn-link" onclick="loadView('actividad')">Ir a Actividad ${Icon('arrow-right', { size: 13 })}</button>
    </div>

    <div class="macro-preview" style="margin-bottom:14px;">
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-lima);">${a.pasos_totales.toLocaleString('es-AR')}</span><span class="label">Pasos totales</span></div>
      <div class="macro-preview-item"><span class="valor mono">${Math.round(a.pasos_promedio_diario).toLocaleString('es-AR')}</span><span class="label">Promedio/día</span></div>
      <div class="macro-preview-item"><span class="valor mono">${(a.distancia_metros_total / 1000).toFixed(1)}</span><span class="label">km totales</span></div>
      <div class="macro-preview-item"><span class="valor mono">${a.sesiones_totales}</span><span class="label">Actividades</span></div>
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-xp);">${a.entrenamientos_completados}</span><span class="label">Entrenamientos</span></div>
    </div>

    ${dias <= 30 ? renderBarrasActividad(a.por_dia, dias) : ''}

    ${porTipoHTML ? `<div class="today-grid" style="margin-top:14px;">${porTipoHTML}</div>` : `<p class="empty-state-sm" style="margin-top:14px;">Sin sesiones registradas en este período.</p>`}
  `;
}

/** Distribución diaria de pasos — solo para 7/30 días: a 90 días una barra
 *  por día deja de ser legible, así que ese período se queda solo con los
 *  totales de arriba (no se inventa una agregación semanal que no se pidió). */
function renderBarrasActividad(porDia, dias) {
  const mapa = new Map((porDia || []).map((d) => [d.fecha, d]));
  const hoy = new Date();
  const serie = [];
  for (let i = dias - 1; i >= 0; i--) {
    const d = new Date(hoy);
    d.setDate(d.getDate() - i);
    const fecha = fmtFechaISO(d);
    serie.push({ fecha, pasos: mapa.has(fecha) ? Number(mapa.get(fecha).pasos) : 0 });
  }
  const max = Math.max(...serie.map((d) => d.pasos), 1);
  const barrasHTML = serie.map((d) => {
    const pct = Math.max(2, Math.round((d.pasos / max) * 100));
    const label = dias <= 7 ? new Date(`${d.fecha}T00:00:00`).toLocaleDateString('es-AR', { weekday: 'short' }).slice(0, 3) : '';
    return `<div class="mp-bar-col" title="${d.fecha}: ${d.pasos.toLocaleString('es-AR')} pasos"><div class="mp-bar" style="height:${pct}%;"></div>${label ? `<span class="mp-bar-label">${label}</span>` : ''}</div>`;
  }).join('');

  return `
    <div style="margin-bottom:6px;">
      <p style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">Pasos por día</p>
      <div class="mp-bars">${barrasHTML}</div>
    </div>
  `;
}

/** Calorías/macros "en modo progreso": promedio del rango vs. metas —
 *  NO es el buscador ni el diario de comidas (eso sigue exclusivo de
 *  Nutrición). Promedia solo sobre los días con registro real: un día
 *  sin comidas cargadas es "sin dato", no "cero calorías". Si un macro no
 *  tiene meta configurada, se muestra el valor sin barra (no se dibuja un
 *  progreso fingiendo una meta que no existe). */
function renderSeccionNutricionProgreso(n) {
  if (n.dias_con_registro === 0) {
    return `
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('flame', { size: 16 })}Nutrición</h3>
      <p class="empty-state-sm">Todavía no registraste comidas en este período. Se registran desde la pestaña Nutrición.</p>
    `;
  }

  const fila = (label, valor, meta, color) => {
    if (!meta) {
      return `<div class="macro-row"><div class="macro-label"><span>${label}</span><span class="mono">${Math.round(valor)} g</span></div></div>`;
    }
    const pct = Math.min(100, (valor / meta) * 100);
    return `
      <div class="macro-row">
        <div class="macro-label"><span>${label}</span><span class="mono">${Math.round(valor)} / ${meta} g</span></div>
        <div class="macro-track"><div class="macro-fill" style="width:${pct}%;background:${color};"></div></div>
      </div>
    `;
  };

  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:4px;">${Icon('flame', { size: 16 })}Nutrición</h3>
    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:14px;">Promedio de los ${n.dias_con_registro} día${n.dias_con_registro === 1 ? '' : 's'} con comidas registradas</p>
    ${fila('Calorías (kcal)', n.calorias_promedio, n.meta_calorias, 'var(--color-nutricion)')}
    ${fila('Proteínas', n.proteinas_promedio, n.meta_proteinas, 'var(--color-xp)')}
    ${fila('Carbohidratos', n.carbohidratos_promedio, n.meta_carbohidratos, 'var(--color-carbohidratos)')}
    ${fila('Grasas', n.grasas_promedio, n.meta_grasas, 'var(--color-grasas)')}
  `;
}

/** Mismo criterio que nutrición: promedio sobre días con registro real. */
function renderSeccionHidratacionProgreso(h) {
  if (h.dias_con_registro === 0) {
    return `
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('droplets', { size: 16 })}Hidratación</h3>
      <p class="empty-state-sm">Todavía no registraste agua en este período.</p>
    `;
  }
  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('droplets', { size: 16 })}Hidratación</h3>
    <div class="macro-preview">
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-hidratacion);">${h.vasos_promedio}</span><span class="label">Vasos/día prom.</span></div>
      <div class="macro-preview-item"><span class="valor mono">${(h.ml_promedio / 1000).toFixed(1)} L</span><span class="label">Litros/día prom.</span></div>
      <div class="macro-preview-item"><span class="valor mono">${h.meta_vasos}</span><span class="label">Meta (vasos)</span></div>
      <div class="macro-preview-item"><span class="valor mono">${h.dias_meta_cumplida}</span><span class="label">Días con meta cumplida</span></div>
    </div>
    <p style="font-size:12px;color:var(--text-secondary);margin-top:10px;">Sobre ${h.dias_con_registro} día${h.dias_con_registro === 1 ? '' : 's'} con registro.</p>
  `;
}

// =====================================================================
// MI PERFIL — identidad + avatar + gamificación personal. Reusa
// Api.sesion() (nivel/XP/coins/racha/equipados), Api.ranking() y
// Api.tiendaGet() (ya existían para la pestaña Ranking & Tienda — acá se
// piden de nuevo solo para mostrar un resumen/acceso rápido, no se
// duplica su lógica) y el sistema de avatar 3D existente.
// =====================================================================
async function renderMiPerfil() {
  const container = document.getElementById('view-mi_perfil');
  container.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Cargando tu perfil…</span></div>`;

  const [sesion, rankingRes, tiendaRes] = await Promise.all([
    Api.sesion(),
    Api.ranking(),
    Api.tiendaGet(),
  ]);
  if (!sesion.success) {
    container.innerHTML = `<p class="empty-state">No se pudo cargar tu perfil.</p>`;
    return;
  }
  const u = sesion.data;

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Identidad</span><h1>Mi Perfil</h1></div>
      <button type="button" class="perfil-config-btn" onclick="abrirModalEditarPerfil()" aria-label="Configuración del perfil" title="Configuración">${Icon('settings', { size: 20 })}</button>
    </div>

    <div class="card perfil-robot-card">
      <div class="robot-avatar-clickable" onclick="abrirModalPersonalizarAvatar()">
        <div class="robot-stage robot-stage--perfil-grande" id="robot-perfil-stage"></div>
      </div>
      <h2 class="perfil-nombre">${u.nombre}</h2>
      <span class="perfil-nivel-badge">Nivel ${u.progreso_nivel.nivel}</span>
      <button type="button" class="btn btn-primary row-label perfil-personalizar-btn" onclick="abrirModalPersonalizarAvatar()">${Icon('sparkles', { size: 16 })}Personalizar avatar</button>
    </div>

    <div class="card" style="margin-top:18px;">
      ${renderGamificacionPerfil(u)}
    </div>

    <div class="card" style="margin-top:18px;">
      ${renderLogrosPerfil(u)}
    </div>

    <div class="card" style="margin-top:18px;">
      ${renderRetosPerfilPlaceholder()}
    </div>

    <div class="card" style="margin-top:18px;">
      ${renderColeccionPerfil(tiendaRes)}
    </div>

    <div class="today-grid" style="margin-top:18px;">
      ${renderRankingTiendaMiniCards(u, rankingRes, tiendaRes)}
    </div>
  `;

  mountPerfilRobot(u.equipados || {});
}

/** Nivel/XP/NutriCoins/Racha — mismos datos que ya trae Api.sesion(). */
function renderGamificacionPerfil(u) {
  const progreso = u.progreso_nivel;
  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('award', { size: 16 })}Progreso en NutriFit</h3>
    <div class="avatar-hero-row" style="margin-bottom:6px;">
      <span class="avatar-hero-nivel">Nivel ${progreso.nivel}</span>
      <span class="mono avatar-hero-xp">${progreso.xp_en_nivel} / ${progreso.xp_para_siguiente} XP</span>
    </div>
    <div class="xp-bar-track" style="margin-bottom:16px;"><div class="xp-bar-fill" style="width:${progreso.porcentaje}%;"></div></div>
    <div class="macro-preview">
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-warning);">${Icon('flame', { size: 14 })} ${u.racha_dias}</span><span class="label">Racha (días)</span></div>
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-coin);">${NutriCoinIcon(14)} ${u.nutri_coins.toLocaleString('es-AR')}</span><span class="label">NutriCoins</span></div>
    </div>
  `;
}

/** Logros REALES derivados de nivel/racha (los únicos dos que hoy
 *  reconoce la app). Se centraliza acá la lista para que la vista previa
 *  y el modal "Ver todos" nunca puedan desincronizarse ni inventar más
 *  logros de los que existen de verdad. */
function obtenerLogrosPerfil(u) {
  return [
    { logrado: u.nivel >= 5, icono: 'award', titulo: 'Constancia', detalle: 'Alcanzar nivel 5' },
    { logrado: u.racha_dias >= 7, icono: 'flame', titulo: 'Racha de fuego', detalle: '7 días seguidos de actividad' },
  ];
}

function logroCardHTML(l) {
  return `
    <div class="logro-card${l.logrado ? ' logrado' : ''}">
      <div class="logro-icono">${Icon(l.icono, { size: 22 })}</div>
      <div class="logro-titulo">${l.titulo}</div>
      <div class="logro-detalle">${l.detalle}</div>
    </div>
  `;
}

function renderLogrosPerfil(u) {
  const logros = obtenerLogrosPerfil(u);
  return `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
      <h3 class="row-label" style="font-size:15px;">${Icon('badge-check', { size: 16 })}Logros</h3>
      <button type="button" class="btn-link" onclick="abrirModalLogros()">Ver todos ${Icon('arrow-right', { size: 12 })}</button>
    </div>
    <div class="logros-grid">${logros.map(logroCardHTML).join('')}</div>
  `;
}

/** Modal "Ver todos" — hoy son los mismos 2 logros de la vista previa (no
 *  hay más criterios reales todavía), pero deja el lugar preparado para
 *  cuando se sumen más sin tener que rediseñar nada. */
async function abrirModalLogros() {
  const sesion = await Api.sesion();
  if (!sesion.success) return;
  const logros = obtenerLogrosPerfil(sesion.data);

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-logros';
  overlay.innerHTML = `
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-icon">${Icon('badge-check', { size: 20 })}</div>
        <div><h3>Tus logros</h3><p class="modal-subtitle">${logros.filter((l) => l.logrado).length} de ${logros.length} desbloqueados</p></div>
        <button type="button" class="avatar-editor-close" onclick="document.getElementById('modal-logros').remove()" aria-label="Cerrar">${Icon('x', { size: 18 })}</button>
      </div>
      <div class="logros-grid" style="margin-top:16px;">${logros.map(logroCardHTML).join('')}</div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);
}

function renderRetosPerfilPlaceholder() {
  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:10px;">${Icon('calendar', { size: 16 })}Retos activos</h3>
    <p class="empty-state-sm">Todavía no existe un sistema de retos en NutriFit — esta sección va a mostrar tus retos activos (ej. "32.000 / 40.000 pasos esta semana") cuando se construya ese backend.</p>
  `;
}

/** "Mi Colección" — Apariencia (tintes del modelo 3D, tienda_items tipo
 *  ropa_avatar) es la ÚNICA categoría con soporte real hoy. Ropa y
 *  Accesorios existen como conceptos en la tienda/modal pero el modelo 3D
 *  es una malla única sin piezas separables (ver renderRopaPanel/
 *  renderAccesoriosPanel) — se muestran como "Próximamente", no se finge
 *  que ya funcionan. */
function renderColeccionPerfil(tiendaRes) {
  const items = tiendaRes.success ? tiendaRes.data : [];
  const skins = items.filter((i) => i.tipo === 'ropa_avatar');
  const skinsPoseidas = skins.filter((i) => i.poseido).length;

  return `
    <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('shirt', { size: 16 })}Mi Colección</h3>
    <div class="macro-preview" style="margin-bottom:14px;">
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-lima);">${skinsPoseidas}/${skins.length || 0}</span><span class="label">Skins</span></div>
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--text-secondary);">—</span><span class="label">Ropa</span></div>
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--text-secondary);">—</span><span class="label">Accesorios</span></div>
    </div>
    <p class="empty-state-sm" style="margin-bottom:14px;">Ropa y accesorios todavía no están disponibles: el modelo 3D actual es una sola malla y no separa esas piezas.</p>
    <button type="button" class="btn btn-ghost row-label" onclick="abrirModalPersonalizarAvatar('apariencia')">${Icon('sparkles', { size: 15 })}Ver colección</button>
  `;
}

const NOMBRE_LIGA = { bronce: 'Liga Bronce', plata: 'Liga Plata', oro: 'Liga Oro', diamante: 'Liga Diamante' };

/** Accesos rápidos a Ranking & Tienda (misma vista, sin duplicar su
 *  contenido acá) — solo un vistazo real: en qué liga/posición está el
 *  usuario (Api.ranking, ya calcula esto para el leaderboard) y cuántos
 *  objetos de la tienda todavía no tiene. */
function renderRankingTiendaMiniCards(u, rankingRes, tiendaRes) {
  let rankingValor = '—';
  let rankingLabel = 'Ranking no disponible';
  if (rankingRes.success) {
    const miLiga = rankingRes.data.mi_liga;
    const lista = rankingRes.data.ligas[miLiga] || [];
    const idx = lista.findIndex((x) => Number(x.id) === Number(u.id));
    rankingValor = idx >= 0 ? `#${idx + 1}` : '—';
    rankingLabel = NOMBRE_LIGA[miLiga] || miLiga;
  }

  let tiendaValor = '—';
  let tiendaLabel = 'Tienda no disponible';
  if (tiendaRes.success) {
    tiendaValor = String(tiendaRes.data.filter((i) => !i.poseido).length);
    tiendaLabel = 'objetos por descubrir';
  }

  return `
    <div class="today-tile" role="button" tabindex="0" onclick="loadView('ranking')">
      <div class="today-tile-head">${Icon('trophy', { size: 15 })}<span>Ranking</span></div>
      <div class="today-tile-value mono">${rankingValor}</div>
      <div class="today-tile-goal">${rankingLabel}</div>
    </div>
    <div class="today-tile" role="button" tabindex="0" onclick="loadView('ranking')">
      <div class="today-tile-head">${Icon('store', { size: 15 })}<span>Tienda</span></div>
      <div class="today-tile-value mono">${tiendaValor}</div>
      <div class="today-tile-goal">${tiendaLabel}</div>
    </div>
  `;
}

function renderPasosHTML(pasosData) {
  const d = pasosData.success ? pasosData.data : { pasos: 0, meta_pasos: 10000, km_recorridos: 0, kcal_quemadas: 0 };
  const pct = d.meta_pasos > 0 ? Math.min(100, (d.pasos / d.meta_pasos) * 100) : 0;
  const soportado = pedometroSoportado();

  let estadoHTML;
  if (!soportado) {
    estadoHTML = '<span class="estado inactivo-soporte">No disponible en este navegador/dispositivo</span>';
  } else if (pedometroActivo) {
    estadoHTML = '<span class="estado activo"><span class="pasos-live-dot"></span>Detectando en vivo</span>';
  } else {
    estadoHTML = '<span class="estado">Detección desactivada</span>';
  }

  return `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
      <h3 class="row-label" style="font-size:15px;">${Icon('footprints', { size: 16 })}Pasos diarios</h3>
      <span class="mono" style="font-size:12px;color:var(--text-secondary);">${d.pasos.toLocaleString('es-AR')} / ${d.meta_pasos.toLocaleString('es-AR')}</span>
    </div>
    <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
      ${crearRingProgreso({ tamano: 120, grosor: 11, color: 'var(--color-lima)', porcentaje: pct, valorTexto: `${Math.round(pct)}%` })}
      <div style="display:flex;gap:24px;flex:1;min-width:160px;">
        <div><p style="font-size:12px;">Distancia</p><p class="mono" style="font-size:18px;font-weight:700;">${d.km_recorridos} km</p></div>
        <div><p style="font-size:12px;">Calorías</p><p class="mono" style="font-size:18px;font-weight:700;color:var(--color-nutricion);">${d.kcal_quemadas} kcal</p></div>
      </div>
    </div>

    <div class="pasos-mode-row">
      <div class="pasos-mode-info">
        <span class="titulo">Sensor de movimiento del dispositivo</span>
        ${estadoHTML}
      </div>
      <div class="theme-toggle-switch${pedometroActivo ? ' on' : ''}" onclick="toggleDeteccionPasos()"></div>
    </div>

    <details class="pasos-manual-fallback">
      <summary>¿Tu dispositivo no tiene sensor? Cargar pasos manualmente</summary>
      <div class="modal-quick-add" style="margin-top:10px;">
        <button type="button" class="chip-btn" onclick="sumarPasos(500)">+500</button>
        <button type="button" class="chip-btn" onclick="sumarPasos(1000)">+1.000</button>
        <button type="button" class="chip-btn" onclick="sumarPasos(2000)">+2.000</button>
      </div>
      <div style="display:flex;gap:8px;margin-top:10px;">
        <input type="number" id="input-pasos-manual" placeholder="Cantidad exacta" min="1" />
        <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="sumarPasosManual()">Agregar</button>
      </div>
    </details>
  `;
}

// =====================================================================
// Podómetro automático — usa el acelerómetro del dispositivo (DeviceMotion)
// para contar pasos en tiempo real y sincronizarlos con el backend.
// Limitación real: no existe una "Step Counter API" estándar en la web;
// esta es la técnica habitual (detección de picos de aceleración) que
// usan la mayoría de los pedómetros web/PWA. Requiere que la pestaña siga
// abierta y, en iOS, un toque explícito del usuario para pedir permiso.
// =====================================================================
const PEDOMETRO_UMBRAL = 2.2;
const PEDOMETRO_MIN_INTERVALO_MS = 300;
const PEDOMETRO_FLUSH_MS = 8000;

let pedometroActivo = false;
let pedometroPendientes = 0;
let pedometroFiltroBase = null;
let pedometroUltimoPasoTs = 0;
let pedometroFlushIntervalId = null;

function pedometroSoportado() {
  return typeof window !== 'undefined' && 'DeviceMotionEvent' in window && window.isSecureContext !== false;
}

async function toggleDeteccionPasos() {
  if (pedometroActivo) { desactivarPedometro(); return; }

  if (!pedometroSoportado()) {
    showToast('Tu dispositivo o navegador no soporta el sensor de movimiento. Usá la carga manual.');
    return;
  }

  if (typeof DeviceMotionEvent.requestPermission === 'function') {
    try {
      const permiso = await DeviceMotionEvent.requestPermission();
      if (permiso !== 'granted') { showToast('No se otorgó permiso para acceder al sensor de movimiento.'); return; }
    } catch (err) {
      showToast('No se pudo solicitar permiso del sensor.');
      return;
    }
  }

  pedometroFiltroBase = null;
  pedometroUltimoPasoTs = 0;
  window.addEventListener('devicemotion', onDeviceMotionPasos);
  pedometroFlushIntervalId = setInterval(flushPedometroPasos, PEDOMETRO_FLUSH_MS);
  pedometroActivo = true;
  showToast('Detección automática de pasos activada.');
  actualizarUIPedometro();

  setTimeout(() => {
    if (pedometroActivo && pedometroFiltroBase === null) {
      showToast('No detectamos datos de movimiento en tu dispositivo. Usá la carga manual como respaldo.');
    }
  }, 5000);
}

function desactivarPedometro() {
  window.removeEventListener('devicemotion', onDeviceMotionPasos);
  if (pedometroFlushIntervalId) { clearInterval(pedometroFlushIntervalId); pedometroFlushIntervalId = null; }
  pedometroActivo = false;
  flushPedometroPasos();
  actualizarUIPedometro();
}

function onDeviceMotionPasos(event) {
  const acc = event.accelerationIncludingGravity || event.acceleration;
  if (!acc || acc.x === null || acc.x === undefined) return;

  const magnitud = Math.sqrt((acc.x || 0) ** 2 + (acc.y || 0) ** 2 + (acc.z || 0) ** 2);
  pedometroFiltroBase = pedometroFiltroBase === null ? magnitud : pedometroFiltroBase * 0.9 + magnitud * 0.1;

  const desvio = Math.abs(magnitud - pedometroFiltroBase);
  const ahora = Date.now();
  if (desvio > PEDOMETRO_UMBRAL && (ahora - pedometroUltimoPasoTs) > PEDOMETRO_MIN_INTERVALO_MS) {
    pedometroUltimoPasoTs = ahora;
    pedometroPendientes += 1;
  }
}

/** El resumen "Progreso de hoy" (Home) y el panel "Detalle de pasos" del
 *  mismo Home muestran el mismo número por separado — cuando el panel de
 *  detalle se actualiza solo (sensor / carga manual, sin pasar por un
 *  renderProgreso() completo) hay que reflejarlo acá también para que no
 *  queden desincronizados. No cambia ninguna lógica de pasos existente. */
function actualizarResumenPasosHome(registro) {
  const valorEl = document.getElementById('resumen-pasos-valor');
  const barraEl = document.getElementById('resumen-pasos-barra');
  if (!valorEl || !barraEl) return;
  const meta = registro.meta_pasos || 10000;
  const pct = meta > 0 ? Math.min(100, (registro.pasos / meta) * 100) : 0;
  valorEl.innerHTML = `${registro.pasos.toLocaleString('es-AR')} <span class="today-tile-goal">/ ${meta.toLocaleString('es-AR')}</span>`;
  barraEl.style.width = `${pct}%`;
}

async function flushPedometroPasos() {
  if (pedometroPendientes <= 0) return;
  const cantidad = pedometroPendientes;
  pedometroPendientes = 0;

  const res = await Api.pasosSumar(cantidad);
  if (!res.success) { pedometroPendientes += cantidad; return; }

  const cardPasos = document.getElementById('card-pasos');
  if (cardPasos) cardPasos.innerHTML = renderPasosHTML({ success: true, data: res.data.registro });
  actualizarResumenPasosHome(res.data.registro);
  actualizarActividadHeroSiVisible();
  if (res.data.xp) await handleXPResult(res.data.xp);
}

function actualizarUIPedometro() {
  const cardPasos = document.getElementById('card-pasos');
  if (!cardPasos) return;
  Api.pasosGet().then((res) => {
    cardPasos.innerHTML = renderPasosHTML(res);
    if (res.success) actualizarResumenPasosHome(res.data);
  });
}

window.addEventListener('pagehide', () => {
  if (pedometroPendientes > 0 && navigator.sendBeacon) {
    const blob = new Blob([JSON.stringify({ cantidad: pedometroPendientes })], { type: 'application/json' });
    navigator.sendBeacon(`${API_BASE}/entrenamiento/pasos.php`, blob);
  }
});

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
  actualizarResumenPasosHome(res.data.registro);
  actualizarActividadHeroSiVisible();
  await handleXPResult(res.data.xp);
}

async function sumarPasosManual() {
  const valor = parseInt(document.getElementById('input-pasos-manual').value, 10);
  if (!valor || valor <= 0) { showToast('Ingresá una cantidad de pasos válida.'); return; }
  await sumarPasos(valor);
}

// =====================================================================
// Actividad física (demo web) — caminata/carrera/ciclismo manuales,
// mientras no existe la app RN/Expo con Health Connect/HealthKit. Usa el
// mismo motor general (Api.actividad*) que va a usar la sincronización
// real del celular más adelante; acá solo cambia que la fuente es
// 'manual' en vez de 'health_connect'/'healthkit'.
// =====================================================================
let tiposActividadCache = null;
let tipoActividadSeleccionada = null;
/** Unidad de ENTRADA de la duración ('minutos' | 'horas'). El backend
 *  sigue recibiendo siempre segundos. */
let unidadDuracionActividad = 'minutos';

/** 3 botones (Caminar/Correr/Bicicleta) en vez del <select> anterior —
 *  misma fuente de datos (Api.actividadTipos, catálogo real en
 *  `tipos_actividad`), solo cambia la presentación. Los campos que se
 *  muestran siguen dependiendo 100% de usa_distancia/usa_pasos de cada
 *  tipo: bicicleta (usa_pasos=0) nunca pide ni genera pasos, sin casos
 *  especiales acá. */
async function renderFormActividadDemo() {
  const cont = document.getElementById('form-actividad-demo');
  if (!cont) return;

  if (!tiposActividadCache) {
    const res = await Api.actividadTipos();
    tiposActividadCache = res.success ? res.data : [];
  }
  if (tiposActividadCache.length === 0) {
    cont.innerHTML = `<p class="empty-state-sm">No se pudo cargar el módulo de actividad.</p>`;
    return;
  }
  if (!tipoActividadSeleccionada || !tiposActividadCache.some((t) => t.clave === tipoActividadSeleccionada)) {
    tipoActividadSeleccionada = tiposActividadCache[0].clave;
  }

  cont.innerHTML = `
    <div class="segmented" style="margin-bottom:14px;">
      ${tiposActividadCache.map((t) => `
        <button type="button" class="segmented-btn${t.clave === tipoActividadSeleccionada ? ' active' : ''}" onclick="seleccionarTipoActividad('${t.clave}')">
          ${Icon(ICONO_TIPO_ACTIVIDAD[t.clave] || 'activity', { size: 15 })} ${t.nombre}
        </button>
      `).join('')}
    </div>
    <div id="campos-actividad-demo"></div>
    <div style="margin-top:10px;">
      <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="enviarActividadDemo()">Registrar</button>
    </div>
    <div id="resultado-actividad-demo"></div>
  `;
  actualizarCamposActividadDemo();
}

function seleccionarTipoActividad(clave) {
  tipoActividadSeleccionada = clave;
  renderFormActividadDemo();
}

function actualizarCamposActividadDemo() {
  const tipo = tiposActividadCache?.find((t) => t.clave === tipoActividadSeleccionada);
  const cont = document.getElementById('campos-actividad-demo');
  if (!tipo || !cont) return;

  // La duración se carga en la unidad que le resulte cómoda al usuario
  // (una salida en bici en "1,5 horas" es más natural que "90"), pero el
  // backend sigue recibiendo SIEMPRE segundos: la unidad es solo de entrada.
  let campos = `
    <div class="field">
      <label>Duración</label>
      <div class="input-con-unidad">
        <input type="text" inputmode="decimal" id="input-actividad-duracion"
               placeholder="${unidadDuracionActividad === 'horas' ? 'Ej: 1,5' : 'Ej: 30'}"
               oninput="actualizarEquivalenciaDuracion()" />
        <div class="segmented segmented-sm" role="group" aria-label="Unidad de duración">
          <button type="button" class="segmented-btn${unidadDuracionActividad === 'minutos' ? ' active' : ''}" onclick="cambiarUnidadDuracion('minutos')">Minutos</button>
          <button type="button" class="segmented-btn${unidadDuracionActividad === 'horas' ? ' active' : ''}" onclick="cambiarUnidadDuracion('horas')">Horas</button>
        </div>
      </div>
      <p class="hint-duracion" id="hint-actividad-duracion"></p>
    </div>
  `;
  if (Number(tipo.usa_distancia) === 1) {
    campos += `
      <div class="field">
        <label>Distancia (km)${Number(tipo.usa_pasos) === 1 ? ' — opcional si cargás pasos' : ''}</label>
        <input type="number" step="0.01" min="0" id="input-actividad-distancia" placeholder="Ej: 5" />
      </div>
    `;
  }
  if (Number(tipo.usa_pasos) === 1) {
    campos += `
      <div class="field">
        <label>Pasos (opcional)</label>
        <input type="number" id="input-actividad-pasos" min="1" placeholder="Ej: 4000" />
      </div>
    `;
  }
  cont.innerHTML = campos;
}

function formatearRitmo(segPorKm) {
  const min = Math.floor(segPorKm / 60);
  const seg = Math.round(segPorKm % 60);
  return `${min}:${String(seg).padStart(2, '0')} min/km`;
}

// --- Duración: entrada flexible, unidad canónica única ---------------
// El contrato con el backend no cambia (duracion_segundos). Acá solo se
// interpreta lo que escribe la persona: "1,5" y "1.5" son lo mismo, y la
// unidad elegida se convierte antes de enviar.

/** Minutos por encima de los cuales se avisa (una sola sesión de más de
 *  6 horas casi siempre es un error de tipeo, p. ej. minutos cargados
 *  como horas). No bloquea: solo pide confirmación. */
const DURACION_MINUTOS_SOSPECHOSA = 360;

/** Convierte el texto del input a un número, aceptando coma decimal. */
function parsearNumeroFlexible(texto) {
  const limpio = String(texto ?? '').trim().replace(',', '.');
  if (limpio === '') return NaN;
  // Se rechaza cualquier cosa que no sea un decimal simple ("1.5.2", "abc").
  if (!/^\d*\.?\d+$/.test(limpio)) return NaN;
  return parseFloat(limpio);
}

/** Devuelve los minutos totales, o null si la entrada no es válida. */
function duracionEnMinutos(texto, unidad) {
  const valor = parsearNumeroFlexible(texto);
  if (!Number.isFinite(valor) || valor <= 0) return null;
  const minutos = unidad === 'horas' ? valor * 60 : valor;
  return Math.round(minutos);
}

function cambiarUnidadDuracion(unidad) {
  if (unidad === unidadDuracionActividad) return;

  // Cambiar la unidad no debe borrar lo que la persona ya cargó.
  const previos = ['duracion', 'distancia', 'pasos'].reduce((acc, campo) => {
    acc[campo] = document.getElementById(`input-actividad-${campo}`)?.value ?? '';
    return acc;
  }, {});

  unidadDuracionActividad = unidad;
  actualizarCamposActividadDemo();

  Object.entries(previos).forEach(([campo, valor]) => {
    const el = document.getElementById(`input-actividad-${campo}`);
    if (el && valor !== '') el.value = valor;
  });
  actualizarEquivalenciaDuracion();
}

/** Muestra en vivo a cuánto equivale lo escrito, para que no haya dudas
 *  sobre qué se va a registrar (1,5 horas → 90 min). */
function actualizarEquivalenciaDuracion() {
  const input = document.getElementById('input-actividad-duracion');
  const hint = document.getElementById('hint-actividad-duracion');
  if (!input || !hint) return;

  const texto = input.value.trim();
  if (texto === '') { hint.textContent = ''; hint.className = 'hint-duracion'; return; }

  const minutos = duracionEnMinutos(texto, unidadDuracionActividad);
  if (minutos === null) {
    hint.textContent = 'Ingresá un número mayor a 0 (podés usar coma: 1,5).';
    hint.className = 'hint-duracion hint-duracion--error';
    return;
  }

  hint.className = 'hint-duracion';
  if (unidadDuracionActividad === 'horas') {
    hint.textContent = `Se registrarán ${minutos} minutos.`;
  } else if (minutos >= 60) {
    const h = Math.floor(minutos / 60);
    const m = minutos % 60;
    hint.textContent = `Equivale a ${h} h${m ? ` ${m} min` : ''}.`;
  } else {
    hint.textContent = '';
  }
}

async function enviarActividadDemo() {
  const clave = tipoActividadSeleccionada;
  const duracionMin = duracionEnMinutos(
    document.getElementById('input-actividad-duracion')?.value,
    unidadDuracionActividad
  );
  if (duracionMin === null) {
    showToast('Ingresá una duración válida (mayor a 0). Podés usar coma: 1,5.');
    return;
  }
  if (duracionMin > DURACION_MINUTOS_SOSPECHOSA) {
    const horas = (duracionMin / 60).toFixed(1).replace('.', ',');
    const ok = confirm(`Estás por registrar ${duracionMin} minutos (${horas} horas) en una sola sesión. ¿Es correcto?`);
    if (!ok) return;
  }

  const distanciaKm = parsearNumeroFlexible(document.getElementById('input-actividad-distancia')?.value);
  const pasos = parseInt(document.getElementById('input-actividad-pasos')?.value, 10);

  const payload = { tipo: clave, duracion_segundos: duracionMin * 60, fuente: 'manual' };
  if (!Number.isNaN(distanciaKm) && distanciaKm > 0) payload.distancia_metros = Math.round(distanciaKm * 1000);
  if (!Number.isNaN(pasos) && pasos > 0) payload.pasos = pasos;

  const res = await Api.actividadRegistrar(payload);
  const resultadoDiv = document.getElementById('resultado-actividad-demo');
  if (!res.success) {
    resultadoDiv.innerHTML = `<p class="empty-state-sm">${res.message || 'No se pudo registrar la actividad.'}</p>`;
    return;
  }

  const r = res.data.registro;
  resultadoDiv.innerHTML = `
    <div class="macro-preview" style="margin:14px 0 0;">
      <div class="macro-preview-item"><span class="valor mono" style="color:var(--color-nutricion);">${r.calorias_kcal}</span><span class="label">kcal</span></div>
      ${r.distancia_metros ? `<div class="macro-preview-item"><span class="valor mono">${(r.distancia_metros / 1000).toFixed(2)}</span><span class="label">km</span></div>` : ''}
      ${r.velocidad_media_kmh ? `<div class="macro-preview-item"><span class="valor mono">${r.velocidad_media_kmh}</span><span class="label">km/h media</span></div>` : ''}
      ${r.ritmo_seg_por_km ? `<div class="macro-preview-item"><span class="valor mono">${formatearRitmo(r.ritmo_seg_por_km)}</span><span class="label">Ritmo</span></div>` : ''}
    </div>
  `;
  showToast('Actividad registrada.');

  if (res.data.xp?.sesion || res.data.xp?.meta_pasos) {
    if (res.data.xp.sesion) await handleXPResult(res.data.xp.sesion);
    if (res.data.xp.meta_pasos) await handleXPResult(res.data.xp.meta_pasos, { skipRobotReaction: true });
  } else if (res.data.xp) {
    await handleXPResult(res.data.xp);
  }

  if (pasos > 0) {
    const resPasos = await Api.pasosGet();
    if (resPasos.success) actualizarResumenPasosHome(resPasos.data);
  }

  actualizarActividadHeroSiVisible();
  const histCont = document.getElementById('card-historial-actividad');
  if (histCont) {
    const histRes = await Api.actividadHistorial(historialActividadLimite);
    histCont.innerHTML = renderHistorialActividadHTML(histRes, historialActividadLimite);
  }
}

// =====================================================================
// Avatar — Robot 3D (Three.js, ver frontend/js/robot-avatar.js). Vive
// únicamente en Mi Perfil (robotAvatarPerfil) y en el modal "Personalizar
// Avatar" (robotAvatarModal) — Home ya no tiene su propia instancia.
// Geometrías y materiales de carrocería se cachean dentro del módulo del
// robot y se comparten entre instancias; acá sólo administramos el ciclo
// de vida (montar/pausar/destruir) para no acumular renderers ni loops de
// requestAnimationFrame de más.
// =====================================================================
let robotAvatarModal = null;

function robotAvatarSoportado() {
  if (typeof window === 'undefined' || !window.NutriFitRobotAvatar) return false;
  try {
    const c = document.createElement('canvas');
    return !!(window.WebGLRenderingContext && (c.getContext('webgl') || c.getContext('experimental-webgl')));
  } catch (e) {
    return false;
  }
}

/** Mapea el NOMBRE de un ítem de tienda tipo "ropa_avatar" a una de las
 *  tints definidas en robot.js (SKINS). El GLB de Meshy es una única
 *  malla con un único material (ver informe de Personalización 2.0) —
 *  esto NO cambia una prenda de verdad, tiñe TODO el cuerpo del avatar.
 *  Por eso en "Apariencia" sólo se muestran los ítems reales que tienen
 *  esta tint detrás (hoy: Remera NutriFit=classic, Camiseta Neón=neon);
 *  dark/blue/gold quedan definidas en robot.js para el día que exista un
 *  ítem de tienda real para ellas, pero no se ofrecen todavía. */
function skinKeyDeNombre(nombre) {
  const n = (nombre || '').toLowerCase();
  if (n.includes('negra') || n.includes('dark')) return 'dark';
  if (n.includes('azul') || n.includes('blue')) return 'blue';
  if (n.includes('dorad') || n.includes('gold')) return 'gold';
  if (n.includes('neón') || n.includes('neon') || n.includes('verde')) return 'neon';
  return 'classic';
}
function skinRobotDesdeEquipados(equipados) {
  return skinKeyDeNombre(equipados.ropa_avatar || '');
}
function hexDeSkin(skinKey) {
  const tint = window.NutriFitRobotAvatar?.SKINS?.[skinKey]?.tint ?? 0xffffff;
  return `#${tint.toString(16).padStart(6, '0')}`;
}

/** Robot grande de Mi Perfil — reusa el mismo sistema (window.
 *  NutriFitRobotAvatar), montado en su propio contenedor. */
let robotAvatarPerfil = null;
function mountPerfilRobot(equipados) {
  destroyPerfilRobot();
  const el = document.getElementById('robot-perfil-stage');
  if (!el) return;
  if (!robotAvatarSoportado()) { el.innerHTML = `<div class="robot-fallback">${Icon('bot', { size: 64 })}</div>`; return; }
  robotAvatarPerfil = window.NutriFitRobotAvatar.create(el, { skin: skinRobotDesdeEquipados(equipados) });
}
function destroyPerfilRobot() {
  if (robotAvatarPerfil) { robotAvatarPerfil.destroy(); robotAvatarPerfil = null; }
}

/** Vuelve a pedir la sesión y aplica la skin equipada a las instancias
 *  del robot que estén montadas (dashboard, perfil y/o modal), sin
 *  reconstruir la escena 3D. */
async function refrescarAvatarPrincipal() {
  const sesion = await Api.sesion();
  if (!sesion.success) return;
  const skin = skinRobotDesdeEquipados(sesion.data.equipados || {});
  robotAvatarPerfil?.setSkin(skin);
  robotAvatarModal?.setSkin(skin);
}

// Si el módulo del robot (Three.js vía CDN) todavía no había terminado de
// cargar cuando se intentó montar por primera vez, se reintenta al vuelo.
window.addEventListener('nutrifit-robot-ready', () => {
  if (document.getElementById('robot-perfil-stage') && !robotAvatarPerfil) {
    Api.sesion().then((sesion) => { if (sesion.success) mountPerfilRobot(sesion.data.equipados || {}); });
  }
  if (document.getElementById('robot-modal-stage') && !robotAvatarModal) {
    cargarPersonalizarAvatar();
  }
});

// =====================================================================
// Modal — Personalizar Avatar 2.0 (tabs: Apariencia / Ropa / Accesorios / Emotes)
// =====================================================================
// Estructura pensada para una futura "economía" de emotes (desbloqueo por
// nivel/coins) sin tener que rearmar nada: cada emote ya trae locked/price/
// levelRequired/owned/rarity, hoy siempre en su valor "todo desbloqueado".
// Las 10 animaciones acá listadas son las mismas que ya existían
// (playAnimation semántico de robot.js) — esto es sólo una forma amigable
// de dispararlas a mano, no se agregó ninguna animación nueva.
const EMOTES = [
  { key: 'greeting', label: 'Saludo', icon: 'hand' },
  { key: 'success', label: 'Victoria', icon: 'trophy' },
  { key: 'celebration', label: 'Celebración', icon: 'party-popper' },
  { key: 'levelup', label: 'Baile', icon: 'music' },
  { key: 'running', label: 'Correr', icon: 'footprints', loop: true },
  { key: 'walking', label: 'Caminar', icon: 'footprints', loop: true },
  { key: 'exercise', variant: 'jumprope', label: 'Saltar soga', icon: 'zap' },
  { key: 'exercise', variant: 'situps', label: 'Abdominales', icon: 'activity' },
  { key: 'exercise', label: 'Flexiones', icon: 'dumbbell' },
  { key: 'gesture', label: 'Gesto "No"', icon: 'ban' },
].map((e) => ({ ...e, locked: false, owned: true, price: 0, levelRequired: 1, rarity: 'common' }));

function emoteChipId(e) { return `emote-chip-${e.key}${e.variant ? `-${e.variant}` : ''}`; }

function renderEmoteGrid() {
  return EMOTES.map((e) => {
    const precio = e.price > 0 ? `${NutriCoinIcon(11)}${e.price}` : (e.levelRequired > 1 ? `Nivel ${e.levelRequired}` : '');
    return `
    <button type="button" class="emote-chip${e.locked ? ' locked' : ''}" id="${emoteChipId(e)}"
      onclick="${e.locked ? '' : `probarEmote('${e.key}', ${e.variant ? `'${e.variant}'` : 'null'}, ${!!e.loop})`}" ${e.locked ? 'disabled' : ''}>
      <span class="emote-chip-icon">${Icon(e.locked ? 'lock' : e.icon, { size: 17 })}</span><span>${e.label}</span>
      ${e.locked && precio ? `<span class="emote-chip-meta">${precio}</span>` : ''}
    </button>
  `;
  }).join('');
}

// ---------------------------------------------------------------------
// Apariencia: tinte de color de TODO el avatar (ver skinKeyDeNombre) —
// sólo se listan acá los ítems de tienda tipo "ropa_avatar" que existan
// de verdad, con estado real (equipped/owned/available), nunca opciones
// inventadas.
// ---------------------------------------------------------------------
function renderAparienciaPanel(items, nivelUsuario) {
  if (!items.length) {
    return `<p class="empty-state">Todavía no hay opciones de apariencia cargadas en la tienda.</p>`;
  }
  return `
    <div class="apariencia-grid">
      ${items.map((item) => {
        const hex = hexDeSkin(skinKeyDeNombre(item.nombre));
        const bloqueadaPorNivel = !item.poseido && Number(item.nivel_requerido) > Number(nivelUsuario);
        const estado = bloqueadaPorNivel ? 'locked' : (!item.poseido ? 'available' : (item.equipado ? 'equipped' : 'owned'));
        const gratis = Number(item.costo_coins) === 0 && Number(item.costo_xp) === 0;
        let accion;
        if (estado === 'locked') {
          accion = `<button type="button" class="btn btn-ghost" disabled>${Icon('lock', { size: 13 })} Nivel ${item.nivel_requerido}</button>`;
        } else if (estado === 'equipped') {
          accion = `<button type="button" class="btn btn-primary" disabled>${Icon('check', { size: 14 })} Equipada</button>`;
        } else if (estado === 'owned') {
          accion = `<button type="button" class="btn btn-ghost" onclick="equiparPrendaModal(${item.id})">Equipar</button>`;
        } else {
          accion = `<button type="button" class="btn btn-primary" onclick="canjearPrendaModal(${item.id})">${gratis ? 'Desbloquear' : 'Canjear'}</button>`;
        }
        const precioHTML = gratis ? 'Gratis' : (item.costo_coins > 0 ? `${NutriCoinIcon(12)}${item.costo_coins}` : `${item.costo_xp} XP`);
        return `
          <div class="apariencia-card ${estado}">
            <div class="apariencia-swatch" style="background:${hex};"></div>
            <div class="apariencia-name">${item.nombre}</div>
            ${estado === 'available' ? `<div class="apariencia-price row-label" style="justify-content:center;">${precioHTML}</div>` : ''}
            ${item.poseido && estado !== 'equipped' ? '<div class="badge-desbloqueado">Desbloqueada</div>' : ''}
            ${accion}
          </div>
        `;
      }).join('')}
    </div>
  `;
}

// ---------------------------------------------------------------------
// Ropa / Accesorios: el GLB actual es UNA sola malla con UN solo material
// (verificado: no hay piezas de remera/pantalón/zapatillas separadas), así
// que todavía no se puede equipar ropa/accesorios de verdad pieza por
// pieza. En vez de simular algo que no funciona, se deja el estado vacío
// explicando qué falta — la arquitectura (tabs, tienda con nivel_requerido
// e imagen_asset ya listos en el backend) ya está preparada para cuando
// existan modelos con piezas separadas.
// ---------------------------------------------------------------------
function renderAvatarPanelVacio({ icon, titulo, texto }) {
  return `
    <div class="avatar-panel-empty">
      ${Icon(icon, { size: 30 })}
      <p class="avatar-panel-empty-title">${titulo}</p>
      <p class="empty-state">${texto}</p>
    </div>
  `;
}
function renderRopaPanel() {
  return renderAvatarPanelVacio({
    icon: 'shirt',
    titulo: 'Todavía no hay prendas independientes',
    texto: 'El avatar es un único modelo 3D con una sola textura para todo el cuerpo: remera, pantalón y zapatillas están fusionados en una sola malla. Para poder cambiar cada prenda por separado hace falta exportar el modelo con esas piezas separadas — la tienda ya está preparada (nivel requerido, thumbnail) para cuando eso exista.',
  });
}
function renderAccesoriosPanel() {
  return renderAvatarPanelVacio({
    icon: 'glasses',
    titulo: 'Todavía no hay accesorios',
    texto: 'Cuando se sumen accesorios reales a la tienda (lentes, gorras, mochilas) y el modelo tenga puntos de anclaje preparados, van a aparecer acá.',
  });
}

let emotePreviewTimeout = null;

/** Reproduce un emote elegido a mano en el modal de personalización. Las
 *  animaciones one-shot se apagan solas (evento "finished" de robot.js);
 *  las de loop (correr/caminar) se cortan acá mismo tras una vista previa
 *  razonable, para que no queden dando vueltas para siempre dentro del
 *  modal (punto 16 de la 2ª pasada). */
function probarEmote(key, variant, loop) {
  if (!robotAvatarModal) return;
  if (emotePreviewTimeout) { clearTimeout(emotePreviewTimeout); emotePreviewTimeout = null; }
  document.querySelectorAll('#avatar-panel-emotes .emote-chip').forEach((el) => el.classList.remove('playing'));

  const chip = document.getElementById(emoteChipId({ key, variant }));
  chip?.classList.add('playing');
  robotAvatarModal.playAnimation(key, { variant: variant || undefined, loop });

  const previewMs = loop ? 2600 : 3200;
  emotePreviewTimeout = setTimeout(() => {
    if (loop) robotAvatarModal?.returnToIdle();
    chip?.classList.remove('playing');
    emotePreviewTimeout = null;
  }, previewMs);
}

function cambiarAvatarTab(tab) {
  document.querySelectorAll('.avatar-tab').forEach((el) => el.classList.toggle('active', el.dataset.tab === tab));
  document.querySelectorAll('.avatar-tab-panel').forEach((el) => el.classList.toggle('active', el.dataset.panel === tab));
}

const AVATAR_TABS = [
  { key: 'apariencia', label: 'Apariencia', icon: 'palette' },
  { key: 'ropa', label: 'Ropa', icon: 'shirt' },
  { key: 'accesorios', label: 'Accesorios', icon: 'glasses' },
  { key: 'emotes', label: 'Emotes', icon: 'sparkles' },
];

function abrirModalPersonalizarAvatar(tabInicial = 'apariencia') {
  cerrarModalPersonalizarAvatar();
  robotAvatarPerfil?.setPaused(true);

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-personalizar-avatar';
  overlay.innerHTML = `
    <div class="modal-card avatar-editor-card">
      <div class="avatar-editor-header">
        <div class="row-label"><span class="modal-icon avatar-editor-icon">${Icon('sparkles', { size: 18 })}</span>
          <div><h3>Personalizar Avatar</h3><p class="modal-subtitle">Elegí cómo se ve y se mueve tu avatar</p></div>
        </div>
        <button type="button" class="avatar-editor-close" onclick="cerrarModalPersonalizarAvatar()" aria-label="Cerrar">${Icon('x', { size: 18 })}</button>
      </div>

      <div class="avatar-editor-body">
        <div class="avatar-editor-stage-wrap">
          <div class="robot-stage robot-stage--modal" id="robot-modal-stage"></div>
          <p class="avatar-hint"><b>Tocá al robot</b> para verlo animar</p>
        </div>

        <div class="avatar-editor-panel">
          <div class="avatar-tabs" role="tablist">
            ${AVATAR_TABS.map((t) => `
              <button type="button" class="avatar-tab${t.key === tabInicial ? ' active' : ''} row-label" role="tab" data-tab="${t.key}" onclick="cambiarAvatarTab('${t.key}')">
                ${Icon(t.icon, { size: 16 })}<span>${t.label}</span>
              </button>
            `).join('')}
          </div>

          <div class="avatar-tab-content">
            <div class="avatar-tab-panel${tabInicial === 'apariencia' ? ' active' : ''}" data-panel="apariencia" id="avatar-panel-apariencia">
              <p class="avatar-panel-loading">Cargando…</p>
            </div>
            <div class="avatar-tab-panel${tabInicial === 'ropa' ? ' active' : ''}" data-panel="ropa">${renderRopaPanel()}</div>
            <div class="avatar-tab-panel${tabInicial === 'accesorios' ? ' active' : ''}" data-panel="accesorios">${renderAccesoriosPanel()}</div>
            <div class="avatar-tab-panel${tabInicial === 'emotes' ? ' active' : ''}" data-panel="emotes" id="avatar-panel-emotes">
              <div class="emote-grid">${renderEmoteGrid()}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrarModalPersonalizarAvatar(); });
  document.body.appendChild(overlay);
  cargarPersonalizarAvatar();
}

function cerrarModalPersonalizarAvatar() {
  if (emotePreviewTimeout) { clearTimeout(emotePreviewTimeout); emotePreviewTimeout = null; }
  robotAvatarModal?.destroy();
  robotAvatarModal = null;
  document.getElementById('modal-personalizar-avatar')?.remove();
  robotAvatarPerfil?.setPaused(false);
}

async function cargarPersonalizarAvatar() {
  const [tiendaRes, sesionRes] = await Promise.all([Api.tiendaGet(), Api.sesion()]);
  if (!document.getElementById('modal-personalizar-avatar')) return; // se cerró mientras cargaba
  if (!tiendaRes.success || !sesionRes.success) return;

  const stageEl = document.getElementById('robot-modal-stage');
  if (stageEl && !robotAvatarModal) {
    if (robotAvatarSoportado()) {
      robotAvatarModal = window.NutriFitRobotAvatar.create(stageEl, { skin: skinRobotDesdeEquipados(sesionRes.data.equipados || {}) });
    } else {
      stageEl.innerHTML = `<div class="robot-fallback">${Icon('bot', { size: 48 })}</div>`;
    }
  }

  const remeras = tiendaRes.data.filter((item) => item.tipo === 'ropa_avatar');
  const panel = document.getElementById('avatar-panel-apariencia');
  if (panel) panel.innerHTML = renderAparienciaPanel(remeras, sesionRes.data.nivel);
}

async function canjearPrendaModal(itemId) {
  const res = await Api.tiendaCanjear(itemId);
  showToast(res.message);
  if (res.success) await cargarPersonalizarAvatar();
}

async function equiparPrendaModal(itemId) {
  const res = await Api.tiendaEquipar(itemId);
  showToast(res.message);
  if (res.success) {
    await cargarPersonalizarAvatar();
    await refrescarAvatarPrincipal();
  }
}

// ---------- Iconos dinámicos por tipo de ejercicio ----------
// Familia SVG consistente (ver frontend/js/icons.js): no hay un ícono
// Lucide específico para cada ejercicio, así que se agrupan por tipo de
// esfuerzo (fuerza/cardio/explosivo/core/movilidad) en vez de usar un
// Marcado visual (client-side) de ejercicios "hechos" dentro de la rutina del día.
// No otorga XP por sí solo — es feedback inmediato mientras se entrena; el XP
// se otorga al confirmar "Marcar como Entrenado".
let ejerciciosMarcados = new Set();

/** Lista limpia (no cards individuales por ejercicio) — cada fila es un
 *  <div> con checkbox+nombre+series×reps, separadas por un divisor sutil.
 *  Sigue visible directo (sin <details>/acordeón); tocar una fila marca/
 *  desmarca el ejercicio, igual que antes. */
// Inicializar modal global una sola vez
document.addEventListener('DOMContentLoaded', () => {
  if (!document.getElementById('modal-detalle-ejercicio')) {
    const modal = document.createElement('div');
    modal.id = 'modal-detalle-ejercicio';
    modal.style.cssText = 'display: none; position: fixed; bottom: 0; left: 0; right: 0; background: white; border-radius: 16px 16px 0 0; box-shadow: 0 -4px 12px rgba(0,0,0,0.15); z-index: 1000; max-height: 80vh; overflow-y: auto; padding-bottom: 20px;';

    modal.innerHTML = `
      <div style="position: sticky; top: 0; background: white; padding: 16px; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; border-radius: 16px 16px 0 0;">
        <h3 id="modal-ejercicio-titulo" style="margin: 0; font-size: 18px;"></h3>
        <button type="button" style="background: none; border: none; cursor: pointer; padding: 0; display: flex; align-items: center; justify-content: center; width: 32px; height: 32px; font-size: 20px;" onclick="cerrarDetalleEjercicio()">
          ✕
        </button>
      </div>
      <div id="modal-ejercicio-contenido" style="padding: 16px;"></div>
    `;

    document.body.appendChild(modal);
  }

  // Cerrar modal si se presiona Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') cerrarDetalleEjercicio();
  });
});

/** Traduce claves internas de enfoque a etiquetas humanas. */
function etiquetaEnfoque(enfoque) {
  const map = {
    'fuerza_y_gasto': 'Fuerza y gasto calórico',
    'fuerza_hipertrofia': 'Fuerza e hipertrofia',
    'mixto_equilibrado': 'Mixto equilibrado',
    'accesible_progresivo': 'Accesible y progresivo',
    'suave_movilidad': 'Suave y movilidad',
  };
  return map[enfoque] || enfoque;
}

/** Traduce claves internas de lugar a etiquetas humanas. */
function etiquetaLugar(lugar) {
  const map = {
    'casa': 'Casa',
    'gimnasio': 'Gimnasio',
    'parque': 'Parque',
    'exterior': 'Exterior',
  };
  return map[lugar] || lugar;
}

/** Traduce claves internas de nivel a etiquetas humanas. */
function etiquetaNivel(nivel) {
  const map = {
    'principiante': 'Principiante',
    'intermedio': 'Intermedio',
    'avanzado': 'Avanzado',
  };
  return map[nivel] || nivel;
}

function renderRutinaHTML(rutinaData) {
  if (!rutinaData.success) {
    const mensaje = rutinaData.message || 'Completa el onboarding para recibir una rutina personalizada.';
    return `<p class="empty-state">${mensaje}</p>`;
  }
  const r = rutinaData.data;
  const total = r.ejercicios.length;
  const hechos = r.completada_hoy ? total : r.ejercicios.filter((e) => ejerciciosMarcados.has(e.id)).length;
  const pct = total > 0 ? Math.round((hechos / total) * 100) : 0;

  // Mostrar información de personalización
  const personalizedInfo = r.personalizada ? `
    <div style="background: var(--bg-secondary); border-radius: 8px; padding: 12px; margin-bottom: 14px; font-size: 13px;">
      <div style="color: var(--text-secondary); margin-bottom: 6px;">Rutina personalizada para vos</div>
      <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 12px;">
        ${r.enfoque ? `<div><span style="color: var(--text-secondary);">Enfoque:</span> ${etiquetaEnfoque(r.enfoque)}</div>` : ''}
        ${r.nivel_sugerido ? `<div><span style="color: var(--text-secondary);">Nivel:</span> ${etiquetaNivel(r.nivel_sugerido)}</div>` : ''}
      </div>
    </div>
  ` : '';

  const ejerciciosHTML = r.ejercicios.map((e) => {
    const marcado = r.completada_hoy || ejerciciosMarcados.has(e.id);
    return `
      <div class="exercise-row${marcado ? ' done' : ''}" style="cursor: pointer; position: relative;">
        <div onclick="${r.completada_hoy ? '' : `toggleEjercicioMarcado(${e.id}, ${r.id})`}" style="display: flex; align-items: center; flex: 1; gap: 12px;">
          <span class="exercise-row-check">${Icon(marcado ? 'circle-check' : 'circle', { size: 18 })}</span>
          <span class="exercise-row-name">${e.nombre}</span>
          <span class="exercise-row-meta mono">${e.series} × ${e.repeticiones}</span>
        </div>
        <button type="button" style="background: none; border: none; color: var(--text-secondary); cursor: pointer; padding: 4px; display: flex; align-items: center; justify-content: center; font-size: 14px;"
          onclick="abrirDetalleEjercicio('${e.catalogo_clave || e.nombre}', ${e.catalogo_id || 'null'}); event.stopPropagation();"
          title="Ver cómo hacerlo">
          ⓘ
        </button>
      </div>
    `;
  }).join('');

  return `
    <div class="rutina-header-row">
      <h4>${r.titulo} [TEST v1]</h4>
      <span class="mono routine-progress-label">${hechos} de ${total} ejercicios</span>
    </div>
    ${personalizedInfo}
    <div class="routine-progress-track"><div class="routine-progress-fill" style="width:${pct}%;"></div></div>
    <div class="exercise-list">${ejerciciosHTML}</div>
    <div class="exercise-list-divider"></div>
    <button class="btn btn-primary btn-entrenar-listo${!r.completada_hoy && pct === 100 ? ' ready' : ''}" ${r.completada_hoy ? 'disabled style="opacity:.5;"' : ''}
      onclick="marcarEntrenamiento(${r.id})">
      ${r.completada_hoy ? `${Icon('circle-check', { size: 17 })} Ya entrenaste hoy` : `${Icon('trophy', { size: 17 })} Marcar como Entrenado (+25 XP)`}
    </button>
  `;
}

/** Escopeado a la vista activa (`.view.active`), no a `document`: desde que
 *  Actividad también reutiliza renderRutinaHTML() (misma rutina, misma
 *  data), puede haber DOS copias de `.exercise-row`/`.routine-progress-*`
 *  en el DOM a la vez (la vigente + la que quedó de la última vez que se
 *  visitó la otra vista) — un querySelector sin escopear siempre pega en
 *  la primera del DOM (Home), aunque el usuario esté tocando la de
 *  Actividad. Escopear a la vista activa resuelve la ambigüedad sin tener
 *  que duplicar la rutina en dos sistemas separados. */
function toggleEjercicioMarcado(ejercicioId, rutinaId) {
  if (ejerciciosMarcados.has(ejercicioId)) ejerciciosMarcados.delete(ejercicioId);
  else ejerciciosMarcados.add(ejercicioId);

  const vista = document.querySelector('.view.active');
  if (!vista) return;

  const fila = vista.querySelector(`.exercise-row[onclick*="toggleEjercicioMarcado(${ejercicioId},"]`);
  if (fila) {
    const marcado = ejerciciosMarcados.has(ejercicioId);
    fila.classList.toggle('done', marcado);
    const check = fila.querySelector('.exercise-row-check');
    if (check) check.innerHTML = Icon(marcado ? 'circle-check' : 'circle', { size: 18 });
  }

  const total = vista.querySelectorAll('.exercise-list .exercise-row').length;
  const hechos = vista.querySelectorAll('.exercise-list .exercise-row.done').length;
  const pct = total > 0 ? Math.round((hechos / total) * 100) : 0;
  const fill = vista.querySelector('.routine-progress-fill');
  const label = vista.querySelector('.routine-progress-label');
  if (fill) fill.style.width = `${pct}%`;
  if (label) label.textContent = `${hechos} de ${total} ejercicios`;
  vista.querySelector('.btn-entrenar-listo')?.classList.toggle('ready', pct === 100);
}

async function marcarEntrenamiento(rutinaId) {
  const res = await Api.completarEntrenamiento(rutinaId);
  if (!res.success) { showToast(res.message); return; }
  ejerciciosMarcados = new Set();
  const subioDeNivel = !!res.data.xp?.subio_de_nivel;
  await handleXPResult(res.data.xp, { skipRobotReaction: true });

  // Re-renderiza la vista que el usuario tiene abierta en este momento
  // (Home o Actividad, ambas muestran la misma rutina real) — no siempre
  // es Home desde que Actividad también permite completar el entrenamiento.
  const vistaActivaId = document.querySelector('.view.active')?.id;
  if (vistaActivaId === 'view-actividad') await renderActividad();
  else await renderProgreso();
  // Ni Home ni Actividad tienen robot propio (solo Mi Perfil/el modal) —
  // reaccionarRobotAvatar no hace nada salvo que el modal esté abierto
  // (guard interno), sin error.
  reaccionarRobotAvatar(subioDeNivel ? 'levelup' : 'celebration');
}

async function abrirDetalleEjercicio(clave, ejercicioId) {
  const modal = document.getElementById('modal-detalle-ejercicio');
  const titulo = document.getElementById('modal-ejercicio-titulo');
  const contenido = document.getElementById('modal-ejercicio-contenido');

  if (!modal) return;

  titulo.textContent = 'Cargando...';
  contenido.innerHTML = '<p style="color: var(--text-secondary);">Cargando detalles del ejercicio...</p>';
  modal.style.display = 'block';

  try {
    const res = await fetch(`/nutrifittwo/backend/api/entrenamiento/ejercicio.php?clave=${encodeURIComponent(clave)}`, {
      credentials: 'include',
    });
    const data = await res.json();

    if (!data.success) {
      titulo.textContent = 'Error';
      contenido.innerHTML = '<p style="color: var(--text-secondary);">No se pudo cargar el ejercicio.</p>';
      return;
    }

    const e = data.data;
    const recursoHTML = e.recurso ?
      `<div style="background: var(--bg-secondary); padding: 12px; border-radius: 8px; margin-bottom: 14px; text-align: center; color: var(--text-secondary); font-size: 12px;">
        Recurso: ${e.recurso.tipo.toUpperCase()}
      </div>` : '';

    titulo.textContent = e.nombre;

    const instruccionesArray = Array.isArray(e.instrucciones) ? e.instrucciones : (e.instrucciones ? e.instrucciones.split('\n').filter(l => l.trim()) : []);
    const instruccionesHTML = instruccionesArray.length > 0 ? `
      <div style="margin-bottom: 20px;">
        <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600;">Cómo hacerlo</h4>
        <ol style="margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.6; color: var(--text-primary);">
          ${instruccionesArray.map(l => `<li style="margin-bottom: 6px;">${l.trim()}</li>`).join('')}
        </ol>
      </div>
    ` : '';

    const erroresArray = Array.isArray(e.errores_comunes) ? e.errores_comunes : (e.errores_comunes ? e.errores_comunes.split('\n').filter(l => l.trim()) : []);
    const erroresHTML = erroresArray.length > 0 ? `
      <div style="margin-bottom: 20px;">
        <h4 style="margin: 0 0 10px; font-size: 14px; font-weight: 600;">Errores comunes</h4>
        <ul style="margin: 0; padding-left: 20px; font-size: 13px; line-height: 1.6; color: var(--text-primary);">
          ${erroresArray.map(l => `<li style="margin-bottom: 6px;">${l.trim()}</li>`).join('')}
        </ul>
      </div>
    ` : '';

    const infoHTML = `
      <div style="background: var(--bg-secondary); border-radius: 8px; padding: 12px; margin-bottom: 14px; font-size: 12px;">
        ${e.grupo_muscular ? `<div><span style="color: var(--text-secondary);">Grupo muscular:</span> ${e.grupo_muscular}</div>` : ''}
        ${e.nivel ? `<div><span style="color: var(--text-secondary);">Nivel:</span> ${etiquetaNivel(e.nivel)}</div>` : ''}
        ${e.equipamiento ? `<div><span style="color: var(--text-secondary);">Equipamiento:</span> ${e.equipamiento}</div>` : ''}
      </div>
    `;

    const sugerenciaHTML = `
      <div style="background: var(--bg-secondary); border-radius: 8px; padding: 12px; margin-bottom: 14px;">
        <h4 style="margin: 0 0 8px; font-size: 13px; font-weight: 600;">Sugerencia</h4>
        <div style="font-size: 13px; line-height: 1.5; color: var(--text-primary);">
          <div><strong>${e.sugerencia?.series || '-'}</strong> series</div>
          <div><strong>${e.sugerencia?.repeticiones || '-'}</strong> repeticiones</div>
          <div>Descanso: <strong>${e.sugerencia?.descanso_segundos ? e.sugerencia.descanso_segundos + ' seg' : '-'}</strong></div>
        </div>
      </div>
    `;

    contenido.innerHTML = `
      ${infoHTML}
      ${sugerenciaHTML}
      ${recursoHTML}
      ${instruccionesHTML}
      ${erroresHTML}
    `;
  } catch (err) {
    titulo.textContent = 'Error';
    contenido.innerHTML = '<p style="color: var(--text-secondary);">Error al cargar el ejercicio.</p>';
  }
}

function cerrarDetalleEjercicio() {
  const modal = document.getElementById('modal-detalle-ejercicio');
  if (modal) modal.style.display = 'none';
}

async function submitPesoHoy() {
  const valor = document.getElementById('input-peso-hoy').value;
  if (!valor || parseFloat(valor) <= 0) { showToast('Ingresa un peso válido.'); return; }
  const res = await Api.progresoPesoPost(parseFloat(valor));
  // El formulario vive en Mi Progreso ahora; Home vuelve a pedir el peso
  // solo/cuando se visite de nuevo, no hace falta re-renderizarlo acá.
  if (res.success) { showToast('Peso actualizado.'); await renderMiProgreso(); }
  else showToast(res.message);
}

// =====================================================================
// PESTAÑA 2 — Calorías & Nutrición Diaria
// =====================================================================
// 1 vaso = 250 ml (constante de conversión fija — ver informe de
// Nutrición 2.0). La DB sigue guardando "vasos" enteros; ml es una
// unidad derivada para mostrar, nunca se inventa un valor: cada vaso
// registrado en el backend equivale exactamente a 250ml, sin excepción.
const ML_POR_VASO = 250;

function renderCaloriasCard(d) {
  const consumidas = Math.round(d.totales_consumidos.calorias);
  const meta = d.metas.meta_calorias;
  const pct = meta > 0 ? Math.min(100, (consumidas / meta) * 100) : 0;
  const radius = 70;
  const circumference = 2 * Math.PI * radius;
  const offset = circumference - (pct / 100) * circumference;
  const diff = consumidas - meta;

  let estadoHTML;
  if (diff > 0) {
    estadoHTML = `<span class="calorie-status over">+${diff} kcal sobre la meta</span>`;
  } else if (diff === 0) {
    estadoHTML = `<span class="calorie-status ok">${Icon('circle-check', { size: 13 })} Meta alcanzada</span>`;
  } else {
    estadoHTML = `<span class="calorie-status">Faltan ${Math.abs(diff)} kcal</span>`;
  }

  const macro = (label, cls, valor, objetivo) => {
    const consumido = Math.round(valor);
    const p = objetivo > 0 ? Math.min(100, (consumido / objetivo) * 100) : 0;
    const completo = objetivo > 0 && consumido >= objetivo;
    return `
      <div class="macro-row">
        <div class="macro-label">
          <span>${label}</span>
          <span class="row-label">
            ${completo ? `<span class="macro-completo">${Icon('circle-check', { size: 12 })}</span>` : ''}
            <span class="mono">${consumido} / ${objetivo} g</span>
          </span>
        </div>
        <div class="macro-track"><div class="macro-fill ${cls}" style="width:${p}%;"></div></div>
      </div>
    `;
  };

  return `
    <div class="card nutri-card-calorias">
      <h3 class="row-label" style="font-size:15px;margin-bottom:4px;">${Icon('flame', { size: 16 })}Calorías</h3>
      <div class="calorie-ring-wrap">
        <svg class="calorie-ring" width="180" height="180" viewBox="0 0 180 180">
          <circle class="track" cx="90" cy="90" r="${radius}"></circle>
          <circle class="fill" cx="90" cy="90" r="${radius}"
            stroke-dasharray="${circumference}" stroke-dashoffset="${offset}"></circle>
        </svg>
        <div class="calorie-ring-center">
          <div class="value mono">${consumidas}</div>
          <div class="label">kcal consumidas</div>
        </div>
      </div>
      <div class="calorie-meta-row">
        <span class="calorie-meta-label">Meta: ${meta} kcal</span>
        ${estadoHTML}
      </div>

      ${macro('Proteínas', 'proteina', d.totales_consumidos.proteinas, d.metas.meta_proteinas)}
      ${macro('Carbohidratos', 'carbo', d.totales_consumidos.carbohidratos, d.metas.meta_carbohidratos)}
      ${macro('Grasas', 'grasa', d.totales_consumidos.grasas, d.metas.meta_grasas)}

      <div class="nutri-recomendacion">
        <p class="row-label">${Icon('sparkles', { size: 14 })}${d.recomendacion_ia}</p>
      </div>
    </div>
  `;
}

function renderHidratacionCard(hidratacionData) {
  const vasos = hidratacionData.success ? hidratacionData.data.vasos : 0;
  const metaVasos = hidratacionData.success ? hidratacionData.data.meta_vasos : 8;
  const ml = vasos * ML_POR_VASO;
  const metaMl = metaVasos * ML_POR_VASO;
  const pct = metaMl > 0 ? Math.min(100, (ml / metaMl) * 100) : 0;
  const glasses = Array.from({ length: metaVasos }, (_, i) =>
    `<div class="water-glass ${i < vasos ? 'filled' : ''}"></div>`
  ).join('');

  return `
    <div class="card nutri-card-agua">
      <h3 class="row-label" style="font-size:15px;margin-bottom:12px;">${Icon('droplets', { size: 16 })}Hidratación</h3>
      <div class="agua-valor mono">${ml.toLocaleString('es-AR')} <span class="agua-valor-meta">/ ${metaMl.toLocaleString('es-AR')} ml</span></div>
      <p class="agua-detalle">${vasos} vaso${vasos === 1 ? '' : 's'} · 250 ml c/u</p>
      <div class="macro-track"><div class="macro-fill" id="agua-barra" style="width:${pct}%;background:var(--color-hidratacion);"></div></div>
      <p class="agua-status${ml >= metaMl ? ' ok' : ''}" id="agua-status">${ml >= metaMl ? `${Icon('circle-check', { size: 13 })} Meta alcanzada` : `Faltan ${metaMl - ml} ml`}</p>

      <div class="water-glass-row" id="agua-glasses">${glasses}</div>

      <div class="agua-actions">
        <button class="btn btn-primary row-label" style="justify-content:center;" onclick="sumarVasoAgua()">${Icon('plus', { size: 16 })}Agregar vaso (250 ml)</button>
        <button type="button" class="agua-quitar-btn" onclick="quitarVasoAgua()" ${vasos <= 0 ? 'disabled' : ''} aria-label="Quitar el último vaso de agua">${Icon('minus', { size: 13 })}Quitar último</button>
      </div>

      <h3 class="row-label" style="font-size:15px;margin:20px 0 10px;">${Icon('utensils', { size: 16 })}Agregar comida rápido</h3>
      <div class="search-input-wrap">
        <span class="search-input-icon">${Icon('search', { size: 16 })}</span>
        <input type="text" id="input-buscar-alimento" placeholder="Buscar alimento..." oninput="buscarAlimentosDebounced(this.value)" />
      </div>
      <div id="resultados-alimentos"></div>
    </div>
  `;
}

const TIPOS_COMIDA_LABELS = { desayuno: 'Desayuno', almuerzo: 'Almuerzo', merienda: 'Merienda', cena: 'Cena', snack: 'Snacks' };

function renderDiarioComidas(comidasPorTipo) {
  const html = Object.entries(TIPOS_COMIDA_LABELS).map(([key, label]) => {
    const items = comidasPorTipo[key] || [];
    const subtotal = items.reduce((s, i) => s + parseFloat(i.calorias_totales), 0);
    const itemsHTML = items.length
      ? items.map((i) => `
          <div class="meal-item">
            <div class="meal-item-info">
              <span class="meal-item-name">${i.alimento}</span>
              <span class="meal-item-gramos mono">${i.gramos} g</span>
            </div>
            <div class="meal-item-right">
              <span class="cal mono">${Math.round(i.calorias_totales)} kcal</span>
              <button type="button" class="meal-item-delete" onclick="eliminarRegistroComida(${i.id})" aria-label="Eliminar ${i.alimento} del registro">${Icon('trash-2', { size: 15 })}</button>
            </div>
          </div>
        `).join('')
      : `<div class="empty-state empty-state-sm">Sin registros aún</div>`;
    return `
      <div class="meal-section">
        <div class="meal-section-title">
          <h4>${label}</h4>
          ${items.length ? `<span class="meal-subtotal mono">${Math.round(subtotal)} kcal</span>` : ''}
        </div>
        ${itemsHTML}
      </div>
    `;
  }).join('');
  return html;
}

function renderRecomendacionesSeccion(recomendaciones) {
  if (!recomendaciones || (!recomendaciones.para_mi?.length && !recomendaciones.top?.length)) {
    return '';
  }

  const paraMi = recomendaciones.para_mi || [];
  const topItems = recomendaciones.top || [];

  // Mostrar máximo 3 de "Para mí", y si no hay, mostrar de "Top"
  const itemsAMostrar = paraMi.length > 0 ? paraMi.slice(0, 3) : topItems.slice(0, 3);
  const esDeMi = paraMi.length > 0;
  const titulo = esDeMi ? 'Recomendado para vos' : 'Opciones populares';

  const itemsHTML = itemsAMostrar.map(item => {
    const alimentoId = item.alimento?.id || 0;
    const nombre = item.alimento?.nombre || 'Alimento';
    const nombreEscapado = nombre.replace(/'/g, "\\'");
    const gramos = item.porcion?.gramos || 100;
    const etiqueta = item.porcion?.etiqueta || `${gramos} g`;
    const kcal = Math.round(item.nutricion?.calorias || 0);
    const proteina = Math.round((item.nutricion?.proteinas || 0) * 10) / 10;
    const carbohidratos = item.nutricion?.carbohidratos || 0;
    const grasas = item.nutricion?.grasas || 0;
    const motivo = item.motivo?.texto ? `<div style="font-size:12px; color:var(--text-secondary); margin-top:6px;">${item.motivo.texto}</div>` : '';

    // abrirModalRegistroComida recalcula proporcionalmente asumiendo que
    // las macros recibidas son "por 100g" — normalizamos según la porción
    // real que devolvió el backend (no siempre es exactamente 100g).
    const factor100 = gramos > 0 ? 100 / gramos : 1;
    const cal100 = (item.nutricion?.calorias || 0) * factor100;
    const prot100 = (item.nutricion?.proteinas || 0) * factor100;
    const carb100 = carbohidratos * factor100;
    const gras100 = grasas * factor100;

    return `
      <div class="recommendation-item" style="background:var(--bg-secondary); border-radius:8px; padding:12px; margin-bottom:8px;">
        <div style="display:flex; justify-content:space-between; align-items:start; gap:8px;">
          <div style="flex:1;">
            <div style="font-weight:600; font-size:14px;">${nombre}</div>
            <div style="font-size:12px; color:var(--text-secondary);">${etiqueta}</div>
            <div style="font-size:13px; margin-top:4px;"><strong>${kcal}</strong> kcal · <strong>${proteina}g</strong> proteína</div>
            ${motivo}
          </div>
          <div style="display:flex; gap:6px; flex-direction:column;">
            <button type="button" class="btn btn-primary" style="padding:6px 10px; font-size:12px; white-space:nowrap;"
              onclick="agregarDesdeRecomendacion(${alimentoId}, '${nombreEscapado}', ${cal100}, ${prot100}, ${carb100}, ${gras100}, ${gramos})">
              Agregar
            </button>
            <button type="button" style="background:none; border:1px solid var(--border-color); border-radius:6px; padding:4px 8px; cursor:pointer; font-size:14px; color:var(--color-warning);"
              onclick="abrirModalGuardarFavorito(${alimentoId}, '${nombreEscapado}', ${gramos}, 'g', ${kcal}, ${proteina}, ${carbohidratos}, ${grasas})">
              ♡
            </button>
          </div>
        </div>
      </div>
    `;
  }).join('');

  return `
    <div class="card" style="margin-top:18px;">
      <h3 class="row-label" style="font-size:15px;margin-bottom:12px;">${Icon('sparkles', { size: 16 })}${titulo}</h3>
      ${itemsHTML}
    </div>
  `;
}

function renderFavoritosSeccion(favoritos) {
  if (!favoritos || favoritos.length === 0) {
    return '';
  }

  const itemsHTML = favoritos.slice(0, 5).map(fav => {
    const kcal = Math.round(fav.calorias_base || 0);
    const proteina = Math.round((fav.proteinas_base || 0) * 10) / 10;
    const nombreEscapado = fav.nombre_personalizado.replace(/'/g, "\\'");
    // Un favorito sin alimento_id (ej. de un escaneo IA sin match nutricional)
    // no puede registrarse: registrar_comida.php exige un alimento_id real.
    const puedeAgregarse = !!fav.alimento_id;

    return `
      <div class="favorite-item" style="background:var(--bg-secondary); border-radius:8px; padding:12px; margin-bottom:8px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
        <div style="flex:1;">
          <div style="font-weight:600; font-size:14px;"><span style="color:var(--color-warning);">♡</span> ${fav.nombre_personalizado}</div>
          <div style="font-size:12px; color:var(--text-secondary);">${fav.gramos_base} ${fav.unidad}</div>
          <div style="font-size:13px; margin-top:4px;"><strong>${kcal}</strong> kcal · <strong>${proteina}g</strong> proteína</div>
        </div>
        <div style="display:flex; gap:6px; align-items:center;">
          ${puedeAgregarse ? `
          <button type="button" class="btn btn-primary" style="padding:6px 12px; font-size:12px; white-space:nowrap;"
            onclick="agregarFavoritoAlDia(${fav.id}, ${fav.alimento_id}, '${nombreEscapado}', ${fav.calorias_base}, ${fav.proteinas_base}, ${fav.carbohidratos_base}, ${fav.grasas_base}, ${fav.gramos_base})">
            Agregar
          </button>` : `<span style="font-size:11px; color:var(--text-secondary);">Sin match</span>`}
          <button type="button" style="background:none; border:none; cursor:pointer; font-size:15px; color:var(--text-secondary); padding:4px;"
            onclick="eliminarFavorito(${fav.id})" title="Quitar de favoritos">✕</button>
        </div>
      </div>
    `;
  }).join('');

  return `
    <div class="card" style="margin-top:12px;">
      <h3 class="row-label" style="font-size:15px;margin-bottom:12px;">${Icon('heart', { size: 16 })}Tus favoritos</h3>
      ${itemsHTML}
    </div>
  `;
}

async function renderNutricion() {
  const container = document.getElementById('view-nutricion');
  const hoy = new Date().toISOString().slice(0, 10);
  const res = await Api.dashboardDiario(hoy);
  const hidratacion = await Api.hidratacionGet();

  let recomendaciones = { success: false, data: null };
  let favoritos = { success: false, data: { favoritos: [] } };

  try {
    recomendaciones = await Api.recomendacionesAlimentos();
  } catch (e) {
    console.log('Error cargando recomendaciones:', e.message);
  }

  try {
    favoritos = await Api.favoritosGet();
  } catch (e) {
    console.log('Error cargando favoritos:', e.message);
  }

  if (!res.success) {
    container.innerHTML = `<p class="empty-state">${res.message}</p>`;
    return;
  }

  const d = res.data;
  const recomendacionesHTML = (recomendaciones && recomendaciones.success) ? renderRecomendacionesSeccion(recomendaciones.data) : '';
  const favoritosHTML = (favoritos && favoritos.success) ? renderFavoritosSeccion(favoritos.data.favoritos) : '';

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Hoy</span><h1>Nutrición Diaria</h1></div>
    </div>

    <div class="grid-2">
      ${renderCaloriasCard(d)}
      ${renderHidratacionCard(hidratacion)}
    </div>

    ${recomendacionesHTML}
    ${favoritosHTML}

    <div class="card" style="margin-top:12px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h3 class="row-label" style="font-size:15px;margin:0;">${Icon('book-open', { size: 16 })}Recetas para vos</h3>
      </div>
      <p style="margin:8px 0 0; font-size:13px; color:var(--text-secondary);">Recetas personalizadas según tu dieta y restricciones</p>
      <button type="button" class="btn btn-primary" style="margin-top:12px; width:100%;" onclick="abrirGeneradorRecetas()">
        Generar receta con IA
      </button>
    </div>

    <div class="card" style="margin-top:12px;">
      <h3 class="row-label" style="font-size:15px;margin-bottom:16px;">${Icon('utensils', { size: 16 })}Diario de comidas</h3>
      ${renderDiarioComidas(d.comidas_por_tipo)}
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

  container.innerHTML = res.data.map((a) => {
    const nombreEscapado = a.nombre_mostrado.replace(/'/g, "\\'");
    return `
    <div class="meal-item" style="display:flex; align-items:center; gap:8px;">
      <div style="flex:1; cursor:pointer;" onclick="abrirModalRegistroComida(${a.id}, '${nombreEscapado}', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
        <span>${a.nombre_mostrado}</span>
        <span class="cal">${a.calorias_por_100g} kcal/100g</span>
      </div>
      <button type="button" style="background:none; border:none; cursor:pointer; font-size:15px; color:var(--color-warning); padding:4px;"
        onclick="event.stopPropagation(); abrirModalGuardarFavorito(${a.id}, '${nombreEscapado}', 100, 'g', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})" title="Guardar como favorito">♡</button>
    </div>
  `;
  }).join('');
}

const TIPOS_COMIDA = [
  { valor: 'desayuno', label: 'Desayuno' },
  { valor: 'almuerzo', label: 'Almuerzo' },
  { valor: 'merienda', label: 'Merienda' },
  { valor: 'cena', label: 'Cena' },
  { valor: 'snack', label: 'Snack' },
];

let macrosPor100gModal = null;

function abrirModalRegistroComida(alimentoId, nombre, caloriasPor100g, proteinas, carbohidratos, grasas, gramosSugeridos) {
  cerrarModalRegistroComida();

  macrosPor100gModal = {
    calorias: parseFloat(caloriasPor100g) || 0,
    proteinas: parseFloat(proteinas) || 0,
    carbohidratos: parseFloat(carbohidratos) || 0,
    grasas: parseFloat(grasas) || 0,
  };
  const gramosIniciales = Math.max(1, Math.round(parseFloat(gramosSugeridos))) || 150;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-registro-comida';
  overlay.innerHTML = `
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-icon">${Icon('utensils', { size: 22 })}</div>
        <div>
          <h3>${nombre}</h3>
          <p class="modal-subtitle">${macrosPor100gModal.calorias} kcal por cada 100g</p>
        </div>
      </div>

      <div class="field">
        <label for="modal-input-gramos">Gramos</label>
        <input type="number" id="modal-input-gramos" value="${gramosIniciales}" min="1" step="1" inputmode="numeric" oninput="actualizarPreviewMacrosModal()" />
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

/** Deshacer el último vaso de agua (punto 1 del brief de Nutrición 2.0).
 *  No revierte el XP ya otorgado — mismo criterio que eliminar un
 *  registro de comida, que tampoco lo hace. */
async function quitarVasoAgua() {
  const res = await Api.hidratacionQuitar();
  if (!res.success) { showToast(res.message); return; }
  await renderNutricion();
}

function agregarDesdeRecomendacion(alimentoId, nombre, calorias, proteinas, carbohidratos, grasas, gramosBase) {
  abrirModalRegistroComida(alimentoId, nombre, calorias, proteinas, carbohidratos, grasas, gramosBase);
}

async function agregarFavoritoAlDia(favoritoId, alimentoId, nombre, caloriasBase, proteinasBase, carbohidratosBase, grasasBase, gramosBase) {
  // Registrar el uso (contador + fecha) sin bloquear la apertura del modal.
  Api.favoritosMarcarUso(favoritoId).catch(() => {});

  // abrirModalRegistroComida recalcula proporcionalmente asumiendo macros
  // "por 100g" — normalizamos desde los valores guardados en gramosBase.
  const factor100 = gramosBase > 0 ? 100 / gramosBase : 1;
  abrirModalRegistroComida(
    alimentoId,
    nombre,
    caloriasBase * factor100,
    proteinasBase * factor100,
    carbohidratosBase * factor100,
    grasasBase * factor100,
    gramosBase
  );
}

async function eliminarFavorito(favoritoId) {
  const res = await Api.favoritosEliminar(favoritoId);
  if (!res.success) { showToast(res.message); return; }
  showToast('Favorito eliminado.');
  await renderNutricion();
}

function abrirModalGuardarFavorito(alimentoId, nombre, gramos, unidad, calorias, proteinas, carbohidratos, grasas, esCompuesto = false, metadata = null) {
  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-guardar-favorito';
  overlay.innerHTML = `
    <div class="modal-card" style="max-width:400px;">
      <div class="modal-header">
        <div style="margin-bottom:8px;"><h3>Guardar como favorito</h3></div>
      </div>

      <div class="field">
        <label for="favorito-nombre">Nombre personalizado</label>
        <input type="text" id="favorito-nombre" value="${nombre.replace(/"/g, '&quot;')}" placeholder="Mi licuado..." maxlength="120" />
      </div>

      <div style="background:var(--bg-secondary); border-radius:8px; padding:12px; margin-bottom:16px; font-size:12px;">
        <div><strong>${gramos} ${unidad}</strong> · ${Math.round(calorias)} kcal</div>
        <div>Proteína: ${Math.round(proteinas * 10) / 10}g · Carbos: ${Math.round(carbohidratos * 10) / 10}g · Grasas: ${Math.round(grasas * 10) / 10}g</div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" onclick="cerrarModalGuardarFavorito()">Cancelar</button>
        <button type="button" class="btn btn-primary" onclick="confirmarGuardarFavorito(${alimentoId || 'null'}, '${nombre.replace(/'/g, "\\'")}', ${gramos}, '${unidad}', ${calorias}, ${proteinas}, ${carbohidratos}, ${grasas}, ${esCompuesto ? 1 : 0})">Guardar</button>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrarModalGuardarFavorito(); });
  document.body.appendChild(overlay);
  document.getElementById('favorito-nombre').focus();
}

function cerrarModalGuardarFavorito() {
  const modal = document.getElementById('modal-guardar-favorito');
  if (modal) modal.remove();
}

async function confirmarGuardarFavorito(alimentoId, nombreOriginal, gramos, unidad, calorias, proteinas, carbohidratos, grasas, esCompuesto) {
  const nombrePersonalizado = document.getElementById('favorito-nombre').value.trim();
  if (!nombrePersonalizado) { showToast('Ingresá un nombre.'); return; }

  cerrarModalGuardarFavorito();

  const res = await Api.favoritosCrear({
    nombre_personalizado: nombrePersonalizado,
    alimento_id: alimentoId || null,
    gramos_base: parseFloat(gramos),
    unidad: unidad,
    calorias_base: parseFloat(calorias),
    proteinas_base: parseFloat(proteinas),
    carbohidratos_base: parseFloat(carbohidratos),
    grasas_base: parseFloat(grasas),
    es_compuesto: !!esCompuesto,
    origen: 'manual',
  });

  if (!res.success) { showToast(res.message); return; }
  showToast('Favorito guardado.');
  await renderNutricion();
}

let recetaActualModal = null;

async function abrirGeneradorRecetas(regenerar = false) {
  if (!document.getElementById('modal-generar-receta')) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.id = 'modal-generar-receta';
    overlay.innerHTML = `
      <div class="modal-card" style="max-width:600px;">
        <div class="modal-header">
          <div style="margin-bottom:8px;"><h3>Generar receta personalizada</h3></div>
        </div>
        <div id="receta-contenido" style="padding:20px; text-align:center;">
          <div style="margin-bottom:10px;">Cargando receta...</div>
          <div class="spinner" style="display:inline-block;"></div>
        </div>
        <div class="modal-actions">
          <button type="button" class="btn btn-ghost" onclick="cerrarModalReceta()">Cerrar</button>
        </div>
      </div>
    `;
    overlay.addEventListener('click', (e) => { if (e.target === overlay) cerrarModalReceta(); });
    document.body.appendChild(overlay);
  }

  const contenido = document.getElementById('receta-contenido');
  contenido.innerHTML = `<div style="margin-bottom:10px;">Cargando receta...</div><div class="spinner" style="display:inline-block;"></div>`;

  const res = await Api.receta(null, regenerar);

  if (!res.success) {
    recetaActualModal = null;
    contenido.innerHTML = `<p style="color:var(--text-secondary);">No se pudo generar la receta: ${res.message}</p>`;
    return;
  }

  const receta = res.data;
  recetaActualModal = receta;
  const porPorcion = receta.nutricion_por_porcion || {};
  const ingredientesConId = (receta.ingredientes || []).filter(ing => ing.alimento_id);

  contenido.innerHTML = `
    <div style="text-align:left;">
      <h4 style="font-size:16px; margin-bottom:8px;">${receta.nombre}</h4>
      <p style="font-size:13px; color:var(--text-secondary); margin-bottom:12px;">${receta.descripcion}</p>

      <div style="background:var(--bg-secondary); border-radius:8px; padding:12px; margin-bottom:16px;">
        <div style="font-size:12px;"><strong>${receta.porciones}</strong> porciones</div>
        <div style="font-size:12px;">Tiempo: <strong>${receta.tiempo_minutos}</strong> min</div>
        <div style="font-size:13px; margin-top:8px;"><strong>${Math.round(porPorcion.calorias || 0)}</strong> kcal/porción</div>
        <div style="font-size:12px;">Proteína: ${Math.round((porPorcion.proteinas || 0) * 10) / 10}g · Carbos: ${Math.round((porPorcion.carbohidratos || 0) * 10) / 10}g · Grasas: ${Math.round((porPorcion.grasas || 0) * 10) / 10}g</div>
      </div>

      <div style="margin-bottom:16px;">
        <h5 style="font-size:13px; font-weight:600; margin-bottom:8px;">Ingredientes:</h5>
        <ul style="margin:0; padding-left:20px; font-size:13px; line-height:1.6;">
          ${(receta.ingredientes || []).map(ing => `<li>${ing.nombre} — ${Math.round(ing.gramos)}g</li>`).join('')}
        </ul>
      </div>

      <div style="margin-bottom:16px;">
        <h5 style="font-size:13px; font-weight:600; margin-bottom:8px;">Pasos:</h5>
        <ol style="margin:0; padding-left:20px; font-size:13px; line-height:1.6;">
          ${(receta.pasos || []).map(paso => `<li style="margin-bottom:6px;">${paso}</li>`).join('')}
        </ol>
      </div>

      <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-ghost" style="flex:1;" onclick="abrirGeneradorRecetas(true)">Generar otra</button>
        <button type="button" class="btn btn-primary" style="flex:1;" ${ingredientesConId.length === 0 ? 'disabled' : ''} onclick="agregarRecetaAlDia()">Agregar al día</button>
      </div>
    </div>
  `;
}

async function agregarRecetaAlDia() {
  if (!recetaActualModal) return;
  const ingredientes = (recetaActualModal.ingredientes || []).filter(ing => ing.alimento_id);
  if (ingredientes.length === 0) { showToast('Esta receta no tiene ingredientes registrables.'); return; }

  const momentosValidos = ['desayuno', 'almuerzo', 'merienda', 'cena'];
  const tipo = momentosValidos.includes(recetaActualModal.momento) ? recetaActualModal.momento : 'snack';

  let ultimoXP = null;
  for (const ing of ingredientes) {
    const res = await Api.registrarComida({ alimento_id: ing.alimento_id, tipo_comida: tipo, gramos: ing.gramos, origen: 'manual' });
    if (res.success) ultimoXP = res.data.xp;
  }

  if (ultimoXP) await handleXPResult(ultimoXP);
  cerrarModalReceta();
  await renderNutricion();
  showToast('Receta agregada a tu día.');
}

function cerrarModalReceta() {
  const modal = document.getElementById('modal-generar-receta');
  if (modal) modal.remove();
  recetaActualModal = null;
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
        <span style="font-size:13px;color:var(--text-secondary);">Activá la cámara o subí una foto de tu plato</span>
      </div>
      <!-- DOS inputs distintos a propósito: en iOS, un input con
           capture="environment" abre SIEMPRE la cámara, así que no puede
           ser el mismo que usa "Subir foto" (por eso antes "Subir foto"
           terminaba abriendo la cámara en iPhone). El de galería NO lleva
           capture. -->
      <input type="file" id="input-foto-camara" accept="image/*" capture="environment" class="hidden" onchange="manejarFotoSeleccionada(this)" />
      <input type="file" id="input-foto-galeria" accept="image/*" class="hidden" onchange="manejarFotoSeleccionada(this)" />
      <div class="escaner-acciones">
        <button class="btn btn-primary row-label" onclick="document.getElementById('input-foto-camara').click()">${Icon('camera', { size: 16 })}Tomar foto</button>
        <button class="btn btn-ghost row-label" onclick="document.getElementById('input-foto-galeria').click()">${Icon('image', { size: 16 })}Subir foto</button>
        <button class="btn btn-ghost row-label" onclick="startCamera()">${Icon('scan', { size: 16 })}Cámara en vivo</button>
      </div>
      <div id="escaner-camara-vivo" class="hidden" style="margin-top:10px;">
        <button class="btn btn-primary row-label" style="width:100%;justify-content:center;" onclick="analizarPlatoIA()">${Icon('sparkles', { size: 16 })}Capturar y analizar</button>
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

/** Muestra u oculta el botón "Capturar y analizar" (solo tiene sentido
 *  mientras la cámara en vivo está encendida). */
function toggleBotonCaptura(visible) {
  const box = document.getElementById('escaner-camara-vivo');
  if (box) box.classList.toggle('hidden', !visible);
}

async function startCamera() {
  const frame = document.getElementById('scanner-frame');
  frame.classList.remove('has-shot');
  try {
    cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    frame.innerHTML = `<video autoplay playsinline></video><div class="scan-line"></div>`;
    frame.querySelector('video').srcObject = cameraStream;
    toggleBotonCaptura(true);
  } catch (err) {
    toggleBotonCaptura(false);
    showToast('No se pudo acceder a la cámara. Usá "Subir foto" o el buscador manual.');
  }
}

function stopCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach((t) => t.stop());
    cameraStream = null;
  }
  toggleBotonCaptura(false);
}

function mostrarFotoCapturada(dataUrl) {
  stopCamera();
  const frame = document.getElementById('scanner-frame');
  frame.classList.add('has-shot');
  frame.innerHTML = `<img src="${dataUrl}" alt="Foto capturada del plato" />`;
}

// =====================================================================
// Análisis de imagen con IA — MobileNet (TensorFlow.js) corriendo en el
// propio navegador, sin costo ni API key. Reemplaza la simulación anterior
// (que elegía un alimento al azar del catálogo): ahora se analiza el
// contenido real de la foto y el resultado se cruza contra el buscador de
// alimentos real (catálogo local + Open Food Facts) para traer información
// nutricional verdadera, no inventada.
// =====================================================================
let modeloVisionPromise = null;

function cargarModeloVision() {
  if (modeloVisionPromise) return modeloVisionPromise;

  const cargarScript = (src) => new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.onload = resolve;
    script.onerror = () => reject(new Error(`No se pudo cargar ${src}`));
    document.head.appendChild(script);
  });

  modeloVisionPromise = (async () => {
    if (!window.tf) await cargarScript('https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.20.0/dist/tf.min.js');
    if (!window.mobilenet) await cargarScript('https://cdn.jsdelivr.net/npm/@tensorflow-models/mobilenet@2.1.1/dist/mobilenet.min.js');
    return window.mobilenet.load({ version: 2, alpha: 1.0 });
  })();

  modeloVisionPromise.catch(() => { modeloVisionPromise = null; });
  return modeloVisionPromise;
}

// Traduce las clases de ImageNet relevantes a un término de búsqueda en
// español para cruzarlas contra el catálogo real de alimentos.
const DICCIONARIO_ALIMENTOS_IA = [
  { claves: ['banana'], termino: 'banana' },
  { claves: ['orange'], termino: 'naranja' },
  { claves: ['lemon'], termino: 'limón' },
  { claves: ['fig'], termino: 'higo' },
  { claves: ['pineapple', 'ananas'], termino: 'ananá' },
  { claves: ['pomegranate'], termino: 'granada' },
  { claves: ['granny smith'], termino: 'manzana' },
  { claves: ['strawberry'], termino: 'frutilla' },
  { claves: ['cucumber', 'cuke'], termino: 'pepino' },
  { claves: ['artichoke'], termino: 'alcaucil' },
  { claves: ['bell pepper'], termino: 'morrón' },
  { claves: ['mushroom'], termino: 'hongo' },
  { claves: ['head cabbage'], termino: 'repollo' },
  { claves: ['broccoli'], termino: 'brócoli' },
  { claves: ['cauliflower'], termino: 'coliflor' },
  { claves: ['zucchini', 'courgette'], termino: 'zapallito' },
  { claves: ['squash'], termino: 'zapallo' },
  { claves: ['corn'], termino: 'maíz' },
  { claves: ['french loaf'], termino: 'pan' },
  { claves: ['bagel'], termino: 'bagel' },
  { claves: ['pretzel'], termino: 'pretzel' },
  { claves: ['cheeseburger'], termino: 'hamburguesa' },
  { claves: ['hotdog', 'hot dog'], termino: 'pancho' },
  { claves: ['mashed potato'], termino: 'puré de papa' },
  { claves: ['guacamole'], termino: 'palta' },
  { claves: ['ice cream', 'icecream'], termino: 'helado' },
  { claves: ['ice lolly', 'lollipop', 'popsicle'], termino: 'helado' },
  { claves: ['carbonara'], termino: 'pasta' },
  { claves: ['meat loaf', 'meatloaf'], termino: 'carne' },
  { claves: ['pizza'], termino: 'pizza' },
  { claves: ['potpie'], termino: 'tarta' },
  { claves: ['burrito'], termino: 'burrito' },
  { claves: ['espresso'], termino: 'café' },
  { claves: ['trifle'], termino: 'postre' },
];

function capturarFrameDeVideo() {
  const video = document.querySelector('#scanner-frame video');
  if (!video || !video.videoWidth) return null;
  const canvas = document.createElement('canvas');
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0);
  return canvas;
}

async function analizarPlatoIA() {
  const canvas = capturarFrameDeVideo();
  if (!canvas) { showToast('Activá la cámara y encuadrá el plato, o usá "Subir foto".'); return; }

  mostrarFotoCapturada(canvas.toDataURL('image/jpeg', 0.85));
  await analizarCanvasConIA(canvas);
}

/** Lado más largo al que se reduce la foto antes de analizarla. 1280px
 *  alcanza de sobra para reconocimiento de alimentos y evita mandar los
 *  ~4000px de una cámara de iPhone (que inflaban el base64 varios MB). */
const ESCANER_LADO_MAX = 1280;

/**
 * Normaliza una foto del usuario antes de analizarla:
 *   - respeta la orientación EXIF (si no, las fotos verticales de iPhone
 *     llegaban rotadas y el reconocimiento empeoraba);
 *   - reduce la resolución excesiva;
 *   - re-codifica a JPEG, que es lo que la API de visión acepta (una foto
 *     HEIC de iPhone, si el navegador puede decodificarla, sale ya como
 *     JPEG de acá).
 * Devuelve un canvas listo, o null si el navegador no pudo decodificar
 * el archivo (ahí el formato realmente no es compatible).
 */
async function normalizarImagenParaAnalisis(archivo) {
  let fuente = null;

  // createImageBitmap con imageOrientation resuelve el EXIF sin librerías.
  if (typeof createImageBitmap === 'function') {
    try {
      fuente = await createImageBitmap(archivo, { imageOrientation: 'from-image' });
    } catch (e) {
      fuente = null;
    }
  }

  if (!fuente) {
    // Respaldo para navegadores sin createImageBitmap (o que lo rechazan).
    fuente = await new Promise((resolve) => {
      const img = new Image();
      const url = URL.createObjectURL(archivo);
      img.onload = () => { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = () => { URL.revokeObjectURL(url); resolve(null); };
      img.src = url;
    });
  }

  if (!fuente) return null;

  const ancho = fuente.width || fuente.naturalWidth;
  const alto = fuente.height || fuente.naturalHeight;
  if (!ancho || !alto) return null;

  const escala = Math.min(1, ESCANER_LADO_MAX / Math.max(ancho, alto));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(ancho * escala);
  canvas.height = Math.round(alto * escala);

  const ctx = canvas.getContext('2d');
  ctx.drawImage(fuente, 0, 0, canvas.width, canvas.height);
  if (typeof fuente.close === 'function') fuente.close();

  return canvas;
}

async function manejarFotoSeleccionada(inputEl) {
  const archivo = inputEl.files && inputEl.files[0];
  inputEl.value = '';
  if (!archivo) return;

  const resultBox = document.getElementById('resultado-ia');
  if (resultBox) {
    resultBox.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Preparando la imagen…</span></div>`;
  }

  const canvas = await normalizarImagenParaAnalisis(archivo);

  if (!canvas) {
    mostrarErrorEscaner({
      codigo: 'FORMATO_NO_COMPATIBLE',
      mensaje: 'No pudimos abrir esa imagen. Probá con una foto JPG o PNG.',
    });
    return;
  }

  mostrarFotoCapturada(canvas.toDataURL('image/jpeg', 0.85));
  await analizarCanvasConIA(canvas);
}

// Punto de entrada del análisis: la IA de visión (Gemini/Claude) identifica
// varios alimentos por foto, estima sus gramos, y el backend ya resuelve la
// información nutricional de cada uno con el mismo motor que el buscador
// manual (NutricionResolver) — acá solo queda mostrar el resultado listo.
// Si el backend no tiene la API key configurada o la llamada falla (sin
// conexión, error de la API), cae automáticamente al reconocimiento local
// gratuito (MobileNet) como respaldo silencioso — el escáner no se rompe.
async function analizarCanvasConIA(canvas) {
  const resultBox = document.getElementById('resultado-ia');
  resultBox.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Analizando la imagen con IA…</span></div>`;

  const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
  const res = await Api.analizarFoto(dataUrl);

  if (res.success) {
    mostrarAlimentosDetectados(res.data);
    return;
  }

  // El backend ahora distingue la causa real (ver ErrorEscaner.php): un
  // problema de configuración o de red NO debe mostrarse como "no se
  // detectaron alimentos", que era lo que pasaba antes.
  const codigo = res.data?.codigo || 'ERROR_SERVIDOR';
  const permiteFallback = res.data?.permite_fallback_local !== false;

  if (permiteFallback) {
    const reconocido = await analizarConMobileNet(canvas, { silencioso: true });
    if (reconocido) return;
  }

  mostrarErrorEscaner({ codigo, mensaje: res.message });
}

/** Mensaje de error del escáner, con el motivo real y una salida útil. */
function mostrarErrorEscaner({ codigo, mensaje }) {
  const resultBox = document.getElementById('resultado-ia');
  if (!resultBox) return;

  const esNoDetectado = codigo === 'NO_ALIMENTOS';

  resultBox.innerHTML = `
    <div class="escaner-error${esNoDetectado ? '' : ' escaner-error--fallo'}">
      <span class="escaner-error-icono">${Icon(esNoDetectado ? 'scan' : 'triangle-alert', { size: 18 })}</span>
      <div>
        <p class="escaner-error-texto">${mensaje || 'No pudimos analizar la imagen.'}</p>
        <p class="escaner-error-ayuda">Podés intentar con otra foto o buscar el alimento a mano acá abajo.</p>
      </div>
    </div>
  `;
}

function tipoComidaSegunHora() {
  const hora = new Date().getHours();
  if (hora < 11) return 'desayuno';
  if (hora < 16) return 'almuerzo';
  if (hora < 20) return 'merienda';
  return 'cena';
}

let itemsDetectadosCamara = [];
let tipoComidaCamaraSeleccionado = null;

function mostrarAlimentosDetectados(data) {
  const resultBox = document.getElementById('resultado-ia');

  if (!data.es_comida || !data.alimentos || data.alimentos.length === 0) {
    // Éste sí es el caso legítimo de "no hay comida en la foto" (la IA
    // respondió bien): se muestra como tal, no como error.
    mostrarErrorEscaner({
      codigo: 'NO_ALIMENTOS',
      mensaje: data.descripcion || 'No encontramos alimentos reconocibles en esta foto.',
    });
    return;
  }

  itemsDetectadosCamara = data.alimentos.map((item) => ({
    nombre: item.nombre,
    gramos: item.gramos_estimados,
    alimentoId: item.alimento_id,
    calorias100g: item.calorias_por_100g ?? null,
    proteinas100g: item.proteinas_por_100g ?? null,
    carbohidratos100g: item.carbohidratos_por_100g ?? null,
    grasas100g: item.grasas_por_100g ?? null,
  }));
  tipoComidaCamaraSeleccionado = tipoComidaSegunHora();

  resultBox.innerHTML = `
    <p class="row-label" style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">${Icon('sparkles', { size: 13 })}${data.descripcion}</p>
    <p style="font-size:12.5px;font-weight:600;margin:2px 0 10px;">Alimentos detectados</p>
    <div id="items-detectados-camara"></div>
    <div class="field" style="margin-top:4px;">
      <label>Tipo de comida</label>
      <div class="modal-tipos">
        ${TIPOS_COMIDA.map((t) => `
          <div class="option-card${t.valor === tipoComidaCamaraSeleccionado ? ' selected' : ''}" data-tipo="${t.valor}" onclick="seleccionarTipoComidaCamara('${t.valor}')">${t.label}</div>
        `).join('')}
      </div>
    </div>
    <div class="macro-preview" id="total-plato-camara"></div>
    <div class="modal-actions" style="margin-top:10px;">
      <button type="button" class="btn btn-primary" onclick="agregarPlatoDetectado()">Agregar a mi día</button>
    </div>
  `;

  renderItemsDetectadosCamara();
}

function seleccionarTipoComidaCamara(tipo) {
  tipoComidaCamaraSeleccionado = tipo;
  document.querySelectorAll('#resultado-ia .modal-tipos .option-card').forEach((el) => {
    el.classList.toggle('selected', el.dataset.tipo === tipo);
  });
}

function formatearMacrosLinea(cal, prot, carb, gras) {
  return `${Math.round(cal)} kcal · P ${Math.round(prot)}g · C ${Math.round(carb)}g · G ${Math.round(gras)}g`;
}

function renderItemsDetectadosCamara() {
  const contenedor = document.getElementById('items-detectados-camara');
  contenedor.innerHTML = itemsDetectadosCamara.map((item, idx) => {
    if (item.alimentoId === null) {
      return `
        <div class="meal-item" style="flex-direction:column;align-items:stretch;gap:6px;margin-bottom:10px;">
          <span style="font-size:12.5px;font-weight:600;">${item.nombre} <span style="color:var(--text-secondary);font-weight:400;">(~${Math.round(item.gramos)}g)</span></span>
          <span style="font-size:11.5px;color:var(--text-secondary);">No encontramos información nutricional automática para este alimento — buscalo manualmente:</span>
          <input type="text" placeholder="Buscar ${item.nombre.replace(/"/g, '')}..." oninput="buscarReemplazoItemCamara(${idx}, this.value)" />
          <div id="candidatos-reemplazo-${idx}"></div>
        </div>
      `;
    }

    const factor = item.gramos / 100;
    return `
      <div class="meal-item" style="flex-direction:column;align-items:stretch;gap:6px;margin-bottom:10px;">
        <div style="display:flex;justify-content:space-between;align-items:center;">
          <span style="font-size:12.5px;font-weight:600;">${item.nombre}</span>
          <div style="display:flex;gap:10px;align-items:center;">
            <button type="button" style="background:none;border:none;cursor:pointer;font-size:14px;color:var(--color-warning);padding:0;" onclick="guardarFavoritoDesdeCamara(${idx})" title="Guardar como favorito">♡</button>
            <button type="button" class="btn-link-editar" onclick="editarItemDetectadoCamara(${idx})">Editar</button>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <input type="number" min="1" step="1" value="${Math.round(item.gramos)}" style="width:70px;" oninput="actualizarGramosItemCamara(${idx}, this.value)" />
          <span style="font-size:11.5px;color:var(--text-secondary);">g</span>
        </div>
        <div id="macros-item-${idx}" style="font-size:11.5px;color:var(--text-secondary);">
          ${formatearMacrosLinea(item.calorias100g * factor, item.proteinas100g * factor, item.carbohidratos100g * factor, item.grasas100g * factor)}
        </div>
      </div>
    `;
  }).join('');

  actualizarTotalPlatoCamara();
}

function actualizarGramosItemCamara(idx, valor) {
  const gramos = parseFloat(valor);
  itemsDetectadosCamara[idx].gramos = gramos > 0 ? gramos : 0;

  const item = itemsDetectadosCamara[idx];
  const factor = item.gramos / 100;
  const macrosBox = document.getElementById(`macros-item-${idx}`);
  if (macrosBox && item.calorias100g !== null) {
    macrosBox.textContent = formatearMacrosLinea(item.calorias100g * factor, item.proteinas100g * factor, item.carbohidratos100g * factor, item.grasas100g * factor);
  }
  actualizarTotalPlatoCamara();
}

function actualizarTotalPlatoCamara() {
  const total = itemsDetectadosCamara.reduce((acc, item) => {
    if (item.alimentoId === null || item.calorias100g === null) return acc;
    const factor = item.gramos / 100;
    acc.cal += item.calorias100g * factor;
    acc.prot += item.proteinas100g * factor;
    acc.carb += item.carbohidratos100g * factor;
    acc.gras += item.grasas100g * factor;
    return acc;
  }, { cal: 0, prot: 0, carb: 0, gras: 0 });

  const box = document.getElementById('total-plato-camara');
  if (!box) return;
  box.innerHTML = `
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-nutricion);">${Math.round(total.cal)}</span>
      <span class="label">kcal totales</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-xp);">${Math.round(total.prot)}g</span>
      <span class="label">Proteínas</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-carbohidratos);">${Math.round(total.carb)}g</span>
      <span class="label">Carbos</span>
    </div>
    <div class="macro-preview-item">
      <span class="valor mono" style="color:var(--color-grasas);">${Math.round(total.gras)}g</span>
      <span class="label">Grasas</span>
    </div>
  `;
}

/** Abre el modal clásico (gramos + tipo, con chips +50/+100/+200g) para
 *  ajustar y registrar este ítem por separado, en vez de como parte del
 *  lote de "Agregar a mi día". */
function editarItemDetectadoCamara(idx) {
  const item = itemsDetectadosCamara[idx];
  abrirModalRegistroComida(item.alimentoId, item.nombre, item.calorias100g, item.proteinas100g, item.carbohidratos100g, item.grasas100g, item.gramos);
}

function guardarFavoritoDesdeCamara(idx) {
  const item = itemsDetectadosCamara[idx];
  const factor = item.gramos / 100;
  abrirModalGuardarFavorito(
    item.alimentoId,
    item.nombre,
    Math.round(item.gramos),
    'g',
    item.calorias100g * factor,
    item.proteinas100g * factor,
    item.carbohidratos100g * factor,
    item.grasas100g * factor
  );
}

let debounceTimerReemplazo = null;
function buscarReemplazoItemCamara(idx, query) {
  clearTimeout(debounceTimerReemplazo);
  debounceTimerReemplazo = setTimeout(async () => {
    const contenedor = document.getElementById(`candidatos-reemplazo-${idx}`);
    if (!contenedor) return;
    if (!query || query.length < 2) { contenedor.innerHTML = ''; return; }

    const res = await Api.buscarAlimentos(query);
    if (!res.success || res.data.length === 0) { contenedor.innerHTML = `<div class="empty-state">Sin resultados</div>`; return; }

    contenedor.innerHTML = res.data.slice(0, 5).map((a) => `
      <div class="meal-item scanner-candidate" onclick="asignarReemplazoItemCamara(${idx}, ${a.id}, ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
        <span>${a.nombre_mostrado}</span><span class="cal">${a.calorias_por_100g} kcal/100g</span>
      </div>
    `).join('');
  }, 300);
}

function asignarReemplazoItemCamara(idx, alimentoId, cal, prot, carb, gras) {
  itemsDetectadosCamara[idx] = {
    ...itemsDetectadosCamara[idx],
    alimentoId,
    calorias100g: cal,
    proteinas100g: prot,
    carbohidratos100g: carb,
    grasas100g: gras,
  };
  renderItemsDetectadosCamara();
}

async function agregarPlatoDetectado() {
  const items = itemsDetectadosCamara.filter((i) => i.alimentoId !== null && i.gramos > 0);
  if (items.length === 0) { showToast('No hay alimentos con información nutricional para agregar todavía.'); return; }

  let ultimoXP = null;
  for (const item of items) {
    const res = await Api.registrarComida({
      alimento_id: item.alimentoId, tipo_comida: tipoComidaCamaraSeleccionado, gramos: item.gramos, origen: 'camara_ia',
    });
    if (res.success) ultimoXP = res.data.xp;
  }

  if (ultimoXP) await handleXPResult(ultimoXP);
  await renderNutricion();
  showToast(items.length === 1 ? 'Comida agregada a tu día.' : `${items.length} alimentos agregados a tu día.`);
  document.getElementById('resultado-ia').innerHTML = `<p class="empty-state">${Icon('check', { size: 14 })} Agregado a tu día. Sacá otra foto cuando quieras.</p>`;
}

/**
 * Reconocimiento local de respaldo (MobileNet en el navegador).
 * Devuelve true solo si llegó a identificar algo: quien lo llama decide
 * si, al devolver false, muestra el error real del servidor en vez de
 * dejar un mensaje genérico que oculte la causa.
 */
async function analizarConMobileNet(canvas, { silencioso = false } = {}) {
  const resultBox = document.getElementById('resultado-ia');
  resultBox.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Cargando modelo de visión local…</span></div>`;

  let modelo;
  try {
    modelo = await cargarModeloVision();
  } catch (err) {
    if (!silencioso) {
      resultBox.innerHTML = `<p class="empty-state">No se pudo cargar el modelo de IA (¿sin conexión a internet?). Usá el buscador manual de abajo.</p>`;
    }
    return false;
  }

  resultBox.innerHTML = `<div class="scanner-loading"><div class="scanner-spinner"></div><span>Analizando la imagen…</span></div>`;
  const predicciones = await modelo.classify(canvas, 5);
  return mostrarResultadoIA(predicciones, { silencioso });
}

async function mostrarResultadoIA(predicciones, { silencioso = false } = {}) {
  const resultBox = document.getElementById('resultado-ia');
  const chipsHTML = predicciones.slice(0, 3)
    .map((p) => `<span class="prediction-chip">${Math.round(p.probability * 100)}% ${p.className.split(',')[0]}</span>`)
    .join('');

  let coincidencia = null;
  for (const p of predicciones) {
    const label = p.className.toLowerCase();
    const entrada = DICCIONARIO_ALIMENTOS_IA.find((d) => d.claves.some((c) => label.includes(c)));
    if (entrada && p.probability > 0.12) { coincidencia = entrada; break; }
  }

  if (!coincidencia) {
    if (!silencioso) {
      resultBox.innerHTML = `
        <div class="prediction-chip-row">${chipsHTML}</div>
        <p class="empty-state">No pudimos identificar un alimento con confianza suficiente. Probá con más luz o de más cerca, o usá el buscador manual de abajo.</p>
      `;
    }
    return false;
  }

  resultBox.innerHTML = `
    ${silencioso ? `<p class="escaner-nota-respaldo">${Icon('info', { size: 13 })} Analizado con el reconocimiento local de respaldo (menos preciso).</p>` : ''}
    <div class="prediction-chip-row">${chipsHTML}</div>
    <p style="font-size:12px;color:var(--text-secondary);margin-bottom:8px;">Detectamos algo parecido a <strong>${coincidencia.termino}</strong>. Buscando información nutricional real…</p>
    <div id="candidatos-ia"></div>
  `;

  const res = await Api.buscarAlimentos(coincidencia.termino);
  const candidatosBox = document.getElementById('candidatos-ia');
  if (!res.success || res.data.length === 0) {
    candidatosBox.innerHTML = `<p class="empty-state">No encontramos "${coincidencia.termino}" en el catálogo ni en Open Food Facts. Probá el buscador manual.</p>`;
    return true;
  }

  candidatosBox.innerHTML = res.data.map((a) => `
    <div class="meal-item scanner-candidate" onclick="abrirModalRegistroComida(${a.id}, '${a.nombre_mostrado.replace(/'/g, "\\'")}', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
      <span>${a.nombre_mostrado}</span><span class="cal">${a.calorias_por_100g} kcal/100g</span>
    </div>
  `).join('');

  return true;
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
    <div class="meal-item" style="cursor:pointer;" onclick="abrirModalRegistroComida(${a.id}, '${a.nombre_mostrado.replace(/'/g, "\\'")}', ${a.calorias_por_100g}, ${a.proteinas}, ${a.carbohidratos}, ${a.grasas})">
      <span>${a.nombre_mostrado}</span><span class="cal">${a.calorias_por_100g} kcal/100g</span>
    </div>
  `).join('');
}

// =====================================================================
// PESTAÑA 4 — Ranking, Amigos & Tienda
// =====================================================================
let ligaActiva = 'bronce';
let miUsuarioId = null;

async function renderRanking() {
  const container = document.getElementById('view-ranking');
  const [rankingRes, tiendaRes, sesionRes] = await Promise.all([Api.ranking(), Api.tiendaGet(), Api.sesion()]);
  const coins = sesionRes.success ? sesionRes.data.nutri_coins : 0;
  miUsuarioId = sesionRes.success ? sesionRes.data.id : null;

  container.innerHTML = `
    <div class="view-header">
      <div><span class="eyebrow">Comunidad</span><h1>Ranking & Tienda</h1></div>
    </div>

    <div class="card">
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('trophy', { size: 17 })}Ligas NutriFit</h3>
      <div class="league-tabs" id="league-tabs"></div>
      <div id="leaderboard-list"></div>
    </div>

    <div class="card" style="margin-top:18px;">
      <h3 class="row-label" style="font-size:15px;margin-bottom:14px;">${Icon('users', { size: 17 })}Amigos</h3>
      <div style="display:flex;gap:8px;margin-bottom:14px;">
        <input type="email" id="input-email-amigo" placeholder="Email de tu amigo" />
        <button class="btn btn-primary" style="width:auto;padding:12px 16px;" onclick="agregarAmigo()">Agregar</button>
      </div>
      <div id="lista-amigos"></div>
    </div>

    <div class="card" style="margin-top:18px;">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
        <h3 class="row-label" style="font-size:15px;">${Icon('store', { size: 17 })}Tienda NutriFit</h3>
        <span class="mono row-label" style="font-size:13.5px;font-weight:700;color:var(--color-xp);">${NutriCoinIcon(15)}${coins.toLocaleString('es-AR')} NutriCoins</span>
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
  const ligas = ['bronce', 'plata', 'oro', 'diamante'];

  tabsEl.innerHTML = ligas.map((l) => `
    <button class="league-tab row-label ${l === ligaActiva ? 'active' : ''}" onclick="switchLiga('${l}')">
      ${Icon('award', { size: 14 })}${l.charAt(0).toUpperCase() + l.slice(1)}
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
    <div class="leaderboard-row ${miUsuarioId !== null && String(u.id) === String(miUsuarioId) ? 'me' : ''}">
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
      <div class="leaderboard-row">
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

const ICONOS_TIENDA_POR_TIPO = {
  ropa_avatar: 'shirt', accesorio_avatar: 'glasses', aura: 'sparkles', marco_perfil: 'image', titulo: 'tag', cupon: 'ticket',
};

function renderTienda(items) {
  const grid = document.getElementById('tienda-grid');

  grid.innerHTML = items.map((item) => {
    let boton;
    if (!item.poseido) {
      boton = `<button class="btn btn-primary" onclick="canjearItem(${item.id})">Canjear</button>`;
    } else if (item.tipo === 'cupon' || item.tipo === 'titulo') {
      // Cupones/títulos no se "equipan" en el avatar, sólo se poseen.
      boton = `<button class="btn btn-ghost" disabled>Ya lo tienes</button>`;
    } else {
      boton = `<button class="btn ${item.equipado ? 'btn-primary' : 'btn-ghost'}" onclick="equiparItem(${item.id})">
        ${item.equipado ? `${Icon('check', { size: 13 })} Equipado` : 'Equipar'}
      </button>`;
    }
    return `
      <div class="shop-item card">
        <div class="icon-box">${Icon(ICONOS_TIENDA_POR_TIPO[item.tipo] || 'gift', { size: 26 })}</div>
        <div class="name">${item.nombre}</div>
        <div class="price row-label" style="justify-content:center;">${item.costo_coins > 0 ? `${NutriCoinIcon(13)}${item.costo_coins} coins` : `${item.costo_xp} XP`}</div>
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
// Editar perfil — misma tabla/endpoint que el onboarding (perfiles_
// biometricos vía Api.onboarding), así que ambos quedan sincronizados:
// cambiar peso/altura/objetivo/actividad/restricciones acá recalcula
// TMB/GET/macros en el momento (ver MotorMetabolico en el backend) y
// registra el peso en el historial, igual que el wizard.
// =====================================================================
const EP_OBJETIVOS = [
  ['perder_grasa', 'Bajar de peso'], ['mantener_peso', 'Mantener mi peso'], ['ganar_musculo', 'Ganar masa muscular'],
  ['mejorar_habitos', 'Mejorar mi alimentación'], ['monitorear_salud', 'Seguir necesidades alimentarias específicas'],
];
const EP_ACTIVIDADES = [
  ['sedentario', 'Sedentario'], ['ligero', 'Ligero'], ['moderado', 'Moderado'], ['muy_activo', 'Muy activo'],
];
const EP_DIETAS = [
  ['omnivora', 'Omnívora'], ['vegetariana', 'Vegetariana'], ['vegana', 'Vegana'],
  ['pescatariana', 'Pescatariana'], ['keto', 'Keto'], ['sin_gluten', 'Sin Gluten'],
];
const EP_RESTRICCIONES = [
  ['celiaco', 'Celíaco / Sin TACC', 'wheat-off'], ['lactosa', 'Intolerancia a la lactosa', 'milk-off'],
  ['frutos_secos', 'Frutos secos', 'nut'], ['mariscos', 'Mariscos', 'shrimp'], ['huevo', 'Huevo', 'egg'],
];
const EP_CONDICIONES = [
  ['diabetes', 'Diabetes', 'droplets'], ['hipertension', 'Hipertensión', 'heart-pulse'],
];

/** Chip compacto tipo pastilla (distinto de `.ob-check-chip` del
 *  onboarding, que es una clase COMPARTIDA con index.html — no se toca
 *  para no cambiar de rebote la pantalla de onboarding). Mismos valores/
 *  lógica de siempre (checkbox real por debajo, `toggleAlergiaExclusiva`
 *  sin cambios), solo cambia la presentación visual. */
function epChipHTML([valor, label, icono], checked) {
  return `<label class="ep-chip${checked ? ' checked' : ''}"><input type="checkbox" class="ep-alergia-input" value="${valor}" ${checked ? 'checked' : ''} onchange="toggleAlergiaExclusiva(this, '.ep-alergia-input'); this.closest('label').classList.toggle('checked', this.checked)"><span class="ep-chip-check">${Icon('check', { size: 12 })}</span><span data-icon="${icono}" data-icon-size="14"></span>${label}</label>`;
}

const EP_TABS = [
  { key: 'personal', label: 'Personal', icon: 'user' },
  { key: 'objetivos', label: 'Objetivos', icon: 'target' },
  { key: 'preferencias', label: 'Preferencias', icon: 'utensils' },
  { key: 'salud', label: 'Salud', icon: 'shield-check' },
];

function cambiarTabEditarPerfil(tab) {
  document.querySelectorAll('.ep-tab').forEach((el) => el.classList.toggle('active', el.dataset.tab === tab));
  document.querySelectorAll('.ep-tab-panel').forEach((el) => el.classList.toggle('active', el.dataset.panel === tab));
}

/** Mismo formulario/campos/lógica de siempre (revisé TODOS los campos
 *  existentes antes de reorganizar: edad, género, altura, peso actual,
 *  peso objetivo, objetivo, nivel de actividad, lugar de entreno, tipo de
 *  dieta, restricciones y condiciones — ninguno desapareció, solo se
 *  reparten en pestañas). guardarEdicionPerfil() no cambia: lee los
 *  mismos ids de siempre, sin importar en qué pestaña estén. */
async function abrirModalEditarPerfil() {
  const res = await Api.perfilGet();
  if (!res.success) { showToast(res.message); return; }
  const p = res.data;

  const overlay = document.createElement('div');
  overlay.className = 'modal-overlay';
  overlay.id = 'modal-editar-perfil';
  overlay.innerHTML = `
    <div class="modal-card ep-card">
      <div class="ep-header">
        <h3>Configuración del perfil</h3>
        <button type="button" class="avatar-editor-close" onclick="document.getElementById('modal-editar-perfil').remove()" aria-label="Cerrar">${Icon('x', { size: 18 })}</button>
      </div>

      <div class="ep-tabs" role="tablist">
        ${EP_TABS.map((t, i) => `
          <button type="button" class="ep-tab${i === 0 ? ' active' : ''} row-label" role="tab" data-tab="${t.key}" onclick="cambiarTabEditarPerfil('${t.key}')">
            ${Icon(t.icon, { size: 15 })}<span>${t.label}</span>
          </button>
        `).join('')}
      </div>

      <div class="ep-body">
        <div class="ep-tab-panel active" data-panel="personal">
          <div class="ep-grid-2">
            <div class="field"><label>Edad</label><input type="number" id="ep-edad" value="${p.edad}" inputmode="numeric" /><span class="field-error" id="err-ep-edad"></span></div>
            <div class="field">
              <label>Género</label>
              <select id="ep-genero">
                <option value="masculino" ${p.genero === 'masculino' ? 'selected' : ''}>Masculino</option>
                <option value="femenino" ${p.genero === 'femenino' ? 'selected' : ''}>Femenino</option>
                <option value="otro" ${p.genero === 'otro' ? 'selected' : ''}>Otro</option>
              </select>
            </div>
            <div class="field"><label>Altura (cm)</label><input type="number" step="0.1" id="ep-altura" value="${p.altura}" inputmode="decimal" oninput="actualizarGuardrailEditarPerfil()" /><span class="field-error" id="err-ep-altura"></span></div>
            <div class="field"><label>Peso actual (kg)</label><input type="number" step="0.1" id="ep-peso" value="${p.peso_actual}" inputmode="decimal" oninput="actualizarGuardrailEditarPerfil()" /><span class="field-error" id="err-ep-peso"></span></div>
          </div>
          <div class="field"><label>Peso objetivo (kg)</label><input type="number" step="0.1" id="ep-peso-objetivo" value="${p.peso_objetivo ?? ''}" inputmode="decimal" oninput="actualizarGuardrailEditarPerfil()" /><span class="field-error" id="err-ep-peso-objetivo"></span></div>
        </div>

        <div class="ep-tab-panel" data-panel="objetivos">
          <div class="ep-grid-2">
            <div class="field">
              <label>Objetivo principal</label>
              <select id="ep-objetivo" onchange="actualizarGuardrailEditarPerfil()">${EP_OBJETIVOS.map(([v, l]) => `<option value="${v}" ${p.objetivo === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
            </div>
            <div class="field">
              <label>Nivel de actividad</label>
              <select id="ep-actividad">${EP_ACTIVIDADES.map(([v, l]) => `<option value="${v}" ${p.nivel_actividad === v ? 'selected' : ''}>${l}</option>`).join('')}</select>
            </div>
          </div>
          <div class="ep-guardrail" id="ep-guardrail"></div>
        </div>

        <div class="ep-tab-panel" data-panel="preferencias">
          <div class="ep-grid-2">
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
          </div>
        </div>

        <div class="ep-tab-panel" data-panel="salud">
          <p class="ob-group-label">Restricciones alimentarias</p>
          <div class="ep-chips-row">${EP_RESTRICCIONES.map((r) => epChipHTML(r, p.alergias.includes(r[0]))).join('')}</div>
          <p class="ob-group-label">Condiciones a tener en cuenta</p>
          <div class="ep-chips-row">${EP_CONDICIONES.map((r) => epChipHTML(r, p.alergias.includes(r[0]))).join('')}</div>
          <div class="ep-chips-row" style="margin-top:10px;">${epChipHTML(['ninguna', 'Ninguna de las anteriores', 'circle-check'], p.alergias.includes('ninguna') || p.alergias.length === 0)}</div>
          <p class="ob-disclaimer row-label" style="margin-top:14px;"><span data-icon="shield-check" data-icon-size="14"></span>NutriFit puede adaptar sugerencias generales, pero no reemplaza indicaciones profesionales.</p>
        </div>
      </div>

      <div class="modal-actions ep-footer-sticky">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('modal-editar-perfil').remove()">Cancelar</button>
        <button type="button" class="btn btn-primary row-label" style="justify-content:center;" onclick="guardarEdicionPerfil()">${Icon('check', { size: 16 })}Guardar cambios</button>
      </div>
    </div>
  `;
  overlay.addEventListener('click', (e) => { if (e.target === overlay) overlay.remove(); });
  document.body.appendChild(overlay);
  hydrateIcons(overlay);
  actualizarGuardrailEditarPerfil();
}

/** Vista previa en vivo del guardrail de IMC (mismo cálculo que el
 *  onboarding, ver evaluarObjetivoPeso/mensajeObjetivoPeso en auth.js) —
 *  no bloquea el guardado, sólo informa. */
function actualizarGuardrailEditarPerfil() {
  const box = document.getElementById('ep-guardrail');
  if (!box) return;
  const edad = document.getElementById('ep-edad').value;
  const altura = document.getElementById('ep-altura').value;
  const peso = document.getElementById('ep-peso').value;
  const pesoObjetivo = document.getElementById('ep-peso-objetivo').value;
  const objetivo = document.getElementById('ep-objetivo').value;

  const evalRes = evaluarObjetivoPeso({ pesoActual: peso, pesoObjetivo, altura, edad });
  if (evalRes.status === 'insufficientData') { box.innerHTML = ''; return; }

  const { icon, tono, texto } = mensajeObjetivoPeso(evalRes, objetivo);
  box.className = `ep-guardrail ep-guardrail--${tono}`;
  box.innerHTML = `${Icon(icon, { size: 16 })}<span>${texto}</span>`;
}

async function guardarEdicionPerfil() {
  const edad = document.getElementById('ep-edad').value;
  const peso = document.getElementById('ep-peso').value;
  const altura = document.getElementById('ep-altura').value;
  const pesoObjetivoRaw = document.getElementById('ep-peso-objetivo').value;

  const errores = validarBiometria({ edad, peso, altura, pesoObjetivo: pesoObjetivoRaw });
  mostrarErroresCampo({ edad: 'ep-edad', peso: 'ep-peso', altura: 'ep-altura', pesoObjetivo: 'ep-peso-objetivo' }, errores);
  if (Object.keys(errores).length > 0) {
    showToast('Revisá los datos marcados antes de guardar.');
    return;
  }

  const alergiasSeleccionadas = Array.from(document.querySelectorAll('.ep-alergia-input:checked')).map((el) => el.value);

  const payload = {
    edad,
    genero: document.getElementById('ep-genero').value,
    peso_actual: peso,
    peso_objetivo: pesoObjetivoRaw || null,
    altura,
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
