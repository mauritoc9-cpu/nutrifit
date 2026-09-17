<?php
declare(strict_types=1);

/**
 * NutriFit — Cliente HTTP para reconocimiento de alimentos con la API de
 * Claude (Anthropic), usando visión + salida estructurada (JSON Schema)
 * para identificar varios alimentos por foto y estimar sus porciones.
 *
 * Se implementa con cURL crudo en vez del SDK oficial de PHP porque ese SDK
 * requiere PHP >= 8.1 y este proyecto corre sobre el PHP 8.0 que trae XAMPP
 * por defecto — el mismo motivo por el que OpenFoodFactsClient también usa
 * cURL directo en vez de un cliente HTTP con dependencias de Composer.
 */
class ClaudeVisionClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const ANTHROPIC_VERSION = '2023-06-01';
    private const MODEL = 'claude-haiku-4-5';
    private const TIMEOUT_SEGUNDOS = 25;

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
            'model' => self::MODEL,
            'max_tokens' => 1024,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => $imagenBase64],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'Identificá cada alimento o bebida visible en esta foto de un plato de comida '
                            . 'y estimá su porción en gramos (para líquidos, en mililitros equivalentes a gramos). '
                            . 'Respondé en español, con nombres genéricos de alimentos (ej: "arroz blanco", '
                            . '"pechuga de pollo a la plancha"), no marcas comerciales — salvo que el alimento '
                            . 'SEA un producto envasado con etiqueta/marca visible en la foto (ej. una bebida '
                            . 'embotellada, un snack), en cuyo caso usá ese nombre y marcá "envasado" en true '
                            . 'para ese ítem. Si hay varios alimentos separados en el plato, listalos por '
                            . 'separado. Si la imagen no muestra comida o bebida reconocible, marcá es_comida '
                            . 'como false y dejá alimentos vacío.',
                    ],
                ],
            ]],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
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
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['es_comida', 'descripcion', 'alimentos'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];

        $respuesta = $this->httpPost($payload);

        $textoJson = null;
        foreach ($respuesta['content'] ?? [] as $bloque) {
            if (($bloque['type'] ?? null) === 'text') {
                $textoJson = $bloque['text'];
                break;
            }
        }

        if ($textoJson === null) {
            throw new RuntimeException('Claude no devolvió una respuesta de texto.');
        }

        $decodificado = json_decode($textoJson, true);
        if (!is_array($decodificado)) {
            throw new RuntimeException('La respuesta de Claude no es JSON válido.');
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
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::ANTHROPIC_VERSION,
            ],
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
            // Ver el comentario equivalente en GeminiVisionClient: el libcurl
            // 7.76 de XAMPP se cuelga esperando respuesta por HTTP/2 con
            // algunos servidores. Forzar HTTP/1.1 lo evita.
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($ch);
        curl_close($ch);

        if ($curlError !== 0 || $body === false) {
            throw new RuntimeException('No se pudo conectar con la API de Claude.');
        }

        $decoded = json_decode($body, true);

        if ($httpCode !== 200) {
            $mensaje = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP $httpCode") : "HTTP $httpCode";
            throw new RuntimeException('Error de la API de Claude: ' . $mensaje);
        }

        return is_array($decoded) ? $decoded : [];
    }
}
