# Asistente conversacional de NutriFit — implementación local

Fecha: 8 de octubre de 2026. Cambios en XAMPP; sin modificaciones en Heroku, JawsDB o producción, sin commit/push/reset/clean. La migración 023 se explicó antes de ejecutarse y se aplicó únicamente en `nutrifit_db` local. No se aplicó la migración 022.

## 1. Funcionalidades implementadas

- Preguntas generales e ingredientes escritos libremente pasan a Gemini, conservando el texto de la consulta. Ya no se reemplazan por una pregunta genérica.
- Memoria de opciones y referencias mediante mensajes recientes del historial existente. El cambio de tema no conserva automáticamente una propuesta de comida.
- Respuesta JSON validada: conversación, solicitud de consulta local o componentes para una estimación. No hay herramientas SQL ni permisos de escritura para el proveedor.
- Calorías y macros calculados con el catálogo y el motor nutricional existente. El chat prioriza alimentos locales curados y la preparación indicada: papa al horno no se convierte en un snack envasado llamado “Papas”.
- Propuestas muestran alimento realmente encontrado, gramos totales, preparación, supuestos, nutrientes y advertencias. Si falta una coincidencia confiable no se ofrece registrar un plato incompleto. No se inventa un porcentaje de incertidumbre.
- Confirmación mediante el botón **Confirmar y registrar en mi día**, con tipo de comida elegido. Usa el endpoint real de comidas. “Sí”/“dale” en el texto no ejecutan una escritura: el asistente indica cómo revisar y confirmar la propuesta.
- Registro transaccional: todos los componentes o ninguno. XP de comida una vez por plato, preservando el bono de racha, límites diarios y reconciliación de logros/retos existente. Reintentos y confirmaciones concurrentes devuelven el mismo resultado.
- Preguntas sobre registros continúan funcionando localmente con consentimiento, incluso con Gemini sin cuota. Incluyen calorías, macros, metas/objetivo, agua, pasos, actividad, rutina, entrenamientos, peso y resumen.
- Recetas generales conversacionales y respaldo explícito sin IA. Se comparten cálculo nutricional y pasos de respaldo con `GeneradorRecetasIA`; no se reemplazó el recetario personalizado ni su caché.
- Panel flotante, historial plegable, sugerencias opcionales, burbujas legibles, indicador de respuesta, scroll interno y reintento. Se conservan las cinco pestañas y la voz existente.

## 2. Archivos de aplicación modificados o agregados

| Archivo | Cambio |
|---|---|
| `backend/classes/asistente/AsistenteService.php` | Conversación, permisos, memoria filtrada, consulta de registros, propuestas, respuestas idempotentes y respaldo. |
| `backend/classes/asistente/GeminiChatClient.php` | Prompt conversacional, schema JSON, validación, pensamiento mínimo, límite de salida y timeout. |
| `backend/classes/asistente/ContextoNutriFitService.php` | Objetivo/metas y macros completos, usando registros reales. |
| `backend/classes/asistente/AsistenteComidaService.php` (nuevo) | Resolución de porciones y creación de propuestas del servidor. |
| `backend/classes/nutricion/ComidaRegistroService.php` (nuevo) | Pipeline compartido de registro y acciones confirmadas/idempotentes. |
| `backend/classes/NutricionResolver.php` | Entrada específica del chat que reutiliza búsqueda, scoring y validación sin consultas a APIs nutricionales externas. |
| `backend/classes/nutricion/GeneradorRecetasIA.php` | Helpers compartidos de cálculo y pasos de respaldo. |
| `backend/api/asistente/conversaciones.php` | Permisos separados por conversación, entrega de historial/propuestas y validaciones existentes. |
| `backend/api/nutricion/registrar_comida.php` | Delegación al servicio compartido; rama de confirmación del asistente con CSRF y propuesta propia. Registro manual/cámara y eliminación conservados. |
| `frontend/js/asistente.js` | Chat, permisos, propuestas revisables, confirmación, reintento con ID estable y voz. |
| `frontend/css/asistente.css` | Distribución del panel y móvil; se quitó una regla antigua que alteraba la navegación global. |
| `frontend/index.html` | Versionado de assets del asistente a `v=6`. |
| `.env.example` | Documentación y límite conversacional por minuto; `.env` real no se modificó. |
| `database/migrations/023_asistente_conversacional.sql` (nuevo) | Tres tablas aditivas de permisos, respuestas y acciones. |

Pruebas agregadas: `tests/asistente-conversacional.php`, `tests/asistente-chat-contract.php`, `tests/asistente-registro-http.cjs`, `tests/apply-asistente-local.php`, `tests/asistente-ui-fixture.php`. Pruebas actualizadas: `tests/asistente.cjs`, `tests/asistente-frontend.cjs`, `tests/asistente-provider-check.php`. Evidencias: `tests/asistente-conversacional-mobile.png`, `tests/asistente-conversacional-desktop.png`. Este informe también es nuevo.

## 3. Comunicación con Gemini

La interfaz hace POST autenticado a `backend/api/asistente/conversaciones.php`, con CSRF, conversación propia y UUID de solicitud. El servicio PHP conserva límites y llama a `GeminiChatClient`. La clave se obtiene exclusivamente en el servidor con `env('GEMINI_API_KEY')` y se envía en el header `x-goog-api-key`, nunca al navegador ni al modelo dentro del prompt.

El cliente usa `generateContent`, JSON estructurado, hasta 2048 tokens de salida, timeout de 22 segundos y conexión de 5 segundos. El modelo se configura con `ASISTENTE_GEMINI_MODELO`; se mantuvo el valor existente `gemini-3.6-flash`. En modelos `gemini-3` se pide pensamiento mínimo. No se muestran partes marcadas como pensamiento.

Ejemplo del contrato del proveedor:

```json
{
  "respuesta": "Con esos datos puedo estimar tu plato. Revisá los pesos asumidos.",
  "consulta": "ninguna",
  "periodo": "hoy",
  "estimar": true,
  "alimentos": [
    {"nombre":"milanesa de carne","gramos":240,"supuesto":"Dos unidades de 120 g; no pesadas.","preparacion":"al horno"},
    {"nombre":"papa","gramos":180,"supuesto":"Una papa mediana; no pesada.","preparacion":"al horno"}
  ]
}
```

El proveedor no determina IDs, calorías, XP ni registros. El backend valida campos, porciones (hasta ocho componentes, 2000 g por componente), resuelve alimentos y calcula nutrientes. Texto y nombres se renderizan mediante `textContent`. JSON incompleto, salida truncada, cifras nutricionales no permitidas o afirmaciones de escritura se rechazan. Estos controles reducen errores del modelo; no sustituyen revisar la coincidencia y los supuestos.

## 4. Contexto e idempotencia

- Hasta 12 mensajes previos, con un total máximo de 10.000 caracteres y hasta 2200 por mensaje; no se envía el historial completo.
- Se conserva el texto generativo que contiene las opciones. Los valores obtenidos localmente no se retransmiten mediante la burbuja del historial.
- Correos y patrones conocidos de claves se redactan. Mensajes clasificados como médicos o de seguridad se omiten del contexto externo. La redacción es básica: el aviso de no escribir información sensible sigue siendo necesario.
- Si se revoca el permiso de compartir registros, la orientación previamente marcada como personalizada se omite del historial enviado.
- Historial de hasta 200 mensajes por conversación, hasta 30 conversaciones y retención de 30 días sin actividad.
- UUID persistente en reintentos de red; respuestas y código original se recuperan sin otra generación. Un fallo ya respondido del proveedor permite intentar una generación nueva.
- Límite predeterminado: seis solicitudes por minuto (configurable y acotado entre 1 y 12), una en curso por usuario y diez intentos generativos por día. Fallos del proveedor también consumen el cupo diario local. Se amplió el límite por minuto para permitir el ejemplo de cuatro turnos sin interrumpirlo tras la tercera pregunta.
- Una porción idéntica en la misma conversación y fecha reutiliza su propuesta. Para registrar otra comida idéntica en ese mismo día, empezá otra conversación. La propuesta vence como máximo a las 24 horas y sólo permite un registro nuevo el día en que se creó.

## 5. Datos de NutriFit y privacidad

Los permisos son independientes y predeterminados en falso para cada conversación:

1. **Usar Gemini para mis mensajes:** autoriza enviar el texto escrito y la ventana reciente a Google. No autoriza enviar registros de la cuenta.
2. **Consultar mis datos en NutriFit:** autoriza consultas locales con el usuario de la sesión. No requiere habilitar envío al proveedor.
3. **Compartir los registros relevantes con Gemini:** requiere los dos anteriores y `ASISTENTE_GEMINI_DATOS_HABILITADOS=1` en el servidor. Ese flag no se habilitó. Los permisos se pueden revocar.

`ContextoNutriFitService` obtiene los números desde los servicios/tablas existentes, distinguiendo consumo registrado de consumo real, falta de datos de cero, promedios semanales y fuentes separadas del contador de pasos. Sólo se selecciona la intención solicitada. Si el envío externo se autoriza, se eliminan identificadores; no se envían credenciales, perfil completo ni historial médico.

Para consultas generales no es necesario activar el flag de datos personales. Lo que el usuario escribe libremente sí se transmite con el primer permiso, por eso la interfaz advierte que no incluya secretos o datos sensibles.

El proveedor nunca escribe en MySQL. La interfaz confirma la propuesta mediante POST a `nutricion/registrar_comida.php` con token propio, `confirmado: true`, tipo de comida y CSRF. Se bloquea el usuario y la acción, se vuelven a leer los alimentos y se verifica que el catálogo no haya cambiado. Una repetición no inserta ni recompensa nuevamente.

## 6. Verificación realizada

| Prueba | Resultado |
|---|---|
| `asistente-conversacional.php` | 50 comprobaciones locales con transporte Gemini simulado: ingredientes, opciones, seguimiento, cambio de tema, estimación, preparación, privacidad, revocación, datos reales, cuota/timeout/conexión, confirmación y XP. |
| `asistente.cjs` | 39 comprobaciones HTTP reales de XAMPP: sesión, CSRF/origen, permisos, IDOR, longitud, controles de uso, datos reales, objetivo, retención e historial; sin llamadas a Gemini. |
| `asistente-registro-http.cjs` | 13 comprobaciones HTTP reales: confirmación estricta, sesión, CSRF, propiedad del token, propuesta vencida, confirmación concurrente, recompensa única y registro manual. |
| `asistente-frontend.cjs` | 24 comprobaciones de interfaz en VM: escape de texto, doble envío, ID de reintento, propuesta/confirmación y dictado/síntesis simulados. |
| `asistente-chat-contract.php` | Ocho comprobaciones del contrato de transporte simulado, schema, contexto, pensamientos y respuestas inválidas. |
| `integrity.cjs` | 65 comprobaciones de integridad de módulos existentes, con cuentas temporales locales. |
| Gemini real, consulta de ejemplo genérica | La API devolvió HTTP 429; no se obtuvo respuesta generativa válida. No se envió información personal de una cuenta real. |
| Navegador, desktop 1366×900 y móvil 375×812 | Panel real, permisos, consultas locales, historial y registro de cena: milanesa al horno 240 g + papa al horno 180 g, 685,2 kcal calculadas desde catálogo. Se verificó su aparición en el diario y botón deshabilitado al reabrir. |
| Navegador, error del proveedor real | Respaldo local de receta y botón Reintentar visibles después del límite de Gemini. |

La evidencia de porciones indica explícitamente “Prueba local, sin llamada a Gemini”; no constituye una demostración de conversación generativa real. No se observaron desbordes horizontales a 375 px y el campo usa fuente de 16 px. Se conservan cinco pestañas. Las cuentas creadas para esta verificación se eliminan al finalizar.

Pendientes: repetir el ejemplo multi-turn con Gemini real cuando el proyecto/modelo disponga de cuota, comprobar calidad de recetas en preguntas variadas y probar micrófono/voz con hardware real, incluyendo navegadores móviles. No se afirma que el dictado funcione en todos los celulares: conserva detección de capacidades, transcripción editable y envío manual. La síntesis elige voz española local; si no existe avisa y no usa una API paga.

## 7. Configuración necesaria

La clave local está configurada, pero la prueba real obtuvo límite 429. Revisá cuota y disponibilidad del modelo en Google AI Studio. Una clave adicional del mismo proyecto comparte sus límites; crear otra clave no aumenta por sí solo la cuota. Referencia oficial: [Límites de Gemini](https://ai.google.dev/gemini-api/docs/rate-limits).

```dotenv
GEMINI_API_KEY=<clave privada del servidor con cuota disponible>
ASISTENTE_GEMINI_MODELO=gemini-3.6-flash
ASISTENTE_LIMITE_MINUTO=6
ASISTENTE_GEMINI_DATOS_HABILITADOS=0
```

No compartas la clave por el chat ni la coloques en JavaScript. Mantener el último flag en cero permite recetas generales y consultas locales; el envío de registros necesita una decisión expresa sobre el tratamiento externo de datos. No se contrataron servicios ni se cambiaron credenciales o facturación.

## 8. Cómo probarlo en XAMPP

1. Iniciá Apache y MySQL. En este equipo la migración local 023 ya está aplicada. En otra instalación local que ya tenga la 018, usá `C:/xampp/php/php.exe tests/apply-asistente-local.php`; el script rechaza JawsDB y una base distinta de `nutrifit_db` en este equipo.
2. Abrí `http://localhost/nutrifittwo/frontend/`, iniciá sesión y recargá para cargar los assets nuevos.
3. Abrí el asistente flotante. En Privacidad activá **Usar Gemini para mis mensajes** si querés autorizar enviar tu conversación a Google. Para consultar tus números activá **Consultar mis datos en NutriFit**. No hace falta compartir registros con Gemini.
4. Preguntá “Hoy tengo milanesas, carne, tomate, cebolla y papas. ¿Qué puedo comer?”. Continuá con la opción elegida, cantidades y preparación. Con el límite 429 actual verás un respaldo, no una respuesta de IA; ese bloqueo externo debe resolverse antes de verificar la conversación generativa completa.
5. Cuando haya una propuesta, revisá los nombres del catálogo y gramos totales. Pedí corregirlos por chat si hace falta. Elegí el tipo de comida y pulsá **Confirmar y registrar en mi día**. Luego abrí Nutrición y verificá el diario. Reabrir el historial debe mostrar **Ya registrada** y su tipo real.
6. Preguntá por calorías consumidas, macros u objetivo: con permiso local responde desde tus registros, incluso sin cuota Gemini. “¿Y esta semana?” conserva la intención de una consulta previa de registros.
7. Para ejecutar pruebas locales: `php tests/asistente-conversacional.php`, `php tests/asistente-chat-contract.php`, `node tests/asistente.cjs`, `node tests/asistente-registro-http.cjs`, `node tests/asistente-frontend.cjs`. Las suites de DB usan cuentas descartables. `php tests/asistente-provider-check.php` es distinto: intenta llamadas reales y consume cuota, pero usa sólo el ejemplo genérico.
