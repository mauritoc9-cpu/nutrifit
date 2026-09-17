/**
 * NutriFit — Autenticación, onboarding y modo oscuro.
 */

// ---------- Modo oscuro persistente ----------
function initTheme() {
  const saved = localStorage.getItem('nutrifit_theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
  actualizarIconoThemeToggle(saved);
}

// Aplica el cambio de tema con las transiciones desactivadas momentáneamente
// (ver comentario de ".theme-switching" en styles.css) para que el color
// nuevo se pinte al instante en todos los navegadores, y las restaura dos
// frames después para no perder las animaciones normales de hover/focus.
function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';

  document.documentElement.classList.add('theme-switching');
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('nutrifit_theme', next);
  actualizarIconoThemeToggle(next);

  requestAnimationFrame(() => requestAnimationFrame(() => {
    document.documentElement.classList.remove('theme-switching');
  }));
}

function actualizarIconoThemeToggle(theme) {
  const btn = document.getElementById('mobile-theme-btn');
  if (btn) btn.innerHTML = Icon(theme === 'dark' ? 'sun' : 'moon', { label: 'Cambiar tema' });
}

// ---------- Navegación entre pantallas de auth ----------
function showScreen(id) {
  document.querySelectorAll('.screen').forEach((el) => el.classList.add('hidden'));
  document.getElementById(id).classList.remove('hidden');
}

// ---------- Login ----------
async function handleLogin(event) {
  event.preventDefault();
  const email = document.getElementById('login-email').value.trim();
  const password = document.getElementById('login-password').value;
  const errorBox = document.getElementById('login-error');
  errorBox.classList.add('hidden');

  const res = await Api.login(email, password);

  if (!res.success) {
    errorBox.textContent = res.message;
    errorBox.classList.remove('hidden');
    return;
  }

  if (res.data.onboarding_completo) {
    await bootApp();
  } else {
    window.NutriFitPendingUser = res.data.usuario.nombre;
    showScreen('screen-onboarding');
    resetWizard();
  }
}

// ---------- Registro ----------
async function handleRegistro(event) {
  event.preventDefault();
  const nombre = document.getElementById('reg-nombre').value.trim();
  const email = document.getElementById('reg-email').value.trim();
  const password = document.getElementById('reg-password').value;
  const errorBox = document.getElementById('reg-error');
  errorBox.classList.add('hidden');

  const res = await Api.registro(nombre, email, password);

  if (!res.success) {
    errorBox.textContent = res.message;
    errorBox.classList.remove('hidden');
    return;
  }

  window.NutriFitPendingUser = nombre;
  showScreen('screen-onboarding');
  resetWizard();
}

// ---------- Recuperar contraseña ----------
async function handleSolicitarReset(event) {
  event.preventDefault();
  const email = document.getElementById('recuperar-email').value.trim();
  const errorBox = document.getElementById('recuperar-error');
  const resultBox = document.getElementById('recuperar-resultado');
  errorBox.classList.add('hidden');
  resultBox.classList.add('hidden');

  const res = await Api.solicitarReset(email);

  if (!res.success) {
    errorBox.textContent = res.message;
    errorBox.classList.remove('hidden');
    return;
  }

  // Modo desarrollo: no hay email configurado, así que mostramos el link
  // directamente acá (ver comentario en backend/api/auth/solicitar_reset.php).
  resultBox.innerHTML = res.data?.link_reset_dev
    ? `${res.message}<br><br><strong>Modo desarrollo</strong> — como no hay email configurado, usá este link:<br>
       <a href="${res.data.link_reset_dev}">${res.data.link_reset_dev}</a>`
    : res.message;
  resultBox.classList.remove('hidden');
}

async function handleResetearPassword(event) {
  event.preventDefault();
  const token = document.getElementById('nueva-password-token').value;
  const password = document.getElementById('nueva-password-input').value;
  const errorBox = document.getElementById('nueva-password-error');
  errorBox.classList.add('hidden');

  const res = await Api.resetearPassword(token, password);

  if (!res.success) {
    errorBox.textContent = res.message;
    errorBox.classList.remove('hidden');
    return;
  }

  showToast('Contraseña actualizada. Iniciá sesión de nuevo.');
  showScreen('screen-login');
}

async function handleLogout() {
  await Api.logout();
  location.reload();
}

// ---------- Onboarding Wizard (7 pasos) ----------
const OB_SEGMENTOS = ['biometria', 'actividad', 'dieta', 'alergias'];
const OB_PASO_LABELS = {
  biometria: 'Información básica',
  actividad: 'Nivel de actividad',
  dieta: 'Dieta',
  alergias: 'Restricciones',
};

// =====================================================================
// Guardrail del objetivo de peso — IMC como herramienta ORIENTATIVA, no
// como diagnóstico. Reemplaza el mensaje anterior que aprobaba cualquier
// meta ("Perder 20kg es un objetivo realista") sin validar nada contra
// la altura. Reutilizado también desde Editar Perfil (app.js).
// =====================================================================
function calcularIMC(pesoKg, alturaCm) {
  const p = parseFloat(pesoKg);
  const a = parseFloat(alturaCm);
  if (!Number.isFinite(p) || !Number.isFinite(a) || p <= 0 || a <= 0) return null;
  const alturaM = a / 100;
  return p / (alturaM * alturaM);
}

/** Evalúa peso objetivo vs. peso actual/altura/edad. Devuelve un status
 *  entre 'valid' | 'caution' | 'invalid' | 'insufficientData' — nunca un
 *  diagnóstico ("estás saludable"/"tenés que adelgazar"), sólo detecta
 *  metas evidentemente problemáticas para pedir que se revisen. */
function evaluarObjetivoPeso({ pesoActual, pesoObjetivo, altura, edad }) {
  const pActual = parseFloat(pesoActual);
  const alt = parseFloat(altura);
  if (!Number.isFinite(pActual) || pActual <= 0 || !Number.isFinite(alt) || alt <= 0) {
    return { status: 'insufficientData' };
  }
  const imcActual = calcularIMC(pActual, alt);
  const pObjetivo = parseFloat(pesoObjetivo);
  const hayObjetivo = Number.isFinite(pObjetivo) && pObjetivo > 0;

  if (!hayObjetivo || Math.abs(pActual - pObjetivo) < 0.5) {
    return { status: 'valid', tipo: 'mantenimiento', imcActual };
  }

  const imcObjetivo = calcularIMC(pObjetivo, alt);
  const tipo = pObjetivo < pActual ? 'bajar' : 'subir';
  const diffKg = Math.abs(pActual - pObjetivo);
  const edadNum = parseInt(edad, 10);
  const esMenor = Number.isFinite(edadNum) && edadNum > 0 && edadNum < 18;

  // Guardrail duro: fuera de todo rango humano plausible (no diagnóstico,
  // sólo sentido común — nadie fija de verdad una meta de IMC 10 o 60).
  if (imcObjetivo < 14 || imcObjetivo > 45) {
    return { status: 'invalid', tipo, imcActual, imcObjetivo, diffKg, esMenor };
  }
  if (esMenor) {
    return { status: 'caution', tipo, imcActual, imcObjetivo, diffKg, esMenor: true };
  }
  if (imcObjetivo < 18.5 || imcObjetivo > 30) {
    return { status: 'caution', tipo, imcActual, imcObjetivo, diffKg };
  }
  return { status: 'valid', tipo, imcActual, imcObjetivo, diffKg };
}

/** Traduce el resultado de evaluarObjetivoPeso a {icon, tono, texto} para
 *  mostrar en el paso de feedback. `objetivo` es la opción elegida en el
 *  primer paso (para adaptar la redacción, ver punto F del brief). */
function mensajeObjetivoPeso(evalRes, objetivo) {
  const fmt = (n) => n.toFixed(1);
  if (evalRes.status === 'insufficientData') {
    return { icon: 'info', tono: 'info', texto: 'Completá tu peso y altura para poder evaluar tu meta.' };
  }
  if (evalRes.status === 'invalid') {
    return {
      icon: 'circle-x', tono: 'invalid',
      texto: `Con tu altura, ese peso objetivo da un IMC de ${fmt(evalRes.imcObjetivo)} — fuera de un rango humano saludable. Revisá el peso objetivo antes de continuar.`,
    };
  }
  if (evalRes.status === 'caution' && evalRes.esMenor) {
    return {
      icon: 'info', tono: 'caution',
      texto: 'Sos menor de 18 años, así que no aplicamos metas de peso pensadas para adultos. Para definir un objetivo de peso, lo mejor es hacerlo acompañado de un adulto responsable o un profesional de la salud.',
    };
  }
  if (evalRes.status === 'caution') {
    return {
      icon: 'triangle-alert', tono: 'caution',
      texto: `Tu peso objetivo parece ${evalRes.tipo === 'bajar' ? 'bajo' : 'alto'} para tu altura (IMC orientativo: ${fmt(evalRes.imcObjetivo)}). Podés continuar, pero te conviene revisar la meta.`,
    };
  }
  // valid
  if (evalRes.tipo === 'mantenimiento') {
    return { icon: 'target', tono: 'valid', texto: 'Tu objetivo es mantener tu peso actual. Vamos a enfocarnos en hábitos y constancia.' };
  }
  if (objetivo === 'ganar_musculo') {
    return { icon: 'circle-check', tono: 'valid', texto: `Ganar ${fmt(evalRes.diffKg)} kg está dentro de un rango razonable. Lo vamos a acompañar con más proteína y tu rutina de entrenamiento.` };
  }
  const verbo = evalRes.tipo === 'bajar' ? 'Bajar' : 'Subir';
  return { icon: 'circle-check', tono: 'valid', texto: `${verbo} ${fmt(evalRes.diffKg)} kg está dentro de un rango razonable según los datos que ingresaste.` };
}

/** Validación técnica de biometría — rangos plausibles, nunca un
 *  diagnóstico. Rechaza NaN/Infinity/negativos/strings y valores fuera
 *  de todo rango humano; NO juzga si un objetivo es "sano" (eso lo hace
 *  evaluarObjetivoPeso, y sin bloquear el guardado). */
function validarBiometria({ edad, peso, altura, pesoObjetivo }) {
  const errores = {};
  const e = parseFloat(edad);
  const p = parseFloat(peso);
  const a = parseFloat(altura);

  if (!Number.isFinite(e) || e < 12 || e > 100) errores.edad = 'Ingresá una edad entre 12 y 100 años.';
  if (!Number.isFinite(p) || p <= 0 || p > 400) errores.peso = 'Ingresá un peso válido (hasta 400 kg).';
  if (!Number.isFinite(a) || a <= 0 || a > 250) errores.altura = 'Ingresá una altura válida (hasta 250 cm).';

  if (pesoObjetivo !== '' && pesoObjetivo != null && pesoObjetivo !== undefined) {
    const po = parseFloat(pesoObjetivo);
    if (!Number.isFinite(po) || po <= 0 || po > 400) errores.pesoObjetivo = 'Ingresá un peso objetivo válido (hasta 400 kg).';
  }
  return errores;
}

/** Pinta errores de validación junto a cada campo (id "err-<inputId>").
 *  Reutilizada por el onboarding y por Editar Perfil. */
function mostrarErroresCampo(prefijoInputs, errores) {
  Object.entries(prefijoInputs).forEach(([clave, inputId]) => {
    const el = document.getElementById(`err-${inputId}`);
    if (el) el.textContent = errores[clave] || '';
    document.getElementById(inputId)?.classList.toggle('input-invalido', !!errores[clave]);
  });
}

const wizardState = {
  objetivo: null, nivel_actividad: null, lugar_entreno: null,
  tipo_dieta: null, alergias: [],
};
let obResultado = null;

function resetWizard() {
  wizardState.objetivo = null;
  wizardState.nivel_actividad = null;
  wizardState.lugar_entreno = null;
  wizardState.tipo_dieta = null;
  wizardState.alergias = [];
  obResultado = null;

  document.querySelectorAll('#screen-onboarding input[type="radio"], #screen-onboarding input[type="checkbox"]')
    .forEach((el) => { el.checked = false; });
  ['ob-edad', 'ob-peso', 'ob-peso-objetivo', 'ob-altura'].forEach((id) => {
    document.getElementById(id).value = '';
    document.getElementById(id).classList.remove('input-invalido');
    const err = document.getElementById(`err-${id}`);
    if (err) err.textContent = '';
  });
  document.getElementById('ob-genero').value = 'masculino';
  ['objetivo', 'actividad', 'dieta'].forEach((paso) => {
    const btn = document.getElementById(`ob-btn-${paso}`);
    if (btn) btn.disabled = true;
  });

  obGoTo('objetivo');
}

function obGoTo(paso) {
  document.querySelectorAll('.ob-step').forEach((el) => el.classList.remove('active'));
  document.getElementById(`ob-step-${paso}`).classList.add('active');

  // El header de progreso se muestra en los 4 segmentos del medio, y también
  // en "feedback" (queda entre biometría y actividad, con biometría ya completa).
  const header = document.getElementById('ob-header');
  const idxSegmento = paso === 'feedback' ? OB_SEGMENTOS.indexOf('actividad') : OB_SEGMENTOS.indexOf(paso);
  const esFeedback = paso === 'feedback';
  header.style.visibility = idxSegmento === -1 ? 'hidden' : 'visible';

  document.querySelectorAll('.ob-progress-bar span').forEach((el, idx) => {
    el.classList.toggle('done', idxSegmento > idx);
    el.classList.toggle('current', !esFeedback && idxSegmento === idx);
  });

  const pasoLabel = document.getElementById('ob-paso-label');
  if (pasoLabel && idxSegmento !== -1) {
    // El feedback es una pantalla intermedia (todavía no "avanzó" a
    // Actividad) — mantiene el número/label del paso que acaba de
    // completar en vez de mostrar "Paso 2" con la etiqueta de "Paso 1".
    const numeroPaso = esFeedback ? OB_SEGMENTOS.indexOf('biometria') + 1 : idxSegmento + 1;
    const segmentoActual = esFeedback ? 'biometria' : OB_SEGMENTOS[idxSegmento];
    pasoLabel.textContent = `Paso ${numeroPaso} de ${OB_SEGMENTOS.length} · ${OB_PASO_LABELS[segmentoActual]}`;
  }
}

function obSelect(campo, valor) {
  wizardState[campo] = valor;
  const botonPorCampo = { objetivo: 'ob-btn-objetivo', nivel_actividad: 'ob-btn-actividad', lugar_entreno: 'ob-btn-actividad', tipo_dieta: 'ob-btn-dieta' };
  if (campo === 'nivel_actividad' || campo === 'lugar_entreno') {
    document.getElementById('ob-btn-actividad').disabled = !(wizardState.nivel_actividad && wizardState.lugar_entreno);
  } else if (botonPorCampo[campo]) {
    document.getElementById(botonPorCampo[campo]).disabled = false;
  }
}

/** "Ninguna" es mutuamente excluyente con cualquier otra restricción o
 *  condición: elegirla desmarca todo lo demás, y elegir cualquier otra
 *  cosa desmarca "Ninguna" (punto J del brief). Compartida entre el
 *  onboarding y Editar Perfil — cada uno pasa su propio scopeSelector
 *  para no mezclar los checkboxes de un formulario con el otro. */
function toggleAlergiaExclusiva(checkboxEl, scopeSelector) {
  const valor = checkboxEl.value;
  const todos = document.querySelectorAll(scopeSelector);

  if (valor === 'ninguna' && checkboxEl.checked) {
    todos.forEach((el) => { if (el.value !== 'ninguna') el.checked = false; });
  } else if (checkboxEl.checked) {
    const ninguna = Array.from(todos).find((el) => el.value === 'ninguna');
    if (ninguna) ninguna.checked = false;
  }

  return Array.from(todos).filter((el) => el.checked).map((el) => el.value);
}

function obToggleAlergia(checkboxEl) {
  wizardState.alergias = toggleAlergiaExclusiva(checkboxEl, '.ob-alergia-input');
}

function obSubmitBiometria() {
  const edad = document.getElementById('ob-edad').value;
  const peso = document.getElementById('ob-peso').value;
  const altura = document.getElementById('ob-altura').value;
  const pesoObjetivoRaw = document.getElementById('ob-peso-objetivo').value;

  const errores = validarBiometria({ edad, peso, altura, pesoObjetivo: pesoObjetivoRaw });
  mostrarErroresCampo({ edad: 'ob-edad', peso: 'ob-peso', altura: 'ob-altura', pesoObjetivo: 'ob-peso-objetivo' }, errores);
  if (Object.keys(errores).length > 0) {
    showToast('Revisá los datos marcados antes de continuar.');
    return;
  }

  const evalRes = evaluarObjetivoPeso({ pesoActual: peso, pesoObjetivo: pesoObjetivoRaw, altura, edad });
  const { icon, tono, texto } = mensajeObjetivoPeso(evalRes, wizardState.objetivo);

  const iconoEl = document.getElementById('ob-feedback-icon');
  iconoEl.className = `ob-feedback-icon ob-feedback-icon--${tono}`;
  iconoEl.innerHTML = Icon(icon, { size: 40 });
  document.getElementById('ob-feedback-msg').textContent = texto;

  const imcEl = document.getElementById('ob-feedback-imc');
  imcEl.textContent = (evalRes.status !== 'insufficientData' && evalRes.imcActual)
    ? `IMC orientativo actual: ${evalRes.imcActual.toFixed(1)}${evalRes.imcObjetivo ? ` · objetivo: ${evalRes.imcObjetivo.toFixed(1)}` : ''}`
    : '';

  obGoTo('feedback');
}

async function obSubmitFinal() {
  const payload = {
    edad: document.getElementById('ob-edad').value,
    genero: document.getElementById('ob-genero').value,
    peso_actual: document.getElementById('ob-peso').value,
    peso_objetivo: document.getElementById('ob-peso-objetivo').value || null,
    altura: document.getElementById('ob-altura').value,
    objetivo: wizardState.objetivo,
    nivel_actividad: wizardState.nivel_actividad,
    lugar_entreno: wizardState.lugar_entreno,
    tipo_dieta: wizardState.tipo_dieta,
    alergias: wizardState.alergias,
  };

  const res = await Api.onboarding(payload);

  if (!res.success) {
    showToast(res.message || 'No se pudo guardar tu perfil.');
    return;
  }

  obResultado = res.data;
  renderResumenOnboarding(res.data);
  obGoTo('resumen');
}

function renderResumenOnboarding(datos) {
  const nombre = (window.NutriFitPendingUser || '').split(' ')[0] || '';
  document.getElementById('ob-resumen-titulo').textContent = nombre ? `¡Felicidades, ${nombre}!` : '¡Felicidades!';

  document.getElementById('ring-calorias').innerHTML = crearRingSVG({
    tamano: 168, grosor: 14, color: 'var(--color-lima)',
    valor: `${datos.meta_calorias.toLocaleString('es-AR')}`, unidad: 'kcal', tamanoTexto: 26,
  });

  const macros = [
    { label: 'Proteína', valor: datos.meta_proteinas, color: 'var(--color-xp)' },
    { label: 'Carbohidratos', valor: datos.meta_carbohidratos, color: 'var(--color-carbohidratos)' },
    { label: 'Grasas', valor: datos.meta_grasas, color: 'var(--color-grasas)' },
    { label: 'Fibra', valor: datos.meta_fibra, color: 'var(--color-fibra)' },
  ];
  document.getElementById('rings-macros').innerHTML = macros.map((m) => `
    <div class="ring-item">
      ${crearRingSVG({ tamano: 76, grosor: 7, color: m.color, valor: m.valor, unidad: 'g', tamanoTexto: 14 })}
      <span class="ring-label">${m.label}</span>
    </div>
  `).join('');

  lanzarConfetti(document.getElementById('ob-trophy'));
}

function crearRingSVG({ tamano, grosor, color, valor, unidad, tamanoTexto }) {
  const radio = (tamano - grosor) / 2;
  const centro = tamano / 2;
  return `
    <svg width="${tamano}" height="${tamano}" viewBox="0 0 ${tamano} ${tamano}">
      <circle cx="${centro}" cy="${centro}" r="${radio}" fill="none" stroke="var(--bg-inset)" stroke-width="${grosor}" />
      <circle cx="${centro}" cy="${centro}" r="${radio}" fill="none" stroke="${color}" stroke-width="${grosor}"
        stroke-linecap="round" stroke-dasharray="${2 * Math.PI * radio * 0.94} ${2 * Math.PI * radio}"
        transform="rotate(-90 ${centro} ${centro})" />
      <text x="${centro}" y="${centro}" text-anchor="middle" dominant-baseline="central"
        font-family="var(--font-display)" font-weight="700" font-size="${tamanoTexto}" fill="var(--text-primary)">${valor}</text>
      <text x="${centro}" y="${centro + tamanoTexto * 0.85}" text-anchor="middle"
        font-family="var(--font-body)" font-size="${tamanoTexto * 0.4}" fill="var(--text-secondary)">${unidad}</text>
    </svg>
  `;
}

function lanzarConfetti(anchorEl) {
  const colores = ['#8BE422', '#8B5CF6', '#EAB308', '#F97316', '#06B6D4'];
  const rect = anchorEl.getBoundingClientRect();
  for (let i = 0; i < 24; i++) {
    const pieza = document.createElement('div');
    pieza.className = 'confetti-piece';
    pieza.style.left = `${rect.left + rect.width / 2 + (Math.random() - 0.5) * 120}px`;
    pieza.style.background = colores[i % colores.length];
    pieza.style.animationDelay = `${Math.random() * 0.3}s`;
    document.body.appendChild(pieza);
    setTimeout(() => pieza.remove(), 2200);
  }
}

async function obFinish() {
  await bootApp();
}

// ---------- Arranque de la app tras login/onboarding ----------
async function bootApp() {
  const sesion = await Api.sesion();
  if (!sesion.success) {
    showScreen('screen-login');
    return;
  }

  showScreen('screen-app');
  renderUserChip(sesion.data);
  await loadView('progreso');

  actualizarBotonRecordatorios();
  if (!window.NutriFitRecordatoriosIniciados) {
    window.NutriFitRecordatoriosIniciados = true;
    chequearRecordatorios();
    setInterval(chequearRecordatorios, 15 * 60 * 1000); // cada 15 minutos
  }
}

function renderUserChip(usuario) {
  const initials = usuario.nombre.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  document.querySelectorAll('.user-chip .avatar-mini').forEach((el) => (el.textContent = initials));
  document.querySelectorAll('.user-chip .name').forEach((el) => (el.textContent = usuario.nombre));
  document.querySelectorAll('.user-chip .level').forEach((el) => (el.innerHTML = `Nivel ${usuario.nivel} · ${NutriCoinIcon(12)} ${usuario.nutri_coins}`));
  window.NutriFitUser = usuario;
}

document.addEventListener('DOMContentLoaded', async () => {
  initTheme();

  // Si se abrió desde un link de recuperación (?reset_token=...), va directo
  // a la pantalla de nueva contraseña, sin pisar la sesión activa si hubiera.
  const tokenReset = new URLSearchParams(window.location.search).get('reset_token');
  if (tokenReset) {
    document.getElementById('nueva-password-token').value = tokenReset;
    showScreen('screen-nueva-password');
    return;
  }

  // Intenta restaurar sesión activa (cookie PHP) al recargar.
  const sesion = await Api.sesion();
  if (sesion.success && sesion.data.onboarding_completo) {
    await bootApp();
  } else if (sesion.success) {
    window.NutriFitPendingUser = sesion.data.nombre;
    showScreen('screen-onboarding');
    resetWizard();
  } else {
    showScreen('screen-login');
  }
});
