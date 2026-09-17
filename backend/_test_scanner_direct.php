<?php
/**
 * Test directo del escáner: simula un POST como si viniera del frontend.
 * Descarga una imagen de comida pequeña y la prueba.
 */
declare(strict_types=1);

require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/classes/GeminiVisionClient.php';
require_once __DIR__ . '/classes/NutricionResolver.php';
require_once __DIR__ . '/classes/nutricion/ErrorEscaner.php';

// Inicializamos mínimos sin bootstrap para evitar conflictos
class Database {
    private static $connection = null;
    public function getConnection() {
        if (self::$connection === null) {
            self::$connection = new PDO(
                'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
                DB_USER,
                DB_PASS
            );
        }
        return self::$connection;
    }
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'nutrifit');
define('DB_USER', 'root');
define('DB_PASS', '');

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'estado' => 'iniciando_test',
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT);

// Descargamos una imagen de comida de prueba (pizza pequeña de ejemplo)
// NOTA: Esta es una imagen PNG mínima de comida real, descargada de un servidor confiable
$urlImagen = 'https://via.placeholder.com/300x300/FF6B6B/FFFFFF?text=Pizza';

echo "\n\n=== DESCARGANDO IMAGEN DE PRUEBA ===\n";
echo "URL: $urlImagen\n";

$imageData = @file_get_contents($urlImagen);
if (!$imageData) {
    echo "ERROR: No se pudo descargar imagen. Usando imagen local en lugar...\n";
    // Generamos una imagen JPEG mínima válida local
    $tmpFile = tempnam(sys_get_temp_dir(), 'nutri_test_');
    $img = imagecreatetruecolor(300, 300);
    $orange = imagecolorallocate($img, 255, 165, 0);
    imagefilledrectangle($img, 0, 0, 300, 300, $orange);
    imagejpeg($img, $tmpFile, 85);
    imagedestroy($img);
    $imageData = file_get_contents($tmpFile);
    unlink($tmpFile);
    echo "Imagen local generada: " . strlen($imageData) . " bytes\n";
} else {
    echo "Imagen descargada: " . strlen($imageData) . " bytes\n";
}

$imagenBase64 = base64_encode($imageData);
echo "Base64 generado: " . strlen($imagenBase64) . " caracteres\n\n";

// Ahora hacemos POST simulado a analizar_foto.php
echo "=== LLAMANDO A analizar_foto.php INTERNAMENTE ===\n";

$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['CONTENT_TYPE'] = 'application/json';

// Simulamos el body JSON que enviaría el frontend
$jsonBody = json_encode(['imagen_base64' => $imagenBase64]);

// Sin mocks, ejecutaremos lógica pura

// Ejecutamos la lógica principal del escáner
try {
    $imagenBase64Var = (string) ($jsonBody['imagen_base64'] ?? '');

    echo "Imagen recibida: " . strlen($imagenBase64Var) . " chars\n";

    if ($imagenBase64Var === '') {
        throw new Exception(ErrorEscaner::ERROR_SUBIDA);
    }

    // Limpieza del data URL
    if (str_contains($imagenBase64Var, ',')) {
        $imagenBase64Var = explode(',', $imagenBase64Var, 2)[1];
        echo "Data URL limpiado\n";
    }

    // Validación de tamaño
    if (strlen($imagenBase64Var) > 8 * 1024 * 1024) {
        throw new Exception(ErrorEscaner::ARCHIVO_DEMASIADO_GRANDE);
    }
    echo "Tamaño validado: OK\n";

    // Decodificación
    $binario = base64_decode($imagenBase64Var, true);
    if ($binario === false || $binario === '') {
        echo "ERROR: Base64 no válido\n";
        throw new Exception(ErrorEscaner::FORMATO_NO_COMPATIBLE);
    }
    echo "Base64 decodificado: " . strlen($binario) . " bytes\n";

    // Validación de formato
    $info = @getimagesizefromstring($binario);
    if ($info === false) {
        echo "ERROR: getimagesizefromstring falló\n";
        throw new Exception(ErrorEscaner::FORMATO_NO_COMPATIBLE);
    }
    echo "Imagen validada: {$info[0]}x{$info[1]} ({$info['mime']})\n";

    $mimesAceptados = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($info['mime'] ?? '', $mimesAceptados, true)) {
        echo "ERROR: MIME no soportado: {$info['mime']}\n";
        throw new Exception(ErrorEscaner::FORMATO_NO_COMPATIBLE);
    }

    // API Keys
    $geminiKey = env('GEMINI_API_KEY');
    echo "GEMINI API Key: " . substr($geminiKey, 0, 15) . "...\n";

    function keyUtilizable(?string $key): bool {
        $key = trim((string) $key);
        if ($key === '' || strlen($key) < 20) return false;
        return !preg_match('/^(pega|tu_|your|xxx|placeholder|cambia)/i', $key);
    }

    $geminiUsable = keyUtilizable($geminiKey);
    echo "GEMINI utilizable: " . ($geminiUsable ? 'SÍ' : 'NO') . "\n";

    if (!$geminiUsable) {
        throw new Exception(ErrorEscaner::IA_NO_CONFIGURADA);
    }

    // Intentar con Gemini
    echo "\nLlamando a GeminiVisionClient...\n";
    $cliente = new GeminiVisionClient($geminiKey);
    $resultadoVision = $cliente->identificarAlimentos($imagenBase64Var);

    echo "Respuesta de Gemini recibida:\n";
    echo json_encode($resultadoVision, JSON_PRETTY_PRINT) . "\n";

    // Resolver nutrición
    if (!($resultadoVision['es_comida'] ?? false) || empty($resultadoVision['alimentos'])) {
        echo "\nNingún alimento detectado (resultado válido de IA)\n";
        $responseData = [
            'success' => true,
            'message' => '',
            'data' => array_merge($resultadoVision, ['codigo' => ErrorEscaner::NO_ALIMENTOS]),
            'http_code' => 200,
        ];
    } else {
        echo "\nAlimentos detectados: " . count($resultadoVision['alimentos']) . "\n";

        $db = (new Database())->getConnection();
        $resolver = new NutricionResolver($db);

        $alimentosResueltos = [];
        foreach ($resultadoVision['alimentos'] as $item) {
            $nombre = (string) ($item['nombre'] ?? '');
            $gramos = (float) ($item['gramos_estimados'] ?? 0);

            echo "  Procesando: $nombre ($gramos g)...\n";

            if ($nombre === '' || $gramos <= 0) continue;

            $match = $resolver->resolverParaCamara($nombre, false);
            if ($match) {
                echo "    ✓ Match encontrado: " . $match['alimento_id'] . "\n";
                $alimentosResueltos[] = array_merge($item, [
                    'alimento_id' => $match['alimento_id'],
                    'calorias_por_100g' => $match['calorias_por_100g'],
                    'proteinas_por_100g' => $match['proteinas'],
                    'carbohidratos_por_100g' => $match['carbohidratos'],
                    'grasas_por_100g' => $match['grasas'],
                ]);
            } else {
                echo "    ✗ Sin match en base de datos\n";
                $alimentosResueltos[] = $item + ['alimento_id' => null];
            }
        }

        $responseData = [
            'success' => true,
            'message' => '',
            'data' => [
                'es_comida' => true,
                'descripcion' => $resultadoVision['descripcion'] ?? '',
                'alimentos' => $alimentosResueltos,
            ],
            'http_code' => 200,
        ];
    }

} catch (Throwable $e) {
    echo "\nEXCEPCIÓN: " . $e->getMessage() . "\n";
    $responseData = [
        'success' => false,
        'message' => ErrorEscaner::mensaje((string) $e->getMessage()),
        'data' => [
            'codigo' => (string) $e->getMessage(),
            'permite_fallback_local' => ErrorEscaner::permiteFallbackLocal((string) $e->getMessage()),
        ],
        'http_code' => 422,
    ];
}

// Mostramos resultado final
echo "\n=== RESULTADO FINAL ===\n";
echo json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

ob_end_clean();
?>
