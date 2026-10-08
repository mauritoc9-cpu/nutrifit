<?php
declare(strict_types=1);
require_once __DIR__.'/ResumenSemanalService.php';

final class ExportarProgresoService
{
    public function __construct(private PDO $db) {}
    public function obtener(int $uid,string $periodo): array
    {
        if(!in_array($periodo,['7','30','todo'],true))throw new InvalidArgumentException('Período inválido.');
        $hasta=date('Y-m-d');$desde=date('Y-m-d',strtotime('-'.($periodo==='7'?6:29).' days'));
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT nombre,DATE(fecha_registro) AS inicio FROM usuarios WHERE id=?');$s->execute([$uid]);$u=$s->fetch();
            if(!$u)throw new DomainException('Cuenta no disponible.');
            if($periodo==='todo'){
                $fechas=[$u['inicio']];
                foreach(['progreso_peso','registros_actividad','registros_comidas','registros_hidratacion','registros_entrenamiento','registros_pasos'] as $tabla){$s=$this->db->prepare("SELECT MIN(fecha) FROM $tabla WHERE usuario_id=? AND fecha<=?");$s->execute([$uid,$hasta]);if($f=$s->fetchColumn())$fechas[]=$f;}
                $desde=min(array_filter($fechas)) ?: $hasta;
            }
            $r=(new ResumenSemanalService($this->db))->generar($uid,$desde,$hasta);
            // Pasos web/manuales son una fuente separada: no sumarlos otra vez a actividad.
            $s=$this->db->prepare('SELECT SUM(pasos) AS total,COUNT(*) AS dias FROM registros_pasos WHERE usuario_id=? AND fecha BETWEEN ? AND ?');$s->execute([$uid,$desde,$hasta]);$r['pasos_registrados']=$s->fetch();
            $s=$this->db->prepare('SELECT COUNT(DISTINCT fecha) FROM (SELECT fecha FROM registros_actividad WHERE usuario_id=? AND fecha BETWEEN ? AND ? UNION SELECT fecha FROM registros_comidas WHERE usuario_id=? AND fecha BETWEEN ? AND ? UNION SELECT fecha FROM registros_hidratacion WHERE usuario_id=? AND fecha BETWEEN ? AND ? UNION SELECT fecha FROM registros_entrenamiento WHERE usuario_id=? AND fecha BETWEEN ? AND ? UNION SELECT fecha FROM registros_pasos WHERE usuario_id=? AND fecha BETWEEN ? AND ?) d');$s->execute([$uid,$desde,$hasta,$uid,$desde,$hasta,$uid,$desde,$hasta,$uid,$desde,$hasta,$uid,$desde,$hasta]);$r['dias_con_registro']=(int)$s->fetchColumn();
            $r['nombre']=$u['nombre'];$r['periodo']=$periodo;$r['generado_en']=date('d/m/Y H:i');
            $this->db->commit();return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function f(mixed $value,string $unit=''): string
    {return $value===null?'Sin registros':number_format((float)$value,1,',','.').($unit?' '.$unit:'');}
    public function metricas(array $r): array
    {
        $a=$r['actividad'];$n=$r['nutricion'];$h=$r['hidratacion'];$p=$r['peso'];$hist=$p['historial'];
        return [
            ['Progreso general','Días con registros',$r['dias_con_registro'],'días'],
            ['Estado actual','Nivel',$r['xp']['nivel'],''],['Estado actual','XP acumulado',$r['xp']['xp_total'],'XP'],
            ['Peso','Peso actual del perfil',$p['peso_actual'],'kg'],
            ['Peso','Variación entre registros del período',count($hist)>=2?(float)$hist[count($hist)-1]['peso_registrado']-(float)$hist[0]['peso_registrado']:null,'kg'],
            ['Actividad','Sesiones registradas',$a['sesiones_totales'],'sesiones'],['Actividad','Tiempo registrado',$a['minutos_totales'],'min'],['Actividad','Distancia registrada',$a['distancia_metros_total']/1000,'km'],
            ['Actividad','Pasos en sesiones de caminata/carrera',$a['pasos_totales'],'pasos'],
            ['Pasos','Contador web/carga manual',(int)$r['pasos_registrados']['dias']>0?$r['pasos_registrados']['total']:null,'pasos'],
            ['Entrenamiento','Rutinas completadas',$a['entrenamientos_completados'],'sesiones'],
            ['Hidratación','Días con registro',$h['dias_con_registro'],'días'],['Hidratación','Promedio por día registrado',$h['ml_promedio']===null?null:$h['ml_promedio']/1000,'L'],['Hidratación','Días con meta alcanzada',$h['dias_meta_cumplida'],'días'],
            ['Nutrición','Días con registro',$n['dias_con_registro'],'días'],['Nutrición','Calorías promedio',$n['calorias_promedio'],'kcal/día registrado'],['Nutrición','Proteínas promedio',$n['proteinas_promedio'],'g/día registrado'],['Nutrición','Carbohidratos promedio',$n['carbohidratos_promedio'],'g/día registrado'],['Nutrición','Grasas promedio',$n['grasas_promedio'],'g/día registrado'],
        ];
    }
    public function pdf(array $r): string
    {
        $vendor = is_file(__DIR__.'/../../vendor/autoload.php') ? __DIR__.'/../../vendor' : __DIR__.'/../vendor';
        require_once $vendor.'/autoload.php';
        $options=new \Dompdf\Options();$options->set('isRemoteEnabled',false);$options->set('isPhpEnabled',false);$options->set('isJavascriptEnabled',false);$options->set('defaultFont','DejaVu Sans');
        $options->set('chroot',$vendor.'/dompdf/dompdf');
        $pdf=new \Dompdf\Dompdf($options);
        $escape=fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
        $sections=[];foreach($this->metricas($r) as [$seccion,$label,$value,$unit])$sections[$seccion][]='<tr><td>'.$escape($label).'</td><td class="value">'.$escape(self::f($value,$unit)).'</td></tr>';
        $body='';foreach($sections as $title=>$rows)$body.='<section><h2>'.$escape($title).'</h2><table>'.implode('',$rows).'</table></section>';
        $hist=$r['peso']['historial'];$weight='';foreach(array_slice($hist,-30) as $row)$weight.='<tr><td>'.$escape(date('d/m/Y',strtotime($row['fecha']))).'</td><td class="value">'.self::f($row['peso_registrado'],'kg').'</td></tr>';
        $note=count($hist)>30?'Se muestran los últimos 30 registros del período. El resumen considera todos; CSV incluye el historial completo.':'Registros de peso dentro del período seleccionado.';
        $html='<!DOCTYPE html><html><head><meta charset="UTF-8"><style>@page{margin:38px 42px 48px}body{font-family:"DejaVu Sans";font-size:10px;color:#263a36}header{border-bottom:4px solid #a3d849;padding-bottom:14px;margin-bottom:20px}h1{font-size:28px;color:#244b40;margin:0 0 7px}h2{font-size:13px;margin:14px 0 6px;color:#244b40}p{line-height:1.6}.muted{color:#61716c;font-size:9px}table{border-collapse:collapse;width:100%}td{padding:6px 9px;border-bottom:1px solid #dde5df;width:65%;word-wrap:break-word}.value{width:35%;text-align:right;background:#f1f6ed}section{page-break-inside:avoid;margin-bottom:8px}.weight thead td{background:#244b40;color:white;font-weight:bold}footer{margin-top:18px;border-top:1px solid #dde5df;padding-top:8px}</style></head><body><header><h1>NutriFit</h1><strong>Informe de progreso</strong><p>'.$escape($r['nombre']).'</p><div class="muted">Período: '.$escape(date('d/m/Y',strtotime($r['rango']['desde']))).' al '.$escape(date('d/m/Y',strtotime($r['rango']['hasta']))).' · '.$r['rango']['dias'].' días<br>Generado: '.$escape($r['generado_en']).' (Argentina)</div></header>'.$body.'<h2>Evolución de peso</h2><p class="muted">'.$escape($note).'</p>'.($weight?'<table class="weight"><thead><tr><td>Fecha</td><td class="value">Peso registrado</td></tr></thead><tbody>'.$weight.'</tbody></table>':'<p>Sin registros de peso en este período.</p>').'<footer class="muted">Datos registrados en NutriFit. La ausencia de registros no significa ausencia de actividad.<br>Nutrición e hidratación: promedios sobre días registrados. Pasos del contador y de sesiones se presentan por separado para evitar duplicarlos. XP y nivel muestran el estado actual, no ganancias del período.<br>Informe personal de seguimiento; no incluye email, datos de sesión ni información de otras cuentas.</footer></body></html>';
        $pdf->loadHtml($html,'UTF-8');$pdf->setPaper('A4');$pdf->render();
        $pdf->getCanvas()->page_text(42,810,'NutriFit · {PAGE_NUM} / {PAGE_COUNT}',$pdf->getFontMetrics()->getFont('DejaVu Sans'),8,[.38,.44,.42]);
        return $pdf->output();
    }
    public static function celdaCSV(mixed $v): string
    {
        $v=(string)($v??'');
        if(preg_match('/^[\s\x00-\x1f]*[=+\-@]/u',$v)||preg_match('/^[\t\r\n]/',$v))$v="'".$v;
        return $v;
    }
    public function csv(array $r): string
    {
        $stream=fopen('php://temp','w+');fwrite($stream,"\xEF\xBB\xBF");
        $write=function(array $row)use($stream){fputcsv($stream,array_map([self::class,'celdaCSV'],$row),';','"','');};
        $write(['Sección','Fecha / período','Métrica','Valor','Unidad']);$write(['Informe',$r['rango']['desde'].' / '.$r['rango']['hasta'],'Nombre',$r['nombre'],'']);
        foreach($this->metricas($r) as [$section,$label,$value,$unit])$write([$section,'',$label,$value===null?'Sin registros':$value,$unit]);
        foreach($r['peso']['historial'] as $row)$write(['Peso',$row['fecha'],'Peso registrado',$row['peso_registrado'],'kg']);
        rewind($stream);$out=stream_get_contents($stream);fclose($stream);return $out;
    }
}
