# Guía de Actualización — NutriFit_Presentacion_Exposicion.pptx

Estructura sugerida para la presentación (15-20 minutos).

---

## ESTRUCTURA DE DIAPOSITIVAS

### 1. **PORTADA**
- Título: NutriFit
- Subtítulo: App Gamificada de Nutrición, Hidratación y Entrenamiento
- Autor: Mauro Cerdan
- Año: 2026
- Imagen: Logo o captura de home

---

### 2. **PROBLEMA**
- Títfo: "El Reto"
- Puntos:
  - ❌ Muchas personas quieren cambiar pero pierden motivación
  - ❌ No saben cuánto comer ni si están progresando
  - ❌ Entrenar solo es aburrido
  - ❌ Falta asesoramiento personalizado
- Imagen: Gráfico de abandono o persona frustrada

---

### 3. **SOLUCIÓN**
- Título: "NutriFit: La Respuesta"
- Puntos:
  - ✅ Gamificación integrada (XP, niveles, logros)
  - ✅ IA para análisis de fotos y asesoramiento
  - ✅ Comunidad (amigos, ranking, compartición)
  - ✅ Personalización automática (TMB, macros)
  - ✅ Visualización clara del progreso
- Imagen: Captura del home

---

### 4. **FUNCIONALIDADES PRINCIPALES**
- Título: "¿Qué puede hacer?"
- Puntos (bullet style):
  - Registro y onboarding personalizado
  - Registro de comidas y hidratación
  - Escaneo de alimentos con IA
  - Rutinas y ejercicios
  - Seguimiento de actividad
  - Gráficos del progreso
  - Compartición de rutinas (QR)
  - Búsqueda de amigos (email + QR)
  - Ranking y tienda
  - Chatbot personalizado
  - Exploración de lugares
- Imagen: Iconos de las 9 vistas principales

---

### 5. **TECNOLOGÍAS**
- Título: "Stack Tecnológico"
- Tabla o diagrama:
  - Backend: PHP 8.2 OOP + PDO
  - BD: MySQL 5.7+
  - Frontend: HTML5/CSS3/JS Vanilla
  - Gráficos: Chart.js 4.4
  - IA: Gemini 2.0 Flash
  - QR: qrcode.js + qr-scanner
  - 3D: Canvas custom
- Imagen: Logos de tecnologías

---

### 6. **ARQUITECTURA**
- Título: "Cómo Funciona"
- Diagrama (o texto):
  - Frontend (SPA, 9 vistas)
  - ↓ Fetch API
  - Backend (PHP, controladores)
  - ↓ PDO
  - MySQL (18+ tablas)
- Nota: "Diseño escalable, seguridad en servidor"

---

### 7. **BASE DE DATOS & SEGURIDAD**
- Título: "Datos Seguros"
- Puntos:
  - 18 migraciones, 18+ tablas normalizadas
  - PDO con sentencias preparadas (protección SQL injection)
  - Contraseñas: bcrypt (cost=12)
  - Sesión: HttpOnly, SameSite, Secure
  - Validaciones: server-side, no client-side
  - Ownership: cada usuario solo ve sus datos
- Imagen: Diagrama de tablas principales (usuarios, comidas, amigos, logros)

---

### 8. **IA & ESCANEO**
- Título: "Inteligencia Artificial"
- Dos sistemas:
  1. **Escaneo de alimentos**: Gemini Vision
     - Foto → IA → detección automática + cantidad estimada
     - Usuario confirma antes de registrar
  2. **Asistente contextualizado**: Gemini 2.0 Flash
     - Acceso a: peso actual, meta, historial, nivel
     - Responde preguntas sobre salud, nutrición, ejercicio
     - Actualmente factual (generación personalizada deshabilitada por privacidad)
- Imagen: Captura de escaneo IA + chatbot

---

### 9. **MI PROGRESO & GRÁFICOS**
- Título: "Visualización del Progreso"
- Puntos:
  - Dashboard con XP, nivel, racha
  - Gráfico línea: Evolución de peso
  - Gráfico barras: Actividad diaria
  - Exportación PDF/CSV
  - Comparativa período anterior
- Imagen: Captura de Mi Progreso con ambos gráficos

---

### 10. **EXPORTACIÓN**
- Título: "Reportes Completos"
- PDF:
  - Información personal
  - Período seleccionable (7/30/todos días)
  - Comidas del período
  - Gráficos
  - Resumen XP
- CSV:
  - Datos tabulares (fecha, alimento, kcal, macros)
  - Importable en Excel/Sheets
- Imagen: Vista de PDF exportado

---

### 11. **QR DE RUTINAS**
- Título: "Compartir Rutinas"
- Puntos:
  - Usuario selecciona rutina → genera QR único
  - Válido 7 días, puede revocarse
  - Otro usuario escanea → ve preview → importa copia
  - Copia independiente (no afecta original, no XP)
  - Token seguro: hash SHA256 en BD
- Imagen: Flujo QR: generar → escanear → previsualizar → importar

---

### 12. **QR SOCIAL (NUEVO)**
- Título: "Agregar Amigos por QR"
- Puntos:
  - Cada usuario tiene código QR personal (estable, regenerable)
  - Amigo escanea → ve nombre + nivel (solo info pública)
  - Confirma → envía solicitud normal
  - Solicitud requiere aceptación (sin garantía automática)
  - Seguridad: NO expone email, peso, datos privados
- Imagen: Flujo: Mi QR → escaneo → preview → enviar solicitud → amigos

---

### 13. **EXPLORAR & MAPAS**
- Título: "Geolocalización"
- Puntos:
  - Mapa interactivo (Leaflet)
  - Busca: gimnasios, parques, nutricionistas
  - Ubicación actual (con permiso)
  - Detalles de lugar: dirección, horarios, contacto
  - Filtros por tipo
- Imagen: Captura del mapa con POI

---

### 14. **GAMIFICACIÓN**
- Título: "Motivación Integrada"
- Sistemas:
  - XP: Comida (+10), Agua (+5 c/3 vasos), Ejercicio (+25)
  - Niveles: Cada 1000 XP
  - Racha: Días consecutivos
  - Logros: Hitos desbloqueables
  - Retos: Desafíos semanales
  - Ranking: Ligas (Bronce/Plata/Oro/Diamante)
  - Tienda: NutriCoins → cosméticos avatar
- Imagen: Captura de ranking + tienda + avatar

---

### 15. **RESPONSIVE**
- Título: "Funciona en Todo"
- Puntos:
  - Mobile: 375×812 (sin scroll horizontal)
  - Desktop: 1366×768+ (interfaz completa)
  - Tablets: Adaptable
  - Navegación: Barra inferior móvil, adaptable desktop
- Imagen: Comparativa mobile vs desktop

---

### 16. **BACKLOG & DESARROLLO**
- Título: "Estado del Proyecto"
- Tabla resumen:
  - 43 historias de usuario
  - 40 completadas ✅
  - 2 en revisión ⚠️ (email, Asistente generativo)
  - 1 mejora futura (accesorios 3D)
- Gráfico: Porcentaje completado

---

### 17. **DEMO EN VIVO**
- Título: "Demostración Práctica"
- Puntos (sin mostrar todavía):
  1. Login / Registro
  2. Onboarding → cálculo automático
  3. Registrar comida + gráfico actualizado
  4. Escaneo IA (foto o simulación)
  5. Mi Progreso + Chart.js
  6. Exportar PDF
  7. QR de rutina
  8. QR Social + agregar amigo
  9. Explorar + mapa
  10. Asistente (si da tiempo)
- Nota: "Live demo en localhost"

---

### 18. **CIERRE & MEJORAS FUTURAS**
- Título: "Próximas Etapas"
- Completado:
  - Plataforma funcional
  - Seguridad implementada
  - IA integrada
  - Comunidad operativa
- Por hacer:
  - Deploy HTTPS (Heroku)
  - Email real (SMTP)
  - Notificaciones push
  - Integración wearables
  - Modelos 3D para accesorios
- Imagen: Roadmap visual

---

## NOTAS DE DISEÑO

- **Colores**: Usa la paleta de NutriFit (verde limón, azul, gris)
- **Fuentes**: Sans-serif moderna (Segoe, Arial, Roboto)
- **Imágenes**: Capturas reales de la app en localhost (no mock-ups)
- **Animaciones**: Subtiles (fade in, slide)
- **Tiempo**: ~1.5 min por diapositiva = 27 diapositivas máximo

---

## TRANSICIONES SUGERIDAS

- Problema → Solución (contraste)
- Funcionalidades → Tecnologías (detalle)
- Arquitectura → BD (base de datos)
- IA → Gráficos (visualización)
- Gamificación → Responsive (experiencia)
- Backlog → Demo (prueba real)
- Demo → Cierre (conclusión)

---

**Última actualización**: 6 de octubre de 2026
**Versión**: 2.0 (incluye QR Social en diapositiva 12)
