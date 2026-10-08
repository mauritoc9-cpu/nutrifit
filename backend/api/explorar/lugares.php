<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../classes/ExplorarService.php';
requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(false,null,'Método no permitido.',405);
$input = getJsonBody();
$action = $input['accion'] ?? 'cercanos';
if (!in_array($action,['config','zona','cercanos'],true)) respond(false,null,'Solicitud inválida.',422);
if ($action === 'config') {
    session_write_close();
    $tiles = env('EXPLORE_TILES_URL','https://tile.openstreetmap.org/{z}/{x}/{y}.png');
    if (!str_starts_with($tiles,'https://') || parse_url($tiles,PHP_URL_QUERY) !== null || parse_url($tiles,PHP_URL_USER) !== null || parse_url($tiles,PHP_URL_PASS) !== null) respond(false,null,'La configuración del mapa no está disponible.',503);
    respond(true,['tiles'=>$tiles]);
}
$now = microtime(true);
if ($now - ($_SESSION['explorar_request_at'] ?? 0) < 1) respond(false,null,'Esperá un momento antes de buscar otra vez.',429);
$_SESSION['explorar_request_at'] = $now;
session_write_close();
try {
    $service = new ExplorarService();
    if ($action === 'zona') {
        if (!is_string($input['q'] ?? null)) throw new InvalidArgumentException('busqueda');
        respond(true,['zonas'=>$service->buscarZona($input['q'])]);
    }
    $lat = ExplorarService::coordenada($input['lat'] ?? null,90);
    $lon = ExplorarService::coordenada($input['lon'] ?? null,180);
    $cat = $input['categoria'] ?? 'todos';
    if (!is_string($cat)) throw new InvalidArgumentException('categoria');
    respond(true,['lugares'=>$service->cercanos($lat,$lon,$cat),'centro'=>['lat'=>$lat,'lon'=>$lon],'radio_m'=>3000,'fuente'=>'OpenStreetMap']);
} catch (InvalidArgumentException $e) {
    respond(false,null,'Revisá la zona o los datos de búsqueda.',422);
} catch (Throwable $e) {
    respond(false,null,'El proveedor de lugares no está disponible. Volvé a intentar en unos momentos.',503);
}
