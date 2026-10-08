<?php
declare(strict_types=1);
require_once __DIR__.'/../../config/bootstrap.php';
require_once __DIR__.'/../../classes/ExportarProgresoService.php';
$uid=requireAuth();
if($_SERVER['REQUEST_METHOD']!=='POST')respond(false,null,'Método no permitido.',405);
$b=getJsonBody();
if(array_diff(array_keys($b),['periodo','formato']) || !is_string($b['periodo']??null) || !in_array($b['periodo'],['7','30','todo'],true) || !in_array($b['formato']??null,['pdf','csv'],true))respond(false,null,'Período o formato inválido.',422);
session_write_close();
header('Cache-Control: no-store, private');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');
try{
    set_time_limit(35);
    $s=new ExportarProgresoService((new Database())->getConnection());$r=$s->obtener($uid,$b['periodo']);
    $out=$b['formato']==='pdf'?$s->pdf($r):$s->csv($r);
    header('Content-Type: '.($b['formato']==='pdf'?'application/pdf':'text/csv; charset=utf-8'));
    header('Content-Disposition: attachment; filename="nutrifit-progreso-'.$b['periodo'].'-'.date('Y-m-d').'.'.$b['formato'].'"');
    header('Content-Length: '.strlen($out));echo $out;exit;
}catch(Throwable $e){respond(false,null,'No pudimos preparar el informe. Volvé a intentar.',500);}
