<?php
declare(strict_types=1);
// Run with php -n: substitute only cURL transport, never call an AI provider.
if (PHP_SAPI === 'cli' && !extension_loaded('curl')) {
foreach (['CURLOPT_RETURNTRANSFER','CURLOPT_POST','CURLOPT_POSTFIELDS','CURLOPT_HTTPHEADER','CURLOPT_CONNECTTIMEOUT_MS','CURLOPT_TIMEOUT_MS','CURLOPT_HTTP_VERSION','CURL_HTTP_VERSION_1_1','CURLINFO_HTTP_CODE'] as $i => $name) define($name, $i+1);
$options = []; $calls = 0; $status = 503; $transportError = 0;
function curl_init($url) { return $url; }
function curl_setopt_array($ch, $value) { $GLOBALS['options'] = $value; return true; }
function curl_exec($ch) { $GLOBALS['calls']++; return $GLOBALS['transportError'] ? false : json_encode($GLOBALS['response']); }
function curl_getinfo($ch, $info) { return $GLOBALS['status']; }
function curl_errno($ch) { return $GLOBALS['transportError']; }
function curl_error($ch) { return 'Operation timed out'; }
function curl_close($ch) {}
function check(bool $condition, string $label): void { if (!$condition) throw new RuntimeException($label); echo 'PASS '.$label.PHP_EOL; }
require_once __DIR__.'/../backend/classes/GeminiVisionClient.php';
check(HttpRequestBudget::timeoutMs(4) === 4000, 'unrelated requests retain their original timeout');
HttpRequestBudget::start(24);
$response = ['error' => ['message' => 'Provider overloaded']];
try { (new GeminiVisionClient('test-not-a-real-key'))->identificarAlimentos('test-image'); throw new LogicException('Expected provider error'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'Provider overloaded'), '503 remains a real provider error'); }
check($calls === 1, 'no blocking retry after 503');
check($options[CURLOPT_TIMEOUT_MS] <= 18000 && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 5000, 'vision timeout stays below Heroku limit');
HttpRequestBudget::start(0.5);
check(HttpRequestBudget::timeoutMs(25) <= 500, 'fallback calls share the remaining request budget');
HttpRequestBudget::start(-1);
$previous = $calls;
try { (new GeminiVisionClient('test-not-a-real-key'))->identificarAlimentos('test-image'); throw new LogicException('Expected deadline failure'); }
catch (HttpRequestBudgetExceeded $e) { check($calls === $previous, 'expired budget prevents another upstream call'); }
HttpRequestBudget::start(24);
$status = 200;
$response = ['candidates' => [['content' => ['parts' => [['text' => json_encode(['es_comida'=>true,'alimentos'=>[['nombre'=>'milanesa','gramos_estimados'=>150,'envasado'=>false]]])]]]]]];
$result = (new GeminiVisionClient('test-not-a-real-key'))->identificarAlimentos('test-image');
check($result['alimentos'][0]['nombre'] === 'milanesa', 'successful vision result keeps original contract');
$transportError = 28;
try { (new GeminiVisionClient('test-not-a-real-key'))->identificarAlimentos('test-image'); throw new LogicException('Expected timeout'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'curl 28'), 'transport timeout is reported, not success'); }
$manifest = json_decode(file_get_contents(__DIR__.'/../composer.json'), true);
$lock = json_decode(file_get_contents(__DIR__.'/../composer.lock'), true);
check(isset($manifest['require']['ext-mbstring'], $lock['platform']['ext-mbstring']), 'mbstring declared in Heroku manifest and lock');
} else {
    http_response_code(404);
    exit(2);
}
