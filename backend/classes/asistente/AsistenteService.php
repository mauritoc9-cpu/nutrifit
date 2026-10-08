<?php
declare(strict_types=1);
require_once __DIR__.'/ContextoNutriFitService.php';
require_once __DIR__.'/GeminiChatClient.php';
require_once __DIR__.'/AsistenteComidaService.php';
final class AsistenteError extends RuntimeException
{
    public function __construct(public string $codigo, public int $http=422){parent::__construct($codigo);}
}
final class AsistenteService
{
    public function __construct(private PDO $db,private $client=null) {}
    private function q(string $sql,array $a=[]): PDOStatement{$s=$this->db->prepare($sql);$s->execute($a);return $s;}
    public function limpiar(int $uid): void
    {
        // Limpieza acotada oportunista: también usuarios inactivos, sin cron.
        $this->q('DELETE FROM asistente_conversaciones WHERE actualizada_en<DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY actualizada_en LIMIT 100');
        $this->q('DELETE FROM asistente_uso WHERE fecha<DATE_SUB(CURDATE(),INTERVAL 30 DAY) LIMIT 100');
    }
    private function propia(int $uid,int $cid,bool $lock=false): array
    {
        $r=$this->q('SELECT * FROM asistente_conversaciones WHERE id=? AND usuario_id=? AND actualizada_en>=DATE_SUB(NOW(),INTERVAL 30 DAY)'.($lock?' FOR UPDATE':''),[$cid,$uid])->fetch();
        if(!$r)throw new AsistenteError('no_disponible',404);return $r;
    }
    public function listar(int $uid): array{return $this->q('SELECT id,titulo,actualizada_en FROM asistente_conversaciones WHERE usuario_id=? AND actualizada_en>=DATE_SUB(NOW(),INTERVAL 30 DAY) ORDER BY actualizada_en DESC,id DESC LIMIT 30',[$uid])->fetchAll();}
    public function nueva(int $uid): array
    {
        $this->db->beginTransaction();try{
            $this->q('SELECT id FROM usuarios WHERE id=? FOR UPDATE',[$uid]);
            if((int)$this->q('SELECT COUNT(*) FROM asistente_conversaciones WHERE usuario_id=?',[$uid])->fetchColumn()>=30)throw new AsistenteError('conversaciones_limite',429);
            $this->q('INSERT INTO asistente_conversaciones(usuario_id) VALUES(?)',[$uid]);$id=(int)$this->db->lastInsertId();$this->db->commit();return ['id'=>$id,'titulo'=>'Nueva conversación'];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function mensajes(int $uid,int $cid): array
    {
        $this->propia($uid,$cid);
        $rows=$this->q('SELECT m.id,m.rol,m.texto,m.intencion,m.origen,m.creada_en,r.datos FROM asistente_mensajes m LEFT JOIN asistente_respuestas r ON r.conversacion_id=m.conversacion_id AND r.solicitud=m.solicitud AND m.rol=\'assistant\' WHERE m.conversacion_id=? ORDER BY m.id LIMIT 200',[$cid])->fetchAll();
        foreach($rows as &$r){$extra=json_decode($r['datos']??'{}',true)??[];unset($r['datos']);$r+=array_intersect_key($extra,array_flip(['codigo','propuesta']));
            if(isset($r['propuesta']['token']))$r['propuesta']=$this->estadoPropuesta($uid,$r['propuesta']);}
        return $rows;
    }

    private function estadoPropuesta(int $uid,array $propuesta): array
    {
        $a=$this->q('SELECT resultado,expira_en>NOW() vigente,DATE(creada_en)=CURDATE() hoy FROM asistente_acciones WHERE token=? AND usuario_id=?',[$propuesta['token'],$uid])->fetch();
        $propuesta['registrada']=!$a||$a['resultado']!==null;$propuesta['vigente']=$a&&(bool)$a['vigente']&&(bool)$a['hoy'];
        $result=json_decode($a['resultado']??'{}',true)??[];$propuesta['tipo_comida']=$result['tipo_comida']??null;
        if(!$propuesta['tipo_comida']&&isset($result['registro']['id']))$propuesta['tipo_comida']=$this->q('SELECT tipo_comida FROM registros_comidas WHERE id=? AND usuario_id=?',[$result['registro']['id'],$uid])->fetchColumn()?:null;
        return $propuesta;
    }

    public function permisos(int $uid,int $cid,?array $actualizar=null): array
    {
        $this->propia($uid,$cid);
        if($actualizar!==null){
            foreach(['mensajes_gemini','consultar_datos','enviar_datos'] as $k)if(!is_bool($actualizar[$k]??null))throw new AsistenteError('solicitud_invalida');
            if($actualizar['enviar_datos']&&(!$actualizar['mensajes_gemini']||!$actualizar['consultar_datos']||env('ASISTENTE_GEMINI_DATOS_HABILITADOS')!=='1'))throw new AsistenteError('privacidad');
            $this->q('INSERT INTO asistente_permisos(conversacion_id,mensajes_gemini,consultar_datos,enviar_datos) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE mensajes_gemini=VALUES(mensajes_gemini),consultar_datos=VALUES(consultar_datos),enviar_datos=VALUES(enviar_datos),actualizada_en=NOW()',[$cid,(int)$actualizar['mensajes_gemini'],(int)$actualizar['consultar_datos'],(int)$actualizar['enviar_datos']]);
        }
        $p=$this->q('SELECT mensajes_gemini,consultar_datos,enviar_datos FROM asistente_permisos WHERE conversacion_id=?',[$cid])->fetch()?:['mensajes_gemini'=>0,'consultar_datos'=>0,'enviar_datos'=>0];
        foreach($p as $k=>$v)$p[$k]=(bool)$v;
        $p['servidor_datos_habilitados']=env('ASISTENTE_GEMINI_DATOS_HABILITADOS')==='1';
        return $p;
    }
    public function eliminar(int $uid,int $cid): void
    {
        $this->propia($uid,$cid);$this->q('DELETE FROM asistente_conversaciones WHERE id=? AND usuario_id=?',[$cid,$uid]);
    }
    public static function clasificar(string $text,?string $prev=null,?string $prevPeriod=null): array
    {
        $t=mb_strtolower($text);$t=strtr($t,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u']);
        if(preg_match('/ayer|anteayer|mañana|pasada|anterior|mes|año|ultimos? \d+|\d{4}-\d{2}-\d{2}/',$t))return ['intencion'=>'periodo','periodo'=>'hoy','ia'=>false];
        $periodo=preg_match('/semana|semanal/',$t)?'semana':($prevPeriod??'hoy');if(str_contains($t,'hoy'))$periodo='hoy';
        if(preg_match('/\b(agrega\w*|registra\w*|elimina\w*|borra\w*|compr\w*|importa\w*|cambia\w*|modifica\w*|completa\w*|dame xp|sum\w* xp)\b/',$t))return ['intencion'=>'escritura','periodo'=>$periodo,'ia'=>false];
        if(preg_match('/diagnost|medic|diabet|embaraz|enfermed|dolor|anorex|bulimi|alerg|tratamiento|insulina|hipertens|depres|lesion/',$t))return ['intencion'=>'medica','periodo'=>$periodo,'ia'=>false];
        if(preg_match('/ignora|instruccion|prompt|api.?key|token|password|contrase|sql|base de datos|otra cuenta|amigos|ubicacion/',$t))return ['intencion'=>'seguridad','periodo'=>$periodo,'ia'=>false];
        $ia=(bool)preg_match('/mejor|consej|idea|explica|interpreta|compara|orienta|habito/',$t);
        $i=match(true){
            (bool)preg_match('/objetivo nutricional|cual es mi objetivo/',$t)=>'objetivo',
            (bool)preg_match('/comida|aliment|macro|proteina/',$t)=>$ia?'comidas':'calorias',
            (bool)preg_match('/caloria|kcal/',$t)=>'calorias',
            (bool)preg_match('/agua|hidrat|vaso/',$t)=>'agua',
            (bool)preg_match('/paso/',$t)=>'pasos',
            (bool)preg_match('/cuantos.*entren|cuantas.*rutina/',$t)=>'entrenamientos',
            (bool)preg_match('/entren|rutina|ejercicio/',$t)=>'rutina',
            (bool)preg_match('/actividad|camina|carrera|bicic|movimiento/',$t)=>'actividad',
            (bool)preg_match('/peso/',$t)=>'peso',
            (bool)preg_match('/racha/',$t)=>'racha',
            (bool)preg_match('/como vengo|semana|resumen|progreso/',$t)=>'resumen',
            (bool)preg_match('/^\s*(y |explica|por que|eso|esta bien)/',$t)&&$prev!==null=>$prev,
            $ia=>'orientacion',default=>'ayuda'};
        if(in_array($i,['peso','racha','rutina','entrenamientos','pasos','agua','calorias'],true))$ia=false;
        return ['intencion'=>$i,'periodo'=>$periodo,'ia'=>$ia];
    }
    private function acabado(int $cid,string $rid,string $text,string $intent,string $origen,string $codigo='ok',array $extra=[]): array
    {
        $out=['texto'=>$text,'intencion'=>$intent,'origen'=>$origen,'codigo'=>$codigo]+$extra;
        $this->db->beginTransaction();try{
        $this->q('INSERT INTO asistente_mensajes(conversacion_id,solicitud,rol,texto,intencion,origen) VALUES(?,?,\'assistant\',?,?,?)',[$cid,$rid,$text,$intent,$origen]);
        $this->q('UPDATE asistente_conversaciones SET actualizada_en=NOW() WHERE id=?',[$cid]);
        $this->q('INSERT INTO asistente_respuestas(conversacion_id,solicitud,datos) VALUES(?,?,?)',[$cid,$rid,json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)]);
        $this->db->commit();return $out;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private static function esConsultaRegistro(string $text,string $intent): bool
    {
        return in_array($intent,['calorias','comidas','agua','pasos','actividad','rutina','entrenamientos','peso','racha','resumen','objetivo','orientacion','periodo'],true)
            &&(bool)preg_match('/\bmi\b|\bmis\b|consum[ií]|registr[eé]|tom[eé]|\bhice\b|cumpl[ií]|racha|vengo|evoluci|cambi[oó] mi|cu[aá]nta agua|cu[aá]ntos pasos|qu[eé] (?:entrenamiento|rutina) tengo/iu',$text);
    }

    private static function redactar(string $text): string
    {
        $text=preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu','[correo omitido]',$text)??$text;
        return preg_replace('/(?:AIza|sk-)[a-zA-Z0-9_-]{16,}|(?:contrase[nñ]a|password|api.?key)\s*[:=]\s*\S+/iu','[secreto omitido]',$text)??$text;
    }

    private static function sinIdentificadores(array $data): array
    {
        $out=[];foreach($data as $k=>$v){if(preg_match('/(^id$|_id$|email|nombre_usuario|token|hash|password|alerg)/i',(string)$k))continue;$out[$k]=is_array($v)?self::sinIdentificadores($v):$v;}return $out;
    }

    private function historialProveedor(int $cid,string $rid,bool $permitirDatos): array
    {
        $rows=$this->q('SELECT m.rol,m.texto,m.intencion,m.origen,r.datos FROM asistente_mensajes m LEFT JOIN asistente_respuestas r ON r.conversacion_id=m.conversacion_id AND r.solicitud=m.solicitud AND m.rol=\'assistant\' WHERE m.conversacion_id=? AND m.solicitud<>? ORDER BY m.id DESC LIMIT 12',[$cid,$rid])->fetchAll();
        $history=[];$size=0;
        foreach($rows as $r){
            if($r['rol']==='assistant'){$meta=json_decode($r['datos']??'{}',true)??[];$text=(!$permitirDatos&&!empty($meta['datos_personales_modelo']))?'[Orientación personalizada omitida tras revocar permiso]':($meta['texto_modelo']??'[Respuesta local de NutriFit; valores personales omitidos]');}
            else $text=in_array($r['intencion'],['medica','seguridad'],true)?'[Mensaje sensible omitido]':self::redactar($r['texto']);
            $text=mb_substr($text,0,2200);$size+=mb_strlen($text);if($size>10000)break;
            $history[]=['rol'=>$r['rol'],'texto'=>$text];
        }
        return array_reverse($history);
    }

    private function respaldo(int $cid,string $rid,string $text,string $intent,string $code,string $facts): array
    {
        $why=match($code){'cuota'=>'Gemini alcanzó una cuota o límite de uso (429).','limite_diario'=>'Alcanzaste las diez consultas de IA de hoy.','timeout'=>'Gemini tardó más de lo permitido.','configuracion'=>'Gemini no está configurado correctamente en el servidor.',default=>'Gemini no pudo responder en este momento.'};
        $out=($facts?$facts."\n\n":'').$why.' No se registró ninguna comida. Podés consultar tus registros o volver a intentar más tarde.';
        // Respaldo pequeño y explícito del recetario existente; no simula una conversación IA.
        if(preg_match('/\btengo\s+(.{3,180}?)(?:[.?!]|$)/iu',$text,$m)){
            $names=array_slice(array_filter(array_map('trim',preg_split('/,|\s+y\s+/u',$m[1]))),0,6);$foods=[];$resolver=new NutricionResolver($this->db);
            foreach($names as $n)if(mb_strlen($n)<=60&&$resolver->buscarParaAsistente($n))$foods[]=$n;
            if(count($foods)>=2)$out.="\n\nReceta de respaldo (sin IA ni estimación):\n".implode("\n",GeneradorRecetasIA::pasosDeRespaldo($foods));
        }
        return $this->acabado($cid,$rid,$out,$intent,'local',$code);
    }

    public function enviar(int $uid,int $cid,string $text,string $rid): array
    {
        if(trim($text)===''||mb_strlen($text)>2000||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$rid))throw new AsistenteError('mensaje_invalido');
        $this->db->beginTransaction();try{
            $this->q('INSERT IGNORE INTO asistente_control(usuario_id) VALUES(?)',[$uid]);
            $control=$this->q('SELECT *, ocupado_hasta>NOW() ocupado, ventana>DATE_SUB(NOW(),INTERVAL 60 SECOND) reciente FROM asistente_control WHERE usuario_id=? FOR UPDATE',[$uid])->fetch();
            $this->propia($uid,$cid,true);
            $old=$this->q('SELECT m.texto,m.intencion,m.origen,r.datos FROM asistente_mensajes m LEFT JOIN asistente_respuestas r ON r.conversacion_id=m.conversacion_id AND r.solicitud=m.solicitud WHERE m.conversacion_id=? AND m.solicitud=? AND m.rol=\'assistant\'',[$cid,$rid])->fetch();
            if($old){
                $original=$this->q('SELECT texto FROM asistente_mensajes WHERE conversacion_id=? AND solicitud=? AND rol=\'user\'',[$cid,$rid])->fetchColumn();
                if($original!==$text)throw new AsistenteError('solicitud_invalida');
                $this->db->commit();$extra=json_decode($old['datos']??'{}',true)??[];unset($old['datos']);
                if(isset($extra['propuesta']['token']))$extra['propuesta']=$this->estadoPropuesta($uid,$extra['propuesta']);
                return $extra+$old+['codigo'=>'ok','repetida'=>true];
            }
            $pending=$this->q('SELECT texto FROM asistente_mensajes WHERE conversacion_id=? AND solicitud=? AND rol=\'user\'',[$cid,$rid])->fetchColumn();
            if($pending!==false&&($pending!==$text||$control['ocupado']))throw new AsistenteError('solicitud_pendiente',409);
            if($control['ocupado'])throw new AsistenteError('ocupado',409);
            $limit=max(1,min(12,(int)env('ASISTENTE_LIMITE_MINUTO','6')));
            if($control['reciente']&&(int)$control['solicitudes']>=$limit)throw new AsistenteError('limite_minuto',429);
            $this->q('UPDATE asistente_control SET ventana=IF(?=1,ventana,NOW()),solicitudes=?,ocupado_hasta=DATE_ADD(NOW(),INTERVAL 45 SECOND),propietario=? WHERE usuario_id=?',[(int)$control['reciente'],$control['reciente']?(int)$control['solicitudes']+1:1,$rid,$uid]);
            if((int)$this->q('SELECT COUNT(*) FROM asistente_mensajes WHERE conversacion_id=?',[$cid])->fetchColumn()>=198)throw new AsistenteError('mensajes_limite',429);
            $last=$this->q('SELECT intencion FROM asistente_mensajes WHERE conversacion_id=? AND rol=\'assistant\' ORDER BY id DESC LIMIT 1',[$cid])->fetchColumn();
            $previous=$this->q('SELECT texto FROM asistente_mensajes WHERE conversacion_id=? AND rol=\'user\' ORDER BY id DESC LIMIT 8',[$cid])->fetchAll(PDO::FETCH_COLUMN);
            $prevPeriod=null;foreach($previous as $p){if(preg_match('/semana/iu',$p)){$prevPeriod='semana';break;}if(preg_match('/hoy/iu',$p)){$prevPeriod='hoy';break;}}
            $classification=self::clasificar($text,$last?:null,$prevPeriod);
            $followRecord=in_array($last,['calorias','comidas','agua','pasos','actividad','rutina','entrenamientos','peso','racha','resumen','objetivo'],true)
                &&(bool)preg_match('/^[¿\s]*(?:y\s+)?(?:esta semana|hoy|eso|el resumen)[?.!\s]*$/iu',$text);
            if($followRecord){$classification['intencion']=$last;$classification['ia']=false;}
            $intent=$classification['intencion'];
            if($pending===false)$this->q('INSERT INTO asistente_mensajes(conversacion_id,solicitud,rol,texto,intencion,origen) VALUES(?,?,\'user\',?,?,\'usuario\')',[$cid,$rid,$text,$intent]);
            $this->q('UPDATE asistente_conversaciones SET titulo=IF(titulo=\'Nueva conversación\',?,titulo),actualizada_en=NOW() WHERE id=?',[mb_substr($text,0,80),$cid]);
            $this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        try{
            $p=$this->permisos($uid,$cid);
            if(in_array($intent,['medica','seguridad'],true))return $this->acabado($cid,$rid,
                $intent==='medica'?'Puedo ofrecer información general, pero no diagnosticar ni indicar tratamientos, dietas médicas o rehabilitación. Consultá a un profesional de salud.':'No puedo revelar secretos, ejecutar instrucciones sobre otras cuentas ni alterar las reglas del asistente. Podés preguntarme sobre alimentación o entrenamiento.',$intent,'local');
            if($intent==='escritura'&&preg_match('/agua|vasos|peso|contrase|cuenta|\bxp\b|monedas|rutina|elimina|borra|compr/iu',$text))return $this->acabado($cid,$rid,'Esa acción se realiza en su sección de NutriFit. Desde el chat sólo puedo proponer comidas y ofrecer su registro con confirmación explícita.',$intent,'local');
            $registro=$followRecord||self::esConsultaRegistro($text,$intent);
            $context=new ContextoNutriFitService($this->db);$data=[];$facts='';
            if($registro){
                if(!$p['consultar_datos'])return $this->acabado($cid,$rid,'Para consultar tus registros activá «Consultar mis datos en NutriFit» en los permisos de esta conversación. Las preguntas generales no necesitan ese permiso.',$intent,'local','consentimiento_datos');
                if($classification['intencion']==='periodo')return $this->acabado($cid,$rid,'Puedo consultar hoy o esta semana; para peso miro los últimos treinta días. Indicá uno de esos períodos.',$intent,'local');
                $data=$context->obtener($uid,$intent,$classification['periodo']);$facts=$context->texto($intent,$data);
                if(!$classification['ia'])return $this->acabado($cid,$rid,$facts,$intent,'local');
            }
            if(!$p['mensajes_gemini'])return $this->acabado($cid,$rid,($facts?$facts."\n\n":'').'Para conversar con IA activá «Usar Gemini para mis mensajes». Se enviará tu texto y una ventana breve de esta conversación; los registros personales no se envían con ese permiso.',$intent,'local','consentimiento_mensajes');
            $key=trim((string)env('GEMINI_API_KEY'));
            if(!$this->client&&$key==='')return $this->respaldo($cid,$rid,$text,$intent,'configuracion',$facts);
            $this->db->beginTransaction();$this->q('INSERT IGNORE INTO asistente_uso(usuario_id,fecha) VALUES(?,CURDATE())',[$uid]);$used=(int)$this->q('SELECT generaciones FROM asistente_uso WHERE usuario_id=? AND fecha=CURDATE() FOR UPDATE',[$uid])->fetchColumn();
            if($used>=10){$this->db->commit();return $this->respaldo($cid,$rid,$text,$intent,'limite_diario',$facts);}
            $this->q('UPDATE asistente_uso SET generaciones=generaciones+1 WHERE usuario_id=? AND fecha=CURDATE()',[$uid]);$this->db->commit();
            $history=$this->historialProveedor($cid,$rid,$p['enviar_datos']&&$p['servidor_datos_habilitados']);
            $external=$p['enviar_datos']&&$p['servidor_datos_habilitados']?self::sinIdentificadores($data):[];
            try{
                $answer=GeminiChatClient::validar(($this->client??new GeminiChatClient($key,env('ASISTENTE_GEMINI_MODELO','gemini-3.6-flash')))->responder(self::redactar($text),$external,$history));
            }catch(Throwable $e){
                $code=in_array($e->getMessage(),['timeout','cuota','conexion','respuesta_invalida','seguridad','configuracion'],true)?$e->getMessage():'proveedor';
                return $this->respaldo($cid,$rid,$text,$intent,$code,$facts);
            }
            $out=$answer['respuesta'];$code='ok';$extra=['texto_modelo'=>$out,'datos_personales_modelo'=>$external!==[]];
            if($answer['consulta']!=='ninguna'&&!$facts){
                if($p['consultar_datos']){$data=$context->obtener($uid,$answer['consulta'],$answer['periodo']);$facts=$context->texto($answer['consulta'],$data);}
                else{$out.="\n\nPara ver valores reales activá «Consultar mis datos en NutriFit».";$code='consentimiento_datos';}
            }
            if($facts)$out.="\n\n".$facts;
            if($answer['estimar']){
                $proposal=(new AsistenteComidaService($this->db))->proponer($uid,$cid,$answer['alimentos']);
                if(isset($proposal['token']))$proposal=$this->estadoPropuesta($uid,$proposal);
                $extra['propuesta']=$proposal;
                $out.="\n\n".($proposal['disponible']?'Estimación calculada por NutriFit. Revisá los alimentos, preparación y gramos totales abajo. Si querés registrarla, elegí el tipo de comida y confirmá.':$proposal['mensaje']);
            }
            return $this->acabado($cid,$rid,$out,'conversacion','gemini',$code,$extra);
        }finally{if($this->db->inTransaction())$this->db->rollBack();$this->q('UPDATE asistente_control SET ocupado_hasta=NULL,propietario=NULL WHERE usuario_id=? AND propietario=?',[$uid,$rid]);}
    }
}
