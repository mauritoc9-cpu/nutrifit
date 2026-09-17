<?php
declare(strict_types=1);

require_once __DIR__ . '/../FiltroAlimentario.php';
require_once __DIR__ . '/../personalizacion/ContextoUsuario.php';
require_once __DIR__ . '/../personalizacion/PerfilObjetivo.php';

/**
 * NutriFit — Recetario personalizado. La IA redacta; el backend decide.
 * =====================================================================
 * REPARTO DE RESPONSABILIDADES (esto es lo importante):
 *
 *   Backend (determinista)      IA (Gemini)
 *   ------------------------    ------------------------------
 *   Qué ingredientes son        Cómo combinarlos
 *   compatibles                 Pasos de preparación
 *   Cuántos gramos valen        Nombre y descripción
 *   Los macros (del catálogo)   Sustituciones sugeridas
 *
 * La IA NUNCA aporta valores nutricionales: recibe una lista CERRADA de
 * ingredientes permitidos y, cuando responde, se valida que no haya
 * inventado ninguno. Los macros se calculan después, desde `alimentos`.
 *
 * Si la IA no está disponible (sin API key, cuota agotada, timeout o
 * error), NO se rompe nada: se arma una receta determinista con los
 * mismos ingredientes validados y se marca `origen = deterministica`.
 */
final class GeneradorRecetasIA
{
    private const API_URL = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.6-flash:generateContent';
    private const TIMEOUT_SEGUNDOS = 25;
    private const MAX_INGREDIENTES_CANDIDATOS = 14;
    private const MIN_INGREDIENTES_VALIDOS = 2;

    private PDO $db;
    private ?string $apiKey;

    public function __construct(PDO $db, ?string $apiKey)
    {
        $this->db = $db;
        $this->apiKey = $apiKey !== null && trim($apiKey) !== '' ? trim($apiKey) : null;
    }

    /**
     * Devuelve una receta lista para mostrar. Siempre responde algo
     * utilizable (IA o fallback determinista).
     *
     * @return array<string, mixed>
     */
    public function generar(ContextoUsuario $ctx, string $momento, bool $forzarRegeneracion = false): array
    {
        $candidatos = $this->ingredientesCompatibles($ctx);

        if (count($candidatos) < self::MIN_INGREDIENTES_VALIDOS) {
            return [
                'disponible' => false,
                'motivo' => 'sin_ingredientes_compatibles',
                'mensaje' => 'No hay suficientes ingredientes compatibles con tu dieta y tus restricciones en el catálogo.',
            ];
        }

        $hash = $this->hashContexto($ctx, $momento, $candidatos);

        if (!$forzarRegeneracion) {
            $cacheada = $this->buscarEnCache($hash);
            if ($cacheada !== null) {
                $cacheada['desde_cache'] = true;
                return $cacheada;
            }
        }

        $receta = null;
        $errorIa = null;

        if ($this->apiKey !== null) {
            try {
                $receta = $this->generarConIa($ctx, $momento, $candidatos);
            } catch (Throwable $e) {
                // No se silencia: se registra y se informa el motivo en la
                // respuesta, además de caer al modo determinista.
                error_log('[GeneradorRecetasIA] ' . $e->getMessage());
                $errorIa = $e->getMessage();
            }
        } else {
            $errorIa = 'GEMINI_API_KEY no configurada';
        }

        if ($receta === null) {
            $receta = $this->recetaDeterministica($ctx, $momento, $candidatos);
            $receta['motivo_fallback'] = $errorIa ?? 'respuesta_de_ia_invalida';
        }

        $this->guardarEnCache($hash, $ctx, $momento, $receta);
        $receta['desde_cache'] = false;

        return $receta;
    }

    // -----------------------------------------------------------------
    // Ingredientes permitidos (filtro duro ANTES de hablar con la IA)
    // -----------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private function ingredientesCompatibles(ContextoUsuario $ctx): array
    {
        $alimentos = $this->db->query(
            "SELECT id, nombre, nombre_mostrado, categoria, calorias_por_100g, proteinas, carbohidratos, grasas
             FROM alimentos WHERE fuente = 'local' ORDER BY categoria, nombre"
        )->fetchAll();

        $compatibles = [];
        foreach ($alimentos as $a) {
            $nombre = (string) ($a['nombre_mostrado'] ?: $a['nombre']);
            if (!FiltroAlimentario::esCompatible($nombre, $a['categoria'], $ctx->tipoDieta, $ctx->restriccionesAlimentarias)) {
                continue;
            }
            $compatibles[] = [
                'id' => (int) $a['id'],
                'nombre' => $nombre,
                'categoria' => (string) $a['categoria'],
                'calorias_por_100g' => (float) $a['calorias_por_100g'],
                'proteinas' => (float) $a['proteinas'],
                'carbohidratos' => (float) $a['carbohidratos'],
                'grasas' => (float) $a['grasas'],
            ];
        }

        // Se prioriza variedad por categoría para que la IA tenga con qué
        // armar algo coherente sin recibir el catálogo entero.
        usort($compatibles, static fn ($x, $y) => [$x['categoria'], $x['nombre']] <=> [$y['categoria'], $y['nombre']]);

        return array_slice($compatibles, 0, self::MAX_INGREDIENTES_CANDIDATOS);
    }

    // -----------------------------------------------------------------
    // Camino IA
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function generarConIa(ContextoUsuario $ctx, string $momento, array $candidatos): ?array
    {
        $respuesta = $this->httpPost($this->construirPayload($ctx, $momento, $candidatos));

        if (isset($respuesta['_error'])) {
            throw new RuntimeException($respuesta['_error']);
        }

        $texto = $respuesta['candidates'][0]['content']['parts'][0]['text'] ?? '';
        $datos = $this->extraerJson($texto);

        if ($datos === null || !isset($datos['ingredientes']) || !is_array($datos['ingredientes'])) {
            return null;
        }

        // --- VALIDACIÓN POSTERIOR: la IA no puede introducir ingredientes ---
        $porNombre = [];
        foreach ($candidatos as $c) {
            $porNombre[$this->normalizar($c['nombre'])] = $c;
        }

        $validados = [];
        $rechazados = [];
        foreach ($datos['ingredientes'] as $item) {
            $nombre = is_array($item) ? (string) ($item['nombre'] ?? '') : '';
            $gramos = is_array($item) ? (float) ($item['gramos'] ?? 0) : 0;
            $clave = $this->normalizar($nombre);

            if ($nombre === '' || $gramos <= 0 || !isset($porNombre[$clave])) {
                $rechazados[] = $nombre !== '' ? $nombre : '(vacío)';
                continue;
            }
            // Tope defensivo: nada de porciones absurdas.
            $gramos = min($gramos, 500.0);
            $validados[] = ['catalogo' => $porNombre[$clave], 'gramos' => $gramos];
        }

        if (count($validados) < self::MIN_INGREDIENTES_VALIDOS) {
            return null;
        }

        $pasos = array_values(array_filter(
            array_map('strval', (array) ($datos['pasos'] ?? [])),
            static fn ($p) => trim($p) !== ''
        ));

        if ($pasos === []) {
            return null;
        }

        return $this->armarReceta(
            $ctx,
            $momento,
            (string) ($datos['nombre'] ?? 'Receta personalizada'),
            (string) ($datos['descripcion'] ?? ''),
            max(1, (int) ($datos['porciones'] ?? 1)),
            max(1, (int) ($datos['tiempo_minutos'] ?? 15)),
            $validados,
            $pasos,
            array_values(array_filter(array_map('strval', (array) ($datos['sustituciones'] ?? [])))),
            'ia',
            $rechazados
        );
    }

    /** @return array<string, mixed> */
    private function construirPayload(ContextoUsuario $ctx, string $momento, array $candidatos): array
    {
        $lista = implode("\n", array_map(
            static fn ($c) => '- ' . $c['nombre'],
            $candidatos
        ));

        $objetivo = PerfilObjetivo::etiqueta($ctx->objetivo);

        $prompt = <<<PROMPT
Sos un asistente de cocina de una app de salud. Armá UNA receta simple para "{$momento}".

Objetivo del usuario: {$objetivo}.
Tipo de dieta: {$ctx->tipoDieta}.

REGLAS ESTRICTAS:
1. Usá ÚNICAMENTE ingredientes de esta lista, con el nombre EXACTO:
{$lista}
2. No agregues ningún ingrediente que no esté en la lista (ni sal, aceite, especias u otros).
3. No inventes ni menciones calorías, proteínas, carbohidratos ni grasas. Esos valores los calcula la aplicación.
4. No des consejos médicos ni menciones enfermedades.

Respondé SOLO con JSON válido, sin texto adicional, con esta forma:
{
  "nombre": "string",
  "descripcion": "string breve",
  "porciones": 1,
  "tiempo_minutos": 15,
  "ingredientes": [{"nombre": "nombre exacto de la lista", "gramos": 120}],
  "pasos": ["paso 1", "paso 2"],
  "sustituciones": ["opcional"]
}
PROMPT;

        return [
            'contents' => [[
                'parts' => [['text' => $prompt]],
            ]],
            'generationConfig' => [
                'temperature' => 0.7,
                'responseMimeType' => 'application/json',
            ],
        ];
    }

    // -----------------------------------------------------------------
    // Fallback determinista (D6)
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function recetaDeterministica(ContextoUsuario $ctx, string $momento, array $candidatos): array
    {
        // Se elige una proteína + un acompañamiento + un vegetal si existen.
        $porCategoria = [];
        foreach ($candidatos as $c) {
            $porCategoria[$c['categoria']][] = $c;
        }

        $elegidos = [];
        foreach (['proteína', 'cereal', 'legumbre', 'vegetal', 'fruta', 'lácteo'] as $categoria) {
            if (isset($porCategoria[$categoria]) && count($elegidos) < 3) {
                $elegidos[] = ['catalogo' => $porCategoria[$categoria][0], 'gramos' => 120.0];
            }
        }
        if (count($elegidos) < self::MIN_INGREDIENTES_VALIDOS) {
            foreach (array_slice($candidatos, 0, 3) as $c) {
                $elegidos[] = ['catalogo' => $c, 'gramos' => 120.0];
            }
        }

        $nombres = array_map(static fn ($e) => mb_strtolower($e['catalogo']['nombre']), $elegidos);

        $pasos = [
            'Preparate los ingredientes: ' . implode(', ', $nombres) . '.',
            'Cociná o calentá lo que lo necesite, sin agregar ingredientes fuera de tu plan.',
            'Serví todo junto y ajustá las cantidades según tu apetito.',
        ];

        return $this->armarReceta(
            $ctx,
            $momento,
            'Plato simple para tu ' . $momento,
            'Combinación armada automáticamente con ingredientes compatibles con tu dieta.',
            1,
            15,
            $elegidos,
            $pasos,
            [],
            'deterministica',
            []
        );
    }

    // -----------------------------------------------------------------
    // Armado común + macros REALES
    // -----------------------------------------------------------------

    /** @return array<string, mixed> */
    private function armarReceta(
        ContextoUsuario $ctx,
        string $momento,
        string $nombre,
        string $descripcion,
        int $porciones,
        int $tiempo,
        array $validados,
        array $pasos,
        array $sustituciones,
        string $origen,
        array $rechazados
    ): array {
        $ingredientes = [];
        $total = ['calorias' => 0.0, 'proteinas' => 0.0, 'carbohidratos' => 0.0, 'grasas' => 0.0];

        foreach ($validados as $v) {
            $c = $v['catalogo'];
            $factor = $v['gramos'] / 100;

            $aporte = [
                'calorias' => round($c['calorias_por_100g'] * $factor, 1),
                'proteinas' => round($c['proteinas'] * $factor, 1),
                'carbohidratos' => round($c['carbohidratos'] * $factor, 1),
                'grasas' => round($c['grasas'] * $factor, 1),
            ];

            foreach ($total as $k => $_) {
                $total[$k] += $aporte[$k];
            }

            $ingredientes[] = [
                'alimento_id' => $c['id'],
                'nombre' => $c['nombre'],
                'categoria' => $c['categoria'],
                'gramos' => $v['gramos'],
                'aporte' => $aporte,
            ];
        }

        $porPorcion = [];
        foreach ($total as $k => $valor) {
            $total[$k] = round($valor, 1);
            $porPorcion[$k] = round($valor / max(1, $porciones), 1);
        }

        return [
            'disponible' => true,
            'origen' => $origen,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'objetivo_relacionado' => [
                'clave' => $ctx->objetivo,
                'etiqueta' => PerfilObjetivo::etiqueta($ctx->objetivo),
            ],
            'momento' => $momento,
            'porciones' => $porciones,
            'tiempo_minutos' => $tiempo,
            'ingredientes' => $ingredientes,
            'pasos' => $pasos,
            'sustituciones' => $sustituciones,
            'nutricion_total' => $total,
            'nutricion_por_porcion' => $porPorcion,
            'nutricion_calculada_por' => 'catalogo_nutrifit',
            'compatibilidad' => [
                'tipo_dieta' => $ctx->tipoDieta,
                'restricciones_alimentarias' => $ctx->restriccionesAlimentarias,
                'validada' => true,
            ],
            'ingredientes_rechazados' => $rechazados,
            'motivo_recomendacion' => 'compatible_con_dieta_y_objetivo',
        ];
    }

    // -----------------------------------------------------------------
    // Cache
    // -----------------------------------------------------------------

    private function hashContexto(ContextoUsuario $ctx, string $momento, array $candidatos): string
    {
        $firma = implode('|', [
            $ctx->objetivo,
            $ctx->tipoDieta,
            implode(',', $ctx->restriccionesAlimentarias),
            $momento,
            implode(',', array_column($candidatos, 'id')),
            'v1',
        ]);

        return substr(sha1($firma), 0, 40);
    }

    /** @return array<string, mixed>|null */
    private function buscarEnCache(string $hash): ?array
    {
        $stmt = $this->db->prepare('SELECT receta FROM recetas_ia WHERE contexto_hash = :h LIMIT 1');
        $stmt->execute(['h' => $hash]);
        $json = $stmt->fetchColumn();

        if (!$json) {
            return null;
        }
        $decodificado = json_decode((string) $json, true);

        return is_array($decodificado) ? $decodificado : null;
    }

    private function guardarEnCache(string $hash, ContextoUsuario $ctx, string $momento, array $receta): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO recetas_ia (usuario_id, contexto_hash, objetivo, tipo_dieta, momento, origen, receta)
             VALUES (:uid, :h, :obj, :dieta, :momento, :origen, :receta)
             ON DUPLICATE KEY UPDATE receta = VALUES(receta), creada_en = NOW()'
        );
        $stmt->execute([
            'uid' => $ctx->usuarioId,
            'h' => $hash,
            'obj' => $ctx->objetivo,
            'dieta' => $ctx->tipoDieta,
            'momento' => $momento,
            'origen' => $receta['origen'] ?? 'deterministica',
            'receta' => json_encode($receta, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // -----------------------------------------------------------------
    // Utilidades
    // -----------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private function extraerJson(string $texto): ?array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }
        // Por si viniera envuelto en ```json ... ```
        if (preg_match('/\{.*\}/s', $texto, $m)) {
            $texto = $m[0];
        }
        $datos = json_decode($texto, true);

        return is_array($datos) ? $datos : null;
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(trim($texto));
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

        $decoded = json_decode((string) $body, true);

        if ($httpCode !== 200) {
            $mensaje = is_array($decoded) ? ($decoded['error']['message'] ?? "HTTP $httpCode") : "HTTP $httpCode";
            return ['_error' => 'Error de la API de Gemini: ' . $mensaje];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
