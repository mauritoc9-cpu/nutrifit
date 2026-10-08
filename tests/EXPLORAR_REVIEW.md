# Mapa / Explorar — implementación y validación

## Proveedor elegido
Leaflet 1.9.4 + tiles de OpenStreetMap; Overpass para puntos de interés; Photon para búsqueda explícita de localidades, zonas y lugares. No existía integración de mapas ni configuración de credenciales de mapas. Funciona con los proveedores públicos sin cuenta, tarjeta ni API key en localhost/ngrok; geolocalización requiere localhost o HTTPS.

Google Maps/Places requiere proyecto con billing y autenticación. Se eligió la alternativa OSM por compatibilidad con PHP y JavaScript vanilla y por permitir probar lugares reales sin credenciales nuevas. No se integró Nominatim público.
Fuentes: https://leafletjs.com/download.html ; https://github.com/komoot/photon ; https://github.com/komoot/photon/blob/master/docs/api-v1.md ; https://wiki.openstreetmap.org/wiki/Overpass_API ; https://operations.osmfoundation.org/policies/tiles/ ; https://developers.google.com/maps/get-started

## Arquitectura
Nueva vista en el routing existente y navegación desktop/mobile. Leaflet carga únicamente al abrir Explorar. Ubicación se pide mediante botón dentro de esa vista, nunca al iniciar Home. Estados: no solicitada, solicitando, permitida, denegada, no disponible y error/timeout. Búsqueda manual independiente del permiso.
Endpoint POST autenticado; se libera sesión antes de llamar proveedores. Validación de coordenadas, categoría y texto; URLs de proveedores se configuran en servidor, no desde peticiones. cURL verifica TLS, tiene timeout y máximo de respuesta. Consultas manuales codificadas, sin autocomplete automático.
GPS se redondea a 3 decimales antes de enviarlo y nuevamente en backend. No se escribe GPS ni perfiles en DB. Caché temporal compartida y anónima: 10 minutos para puntos y 24 horas para zonas públicas, con purga; bloqueos de proveedor y separación mínima de consultas. No se imprimen coordenadas personales ni secretos.
Una consulta acotada cubre las categorías; filtros locales no generan nuevas requests. Hasta 30 resultados/marcadores visibles, radio 3 km, consultas limitadas a 80 elementos por grupo. Movimientos del mapa requieren pulsar Buscar en esta zona. AbortController, timeouts y número de secuencia descartan respuestas viejas; salir cancela trabajo y ubicación pendiente.
Datos externos en tarjetas/popups se asignan con textContent. Sin ratings, imágenes o disponibilidad inventados. Direcciones y horarios solo si OSM los devuelve. Distancias son aproximadas en línea recta. Cómo llegar abre Google Maps externo con destino real, sin requerir API de Google ni transmitir origen GPS.

## Archivos
Creados: backend/classes/ExplorarService.php; backend/api/explorar/lugares.php; frontend/js/explorar.js; frontend/css/explorar.css; tests/explorar.cjs; esta revisión y captura explorar-375.jpg.
Modificados en esta fase: frontend/js/app.js; frontend/index.html; .env.example. Sin migraciones ni cambios de economía, XP, Social o Avatar.

## Configuración
No falta credencial para la implementación local. Variables opcionales documentadas en .env.example: EXPLORE_PHOTON_URL, EXPLORE_OVERPASS_URL y EXPLORE_TILES_URL. Las primeras requieren servicios HTTPS compatibles con Photon/Overpass; tiles necesita una plantilla HTTPS pública z/x/y, sin secretos ni query tokens. La URL de tiles es pública en el navegador.
Para producción con más tráfico: contratar o alojar proveedores compatibles, evaluar condiciones/cuotas y configurar esas URLs. Photon demo, Overpass público y tiles OSM no tienen SLA; no se deben usar como infraestructura ilimitada. Tiles respetan atribución, caché del navegador y no hay descarga offline/prefetch.

## Pruebas
17 comprobaciones automatizadas aprobadas: ausencia de permiso automático; solicitud, denegación, indisponibilidad, timeout y permiso concedido con coordenadas de prueba; redondeo GPS; texto externo XSS tratado como texto; cuatro filtros; URL segura de rutas; ausencia de resultados; red sin loader infinito; respuesta obsoleta ignorada; ubicación tardía ignorada al salir; validación/normalización PHP.
HTTP real: sin sesión 401; coordenadas inválidas 422; datos reales 200; repetición rápida 429. San Isidro devolvió 28 gimnasios, 30 lugares de comida y 1 tienda de suplementos en el resultado acotado.
Navegador a 375x812: abrir Explorar, mapa real, localidad manual, filtros sucesivos y rápidos, selección de tarjeta y marcador, Ver en mapa, popup y Cómo llegar; búsqueda XSS rechazada sin ejecución; localidad inexistente muestra ausencia de resultados. Documento sin scroll horizontal (ancho 360 dentro de viewport 375). Permiso real del navegador de prueba finalizó en timeout y la app siguió funcionando. Concesión y denegación se validaron en pruebas controladas de los callbacks de geolocalización; no se afirma validación física en un teléfono ni se usó ubicación personal del dispositivo.
Regresión rápida visual de Home, rutina de entrenamiento existente, Nutrición, Actividad, Mi Progreso, Perfil, Ranking y Tienda. Sin errores importantes de consola de JavaScript en la navegación y pruebas funcionales normales. Los rechazos HTTP intencionales pueden aparecer en la consola de red.
Sintaxis de los archivos PHP y JavaScript afectados aprobada; git diff --check aprobado. Cuenta temporal eliminada y sin commit/staging.

## Correcciones encontradas
Photon público no admite lang=es: se conserva idioma original. Se evitaron resultados de comida que agotaban el límite de otras categorías separando grupos de Overpass. Se corrigió el estado buscando tras una respuesta exitosa, el doble inicio del mapa al navegar rápido, el botón buscar sin nombre accesible, botones secundarios fuera del estilo existente, estado de solicitud GPS cancelada y la navegación de ocho elementos a 375px.

## Limitaciones y pendientes
OSM puede tener comercios faltantes o datos desactualizados; suplementos depende de etiquetado específico y puede quedar vacío en algunas zonas. Se omiten puntos sin nombre. Resultado acotado no garantiza inventario exhaustivo ni los 30 negocios más próximos de toda la base. Photon puede requerir provincia/país para desambiguar. No hay evaluación automática de si un negocio está abierto ahora ni navegación GPS propia.
No hay pendientes bloqueantes de configuración local. Recomendado verificar permisos físicos en Android/iOS y seleccionar infraestructura con garantías al desplegar a escala. No se implementaron fases excluidas.

MAPA / EXPLORAR = FUNCIONAL para el entorno local y uso moderado con proveedores públicos.
