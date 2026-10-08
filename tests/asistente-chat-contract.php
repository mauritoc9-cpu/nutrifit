<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit;
require_once __DIR__.'/../backend/classes/asistente/GeminiChatClient.php';
$count=0;
function verify(string $name,bool $ok): void {global $count;if(!$ok)throw new RuntimeException('FAIL '.$name);echo "PASS $name\n";$count++;}
$valid=['respuesta'=>'1. Pollo con arroz. 2. Huevo con arroz. ¿Qué opción preferís?','consulta'=>'ninguna','periodo'=>'hoy','estimar'=>false,'alimentos'=>[]];
$seen=[];$transport=function($payload)use(&$seen,$valid){$seen=$payload;return ['candidates'=>[['finishReason'=>'STOP','content'=>['parts'=>[['thought'=>true,'text'=>'NO MOSTRAR pensamiento'],['text'=>json_encode($valid,JSON_UNESCAPED_UNICODE)]]]]]];};
$r=(new GeminiChatClient('test','gemini-3.6-flash',$transport))->responder('Tengo pollo, arroz y huevo.',[],[['rol'=>'assistant','texto'=>'¿Qué tenés para cocinar?']]);
verify('protocolo estructurado y excluye pensamiento',$r===$valid);
$body=json_decode($seen['contents'][0]['parts'][0]['text'],true);
verify('pregunta y memoria libres viajan sin reemplazo',$body['consulta']==='Tengo pollo, arroz y huevo.'&&$body['conversacion_reciente'][0]['texto']==='¿Qué tenés para cocinar?');
verify('schema no ofrece escrituras arbitrarias',!in_array('ejecutar_sql',$seen['generationConfig']['responseSchema']['properties']['consulta']['enum'],true));
verify('presupuesto de salida y razonamiento acotado',$seen['generationConfig']['maxOutputTokens']===2048&&$seen['generationConfig']['thinkingConfig']['thinkingLevel']==='minimal');
foreach([
 ['candidates'=>[['finishReason'=>'MAX_TOKENS','content'=>['parts'=>[['text'=>json_encode($valid)]]]]]],
 ['candidates'=>[['content'=>['parts'=>[['text'=>'no es JSON']]]]]],
 ['candidates'=>[['content'=>['parts'=>[['text'=>json_encode(array_replace($valid,['consulta'=>'ejecutar_sql']))]]]]]],
 ['promptFeedback'=>['blockReason'=>'SAFETY']]
] as $bad){try{(new GeminiChatClient('test','gemini-3.6-flash',fn()=>$bad))->responder('general',[]);verify('rechaza proveedor inválido',false);}catch(RuntimeException $e){verify('rechaza proveedor inválido',true);}}
echo "TOTAL $count passed; contrato de transporte simulado, sin DB ni red.\n";
