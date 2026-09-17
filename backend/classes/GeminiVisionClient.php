<?php
declare(strict_types=1);

/**
 * NutriFit — Cliente HTTP para reconocimiento de alimentos con la API de
 * Gemini (Google), como alternativa gratuita a ClaudeVisionClient — el
 * nivel gratuito de los modelos "Flash" de Gemini no pide tarjeta de
 * crédito, con un límite de uso diario razonable para una app personal.
 *
 * Usa el endpoint clásico "generateContent" (curl crudo, mismo motivo que
 * ClaudeVisionClient: sin dependencias de Composer) con salida JSON
 * estructurada (generationConfig.responseSchema) para una respuesta
 * siempre parseable.
 */
class GeminiVisionClient
{
    // Se fija una versión concreta en vez del alias "gemini-flash-latest": al
    // probar la integración, ese alias apuntaba a gemini-3.8-flash (recién
    // lanzado), que devolvía 503 "high demand" específicamente al combinar
    // imagen + salida JSON estructurada. gemini-3.6-flash funciona sin problemas
    // con la misma request. Si en el futuro esto vuelve a fallar, probar de
    // nuevo con el alias -latest por si ya se estabilizó.
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';
    // El nivel gratuito de Gemini puede tardar mucho o devolver 503 "high
    // demand" en horas pico (se midió hasta ~18s de proceso del lado de
    // Google para un pedido trivial durante las pruebas) — timeout generoso
    // + un reintento absorben esos picos sin que el usuario vea un error.
    private const TIMEOUT_SEGUNDOS = 45;
    private const REINTENTOS = 1;

    private string $apiKey;

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @param string $imagenBase64 JPEG en base64, sin el prefijo "data:image/...;base64,"
     * @return array{es_comida: bool, descripcion: string, alimentos: array<int, array{nombre: string, gramos_estimados: float, envasado: bool}>}
     */
    public function identificarAlimentos(string $imagenBase64): array
    {
        $payload = [
            'contents' => [[
                'parts' => [
                    ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => $imagenBase64]],
                    ['text' => 'Identificá cada alimento o bebida visible en esta foto de un plato de comida '
                        . 'y estimá su porción en gramos (para líquidos, en mililitros equivalentes a gramos). '
                        . 'Respondé en español, con nombres genéricos de alimentos (ej: "arroz blanco", '
                        . '"pechuga de pollo a la plancha"), no marcas comerciales — salvo que el alimento '
                        . 'SEA un producto envasado con etiqueta/marca visible en la foto (ej. una bebida '
                        . 'embotellada, un snack), en cuyo caso usá ese nombre y marcá "envasado" en true '
                        . 'para ese ítem. Si hay varios alimentos separados en el plato, listalos por '
                        . 'separado. Si la imagen no muestra comida o bebida reconocible, marcá es_comida '
                        . 'como false y dejá alimentos vacío.'],
                ],
            ]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'es_comida' => ['type' => 'boolean'],
                        'descripcion' => [
                            'type' => 'string',
                            'description' => 'Descripción breve (una oración) de lo que se ve en la imagen',
                        ],
                        'alimentos' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'nombre' => ['type' => 'string'],
                                    'gramos_estimados' => ['type' => 'number'],
                                    'envasado' => [
                                        'type' => 'boolean',
                                        'description' => 'true si es un producto envasado/comercial con marca visible en la foto',
                                    ],
                                ],
                                'required' => ['nombre', 'gramos_estimados', 'envasado'],
                            ],
                        ],
                    ],
                    'required' => ['es_comida', 'descripcion', 'alimentos'],
                ],
            ],
        ];

        $respuesta = $this->httpPost($payload);
        $intentos = 0;
        while (isset($respuesta['_error']) && ($respuesta['_reintentable'] ?? false) && $intentos < self::REINTENTOS) {
            $intentos++;
            sleep(2);
            $respuesta = $this->httpPost($payload);
        }
        if (isset($respuesta['_error'])) {
            throw new RuntimeException($respuesta['_error']);
        }

        $textoJson = $respuesta['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($textoJson === null) {
            throw new RuntimeException('Gemini no devolvió una respuesta de texto.');
        }

        $decodificado = json_decode($textoJson, true);
        if (!is_array($decodificado)) {
            throw new RuntimeException('La respuesta de Gemini no es JSON válido.');
        }

        return $decodificado;
    }

    /** @param array<string, mixed> $payload */
    private function httpPost(array $payload): array
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        $curlErrorMsg = curl_error($ch);
        curl_close($ch);

        if ($curlError !== 0 || $body === false) {
            return ['_error' => "No se pudo conectar con la API de Gemini (curl $curlError: $curlErrorMsg)."];
        }

        $decoded = json_decode($body, true);

        if ($httpCode !== 200) {
            $mensaje = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP $httpCode") : "HTTP $httpCode";
            // Solo 503 (sobrecarga transitoria del servidor) vale la pena
            // reintentar. Un 429 del nivel gratuito casi siempre es la CUOTA
            // DIARIA agotada (ej. "limit: 20, free_tier_requests") — 2
            // segundos después sigue agotada, así que reintentar solo
            // quema otro de los pocos requests/día disponibles sin chance
            // real de éxito.
            $reintentable = $httpCode === 503;
            return ['_error' => 'Error de la API de Gemini: ' . $mensaje, '_reintentable' => $reintentable];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
