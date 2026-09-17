<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../classes/GeminiVisionClient.php';
require_once __DIR__ . '/../../classes/ClaudeVisionClient.php';
require_once __DIR__ . '/../../classes/NutricionResolver.php';
require_once __DIR__ . '/../../classes/nutricion/ErrorEscaner.php';

/**
 * Analiza una foto de comida con visión de IA, identifica los alimentos
 * visibles con una estimación de gramos por alimento, y resuelve la
 * información nutricional de cada uno con el mismo motor que usa el
 * buscador manual (NutricionResolver) — todo el matching contra USDA/Open
 * Food Facts pasa acá adentro; el frontend solo recibe un resultado listo
 * para mostrar, sin tener que elegir entre varias fuentes.
 *
 * Se prueba primero Gemini (nivel gratuito de Google, sin tarjeta de
 * crédito) y, si no está configurado, Claude (de pago). Si ninguno está
 * configurado o ambos fallan, se responde con éxito=false y el frontend cae
 * automáticamente al reconocimiento local gratuito (MobileNet) como
 * respaldo — el escáner nunca deja de andar.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

requireAuth();

$body = getJsonBody();
$imagenBase64 = (string) ($body['imagen_base64'] ?? '');

/** Respuesta de error tipificada: el cliente decide qué mostrar segun el codigo. */
function responderError(string $codigo, int $http = 422): void
{
    respond(false, [
        'codigo' => $codigo,
        'permite_fallback_local' => ErrorEscaner::permiteFallbackLocal($codigo),
    ], ErrorEscaner::mensaje($codigo), $http);
}

if ($imagenBase64 === '') {
    responderError(ErrorEscaner::ERROR_SUBIDA);
}

// El frontend puede mandar el data URL completo ("data:image/jpeg;base64,...")
// o solo la parte en base64 — nos quedamos solo con el base64.
if (str_contains($imagenBase64, ',')) {
    $imagenBase64 = explode(',', $imagenBase64, 2)[1];
}

// Límite generoso (~8MB en base64) para evitar abusos, sin restringir fotos normales de cámara.
if (strlen($imagenBase64) > 8 * 1024 * 1024) {
    responderError(ErrorEscaner::ARCHIVO_DEMASIADO_GRANDE, 413);
}

// Validación real del contenido: que sea una imagen que el servidor pueda
// leer y en un formato que la API de visión acepte. Un HEIC de iPhone sin
// convertir, por ejemplo, cae acá con un mensaje claro en vez de terminar
// como "no se detectaron alimentos".
$binario = base64_decode($imagenBase64, true);
if ($binario === false || $binario === '') {
    responderError(ErrorEscaner::FORMATO_NO_COMPATIBLE);
}

$info = @getimagesizefromstring($binario);
if ($info === false) {
    responderError(ErrorEscaner::FORMATO_NO_COMPATIBLE);
}

$mimesAceptados = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($info['mime'] ?? '', $mimesAceptados, true)) {
    responderError(ErrorEscaner::FORMATO_NO_COMPATIBLE);
}

$geminiKey = env('GEMINI_API_KEY');
$claudeKey = env('ANTHROPIC_API_KEY');

/**
 * Una key de ejemplo sin reemplazar (el placeholder de .env.example) no
 * es una key: tratarla como configurada hacía que el usuario viera
 * "no se detectaron alimentos" cuando en realidad faltaba configurar el
 * servidor.
 */
function keyUtilizable(?string $key): bool
{
    $key = trim((string) $key);
    if ($key === '' || strlen($key) < 20) {
        return false;
    }

    return !preg_match('/^(pega|tu_|your|xxx|placeholder|cambia)/i', $key);
}

$geminiUsable = keyUtilizable($geminiKey);
$claudeUsable = keyUtilizable($claudeKey);

if (!$geminiUsable && !$claudeUsable) {
    responderError(ErrorEscaner::IA_NO_CONFIGURADA, 501);
}

$resultadoVision = null;
$ultimoCodigo = ErrorEscaner::ERROR_GEMINI;

if ($geminiUsable) {
    try {
        $resultadoVision = (new GeminiVisionClient($geminiKey))->identificarAlimentos($imagenBase64);
    } catch (Throwable $e) {
        $ultimoCodigo = ErrorEscaner::desdeExcepcion($e);
        error_log('[analizar_foto] Gemini Vision falló (' . $ultimoCodigo . '): ' . $e->getMessage());
        // Si también hay una key de Claude configurada, lo intentamos antes de rendirnos.
    }
}

if ($resultadoVision === null && $claudeUsable) {
    try {
        $resultadoVision = (new ClaudeVisionClient($claudeKey))->identificarAlimentos($imagenBase64);
    } catch (Throwable $e) {
        $ultimoCodigo = ErrorEscaner::desdeExcepcion($e);
        error_log('[analizar_foto] Claude Vision falló (' . $ultimoCodigo . '): ' . $e->getMessage());
    }
}

if ($resultadoVision === null) {
    responderError($ultimoCodigo, 502);
}

// Acá sí: la IA respondió bien y simplemente no hay comida reconocible.
if (!($resultadoVision['es_comida'] ?? false) || empty($resultadoVision['alimentos'])) {
    respond(true, array_merge($resultadoVision, ['codigo' => ErrorEscaner::NO_ALIMENTOS]));
}

$db = (new Database())->getConnection();
$resolver = new NutricionResolver($db);

$alimentosResueltos = [];
$totales = ['calorias' => 0.0, 'proteinas' => 0.0, 'carbohidratos' => 0.0, 'grasas' => 0.0];

foreach ($resultadoVision['alimentos'] as $item) {
    $nombre = (string) ($item['nombre'] ?? '');
    $gramos = (float) ($item['gramos_estimados'] ?? 0);
    $envasado = (bool) ($item['envasado'] ?? false);

    if ($nombre === '' || $gramos <= 0) {
        continue;
    }

    $match = $resolver->resolverParaCamara($nombre, $envasado);
    $factor = $gramos / 100;

    if ($match === null) {
        // No se encontró ninguna coincidencia nutricional confiable: se
        // muestra igual el alimento detectado (para no perder el ítem),
        // pero sin macros — el frontend debe ofrecer resolverlo a mano.
        $alimentosResueltos[] = [
            'nombre' => $nombre,
            'gramos_estimados' => $gramos,
            'alimento_id' => null,
            'confianza' => 'sin_match',
            'calorias' => null,
            'proteinas' => null,
            'carbohidratos' => null,
            'grasas' => null,
        ];
        continue;
    }

    $calorias = round($match['calorias_por_100g'] * $factor, 1);
    $proteinas = round($match['proteinas'] * $factor, 1);
    $carbohidratos = round($match['carbohidratos'] * $factor, 1);
    $grasas = round($match['grasas'] * $factor, 1);

    $alimentosResueltos[] = [
        'nombre' => $nombre,
        'gramos_estimados' => $gramos,
        'alimento_id' => $match['alimento_id'],
        'confianza' => $match['confianza'],
        'calorias_por_100g' => $match['calorias_por_100g'],
        'proteinas_por_100g' => $match['proteinas'],
        'carbohidratos_por_100g' => $match['carbohidratos'],
        'grasas_por_100g' => $match['grasas'],
        'calorias' => $calorias,
        'proteinas' => $proteinas,
        'carbohidratos' => $carbohidratos,
        'grasas' => $grasas,
    ];

    $totales['calorias'] += $calorias;
    $totales['proteinas'] += $proteinas;
    $totales['carbohidratos'] += $carbohidratos;
    $totales['grasas'] += $grasas;
}

respond(true, [
    'es_comida' => true,
    'descripcion' => $resultadoVision['descripcion'] ?? '',
    'alimentos' => $alimentosResueltos,
    'total' => [
        'calorias' => round($totales['calorias'], 1),
        'proteinas' => round($totales['proteinas'], 1),
        'carbohidratos' => round($totales['carbohidratos'], 1),
        'grasas' => round($totales['grasas'], 1),
    ],
]);
