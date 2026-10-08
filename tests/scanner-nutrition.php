<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/classes/NutricionResolver.php';
class ScannerTestRepository extends AlimentoRepository {
    public array $rows = [];
    public int $remoteCalls = 0;
    public function __construct() {}
    public function buscarLocal(string $query, int $limite): array { return $this->rows; }
    public function buscarExternos(string $original, string $english, bool $packaged, int $limit): array {
        $this->remoteCalls++;
        return [];
    }
    public function guardarNombreMostrado(int $id, string $nombre): void {}
    public function corregirCalorias(int $id, float $calorias): void {}
}
class ScannerTestTranslator extends TraductorAlimentos {
    public function __construct() {}
    public function aIngles(string $es): string { HttpRequestBudget::timeoutMs(1); return $es; }
    public function aEspanolLote(array $names): array { throw new HttpRequestBudgetExceeded('test deadline'); }
}
class ScannerTestPDO extends PDO { public function __construct() {} }
function verifyScanner(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}
$reflection = new ReflectionClass(NutricionResolver::class);
$resolver = $reflection->newInstanceWithoutConstructor();
$repo = new ScannerTestRepository();
foreach (['repo'=>$repo, 'traductor'=>new ScannerTestTranslator(), 'db'=>new ScannerTestPDO()] as $name=>$value) {
    $property = $reflection->getProperty($name);
    $property->setAccessible(true);
    $property->setValue($resolver, $value);
}
$food = ['id'=>1,'nombre'=>'Milanesa','nombre_mostrado'=>null,'categoria'=>'carne',
    'calorias_por_100g'=>250,'proteinas'=>20,'carbohidratos'=>15,'grasas'=>12,'fuente'=>'local'];
$repo->rows = [$food];
HttpRequestBudget::start(24);
$match = $resolver->resolverParaCamara('milanesas', false);
verifyScanner($match['alimento_id'] === 1 && $repo->remoteCalls === 0, 'one validated exact match avoids remote enrichment');
HttpRequestBudget::start(-1);
verifyScanner($resolver->resolverParaCamara('milanesas', false)['alimento_id'] === 1, 'expired remote deadline still permits local nutrition');
$repo->rows = [array_replace($food, ['nombre'=>'Milanesa de pollo'])];
verifyScanner($resolver->resolverParaCamara('milanesa', false)['alimento_id'] === 1, 'deadline retains a validated local variant');
$repo->rows = [$food];
HttpRequestBudget::start(24);
$resolver->buscarParaUsuario('milanesa');
verifyScanner($repo->remoteCalls === 1, 'manual search still enriches its list of variants');
$repo->rows = [array_replace($food, ['fuente'=>'usda','nombre'=>'Milanesa de pollo'])];
$match = $resolver->resolverParaCamara('milanesa', false);
verifyScanner($match['alimento_id'] === 1 && $repo->remoteCalls === 2, 'translation deadline preserves previously obtained nutrition');
$repo->rows = [array_replace($food, ['calorias_por_100g'=>-5])];
HttpRequestBudget::start(-1);
verifyScanner($resolver->resolverParaCamara('milanesa', false) === null, 'invalid nutrition is never invented to fill a timeout');
