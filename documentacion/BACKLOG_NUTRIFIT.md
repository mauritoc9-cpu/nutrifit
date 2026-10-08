# Backlog del Proyecto — NutriFit

## ¿Qué es este backlog?

El *backlog* es la lista ordenada de todo lo que el proyecto necesita hacer,
expresado como **historias de usuario** (lo que un usuario quiere lograr) junto
con las **tareas** técnicas que cada una implica. En NutriFit se usó para
organizar el desarrollo por áreas, priorizar qué construir primero y llevar el
seguimiento de qué estaba terminado, qué estaba en revisión y qué quedaba
pendiente. Este documento refleja el **estado real** del proyecto al momento de
la entrega (coherente con la auditoría final): no se marca como terminado nada
que no esté efectivamente implementado y probado.

### Estados

| Estado | Significado |
| --- | --- |
| **Completado** | Implementado de punta a punta (base de datos → backend → API → interfaz) y probado. |
| **En revisión** | Implementado en lo esencial, pero con una parte que depende de una decisión o configuración externa antes de darse por cerrado. |
| **Pendiente** | Planificado dentro del alcance del proyecto, todavía no hecho. |
| **Mejora futura** | Idea válida pero fuera del alcance de la tesis; no se implementa ahora. |

### Prioridades

**Alta** (núcleo del sistema) · **Media** (funcionalidad importante) · **Baja** (complementaria).

---

## 1. Autenticación

### HU-01 — Registro
Como persona nueva quiero crear una cuenta para guardar mi progreso.
- Formulario de registro con validaciones.
- Endpoint `registro.php` con hash de contraseña (bcrypt).
- Persistencia en la tabla `usuarios` y creación de sesión.
- **Prioridad:** Alta · **Estado:** Completado

### HU-02 — Inicio de sesión
Como usuario registrado quiero iniciar sesión para acceder a mis datos.
- Validación de credenciales (`password_verify`).
- Endpoint `login.php`.
- **Prioridad:** Alta · **Estado:** Completado

### HU-03 — Gestión de sesiones
Como usuario quiero que mi sesión sea segura mientras uso la app.
- Regeneración del identificador de sesión al autenticarse.
- Cookies `HttpOnly` / `SameSite=Lax` / `Secure` bajo HTTPS.
- Cierre de sesión que limpia la cookie.
- **Prioridad:** Alta · **Estado:** Completado

### HU-04 — Recuperación de contraseña
Como usuario quiero recuperar el acceso si olvido mi contraseña.
- Generación de token seguro, guardado **hasheado** (SHA-256), expiración 30 min y uso único.
- Validación del token y cambio de contraseña.
- *Pendiente:* envío real del enlace por correo (no hay transporte de email configurado todavía).
- **Prioridad:** Media · **Estado:** En revisión

---

## 2. Perfil / Onboarding

### HU-05 — Onboarding
Como usuario nuevo quiero cargar mis datos y mi objetivo para recibir un plan adaptado.
- Cuestionario de datos biométricos (edad, peso, altura, género, actividad).
- Cálculo de TMB/GET y metas de calorías y macros (Mifflin-St Jeor).
- Persistencia en `perfiles_biometricos`.
- **Prioridad:** Alta · **Estado:** Completado

### HU-06 — Edición de perfil
Como usuario quiero editar mis datos y objetivos cuando cambien.
- Modal de edición de perfil con las mismas validaciones del onboarding.
- Actualización que recalcula metas automáticamente.
- **Prioridad:** Media · **Estado:** Completado

---

## 3. Nutrición

### HU-07 — Registro de comidas (diario)
Como usuario quiero registrar lo que como para controlar mis calorías y macros.
- Alta de comida por tipo (desayuno/almuerzo/merienda/cena/snack) y gramos.
- Cálculo de aporte nutricional y diario del día.
- Eliminar un registro del diario.
- **Prioridad:** Alta · **Estado:** Completado

### HU-08 — Búsqueda de alimentos
Como usuario quiero buscar alimentos para agregarlos fácilmente.
- Motor que combina catálogo local + USDA + Open Food Facts + Codulia.
- Resultados filtrados según dieta/restricciones del usuario.
- **Prioridad:** Alta · **Estado:** Completado

### HU-09 — Favoritos
Como usuario quiero guardar comidas frecuentes para reutilizarlas.
- Guardar como favorito (simple o compuesto, ej. "Mi licuado").
- Usar un favorito para registrarlo en el día; eliminar favorito.
- *Pendiente:* botón de edición de un favorito desde la interfaz (la API de edición existe).
- **Prioridad:** Media · **Estado:** Completado

### HU-10 — Escáner de alimentos con IA
Como usuario quiero sacar una foto de mi comida para registrarla sin buscarla a mano.
- Análisis de imagen con IA de visión (Gemini) y reconocimiento local (MobileNet) como respaldo.
- Detección de varios alimentos, estimación de cantidad y resolución nutricional.
- **Prioridad:** Alta · **Estado:** Completado

### HU-11 — Hidratación
Como usuario quiero registrar los vasos de agua para cumplir mi meta diaria.
- Sumar/quitar vasos con meta diaria y recompensa de XP controlada.
- **Prioridad:** Media · **Estado:** Completado

### HU-12 — Recetas personalizadas
Como usuario quiero recetas acordes a mi dieta y objetivo.
- Generación con IA (Gemini) validando ingredientes contra el catálogo.
- Respaldo determinista si la IA no está disponible.
- Agregar la receta al día.
- **Prioridad:** Media · **Estado:** Completado

---

## 4. Entrenamiento

### HU-13 — Rutina recomendada
Como usuario quiero una rutina adaptada a mi objetivo, nivel y lugar.
- Generación personalizada desde el catálogo de ejercicios (casa/gimnasio, nivel, equipamiento).
- Detalle de cada ejercicio (series, repeticiones, descanso, instrucciones, errores comunes).
- **Prioridad:** Alta · **Estado:** Completado

### HU-14 — Completar ejercicios individuales
Como usuario quiero marcar cada ejercicio que hago, no solo la rutina entera.
- Marcado/desmarcado individual persistido por día.
- No otorga XP por sí solo (evita *farming*).
- **Prioridad:** Media · **Estado:** Completado

### HU-15 — Completar entrenamiento
Como usuario quiero registrar que completé mi entrenamiento y ganar XP.
- Registro del entrenamiento del día con protección anti-duplicado.
- Otorgamiento de XP con tope correcto.
- **Prioridad:** Alta · **Estado:** Completado

### HU-16 — Actividad física y pasos
Como usuario quiero registrar caminata, carrera o ciclismo y mis pasos.
- Tipos de actividad con cálculo de calorías (MET × peso × tiempo).
- Podómetro por acelerómetro del navegador y carga manual.
- Protección contra registros duplicados.
- **Prioridad:** Media · **Estado:** Completado

---

## 5. Progreso

### HU-17 — Historial de peso
Como usuario quiero registrar mi peso y ver su evolución.
- Registro de peso por fecha y comparación con el objetivo.
- **Prioridad:** Media · **Estado:** Completado

### HU-18 — Estadísticas con Chart.js
Como usuario quiero ver gráficos de mi evolución con datos reales.
- Gráfico de línea de peso y gráfico de barras de actividad (Chart.js), con datos reales.
- Manejo de 0/1/varios registros; responsive; tooltips y etiquetas en español.
- **Prioridad:** Alta · **Estado:** Completado

### HU-19 — Exportación de progreso (PDF/CSV)
Como usuario quiero exportar mi progreso para guardarlo o compartirlo.
- Informe PDF (Dompdf) y CSV, por período 7/30/historial, con datos reales y autenticación.
- **Prioridad:** Alta · **Estado:** Completado

---

## 6. Gamificación

### HU-20 — XP y niveles
Como usuario quiero ganar experiencia y subir de nivel por mis acciones saludables.
- XP por comida, agua, pasos, actividad y entrenamiento, con topes anti-*farming*.
- Cálculo de nivel por fórmula escalonada.
- **Prioridad:** Alta · **Estado:** Completado

### HU-21 — Racha diaria
Como usuario quiero mantener una racha de días activos.
- Cálculo de racha con vigencia por fecha (zona horaria de Argentina).
- **Prioridad:** Media · **Estado:** Completado

### HU-22 — Logros
Como usuario quiero desbloquear logros por mis hitos.
- 8 logros reales persistentes, desbloqueo automático y recompensa una sola vez.
- **Prioridad:** Media · **Estado:** Completado

### HU-23 — Retos semanales
Como usuario quiero retos con progreso real y recompensa.
- 5 retos semanales calculados en vivo desde datos reales; recompensa única por semana.
- **Prioridad:** Media · **Estado:** Completado

### HU-24 — Ranking global
Como usuario quiero comparar mi XP con el resto en ligas.
- Ranking por ligas según XP total.
- **Prioridad:** Media · **Estado:** Completado

### HU-25 — Tienda (NutriCoins)
Como usuario quiero canjear objetos con las monedas que gano.
- Compra y equipamiento de objetos con descuento real de monedas/XP y validaciones (nivel, saldo).
- **Prioridad:** Media · **Estado:** Completado

---

## 7. Social

### HU-26 — Amigos
Como usuario quiero agregar amigos para acompañarnos.
- Enviar/aceptar/rechazar solicitudes; un vínculo único por par.
- **Prioridad:** Media · **Estado:** Completado

### HU-27 — Ranking de amigos
Como usuario quiero ver un ranking solo entre mis amigos.
- Tabla de XP/nivel del usuario y sus amigos aceptados; estado vacío con invitación a agregar.
- **Prioridad:** Media · **Estado:** Completado

---

## 8. Avatar

### HU-28 — Avatar 3D
Como usuario quiero un avatar 3D que me represente.
- Modelo 3D con Three.js que reacciona a eventos (subir de nivel, celebrar).
- **Prioridad:** Baja · **Estado:** Completado

### HU-29 — Personalización del avatar
Como usuario quiero personalizar mi avatar con objetos cosméticos.
- Tinte de ropa, aura, marco de perfil y título, equipables y persistentes.
- **Prioridad:** Baja · **Estado:** Completado

### HU-30 — Accesorios 3D independientes
Como usuario quiero accesorios 3D (lentes, gorras) sobre el avatar.
- Requiere modelos/puntos de anclaje 3D que todavía no existen; no se simula un efecto inexistente.
- **Prioridad:** Baja · **Estado:** Mejora futura

---

## 9. Explorar (Mapa)

### HU-31 — Mapa con lugares reales
Como usuario quiero ver en un mapa lugares útiles cerca.
- Leaflet + OpenStreetMap + Overpass; lugares reales, marcadores y tarjetas.
- **Prioridad:** Media · **Estado:** Completado

### HU-32 — Búsqueda y filtros
Como usuario quiero buscar por localidad y filtrar por categoría.
- Búsqueda de localidad (Photon) y filtros: gimnasios, comida saludable, suplementos, deportes.
- "Cómo llegar" abre la navegación externa con el destino real.
- **Prioridad:** Media · **Estado:** Completado

### HU-33 — Geolocalización
Como usuario quiero que el mapa use mi ubicación si lo permito.
- Pedido de ubicación por botón (no al iniciar), con todos los estados de permiso.
- GPS redondeado y no almacenado; funciona igual con búsqueda manual si se deniega.
- *Nota:* validado en navegador; falta probar permiso en un teléfono físico.
- **Prioridad:** Baja · **Estado:** Completado

---

## 10. QR de rutinas

### HU-34 — Compartir rutina por QR
Como usuario quiero compartir una rutina mía mediante un código QR.
- Generación de QR con una instantánea de la rutina (no es un enlace a una web).
- **Prioridad:** Media · **Estado:** Completado

### HU-35 — Importar rutina desde QR
Como usuario quiero importar la rutina de otra persona escaneando su QR.
- Lectura por cámara o imagen, vista previa e importación como copia propia independiente.
- No otorga XP por importar; detecta duplicados.
- **Prioridad:** Media · **Estado:** Completado

### HU-36 — Seguridad del QR
Como usuario quiero que compartir una rutina sea seguro.
- Token impredecible (256 bits), guardado hasheado, expiración de 7 días y revocación.
- **Prioridad:** Alta · **Estado:** Completado

---

## 11. Asistente NutriFit (IA)

### HU-37 — Consultas sobre datos reales
Como usuario quiero preguntarle al asistente sobre mi propio progreso.
- Clasificación local de la intención y respuesta con datos reales (calorías, agua, pasos, rutina, racha).
- **Prioridad:** Media · **Estado:** Completado

### HU-38 — Historial del asistente
Como usuario quiero conservar mis conversaciones.
- Guardado de conversaciones y mensajes con propiedad por usuario y retención.
- **Prioridad:** Baja · **Estado:** Completado

### HU-39 — Voz (dictado y lectura)
Como usuario quiero dictar mis preguntas y escuchar las respuestas.
- Dictado (reconocimiento de voz, es-AR) y lectura con voz local; no guarda audio.
- **Prioridad:** Baja · **Estado:** Completado

### HU-40 — Orientación generativa personalizada
Como usuario quiero consejos generados por IA sobre mis hábitos.
- Integración con Gemini lista, pero **deshabilitada por privacidad** (`ASISTENTE_GEMINI_DATOS_HABILITADOS=0`): no se envían datos personales de salud hasta confirmar facturación y tratamiento de datos. Mientras tanto responde con datos locales.
- **Prioridad:** Baja · **Estado:** En revisión

---

## 12. Responsive y Seguridad

### HU-41 — Diseño responsive
Como usuario quiero usar la app cómodamente en celular y en computadora.
- Interfaz verificada en 375×812 (mobile) y 1366×768 (desktop), sin scroll horizontal.
- **Prioridad:** Alta · **Estado:** Completado

### HU-42 — Seguridad de la aplicación
Como usuario quiero que mis datos estén protegidos.
- Endpoints protegidos con sesión, verificación de propiedad (cada usuario solo ve lo suyo).
- Escape de datos (prevención de XSS) y consultas preparadas (PDO).
- **Prioridad:** Alta · **Estado:** Completado

---

## 13. Mejora Post-Cierre: QR Social

### HU-43 — Agregar amigos por QR
Como usuario quiero compartir un código QR para que otros me agreguen como amigo sin necesidad de conocer mi email.
- Generar token público estable (impredecible, sin expiración) en tabla `perfiles_comparticiones`.
- Mostrar "Mi QR" con opción de regeneración en módulo Amigos.
- Escanear QR de otro usuario usando cámara o subir imagen.
- Preview con info pública (nombre, nivel, racha si aplica).
- Reutilizar flujo de solicitud de amistad existente.
- Validaciones: no auto-amistad, no duplicados, solo info pública.
- **Prioridad:** Media (mejora de experiencia) · **Estado:** Completado
  - ✅ Migración 019 creada (`perfiles_comparticiones` con token_hash UNIQUE).
  - ✅ Endpoint POST `/gamificacion/amigos_qr.php` (generar, preview).
  - ✅ Modificación de `/gamificacion/amigos.php` para acción `agregar-por-qr`.
  - ✅ Frontend `/js/amigos-qr.js` con generador/escáner de QR (reutiliza qrcode.js y qr-scanner).
  - ✅ Botones "Mi QR" y "Escanear QR" en vista Ranking (tab Amigos).
  - ✅ Script cargado en index.html, integrado con cierre al cambiar de vista.
  - ✅ Tokens seguros: 32 bytes → 64 hex, solo hash almacenado en BD, flujo no otorga amistad automática.

---

## Resumen

**Total de historias de usuario: 43**

| Estado | Cantidad |
| --- | --- |
| Completado | 40 |
| En revisión | 2 |
| Pendiente | 0 |
| Mejora futura | 1 |

> Nota: además de la mejora futura (HU-30, accesorios 3D), hay **pendientes puntuales** dentro de historias ya completadas: el **transporte de email** de la recuperación de contraseña (HU-04, En revisión) y el **botón de edición de favoritos** desde la interfaz (HU-09). El **canje de cupones en comercios (Beneficios)** también queda como mejora futura fuera del alcance de la tesis.

### Funcionalidades que NO se marcaron como completas (y por qué)
- **Recuperación de contraseña (HU-04)** → *En revisión*: la lógica de tokens seguros funciona, pero falta configurar el envío real del correo.
- **Orientación generativa del Asistente (HU-40)** → *En revisión*: está implementada pero **deshabilitada a propósito** por privacidad (no se envían datos de salud a un servicio externo hasta confirmar facturación/tratamiento de datos).
- **Accesorios 3D independientes (HU-30)** → *Mejora futura*: necesitan modelos 3D que aún no existen; no se finge un efecto inexistente.
- **Canje de cupones / Beneficios en comercios** → *Mejora futura*: fuera del alcance actual.
- **Edición de favoritos desde la interfaz** → *Pendiente puntual* dentro de HU-09 (la API ya existe).

---

## Próximos pasos antes de la presentación

Estos son los pendientes **reales de cierre de la tesis** (no funcionalidades nuevas):

1. **Actualizar la documentación** — en especial el README (describe el escáner como simulado y menciona solo 4 pestañas; hoy el escáner usa IA real y hay ~9 secciones).
2. **Actualizar el repositorio** — commit y push del trabajo actual (hay cambios locales sin subir que no están reflejados en el repositorio compartido).
3. **Deploy** — publicar el proyecto en un entorno online con HTTPS (necesario para cámara y geolocalización).
4. **Pruebas finales** — validación de los flujos principales en el entorno desplegado y en un teléfono físico.
