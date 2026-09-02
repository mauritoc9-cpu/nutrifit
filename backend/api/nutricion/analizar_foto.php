<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

/**
 * Simulación de análisis de visión computacional.
 * En producción este endpoint llamaría a una API real de reconocimiento
 * de imágenes (p. ej. un modelo de clasificación de alimentos). Aquí
 * se simula devolviendo una coincidencia aleatoria del catálogo con
 * una estimación de gramos, para que el frontend pueda completar el
 * flujo de "Confirmación Manual" descrito en el brief.
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

requireAuth();

$db = (new Database())->getConnection();
$stmt = $db->query('SELECT id, nombre, calorias_por_100g, proteinas, carbohidratos, grasas, imagen FROM alimentos ORDER BY RAND() LIMIT 1');
$alimento = $stmt->fetch();

if (!$alimento) {
    respond(false, null, 'No hay alimentos en el catálogo para simular el análisis.', 404);
}

$gramosEstimados = random_int(80, 250);
$confianza = random_int(72, 97);

respond(true, [
    'alimento_detectado' => $alimento,
    'gramos_estimados'   => $gramosEstimados,
    'confianza_porcentaje' => $confianza,
    'nota' => 'Estimación automática — ajusta los gramos antes de confirmar el registro.',
]);
