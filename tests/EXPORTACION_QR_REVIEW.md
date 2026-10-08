# Exportación de progreso y QR de rutinas — revisión final

Fecha: 2026-10-06. Entorno: localhost/XAMPP. Sin commit ni staging.

## Arquitectura

ExportarProgresoService obtiene exclusivamente datos del usuario autenticado, con parámetros preparados y lectura consistente. Reutiliza ResumenSemanalService y registros existentes para peso, comidas, hidratación, entrenamientos, actividad y pasos. El contador de pasos se distingue de los pasos registrados en sesiones para evitar sumarlos dos veces. XP y nivel se identifican como estado actual, no como ganancias del período.

El endpoint POST progreso/exportar.php valida períodos 7/30/todo y formato pdf/csv, rechaza parámetros extra (incluido user_id), libera la sesión y devuelve el archivo directamente con no-store/private. No guarda informes en URLs públicas. PDF A4 con tablas, fechas, paginación y fuente UTF-8; HTML escapado, recursos remotos/PHP/JS deshabilitados. CSV con BOM UTF-8, separador punto y coma y protección de fórmulas. El frontend muestra preparación, resultado/error y dispone de timeout y cancelación al salir.

RutinasCompartidasService genera códigos de 256 bits, guarda solamente SHA-256 del token y una instantánea de ejercicios. Expiran a los 7 días, son revocables; generar otro QR invalida el anterior. El QR no es una URL y no contiene IDs ni datos de cuenta. Preview autenticado expone exclusivamente campos de rutina. Importación transaccional con bloqueo por usuario, copia independiente y detección de duplicados por contenido. Elegir una copia persiste sin reemplazar la rutina personalizada. Importar/seleccionar no concede XP; completar ejercicios/entrenamiento usa las protecciones existentes.

## Dependencias

- Dompdf 3.1.6, instalado localmente en backend/vendor, Composer backend/composer.json y lock reproducibles. Configuración allow-plugins=false. Auditoría Composer sin avisos de vulnerabilidad conocidos.
- qrcode-generator 2.0.4 (MIT): generación SVG local y carga bajo demanda.
- qr-scanner 1.4.2 (MIT): cámara/imagen con worker local, carga bajo demanda.
- Licencias y scripts guardados en frontend/js/qr-libs.
- Fuentes oficiales: https://github.com/dompdf/dompdf ; https://github.com/kazuhikoarase/qrcode-generator ; https://github.com/nimiq/qr-scanner .

## Archivos

Creados: backend/classes/ExportarProgresoService.php; backend/classes/entrenamiento/RutinasCompartidasService.php; backend/api/progreso/exportar.php; backend/api/entrenamiento/compartir_rutina.php; backend/composer.json; backend/composer.lock; database/migrations/017_exportacion_qr_rutinas.sql; frontend/js/exportacion-qr.js; frontend/css/exportacion-qr.css; frontend/js/qr-libs/*; tests/exportacion-qr.cjs; tests/qr-frontend.cjs; este informe, evidencia mobile y preservación.

Modificados en esta fase: backend/api/entrenamiento/rutina_recomendada.php; frontend/js/app.js; frontend/js/icons.js; frontend/index.html. Los otros archivos heredados se verificaron contra su hash inicial y permanecen intactos; ningún archivo previo falta. .env no fue leído ni incluido en evidencia. Staging vacío.

## Base de datos

Migración aditiva 017 aplicada: rutina_comparticiones y rutina_importaciones, claves foráneas, token_hash único y deduplicación por usuario/contenido. No se eliminaron ni modificaron migraciones anteriores. Para otra instalación: aplicar 017 y ejecutar composer install dentro de backend (vendor está ignorado por Git). En este localhost ambas tareas están completadas.

## Pruebas y resultados

- 35 pruebas reales backend: PDF 7/30/historial, datos reales de fixtures, caracteres especiales, vacío, CSV UTF-8/fórmulas, autenticación, rechazo user_id, tokens válidos/inválidos/vencidos/revocados, preview sin datos privados, copia propia, original intacto, duplicación idempotente, persistencia de selección, IDOR y ausencia de XP por importación.
- 5 pruebas frontend: cámara denegada con fallback y liberación del scanner, código inválido sin request, contenido escapado y cierre. Dependencia de cámara simulada únicamente en esta prueba controlada; el flujo backend usa DB real.
- 65 pruebas de integridad existentes aprobadas, incluidas autenticación, economía transaccional, límites XP y actividad idempotente.
- PDF: extracción de texto verifica datos y períodos; renderizado e inspección visual de las 6 páginas de los 3 informes sin cortes ni caracteres rotos. Renderings intermedios eliminados.
- UI real a 375x812: PDF listo para descarga; QR SVG generado localmente; lectura de imagen QR; preview, confirmación y copia B; duplicado sin segunda copia; selección y ejercicio persistentes tras reload; entrenamiento completado y toast de logro existente.
- Regresión de Home, Nutrición, Entrenamiento/Actividad, Mi Progreso, Perfil/Colección, Ranking/Tienda y apertura de Explorar. Sin overflow horizontal en las vistas revisadas y modal dentro del viewport; consola sin errores. Advertencia de qr-scanner sobre HTTPS al intentar cámara en el navegador de pruebas; timeout/fallback correcto.
- Sintaxis PHP de cinco archivos y JS de app/exportación correcta. git diff --check sin errores.
- Cuentas temporales A/B/vacía y copias retiradas; test de integridad limpia sus propias cuentas. QR temporal eliminado. Capturas e informes contienen únicamente fixtures.

## Límites y pendientes

Cámara implementada, pero captura en dispositivo físico no comprobada: se requiere navegador compatible, permiso y HTTPS (localhost suele ser contexto seguro). Este navegador de pruebas no obtuvo stream; entrada por código e imagen sí verificadas. PDF muestra los últimos 30 registros de peso del período cuando hay más, y lo aclara; CSV conserva el historial completo. Las copias son instantáneas, no sincronización automática ni recomendaciones personalizadas. Compartir exige rutina propia; plantillas globales no se comparten como propias.

No falta configuración local. Pendiente de validación externa: cámara en teléfono físico/HTTPS. No se iniciaron IA, voz, Beneficios, HealthKit ni funciones sociales.

EXPORTACIÓN PDF/CSV = FUNCIONAL
QR DE RUTINAS = FUNCIONAL
