<?php
declare(strict_types=1);

/**
 * NutriFit — Cliente HTTP para Codulia (https://nutricion-api-arg.fly.dev),
 * catálogo de alimentos y productos comerciales específicamente argentinos
 * (marcas como Swift, Paty, Sadia, La Serenísima, Coca-Cola AR, etc.).
 *
 * Nota sobre autenticación: la página pública de Codulia documenta
 * `x-api-key`, pero el dashboard privado del usuario mostraba
 * `Authorization: Bearer`. Probado en vivo contra la API real: `x-api-key`
 * responde 200, `Authorization: Bearer` responde 401. Se implementa con
 * `x-api-key` — si Codulia cambia esto en el futuro, es el único lugar
 * que hay que tocar.
 *
 * La API separa búsqueda de detalle:
 *   - GET /v1/foods/search?q=... → liviano: id, nombre, marca, fuente
 *     (GENERIC/BRANDED), calorías, imagen, porción sugerida. SIN proteínas/
 *     carbohidratos/grasas.
 *   - GET /v1/foods/{id} → macros completos (proteinPer100g, carbsPer100g,
 *     fatPer100g, fiberPer100g, sugarPer100g, sodiumPer100g, barcode).
 *
 * El plan gratuito tiene una cuota mensual chica (medida en pruebas:
 * ~1000 requests/mes + un límite de ráfaga de 120/60s) — por eso este
 * cliente expone la búsqueda liviana y el detalle como dos métodos
 * separados en vez de resolver todo en una sola llamada: quien orquesta
 * (AlimentoRepository) filtra por relevancia REAL antes de gastar cuota
 * pidiendo detalle, en vez de pedirlo para los ~20 resultados de cada
 * búsqueda. El "rank" que devuelve la propia búsqueda de Codulia no
 * siempre es confiable para eso (en pruebas, "coca cola" devolvió
 * "Coliflor Cocido" con rank 0.99 — muy alto pero irrelevante).
 */
class CoduliaClient
{
    private const BASE_URL = 'https://nutricion-api-arg.fly.dev';
    private const TIMEOUT_SEGUNDOS = 6;

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Búsqueda liviana — nombre, marca y calorías, sin macros completos.
     * Devuelve [] ante cualquier falla (timeout, 401/403/404/429/5xx,
     * respuesta vacía o malformada) para no romper nunca al resolver.
     *
     * @return array<int, array{
     *   fuente_id: string, nombre: string, marca: ?string, source: string,
     *   calorias_por_100g: ?float, imagen: ?string,
     *   porcion_gramos: ?float, porcion_label: ?string
     * }>
     */
    public function buscarLigero(string $query, int $limite = 20): array
    {
        if (trim($query) === '') {
            return [];
        }

        $respuesta = $this->httpGet('/v1/foods/search?' . http_build_query(['q' => $query]));
        $items = $respuesta['results'] ?? null;
        if (!is_array($items)) {
            return [];
        }

        $resultados = [];
        foreach (array_slice($items, 0, $limite) as $item) {
            if (!isset($item['id'], $item['name'])) {
                continue;
            }
            $porcion = $item['defaultServing'] ?? null;
            $resultados[] = [
                'fuente_id' => (string) $item['id'],
                'nombre' => mb_substr((string) $item['name'], 0, 120),
                'marca' => (!empty($item['brand'])) ? mb_substr((string) $item['brand'], 0, 80) : null,
                'source' => (string) ($item['source'] ?? 'GENERIC'),
                'calorias_por_100g' => is_numeric($item['caloriesPer100g'] ?? null) ? (float) $item['caloriesPer100g'] : null,
                'imagen' => !empty($item['photoUrl']) ? mb_substr((string) $item['photoUrl'], 0, 255) : null,
                'porcion_gramos' => is_numeric($porcion['amount'] ?? null) ? (float) $porcion['amount'] : null,
                'porcion_label' => !empty($porcion['label']) ? mb_substr((string) $porcion['label'], 0, 80) : null,
            ];
        }

        return $resultados;
    }

    /**
     * Detalle completo (con macros) de UN alimento por su id de Codulia.
     * Devuelve null si no existe, si falta algún macro clave, o ante
     * cualquier falla de red/API — nunca lanza.
     *
     * @return array{
     *   fuente_id: string, nombre: string, categoria: null, marca: ?string,
     *   codigo_barras: ?string, calorias_por_100g: float, proteinas: float,
     *   carbohidratos: float, grasas: float, imagen: ?string,
     *   porcion_gramos: ?float, porcion_label: ?string
     * }|null
     */
    public function obtenerDetalle(string $idExterno): ?array
    {
        $respuesta = $this->httpGet('/v1/foods/' . rawurlencode($idExterno));
        $f = $respuesta['food'] ?? null;
        if (!is_array($f)) {
            return null;
        }

        $calorias = $f['caloriesPer100g'] ?? null;
        $proteinas = $f['proteinPer100g'] ?? null;
        $carbohidratos = $f['carbsPer100g'] ?? null;
        $grasas = $f['fatPer100g'] ?? null;

        // Mismo criterio que USDA/Open Food Facts: sin las 4 macros
        // básicas, el registro no sirve para el dashboard.
        if (!is_numeric($calorias) || !is_numeric($proteinas) || !is_numeric($carbohidratos) || !is_numeric($grasas)) {
            return null;
        }

        $porcion = $f['servings'][0] ?? null;

        return [
            'fuente_id' => (string) ($f['id'] ?? $idExterno),
            'nombre' => mb_substr((string) ($f['name'] ?? ''), 0, 120),
            'categoria' => null, // Codulia no da rubro/categoría en el detalle.
            'marca' => (!empty($f['brand'])) ? mb_substr((string) $f['brand'], 0, 80) : null,
            'codigo_barras' => (!empty($f['barcode'])) ? mb_substr((string) $f['barcode'], 0, 64) : null,
            'calorias_por_100g' => round((float) $calorias, 2),
            'proteinas' => round((float) $proteinas, 2),
            'carbohidratos' => round((float) $carbohidratos, 2),
            'grasas' => round((float) $grasas, 2),
            'imagen' => !empty($f['photoUrl']) ? mb_substr((string) $f['photoUrl'], 0, 255) : null,
            'porcion_gramos' => is_numeric($porcion['amount'] ?? null) ? (float) $porcion['amount'] : null,
            'porcion_label' => !empty($porcion['label']) ? mb_substr((string) $porcion['label'], 0, 80) : null,
        ];
    }

    /** @return array<string, mixed>|null null ante timeout, error de red, o cualquier HTTP != 200 (401/403/404/429/5xx incluidos). */
    private function httpGet(string $path): ?array
    {
        $ch = curl_init(self::BASE_URL . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['x-api-key: ' . $this->apiKey],
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error !== 0 || $body === false || $httpCode !== 200) {
            return null;
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : null;
    }
}
