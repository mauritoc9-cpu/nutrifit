/**
 * NutriFit — Capa de comunicación con la API PHP.
 * Ajusta API_BASE si tu proyecto vive en otra ruta dentro de XAMPP/htdocs.
 */
const API_BASE = '/nutrifittwo/backend/api';

async function apiRequest(path, { method = 'GET', body = null } = {}) {
  const options = {
    method,
    credentials: 'include', // envía la cookie de sesión PHP
    headers: {},
  };

  if (body !== null) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(`${API_BASE}${path}`, options);
  } catch (err) {
    return { success: false, message: 'No se pudo conectar con el servidor.', data: null };
  }

  const json = await response.json().catch(() => ({
    success: false,
    message: 'Respuesta inválida del servidor.',
    data: null,
  }));

  return json;
}

const Api = {
  registro: (nombre, email, password) =>
    apiRequest('/auth/registro.php', { method: 'POST', body: { nombre, email, password } }),

  login: (email, password) =>
    apiRequest('/auth/login.php', { method: 'POST', body: { email, password } }),

  logout: () => apiRequest('/auth/logout.php', { method: 'POST' }),

  sesion: () => apiRequest('/auth/sesion.php'),

  onboarding: (payload) =>
    apiRequest('/auth/onboarding.php', { method: 'POST', body: payload }),

  perfilGet: () => apiRequest('/auth/perfil.php'),

  solicitarReset: (email) =>
    apiRequest('/auth/solicitar_reset.php', { method: 'POST', body: { email } }),
  resetearPassword: (token, password) =>
    apiRequest('/auth/resetear_password.php', { method: 'POST', body: { token, password } }),

  dashboardDiario: (fecha) =>
    apiRequest(`/nutricion/dashboard_diario.php?fecha=${encodeURIComponent(fecha)}`),

  buscarAlimentos: (q) =>
    apiRequest(`/nutricion/buscar_alimentos.php?q=${encodeURIComponent(q)}`),

  registrarComida: (payload) =>
    apiRequest('/nutricion/registrar_comida.php', { method: 'POST', body: payload }),
  eliminarComida: (registroId) =>
    apiRequest('/nutricion/registrar_comida.php', { method: 'DELETE', body: { registro_id: registroId } }),

  analizarFoto: () => apiRequest('/nutricion/analizar_foto.php', { method: 'POST' }),

  hidratacionGet: () => apiRequest('/nutricion/hidratacion.php'),
  hidratacionSumar: () => apiRequest('/nutricion/hidratacion.php', { method: 'POST' }),

  listaCompras: () => apiRequest('/nutricion/lista_compras.php'),

  rutinaRecomendada: () => apiRequest('/entrenamiento/rutina_recomendada.php'),
  completarEntrenamiento: (rutinaId) =>
    apiRequest('/entrenamiento/completar_entrenamiento.php', { method: 'POST', body: { rutina_id: rutinaId } }),

  progresoPesoGet: () => apiRequest('/entrenamiento/progreso_peso.php'),
  progresoPesoPost: (peso) =>
    apiRequest('/entrenamiento/progreso_peso.php', { method: 'POST', body: { peso } }),

  pasosGet: () => apiRequest('/entrenamiento/pasos.php'),
  pasosSumar: (cantidad) =>
    apiRequest('/entrenamiento/pasos.php', { method: 'POST', body: { cantidad } }),

  ranking: () => apiRequest('/gamificacion/ranking.php'),
  amigosGet: () => apiRequest('/gamificacion/amigos.php'),
  amigosAgregar: (email) =>
    apiRequest('/gamificacion/amigos.php', { method: 'POST', body: { accion: 'agregar', email } }),
  amigosAceptar: (relacionId) =>
    apiRequest('/gamificacion/amigos.php', { method: 'POST', body: { accion: 'aceptar', relacion_id: relacionId } }),
  amigosRechazar: (relacionId) =>
    apiRequest('/gamificacion/amigos.php', { method: 'POST', body: { accion: 'rechazar', relacion_id: relacionId } }),

  tiendaGet: () => apiRequest('/gamificacion/tienda.php'),
  tiendaCanjear: (itemId) =>
    apiRequest('/gamificacion/tienda.php', { method: 'POST', body: { accion: 'canjear', item_id: itemId } }),
  tiendaEquipar: (itemId) =>
    apiRequest('/gamificacion/tienda.php', { method: 'POST', body: { accion: 'equipar', item_id: itemId } }),
};
