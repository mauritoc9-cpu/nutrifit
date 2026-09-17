<?php
declare(strict_types=1);

/**
 * NutriFit — Cliente HTTP para USDA FoodData Central.
 *
 * Fuente para alimentos GENÉRICOS y preparados "tal como se consumen"
 * (Foundation Foods, SR Legacy y Survey/FNDDS) — justo lo que Open Food
 * Facts no cubre bien, porque OFF es un catálogo de productos envasados
 * con código de barras. Se excluye a propósito el dataType "Branded" de
 * USDA: ese sigue siendo trabajo de Open Food Facts.
 *
 * Gratis, sin tarjeta de crédito (alta en api.data.gov/signup), límite de
 * 1000 requests/hora por key — de sobra para el volumen de esta app. Sin
 * key configurada cae a DEMO_KEY (30/hora, 50/día) para no romper nada en
 * desarrollo.
 *
 * Mismo patrón de cURL crudo que OpenFoodFactsClient/ClaudeVisionClient
 * (sin dependencias de Composer, compatible con el PHP 8.0 de XAMPP).
 */
class UsdaFdcClient
{
    private const SEARCH_URL = 'https://api.nal.usda.gov/fdc/v1/foods/search';
    private const TIMEOUT_SEGUNDOS = 6;
    // Excluye "Branded": esos productos envasados los cubre Open Food Facts.
    private const DATA_TYPES = 'Foundation,SR Legacy,Survey (FNDDS)';

    private string $apiKey;

    public function __construct(?string $apiKey = null)
    {
        $this->apiKey = $apiKey !== null && $apiKey !== '' ? $apiKey : 'DEMO_KEY';
    }

    /**
     * @param string $queryEn Nombre del alimento EN INGLÉS (USDA no entiende español)
     * @return array<int, array{
     *   fuente_id: string, nombre: string, categoria: ?string,
     *   calorias_por_100g: float, proteinas: float, carbohidratos: float,
     *   grasas: float, imagen: null
     * }>
     */
    public function buscar(string $queryEn, int $limite = 10): array
    {
        if (trim($queryEn) === '') {
            return [];
        }

        $params = http_build_query([
            'query' => $queryEn,
            'pageSize' => $limite,
            'dataType' => self::DATA_TYPES,
            'api_key' => $this->apiKey,
        ]);

        $respuesta = $this->httpGet(self::SEARCH_URL . '?' . $params);
        if ($respuesta === null) {
            return [];
        }

        $decoded = json_decode($respuesta, true);
        $alimentos = $decoded['foods'] ?? null;
        if (!is_array($alimentos)) {
            return [];
        }

        $resultados = [];
        foreach ($alimentos as $alimento) {
            $normalizado = $this->normalizar($alimento);
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
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error !== 0 || $body === false || $httpCode !== 200) {
            return null;
        }

        return $body;
    }

    /**
     * Convierte un resultado de /foods/search al esquema de la tabla
     * `alimentos`. Devuelve null si falta algún macro clave o si la única
     * energía disponible viene en kJ y no se puede convertir con confianza.
     */
    private function normalizar(array $alimento): ?array
    {
        $fdcId = $alimento['fdcId'] ?? null;
        $nombre = trim((string) ($alimento['description'] ?? ''));
        if ($fdcId === null || $nombre === '') {
            return null;
        }

        $nutrientes = $alimento['foodNutrients'] ?? [];
        $calorias = $this->extraerEnergiaKcal($nutrientes);
        $proteinas = $this->extraerNumero($nutrientes, 'Protein');
        $carbohidratos = $this->extraerNumero($nutrientes, 'Carbohydrate, by difference');
        $grasas = $this->extraerNumero($nutrientes, 'Total lipid (fat)');

        if ($calorias === null || $proteinas === null || $carbohidratos === null || $grasas === null) {
            return null;
        }

        return [
            'fuente_id' => (string) $fdcId,
            'nombre' => mb_substr($nombre, 0, 120),
            'categoria' => isset($alimento['foodCategory']) ? mb_substr((string) $alimento['foodCategory'], 0, 60) : null,
            'calorias_por_100g' => $calorias,
            'proteinas' => $proteinas,
            'carbohidratos' => $carbohidratos,
            'grasas' => $grasas,
            'imagen' => null,
        ];
    }

    /**
     * USDA a veces lista la energía dos veces: una en KCAL y otra en kJ.
     * Se busca explícitamente la variante en KCAL; si solo existe en kJ,
     * se convierte (÷4.184) en vez de descartar el alimento.
     */
    private function extraerEnergiaKcal(array $nutrientes): ?float
    {
        $kcal = null;
        $kj = null;
        foreach ($nutrientes as $n) {
            if (($n['nutrientName'] ?? '') !== 'Energy' || !isset($n['value']) || !is_numeric($n['value'])) {
                continue;
            }
            $unidad = strtoupper((string) ($n['unitName'] ?? ''));
            if ($unidad === 'KCAL') {
                $kcal = (float) $n['value'];
            } elseif ($unidad === 'KJ') {
                $kj = (float) $n['value'];
            }
        }

        if ($kcal !== null) {
            return round($kcal, 2);
        }
        if ($kj !== null) {
            return round($kj / 4.184, 2);
        }
        return null;
    }

    private function extraerNumero(array $nutrientes, string $nombreNutriente): ?float
    {
        foreach ($nutrientes as $n) {
            if (($n['nutrientName'] ?? '') === $nombreNutriente && isset($n['value']) && is_numeric($n['value'])) {
                return round((float) $n['value'], 2);
            }
        }
        return null;
    }
}
