# Asistente NutriFit - revisión de implementación

Fecha: 2026-10-06. Localhost/XAMPP. Sin commit ni staging.

## Arquitectura y alcance

Nueva vista Asistente/NutriFit IA en navegación desktop/mobile. UI -> endpoint autenticado -> AsistenteService -> clasificación local -> ContextoNutriFitService -> respuesta factual o GeminiChatClient -> validación -> texto seguro. No SDK/dependencias nuevas; PHP/cURL/PDO y APIs nativas del navegador.

El asistente no dispone de herramientas, SQL elegido por el modelo, acceso a filesystem, navegación web ni endpoints de escritura. Sólo escribe su propio historial y control de uso. Las consultas reutilizan ResumenSemanalService, RegistroActividadRepository y la lectura de rutina seleccionada de RutinasCompartidasService. No llama al generador de rutinas ni reconciliadores de XP/logros.

## Intenciones y contexto

Clasificación local: calorías/comidas, agua, pasos, rutina/entrenamiento de hoy, cantidad de entrenamientos, actividad, resumen semanal, peso y racha. Intenciones de escritura, medicina, acceso a secretos y períodos no soportados tienen respuestas locales explícitas. Preguntas exactas no consumen Gemini. Hoy o semana calendario de Argentina (lunes-hoy); peso usa últimos treinta días. Diferencia cero de ausencia de registros. Pasos de sesiones son fuente actual; contador histórico se distingue y nunca se suma dos veces.

Gemini se reserva para mejorar comidas/orientación de hábitos. Recibe consulta canónica determinada por backend y agregados de esa intención: fechas, calorías/macros, metas, días registrados y grupos de comida cuando corresponde. No se envía mensaje libre original, nombre/email/ID, perfil biométrico, restricciones médicas, amigos, ubicación, tokens, fotos ni historial completo. Así un correo o síntoma escrito junto a una pregunta no sale inadvertidamente. Datos JSON separados de instrucciones de sistema; no interpretación como instrucciones privilegiadas.

Conversación contextual acotada: intención y período se resuelven con los últimos turnos locales, y datos se consultan nuevamente en backend. No se retransmite historial libre a Gemini; no pretende memoria conversacional ilimitada. Orientación v1 sobre hábitos/comidas; no planes médicos ni acciones.

GeminiChatClient separado del escáner. Modelo predeterminado gemini-3.6-flash verificado con API real: una consulta genérica sin datos personales, respuesta JSON válida. Timeout 25s/conexión 8s, sin reintentos ni fallback pago. Clasifica cuota, conexión, timeout, configuración, bloqueo y respuesta inválida. Orientación no acepta cifras, HTML/URLs o términos de tratamiento: los datos exactos se muestran desde backend, separado del texto generado.

## Privacidad y configuración pendiente

El usuario respondió que no está seguro de tener facturación activa. Por eso no se habilitó enviar datos personales de salud a Gemini; .env real permanece intacto. Sin ASISTENTE_GEMINI_DATOS_HABILITADOS=1, preguntas generativas muestran datos locales y aviso de orientación no disponible. Configuración documentada en .env.example con valor predeterminado 0, modelo configurable mediante ASISTENTE_GEMINI_MODELO.

Para habilitar orientación personalizada: verificar que el proyecto asociado a la clave opera bajo Paid Services/tratamiento adecuado y presupuesto; luego configurar ASISTENTE_GEMINI_DATOS_HABILITADOS=1 en el servidor. No basta la presencia de la clave. Gemini/API/modelo ya respondieron; no falta instalar un proveedor ni obtener una nueva clave por razón técnica.

Referencia de privacidad: https://ai.google.dev/gemini-api/terms . Cuotas por proyecto/modelo: https://ai.google.dev/gemini-api/docs/rate-limits .

## DB e historial

Migración aditiva 018_asistente.sql aplicada: asistente_conversaciones, asistente_mensajes, asistente_control y asistente_uso. Foreign keys con cascada desde usuario/conversación; request id único por conversación y rol; índices de owner/actualización/retención.

Crear/listar/continuar/eliminar con ownership en servidor. Hasta treinta conversaciones y doscientos mensajes por conversación. Guarda solamente texto, roles, timestamps e intención/origen; no payload de contexto, prompts internos, claves o audio. Retención de treinta días desde última actividad, con borrado oportunista acotado a cien conversaciones vencidas por petición, incluidos usuarios inactivos. Sin tráfico, no se ejecuta limpieza física, pero las vencidas no son accesibles. Es una limitación de operar sin cron.

## Límites y seguridad

Dos mil caracteres, tres consultas/minuto persistentes, una solicitud simultánea por usuario con lease de cuarenta y cinco segundos, diez intentos Gemini diarios por usuario reservados atómicamente. Respuestas deterministas no consumen contador Gemini. Las fallas de proveedor también consumen intento para evitar abuso. Idempotencia por solicitud; sesiones liberadas antes de red/DB larga. CSRF propio, validación de origen, límite real de body 16KB, ownership y prepared statements. JSON/errores técnicos nunca se muestran al usuario; texto renderizado con textContent. No se registran prompts ni respuestas crudas del proveedor en logs.

Presupuesto es por usuario, no un monitor global del proyecto Gemini compartido con escáner/recetas/traducción. Configurar presupuesto del proveedor antes de habilitar el modo remoto para múltiples usuarios.

## Frontend, mobile y voz

Estados de carga/pensando, respuesta local, proveedor no disponible, límites y sin conexión. AbortController con timeout y cancelar; doble envío bloqueado. Cancelación cliente no garantiza cancelar una generación ya iniciada en servidor: si terminó puede aparecer en historial. Abort al salir; foco/input utilizables.

375x812 probado sin overflow; mensajes largos y HTML literal seguros, historial persistente tras reload. Altura 375x450 usada para simular espacio disponible con teclado: input y enviar quedan por encima de la navegación. No reemplaza prueba con teclado físico Android/iOS.

Nueva entrada requirió navegación mobile en dos filas para conservar targets táctiles/legibilidad; cambios CSS contenidos en asistente.css y variable de altura existente. Regresión Home, Actividad/Entrenamiento, Nutrición, Escáner, Mi Progreso, Perfil/Colección, Ranking/Tienda y Explorar sin overflow ni errores de consola.

Dictado feature detection SpeechRecognition/webkitSpeechRecognition, idioma es-AR, editable antes de enviar, cancelación y permisos denegados. Puede usar servicio remoto del navegador; informado en interfaz; no guarda audio. TTS manual con speechSynthesis, sólo voz local española para no enviar respuestas de salud a un servicio remoto, detener y cancelar al salir. Sin voz local hay feedback y lectura de texto. En IAB se verificaron botones/cancelación y voz local disponible; captura/transcripción y audio en dispositivo físico pendientes.

## Archivos

Creados:
- backend/classes/asistente/AsistenteService.php
- backend/classes/asistente/ContextoNutriFitService.php
- backend/classes/asistente/GeminiChatClient.php
- backend/api/asistente/conversaciones.php
- database/migrations/018_asistente.sql
- frontend/js/asistente.js
- frontend/css/asistente.css
- tests/asistente.cjs
- tests/asistente-frontend.cjs
- tests/asistente-provider-check.php (sólo CLI; consulta genérica, cada ejecución consume una generación real)
- tests/asistente-375.png
- tests/asistente-preservation.json
- este informe

Modificados: frontend/index.html, frontend/js/app.js y .env.example. Ningún módulo backend de fases cerradas cambió; Exportación/QR, escáner, Gamificación/Social/Avatar, migraciones anteriores y configuración real preservados. 186 archivos originales comparados por hash: sólo esos tres cambios previstos, ninguno faltante.

## Pruebas

44 pruebas backend aprobadas sobre cuentas temporales/DB real: hechos (321 kcal, 8 vasos, 1234 pasos, sesión y entrenamiento), peso/racha/vacío, historial CRUD/retención, IDOR, CSRF/origen/body lógico, tamaño mensaje, rate, duplicación, una solicitud simultánea, no cambios de salud/XP/coins. Proveedor simulado por dependencia CLI para contexto mínimo, generación válida, límite diario y errores timeout/cuota/respuesta inválida; nunca accesible desde endpoint real. Validación JSON/vacío/cifras del cliente probada por transporte controlado.

18 pruebas frontend aprobadas: feature detection sin soporte, es-AR, dictado editable/sin autoenvío, cancelar/permisos denegados, síntesis local/detener/salir, doble envío, HTML seguro, carga/cancelación y recuperación de input. APIs de voz simuladas en estas pruebas unitarias; UI real además probada.

65 pruebas de integridad existentes aprobadas. Sintaxis PHP/JS válida; git diff --check sin errores y staging vacío. Todas las cuentas temporales creadas (incluidas corridas preliminares fallidas) retiradas; tests normales limpian en finally. Captura sólo de fixture.

Solicitudes reales Gemini durante esta fase: EXACTAMENTE UNA generación, genérica, respuesta válida. Suites restantes usan cero solicitudes reales; los escenarios de datos de cuenta con Gemini se verificaron mediante simulación y bloqueo de privacidad.

## Pendientes reales

Confirmar Paid Services y habilitar configuración remota; después validación end-to-end con contexto personal autorizado. Dictado/audio y teclado en teléfono físico. Retención física depende de tráfico por diseño sin cron; cuota global depende del proyecto/proveedor. No se agregaron funciones de escritura, web/tools, IA médica, servicios pagos de voz o nuevas funciones sociales.

ASISTENTE IA = REQUIERE CONFIGURACIÓN
