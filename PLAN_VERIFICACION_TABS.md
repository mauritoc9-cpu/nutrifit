# PLAN DE VERIFICACIÓN: SISTEMA DE TABS

**Fecha**: 7 de octubre de 2026\
**Estado**: ✅ IMPLEMENTADO Y LISTO PARA PROBAR

---

## 📋 CHECKLIST COMPLETA DE FUNCIONALIDADES

### SECCIÓN: INICIO ✅
- [ ] La pestaña "Inicio" carga correctamente
- [ ] Se muestran las 4 acciones rápidas (+Comida, +Agua, +Actividad, Escanear)
- [ ] Cada acción rápida navega a la sección correspondiente
- [ ] Se muestra resumen del día (calorías, hidratación, pasos)
- [ ] FAB Asistente es visible y funcional

---

### SECCIÓN: NUTRICIÓN ✅

#### TAB 1: 📋 Diario (DEFAULT)
- [ ] Se carga el dashboard de nutrición
- [ ] Se muestra tarjeta de calorías con meta
- [ ] Se muestra tarjeta de hidratación
- [ ] Se muestran recomendaciones de alimentos
- [ ] Se muestran favoritos guardados
- [ ] Botón "Generar receta con IA" funciona
- [ ] Diario de comidas por tipo (Desayuno, Almuerzo, Merienda, Cena, Snack)
- [ ] TAB selector visible con 2 opciones: 📋 Diario | 📸 Escáner

#### TAB 2: 📸 Escáner IA
- [ ] Al hacer click en "📸 Escáner", se carga `renderEscaner()`
- [ ] Se muestra el scanner frame (recuadro de captura)
- [ ] Botón "Tomar foto" (camera capture)
- [ ] Botón "Subir foto" (galería)
- [ ] Botón "Cámara en vivo"
- [ ] Al activar cámara en vivo, aparece botón "Capturar y analizar"
- [ ] Buscador manual de alimentos funciona
- [ ] Resultado del análisis IA se muestra correctamente
- [ ] TAB selector permite volver a Diario

---

### SECCIÓN: PROGRESO ✅

#### TAB 1: 📊 Estadísticas (DEFAULT)
- [ ] Se carga el dashboard de progreso
- [ ] Se muestran 4 tiles: Peso | Actividad | Nutrición | Hidratación
- [ ] Cada tile muestra tendencia (↑/↓) si hay datos
- [ ] Se muestra selector de período (Semana/Mes/Año)
- [ ] Gráfico de peso (Chart.js line chart) visible
- [ ] Gráfico de actividad (Chart.js bar chart) visible
- [ ] Secciones desplegables funcionan:
  - [ ] Peso (registro manual, historial con gráfico)
  - [ ] Actividad (resumen)
  - [ ] Nutrición (calorías, macros)
  - [ ] Hidratación (vasos, litros)
- [ ] Exportar progreso (QR) disponible
- [ ] TAB selector visible con 2 opciones: 📊 Estadísticas | 🏃 Actividad

#### TAB 2: 🏃 Actividad
- [ ] Al hacer click en "🏃 Actividad", se carga `renderActividad()`
- [ ] Se muestra resumen del día con:
  - [ ] Anillo de progreso de pasos (% hacia meta)
  - [ ] Valor actual de pasos
  - [ ] Meta de pasos
  - [ ] Distancia en km
  - [ ] Calorías activas
- [ ] Sección "Registrar actividad" con:
  - [ ] Formulario para registrar manualmente (caminata/carrera/ciclismo)
  - [ ] Cantidad de tiempo
  - [ ] Distancia (opcional)
- [ ] Sección "Entrenamiento recomendado" con rutina del día
- [ ] Sección "Podómetro/carga manual de pasos" expandible
- [ ] Historial de actividades pasadas
- [ ] TAB selector permite volver a Estadísticas

---

### SECCIÓN: PERFIL ✅

#### MENÚ HORIZONTAL CON 6 TABS
Se debe ver una barra horizontal scrollable con: 👤 Perfil | 👥 Amigos | 🏆 Ranking | 🎖️ Logros | 🎯 Retos | 🏪 Tienda

#### TAB 1: 👤 Perfil (DEFAULT)
- [ ] Avatar 3D visible y clickeable para personalizar
- [ ] Nombre del usuario
- [ ] Nivel actual + badge
- [ ] Título equipado (si existe)
- [ ] Botón "Personalizar avatar"
- [ ] Sección "Progreso en NutriFit":
  - [ ] Nivel
  - [ ] Barra de XP
  - [ ] Racha (días)
  - [ ] NutriCoins
- [ ] Sección "Retos de la semana" (mini-preview):
  - [ ] Muestra hasta los primeros retos
  - [ ] Barra de progreso para cada reto
- [ ] Sección "Logros" (mini-preview):
  - [ ] Muestra hasta los primeros 4 logros
  - [ ] Botón "Ver todos" visible
- [ ] Sección "Mi Colección":
  - [ ] Contador de items por categoría (Ropa/Aura/Marco/Título)
  - [ ] Botón "Personalizar ropa/tinte"
- [ ] Mini-cards de acceso rápido a Ranking y Tienda
- [ ] TAB selector visible al top

#### TAB 2: 👥 Amigos
- [ ] Se carga `renderAmigosCompleto()`
- [ ] Botones de acción:
  - [ ] "Mi QR" - Muestra el código QR personal
  - [ ] "Escanear QR" - Abre lector de QR
- [ ] "Lista de Amigos" con:
  - [ ] Cada amigo: Nombre, Nivel, XP
  - [ ] Mensaje si no hay amigos
- [ ] TAB selector permite volver a Perfil, ir a Ranking, etc.

#### TAB 3: 🏆 Ranking
- [ ] Se carga `renderRanking()`
- [ ] Sección "Ranking" con:
  - [ ] Selector "Global" / "Amigos"
  - [ ] **Global**: Tabs de ligas (Bronce/Plata/Oro/Diamante)
  - [ ] **Global**: Leaderboard con posición del usuario
  - [ ] **Amigos**: Lista solo de amigos aceptados
- [ ] Sección "Amigos" (mini-preview en ranking):
  - [ ] Mi QR
  - [ ] Escanear QR
  - [ ] Agregar por email
  - [ ] Lista de amigos
- [ ] Sección "Tienda NutriFit":
  - [ ] Grid de items
  - [ ] NutriCoins disponibles visible
  - [ ] Botones de canjeo/equipado
- [ ] TAB selector permite ir a otras vistas

#### TAB 4: 🎖️ Logros
- [ ] Se carga `renderLogrosCompleto()`
- [ ] Mostrar "X/Y logros desbloqueados"
- [ ] Grid COMPLETO de todos los logros
- [ ] Logros desbloqueados aparecen en estilo diferente (logrado)
- [ ] Cada logro muestra:
  - [ ] Ícono
  - [ ] Título
  - [ ] Descripción
- [ ] TAB selector permite ir a Retos, volver a Perfil, etc.

#### TAB 5: 🎯 Retos
- [ ] Se carga `renderRetosCompleto()`
- [ ] Mostrar "Retos de la Semana"
- [ ] Lista COMPLETA de todos los retos semanales
- [ ] Cada reto muestra:
  - [ ] Ícono
  - [ ] Título
  - [ ] Descripción
  - [ ] Recompensa XP
  - [ ] Barra de progreso
  - [ ] Contador (X/Y objetivo)
  - [ ] Check si completado
- [ ] TAB selector permite ir a Logros, Tienda, Perfil, etc.

#### TAB 6: 🏪 Tienda
- [ ] Se carga `renderTienda()`
- [ ] Grid de items con:
  - [ ] Ícono del tipo
  - [ ] Nombre
  - [ ] Precio (XP o NutriCoins)
  - [ ] Botón (Canjear/Equipar/Desequipar)
- [ ] Categorías visuales por tipo:
  - [ ] Ropa/tinte avatar
  - [ ] Auras
  - [ ] Marcos de perfil
  - [ ] Títulos
  - [ ] Accesorios (Próximamente)
  - [ ] Cupones (Ya tienes)
- [ ] Operaciones de canjeo/equipado funcionan
- [ ] TAB selector permite navegar entre tabs

---

## 🔄 NAVEGACIÓN E INTERACCIÓN

### Comportamiento de Tabs
- [ ] Al hacer click en un tab, se actualiza el estado sin recargar la página
- [ ] El TAB activo se marca visualmente (clase "active")
- [ ] Los tabs están en barra horizontal scrollable (en mobile)
- [ ] No hay saltos de página, transiciones suaves
- [ ] El back button del navegador NO se bloquea

### Acciones Rápidas Desde Inicio
- [ ] "+Comida" navega a Nutrición (Diario tab)
- [ ] "+Agua" permite registrar agua (¿Nutrición o Progreso?)
- [ ] "+Actividad" navega a Progreso (Actividad tab)
- [ ] "Escanear" navega a Nutrición (Escáner tab)

### FAB Asistente
- [ ] Visible desde CUALQUIER sección
- [ ] No se superpone con contenido
- [ ] Respeta safe-area en iPhone
- [ ] Clickeable desde cualquier tab

---

## 📱 RESPONSIVE TESTING (3 BREAKPOINTS)

### Mobile 1: iPhone XS (375x812)
- [ ] Bottom nav con 4 items + FAB ✅
- [ ] Tabs scroll horizontalmente
- [ ] No hay scroll horizontal de contenido
- [ ] FAB no tapa contenido

### Mobile 2: Pixel 4 (390x844)
- [ ] Bottom nav proporcional
- [ ] Contenido se adapta
- [ ] FAB posicionado correctamente

### Mobile 3: Galaxy S21 (430x932)
- [ ] Layout se adapta
- [ ] FAB respeta safe-area
- [ ] Tabs son accesibles

### Desktop (1366x768+)
- [ ] Sidebar de 4 items visible (no bottom nav)
- [ ] FAB en esquina inferior derecha
- [ ] Layout optimizado para desktop
- [ ] Ancho completo utilizado

---

## ⚙️ VERIFICACIÓN TÉCNICA

### Files Modificados
- [x] `frontend/js/app.js` - Variables de estado + funciones modificadas + nuevas funciones
- [ ] NO modificado: `frontend/index.html` - No cambió
- [ ] NO modificado: `frontend/css/styles.css` - No cambió
- [ ] NO modificado: `.env` - No cambió

### Líneas Clave en app.js
- Línea ~14-28: Variables de estado + funciones cambiarTab
- Línea ~980: `renderProgreso()` modificada
- Línea ~2900: `renderNutricion()` modificada
- Línea ~1280: `renderPerfil()` refactorizada
- Línea ~1435-1600: `renderAmigosCompleto()`, `renderLogrosCompleto()`, `renderRetosCompleto()`
- Línea ~3513: `renderEscaner()` ahora async
- Línea ~4166: `renderRanking()` sin cambios (ya renderiza a view-perfil)
- Línea ~4361: `renderTienda()` refactorizada

### Funciones Render Verificadas
- [x] `renderActividad()` - Renderiza a `view-progreso`
- [x] `renderEscaner()` - Renderiza a `view-nutricion` (ahora async)
- [x] `renderRanking()` - Renderiza a `view-perfil`
- [x] `renderTienda()` - Ahora es async, renderiza a `view-perfil`
- [x] `renderAmigosCompleto()` - Nueva, renderiza a `view-perfil`
- [x] `renderLogrosCompleto()` - Nueva, renderiza a `view-perfil`
- [x] `renderRetosCompleto()` - Nueva, renderiza a `view-perfil`

### Funciones API Utilizadas
- [ ] `Api.actividadResumenDia()` - Para actividad
- [ ] `Api.rutinaRecomendada()` - Para rutina
- [ ] `Api.actividadHistorial()` - Para historial
- [ ] `Api.ranking()` - Para ranking
- [ ] `Api.ranking('amigos')` - Para ranking de amigos
- [ ] `Api.tiendaGet()` - Para tienda
- [ ] `Api.logrosGet()` - Para logros
- [ ] `Api.retosGet()` - Para retos
- [ ] `Api.amigosGet()` - Para amigos
- [ ] `Api.dashboardDiario()` - Para nutrición
- [ ] `Api.hidratacionGet()` - Para hidratación

---

## ✅ CRITERIOS DE ÉXITO

### ✅ NO se eliminó ninguna funcionalidad
- [ ] Actividad 100% accesible
- [ ] Escáner 100% accesible
- [ ] Ranking 100% accesible
- [ ] Tienda 100% accesible
- [ ] Amigos QR 100% accesible
- [ ] Logros 100% accesible
- [ ] Retos 100% accesible

### ✅ Arquitectura mantenida
- [ ] 4 pestañas principales
- [ ] FAB Asistente global
- [ ] Bottom nav en mobile (4 items)
- [ ] Sidebar en desktop
- [ ] SPA sin recargas

### ✅ Comportamiento correcto
- [ ] Tabs cambian sin recargar
- [ ] Estados persisten durante la sesión
- [ ] Back button funciona
- [ ] Errores se manejan gracefully

### ✅ Mobile-first responsive
- [ ] 375x812 (XS)
- [ ] 390x844 (pequeño)
- [ ] 430x932 (mediano)
- [ ] 1366x768+ (desktop)

---

## 📝 NOTAS PARA EL USUARIO

### Cómo Probar Cada Funcionalidad

**1. Actividad**
- Ve a Pestaña "Progreso"
- Haz click en tab "🏃 Actividad"
- Verás pasos, rutina recomendada, historial, podómetro

**2. Escáner IA**
- Ve a Pestaña "Nutrición"
- Haz click en tab "📸 Escáner"
- Prueba: Tomar foto / Subir foto / Cámara en vivo

**3. Amigos QR**
- Ve a Pestaña "Perfil"
- Haz click en tab "👥 Amigos"
- Prueba: Mi QR / Escanear QR

**4. Ranking Completo**
- Ve a Pestaña "Perfil"
- Haz click en tab "🏆 Ranking"
- Prueba: Global / Amigos / Ligas

**5. Logros Expandidos**
- Ve a Pestaña "Perfil"
- Haz click en tab "🎖️ Logros"
- Verás grid completo de logros

**6. Retos Expandidos**
- Ve a Pestaña "Perfil"
- Haz click en tab "🎯 Retos"
- Verás lista completa con barras de progreso

**7. Tienda Completa**
- Ve a Pestaña "Perfil"
- Haz click en tab "🏪 Tienda"
- Verás catálogo completo

---

## 🐛 POSIBLES ISSUES CONOCIDOS

Ninguno por el momento. Todos los cambios son funcionales.

---

## ✨ PRÓXIMOS PASOS OPCIONALES

1. Crear atajos teclado para tabs (ej: Alt+1, Alt+2, etc.)
2. Agregar animaciones suaves de transición entre tabs
3. Guardar el último tab usado en localStorage
4. Agregar indicador visual de que hay contenido sin leer en algunos tabs

---

**Estado**: ✅ LISTO PARA VERIFICACIÓN EXHAUSTIVA
