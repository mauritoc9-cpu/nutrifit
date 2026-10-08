# Guía de Actualización — NutriFit_Documentacion.docx

Este archivo contiene el contenido que debe estar en el documento de tesis actualizado.
Edita el DOCX manualmente en Word/Google Docs según esta guía.

---

## CARÁTULA

- Título: **NutriFit — App Gamificada de Nutrición, Hidratación y Entrenamiento**
- Subtítulo: Proyecto de Tesis de Informática
- Autor: Mauro Cerdan
- Institución: [Tu universidad]
- Año: 2026
- Email: nutrifit.argentina2026@gmail.com

---

## 1. FUNDAMENTACIÓN

NutriFit surge de la necesidad de crear herramientas tecnológicas que acompañen a las personas en su camino hacia hábitos saludables. La gamificación, la inteligencia artificial y los sistemas sociales son pilares modernos para aumentar la adherencia a programas de salud.

La aplicación integra:
- **Personalización**: cálculo automático de necesidades calóricas (Mifflin-St Jeor)
- **Motivación**: sistemas de puntos, niveles, logros y competencia social
- **Asistencia**: chatbot con contexto personalizado (datos del usuario)
- **Comunidad**: ranking, amigos, compartición de rutinas

---

## 2. PROBLEMÁTICA

### Contexto
Muchas personas desean mejorar su salud pero enfrentan barreras:
- **Falta de motivación**: sin retroalimentación inmediata, abandonan
- **Desconocimiento**: no saben cuánto comer ni si están progresando
- **Hábitos invisibles**: cuántos pasos, vasos de agua, calorías quemadas
- **Aislamiento**: entrenar solo es aburrido
- **Falta de asesoramiento**: guía incorrecta lleva a malos resultados

### Solución Actual
Las apps de salud existentes (MyFitnessPal, Strava, etc.) son especializadas pero no integran:
- Gamificación profunda (más allá de badges básicos)
- Contexto personalizado en IA
- Comunidad y competencia integrada
- Flujo user-friendly para móvil y desktop

---

## 3. SOLUCIÓN PROPUESTA

NutriFit es una plataforma integral que:

1. **Personaliza** metas según el perfil (TMB → GET)
2. **Registra** comidas en tiempo real (búsqueda + escaneo IA)
3. **Visualiza** progreso con gráficos interactivos (Chart.js)
4. **Motiva** mediante XP, niveles, rachas, logros y competencia
5. **Conecta** usuarios para entrenar juntos o competir
6. **Asiste** con chatbot personalizado (Gemini, contexto del usuario)

---

## 4. OBJETIVOS

### Generales
- Desarrollar una aplicación web gamificada que aumente la adherencia a hábitos saludables
- Integrar inteligencia artificial para análisis de fotos y asistencia contextualizada
- Crear un sistema social que motive la competencia y cooperación

### Específicos
- ✅ Implementar sistema de autenticación seguro (bcrypt + sesión)
- ✅ Calcular automáticamente necesidades calóricas personalizadas
- ✅ Integrar escaneo de alimentos con IA (Gemini Vision)
- ✅ Crear sistema de gamificación (XP, niveles, rachas, logros, retos)
- ✅ Implementar ranking global con ligas (Bronce/Plata/Oro/Diamante)
- ✅ Desarrollar sistema de amigos con solicitudes y validaciones
- ✅ Permitir compartición de rutinas mediante QR
- ✅ Agregar funcionalidad de "QR Social" para conectar usuarios
- ✅ Visualizar progreso con gráficos interactivos (Chart.js)
- ✅ Exportar reportes en PDF y CSV
- ✅ Integrar geolocalización para exploración de lugares
- ✅ Crear asistente chatbot con contexto personalizado
- ✅ Optimizar para mobile (375×812) y desktop (1366×768)

---

## 5. USUARIOS DESTINATARIOS

**Perfil primario**: Personas de 18-50 años interesadas en mejorar su salud
- Que registran comidas y actividad
- Que valoran motivación y comunidad
- Que quieren tracking visualizado

**Perfil secundario**: Instructores/nutricionistas
- Que desean motivar a clientes
- Que buscan herramientas de seguimiento colaborativo

**Actualmente soporta**: Todos los perfiles anteriores

---

## 6. TECNOLOGÍAS

| Componente | Tecnología | Justificación |
|---|---|---|
| **Backend** | PHP 8.2 OOP | Manejo de lógica, cálculos, IA |
| **Base de datos** | MySQL 5.7+ | Transacciones, integridad |
| **Frontend** | HTML5/CSS3/JS Vanilla | SPA sin dependencias pesadas |
| **Gráficos** | Chart.js 4.4.9 | Interactivo, ligeromulti-formato |
| **QR** | qrcode.js + qr-scanner | Generación y lectura sin servidor |
| **3D** | Canvas 2D/WebGL custom | Avatar renderizado localmente |
| **IA** | Gemini 2.0 Flash | Escaneo de fotos, chatbot personalizado |
| **Autenticación** | bcrypt + sesión PHP | Seguridad estándar |
| **Hosting (futuro)** | Heroku + JawsDB | Escalabilidad |

---

## 7. ARQUITECTURA

### Capas

**Presentación** (Frontend)
- SPA HTML5/CSS3/JS Vanilla
- 9 vistas: Progreso, Actividad, Mi Progreso, Nutrición, Escáner, Ranking, Perfil, Explorar, Asistente
- Responsive: mobile 375×812, desktop 1366×768

**Lógica** (Backend)
- PHP 8.2 OOP con PDO
- Controladores por módulo (auth, nutricion, entrenamiento, gamificacion, etc.)
- Services reutilizables (XP, Gamificación, Exportación, Rutinas Compartidas)

**Datos** (MySQL)
- 18+ tablas normalizadas
- Migraciones SQL versioned
- Índices en FK y búsquedas frecuentes

### Flujo de Datos

1. **Autenticación**: Usuario → Login (PHP) → Sesión → SPA
2. **Registro**: Comida → Fetch API → API PHP → Cálculos → DB → Respuesta JSON → Render
3. **IA**: Foto → Gemini Vision → Confirmación Usuario → Registro
4. **Visualización**: DB → Endpoint PHP → Datos JSON → Chart.js
5. **Exportación**: DB → Generación PDF/CSV → Descarga

---

## 8. BASE DE DATOS

### Esquema Relacional

**Tablas principales:**
- `usuarios` (id, nombre, email, password, xp_total, nivel, racha_dias, avatar_config)
- `onboarding` (usuario_id, altura, peso, objetivo, estilo_vida)
- `comidas` (id, usuario_id, fecha, alimento_id, cantidad_g, kcal, proteina, carbos, grasas)
- `rutinas_ejercicios` (id, usuario_id, titulo, lugar, nivel_dificultad)
- `ejercicios` (id, rutina_id, nombre, series, repeticiones, descanso_segundos)
- `amigos_ranking` (usuario_id, amigo_id, estado='pendiente'|'aceptado')
- `logros`, `retos`, `retos_completados` (gamificación)
- `tienda`, `cosmeticos_usuario` (avatar, accesorios)
- `rutina_comparticiones` (QR de rutinas: token_hash, expira_en)
- `perfiles_comparticiones` (QR social: usuario_id, token_hash)
- `asistente_conversaciones` (usuario_id, mensaje, respuesta, timestamp)

### Migraciones
18 migraciones aplicadas secuencialmente (001 → 019)
Última: `019_qr_social.sql` → Tabla `perfiles_comparticiones`

---

## 9. FUNCIONALIDADES

### 9.1 Autenticación & Perfil

**Registro**
- Email + contraseña
- Validación de formato
- Hash bcrypt (cost=12)

**Onboarding**
- Biometría: altura, peso, edad, género
- Objetivo: bajar de peso, mantener, ganar músculo
- Estilo: sedentario, moderadamente activo, muy activo
- Cálculo automático TMB (Mifflin-St Jeor) → GET → metas calóricas

**Perfil**
- Avatar 3D robot personalizable
- Accesorios cosméticos (marco, aura, título)
- Histórico de datos (peso, XP, logros)

### 9.2 Nutrición

**Dashboard Calórico**
- Anillo de progreso vs meta
- Breakdown de macros (proteína, carbos, grasas)

**Registro de Comidas**
- Búsqueda en catálogo de +1500 alimentos
- Cantidad en gramos
- Cálculos automáticos (kcal, macros)
- XP al registrar (+10 por comida)

**Escaneo IA**
- Foto → Gemini Vision
- Análisis automático (alimento + cantidad estimada)
- Confirmación manual antes de registrar
- Fallback: búsqueda manual si no reconoce

**Hidratación**
- Contador de vasos
- Meta personalizada
- XP (+5 cada 3 vasos)

**Recomendaciones**
- Próxima comida sugerida según déficit calórico
- Basada en histórico del usuario

**Favoritos & Lista de Compras**
- Almacenamiento de comidas frecuentes
- Creación de listas

### 9.3 Actividad & Entrenamiento

**Rutina Diaria**
- Ejercicios sugeridos (~25 XP al completar)
- Marcado individual de ejercicios
- Progreso visual

**Pasos & Actividad**
- Histórico de pasos
- Integración con datos (manual o API futura)

**Progreso de Peso**
- Gráfico histórico
- Tendencia a lo largo del tiempo

---

## 9.4 Mi Progreso (Estadísticas)

**Dashboard Visual**
- XP actual, nivel, racha de días
- Logros completados, retos activos

**Gráficos (Chart.js)**
- Línea: Peso (últimos 7/30/todos días)
- Barras: Actividad diaria (pasos, ejercicio)

**Exportación**
- PDF: Informe completo (período seleccionable)
- CSV: Datos para análisis

### 9.5 QR

#### A) QR de Rutinas
- **Crear**: Usuario selecciona rutina → genera código único (7 días de validez)
- **Código**: `NF-RUTINA-1:` + 64 hex (token en claro)
- **BD**: Hash SHA256 almacenado + contenido JSON + expiración
- **Preview**: Otros usuarios ven ejercicios sin spoilers
- **Importar**: Crea copia independiente de la rutina (no XP)
- **Revocar**: Invalida el código pero mantiene copias importadas

#### B) QR Social
- **Mi QR**: Código estable y regenerable (sin expiración)
- **Código**: `NF-SOCIAL-1:` + 64 hex (token en claro)
- **BD**: Hash SHA256 almacenado, único por usuario
- **Escaneo**: Cámara, imagen o código manual
- **Preview**: Muestra nombre, nivel (solo info pública)
- **Agregar**: Reutiliza flujo normal de solicitud de amistad
- **Seguridad**: NO expone email ni datos privados

---

## 9.6 Gamificación

**XP & Niveles**
- Ganancia: Comida (+10), Agua (+5 c/3 vasos), Ejercicio (+25)
- Límite diario: Configurable, anti-farming
- Nivel = cada 1000 XP (personalizable)
- Racha: Contador de días consecutivos

**Logros (Hitos)**
- Largo plazo: "100 comidas", "Nivel 10", etc.
- Insignia unlockable
- Persistencia en perfil

**Retos (Semanales)**
- Desafío semanal con meta
- Recompensa al completar

**Ranking Global**
- Usuarios por XP (primario)
- Ligas: Bronce, Plata, Oro, Diamante (secundario, rango de XP)
- Refresco de estado al cambio de nivel

**Ranking de Amigos**
- Solo usuarios aceptados
- Misma lógica de XP

**Tienda de Cosméticos**
- Moneda: NutriCoins (+100 por nivel alcanzado, una sola vez)
- Accesorios: Marco de avatar, aura, título personalizado
- Compra inmediata, persistencia en BD

---

## 9.7 Amigos

**Búsqueda por Email**
- Input de email del amigo
- Validaciones: existe, no es uno mismo, no duplicar solicitud

**QR Social**
- Escaneo de código del amigo
- Preview antes de confirmar

**Solicitud/Aceptación**
- Estado: `pendiente` o `aceptado`
- Visualización: Quién envió, quién recibió
- Opciones: Aceptar, Rechazar

**Visualización de Progreso**
- Ver XP, nivel, racha del amigo aceptado
- Ranking privado de amigos

---

## 9.8 Explorar (Geolocalización)

**Mapa Interactivo**
- Leaflet.js (o similar)
- Puntos de interés (POI): gimnasios, parques, nutricionistas
- Búsqueda por tipo
- Detalles: dirección, horarios, contacto

**Geolocalización**
- Ubicación actual del usuario (con permiso)
- Filtrado de lugares cercanos

---

## 9.9 Asistente NutriFit

**Chatbot Contextualizado**
- Pregunta usuario → Gemini API
- Contexto: peso actual, meta, historial comidas, nivel, objetivo
- Respuesta generada con IA

**Estado Actual**
- ✅ Factual/local: responde preguntas sobre nutrición, ejercicio, salud general
- ⏸️ Generación personalizada: deshabilitada por privacidad (requiere confirmar con usuario tratamiento de datos)

---

## 10. CRUD

### Creación (Create)

| Entidad | Endpoint | Datos |
|---|---|---|
| Usuario | `POST /auth/registro.php` | email, password, nombre |
| Comida | `POST /nutricion/registrar_comida.php` | alimento_id, cantidad_g, fecha |
| Amigo | `POST /gamificacion/amigos.php?accion=agregar` | email del amigo |
| Logro | Automático al cumplir meta | — |
| Comida Favorita | `POST /nutricion/favoritos.php?accion=agregar` | alimento_id |

### Lectura (Read)

| Entidad | Endpoint |
|---|---|
| Datos Usuario | `GET /auth/sesion.php` |
| Comidas del día | `GET /nutricion/dashboard_diario.php` |
| Amigos | `GET /gamificacion/amigos.php` |
| Ranking | `GET /gamificacion/ranking.php` |
| Logros | `GET /gamificacion/logros.php` |
| QR Rutina | `POST /entrenamiento/compartir_rutina.php?accion=preview` |

### Actualización (Update)

| Entidad | Endpoint | Datos |
|---|---|---|
| Perfil | `POST /auth/perfil.php` | nombre, avatar_config |
| Amistad | `POST /gamificacion/amigos.php?accion=aceptar` | relacion_id |
| Peso | `POST /entrenamiento/progreso_peso.php` | peso, fecha |

### Eliminación (Delete)

| Entidad | Endpoint |
|---|---|
| Comida | `DELETE /nutricion/registrar_comida.php` |
| Amigo | `POST /gamificacion/amigos.php?accion=rechazar` |
| Favorito | `POST /nutricion/favoritos.php?accion=eliminar` |
| Reto Completado | Automático si abandona |

---

## 11. AUTENTICACIÓN & SEGURIDAD

### Autenticación

**Login**
1. Email + contraseña
2. Búsqueda en BD
3. `password_verify()` (bcrypt)
4. Si válido: regenerar sesión, crear `$_SESSION['user_id']`
5. Cookie automática (HttpOnly, SameSite=Lax, Secure en HTTPS)

**Logout**
1. Destruir sesión
2. Limpiar cookie

**Recuperación**
1. Email → genera token (32 bytes, SHA256 hash en BD)
2. Token + email → endpoint de reset
3. Reset solo válido si token coincide y no expiró (24h)
4. **Pendiente**: envío de correo real (smtp configurada, no implementada)

### Seguridad

**Protecciones**
- ✅ Contraseñas: bcrypt (cost=12)
- ✅ Consultas: PDO con sentencias preparadas
- ✅ Escape: `htmlspecialchars()` en salidas
- ✅ CSRF: regeneración de sesión en login
- ✅ Autorización: verificación de ownership en servidor
- ✅ Tokens: impredecibles, hash almacenado

**Headers HTTP**
- `X-Frame-Options: DENY`
- `X-Content-Type-Options: nosniff`
- `Content-Security-Policy` (básico)

---

## 12. ESTADÍSTICAS & VISUALIZACIÓN (Chart.js)

**Gráfico de Peso**
- Tipo: Línea
- Eje X: Fechas (últimos 7/30/todos días)
- Eje Y: Peso (kg)
- Interactivo: hover muestra valor exacto

**Gráfico de Actividad**
- Tipo: Barras
- Eje X: Días de la semana (o últimos 7 días)
- Eje Y: Pasos/actividad
- Interactivo: click para detalles

**Resumen de Tiles**
- XP, Nivel, Racha
- Comparativa con período anterior

---

## 13. EXPORTACIÓN (PDF/CSV)

**PDF**
- Generador: DOMPDF
- Contenido: Usuario, período, comidas, XP, gráficos, resumen
- Descarga: `nutrifit-progreso-[fecha].pdf`

**CSV**
- Formato: comidas.csv con columnas (fecha, alimento, kcal, proteina, carbos, grasas)
- Importable en Excel/Sheets
- Descarga: `nutrifit-progreso-[fecha].csv`

---

## 14. BASE DE DATOS & INTEGRIDAD

**Normalización**
- 3NF: sin redundancias
- Claves foráneas con `ON DELETE CASCADE`

**Migraciones**
- Versionadas (001 → 019)
- Aditivas (no alteran datos anteriores)

**Índices**
- FK (usuario_id, amigo_id)
- Búsquedas (email, token_hash)
- Ordenamiento (xp_total DESC, fecha DESC)

---

## 15. RESPONSIVE

**Mobile (375×812)**
- Barra de navegación inferior (5 iconos)
- Tarjetas verticales
- Botones táctiles (48px mínimo)
- Sin scroll horizontal

**Desktop (1366×768+)**
- Barra lateral (opcional)
- Grillas de 2-3 columnas
- Más datos visibles

**Tablets (768×1024)**
- Intermedio
- Navegación adaptable

**Testeado en**
- Chrome 120+
- Firefox 121+
- Safari 17+
- Edge 120+

---

## 16. BACKLOG & ESTADO

**Total**: 43 historias de usuario
**Completadas**: 40
**En revisión**: 2
**Pendiente**: 0
**Mejora futura**: 1

| Funcionalidad | Estado |
|---|---|
| Autenticación | ✅ Completo |
| Nutrición | ✅ Completo |
| Escaneo IA | ✅ Completo |
| Entrenamiento | ✅ Completo |
| Actividad | ✅ Completo |
| Mi Progreso | ✅ Completo |
| Gamificación | ✅ Completo |
| QR Rutinas | ✅ Completo |
| QR Social | ✅ Completo (NUEVO) |
| Ranking | ✅ Completo |
| Amigos | ✅ Completo |
| Tienda | ✅ Completo |
| Avatar 3D | ✅ Completo |
| Explorar | ✅ Completo |
| Asistente | ⚠️ Parcial (factual ✅, generativo ⏸️) |
| Recuperación email | ⏸️ Incompleto (token ✅, SMTP ❌) |
| Accesorios 3D | 📌 Futuro |

---

## 17. MEJORAS FUTURAS

1. **Email real**: Configurar SMTP para recuperación de contraseña
2. **Modelos 3D**: Accesorios avatar en formato GLB/GLTF
3. **Notificaciones push**: Service Worker + VAPID
4. **Wearables**: Integración Apple Watch, Fitbit, Garmin
5. **Social avanzado**: Desafíos entre amigos, grupos, eventos
6. **Más alimentos**: USDA, Open Food Facts, código de barras
7. **Análisis avanzado**: ML para predicción de progreso, recomendaciones mejoradas

---

## 18. CONCLUSIÓN

NutriFit es una aplicación web funcional e integrada que demuestra:
- ✅ Arquitectura escalable (backend, frontend, BD)
- ✅ Seguridad (autenticación, validaciones, escape)
- ✅ Experiencia de usuario (responsive, gamificación, visualización)
- ✅ Inteligencia artificial (escaneo, chatbot contextualizado)
- ✅ Comunidad (amigos, ranking, compartición)

El proyecto es candidato a evolución hacia producción (deploy HTTPS, optimización, monitoreo).

---

**Última actualización**: 6 de octubre de 2026
**Versión**: 2.0 (incluye QR Social)
