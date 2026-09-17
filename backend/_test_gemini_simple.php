<?php
/**
 * Test simple del cliente Gemini Vision.
 * Genera una imagen y la envía directamente a Gemini.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/classes/GeminiVisionClient.php';
require_once __DIR__ . '/classes/nutricion/ErrorEscaner.php';

echo "=== TEST SIMPLE GEMINI VISION ===\n\n";

// 1. Verificar API Key
$geminiKey = env('GEMINI_API_KEY');
echo "1. API KEY GEMINI\n";
echo "   Presente: " . (empty($geminiKey) ? 'NO' : 'SÍ') . "\n";
echo "   Longitud: " . strlen($geminiKey) . "\n";
echo "   Primeros 20 chars: " . substr($geminiKey, 0, 20) . "\n\n";

// 2. Usar imagen de prueba descargada
echo "2. DESCARGANDO IMAGEN DE PRUEBA\n";

// Imagen placeholder: pizza/comida
$urlImagen = 'https://images.unsplash.com/photo-1614707267537-b85faf00021b?w=300&q=80'; // Pizza real

echo "   URL: $urlImagen\n";
$imagenBinaria = @file_get_contents($urlImagen, false, stream_context_create([
    'http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)']
]));

if (!$imagenBinaria) {
    echo "   ✗ No se pudo descargar. Usando imagen local en su lugar...\n";
    // Imagen PNG mínima válida
    $imagenBinaria = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
}

$imagenBase64 = base64_encode($imagenBinaria);
echo "   Imagen obtenida: " . strlen($imagenBinaria) . " bytes\n";
echo "   Base64 length: " . strlen($imagenBase64) . " chars\n\n";

// 3. Intentar con Gemini
echo "3. LLAMANDO A GEMINI VISION\n";
try {
    $cliente = new GeminiVisionClient($geminiKey);
    echo "   Cliente creado exitosamente\n";

    $resultado = $cliente->identificarAlimentos($imagenBase64);

    echo "\n✓ RESPUESTA EXITOSA DE GEMINI\n";
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    echo "\n✗ ERROR EN GEMINI VISION\n";
    echo "   Mensaje: " . $e->getMessage() . "\n";
    echo "   Código de error: " . ErrorEscaner::desdeExcepcion($e) . "\n";
    echo "   Stack trace:\n";
    echo $e->getTraceAsString();
}
?>
