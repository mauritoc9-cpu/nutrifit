# ANÁLISIS: REDISEÑO DE NAVEGACIÓN NUTRIFIT

**Fecha**: 7 de octubre de 2026\
**Alcance**: Reestructuración de arquitectura de 7→4 pestañas + botón flotante

---

## 1. ESTRUCTURA ACTUAL

### Navegación Principal (Bottom Nav + Menu Lateral)

**9 VISTAS totales**, que se mapean a:

| Vista | Nombre mostrado | Tipo | Archivo render |
|-------|---|---|---|
| `progreso` | Progreso (Home) | Nav principal | `renderProgreso()` - app.js:294 |
| `actividad` | Actividad | Nav principal | `renderActividad()` - app.js:595 |
| `nutricion` | Nutrición | Nav principal | `renderNutricion()` - app.js:2856 |
| `escaner` | Escáner | Nav principal | `renderEscaner()` - app.js:3324 |
| `mi_progreso` | Mi Progreso | Nav principal | `renderMiProgreso()` - app.js:953 |
| `ranking` | Ranking | Nav principal | `renderRanking()` - app.js:3977 |
| `mi_perfil` | Perfil | Nav principal | `renderMiPerfil()` - app.js:1231 |
| `asistente` | Asistente | Nav principal | `Asistente.render()` - asistente.js |
| `explorar` | Explorar | Nav principal | `Explorar.render()` - explorar.js |

**2 BOTONES adicionales** (no son pestañas):
- "Editar perfil" → `abrirModalEditarPerfil()`
- "Recordatorios" → `toggleRecordatorios()`

### HTML de Navegación

**Bottom Nav** (línea 364-390 en index.html):
```html
<nav class="bottom-nav">
  <div class="nav-item active" data-view="progreso" onclick="loadView('progreso')">...</div>
  <div class="nav-item" data-view="actividad" onclick="loadView('actividad')">...</div>
  <div class="nav-item" data-view="nutricion" onclick="loadView('nutricion')">...</div>
  <div class="nav-item" data-view="escaner" onclick="loadView('escaner')">...</div>
  <div class="nav-item" data-view="mi_progreso" onclick="loadView('mi_progreso')">...</div>
  <div class="nav-item" data-view="ranking" onclick="loadView('ranking')">...</div>
  <div class="nav-item" data-view="mi_perfil" onclick="loadView('mi_perfil')">...</div>
  <div class="nav-item" data-view="asistente" onclick="loadView('asistente')">...</div>
  <div class="nav-item" data-view="explorar" onclick="loadView('explorar')">...</div>
</nav>
```

**Menu Lateral** (para desktop/tablet):
```html
<div class="sidebar-nav">
  <li class="nav-item active" data-view="progreso" onclick="loadView('progreso')">...</li>
  <li class="nav-item" data-view="actividad" onclick="loadView('actividad')">...</li>
  <!-- ... mismos items ... -->
  <button class="nav-item" style="width:100%;" onclick="abrirModalEditarPerfil()">...</button>
  <button class="nav-item" id="btn-recordatorios" style="width:100%;" onclick="toggleRecordatorios()">...</button>
</div>
```

### Función de carga de vistas

**app.js línea 105-133**:
```javascript
async function loadView(viewName) {
  if (!VIEWS.includes(viewName)) return;

  // 1. Activa vista HTML (quita .active de otras, la pone en viewName)
  document.querySelectorAll('.view').forEach(el => el.classList.remove('active'));
  document.getElementById(`view-${viewName}`).classList.add('active');

  // 2. Actualiza nav items
  document.querySelectorAll('.nav-item[data-view]').forEach(el => {
    el.classList.toggle('active', el.dataset.view === viewName);
  });

  // 3. Limpia recursos (camera, modales, charts)
  stopCamera();
  if (viewName !== 'asistente' && typeof Asistente !== 'undefined') Asistente.salir();
  // ... más limpiezas ...

  // 4. Renderiza vista
  if (viewName === 'progreso') await renderProgreso();
  if (viewName === 'actividad') await renderActividad();
  // ... etc ...
}
```

**Constante VIEWS (app.js línea 12)**:
```javascript
const VIEWS = ['progreso', 'actividad', 'mi_progreso', 'nutricion', 'escaner', 'ranking', 'mi_perfil', 'explorar', 'asistente'];
```

---

## 2. MAPEO: NUEVO DISEÑO

### Estructura propuesta

```
BOTTOM NAV (4 pestañas principales)
├─ Inicio
├─ Nutrición
├─ Progreso
└─ Perfil

BOTÓN FLOTANTE (siempre disponible)
└─ Asistente
```

### Mapeo de vistas a nuevas secciones

#### SECCIÓN 1: INICIO
**Vista única**: `inicio` (renombrada de `progreso`)

**Contenido**:
- Resumen HOY: calorías/meta, agua/meta, pasos/meta
- Entrenamiento del día
- Racha y nivel (discreto)
- **Acciones rápidas** (+Comida, +Agua, +Actividad, Escanear)

**Función renderizado**:
- Renombrar `renderProgreso()` → `renderInicio()`
- Mantener lógica existente (ya tiene resumen HOY)
- Agregar acciones rápidas

---

#### SECCIÓN 2: NUTRICIÓN
**Vista principal**: `nutricion` (mantener nombre)

**Contenido que SE AGRUPA aquí**:
- ✅ Diario/comidas (ya está)
- ✅ Búsqueda de alimentos (ya está)
- ✅ Favoritos (ya está)
- ✅ Hidratación (ya está)
- ✅ Recetas (ya está)
- ✅ Información nutricional (ya está)
- ➕ **ESCÁNER IA** (se mueve de pestaña a subsección destacada)

**Función renderizado**:
- Mantener `renderNutricion()` - app.js:2856
- Incorporar interfaz escáner como botón/acción destacada
- Reutilizar `renderEscaner()` como sub-función dentro de nutrición
- **No duplicar**: el escáner sigue siendo la misma cámara/lógica

**Estado de `escaner` vista**:
- ❌ Se elimina como pestaña independiente
- ✅ La lógica sigue disponible (dentro de Nutrición)
- ✅ Acceso rápido desde Inicio

---

#### SECCIÓN 3: PROGRESO
**Vista principal**: `mi_progreso` (mantener nombre, pero es ahora "Progreso")

**Contenido que SE AGRUPA aquí**:
- ✅ Peso (ya está)
- ✅ Chart.js: línea + barras (ya está)
- ✅ Objetivo (ya está)
- ✅ Estadísticas (ya está)
- ➕ **ACTIVIDAD** (se mueve de pestaña a subsección)
- ➕ Pasos histórico
- ➕ Entrenamientos realizados
- ➕ Historial
- ➕ Estadísticas semanales/mensuales
- ➕ Hidratación histórica

**Función renderizado**:
- Mantener `renderMiProgreso()` - app.js:953 (ya tiene Chart.js)
- Incorporar `renderActividad()` como subsección
- **No duplicar**: actividad sigue siendo la misma función

**Estado de `actividad` vista**:
- ❌ Se elimina como pestaña independiente
- ✅ La lógica sigue disponible (dentro de Progreso)
- ✅ Registro rápido desde Inicio

---

#### SECCIÓN 4: PERFIL
**Vista principal**: `mi_perfil` (mantener nombre)

**Contenido que SE AGRUPA aquí** (en formato lista/filas, NO tarjetas enormes):

**Área superior** (siempre visible):
- Avatar 3D
- Nombre
- Nivel
- Botón "Personalizar avatar"

**Secciones expandibles/menú**:

```
MI CUENTA
├─ Datos personales
└─ Objetivo

PERSONALIZACIÓN
├─ Avatar (enlace a modal existente)
└─ Mi colección (accesorios)

COMUNIDAD
├─ Amigos (se mueve de Ranking)
└─ Ranking (se mueve de pestaña principal)

RECOMPENSAS
├─ Logros (se mueve de Ranking)
├─ Retos (se mueve de Ranking)
└─ Tienda (se mueve de Ranking)

CONFIGURACIÓN
├─ Tema
├─ Preferencias
└─ Cuenta (logout, etc.)
```

**Función renderizado**:
- Mantener `renderMiPerfil()` - app.js:1231
- Incorporar sub-renders: `renderRanking()`, `renderAmigos()`, etc.
- Cambiar layout de tarjetas a menú de lista

**Estado de `ranking` vista**:
- ❌ Se elimina como pestaña independiente
- ✅ La lógica sigue disponible (dentro de Perfil → Comunidad)
- ✅ Funciones existentes: `renderRanking()`, `renderRankingAmigos()`

---

#### SECCIÓN 5: ASISTENTE (Botón Flotante)
**Vista**: `asistente` (mantener, pero NO en bottom nav)

**Ubicación**:
- **Mobile**: Botón flotante (FAB) sobre bottom nav, respeta safe-area
- **Desktop**: Panel lateral/flotante fijo

**Función renderizado**:
- Mantener `Asistente.render()` - asistente.js
- Cambiar trigger de tab click a FAB click
- Reutilizar completamente la lógica existente

---

#### SECCIÓN 6: EXPLORAR (¿Dónde?)
**DECISIÓN REQUERIDA**: El usuario aún no ha especificado dónde va "Explorar".

**Opciones**:
1. Incluir en Perfil como subsección (COMUNIDAD o nueva sección LUGARES)
2. Mantener como pestaña (llegaría a 5 pestañas)
3. Acceso mediante modal desde Inicio
4. Asignarlo temporalmente y confirmarlo después

**Recomendación**: Incluir en Perfil como "EXPLORAR → Mapa" dentro de COMUNIDAD o como sección nueva.

---

## 3. CAMBIOS TÉCNICOS REQUERIDOS

### 3.1 Cambios en `app.js`

**Cambio 1: Actualizar VIEWS**
```javascript
// ANTES:
const VIEWS = ['progreso', 'actividad', 'mi_progreso', 'nutricion', 'escaner', 'ranking', 'mi_perfil', 'explorar', 'asistente'];

// DESPUÉS:
const VIEWS = ['inicio', 'nutricion', 'progreso', 'perfil', 'asistente', 'explorar'];
// Nota: 'actividad', 'escaner', 'ranking' desaparecen de aquí (siguen siendo funciones)
```

**Cambio 2: Renombrar función principal**
```javascript
// ANTES:
async function renderProgreso() { ... }

// DESPUÉS:
async function renderInicio() { ... }
```

**Cambio 3: Actualizar loadView()**
```javascript
// ANTES:
if (viewName === 'progreso') await renderProgreso();
if (viewName === 'actividad') await renderActividad();
if (viewName === 'escaner') renderEscaner();
if (viewName === 'ranking') await renderRanking();

// DESPUÉS:
if (viewName === 'inicio') await renderInicio();
if (viewName === 'nutricion') await renderNutricion();
if (viewName === 'progreso') await renderProgreso();
if (viewName === 'perfil') await renderMiPerfil();
if (viewName === 'asistente') await Asistente.render();
// Nota: actividad, escaner, ranking NO cargan como views (se llaman desde otras vistas)
```

**Cambio 4: Limpiezas en loadView() — Remover comprobaciones de vistas eliminadas**
```javascript
// REMOVER:
if (viewName !== 'ranking' && typeof AmigosQR !== 'undefined') AmigosQR.cerrar();
if (viewName !== 'mi_progreso' && typeof ExportarProgreso !== 'undefined') ExportarProgreso.salir();
```

### 3.2 Cambios en `index.html`

**Cambio 1: Renombrar vista HTML de progreso**
```html
<!-- ANTES: -->
<div id="view-progreso" class="view active"></div>
<div id="view-actividad" class="view"></div>

<!-- DESPUÉS: -->
<div id="view-inicio" class="view active"></div>
<!-- view-actividad DESAPARECE (no es vista independiente) -->
```

**Cambio 2: Actualizar Bottom Nav**
```html
<!-- ANTES: 9 items -->
<nav class="bottom-nav">
  <div class="nav-item" data-view="progreso">...</div>
  <div class="nav-item" data-view="actividad">...</div>
  <div class="nav-item" data-view="nutricion">...</div>
  <div class="nav-item" data-view="escaner">...</div>
  <div class="nav-item" data-view="mi_progreso">...</div>
  <div class="nav-item" data-view="ranking">...</div>
  <div class="nav-item" data-view="mi_perfil">...</div>
  <div class="nav-item" data-view="asistente">...</div>
  <div class="nav-item" data-view="explorar">...</div>
</nav>

<!-- DESPUÉS: 4 items principales + 1 FAB -->
<nav class="bottom-nav">
  <div class="nav-item active" data-view="inicio" onclick="loadView('inicio')">
    <span class="icon" data-icon="home"></span>
    <span class="label">Inicio</span>
  </div>
  <div class="nav-item" data-view="nutricion" onclick="loadView('nutricion')">
    <span class="icon" data-icon="utensils"></span>
    <span class="label">Nutrición</span>
  </div>
  <div class="nav-item" data-view="progreso" onclick="loadView('progreso')">
    <span class="icon" data-icon="chart-line"></span>
    <span class="label">Progreso</span>
  </div>
  <div class="nav-item" data-view="perfil" onclick="loadView('perfil')">
    <span class="icon" data-icon="user"></span>
    <span class="label">Perfil</span>
  </div>
</nav>

<!-- FAB Asistente (flotante) -->
<button class="fab fab-asistente" onclick="loadView('asistente')" title="Abrir Asistente">
  <span class="icon" data-icon="bot"></span>
</button>
```

**Cambio 3: Actualizar vistas HTML**
```html
<!-- ANTES: 9 vistas -->
<div id="view-progreso" class="view active"></div>
<div id="view-actividad" class="view"></div>
<div id="view-mi_progreso" class="view"></div>
<div id="view-nutricion" class="view"></div>
<div id="view-escaner" class="view"></div>
<div id="view-ranking" class="view"></div>
<div id="view-mi_perfil" class="view"></div>
<div id="view-explorar" class="view"></div>
<div id="view-asistente" class="view"></div>

<!-- DESPUÉS: 5 vistas (las antiguas subsecciones no necesitan div separado) -->
<div id="view-inicio" class="view active"></div>
<div id="view-nutricion" class="view"></div>
<div id="view-progreso" class="view"></div>
<div id="view-perfil" class="view"></div>
<div id="view-asistente" class="view"></div>
<div id="view-explorar" class="view"></div> <!-- Temporal: decidir ubicación -->
```

### 3.3 Cambios en las funciones render

**renderInicio()** (renombrada de renderProgreso):
```javascript
async function renderInicio() {
  // Lógica existente de HOME
  // + Agregar acciones rápidas
  const container = document.getElementById('view-inicio');
  // Mostrar: calorías, agua, pasos, entrenamiento, racha, nivel
  // Botones: +Comida, +Agua, +Actividad, Escanear
}
```

**renderNutricion()**:
```javascript
async function renderNutricion() {
  // Lógica existente
  // + Incorporar botón destacado para escáner
  // El escáner sigue siendo renderEscaner() cuando se hace click
}
```

**renderProgreso()** (renombrada de renderMiProgreso):
```javascript
async function renderProgreso() {
  // Lógica existente de chart.js + estadísticas
  // + Incorporar subsección de "Actividad" desde renderActividad()
}
```

**renderPerfil()** (renombrada de renderMiPerfil):
```javascript
async function renderPerfil() {
  // Lógica existente de avatar + perfil
  // + Llamar a renderRanking(), renderAmigos(), renderLogros(), renderRetos(), renderTienda()
  // Como subsecciones expandibles
}
```

---

## 4. RIESGOS DE ROMPER ENLACES INTERNOS

### 4.1 Enlaces que navegan a vistas

**Búsqueda necesaria**: Todos los lugares en código que llaman `loadView('actividad')`, `loadView('escaner')`, `loadView('ranking')`, etc.

**Impacto**:
- ❌ `loadView('actividad')` → debe cambiar a `loadView('progreso')` o no navegar (si la acción está en Inicio)
- ❌ `loadView('escaner')` → debe cambiar a `loadView('nutricion')`
- ❌ `loadView('ranking')` → debe cambiar a `loadView('perfil')`

**Dónde buscar**:
- Toast/notificaciones que abren vistas
- Botones dentro de las vistas (ej: "Ver progreso" en Inicio)
- Redirects después de acciones
- Enlaces en perfil

### 4.2 Referencias a VIEWS array

**Impacto**:
- ✅ Actualizar `const VIEWS = [...]` automáticamente lo arreglará
- ✅ `if (!VIEWS.includes(viewName))` seguirá funcionando

### 4.3 Selectores CSS/HTML que usan nombres de vista

**Búsqueda**:
- `#view-actividad`, `#view-escaner`, `#view-ranking`
- `data-view="actividad"`, etc.

**Impacto**: Cambiar a nuevos IDs

---

## 5. FUNCIONES A REUTILIZAR

### 5.1 Ya desarrolladas (SIN duplicar)

| Función | Ubicación | Nueva ubicación |
|---------|-----------|---|
| `renderProgreso()` | app.js:294 | Renombrar a `renderInicio()` |
| `renderActividad()` | app.js:595 | Sub-función en `renderProgreso()` |
| `renderMiProgreso()` | app.js:953 | Renombrar a `renderProgreso()` |
| `renderNutricion()` | app.js:2856 | Mantener (agregar botón escáner) |
| `renderEscaner()` | app.js:3324 | Llamada desde `renderNutricion()` |
| `renderRanking()` | app.js:3977 | Sub-función en `renderPerfil()` |
| `renderMiPerfil()` | app.js:1231 | Renombrar a `renderPerfil()` |
| `Asistente.render()` | asistente.js | Mantener (FAB en lugar de tab) |
| `Explorar.render()` | explorar.js | Decidir ubicación |

### 5.2 Componentes que siguen igual

- Cámara para escáner (`stopCamera()`, etc.)
- Charts de progreso
- Avatar 3D
- Modales de edición
- Sistema de toasts
- Gamificación visual

---

## 6. PLAN DE IMPLEMENTACIÓN RESUMIDO

### FASE 1: Cambios estructurales (app.js e index.html)
1. Renombrar vistas en HTML: `progreso` → `inicio`, `mi_progreso` → `progreso`, `mi_perfil` → `perfil`
2. Eliminar Bottom Nav items para: `actividad`, `escaner`, `ranking`
3. Actualizar `const VIEWS`
4. Actualizar `loadView()` para nuevas vistas
5. Renombrar funciones render

### FASE 2: Refactorización de funciones render
1. `renderInicio()` + agregar acciones rápidas
2. `renderNutricion()` + botón escáner destacado
3. `renderProgreso()` + subsección actividad
4. `renderPerfil()` + subsecciones (ranking, amigos, logros, etc.)

### FASE 3: Asistente como FAB
1. Remover de Bottom Nav
2. Agregar botón FAB flotante
3. Estilos FAB (CSS)

### FASE 4: Búsqueda y corrección de enlaces rotos
1. Grep por `loadView('` en todo el proyecto
2. Actualizar referencias a vistas eliminadas
3. Pruebas de navegación interna

### FASE 5: Pruebas responsivas
1. Mobile (375px, 390px, 430px)
2. Tablet (768px)
3. Desktop (1366px+)

---

## 7. DECISIONES PENDIENTES

**¿QUÉ HACER CON "EXPLORAR"?**

Opciones:
1. ✅ Incluir en Perfil → COMUNIDAD → Mapa
2. Incluir en Perfil → Nueva sección LUGARES
3. Mantener como vista separada (5 pestañas)
4. Acceso desde Inicio (Ubicación cercana)

**Recomendación**: Opción 1 (dentro de Perfil/COMUNIDAD)

---

## RESUMEN TÉCNICO

| Aspecto | Impacto |
|---------|--------|
| **Vistas**: 9 → 5 | Eliminados: actividad, escaner, ranking (como tabs) |
| **Bottom Nav**: 9 → 4 | Solo: Inicio, Nutrición, Progreso, Perfil |
| **FAB**: 0 → 1 | Nuevo: Asistente flotante |
| **Funciones render**: Reutilizadas | Renombradas algunas, subsecciones integradas |
| **Enlaces rotos**: Posibles | Require búsqueda exhaustiva en código |
| **Duplicación**: CERO | Todas las funciones se reutilizan |

---

## APROBACIÓN REQUERIDA

Antes de empezar, confirme:

- [ ] ¿Explorar va en Perfil/COMUNIDAD?
- [ ] ¿Acciones rápidas en Inicio incluyen: +Comida, +Agua, +Actividad, Escanear?
- [ ] ¿FAB Asistente debe respetar safe-area en iPhone (pantalla notch)?
- [ ] ¿Los iconos propuestos para bottom nav son correctos?

---

**ESTADO**: ✅ **ANÁLISIS COMPLETADO — LISTO PARA IMPLEMENTACIÓN**

Una vez aprobado, procedo a implementar sin eliminar ninguna funcionalidad.
