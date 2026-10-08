# NutriFit: seguridad e integridad

No se hizo commit ni staging. No se leyó .env en estas pruebas. No se reaplicaron migraciones previas.

## Causas y soluciones

| Problema | Causa | Solución |
| --- | --- | --- |
| Archivos internos expuestos | Apache servía archivos del directorio del proyecto | .htaccess bloquea ocultos, SQL, documentación, tests y backend fuera de api; deshabilita listado |
| Recuperación pública | Token/link en respuesta y logs | Respuesta uniforme sin emitir tokens mientras no haya correo; helper de entrega privada, hash SHA-256, expiración 30 min y consumo atómico |
| Fijación de sesión | ID sin rotación al autenticarse | Regeneración login/registro, strict mode, cookies HttpOnly/Lax y Secure bajo HTTPS directo/proxy local; logout borra cookie |
| XP de entrenamiento | IDs sin ownership y límite por rutina | Valida perfil/rutina/usuario; serializa por usuario; repetición 409; un premio por usuario/día; sesiones extra registran progreso sin XP |
| XP por fechas arbitrarias | Límite medido sobre fecha elegida y premio en fecha actual | Parseo exacto, sin futuro, fecha/hora coherentes; históricos registrables sin XP; contador diario y transacción común |
| Compras repetidas/concurrentes | Saldo fuera de transacción e INSERT IGNORE posterior al cobro | Bloqueo del usuario, propiedad dentro de transacción, descuento e inventario atómicos |
| Coins por recuperar nivel | Premio comparado solo con nivel actual gastable | nivel_maximo_alcanzado persistente, monotónico; mismo nivel nunca vuelve a premiarse desde la migración |
| XSS | Datos sin escape en innerHTML/atributos onclick | Escape HTML al renderizar y JSON+HTML para argumentos JavaScript; datos originales preservados |
| Racha vencida | Lecturas sin comprobar fecha | SistemaXP centraliza vigencia; sesión/logros/retos reconcilian; premios de logros/retos no crean días de actividad |
| Perfil con XP anterior | Lecturas paralelas antes de recompensar | Reconcilia logros y retos antes de leer sesión/ranking |
| Amistades inversas | UNIQUE de dirección A,B | Par generado LEAST/GREATEST e índice único; migración conserva aceptadas y archiva redundantes antes de consolidar |
| Entrada XAMPP | Redirección absoluta /frontend | Redirección relativa frontend/ |
| Agua repetida | Quitar/agregar cruza nuevamente mismo múltiplo | Límite monotónico por umbral alcanzado, sin cambiar recompensa/tope diario |
| Desborde mobile | Nombre largo en flex centrado | Corte de palabras y ancho máximo; assets versionados para evitar caché anterior |

## Migraciones

- 015_integridad_xp_reset.sql: máximo nivel premiado, hash separado para tokens; revoca tokens antiguos expuestos; backfill conservador desde nivel actual y saldo XP más gastos conocidos en inventario.
- 016_amistades_unicas.sql: archivo de relaciones redundantes, conserva aceptación, par no dirigido y restricción única.

Ambas se aplicaron dos veces en MariaDB local sin errores. En la base inspeccionada había 0 amistades preexistentes. No se alteró ninguna migración 001–014. La sintaxis IF NOT EXISTS utilizada corresponde a MariaDB/XAMPP; revisar adaptación antes de usar un servidor MySQL que no admita esas variantes.

## Semántica de entrenamiento y fechas

Marcar/desmarcar ejercicios guarda progreso individual sin XP. Completar es una confirmación de sesión válida del usuario; no exige marcar todos los ejercicios. La primera sesión de hoy puede premiar XP, repetir la misma sesión responde 409 y otra rutina no obtiene premio adicional. Actividad histórica se guarda pero no modifica XP ni racha. Se mantienen los tres premios máximos diarios de actividad, uno por meta de pasos y los topes de comida/agua.

## Pruebas

65 checks de tests/integrity.cjs pasaron: HTTP interno 403; frontend 200; API sin sesión 401; index relativo; registro, onboarding, login/logout y rotación; cookie Secure simulando proxy HTTPS local; reset uniforme, hash privado, expiración y uso único; rutinas ajenas/inexistentes; marcado persistente; repetición/segunda rutina sin farming; fechas futuras/imposibles; actividad duplicada con dos sesiones HTTP independientes; máximo diario; historial sin XP; canje concurrente/recompra/saldo insuficiente; subir/gastar/bajar/volver a subir sin coins repetidas; racha vencida; amistad inversa; umbrales de agua; APIs de todos los módulos principales.

UI real: Home, Nutrición, Actividad/Entrenamiento, Mi Progreso, Perfil, Ranking y Tienda cargaron. Nombre con etiqueta img/onerror se muestra como texto; 0 imágenes inyectadas y marcador de ejecución ausente. Avatar tiene canvas. Perfil y Ranking a 375px sin desborde horizontal después de los cambios. Evidencia: perfil-375.jpg. No se invocaron intencionalmente APIs externas. No se probó un túnel ngrok real ni producción HTTPS; sí se comprobó la cookie con el header del proxy local.

Ejecutar: node tests/integrity.cjs. Usa exclusivamente cuentas temporales con dominio example.invalid y limpia sus registros al terminar. NUTRIFIT_PHP y NUTRIFIT_TEST_BASE permiten ajustar entorno. La opción --keep está destinada a inspección UI de fixtures; los fixtures usados en esta ejecución fueron eliminados.

## Git y conservación

Snapshot inicial/final y hashes de archivos existentes: git-preservation.json. No faltan archivos del snapshot. Las 11 eliminaciones previas siguen eliminadas; .claude sigue untracked e intacto; todas las migraciones previas conservan su hash. .env no está staged ni trackeado. Se agregaron cambios sobre archivos heredados sin revertirlos. git diff --check pasó.

## Limitaciones y decisiones futuras

No existe un historial completo previo de niveles premiados o compras cobradas repetidas veces. El backfill conserva una cota calculable de máximo nivel desde los datos disponibles; no puede reconstruir eventos que nunca se guardaron. Desde este cambio, los premios nuevos son persistentes y atómicos. Si hubo pérdidas por el bug antiguo, una compensación requiere una decisión y evidencia del usuario; no se inventaron saldos.

El correo sigue deshabilitado hasta elegir/configurar un proveedor: no se envían instrucciones realmente. La futura integración debe llamar emitirParaEntregaPrivada únicamente cuando pueda entregar el secreto por un canal privado. No se agregaron proveedores ni claves.

Apache local bloquea los archivos. Al publicar en otro servidor se debe aplicar una protección equivalente si no interpreta .htaccess. Dado que .env estuvo accesible, decidir rotación de credenciales potencialmente expuestas; no se rotaron credenciales ni se inspeccionaron sus valores.

No se implementó pulido visual de gamificación, Mapa, IA, Beneficios ni features nuevas.

## Archivos con cambios de esta tarea

- backend/api/actividad/registrar.php
- backend/api/auth/login.php
- backend/api/auth/logout.php
- backend/api/auth/registro.php
- backend/api/auth/resetear_password.php
- backend/api/auth/sesion.php
- backend/api/auth/solicitar_reset.php
- backend/api/entrenamiento/completar_ejercicio.php
- backend/api/entrenamiento/completar_entrenamiento.php
- backend/api/entrenamiento/pasos.php
- backend/api/gamificacion/amigos.php
- backend/api/gamificacion/tienda.php
- backend/api/nutricion/hidratacion.php
- backend/classes/gamificacion/LogrosService.php
- backend/classes/gamificacion/RetosService.php
- backend/classes/SistemaXP.php
- backend/classes/Usuario.php
- backend/config/bootstrap.php
- backend/config/database.php
- frontend/css/styles.css
- frontend/index.html
- frontend/js/app.js
- index.php

Nuevos: .htaccess; backend/classes/PasswordResetTokens.php; migraciones 015 y 016; tests/integrity.cjs, SECURITY_REVIEW.md, git-preservation.json y perfil-375.jpg.
