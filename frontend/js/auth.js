/**
 * NutriFit — Autenticación, onboarding y modo oscuro.
 */

// ---------- Modo oscuro persistente ----------
function initTheme() {
  const saved = localStorage.getItem('nutrifit_theme') || 'light';
  document.documentElement.setAttribute('data-theme', saved);
}

function toggleTheme() {
  const current = document.documentElement.getAttribute('data-theme');
  const next = current === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('nutrifit_theme', next);
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
const OB_MENSAJES_OBJETIVO = {
  perder_grasa: '¡Vamos a bajar esos kilos de más, paso a paso!',
  mantener_peso: 'Mantener tu peso actual es un objetivo totalmente alcanzable.',
  ganar_musculo: '¡Vamos a construir músculo con un plan a tu medida!',
  monitorear_salud: 'Vamos a armar un plan que acompañe tu condición de salud.',
  mejorar_habitos: '¡Vamos a mejorar tus hábitos alimenticios, de a poco!',
};

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
  ['ob-edad', 'ob-peso', 'ob-peso-objetivo', 'ob-altura'].forEach((id) => { document.getElementById(id).value = ''; });
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
  document.querySelectorAll('.ob-chip').forEach((el) => {
    const idxChip = OB_SEGMENTOS.indexOf(el.dataset.chip);
    el.classList.toggle('done', idxSegmento > idxChip);
    el.classList.toggle('current', !esFeedback && idxSegmento === idxChip);
  });
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

function obToggleAlergia(checkboxEl) {
  const valor = checkboxEl.value;
  const todos = document.querySelectorAll('#ob-options-alergias input[type="checkbox"]');

  if (valor === 'ninguna' && checkboxEl.checked) {
    todos.forEach((el) => { if (el.value !== 'ninguna') el.checked = false; });
  } else if (checkboxEl.checked) {
    document.querySelector('#ob-options-alergias input[value="ninguna"]').checked = false;
  }

  wizardState.alergias = Array.from(todos).filter((el) => el.checked).map((el) => el.value);
}

function obSubmitBiometria() {
  const edad = document.getElementById('ob-edad').value;
  const peso = document.getElementById('ob-peso').value;
  const altura = document.getElementById('ob-altura').value;
  if (!edad || !peso || !altura) {
    showToast('Completá edad, peso y altura para continuar.');
    return;
  }

  const pesoObjetivo = parseFloat(document.getElementById('ob-peso-objetivo').value);
  const pesoActual = parseFloat(peso);
  let mensaje;
  if (pesoObjetivo && Math.abs(pesoActual - pesoObjetivo) >= 0.5) {
    const diff = Math.abs(pesoActual - pesoObjetivo).toFixed(1).replace(/\.0$/, '');
    const verbo = pesoActual > pesoObjetivo ? 'Perder' : 'Ganar';
    mensaje = `${verbo} ${diff} kg es un objetivo realista.<br>¡Vamos a lograrlo!`;
  } else {
    mensaje = OB_MENSAJES_OBJETIVO[wizardState.objetivo] || '¡Vamos a lograr tu objetivo, paso a paso!';
  }
  document.getElementById('ob-feedback-msg').innerHTML = mensaje;

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
  document.querySelectorAll('.user-chip .level').forEach((el) => (el.textContent = `Nivel ${usuario.nivel} · 🪙 ${usuario.nutri_coins}`));
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
