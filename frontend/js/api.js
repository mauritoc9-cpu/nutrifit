/**
 * NutriFit — Capa de comunicación con la API PHP.
 * Ajusta API_BASE si tu proyecto vive en otra ruta dentro de XAMPP/htdocs.
 */
const API_BASE = '/backend/api';

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

  analizarFoto: (imagenBase64) =>
    apiRequest('/nutricion/analizar_foto.php', { method: 'POST', body: { imagen_base64: imagenBase64 } }),

  hidratacionGet: () => apiRequest('/nutricion/hidratacion.php'),
  hidratacionSumar: () => apiRequest('/nutricion/hidratacion.php', { method: 'POST' }),
  hidratacionQuitar: () => apiRequest('/nutricion/hidratacion.php', { method: 'DELETE' }),

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

  // Módulo general de Actividad Física (caminata/carrera/ciclismo, y lo
  // que se sume después) — pasosGet/pasosSumar de arriba siguen iguales,
  // por compatibilidad, pero ya corren sobre este mismo motor por dentro.
  actividadTipos: () => apiRequest('/actividad/tipos.php'),
  actividadRegistrar: (payload) =>
    apiRequest('/actividad/registrar.php', { method: 'POST', body: payload }),
  actividadResumenDia: (fecha) =>
    apiRequest(`/actividad/resumen_dia.php${fecha ? `?fecha=${encodeURIComponent(fecha)}` : ''}`),
  actividadHistorial: (limite) =>
    apiRequest(`/actividad/historial.php${limite ? `?limite=${encodeURIComponent(limite)}` : ''}`),

  // Mi Progreso: resumen agregado (semanal por default) — misma capa de
  // datos que ya usan Home/Nutrición/Actividad, solo agregada por rango.
  resumenSemanal: (dias) =>
    apiRequest(`/progreso/resumen_semanal.php${dias ? `?dias=${encodeURIComponent(dias)}` : ''}`),
  resumenRango: (desde, hasta) =>
    apiRequest(`/progreso/resumen_semanal.php?desde=${encodeURIComponent(desde)}&hasta=${encodeURIComponent(hasta)}`),

  // Motor de Personalización (backend = fuente de verdad de las reglas).
  planPersonalizacion: (dias) =>
    apiRequest(`/personalizacion/plan.php${dias ? `?dias=${encodeURIComponent(dias)}` : ''}`),
  ejercicioDetalle: (id) =>
    apiRequest(`/entrenamiento/ejercicio.php?id=${encodeURIComponent(id)}`),
  recomendacionesAlimentos: () => apiRequest('/nutricion/recomendaciones.php'),
  receta: (momento, regenerar) =>
    apiRequest(`/nutricion/receta.php?momento=${encodeURIComponent(momento || '')}${regenerar ? '&regenerar=1' : ''}`),

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

  // Comidas favoritas
  favoritosGet: () => apiRequest('/nutricion/favoritos.php'),
  favoritosCrear: (payload) =>
    apiRequest('/nutricion/favoritos.php', { method: 'POST', body: payload }),
  favoritosActualizar: (favoritoId, payload) =>
    apiRequest(`/nutricion/favoritos.php?id=${encodeURIComponent(favoritoId)}`, { method: 'PUT', body: payload }),
  favoritosEliminar: (favoritoId) =>
    apiRequest(`/nutricion/favoritos.php?id=${encodeURIComponent(favoritoId)}`, { method: 'DELETE' }),
};
