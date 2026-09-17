<?php
/**
 * Test POST real a analizar_foto.php
 * Simula un cliente completo: crea sesión, genera imagen, hace POST
 */
declare(strict_types=1);

// Simular una sesión PHP válida
session_start();
$_SESSION['usuario_id'] = 1;
$_SESSION['email'] = 'test.escaner@nutrifit.local';

require_once __DIR__ . '/config/env.php';

echo "=== TEST POST REAL ===\n\n";

// 1. Generar imagen JPEG pequeña (sin GD, usando datos binarios mínimos)
echo "1. GENERANDO IMAGEN\n";

// Un JPEG mínimo válido: 1x1 pixelJPEG
$jpegMinimo = base64_decode('
/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAv/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8VAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIRAxEAPwCwAA//Z
');

if ($jpegMinimo) {
    echo "   Imagen base64 mínima lista\n";
} else {
    echo "   ERROR: JPEG inválido\n";
    exit(1);
}

// 2. Hacer POST interno a analizar_foto.php
echo "\n2. HACIENDO POST SIMULADO\n";

// Capturar salida de analizar_foto.php
ob_start();

// Simular la solicitud
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';
$_POST = [];

// Mock getJsonBody
if (!function_exists('getJsonBody')) {
    function getJsonBody() {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }
}

// Simular el contenido JSON del request
$jsonBody = json_encode(['imagen_base64' => $jpegMinimo]);
$_REQUEST['imagen_base64'] = $jpegMinimo;

// Usar file_put_contents para simular stdin
$stdinPath = sys_get_temp_dir() . '/php_input_' . getmypid();
file_put_contents($stdinPath, $jsonBody);

// Ejecutar el script del escáner
try {
    // Incluir el script sin salir
    include __DIR__ . '/api/nutricion/analizar_foto.php';
} catch (Throwable $e) {
    echo "EXCEPCIÓN: " . $e->getMessage();
}

$output = ob_get_clean();
unlink($stdinPath);

echo "   Respuesta recibida:\n";
echo "   " . strlen($output) . " bytes\n";
echo "\n3. RESULTADO\n";
echo $output;
?>
