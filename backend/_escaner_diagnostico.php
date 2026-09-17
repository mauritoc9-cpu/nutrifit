<?php
/**
 * Script de diagnóstico para el Escáner IA.
 * Prueba cada etapa del flujo sin el frontend.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/classes/GeminiVisionClient.php';
require_once __DIR__ . '/classes/NutricionResolver.php';
require_once __DIR__ . '/classes/nutricion/ErrorEscaner.php';

header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO DEL ESCÁNER IA ===\n\n";

// Paso 1: Verificar API keys
echo "1. VERIFICACIÓN DE API KEYS\n";
echo "   GEMINI_API_KEY presente: " . (env('GEMINI_API_KEY') ? 'SÍ' : 'NO') . "\n";
$geminiKey = env('GEMINI_API_KEY');
echo "   GEMINI_API_KEY length: " . strlen($geminiKey) . "\n";
echo "   GEMINI_API_KEY starts with: " . substr($geminiKey, 0, 10) . "...\n";

function keyUtilizable(?string $key): bool {
    $key = trim((string) $key);
    if ($key === '' || strlen($key) < 20) return false;
    return !preg_match('/^(pega|tu_|your|xxx|placeholder|cambia)/i', $key);
}

echo "   GEMINI_API_KEY utilizable: " . (keyUtilizable($geminiKey) ? 'SÍ' : 'NO') . "\n\n";

// Paso 2: Crear imagen de prueba (descarga una pequeña foto de comida de ejemplo)
echo "2. IMAGEN DE PRUEBA\n";

// Una imagen JPEG mínima válida de comida (pequeño pastel/donut)
$imagenBase64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

echo "   Usando imagen de prueba...\n";
$binario = base64_decode($imagenBase64, true);
echo "   Base64 decodificado: " . strlen($binario) . " bytes\n";

$info = @getimagesizefromstring($binario);
if ($info === false) {
    echo "   ERROR: No se pudo leer la imagen decodificada\n\n";
} else {
    echo "   MIME type detectado: " . $info['mime'] . "\n";
    echo "   Dimensiones: {$info[0]}x{$info[1]}\n\n";
}

// Paso 3: Intentar llamar a GeminiVisionClient
echo "3. LLAMADA A GEMINI VISION\n";
try {
    $cliente = new GeminiVisionClient($geminiKey);
    echo "   Cliente Gemini creado: OK\n";

    // No hacemos la llamada real porque es imagen de prueba
    echo "   (Saltando llamada real para no usar cuota)\n\n";
} catch (Throwable $e) {
    echo "   ERROR al crear cliente: " . $e->getMessage() . "\n\n";
}

// Paso 4: Verificar NutricionResolver
echo "4. VERIFICACIÓN DE NUTRICIÓN RESOLVER\n";
try {
    $db = (new Database())->getConnection();
    $resolver = new NutricionResolver($db);
    echo "   NutricionResolver creado: OK\n";

    // Prueba con un alimento conocido
    $match = $resolver->resolverParaCamara('manzana', false);
    echo "   Búsqueda de test (manzana): " . ($match ? "ENCONTRADO" : "NO ENCONTRADO") . "\n";
    if ($match) {
        echo "   - ID: " . $match['alimento_id'] . "\n";
        echo "   - Calorias/100g: " . $match['calorias_por_100g'] . "\n";
    }
    echo "\n";
} catch (Throwable $e) {
    echo "   ERROR: " . $e->getMessage() . "\n\n";
}

echo "=== LISTO PARA PRUEBA EN VIVO ===\n";
echo "Accede a /nutrifittwo/backend/api/nutricion/analizar_foto.php vía POST con:\n";
echo "  { \"imagen_base64\": \"<data:image/jpeg;base64,...>\" }\n";
echo "\nEjemplo curl:\n";
echo "curl -X POST http://localhost/nutrifittwo/backend/api/nutricion/analizar_foto.php \\\n";
echo "  -H 'Content-Type: application/json' \\\n";
echo "  -d '{\"imagen_base64\": \"iVBORw0KG....\"}'\n";
?>
