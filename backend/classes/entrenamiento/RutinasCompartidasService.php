<?php
declare(strict_types=1);

final class RutinasCompartidasService
{
    public function __construct(private PDO $db) {}

    private function propia(int $uid, int $rid): array
    {
        $s=$this->db->prepare('SELECT id,titulo,lugar,nivel_dificultad FROM rutinas_ejercicios WHERE id=? AND usuario_id=?');
        $s->execute([$rid,$uid]);$r=$s->fetch();
        if (!$r) throw new DomainException('Rutina no disponible para tu cuenta.');
        $s=$this->db->prepare('SELECT catalogo_id,nombre,series,repeticiones,descanso_segundos FROM ejercicios WHERE rutina_id=? ORDER BY orden,id LIMIT 51');
        $s->execute([$rid]);$r['ejercicios']=$s->fetchAll();
        if (!$r['ejercicios'] || count($r['ejercicios'])>50) throw new DomainException('La rutina no se puede compartir.');
        unset($r['id']);return $r;
    }

    public function compartir(int $uid,int $rid): array
    {
        $this->db->beginTransaction();
        try {
            $this->bloquearUsuario($uid);$contenido=$this->propia($uid,$rid);
            // Rotar el QR revoca el anterior, pero nunca elimina las copias importadas.
            $s=$this->db->prepare('UPDATE rutina_comparticiones SET revocada_en=NOW() WHERE usuario_id=? AND rutina_id=? AND revocada_en IS NULL');$s->execute([$uid,$rid]);
            $token=bin2hex(random_bytes(32));$expira=date('Y-m-d H:i:s',time()+7*86400);
            $s=$this->db->prepare('INSERT INTO rutina_comparticiones(usuario_id,rutina_id,token_hash,contenido,expira_en) VALUES(?,?,?,?,?)');
            $s->execute([$uid,$rid,hash('sha256',$token),json_encode($contenido,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$expira]);
            $this->db->commit();return ['codigo'=>'NF-RUTINA-1:'.$token,'expira_en'=>$expira,'rutina'=>$this->publica($contenido)];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    private function token(string $codigo): string
    {
        if (!preg_match('/^(?:NF-RUTINA-1:)?([a-f0-9]{64})$/D',trim($codigo),$m)) throw new DomainException('Código de rutina inválido.');
        return $m[1];
    }
    private function comparticion(string $codigo,bool $lock=false): array
    {
        $s=$this->db->prepare('SELECT * FROM rutina_comparticiones WHERE token_hash=? AND revocada_en IS NULL AND expira_en>NOW()'.($lock?' FOR UPDATE':''));
        $s->execute([hash('sha256',$this->token($codigo))]);$row=$s->fetch();
        if(!$row)throw new DomainException('El código no existe, venció o fue revocado.');
        return $row;
    }
    private function publica(array $r): array
    {
        return ['titulo'=>$r['titulo'],'lugar'=>$r['lugar'],'nivel_dificultad'=>$r['nivel_dificultad'],'ejercicios'=>array_map(fn($e)=>['nombre'=>$e['nombre'],'series'=>(int)$e['series'],'repeticiones'=>$e['repeticiones'],'descanso_segundos'=>$e['descanso_segundos']===null?null:(int)$e['descanso_segundos']],$r['ejercicios'])];
    }
    public function preview(string $codigo): array
    {
        $row=$this->comparticion($codigo);
        return ['rutina'=>$this->publica(json_decode($row['contenido'],true,512,JSON_THROW_ON_ERROR)),'expira_en'=>$row['expira_en']];
    }
    private function bloquearUsuario(int $uid): void
    {
        $s=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');$s->execute([$uid]);
    }
    public function importar(int $uid,string $codigo): array
    {
        $this->db->beginTransaction();
        try {
            $this->bloquearUsuario($uid);$row=$this->comparticion($codigo,true);$hash=hash('sha256',$row['contenido']);
            $s=$this->db->prepare('SELECT rutina_id FROM rutina_importaciones WHERE usuario_id=? AND contenido_hash=?');$s->execute([$uid,$hash]);
            if($id=$s->fetchColumn()){$this->db->commit();return ['rutina_id'=>(int)$id,'ya_importada'=>true];}
            $r=json_decode($row['contenido'],true,512,JSON_THROW_ON_ERROR);
            $s=$this->db->prepare("INSERT INTO rutinas_ejercicios(usuario_id,titulo,lugar,objetivo,nivel_dificultad,descripcion,origen,plan_hash,generada_en) VALUES(?,?,?,'mejorar_habitos',?,'Copia importada por QR. No es una recomendación personalizada.','generada',NULL,NOW())");
            $s->execute([$uid,$r['titulo'],$r['lugar'],$r['nivel_dificultad']]);$rid=(int)$this->db->lastInsertId();
            $s=$this->db->prepare('INSERT INTO ejercicios(rutina_id,catalogo_id,nombre,series,repeticiones,descanso_segundos,orden) VALUES(?,?,?,?,?,?,?)');
            foreach($r['ejercicios'] as $i=>$e)$s->execute([$rid,$e['catalogo_id'],$e['nombre'],$e['series'],$e['repeticiones'],$e['descanso_segundos'],$i+1]);
            $s=$this->db->prepare('INSERT INTO rutina_importaciones(usuario_id,comparticion_id,contenido_hash,rutina_id) VALUES(?,?,?,?)');$s->execute([$uid,$row['id'],$hash,$rid]);
            $this->db->commit();return ['rutina_id'=>$rid,'ya_importada'=>false];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function revocar(int $uid,int $rid): void
    {
        $this->propia($uid,$rid);
        $s=$this->db->prepare('UPDATE rutina_comparticiones SET revocada_en=NOW() WHERE usuario_id=? AND rutina_id=? AND revocada_en IS NULL');$s->execute([$uid,$rid]);
    }
    public function listar(int $uid): array
    {
        $s=$this->db->prepare('SELECT r.id,r.titulo,r.lugar,r.nivel_dificultad,i.seleccionada FROM rutina_importaciones i JOIN rutinas_ejercicios r ON r.id=i.rutina_id AND r.usuario_id=i.usuario_id WHERE i.usuario_id=? ORDER BY i.creada_en DESC LIMIT 100');$s->execute([$uid]);return $s->fetchAll();
    }
    public function seleccionar(int $uid,int $rid): void
    {
        $this->db->beginTransaction();
        try{
            $this->bloquearUsuario($uid);
            if($rid!==0){$s=$this->db->prepare('SELECT i.id FROM rutina_importaciones i JOIN rutinas_ejercicios r ON r.id=i.rutina_id AND r.usuario_id=i.usuario_id WHERE i.usuario_id=? AND i.rutina_id=?');$s->execute([$uid,$rid]);if(!$s->fetch())throw new DomainException('Esa rutina no pertenece a tus importaciones.');}
            $s=$this->db->prepare('UPDATE rutina_importaciones SET seleccionada=0 WHERE usuario_id=?');$s->execute([$uid]);
            if($rid!==0){$s=$this->db->prepare('UPDATE rutina_importaciones SET seleccionada=1 WHERE usuario_id=? AND rutina_id=?');$s->execute([$uid,$rid]);}
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function actual(int $uid): ?array
    {
        $s=$this->db->prepare('SELECT r.* FROM rutina_importaciones i JOIN rutinas_ejercicios r ON r.id=i.rutina_id AND r.usuario_id=i.usuario_id WHERE i.usuario_id=? AND i.seleccionada=1 LIMIT 1');$s->execute([$uid]);$r=$s->fetch();if(!$r)return null;
        $s=$this->db->prepare('SELECT e.*,c.clave AS catalogo_clave FROM ejercicios e LEFT JOIN ejercicios_catalogo c ON c.id=e.catalogo_id WHERE e.rutina_id=? ORDER BY e.orden,e.id');$s->execute([$r['id']]);$r['ejercicios']=$s->fetchAll();
        $s=$this->db->prepare('SELECT id FROM registros_entrenamiento WHERE usuario_id=? AND rutina_id=? AND fecha=CURDATE()');$s->execute([$uid,$r['id']]);$r['completada_hoy']=(bool)$s->fetch();
        $s=$this->db->prepare('SELECT ejercicio_id FROM ejercicios_completados WHERE usuario_id=? AND rutina_id=? AND fecha=CURDATE()');$s->execute([$uid,$r['id']]);$r['ejercicios_completados_hoy']=array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN));
        $r['personalizada']=false;$r['importada']=true;return $r;
    }
}
