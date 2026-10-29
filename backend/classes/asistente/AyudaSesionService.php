<?php
declare(strict_types=1);
require_once __DIR__.'/AyudaNutriFit.php';

final class AyudaSesionError extends RuntimeException
{
    public function __construct(public string $codigo,public int $http=422){parent::__construct($codigo);}
}
/** FAQ local. Historial privado de la sesión PHP; sin modelos externos ni tablas. */
final class AyudaSesionService
{
    private array $state;
    public function __construct(array &$session,int $uid)
    {
        if(($session['ayuda_nutrifit']['usuario_id']??null)!==$uid){
            $session['ayuda_nutrifit']=['usuario_id'=>$uid,'csrf'=>bin2hex(random_bytes(32)),
                'conversaciones'=>[],'secuencia'=>0,'ventana'=>time(),'solicitudes'=>0];
        }
        $this->state=&$session['ayuda_nutrifit'];
    }
    public function estado(?int $id=null): array
    {
        $mensajes=$id!==null?$this->conversacion($id)['mensajes']:[];
        $lista=array_values(array_map(static fn($c)=>['id'=>$c['id'],'titulo'=>$c['titulo']],$this->state['conversaciones']));
        return ['modo'=>'ayuda_local','csrf'=>$this->state['csrf'],'conversaciones'=>array_reverse($lista),'mensajes'=>$mensajes];
    }
    public function autorizar(mixed $token): bool
    {
        return is_string($token)&&hash_equals($this->state['csrf'],$token);
    }
    public function nueva(): array
    {
        if(count($this->state['conversaciones'])>=10)throw new AyudaSesionError('conversaciones_limite',429);
        $id=++$this->state['secuencia'];
        $this->state['conversaciones'][$id]=['id'=>$id,'titulo'=>'Nueva conversación','mensajes'=>[],'respuestas'=>[]];
        return ['id'=>$id];
    }
    private function conversacion(int $id): array
    {
        if(!isset($this->state['conversaciones'][$id]))throw new AyudaSesionError('conversacion_no_disponible',404);
        return $this->state['conversaciones'][$id];
    }
    public function eliminar(int $id): void
    {
        $this->conversacion($id);unset($this->state['conversaciones'][$id]);
    }
    public function enviar(int $id,string $text,string $request): array
    {
        $text=trim($text);
        if($text===''||mb_strlen($text)>2000||!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D',$request))throw new AyudaSesionError('mensaje_invalido');
        $c=$this->conversacion($id);
        if(isset($c['respuestas'][$request])){
            if($c['respuestas'][$request]['entrada']!==$text)throw new AyudaSesionError('solicitud_invalida',409);
            return $c['respuestas'][$request]['respuesta']+['repetida'=>true];
        }
        if(time()-(int)$this->state['ventana']>=60){$this->state['ventana']=time();$this->state['solicitudes']=0;}
        if($this->state['solicitudes']>=30)throw new AyudaSesionError('limite_minuto',429);
        if(count($c['mensajes'])>=40)throw new AyudaSesionError('mensajes_limite',429);
        ++$this->state['solicitudes'];
        $out=AyudaNutriFit::responder($text);
        $c['titulo']=mb_substr($text,0,60);
        $c['mensajes'][]=['rol'=>'user','texto'=>$text,'origen'=>'usuario'];
        $c['mensajes'][]=['rol'=>'assistant','texto'=>$out['texto'],'origen'=>'local'];
        $c['respuestas'][$request]=['entrada'=>$text,'respuesta'=>$out];
        $this->state['conversaciones'][$id]=$c;
        return $out;
    }
}
