<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PerfilDefaults.php';

/**
 * NutriFit — Objetivo de hidratación del usuario.
 * =====================================================================
 * Regla estándar y conservadora: ~35 ml por kg de peso corporal, con un
 * extra acotado si la persona viene registrando actividad. Se redondea a
 * vasos de 250 ml, que es la unidad que ya usa toda la app.
 *
 * IMPORTANTE — este módulo NO cambia el tracking actual: `hidratacion.php`
 * sigue guardando y leyendo `meta_vasos` como siempre (8 por defecto). Lo
 * que se calcula acá es el objetivo SUGERIDO que expone el plan, con su
 * origen explícito. Cambiar la meta real que se persiste es una decisión
 * de producto para otra etapa.
 *
 * Si no conocemos el peso, no se inventa: se devuelve el default de
 * siempre y se marca `origen = default` para que quede claro.
 */
final class PersonalizadorHidratacion
{
    private const ML_POR_KG = 35;
    private const ML_POR_VASO = 250;
    private const ML_EXTRA_POR_SESION_ACTIVIDAD = 250;
    private const MAX_VASOS_EXTRA_ACTIVIDAD = 3;

    /** @return array<string, mixed> */
    public function calcular(ContextoUsuario $ctx): array
    {
        $motivos = [];

        if ($ctx->pesoActualKg === null || $ctx->pesoActualKg <= 0) {
            return [
                'meta_vasos' => PerfilDefaults::META_VASOS,
                'meta_ml' => PerfilDefaults::META_VASOS * self::ML_POR_VASO,
                'ml_por_vaso' => self::ML_POR_VASO,
                'origen' => 'default',
                'meta_vasos_persistida' => PerfilDefaults::META_VASOS,
                'motivos' => [[
                    'codigo' => 'hidratacion_default',
                    'motivo' => 'sin_peso_registrado',
                    'datos' => [],
                ]],
            ];
        }

        $mlBase = $ctx->pesoActualKg * self::ML_POR_KG;
        $motivos[] = [
            'codigo' => 'hidratacion_por_peso',
            'motivo' => 'formula_ml_por_kg',
            'datos' => ['peso_kg' => $ctx->pesoActualKg, 'ml_por_kg' => self::ML_POR_KG],
        ];

        $vasosExtra = 0;
        $sesiones = $ctx->reciente->sesionesActividad ?? 0;
        if ($sesiones > 0) {
            // Promedio de sesiones por día en la ventana, acotado.
            $dias = max(1, $ctx->reciente->diasVentana);
            $vasosExtra = (int) min(
                self::MAX_VASOS_EXTRA_ACTIVIDAD,
                round(($sesiones / $dias) * (self::ML_EXTRA_POR_SESION_ACTIVIDAD / self::ML_POR_VASO))
            );
            if ($vasosExtra > 0) {
                $motivos[] = [
                    'codigo' => 'hidratacion_extra_actividad',
                    'motivo' => 'actividad_registrada_reciente',
                    'datos' => ['sesiones_ventana' => $sesiones, 'vasos_extra' => $vasosExtra],
                ];
            }
        }

        $vasos = (int) round($mlBase / self::ML_POR_VASO) + $vasosExtra;
        // Rango sano de presentación: nunca menos de 6 ni más de 14 vasos.
        $vasos = max(6, min(14, $vasos));

        return [
            'meta_vasos' => $vasos,
            'meta_ml' => $vasos * self::ML_POR_VASO,
            'ml_por_vaso' => self::ML_POR_VASO,
            'origen' => 'calculado',
            // Lo que hoy persiste registros_hidratacion, para que el plan
            // sea honesto sobre la diferencia entre sugerido y vigente.
            'meta_vasos_persistida' => PerfilDefaults::META_VASOS,
            'motivos' => $motivos,
        ];
    }
}
