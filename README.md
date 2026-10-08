# 🥗 NutriFit — App Gamificada de Nutrición, Hidratación y Entrenamiento

**NutriFit** es una aplicación web gamificada que acompaña al usuario en su camino hacia hábitos saludables de nutrición, actividad física e hidratación. Combina gamificación (XP, niveles, rachas, logros), inteligencia artificial (escaneo de alimentos, asistente personalizado), geolocalización (exploración de lugares), sistemas sociales (amigos, ranking) y visualización de progreso (gráficos, exportación).

**Stack:** PHP 8+ (OOP/PDO) · MySQL/MariaDB · HTML5/CSS3/JS Vanilla · Chart.js 4.4 · Geolocalización · IA (Gemini)

---

## 🎯 Problemática

Muchas personas quieren mejorar su salud pero:
- Pierden motivación sin retroalimentación inmediata
- No saben cuánto comer ni si están en el camino correcto
- Desconocen sus hábitos (cuántos pasos, cuántos vasos de agua)
- Se aburren haciendo ejercicio sin comunidad
- Les falta una guía personalizada en el momento

## ✅ Solución

NutriFit ofrece un sistema de gamificación integrado que:
1. **Personaliza** metas calóricas/macros según perfil (Mifflin-St Jeor)
2. **Registra** comidas en tiempo real (búsqueda + escaneo IA)
3. **Visualiza** progreso con gráficos interactivos (peso, actividad)
4. **Motiva** mediante XP, niveles, rachas, logros y competencia social
5. **Asiste** con un chatbot personalizado (contexto de datos propios)
6. **Conecta** usuarios para entrenar juntos o compartir rutinas

---

## 📱 Funcionalidades Actuales

### 1. **Autenticación & Perfil**
- Registro con validación de email
- Login seguro (bcrypt + sesión HttpOnly)
- Recuperación de contraseña vía tokens (email configuración pendiente)
- Onboarding: biometría (altura, peso), objetivo y estilo de vida
- Cálculo automático de TMB → GET → metas calóricas y macros (Mifflin-St Jeor)
- Perfil editable, avatar 3D personalizable con accesorios cosméticos

### 2. **Nutrición**
- **Dashboard calórico**: anillo de progreso, macro breakdown (proteína/carbos/grasas)
- **Registro de comidas**: búsqueda en catálogo de +1500 alimentos (kcal, macros, fibra)
- **Favoritos**: almacenamiento de comidas frecuentes
- **Escáner IA**: análisis de foto con IA (Gemini Vision) → cantidad estimada → confirmación manual
- **Hidratación**: contador de vasos con meta personalizada
- **Recomendaciones**: sugerencia de próxima comida según déficit calórico
- **Lista de compras**: creación de listas personalizadas
- **Recetas**: búsqueda de recetas generadas con IA según preferencias

### 3. **Actividad & Entrenamiento**
- **Rutina recomendada**: lista de ejercicios diarios (~25 XP al completar)
- **Seguimiento de pasos**: integración de datos de actividad
- **Progreso de peso**: gráfico histórico de cambios
- **Entrenamiento personalizado**: planes adaptados al nivel y objetivo

### 4. **Mi Progreso**
- **Dashboard visual**: XP actual, nivel, racha de días, logros y retos activos
- **Gráficos Chart.js**:
  - Línea: evolución de peso a lo largo del tiempo
  - Barras: resumen de actividad diaria
- **Exportación**: PDF con informe completo o CSV para análisis
- **Estadísticas**: histórico de últimos 7 días, 30 días o todo el historial
- **Avatar 3D Robot**: renderizado en tiempo real, personalizable con marco/aura/título

### 5. **Sistema de QR**

#### A) QR de Rutinas
- **Compartir**: generar QR de tu rutina (código único, válido 7 días)
- **Preview**: visualizar antes de importar
- **Importar**: crear copia independiente (no afecta original, no otorga XP)
- **Revocar**: invalidar código compartido
- **Seguridad**: token en claro solo en QR, hash en BD, expiración automática

#### B) QR Social
- **Mi QR**: código personal estable y regenerable (no caduca)
- **Escanear**: usar cámara, subir imagen o ingresar código manualmente
- **Preview**: muestra nombre, nivel (solo info pública)
- **Agregar amigo**: flujo normal de solicitud, reutiliza validaciones existentes
- **Seguridad**: token impredecible (32 bytes), hash SHA256 en BD, sin email ni datos privados

### 6. **Gamificación**

#### XP & Niveles
- Ganancia de XP: comida (+10), agua (+5 cada 3 vasos), ejercicio (+25)
- Anti-farming: límite diario de XP (ajustable por configuración)
- Racha de días: incentivo por consistencia
- Multiplicador de XP: bonus por nivel alcanzado

#### Logros & Retos
- **Logros**: hitos de largo plazo (ej. "100 comidas registradas")
- **Retos**: desafíos semanales con recompensas

#### Ranking & Ligas
- **Ranking global**: usuarios ordenados por XP en ligas (Bronce/Plata/Oro/Diamante)
- **Ranking de amigos**: competencia interna con contactos aceptados
- **Recompensas**: +100 NutriCoins por cada nivel nuevo

#### Sistema de Amigos
- **Búsqueda por email**: agregación manual
- **QR Social**: código para compartir perfil sin exponer email
- **Solicitud/Aceptación**: validaciones contra auto-amistad y duplicados
- **Visualización de progreso del amigo**: XP, nivel, racha

#### Tienda de Cosméticos
- **NutriCoins**: moneda ganada por alcanzar niveles
- **Accesorios**: marcos de avatar, auras, títulos personalizados
- **Persistencia**: cambios reflejados inmediatamente en perfil

### 7. **Explorar**
- **Mapa interactivo**: búsqueda de lugares (gimnasios, parques, nutricionistas)
- **Geolocalización**: ubicación actual del usuario (con permiso)
- **Filtros**: búsqueda por tipo de lugar
- **Detalles**: horarios, dirección, contacto

### 8. **Asistente NutriFit**
- **Chatbot personalizado**: responde preguntas sobre salud, nutrición, entrenamiento
- **Contexto del usuario**: tiene acceso a datos propios (peso, metas, historial)
- **Generación IA**: respuestas con Gemini API (modelo `gemini-2.0-flash`)
- **Estado actual**: factual/local habilitado; generación de planes personalizados actualmente **deshabilitada por privacidad** (requiere confirmación de usuario sobre tratamiento de datos)

### 9. **Responsive**
- **Mobile** (375×812): navegación fluida, sin scroll horizontal
- **Desktop** (1366×768+): interfaz completa aprovecha espacio
- **Tablets**: diseño adaptable intermedio
- Testeado en navegadores modernos (Chrome, Firefox, Safari, Edge)

---

## 🏗️ Arquitectura

### Backend
```
backend/
├── config/
│   ├── database.php          ← Conexión PDO (XAMPP/Heroku)
│   └── bootstrap.php         ← Sesión, headers, timezone, helpers
├── classes/
│   ├── Usuario.php           ← Registro, login, perfil
│   ├── SistemaXP.php         ← Cálculo de XP, niveles, racha
│   ├── MotorMetabolico.php   ← Mifflin-St Jeor, TMB, GET
│   ├── PasswordResetTokens.php ← Tokens de recuperación
│   └── (múltiples Services)  ← Gamificación, rutinas, exportación, etc.
└── api/
    ├── auth/                 ← Autenticación y sesión
    ├── nutricion/            ← Comidas, escaneo IA, hidratación
    ├── entrenamiento/        ← Rutinas, ejercicios, progreso
    ├── gamificacion/         ← Ranking, amigos, tienda, logros, retos, QR social
    ├── actividad/            ← Pasos, historial
    ├── asistente/            ← Conversaciones IA
    ├── explorar/             ← Geolocalización
    └── progreso/             ← Exportación, resumen
```

### Frontend
```
frontend/
├── index.html                ← Shell SPA + login/registro/onboarding
├── css/
│   ├── styles.css            ← Estilos principales + variables de tema
│   └── (módulos específicos) ← exportacion-qr.css, explorar.css, etc.
└── js/
    ├── api.js                ← Cliente Fetch centralizado
    ├── auth.js               ← Login, registro, onboarding, modo oscuro
    ├── app.js                ← Navegación y vistas principales (~4500 líneas)
    ├── exportacion-qr.js     ← QR de rutinas (generador + escáner)
    ├── amigos-qr.js          ← QR social (generador + escáner)
    ├── asistente.js          ← Chatbot UI
    ├── explorar.js           ← Mapa e integración
    ├── icons.js              ← Componentes de iconos
    ├── avatars/
    │   ├── avatar-manager.js ← Control de accesorios
    │   └── robot.js          ← Renderizado 3D
    ├── qr-libs/              ← qrcode.js + qr-scanner.min.js
    └── chart-libs/           ← Chart.js 4.4.9
```

---

## 🗄️ Base de Datos

**18 migraciones SQL** (desde `001_alimentos_fuente_externa.sql` hasta `019_qr_social.sql`):

| Tabla | Descripción |
|-------|------------|
| `usuarios` | Registro, credenciales, XP, nivel, avatar |
| `onboarding` | Datos de perfil (altura, peso, objetivo, estilo) |
| `alimentos` | Catálogo de +1500 alimentos (kcal, macros) |
| `comidas` | Registro diario de ingestas |
| `comidas_favoritas` | Frecuentes del usuario |
| `hidratacion` | Contador de vasos por día |
| `historial_pasos` | Actividad de pasos |
| `rutinas_ejercicios` | Rutinas personalizadas |
| `ejercicios` | Ejercicios dentro de rutinas |
| `ejercicios_completados` | Marcado de ejercicios |
| `amigos_ranking` | Solicitudes y amistades |
| `ranking_global` | Cache de ligas y XP |
| `logros`, `retos`, `retos_completados` | Gamificación |
| `tienda`, `cosmeticos_usuario` | Avatar y accesorios |
| `rutina_comparticiones`, `rutina_importaciones` | QR de rutinas |
| `perfiles_comparticiones` | QR social |
| `recetas` | Generadas con IA |
| `asistente_conversaciones` | Historial de chat |
| `lugares_explorar` | POI para geolocalización |

---

## 🔐 Seguridad

- **Autenticación**: sesión PHP + cookie HttpOnly/SameSite/Secure
- **Consultas**: PDO con sentencias preparadas (protección contra inyección SQL)
- **Contraseñas**: `password_hash()` con bcrypt (cost=12)
- **CSRF**: regeneración de sesión en login
- **Escape**: `htmlspecialchars()` en todas las salidas (prevención XSS)
- **Autorización**: cada usuario solo accede a sus propios datos (verificación de ownership en servidor)
- **Tokens QR**: 32 bytes → 64 hex, solo hash almacenado en BD
- **API REST**: validación de tipos, límites de solicitud

---

## 🚀 Instalación

### Requisitos
- **XAMPP** (Apache 2.4+, PHP 8.0+, MySQL/MariaDB 5.7+)
- **Navegador moderno** (Chrome, Firefox, Safari, Edge)

### Pasos

1. **Clonar/copiar proyecto**
   ```bash
   # Copiar carpeta en htdocs
   cp -r nutrifittwo /c/xampp/htdocs/
   ```

2. **Base de datos**
   - Abrir `http://localhost/phpmyadmin`
   - Crear base `nutrifit_db` (charset utf8mb4)
   - Importar migraciones (SQL ejecuta automáticamente al acceder al backend, o aplicar manualmente)

3. **Configuración .env (si aplica)**
   ```bash
   # .env (no incluir en repo)
   GEMINI_API_KEY=your_key_here  # Requerido para asistente/escaneo IA
   DB_HOST=127.0.0.1
   DB_USER=root
   DB_PASS=
   DB_NAME=nutrifit_db
   ```

4. **Acceder**
   ```
   http://localhost/nutrifittwo/frontend/
   ```

---

## 📊 Tecnologías

| Capa | Tecnologías |
|-----|------------|
| **Backend** | PHP 8.2 (OOP, PDO), MySQL 5.7+, Mifflin-St Jeor (cálculos), Gemini 2.0 Flash (IA) |
| **Frontend** | HTML5, CSS3 (custom properties), JS ES2020 (Fetch API, async/await), Chart.js 4.4.9 |
| **QR** | qrcode.js (generación), qr-scanner.min.js (lectura) |
| **3D** | Canvas 2D/WebGL (Avatar 3D robot renderizado a mano) |
| **Mapas** | Leaflet (opcional, POI en base de datos) |
| **Hosting (futuro)** | Heroku (JawsDB para MySQL) |

---

## 🎮 Flujo de Uso

1. **Registro** → email + contraseña (bcrypt)
2. **Onboarding** → altura, peso, objetivo, estilo de vida
   - Backend calcula TMB y GET automáticamente
3. **Acceso a la app** → SPA con 9 vistas:
   - Progreso
   - Actividad
   - Mi Progreso (gráficos)
   - Nutrición
   - Escáner
   - Ranking
   - Perfil
   - Explorar
   - Asistente

4. **Ganancia de XP**: registrar comida (+10), agua (+5/3 vasos), ejercicio (+25)
5. **Motivación**: sube de nivel → +100 NutriCoins → canje en tienda
6. **Comunidad**: agrega amigos (email o QR) → ve su progreso → compite en ranking

---

## 📈 Estado del Proyecto

| Funcionalidad | Estado | Notas |
|---|---|---|
| Autenticación | ✅ Completo | Registro, login, sesión segura |
| Nutrición | ✅ Completo | Comidas, favoritos, hidratación, recomendaciones |
| Escaneo IA | ✅ Completo | Gemini Vision integrado |
| Entrenamiento | ✅ Completo | Rutinas, ejercicios, progreso |
| Actividad | ✅ Completo | Pasos, historial |
| Mi Progreso | ✅ Completo | Chart.js + exportación PDF/CSV |
| Gamificación | ✅ Completo | XP, niveles, racha, logros, retos |
| QR Rutinas | ✅ Completo | Compartir, previsualizar, importar |
| QR Social | ✅ Completo | Mi QR + escaneo, sin exponer email |
| Ranking | ✅ Completo | Ligas global, ranking de amigos |
| Amigos | ✅ Completo | Búsqueda por email y QR |
| Tienda | ✅ Completo | NutriCoins, cosméticos avatar |
| Avatar 3D | ✅ Completo | Robot renderizado, accesorios |
| Explorar | ✅ Completo | Mapas, geolocalización, POI |
| Asistente | ⚠️ Parcial | Factual/local ✅, generación personalizada ⏸️ |
| Recuperación email | ⏸️ Incompleto | Token seguro implementado, email no configurado |
| Accesorios 3D | 📌 Futuro | Modelos 3D no implementados |
| Deploy HTTPS | 📌 Pendiente | Necesario para producción |

---

## 🧪 Testing

- **Test unitarios**: validaciones backend en API
- **Test de flujo**: script `tests/test-qr-social.php` verifica generación, escaneo, amistad completa
- **Responsive**: verificado en 375×812 (mobile) y 1366×768 (desktop)

---

## 📝 Documentación Adicional

- `documentacion/BACKLOG_NUTRIFIT.md` — 43 historias de usuario (40 completadas)
- `documentacion/NutriFit_Documentacion.docx` — Documento de tesis completo
- `documentacion/NutriFit_Presentacion_Exposicion.pptx` — Presentación 15-20 min
- `documentacion/NutriFit_Guion_Exposicion.docx` — Guion de presentación + demo

---

## 🚧 Próximas Mejoras

1. **Email real** en recuperación de contraseña (actualmente solo genera token)
2. **Modelos 3D** para accesorios de avatar
3. **Notificaciones push** para recordatorios
4. **Deploy HTTPS** en Heroku (requiere JawsDB)
5. **Sincronización** con wearables (Apple Watch, Fitbit)
6. **Más alimentos** en catálogo y integración con USDA/Open Food Facts

---

## 📞 Autor

**NutriFit** — Proyecto de tesis de Informática\
**Desarrollador**: Mauro Cerdan\
**Año**: 2026\
**Email**: nutrifit.argentina2026@gmail.com

---

**¡Bienvenido a NutriFit! 🏃‍♂️💪🥗**
