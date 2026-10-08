<?php
declare(strict_types=1);
require_once __DIR__.'/../NutricionResolver.php';
require_once __DIR__.'/../nutricion/GeneradorRecetasIA.php';

/** Propuestas verificables. No escribe comidas ni otorga XP. */
final class AsistenteComidaService
{
    public function __construct(private PDO $db) {}

    public function proponer(int $uid,int $cid,array $alimentos): array
    {
        $resolver=new NutricionResolver($this->db);$items=[];$faltantes=[];$advertencias=[];
        foreach($alimentos as $a){
            $candidatos=$resolver->buscarParaAsistente($a['nombre']);
            $preparacion=MatchScorer::normalizar($a['preparacion']);
            $metodo=null;foreach(['horno','frit','herv','plancha','crudo','cocido'] as $m)if(str_contains($preparacion,$m)){$metodo=$m;break;}
            if($metodo!==null){
                $coinciden=array_values(array_filter($candidatos,fn($c)=>str_contains(MatchScorer::normalizar($c['nombre_mostrado']??$c['nombre']),$metodo)));
                if($coinciden)$candidatos=$coinciden;
                else $advertencias[]='El catálogo no distingue «'.$a['preparacion'].'» para «'.$a['nombre'].'»; el resultado puede variar por cocción y aceite.';
            }
            $c=$candidatos[0]??null;
            if(!$c){$faltantes[]=$a['nombre'];continue;}
            $nombre=$c['nombre_mostrado']??$c['nombre'];
            $catalogo=MatchScorer::normalizar($nombre);
            if((str_contains($preparacion,'horno')&&str_contains($catalogo,'frit'))
                ||(str_contains($preparacion,'frit')&&str_contains($catalogo,'horno'))
                ||(str_contains($preparacion,'crudo')&&preg_match('/horno|frit|herv|cocid/',$catalogo))){$faltantes[]=$a['nombre'].' ('.$a['preparacion'].')';continue;}
            $grams=round((float)$a['gramos'],1);
            $items[]=['alimento_id'=>(int)$c['id'],'nombre'=>$nombre,'consulta'=>$a['nombre'],'gramos'=>$grams,
                'supuesto'=>$a['supuesto'],'preparacion'=>$a['preparacion'],'fuente'=>$c['fuente']??'catalogo',
                'aporte'=>GeneradorRecetasIA::aporte($c,$grams)];
            if($catalogo!==MatchScorer::normalizar($a['nombre']))$advertencias[]='Para «'.$a['nombre'].'» se usó «'.$nombre.'». Revisá si corresponde.';
        }
        if($faltantes)return ['disponible'=>false,'faltantes'=>$faltantes,
            'mensaje'=>'No encontré una coincidencia suficientemente confiable para: '.implode(', ',$faltantes).'. Precisá el alimento o elegilo en Nutrición; todavía no se puede registrar este plato.'];
        if(!$items)return ['disponible'=>false,'mensaje'=>'Faltan alimentos para calcular la porción.'];
        $total=array_fill_keys(['calorias','proteinas','carbohidratos','grasas'],0.0);
        foreach($items as $i)foreach($total as $k=>$v)$total[$k]+=$i['aporte'][$k];
        foreach($total as $k=>$v)$total[$k]=round($v,2);
        $propuesta=['disponible'=>true,'items'=>$items,'total'=>$total,'advertencias'=>array_values(array_unique($advertencias)),
            'incertidumbre'=>'Estimación orientativa: el peso por unidad, el empanado y el aceite cambian los valores. No hay un margen porcentual validado; revisá los gramos totales y las coincidencias antes de confirmar.'];
        // Misma porción en una conversación = misma acción, incluso después de un reintento generativo.
        $canonical=array_map(fn($i)=>[$i['alimento_id'],$i['gramos']],$items);sort($canonical);
        $huella=hash('sha256',json_encode([date('Y-m-d'),$canonical]));$token=bin2hex(random_bytes(32));
        $q=$this->db->prepare('INSERT IGNORE INTO asistente_acciones(token,conversacion_id,usuario_id,huella,propuesta,expira_en) VALUES(?,?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))');
        $q->execute([$token,$cid,$uid,$huella,json_encode($propuesta,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $q=$this->db->prepare('SELECT token,propuesta,resultado,expira_en,expira_en>NOW() vigente FROM asistente_acciones WHERE conversacion_id=? AND usuario_id=? AND huella=?');$q->execute([$cid,$uid,$huella]);$row=$q->fetch();
        $out=json_decode($row['propuesta'],true);$out['token']=$row['token'];$out['registrada']=$row['resultado']!==null;$out['vigente']=(bool)$row['vigente'];$out['expira_en']=$row['expira_en'];
        return $out;
    }
}
