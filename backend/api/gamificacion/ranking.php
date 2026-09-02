<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';

/**
 * Ligas por rango de XP total (Bronce / Plata / Oro / Diamante).
 * Devuelve el top de cada liga y en qué liga está el usuario actual.
 */

const LIGAS = [
    'diamante' => 5000,
    'oro'      => 2000,
    'plata'    => 500,
    'bronce'   => 0,
];

$usuarioId = requireAuth();
$db = (new Database())->getConnection();

$usuarios = $db->query('SELECT id, nombre, xp_total, nivel FROM usuarios ORDER BY xp_total DESC')->fetchAll();

function determinarLiga(int $xp): string
{
    foreach (LIGAS as $liga => $minimo) {
        if ($xp >= $minimo) {
            return $liga;
        }
    }
    return 'bronce';
}

$ligas = ['diamante' => [], 'oro' => [], 'plata' => [], 'bronce' => []];
$miLiga = 'bronce';

foreach ($usuarios as $u) {
    $liga = determinarLiga((int) $u['xp_total']);
    $ligas[$liga][] = $u;
    if ((int) $u['id'] === $usuarioId) {
        $miLiga = $liga;
    }
}

// Limita cada liga a los primeros 20 para el leaderboard.
foreach ($ligas as $liga => $lista) {
    $ligas[$liga] = array_slice($lista, 0, 20);
}

respond(true, [
    'mi_liga' => $miLiga,
    'ligas'   => $ligas,
]);
