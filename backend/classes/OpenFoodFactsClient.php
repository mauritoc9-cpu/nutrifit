<?php
declare(strict_types=1);

/**
 * NutriFit — Cliente HTTP para Open Food Facts.
 *
 * Se eligió Open Food Facts como fuente externa de alimentos porque:
 *  - No requiere API key ni registro (cero fricción de integración).
 *  - Catálogo muy amplio (millones de productos), con buena cobertura
 *    de marcas y productos envasados de Argentina/Latinoamérica.
 *  - Soporta nombres en español vía el parámetro `lc=es`.
 *  - Licencia abierta (ODbL), pensada para reutilización por terceros.
 *
 * Si en el futuro se necesita mayor precisión nutricional para alimentos
 * genéricos/crudos (ej. "pechuga de pollo"), se puede sumar como fuente
 * complementaria USDA FoodData Central (gratuita, requiere API key simple).
 *
 * Nota: se usa el motor de búsqueda nuevo (search-a-licious, en
 * search.openfoodfacts.org) en vez del endpoint legacy `cgi/search.pl`.
 * El legacy resultó no confiable en pruebas: la misma consulta exacta
 * devolvía 0 resultados en algunos intentos y resultados correctos en
 * otros. El motor nuevo respondió consistente en pruebas repetidas.
 */
class OpenFoodFactsClient
{
    private const SEARCH_URL = 'https://search.openfoodfacts.org/search';
    private const TIMEOUT_SEGUNDOS = 4;

    /**
     * Busca productos por texto y devuelve solo los que tienen
     * información nutricional completa (kcal, proteínas, carbos, grasas).
     *
     * @return array<int, array{
     *   fuente_id: string, nombre: string, categoria: ?string,
     *   calorias_por_100g: float, proteinas: float, carbohidratos: float,
     *   grasas: float, imagen: ?string
     * }>
     */
    public function buscar(string $query, int $limite = 10): array
    {
        if (!function_exists('curl_init') || trim($query) === '') {
            return [];
        }

        $params = http_build_query([
            'q' => $query,
            'page_size' => $limite,
            'langs' => 'es',
            'fields' => 'code,product_name,product_name_es,generic_name,categories,'
                . 'image_front_small_url,image_url,nutriments',
        ]);

        $respuesta = $this->httpGet(self::SEARCH_URL . '?' . $params);
        if ($respuesta === null) {
            return [];
        }

        $decoded = json_decode($respuesta, true);
        $productos = $decoded['hits'] ?? null;
        if (!is_array($productos)) {
            return [];
        }

        $resultados = [];
        foreach ($productos as $producto) {
            $normalizado = $this->normalizar($producto);
            if ($normalizado !== null) {
                $resultados[] = $normalizado;
            }
        }

        return $resultados;
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
            // Open Food Facts pide un User-Agent descriptivo para identificar la app.
            CURLOPT_USERAGENT => 'NutriFit/1.0 (buscador de alimentos; contacto: nutrifit.argentina2026@gmail.com)',
            CURLOPT_FAILONERROR => true,
        ]);

        $body = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error !== 0 || $body === false) {
            return null;
        }

        return $body;
    }

    /**
     * Convierte un producto de OFF al esquema de la tabla `alimentos`.
     * Devuelve null si al producto le falta información nutricional clave
     * (evita cachear alimentos "incompletos" e inútiles para el dashboard).
     */
    private function normalizar(array $producto): ?array
    {
        $codigo = trim((string) ($producto['code'] ?? ''));
        $nombre = trim((string) (
            $producto['product_name_es']
            ?? $producto['product_name']
            ?? $producto['generic_name']
            ?? ''
        ));

        if ($codigo === '' || $nombre === '') {
            return null;
        }

        $nutrientes = $producto['nutriments'] ?? [];
        $calorias = $this->extraerNumero($nutrientes, 'energy-kcal_100g');
        $proteinas = $this->extraerNumero($nutrientes, 'proteins_100g');
        $carbohidratos = $this->extraerNumero($nutrientes, 'carbohydrates_100g');
        $grasas = $this->extraerNumero($nutrientes, 'fat_100g');

        // Sin calorías o macros básicas el registro no sirve para el dashboard.
        if ($calorias === null || $proteinas === null || $carbohidratos === null || $grasas === null) {
            return null;
        }

        $categoria = null;
        if (!empty($producto['categories'])) {
            $primeraCategoria = explode(',', (string) $producto['categories'])[0] ?? '';
            $categoria = trim($primeraCategoria) !== '' ? mb_substr(trim($primeraCategoria), 0, 60) : null;
        }

        $imagen = $producto['image_front_small_url'] ?? $producto['image_url'] ?? null;

        return [
            'fuente_id' => $codigo,
            'nombre' => mb_substr($nombre, 0, 120),
            'categoria' => $categoria,
            'calorias_por_100g' => $calorias,
            'proteinas' => $proteinas,
            'carbohidratos' => $carbohidratos,
            'grasas' => $grasas,
            'imagen' => $imagen !== null ? mb_substr((string) $imagen, 0, 255) : null,
        ];
    }

    private function extraerNumero(array $nutrientes, string $clave): ?float
    {
        if (!isset($nutrientes[$clave]) || !is_numeric($nutrientes[$clave])) {
            return null;
        }
        return round((float) $nutrientes[$clave], 2);
    }
}
