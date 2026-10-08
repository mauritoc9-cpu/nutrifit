<?php
declare(strict_types=1);
require_once __DIR__.'/../ResumenSemanalService.php';
require_once __DIR__.'/../RegistroActividadRepository.php';
require_once __DIR__.'/../entrenamiento/RutinasCompartidasService.php';
require_once __DIR__.'/../personalizacion/PerfilObjetivo.php';
final class ContextoNutriFitService
{
    public function __construct(private PDO $db) {}
    private function fila(string $sql,array $args): ?array{$s=$this->db->prepare($sql);$s->execute($args);return $s->fetch()?:null;}
    public function obtener(int $uid,string $intent,string $periodo): array
    {
        $hoy=date('Y-m-d');$desde=$periodo==='semana'?date('Y-m-d',strtotime('monday this week')):$hoy;
        if($intent==='peso')$desde=date('Y-m-d',strtotime('-29 days'));
        $r=['periodo'=>['desde'=>$desde,'hasta'=>$hoy]];
        if($intent==='objetivo')$r['objetivo']=$this->fila('SELECT objetivo,meta_calorias,meta_proteinas,meta_carbohidratos,meta_grasas FROM perfiles_biometricos WHERE usuario_id=?',[$uid]);
        if(in_array($intent,['calorias','comidas','resumen','orientacion'],true)){
            $s=(new ResumenSemanalService($this->db))->generar($uid,$desde,$hoy);
            $r['nutricion']=$s['nutricion'];
            if($intent==='comidas'||($intent==='orientacion'&&$periodo==='hoy')){
                $q=$this->db->prepare('SELECT COUNT(*) AS registros, SUM(calorias_totales) AS calorias, SUM(proteinas_totales) AS proteinas, SUM(carbohidratos_totales) AS carbohidratos, SUM(grasas_totales) AS grasas FROM registros_comidas WHERE usuario_id=? AND fecha=?');$q->execute([$uid,$hoy]);$r['hoy']=$q->fetch();
                // Nombres externos se omiten: agregados por comida bastan para esta versión.
                $q=$this->db->prepare('SELECT tipo_comida,COUNT(*) registros,SUM(calorias_totales) calorias,SUM(proteinas_totales) proteinas FROM registros_comidas WHERE usuario_id=? AND fecha=? GROUP BY tipo_comida');$q->execute([$uid,$hoy]);$r['comidas']=$q->fetchAll();
            }
        }
        if(in_array($intent,['agua','resumen'],true)){
            if($periodo==='hoy')$r['agua']=$this->fila('SELECT vasos,meta_vasos FROM registros_hidratacion WHERE usuario_id=? AND fecha=?',[$uid,$hoy]);
            else $r['agua']=(new ResumenSemanalService($this->db))->generar($uid,$desde,$hoy)['hidratacion'];
        }
        if(in_array($intent,['pasos','actividad','resumen','entrenamientos'],true)){
            $r['actividad']=(new ResumenSemanalService($this->db))->generar($uid,$desde,$hoy)['actividad'];
            $r['contador']=$this->fila('SELECT COUNT(*) registros,SUM(pasos) pasos FROM registros_pasos WHERE usuario_id=? AND fecha BETWEEN ? AND ?',[$uid,$desde,$hoy]);
        }
        if($intent==='racha')$r['racha']=$this->fila('SELECT racha_dias FROM usuarios WHERE id=?',[$uid])['racha_dias'];
        if($intent==='peso')$r['peso']=(new ResumenSemanalService($this->db))->generar($uid,$desde,$hoy)['peso']['historial'];
        if($intent==='rutina'){
            // Sólo lectura: no llamar al generador de rutinas ni reconciliar XP/logros.
            $r['rutina']=(new RutinasCompartidasService($this->db))->actual($uid);
            if(!$r['rutina']){
                $r['rutina']=$this->fila('SELECT r.id,r.titulo FROM rutinas_ejercicios r WHERE r.usuario_id=? AND NOT EXISTS(SELECT 1 FROM rutina_importaciones i WHERE i.rutina_id=r.id) ORDER BY r.generada_en DESC,r.id DESC LIMIT 1',[$uid]);
                if($r['rutina']){$q=$this->db->prepare('SELECT nombre,series,repeticiones FROM ejercicios WHERE rutina_id=? ORDER BY orden');$q->execute([$r['rutina']['id']]);$r['rutina']['ejercicios']=$q->fetchAll();}
            }
            $r['entrenado']=$this->fila('SELECT COUNT(*) n FROM registros_entrenamiento WHERE usuario_id=? AND fecha=?',[$uid,$hoy])['n'];
        }
        return $r;
    }
    public function texto(string $intent,array $r): string
    {
        $p=$r['periodo']['desde'].' a '.$r['periodo']['hasta'];$lines=['Registros de NutriFit ('.$p.').'];
        if(array_key_exists('objetivo',$r)){
            $o=$r['objetivo'];if(!$o)$lines[]='Todavía no hay un objetivo nutricional configurado.';
            else{$lines[]='Objetivo: '.PerfilObjetivo::etiqueta($o['objetivo']).'.';foreach(['meta_calorias'=>'kcal/día','meta_proteinas'=>'g de proteína/día','meta_carbohidratos'=>'g de carbohidratos/día','meta_grasas'=>'g de grasas/día'] as $k=>$unit)if($o[$k]!==null)$lines[]=$o[$k].' '.$unit;}
        }
        if(isset($r['nutricion'])){$n=$r['nutricion'];$lines[]=$n['dias_con_registro']?($r['periodo']['desde']===$r['periodo']['hasta']?"Hoy registraste {$n['calorias_promedio']} kcal y {$n['proteinas_promedio']} g de proteína.":"Nutrición: {$n['dias_con_registro']} días registrados; promedio {$n['calorias_promedio']} kcal y {$n['proteinas_promedio']} g de proteína por día registrado.") : 'No hay comidas registradas en este período.';if($n['dias_con_registro'])$lines[]='Carbohidratos: '.$n['carbohidratos_promedio'].' g; grasas: '.$n['grasas_promedio'].' g'.($r['periodo']['desde']===$r['periodo']['hasta']?' registrados hoy.':' por día registrado (promedio).');if($n['meta_calorias']!==null)$lines[]='Meta de calorías: '.$n['meta_calorias'].' kcal/día.';}
        if(array_key_exists('agua',$r)){$a=$r['agua'];if(!$a)$lines[]='No hay agua registrada hoy; la meta habitual es 8 vasos (2000 ml).';elseif(isset($a['vasos'])){$ml=$a['vasos']*250;$meta=$a['meta_vasos']*250;$lines[]="Agua: {$a['vasos']} vasos ({$ml} ml), meta {$meta} ml. ".($a['vasos']>=$a['meta_vasos']?'Meta cumplida.':'Meta todavía no cumplida.');}else $lines[]=$a['dias_con_registro']?"Agua: {$a['dias_con_registro']} días registrados; {$a['dias_meta_cumplida']} días con meta cumplida.":'No hay hidratación registrada en este período.';}
        if(isset($r['actividad'])){$a=$r['actividad'];$lines[]=$a['sesiones_totales']?"Actividad: {$a['sesiones_totales']} sesiones, {$a['minutos_totales']} minutos, ".round($a['distancia_metros_total']/1000,2).' km y '.$a['pasos_totales'].' pasos en sesiones.':'No hay sesiones de actividad registradas en este período.';$lines[]='Entrenamientos completados: '.$a['entrenamientos_completados'].'.';if($r['contador']['registros'])$lines[]='Contador de pasos: '.$r['contador']['pasos'].' (fuente separada; no se suma a sesiones).';else $lines[]='Sin registros del contador de pasos.';}
        if(isset($r['racha']))$lines[]='Tu racha actual es de '.$r['racha'].' días.';
        if(isset($r['peso'])){$w=$r['peso'];$lines[]=count($w)>=2?'Peso registrado: '.$w[0]['peso_registrado'].' → '.$w[count($w)-1]['peso_registrado'].' kg; variación '.round((float)$w[count($w)-1]['peso_registrado']-(float)$w[0]['peso_registrado'],1).' kg.':'No hay suficientes registros de peso para calcular evolución (se requieren dos).';}
        if(array_key_exists('rutina',$r)){$lines[]=$r['entrenado']?'Ya registraste un entrenamiento hoy.':'No hay entrenamiento completado registrado hoy.';if($r['rutina']){$lines[]='Rutina disponible: '.$r['rutina']['titulo'].'.';foreach($r['rutina']['ejercicios']??[] as $e)$lines[]=$e['nombre'].': '.$e['series'].' × '.$e['repeticiones'];}else $lines[]='Todavía no hay una rutina propia guardada. Abrí Actividad para consultar tu plan.';}
        return implode("\n",$lines);
    }
}
