<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/AlimentoRepository.php';
require_once __DIR__ . '/../../classes/FiltroAlimentario.php';

$usuarioId = requireAuth();

$query = trim($_GET['q'] ?? '');

if ($query === '') {
    respond(true, []);
}

$db = (new Database())->getConnection();
$repositorio = new AlimentoRepository($db);
$perfil = FiltroAlimentario::obtenerPerfilAlimentario($db, $usuarioId);

// Se pide margen extra porque el filtro por dieta/alergias descarta candidatos.
$candidatos = $repositorio->buscar($query, 25);

$resultados = array_values(array_filter(
    $candidatos,
    fn ($a) => FiltroAlimentario::esCompatible($a['nombre'], $a['categoria'], $perfil['tipo_dieta'], $perfil['alergias'])
));

respond(true, array_slice($resultados, 0, 15));
