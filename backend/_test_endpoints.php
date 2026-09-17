<?php
declare(strict_types=1);
require_once __DIR__ . '/config/bootstrap.php';

$sesion = [];
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $sesion = $_SESSION['usuario'] ?? null;
} catch (Exception $e) {
    // Session error
}

echo "=== TEST DE ENDPOINTS ===\n\n";
echo "Sesión actual: " . ($sesion ? "Usuario " . $sesion['id'] : "No autenticado") . "\n\n";

// Test recomendaciones
echo "1. GET /api/nutricion/recomendaciones.php\n";
if ($sesion) {
    $_GET['fecha'] = date('Y-m-d');
    $_SERVER['REQUEST_METHOD'] = 'GET';
    include __DIR__ . '/api/nutricion/recomendaciones.php';
} else {
    echo "   Requiere autenticación\n";
}

// Test favoritos
echo "\n2. GET /api/nutricion/favoritos.php\n";
if ($sesion) {
    $_SERVER['REQUEST_METHOD'] = 'GET';
    unset($_GET['fecha']);
    include __DIR__ . '/api/nutricion/favoritos.php';
} else {
    echo "   Requiere autenticación\n";
}

// Test receta
echo "\n3. GET /api/nutricion/receta.php\n";
if ($sesion) {
    $_GET['momento'] = '';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    include __DIR__ . '/api/nutricion/receta.php';
} else {
    echo "   Requiere autenticación\n";
}
?>
