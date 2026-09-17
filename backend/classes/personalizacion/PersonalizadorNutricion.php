<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PerfilObjetivo.php';
require_once __DIR__ . '/CatalogoRestricciones.php';

/**
 * NutriFit — Enfoque nutricional del usuario.
 * =====================================================================
 * NO recalcula las metas: las metas siguen saliendo de MotorMetabolico
 * (onboarding) y son la fuente de verdad. Lo que hace este módulo es
 * decir CÓMO usarlas: qué priorizar, qué mirar primero y por qué.
 *
 * Además expone la lectura del historial reciente (adherencia), que es
 * lo que permite después dar recomendaciones con contexto en vez de
 * repetir siempre lo mismo.
 */
final class PersonalizadorNutricion
{
    private const PRIORIDAD_MACRO = [
        PerfilObjetivo::FAMILIA_PERDER => 'proteinas',
        PerfilObjetivo::FAMILIA_GANAR => 'proteinas',
        PerfilObjetivo::FAMILIA_MANTENER => 'equilibrio',
        PerfilObjetivo::FAMILIA_HABITOS => 'equilibrio',
        PerfilObjetivo::FAMILIA_SALUD => 'equilibrio',
    ];

    /** @return array<string, mixed> */
    public function calcular(ContextoUsuario $ctx): array
    {
        $definicion = PerfilObjetivo::definicion($ctx->objetivo);
        $familia = $definicion['familia'];
        $motivos = [];

        $motivos[] = [
            'codigo' => 'enfoque_nutricion',
            'motivo' => 'derivado_de_objetivo',
            'datos' => ['objetivo' => $ctx->objetivo, 'enfoque' => $definicion['enfoque_nutricion']],
        ];

        // Proteína por kg: sólo si conocemos el peso real.
        $proteinaPorKg = null;
        if ($ctx->pesoActualKg !== null && $ctx->pesoActualKg > 0) {
            $proteinaPorKg = round($ctx->metaProteinas / $ctx->pesoActualKg, 2);
            $motivos[] = [
                'codigo' => 'proteina_por_kg',
                'motivo' => 'meta_vigente_sobre_peso_actual',
                'datos' => ['g_por_kg' => $proteinaPorKg],
            ];
        }

        // Adherencia reciente: cuántos días registró comidas.
        $adherencia = null;
        if ($ctx->reciente !== null) {
            $dias = max(1, $ctx->reciente->diasVentana);
            $registrados = $ctx->reciente->diasConRegistroComidas;
            $adherencia = [
                'dias_ventana' => $ctx->reciente->diasVentana,
                'dias_con_registro' => $registrados,
                'porcentaje' => (int) round(($registrados / $dias) * 100),
                'calorias_promedio' => $ctx->reciente->caloriasPromedio,
                'proteinas_promedio' => $ctx->reciente->proteinasPromedio,
            ];

            if ($registrados === 0) {
                $motivos[] = [
                    'codigo' => 'sin_registro_alimentario',
                    'motivo' => 'ventana_sin_comidas_registradas',
                    'datos' => ['dias_ventana' => $ctx->reciente->diasVentana],
                ];
            } elseif ($ctx->reciente->proteinasPromedio !== null
                && $ctx->reciente->proteinasPromedio < $ctx->metaProteinas * 0.8) {
                $motivos[] = [
                    'codigo' => 'proteina_promedio_baja',
                    'motivo' => 'promedio_reciente_debajo_del_80_por_ciento',
                    'datos' => [
                        'promedio' => $ctx->reciente->proteinasPromedio,
                        'meta' => $ctx->metaProteinas,
                    ],
                ];
            }
        }

        // Condiciones → tono conservador (nunca un diagnóstico ni una dieta médica).
        if ($ctx->condiciones !== []) {
            $motivos[] = [
                'codigo' => 'nutricion_conservadora',
                'motivo' => 'condiciones_declaradas_en_perfil',
                'datos' => ['condiciones' => $ctx->condiciones],
            ];
        }

        return [
            'metas' => [
                'calorias' => $ctx->metaCalorias,
                'proteinas' => $ctx->metaProteinas,
                'carbohidratos' => $ctx->metaCarbohidratos,
                'grasas' => $ctx->metaGrasas,
                'fibra' => $ctx->metaFibra,
            ],
            'enfoque' => $definicion['enfoque_nutricion'],
            'macro_prioritario' => self::PRIORIDAD_MACRO[$familia] ?? 'equilibrio',
            'proteina_g_por_kg' => $proteinaPorKg,
            'tipo_dieta' => $ctx->tipoDieta,
            'adherencia_reciente' => $adherencia,
            'motivos' => $motivos,
        ];
    }
}
