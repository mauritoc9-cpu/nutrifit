# Guía de Actualización — NutriFit_Guion_Exposicion.docx

Guion detallado para la presentación (15-20 minutos) + Demo en vivo.

---

## PARTE 1: PRESENTACIÓN (Diapositivas)

### [1] PORTADA (30 seg)
**Decir:**
"Buenos días/tardes. Me llamo Mauro Cerdan y presento NutriFit, una aplicación web gamificada de nutrición, hidratación y entrenamiento. El proyecto fue desarrollado como tesis de Informática a lo largo de este año."

**Mostrar:**
- Portada con logo y datos

**Transición:**
"Pero antes de hablar de la app, quería contar qué problema intenta resolver."

---

### [2] PROBLEMA (1.5 min)
**Decir:**
"Muchas personas desean mejorar su salud pero enfrentan un problema común: **pierden motivación sin retroalimentación inmediata**. Además, no saben cuánto comer realmente, entrenar solo es aburrido, y la falta de asesoramiento personalizado hace que cometan errores.

¿Qué pasaría si tuviéramos una herramienta que:
- Calculara automáticamente cuánto necesitas comer
- Te motivara con gamificación
- Te conectara con otros usuarios
- Usara IA para ayudarte
- Mostrara tu progreso de forma clara?

Eso es NutriFit."

**Mostrar:**
- Gráfico de abandono o lista de problemas

**Transición:**
"Permíteme mostrar cómo funciona."

---

### [3] SOLUCIÓN (1 min)
**Decir:**
"NutriFit es una plataforma integral que combina:
- **Personalización**: calcula automáticamente tu metabolismo base y meta calórica
- **Gamificación**: ganas XP por registrar comidas, beber agua, hacer ejercicio
- **IA**: analiza fotos de comidas y responde preguntas personalizadas
- **Comunidad**: amigos, ranking, competencia sana
- **Visualización**: gráficos que te muestran el progreso en tiempo real"

**Mostrar:**
- Captura de home/dashboard

**Transición:**
"¿Específicamente, qué se puede hacer?"

---

### [4] FUNCIONALIDADES (2 min)
**Decir:**
"La app tiene 9 secciones principales:

1. **Mi Progreso**: tu nivel, XP, racha de días, avatar personalizado
2. **Nutrición**: registro de comidas, búsqueda en catálogo de 1500 alimentos
3. **Escaneo**: tomas foto de la comida y la IA la analiza automáticamente
4. **Actividad**: seguimiento de pasos y ejercicios
5. **Mi Progreso avanzado**: gráficos interactivos de peso y actividad, exportación PDF/CSV
6. **Ranking**: compite en ligas (Bronce, Plata, Oro, Diamante)
7. **Perfil**: avatar 3D con accesorios que compras con puntos
8. **Explorar**: mapa para encontrar gimnasios, parques, nutricionistas cerca tuyo
9. **Asistente**: chatbot que responde preguntas con contexto de tus datos

Todo funciona en mobile y desktop sin problemas."

**Mostrar:**
- Capturas pequeñas de las 9 vistas (grid 3x3)

**Transición:**
"Ahora bien, ¿cómo está construida técnicamente?"

---

### [5] TECNOLOGÍAS (1 min)
**Decir:**
"El stack es moderno pero no excesivamente complejo:
- **Backend**: PHP 8.2 con patrón OOP y PDO
- **Base de datos**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript vanilla (sin frameworks pesados)
- **Gráficos**: Chart.js 4.4, que es interactivo y ligero
- **IA**: Gemini 2.0 Flash de Google para análisis de fotos y chat
- **QR**: librerías open source (qrcode.js, qr-scanner)
- **3D**: Canvas personalizado para el avatar robot

La elección fue intencionada: máxima compatibilidad, mínimas dependencias."

**Mostrar:**
- Tabla o logos de tecnologías

**Transición:**
"Esto significa una base de datos cuidadosamente diseñada. Veamos:"

---

### [6] BASE DE DATOS & SEGURIDAD (1.5 min)
**Decir:**
"Tenemos 18+ tablas normalizadas que guardan:
- Usuarios con datos biométricos
- Histórico de comidas
- Amistades y solicitudes
- XP, logros, retos completados
- Avatar y accesorios
- Rutinas e importaciones de QR

Todo está protegido:
- **Contraseñas**: bcrypt con cost=12, nunca en claro
- **Consultas**: PDO con sentencias preparadas, imposible SQL injection
- **Sesiones**: HttpOnly, SameSite, Secure en HTTPS
- **Validaciones**: en servidor, no client-side
- **Privacidad**: cada usuario solo ve sus datos y los públicos de amigos"

**Mostrar:**
- Diagrama de tablas (usuario → comidas → amigos → logros, etc.)

**Transición:**
"La seguridad fue prioridad porque usamos datos sensibles. Hablando de datos sensibles, la IA es una parte central:"

---

### [7] IA & ESCANEO (1.5 min)
**Decir:**
"Tenemos dos sistemas de IA:

1. **Escaneo de Alimentos**:
   - Tomas una foto de la comida
   - Gemini Vision analiza automáticamente
   - Estima el alimento y cantidad
   - **Vos confirmas** antes de registrar
   - Si no reconoce, buscas manualmente
   - Esto garantiza que los datos sean correctos

2. **Asistente Contextualizado**:
   - Es un chatbot que tiene acceso a tus datos
   - Sabe cuánto pesas, cuál es tu meta, qué comiste hoy
   - Puede responder preguntas sobre nutrición, entrenamiento, salud
   - **Actualmente deshabilitado para planes personalizados** porque requiere confirmación del usuario sobre privacidad
   - Pero responde preguntas factales sin problemas

La IA no reemplaza profesionales, pero sí ayuda."

**Mostrar:**
- Captura de escaneo IA (foto → resultado)
- Captura del chatbot

**Transición:**
"Pero no solo es IA. La motivación también viene de visualizar tu progreso:"

---

### [8] MI PROGRESO & GRÁFICOS (1.5 min)
**Decir:**
"La sección 'Mi Progreso' es la joya de la corona:
- Ves tu XP actual, nivel, racha de días
- Hay dos gráficos interactivos:
  1. **Gráfico de Peso**: línea que muestra tu evolución (últimos 7 días, 30 días, o todo)
  2. **Gráfico de Actividad**: barras con pasos, ejercicios
- Ambos se actualizan en tiempo real
- Puedes compararlos con el período anterior
- Y todo se puede exportar:
  - **PDF**: informe completo con gráficos, datos, resumen
  - **CSV**: datos tabulares para análisis en Excel

Esto es lo que mantiene la motivación: ver que tu trabajo tiene resultado."

**Mostrar:**
- Captura de Mi Progreso con ambos gráficos
- Ejemplo de PDF exportado

**Transición:**
"Pero la motivación también viene de compartir y competir. Por eso existe el sistema de QR:"

---

### [9] QR DE RUTINAS (1 min)
**Decir:**
"Si creas una rutina (una secuencia de ejercicios), puedes compartirla:
- Presionas 'Compartir Rutina'
- Se genera un QR único, válido por 7 días
- Otro usuario escanea el QR con su cámara
- Ve una previsualización de los ejercicios
- Decide importar → se crea una **copia independiente**
- La copia no afecta tu rutina original ni te da XP extra

El token es seguro: generamos 32 bytes aleatorios, pero solo guardamos el hash SHA256 en la base de datos."

**Mostrar:**
- Captura de QR generado
- Flujo: generar → escanear → previsualizar → importar

**Transición:**
"Pero hay algo nuevo que agregamos recientemente:"

---

### [10] QR SOCIAL (1.5 min) — **NUEVA FUNCIONALIDAD**
**Decir:**
"Además de compartir rutinas, cada usuario tiene un **código QR personal** que permite agregar amigos de forma rápida y segura.

¿Cómo funciona?
1. Vas a tu perfil y ves 'Mi QR'
   - Es un código estable que no caduca
   - Puedes regenerarlo en cualquier momento
   - Lo muestras a un amigo

2. Tu amigo abre la app, va a 'Escanear QR'
   - Escanea tu código con la cámara
   - O sube una imagen que tenga el QR
   - O lo copia manualmente si la cámara no funciona

3. Aparece un preview con tu nombre y nivel
   - **Solo información pública** (sin email, sin peso, sin datos privados)
   - Se respeta tu privacidad

4. Presiona 'Enviar solicitud'
   - Es una **solicitud normal** que requiere que aceptes
   - No se hacen amigos automáticamente

**Seguridad**: generamos un token impredecible (32 bytes), pero solo guardamos su hash SHA256 en la base de datos. El token nunca se ve en logs ni se transmite sin cifrar.

Esto es especialmente útil en eventos, gimnasios, o cuando quieres agregar gente rápidamente."

**Mostrar:**
- Captura de 'Mi QR' (código visible)
- Captura de escaneo
- Captura de preview
- Captura de solicitud enviada

**Transición:**
"La comunidad es importante. Por eso existe el Ranking:"

---

### [11] EXPLORAR & MAPAS (1 min)
**Decir:**
"Tenemos un mapa interactivo donde puedes:
- Buscar gimnasios, parques, nutricionistas cerca tuyo
- Ver detalles: dirección, horarios, contacto
- Filtrar por tipo
- Usar tu ubicación actual si lo autorizas

Es útil cuando quieres entrenar o ver un profesional."

**Mostrar:**
- Captura del mapa con POI

**Transición:**
"Ahora bien, ¿qué te motiva a seguir usando la app?"

---

### [12] GAMIFICACIÓN (1.5 min)
**Decir:**
"La gamificación está en todas partes:

**XP**: ganas puntos por:
- Registrar comida: +10 XP
- Beber agua: +5 XP cada 3 vasos
- Completar ejercicio: +25 XP
- Hay un límite diario anti-farming

**Niveles**: acumulas XP → subes de nivel
- Cada nivel desbloqueado: +100 NutriCoins

**Racha**: contador de días consecutivos con actividad
- Si pierdes la racha, vuelve a empezar
- Notificaciones de recordatorio

**Logros**: hitos desbloqueables (100 comidas, Nivel 10, etc.)

**Retos**: desafíos semanales con recompensa

**Ranking**: compite con otros usuarios
- Ranking global por XP
- Divisiones por rango (Bronce, Plata, Oro, Diamante)
- Ranking privado solo con tus amigos

**Tienda**: usa NutriCoins para:
- Marco de avatar
- Aura personalizada
- Título especial

Cuando subes de nivel, desbloqueamos accesorios en la tienda. Es un círculo virtuoso: registrar datos → XP → sube nivel → más dinero virtual → compra cosméticos → ve tu avatar más cool → más motivación."

**Mostrar:**
- Captura de ranking (ligas)
- Captura de tienda con accesorios
- Captura de avatar con accesorios equipados

**Transición:**
"Pero la app funciona en cualquier dispositivo:"

---

### [13] RESPONSIVE (45 seg)
**Decir:**
"Fue testeada en:
- **Mobile** (375×812): navegación fluida, botones táctiles, sin scroll horizontal
- **Desktop** (1366×768): interfaz completa aprovechando espacio
- **Tablets**: diseño adaptable

Todo funciona en Chrome, Firefox, Safari, Edge, versiones modernas."

**Mostrar:**
- Comparativa móvil vs desktop (split screen)

**Transición:**
"Hablando del estado del proyecto:"

---

### [14] BACKLOG & DESARROLLO (1 min)
**Decir:**
"Tenemos un backlog de 43 historias de usuario:
- 40 completadas ✅
- 2 en revisión ⚠️ (recuperación por email falta el servidor SMTP, Asistente generativo deshabilitado por privacidad)
- 1 mejora futura 📌 (accesorios 3D de avatar, requieren modelos que aún no tenemos)

Especialmente:
- ✅ Autenticación completa
- ✅ Nutrición e IA funcional
- ✅ Gamificación integral
- ✅ QR de rutinas + QR Social
- ✅ Ranking y amigos
- ✅ Exportación PDF/CSV
- ✅ Responsive completo

**Deploy**: aún en localhost. Para producción necesitamos HTTPS (Heroku con JawsDB)."

**Mostrar:**
- Tabla o gráfico de completitud (40/43)

**Transición:**
"Ahora les muestro todo en vivo."

---

## PARTE 2: DEMO EN VIVO (5-8 minutos)

**Preparación previa:**
- Tener localhost corriendo (`http://localhost/nutrifittwo/frontend/`)
- Dos navegadores o dos tabs abiertos (para mostrar amigos)
- Capturas de respaldo (en caso de problema con cámara, IA, etc.)

### [DEMO 1] LOGIN & ONBOARDING (1 min)
**Qué hacer:**
1. Mostrar pantalla de login
2. Hacer login con usuario de prueba
3. Mostrar onboarding (altura, peso, objetivo)
   - Explicar: "El backend calcula automáticamente tu TMB y meta calórica"
4. Completar onboarding, acceder a home

**Decir:**
"Primero, creamos una cuenta e ingresamos datos básicos. El sistema calcula automáticamente cuántas calorías necesitas usando la fórmula Mifflin-St Jeor."

---

### [DEMO 2] REGISTRAR COMIDA & PROGRESO VIVO (1.5 min)
**Qué hacer:**
1. Ir a sección Nutrición
2. Registrar una comida (buscar "arroz con pollo" u otro)
3. Confirmar cantidad
4. Ver que aparece en el dashboard
5. Ir a Mi Progreso → ver que los gráficos se actualizaron

**Decir:**
"Registramos una comida. El sistema automáticamente:
- Calcula calorías y macros
- Actualiza el anillo de progreso
- Te da +10 XP
- Actualiza los gráficos en tiempo real"

---

### [DEMO 3] MI PROGRESO & CHART.JS (1 min)
**Qué hacer:**
1. Ir a sección Mi Progreso
2. Mostrar gráfico de peso (línea)
3. Mostrar gráfico de actividad (barras)
4. Pasar mouse sobre los gráficos para ver tooltips
5. Cambiar período (7 días, 30, todos)

**Decir:**
"Aquí ves tu evolución. Los gráficos son interactivos: pasas el mouse y ves valores exactos. Puedes cambiar el período de análisis."

---

### [DEMO 4] EXPORTACIÓN PDF (45 seg)
**Qué hacer:**
1. En Mi Progreso, hacer click en 'Exportar PDF'
2. Mostrar el diálogo (seleccionar período)
3. Descargar
4. Abrir el PDF en otra tab
5. Mostrar que tiene gráficos, datos, resumen

**Decir:**
"Toda esta información se puede exportar a PDF. También hay opción de CSV para análisis en Excel."

---

### [DEMO 5] QR DE RUTINA (1.5 min)
**Qué hacer:**
1. Ir a sección Actividad
2. Hacer click en 'Compartir Rutina'
3. Mostrar QR generado
4. Opcionalmente, escanear con otro dispositivo/tab
   - Si no funciona: mostrar captura de respaldo
5. Mostrar preview de la rutina

**Decir:**
"Las rutinas se pueden compartir por QR. Otro usuario escanea, ve una previsualización, y decide importar. Crea una copia que no afecta la original."

---

### [DEMO 6] QR SOCIAL & AGREGAR AMIGO (2 min)
**Qué hacer:**
1. Ir a sección Ranking
2. Tab de Amigos
3. Click en 'Mi QR'
4. Mostrar código QR personal
5. Cerrar ese modal
6. Click en 'Escanear QR'
   - Opción: escanear código de otro usuario (si está en otra tab/navegador)
   - O mostrar captura de respaldo del flujo
7. Mostrar preview: nombre + nivel
8. Enviar solicitud
9. Mensaje de confirmación

**Decir:**
"Este es el QR Social que agregamos recientemente. Cada usuario tiene su código personal. Otro usuario lo escanea, ve tu nombre y nivel (solo info pública), y puede enviar solicitud de amistad. Todo es seguro: no exponemos email ni datos privados."

---

### [DEMO 7] EXPLORAR & MAPA (1 min)
**Qué hacer:**
1. Ir a sección Explorar
2. Mostrar mapa
3. Buscar un lugar (ej. "gimnasio")
4. Mostrar resultados en el mapa
5. Click en un POI para ver detalles

**Decir:**
"Aquí buscas lugares (gimnasios, parques, nutricionistas) cerca tuyo. Integramos mapas y geolocalización."

---

### [DEMO 8] ASISTENTE (45 seg) — si da tiempo
**Qué hacer:**
1. Ir a sección Asistente
2. Hacer una pregunta (ej. "¿Cuánta proteína debo comer?")
3. Mostrar que el chatbot responde con contexto de tus datos

**Decir:**
"El chatbot tiene acceso a tus datos (peso, meta, historial) y responde preguntas personalizadas. Está alimentado por Gemini de Google."

**Si no da tiempo:**
"Omitir, el chat está en la app pero la demostración completa es larga."

---

### [DEMO 9] GAMIFICACIÓN (30 seg) — si da tiempo
**Qué hacer:**
1. Ir a sección Ranking
2. Mostrar ranking global (ligas)
3. Mostrar tienda de accesorios

**Decir:**
"Ves tu posición en el ranking, las ligas, y qué accesorios puedes comprar con tus puntos."

---

## PARTE 3: CIERRE (1 min)

**Decir:**
"Para resumir:
- **NutriFit** es una plataforma gamificada que motiva hábitos saludables
- Integra IA para análisis de fotos y asesoramiento
- Personaliza metas según tu metabolismo
- Conecta usuarios para competir y cooperar
- Está construida con seguridad en mente
- Funciona en cualquier dispositivo
- 40 de 43 funcionalidades completadas, lista para evolucionar

El proyecto demostró que es posible crear una app integral de salud sin frameworks complejos, manteniendo seguridad, escalabilidad y experiencia de usuario.

¿Preguntas?"

**Mostrar:**
- Portada de cierre (roadmap o conclusión)

---

## NOTAS IMPORTANTES

**Timing:**
- Presentación: 14-16 min
- Demo: 5-8 min
- Preguntas: 2-3 min
- **Total: 20-25 min**

**Respaldos:**
- Si la cámara del QR no funciona: tener capturas del escaneo
- Si el escaneo IA no funciona: captura del resultado
- Si la IA (Gemini) no está disponible: captura del chatbot

**Nerviosismo:**
- Practicar el flujo una vez antes
- Tener los datos de login memorizados
- No improvisar cambios grandes

**Público:**
- Tribunal de tesis: técnico, interesado en arquitectura y decisiones
- Compañeros: quieren ver que funciona
- Mostrar **claridad**: explica qué ves, por qué importa

---

**Última actualización**: 6 de octubre de 2026
**Versión**: 2.0 (incluye QR Social en DEMO 6)
