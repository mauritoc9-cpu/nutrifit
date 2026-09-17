<?php
declare(strict_types=1);

/**
 * NutriFit — Traducción ES<->EN de nombres de alimentos, con caché en la
 * tabla `traducciones_alimentos` (mismo patrón "write-through" que
 * AlimentoRepository usa para Open Food Facts).
 *
 * USDA FoodData Central solo entiende inglés, pero NutriFit tiene que
 * mostrarse 100% en español. Este cliente resuelve las dos direcciones:
 *
 *  - ES -> EN: para poder consultar USDA con un término en español que
 *    escribió el usuario o que devolvió la IA de visión.
 *  - EN -> ES: para mostrar el `nombre_mostrado` de un resultado que vino
 *    de USDA (o de Open Food Facts con nombre en otro idioma), sin exponer
 *    nunca el nombre original en inglés en la interfaz.
 *
 * Prioridad: diccionario local (rápido, gratis, curado) -> si no está,
 * una sola llamada de texto a la IA que ya tiene la app configurada
 * (Gemini primero, gratis; Claude como respaldo). El resultado se cachea
 * para no volver a pagar/esperar por el mismo término. Si la IA no da una
 * traducción confiable, se marca como tal para que el llamador pueda
 * ocultar ese resultado en vez de mostrar un nombre raro o en inglés.
 */
class TraductorAlimentos
{
    private const TIMEOUT_SEGUNDOS = 12;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Traduce un término de búsqueda del español al inglés para consultar
     * USDA. Si no se puede traducir con confianza, devuelve el término
     * original tal cual (mejor intentar la búsqueda que no buscar nada).
     */
    public function aIngles(string $es): string
    {
        $esNorm = MatchScorer::normalizar($es);
        if ($esNorm === '') {
            return $es;
        }

        $stmt = $this->db->prepare('SELECT en_normalizado FROM traducciones_alimentos WHERE es_normalizado = :es LIMIT 1');
        $stmt->execute(['es' => $esNorm]);
        $fila = $stmt->fetch();
        if ($fila) {
            return $fila['en_normalizado'];
        }

        $resultado = $this->pedirTraduccionesAIA([$es], 'es_a_en');
        $en = $resultado[$es]['en'] ?? null;
        $confiable = $resultado[$es]['confiable'] ?? false;

        if ($en !== null && $confiable) {
            $this->guardarEnDiccionario($en, $es, ucfirst($es), 'ia');
            return MatchScorer::normalizar($en);
        }

        return $es;
    }

    /**
     * Traduce en UN solo llamado (para no golpear la IA una vez por
     * resultado de búsqueda) una lista de nombres en inglés a su nombre
     * mostrado en español.
     *
     * @param array<int, string> $nombresEn
     * @return array<string, array{mostrado: string, confiable: bool}> indexado por el nombre en inglés original
     */
    public function aEspanolLote(array $nombresEn): array
    {
        $nombresEn = array_values(array_unique(array_filter($nombresEn, fn ($n) => trim($n) !== '')));
        if ($nombresEn === []) {
            return [];
        }

        $resultado = [];
        $faltantes = [];

        $stmt = $this->db->prepare('SELECT es_mostrado FROM traducciones_alimentos WHERE en_normalizado = :en LIMIT 1');
        foreach ($nombresEn as $original) {
            $enNorm = MatchScorer::normalizar($original);
            $stmt->execute(['en' => $enNorm]);
            $fila = $stmt->fetch();
            if ($fila) {
                $resultado[$original] = ['mostrado' => $fila['es_mostrado'], 'confiable' => true];
            } else {
                $faltantes[] = $original;
            }
        }

        if ($faltantes === []) {
            return $resultado;
        }

        $traducciones = $this->pedirTraduccionesAIA($faltantes, 'en_a_es');
        foreach ($faltantes as $original) {
            $es = $traducciones[$original]['es'] ?? null;
            $confiable = $traducciones[$original]['confiable'] ?? false;
            $esValido = $confiable && $es !== null && $this->pareceNombreValido($es);

            if ($esValido) {
                $this->guardarEnDiccionario($original, $es, $es, 'ia');
            }

            $resultado[$original] = [
                'mostrado' => $esValido ? $es : $original,
                'confiable' => $esValido,
            ];
        }

        return $resultado;
    }

    /** Rechaza traducciones vacías, demasiado largas, o que son solo números/símbolos. */
    private function pareceNombreValido(string $texto): bool
    {
        $texto = trim($texto);
        return $texto !== '' && mb_strlen($texto) <= 80 && preg_match('/[a-zA-ZáéíóúñÁÉÍÓÚÑ]/', $texto) === 1;
    }

    private function guardarEnDiccionario(string $en, string $es, string $esMostrado, string $origen): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO traducciones_alimentos (en_normalizado, es_normalizado, es_mostrado, origen)
                 VALUES (:en, :es, :es_mostrado, :origen)
                 ON DUPLICATE KEY UPDATE es_mostrado = VALUES(es_mostrado)'
            );
            $stmt->execute([
                'en' => MatchScorer::normalizar($en),
                'es' => MatchScorer::normalizar($es),
                'es_mostrado' => trim($esMostrado),
                'origen' => $origen,
            ]);
        } catch (Throwable $e) {
            error_log('[TraductorAlimentos] No se pudo cachear traducción: ' . $e->getMessage());
        }
    }

    /**
     * @param array<int, string> $terminos
     * @param 'en_a_es'|'es_a_en' $direccion
     * @return array<string, array{en?: string, es?: string, confiable: bool}> indexado por el término original
     */
    private function pedirTraduccionesAIA(array $terminos, string $direccion): array
    {
        $geminiKey = env('GEMINI_API_KEY');
        $claudeKey = env('ANTHROPIC_API_KEY');

        if (!$geminiKey && !$claudeKey) {
            return [];
        }

        $instruccion = $direccion === 'en_a_es'
            ? 'Para cada nombre de alimento en inglés de la lista, dame el nombre común en español que usaría '
                . 'alguien de Argentina (NO una traducción literal rara). Si no existe un nombre común claro, '
                . 'marcá confiable=false y dejá el campo vacío.'
            : 'Para cada nombre de alimento en español de la lista, dame el nombre en inglés más simple y común '
                . 'para buscarlo en una base de datos nutricional (USDA). Si no se puede traducir con confianza, '
                . 'marcá confiable=false y dejá el campo vacío.';

        $campoSalida = $direccion === 'en_a_es' ? 'es' : 'en';
        $prompt = $instruccion . "\n\nLista: " . implode(' | ', $terminos)
            . "\n\nRespondé con un array de objetos, uno por cada término de la lista, en el mismo orden, "
            . "con las claves \"original\", \"$campoSalida\" y \"confiable\".";

        $schema = [
            'type' => 'object',
            'properties' => [
                'traducciones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'original' => ['type' => 'string'],
                            $campoSalida => ['type' => 'string'],
                            'confiable' => ['type' => 'boolean'],
                        ],
                        'required' => ['original', $campoSalida, 'confiable'],
                    ],
                ],
            ],
            'required' => ['traducciones'],
        ];

        $items = null;
        if ($geminiKey) {
            $items = $this->llamarGemini($geminiKey, $prompt, $schema);
        }
        if ($items === null && $claudeKey) {
            $items = $this->llamarClaude($claudeKey, $prompt, $schema);
        }
        if ($items === null) {
            return [];
        }

        $resultado = [];
        foreach ($items as $item) {
            $original = (string) ($item['original'] ?? '');
            if ($original === '') {
                continue;
            }
            $resultado[$original] = [
                $campoSalida => trim((string) ($item[$campoSalida] ?? '')) ?: null,
                'confiable' => (bool) ($item['confiable'] ?? false),
            ];
        }
        return $resultado;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function llamarGemini(string $apiKey, string $prompt, array $schema): ?array
    {
        $payload = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
        ];

        $respuesta = $this->httpPostJson(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent',
            $payload,
            ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]
        );

        $textoJson = $respuesta['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if ($textoJson === null) {
            return null;
        }
        $decodificado = json_decode($textoJson, true);
        return is_array($decodificado['traducciones'] ?? null) ? $decodificado['traducciones'] : null;
    }

    /** @return array<int, array<string, mixed>>|null */
    private function llamarClaude(string $apiKey, string $prompt, array $schema): ?array
    {
        $payload = [
            'model' => 'claude-haiku-4-5',
            'max_tokens' => 1024,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];

        $respuesta = $this->httpPostJson(
            'https://api.anthropic.com/v1/messages',
            $payload,
            ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01']
        );

        $textoJson = null;
        foreach ($respuesta['content'] ?? [] as $bloque) {
            if (($bloque['type'] ?? null) === 'text') {
                $textoJson = $bloque['text'];
                break;
            }
        }
        if ($textoJson === null) {
            return null;
        }
        $decodificado = json_decode($textoJson, true);
        return is_array($decodificado['traducciones'] ?? null) ? $decodificado['traducciones'] : null;
    }

    /** @param array<int, string> $headers @return array<string, mixed> */
    private function httpPostJson(string $url, array $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_TIMEOUT => self::TIMEOUT_SEGUNDOS,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error !== 0 || $body === false || $httpCode !== 200) {
            return [];
        }

        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }
}
