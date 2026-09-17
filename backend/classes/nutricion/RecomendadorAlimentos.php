<?php
declare(strict_types=1);

require_once __DIR__ . '/../FiltroAlimentario.php';
require_once __DIR__ . '/../personalizacion/ContextoUsuario.php';
require_once __DIR__ . '/../personalizacion/BalanceNutricionalDiario.php';
require_once __DIR__ . '/../personalizacion/PerfilObjetivo.php';

/**
 * NutriFit — Qué conviene comer AHORA, con datos nutricionales reales.
 * =====================================================================
 * NO crea una base nutricional paralela: lee la tabla `alimentos`, que
 * es la misma que alimenta el buscador y la cámara (local curado +
 * cache de Codulia/USDA/Open Food Facts). Los macros que devuelve salen
 * de ahí, nunca de una estimación inventada.
 *
 * Dos listas, como se pidió:
 *   TOP     → catálogo curado, útil en general, sólo filtrado por dieta
 *             y restricciones. No depende del momento del día.
 *   PARA MÍ → ordenado según lo que al usuario le FALTA hoy (balance
 *             real) y su objetivo.
 *
 * Filtros duros: todo pasa por FiltroAlimentario antes de puntuar. Un
 * alimento incompatible con la dieta o con una restricción declarada no
 * entra a la lista bajo ninguna circunstancia.
 *
 * Sólo se usa el catálogo `fuente='local'` (curado). El cache externo
 * tiene productos de marca sueltos que llegaron por búsquedas puntuales
 * y no sirven como recomendación general.
 */
final class RecomendadorAlimentos
{
    private const PORCION_DEFECTO_G = 100.0;
    private const MAX_RESULTADOS = 6;

    /** Momento del día → tipo de comida sugerido (horario local del server). */
    private const FRANJAS = [
        ['hasta' => 11, 'comida' => 'desayuno'],
        ['hasta' => 15, 'comida' => 'almuerzo'],
        ['hasta' => 19, 'comida' => 'merienda'],
        ['hasta' => 24, 'comida' => 'cena'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, mixed>
     */
    public function recomendar(ContextoUsuario $ctx, BalanceNutricionalDiario $balance, ?int $hora = null): array
    {
        $hora = $hora ?? (int) date('G');
        $momento = $this->momentoDelDia($hora);

        $compatibles = $this->catalogoCompatible($ctx);

        $necesidad = $this->necesidadPrincipal($ctx, $balance);

        $paraMi = $this->rankearParaMi($compatibles, $balance, $necesidad);
        $top = $this->rankearTop($compatibles);

        return [
            'momento' => [
                'hora' => $hora,
                'comida_sugerida' => $momento,
            ],
            'necesidad_principal' => $necesidad,
            'restantes' => $balance->comoArrayRestante(),
            'compatibilidad' => [
                'tipo_dieta' => $ctx->tipoDieta,
                'restricciones_alimentarias' => $ctx->restriccionesAlimentarias,
                'candidatos_compatibles' => count($compatibles),
            ],
            'para_mi' => $paraMi,
            'top' => $top,
        ];
    }

    /**
     * Catálogo curado que pasa los filtros duros del usuario.
     *
     * @return array<int, array<string, mixed>>
     */
    private function catalogoCompatible(ContextoUsuario $ctx): array
    {
        $alimentos = $this->db->query(
            "SELECT id, nombre, nombre_mostrado, categoria, calorias_por_100g, proteinas,
                    carbohidratos, grasas, porcion_sugerida_gramos, porcion_sugerida_label, fuente
             FROM alimentos WHERE fuente = 'local'"
        )->fetchAll();

        $compatibles = array_filter($alimentos, function ($a) use ($ctx) {
            return FiltroAlimentario::esCompatible(
                (string) ($a['nombre_mostrado'] ?: $a['nombre']),
                $a['categoria'],
                $ctx->tipoDieta,
                // Sólo las restricciones ALIMENTARIAS actúan como filtro.
                // Las condiciones (diabetes/hipertensión) nunca esconden
                // comida por su nombre: eso sería tratarlas como alergias.
                $ctx->restriccionesAlimentarias
            );
        });

        return array_values($compatibles);
    }

    /**
     * Qué macro hace falta priorizar ahora mismo. Combina el déficit real
     * del día con el macro que el objetivo del usuario prioriza.
     *
     * @return array<string, mixed>
     */
    private function necesidadPrincipal(ContextoUsuario $ctx, BalanceNutricionalDiario $balance): array
    {
        if ($balance->excesoCalorico) {
            return [
                'codigo' => 'controlar_calorias',
                'motivo' => 'meta_calorica_superada',
                'macro' => null,
            ];
        }

        if ($balance->macrosCubiertos) {
            return [
                'codigo' => 'mantenimiento',
                'motivo' => 'macros_del_dia_cubiertos',
                'macro' => null,
            ];
        }

        // Déficit relativo por macro (cuánto falta sobre la meta).
        $relativos = [
            'proteinas' => $ctx->metaProteinas > 0 ? $balance->restanteProteinas / $ctx->metaProteinas : 0.0,
            'carbohidratos' => $ctx->metaCarbohidratos > 0 ? $balance->restanteCarbohidratos / $ctx->metaCarbohidratos : 0.0,
            'grasas' => $ctx->metaGrasas > 0 ? $balance->restanteGrasas / $ctx->metaGrasas : 0.0,
        ];

        // El objetivo desempata: perder/ganar priorizan proteína cuando
        // el déficit de proteína es comparable al de otro macro.
        $familia = PerfilObjetivo::familia($ctx->objetivo);
        if (in_array($familia, [PerfilObjetivo::FAMILIA_PERDER, PerfilObjetivo::FAMILIA_GANAR], true)) {
            $relativos['proteinas'] *= 1.25;
        }

        arsort($relativos);
        $macro = (string) array_key_first($relativos);

        return [
            'codigo' => 'cubrir_' . $macro,
            'motivo' => 'mayor_deficit_relativo_del_dia',
            'macro' => $macro,
            'restante' => $balance->comoArrayRestante()[$macro] ?? null,
        ];
    }

    /**
     * "Para mí": ordena por cuánto aporta cada alimento al macro que hace
     * falta, penalizando lo que no entra en las calorías que quedan.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankearParaMi(array $compatibles, BalanceNutricionalDiario $balance, array $necesidad): array
    {
        $macro = $necesidad['macro'];
        $restanteCal = $balance->restanteCalorias;

        $puntuados = [];
        foreach ($compatibles as $a) {
            $porcion = $this->porcion($a);
            $n = $this->nutricionPorcion($a, $porcion);

            if ($macro !== null) {
                // Densidad del macro por caloría: premia el alimento que
                // aporta lo que falta sin gastar todo el margen calórico.
                $densidad = $n['calorias'] > 0 ? $n[$macro] / $n['calorias'] : 0.0;
                $score = $densidad * 100;
            } else {
                // Sin macro pendiente: se prioriza lo liviano.
                $score = $n['calorias'] > 0 ? 100 / $n['calorias'] : 0.0;
            }

            // No recomendar algo que se pasa del margen calórico del día.
            if ($restanteCal > 0 && $n['calorias'] > $restanteCal) {
                $score *= 0.25;
            }

            $puntuados[] = [
                'alimento' => $this->salidaAlimento($a),
                'porcion' => $porcion,
                'nutricion' => $n,
                'score' => round($score, 4),
                'motivo' => $this->motivoPara($macro, $n, $necesidad),
            ];
        }

        usort($puntuados, static fn ($x, $y) => $y['score'] <=> $x['score']);

        return array_slice($puntuados, 0, self::MAX_RESULTADOS);
    }

    /**
     * "Top": catálogo curado compatible, ordenado por densidad proteica
     * y luego alfabéticamente. Es estable y no depende del día.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rankearTop(array $compatibles): array
    {
        $items = [];
        foreach ($compatibles as $a) {
            $porcion = $this->porcion($a);
            $n = $this->nutricionPorcion($a, $porcion);
            $items[] = [
                'alimento' => $this->salidaAlimento($a),
                'porcion' => $porcion,
                'nutricion' => $n,
                'motivo' => [
                    'codigo' => 'catalogo_curado',
                    'texto' => 'Opción del catálogo curado compatible con tu dieta y tus restricciones.',
                ],
            ];
        }

        usort($items, static function ($x, $y) {
            $densidadX = $x['nutricion']['calorias'] > 0 ? $x['nutricion']['proteinas'] / $x['nutricion']['calorias'] : 0;
            $densidadY = $y['nutricion']['calorias'] > 0 ? $y['nutricion']['proteinas'] / $y['nutricion']['calorias'] : 0;
            if ($densidadX === $densidadY) {
                return strcmp($x['alimento']['nombre'], $y['alimento']['nombre']);
            }
            return $densidadY <=> $densidadX;
        });

        return array_slice($items, 0, self::MAX_RESULTADOS);
    }

    /** @return array<string, mixed> */
    private function motivoPara(?string $macro, array $n, array $necesidad): array
    {
        if ($macro === null) {
            return [
                'codigo' => $necesidad['codigo'],
                'texto' => $necesidad['codigo'] === 'controlar_calorias'
                    ? 'Opción liviana: ya superaste tu meta calórica de hoy.'
                    : 'Ya cubriste tus macros: esta es una opción liviana para cerrar el día.',
            ];
        }

        $etiquetas = [
            'proteinas' => 'proteína',
            'carbohidratos' => 'carbohidratos',
            'grasas' => 'grasas',
        ];
        $etiqueta = $etiquetas[$macro] ?? $macro;

        return [
            'codigo' => 'aporta_' . $macro,
            'texto' => sprintf(
                'Aporta %sg de %s en esta porción, que es lo que más te falta hoy.',
                $n[$macro],
                $etiqueta
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function porcion(array $a): array
    {
        $gramos = $a['porcion_sugerida_gramos'] !== null
            ? (float) $a['porcion_sugerida_gramos']
            : self::PORCION_DEFECTO_G;

        return [
            'gramos' => $gramos,
            'etiqueta' => $a['porcion_sugerida_label'] ?: (((int) $gramos) . ' g'),
            'origen' => $a['porcion_sugerida_gramos'] !== null ? 'catalogo' : 'porcion_estandar_100g',
        ];
    }

    /** Macros reales del catálogo escalados a la porción. */
    private function nutricionPorcion(array $a, array $porcion): array
    {
        $factor = $porcion['gramos'] / 100;

        return [
            'calorias' => round((float) $a['calorias_por_100g'] * $factor, 1),
            'proteinas' => round((float) $a['proteinas'] * $factor, 1),
            'carbohidratos' => round((float) $a['carbohidratos'] * $factor, 1),
            'grasas' => round((float) $a['grasas'] * $factor, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function salidaAlimento(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'nombre' => (string) ($a['nombre_mostrado'] ?: $a['nombre']),
            'categoria' => $a['categoria'],
            'fuente' => $a['fuente'],
        ];
    }

    private function momentoDelDia(int $hora): string
    {
        foreach (self::FRANJAS as $franja) {
            if ($hora < $franja['hasta']) {
                return $franja['comida'];
            }
        }

        return 'cena';
    }
}
