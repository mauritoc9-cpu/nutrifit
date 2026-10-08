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

// scope=amigos → leaderboard sólo del usuario + sus amigos aceptados.
// scope=global (default) → ligas por XP de todos los usuarios.
$scope = ($_GET['scope'] ?? 'global') === 'amigos' ? 'amigos' : 'global';

if ($scope === 'amigos') {
    // IDs de amigos con la amistad aceptada (en cualquiera de los dos sentidos).
    $stmtAmigos = $db->prepare(
        'SELECT IF(usuario_id = :uid1, amigo_id, usuario_id) AS amigo_id
         FROM amigos_ranking
         WHERE estado = "aceptado" AND (usuario_id = :uid2 OR amigo_id = :uid3)'
    );
    $stmtAmigos->execute(['uid1' => $usuarioId, 'uid2' => $usuarioId, 'uid3' => $usuarioId]);
    $idsAmigos = array_map('intval', $stmtAmigos->fetchAll(PDO::FETCH_COLUMN));

    // Siempre se incluye al usuario actual en su propio ranking de amigos.
    $ids = array_values(array_unique(array_merge([$usuarioId], $idsAmigos)));
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $db->prepare(
        "SELECT id, nombre, xp_total, nivel FROM usuarios WHERE id IN ($placeholders) ORDER BY xp_total DESC, nombre ASC"
    );
    $stmt->execute($ids);
    $filas = $stmt->fetchAll();

    $tabla = [];
    $posicion = 0;
    foreach ($filas as $f) {
        $posicion++;
        $tabla[] = [
            'posicion' => $posicion,
            'id' => (int) $f['id'],
            'nombre' => $f['nombre'],
            'xp_total' => (int) $f['xp_total'],
            'nivel' => (int) $f['nivel'],
            'es_tu_usuario' => (int) $f['id'] === $usuarioId,
        ];
    }

    respond(true, [
        'scope' => 'amigos',
        'tabla' => $tabla,
        'tiene_amigos' => count($idsAmigos) > 0,
    ]);
}

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

respond(true, [
    'scope'   => 'global',
    'mi_liga' => $miLiga,
    'ligas'   => $ligas,
]);
