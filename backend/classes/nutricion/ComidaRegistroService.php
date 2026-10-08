<?php
declare(strict_types=1);
require_once __DIR__.'/../SistemaXP.php';
require_once __DIR__.'/../gamificacion/GamificacionService.php';
require_once __DIR__.'/GeneradorRecetasIA.php';

final class ComidaRegistroError extends RuntimeException
{
    public function __construct(string $mensaje,public int $http=422){parent::__construct($mensaje);}
}

/** Pipeline único para registro manual/cámara y propuestas del asistente. */
final class ComidaRegistroService
{
    public function __construct(private PDO $db) {}
    public function registrar(int $uid,string $tipo,array $items,string $origen='manual',?string $accion=null,bool $confirmado=false): array
    {
        if(!in_array($tipo,['desayuno','almuerzo','merienda','cena','snack'],true))throw new ComidaRegistroError('Elegí un tipo de comida válido.');
        if($accion!==null&&(!$confirmado||!preg_match('/^[a-f0-9]{64}$/D',$accion)))throw new ComidaRegistroError('Revisá la propuesta y confirmá explícitamente su registro.');
        $this->db->beginTransaction();
        try{
            $s=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');$s->execute([$uid]);
            if($accion!==null){
                $s=$this->db->prepare('SELECT a.*,a.expira_en>NOW() vigente,DATE(a.creada_en)=CURDATE() hoy,c.actualizada_en>=DATE_SUB(NOW(),INTERVAL 30 DAY) conversacion_vigente FROM asistente_acciones a JOIN asistente_conversaciones c ON c.id=a.conversacion_id WHERE a.token=? AND a.usuario_id=? AND c.usuario_id=? FOR UPDATE');$s->execute([$accion,$uid,$uid]);$a=$s->fetch();
                if(!$a)throw new ComidaRegistroError('Propuesta no disponible.',404);
                if($a['resultado']!==null){$r=json_decode($a['resultado'],true);$this->db->commit();return $r+['repetida'=>true];}
                if(!$a['vigente']||!$a['hoy']||!$a['conversacion_vigente'])throw new ComidaRegistroError('Esta propuesta venció. Pedí una estimación nueva.');
                $p=json_decode($a['propuesta'],true);$items=$p['items']??[];$origen='manual';
            }
            if(count($items)<1||count($items)>8)throw new ComidaRegistroError('Porción inválida.');
            $registros=[];
            foreach($items as $i){
                $id=filter_var($i['alimento_id']??null,FILTER_VALIDATE_INT);$grams=$i['gramos']??null;
                if(!$id||$id<1||!is_numeric($grams)||!is_finite((float)$grams)||(float)$grams<=0||(float)$grams>2000)throw new ComidaRegistroError('Alimento o gramos inválidos.');
                $grams=(float)$grams;$s=$this->db->prepare('SELECT * FROM alimentos WHERE id=?');$s->execute([$id]);$food=$s->fetch();
                if(!$food)throw new ComidaRegistroError('Alimento no encontrado.',404);
                $aporte=GeneradorRecetasIA::aporte($food,$grams);
                if($accion!==null&&$aporte!=$i['aporte'])throw new ComidaRegistroError('El catálogo cambió desde la estimación. Pedí una nueva propuesta.');
                $s=$this->db->prepare('INSERT INTO registros_comidas(usuario_id,alimento_id,tipo_comida,gramos,calorias_totales,proteinas_totales,carbohidratos_totales,grasas_totales,origen,fecha) VALUES(?,?,?,?,?,?,?,?,?,CURDATE())');
                $s->execute([$uid,$id,$tipo,$grams,$aporte['calorias'],$aporte['proteinas'],$aporte['carbohidratos'],$aporte['grasas'],in_array($origen,['manual','camara_ia'],true)?$origen:'manual']);
                $registros[]=['id'=>(int)$this->db->lastInsertId(),'alimento'=>$food['nombre_mostrado']??$food['nombre'],'gramos'=>$grams]+$aporte;
            }
            // Una propuesta es una comida, no una recompensa por cada ingrediente.
            $xp=(new SistemaXP($this->db))->otorgarXPConLimite($uid,SistemaXP::XP_COMIDA,'comida',SistemaXP::MAX_COMIDA_XP_DIA);
            $r=['gamificacion'=>(new GamificacionService($this->db))->reconciliar($uid,$xp),'registro'=>$registros[0],'registros'=>$registros,'tipo_comida'=>$tipo,'xp'=>$xp];
            if($accion!==null){$s=$this->db->prepare('UPDATE asistente_acciones SET resultado=? WHERE token=? AND usuario_id=?');$s->execute([json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$accion,$uid]);}
            $this->db->commit();return $r;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
