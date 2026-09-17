<?php
declare(strict_types=1);

require_once __DIR__ . '/OpenFoodFactsClient.php';
require_once __DIR__ . '/UsdaFdcClient.php';
require_once __DIR__ . '/CoduliaClient.php';
require_once __DIR__ . '/MatchScorer.php';

/**
 * NutriFit — Acceso a datos nutricionales con caché local + fuentes externas.
 *
 * Esta clase solo trae y cachea candidatos crudos; NO decide cuál es el
 * mejor ni traduce nombres — eso es responsabilidad de NutricionResolver
 * (que orquesta esta clase + TraductorAlimentos + MatchScorer). Separar
 * las cosas así permite testear/ajustar el scoring sin tocar cómo se
 * consultan las fuentes.
 *
 * Estrategia (write-through cache):
 *   1. Busca primero en la tabla local `alimentos` (rápido, sin red, y
 *      donde vive el catálogo curado de platos argentinos comunes).
 *   2. Si faltan resultados, consulta fuentes externas:
 *        - Codulia primero SIEMPRE (si hay API key configurada): catálogo
 *          de alimentos y marcas específicamente argentinas, cubre tanto
 *          genéricos como envasados en una sola fuente.
 *        - Después, USDA/Open Food Facts en el orden que ya dependía de
 *          si el alimento es un producto envasado o no.
 *   3. Cada resultado externo se guarda (INSERT ... ON DUPLICATE KEY
 *      UPDATE) en `alimentos` antes de devolverlo, así siempre tiene un
 *      `id` local real y la próxima búsqueda del mismo alimento ya lo
 *      encuentra en el paso 1, sin gastar otra llamada externa.
 *
 * Codulia tiene una cuota mensual chica (medida en pruebas: ~1000
 * requests/mes), y a diferencia de USDA/OFF separa búsqueda (liviana, sin
 * macros) de detalle (con macros). Por eso NO se pide el detalle de todos
 * los resultados de una búsqueda: se prefiltra por relevancia real contra
 * la consulta y solo se gasta cuota en los pocos candidatos que de verdad
 * van a competir por aparecer.
 */
class AlimentoRepository
{
    private const COLUMNAS = 'id, nombre, nombre_mostrado, categoria, calorias_por_100g, proteinas, carbohidratos, grasas, imagen, fuente';
    // Cuántos candidatos de Codulia se llevan al detalle (con macros) por
    // búsqueda — no los ~20 que devuelve el buscador liviano.
    private const CODULIA_MAX_DETALLES = 3;

    private PDO $db;
    private OpenFoodFactsClient $offClient;
    private UsdaFdcClient $usdaClient;
    private ?CoduliaClient $coduliaClient;

    public function __construct(
        PDO $db,
        ?OpenFoodFactsClient $offClient = null,
        ?UsdaFdcClient $usdaClient = null,
        ?CoduliaClient $coduliaClient = null
    ) {
        $this->db = $db;
        $this->offClient = $offClient ?? new OpenFoodFactsClient();
        $this->usdaClient = $usdaClient ?? new UsdaFdcClient(env('USDA_FDC_API_KEY'));

        if ($coduliaClient !== null) {
            $this->coduliaClient = $coduliaClient;
        } else {
            $coduliaKey = env('CODULIA_API_KEY');
            // Sin key configurada, Codulia queda deshabilitado sin romper nada.
            $this->coduliaClient = $coduliaKey ? new CoduliaClient($coduliaKey) : null;
        }
    }

    /**
     * Además del LIKE literal, compara versiones "compactadas" (sin
     * espacios ni guiones, en minúscula) de columna y consulta — así
     * "cocacola" encuentra "Coca-Cola" y "coca cola" encuentra "Coca Cola",
     * sin necesitar que el usuario escriba el separador exacto que tiene
     * la fuente original.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarLocal(string $query, int $limite): array
    {
        // Placeholders repetidos (:q1/:q2/...) porque PDO con prepares
        // reales (PDO::ATTR_EMULATE_PREPARES => false) no permite
        // reutilizar el mismo parámetro nombrado dos veces en una consulta.
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
             FROM alimentos
             WHERE nombre LIKE :q1
                OR nombre_mostrado LIKE :q2
                OR REPLACE(REPLACE(LOWER(nombre), \' \', \'\'), \'-\', \'\') LIKE :q3
                OR REPLACE(REPLACE(LOWER(nombre_mostrado), \' \', \'\'), \'-\', \'\') LIKE :q4
             ORDER BY nombre ASC LIMIT :lim'
        );
        $comodin = '%' . $query . '%';
        $comodinCompacto = '%' . $this->compactar($query) . '%';
        $stmt->bindValue('q1', $comodin);
        $stmt->bindValue('q2', $comodin);
        $stmt->bindValue('q3', $comodinCompacto);
        $stmt->bindValue('q4', $comodinCompacto);
        $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    private function compactar(string $texto): string
    {
        return strtolower(str_replace([' ', '-'], '', $texto));
    }

    /**
     * Trae candidatos de las fuentes externas y los cachea. `$queryEn` es el
     * término ya traducido al inglés (para USDA); `$queryOriginal` es el que
     * escribió/detectó el usuario, tal cual, para Codulia/Open Food Facts.
     *
     * No es una cascada rígida que se detiene en la primera fuente que
     * responde: se combinan candidatos de Codulia + USDA + OFF (hasta
     * $limite en total) y es NutricionResolver quien decide con
     * MatchScorer cuáles sobreviven.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarExternos(string $queryOriginal, string $queryEn, bool $envasado, int $limite): array
    {
        $resultados = [];

        // Codulia va primero y siempre (no depende de $envasado): cubre
        // tanto alimentos genéricos como marcas envasadas argentinas en
        // una sola fuente curada.
        if ($this->coduliaClient !== null) {
            $resultados = array_merge($resultados, $this->buscarCodulia($queryOriginal));
        }

        $fuentesEnOrden = $envasado
            ? [['off', $queryOriginal], ['usda', $queryEn]]
            : [['usda', $queryEn], ['off', $queryOriginal]];

        foreach ($fuentesEnOrden as [$fuenteCorta, $query]) {
            if (count($resultados) >= $limite) {
                break;
            }
            $fuente = $fuenteCorta === 'off' ? 'openfoodfacts' : 'usda';
            $candidatos = $this->consultarFuente($fuenteCorta, $query, $limite);
            foreach ($candidatos as $candidato) {
                $id = $this->cachearAlimentoExterno($candidato, $fuente);
                if ($id === null) {
                    continue;
                }
                $resultados[] = $this->aCandidato($id, $candidato, $fuente);
            }
        }

        return $resultados;
    }

    /**
     * Búsqueda liviana en Codulia + prefiltro de relevancia (gratis, sin
     * gastar cuota) + detalle (con macros) solo de los mejores candidatos.
     * No se usa el "rank" propio de Codulia como único criterio: en
     * pruebas devolvió resultados irrelevantes con rank muy alto (ej.
     * "Coliflor Cocido" con 0.99 para la consulta "coca cola").
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarCodulia(string $queryOriginal): array
    {
        try {
            $ligeros = $this->coduliaClient->buscarLigero($queryOriginal, 20);
        } catch (Throwable $e) {
            error_log('[AlimentoRepository] Codulia (búsqueda) falló: ' . $e->getMessage());
            return [];
        }
        if ($ligeros === []) {
            return [];
        }

        $relevantes = array_values(array_filter(
            $ligeros,
            fn ($c) => MatchScorer::esRelevante($queryOriginal, $c['nombre'])
        ));
        if ($relevantes === []) {
            // El prefiltro fue demasiado estricto: mejor probar con los
            // primeros livianos que gastar cuota en nada.
            $relevantes = $ligeros;
        }

        $resultados = [];
        foreach (array_slice($relevantes, 0, self::CODULIA_MAX_DETALLES) as $ligero) {
            try {
                $detalle = $this->coduliaClient->obtenerDetalle($ligero['fuente_id']);
            } catch (Throwable $e) {
                error_log('[AlimentoRepository] Codulia (detalle) falló: ' . $e->getMessage());
                continue;
            }
            if ($detalle === null) {
                continue;
            }

            $id = $this->cachearAlimentoExterno($detalle, 'codulia');
            if ($id === null) {
                continue;
            }
            $resultados[] = $this->aCandidato($id, $detalle, 'codulia');
        }

        return $resultados;
    }

    /** @return array<int, array<string, mixed>> */
    private function consultarFuente(string $fuente, string $query, int $limite): array
    {
        try {
            return $fuente === 'off'
                ? $this->offClient->buscar($query, $limite + 5)
                : $this->usdaClient->buscar($query, $limite + 5);
        } catch (Throwable $e) {
            // Un problema de red/API externa nunca debe romper la búsqueda:
            // el usuario se queda con los resultados que ya se juntaron.
            error_log("[AlimentoRepository] $fuente falló: " . $e->getMessage());
            return [];
        }
    }

    /** Da forma al candidato tal como lo espera NutricionResolver, sea cual sea la fuente. */
    private function aCandidato(int $id, array $datos, string $fuente): array
    {
        return [
            'id' => $id,
            'nombre' => $datos['nombre'],
            'nombre_mostrado' => null, // se resuelve después, en NutricionResolver
            'categoria' => $datos['categoria'] ?? null,
            'calorias_por_100g' => $datos['calorias_por_100g'],
            'proteinas' => $datos['proteinas'],
            'carbohidratos' => $datos['carbohidratos'],
            'grasas' => $datos['grasas'],
            'imagen' => $datos['imagen'] ?? null,
            'fuente' => $fuente,
        ];
    }

    private function cachearAlimentoExterno(array $alimento, string $fuente): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO alimentos
                   (nombre, categoria, marca, codigo_barras, calorias_por_100g, proteinas, carbohidratos, grasas,
                    imagen, porcion_sugerida_gramos, porcion_sugerida_label, fuente, fuente_id, fuente_actualizado)
                 VALUES
                   (:nombre, :categoria, :marca, :codigo_barras, :cal, :prot, :carb, :gras,
                    :imagen, :porcion_gramos, :porcion_label, :fuente, :fuente_id, NOW())
                 ON DUPLICATE KEY UPDATE
                   nombre = VALUES(nombre),
                   categoria = VALUES(categoria),
                   marca = VALUES(marca),
                   codigo_barras = VALUES(codigo_barras),
                   calorias_por_100g = VALUES(calorias_por_100g),
                   proteinas = VALUES(proteinas),
                   carbohidratos = VALUES(carbohidratos),
                   grasas = VALUES(grasas),
                   imagen = VALUES(imagen),
                   porcion_sugerida_gramos = VALUES(porcion_sugerida_gramos),
                   porcion_sugerida_label = VALUES(porcion_sugerida_label),
                   fuente_actualizado = VALUES(fuente_actualizado),
                   id = LAST_INSERT_ID(id)'
            );
            $stmt->execute([
                'nombre' => $alimento['nombre'],
                'categoria' => $alimento['categoria'] ?? null,
                'marca' => $alimento['marca'] ?? null,
                'codigo_barras' => $alimento['codigo_barras'] ?? null,
                'cal' => $alimento['calorias_por_100g'],
                'prot' => $alimento['proteinas'],
                'carb' => $alimento['carbohidratos'],
                'gras' => $alimento['grasas'],
                'imagen' => $alimento['imagen'] ?? null,
                'porcion_gramos' => $alimento['porcion_gramos'] ?? null,
                'porcion_label' => $alimento['porcion_label'] ?? null,
                'fuente' => $fuente,
                'fuente_id' => $alimento['fuente_id'],
            ]);

            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[AlimentoRepository] No se pudo cachear alimento externo: ' . $e->getMessage());
            return null;
        }
    }

    /** Guarda el nombre_mostrado en español resuelto por TraductorAlimentos, para no tener que volver a traducirlo. */
    public function guardarNombreMostrado(int $alimentoId, string $nombreMostrado): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE alimentos SET nombre_mostrado = :nm WHERE id = :id');
            $stmt->execute(['nm' => $nombreMostrado, 'id' => $alimentoId]);
        } catch (Throwable $e) {
            error_log('[AlimentoRepository] No se pudo guardar nombre_mostrado: ' . $e->getMessage());
        }
    }

    /**
     * Corrige el valor cacheado de calorías/100g cuando MatchScorer detecta
     * un dato inconsistente (típicamente kJ guardado como si fuera kcal),
     * para que registrar_comida.php también calcule bien de ahí en más.
     */
    public function corregirCalorias(int $alimentoId, float $nuevasCalorias): void
    {
        try {
            $stmt = $this->db->prepare('UPDATE alimentos SET calorias_por_100g = :cal WHERE id = :id');
            $stmt->execute(['cal' => $nuevasCalorias, 'id' => $alimentoId]);
        } catch (Throwable $e) {
            error_log('[AlimentoRepository] No se pudo corregir calorías: ' . $e->getMessage());
        }
    }
}
