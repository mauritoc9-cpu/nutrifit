# QUICK START: CÓMO PROBAR LOS TABS

**Objetivo**: Verificar en 10 minutos que todas las funcionalidades están recuperadas\
**Requerimiento**: Credenciales de usuario con datos en la base de datos

---

## 🚀 5 PASOS PARA TESTEAR

### PASO 1: Abrir la App
```
URL: http://localhost/nutrifittwo/frontend/
Viewport: 375x812 (móvil para ver bottom nav)
```

### PASO 2: Iniciar Sesión
- Email: `[tu email registrado]`
- Contraseña: `[tu contraseña]`

### PASO 3: Testear NUTRICIÓN
1. Haz click en tab "Nutrición" (bottom nav, 2do item)
2. Deberías ver TAB SELECTOR: **📋 Diario | 📸 Escáner**
3. **Por defecto está en "📋 Diario"** → Verás calorías, hidratación, diario de comidas
4. Haz click en **"📸 Escáner"** → Debería cargar:
   - Scanner frame (recuadro de captura)
   - Botones: "Tomar foto", "Subir foto", "Cámara en vivo"
   - Buscador manual de alimentos

✅ **Éxito**: Ambos tabs funcionan sin recargar página

### PASO 4: Testear PROGRESO
1. Haz click en tab "Progreso" (bottom nav, 3er item)
2. Deberías ver TAB SELECTOR: **📊 Estadísticas | 🏃 Actividad**
3. **Por defecto está en "📊 Estadísticas"** → Verás gráficos, peso, resumen
4. Haz click en **"🏃 Actividad"** → Debería cargar:
   - Anillo de progreso de pasos
   - Rutina recomendada
   - Registro manual de actividad
   - Historial de actividades
   - Podómetro

✅ **Éxito**: Ambos tabs funcionan sin recargar página

### PASO 5: Testear PERFIL (EL MÁS IMPORTANTE)
1. Haz click en tab "Perfil" (bottom nav, 4to item)
2. Deberías ver MENÚ HORIZONTAL: **👤 | 👥 | 🏆 | 🎖️ | 🎯 | 🏪**
   (Si en mobile no ves todos, scroll horizontal)
3. **Prúeba cada uno**:

#### Tab 👤 "Perfil" (DEFAULT)
- Avatar 3D
- Nombre + Nivel
- Racha + NutriCoins
- Mini-preview de Retos
- Mini-preview de Logros
- Mi Colección

✅ **Éxito**: Se carga correctamente

#### Tab 👥 "Amigos"
- Botones: "Mi QR" + "Escanear QR"
- Lista de amigos (si tienes)

✅ **Éxito**: Se carga correctamente

#### Tab 🏆 "Ranking"
- Selector: Global / Amigos
- Tabs de ligas: Bronce, Plata, Oro, Diamante
- Leaderboard (tu posición + top 10)
- Tienda con items (grid de items)

✅ **Éxito**: Se carga correctamente

#### Tab 🎖️ "Logros"
- Contador "X/Y logros desbloqueados"
- Grid COMPLETO de todos los logros
- Logros desbloqueados resaltados

✅ **Éxito**: Se carga el grid completo (no es un modal)

#### Tab 🎯 "Retos"
- Título "Retos de la Semana"
- Lista COMPLETA de retos
- Cada reto con:
  - Título + Descripción
  - Barra de progreso
  - Contador (X/Y)
  - Recompensa XP
  - Check si completado

✅ **Éxito**: Se carga la lista completa (no es un mini-preview)

#### Tab 🏪 "Tienda"
- Grid de items disponibles
- Cada item con:
  - Ícono
  - Nombre
  - Precio (XP o NutriCoins)
  - Botón (Canjear/Equipar/Desequipar)

✅ **Éxito**: Se carga el catálogo completo

---

## ✅ CHECKLIST DE RESULTADO ESPERADO

Después de completar los 5 pasos, deberías poder verificar:

### ✅ Nutrición
- [x] Tab "Diario" funciona
- [x] Tab "Escáner" carga cámara/foto
- [x] Cambio de tab es instantáneo (sin recargar)

### ✅ Progreso
- [x] Tab "Estadísticas" muestra gráficos
- [x] Tab "Actividad" muestra pasos + rutina
- [x] Cambio de tab es instantáneo

### ✅ Perfil (Lo Más Crítico)
- [x] 6 tabs son accesibles
- [x] Tabs en barra horizontal
- [x] "Amigos" carga correctamente
- [x] "Ranking" carga leaderboard + tienda
- [x] "Logros" muestra grid completo (NO modal)
- [x] "Retos" muestra lista completa
- [x] "Tienda" muestra catálogo completo
- [x] Cambio entre tabs es instantáneo

---

## 🔍 VERIFICACIÓN DETALLADA POR TAB

### Si algo NO funciona, revisa:

#### Nutrición / Escáner no carga
- Abre DevTools (F12)
- Consola → ¿Errores de JavaScript?
- ¿`renderEscaner()` fue llamada?
- ¿El contenedor `view-nutricion` existe?

#### Progreso / Actividad no carga
- ¿Tienes datos de actividad en la BD?
- ¿`renderActividad()` fue llamada?
- ¿El contenedor `view-progreso` existe?

#### Perfil / Tabs no aparecen
- ¿Los 6 tabs están en el HTML?
- ¿El variable `perfilTab` se actualizó?
- ¿`cambiarPerfilTab()` fue llamada?

#### Perfil / Ranking no carga
- ¿`Api.ranking()` responde correctamente?
- ¿Hay datos de ranking en la BD?
- Revisa network requests (DevTools → Network)

#### Perfil / Logros muestra modal en lugar de vista
- Deberías ver el grid completo, NO un modal
- Si ves un modal, `renderLogrosCompleto()` no se llamó
- Verifica que `perfilTab === 'logros'` en `renderPerfil()`

---

## 🎬 VÍDEO DE PRUEBA (5 MINUTOS)

Si quieres grabar una prueba rápida:

1. **0:00-0:30** - Navegar a Nutrición, cambiar entre tabs "Diario" ↔ "Escáner"
2. **0:30-1:00** - Navegar a Progreso, cambiar entre tabs "Estadísticas" ↔ "Actividad"
3. **1:00-2:30** - Navegar a Perfil, probar 6 tabs uno por uno
4. **2:30-5:00** - Profundizar en cada tab:
   - "Amigos": Click en "Mi QR" o "Escanear"
   - "Ranking": Switch Global ↔ Amigos, cambiar ligas
   - "Logros": Scroll por grid completo
   - "Retos": Ver lista completa con progreso
   - "Tienda": Scroll por items disponibles

---

## 🐛 REPORTAR BUGS

Si algo NO funciona:

1. Abre DevTools (F12)
2. Consola (Console tab) → Copia errores JavaScript
3. Network (Network tab) → Copia errores de API
4. Screenshots de:
   - URL actual
   - El error que ves
   - Console output

---

## 📊 MATRIZ DE PRUEBA RÁPIDA

| Tab | Cómo Acceder | Qué Debería Ver | ✅/❌ |
|-----|---|---|---|
| **Nutrición: Diario** | Nutrición → "📋 Diario" | Calorías, hidratación, diario | |
| **Nutrición: Escáner** | Nutrición → "📸 Escáner" | Cámara, foto, análisis IA | |
| **Progreso: Estadísticas** | Progreso → "📊 Estadísticas" | Gráficos, peso, resumen | |
| **Progreso: Actividad** | Progreso → "🏃 Actividad" | Pasos, rutina, historial | |
| **Perfil: Perfil** | Perfil → "👤 Perfil" | Avatar, XP, mini-cards | |
| **Perfil: Amigos** | Perfil → "👥 Amigos" | QR, lista de amigos | |
| **Perfil: Ranking** | Perfil → "🏆 Ranking" | Leaderboard, tienda | |
| **Perfil: Logros** | Perfil → "🎖️ Logros" | Grid completo de logros | |
| **Perfil: Retos** | Perfil → "🎯 Retos" | Lista de retos con progreso | |
| **Perfil: Tienda** | Perfil → "🏪 Tienda" | Catálogo de items | |

---

## ⏱️ TIEMPO ESTIMADO

- **5 minutos**: Prueba rápida (todos los tabs abren)
- **15 minutos**: Prueba funcional (verificar cada funcionalidad)
- **30 minutos**: Prueba exhaustiva (con DevTools, screenshots, reportes)

---

## 🎯 RESULTADO ESPERADO

Después de 5-10 minutos, deberías poder confirmar:

✅ **4 pestañas principales funcionan**
✅ **Tabs secundarios funcionan dentro de cada pestaña**
✅ **NO hay funcionalidades eliminadas**
✅ **Todo es mobile-first responsive**
✅ **FAB Asistente sigue siendo accesible**

---

**¡Listo para testear!**

Si necesitas ayuda, revisa los otros documentos:
- `PLAN_VERIFICACION_TABS.md` - Checklist exhaustivo
- `RESUMEN_SISTEMA_TABS.md` - Explicación técnica
- `AUDITORIA_RECUPERACION_FUNCIONALIDADES.md` - Listado de 28 funciones

---

Generado: 7 de octubre de 2026
