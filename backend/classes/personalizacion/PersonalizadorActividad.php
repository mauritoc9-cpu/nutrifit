<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PerfilObjetivo.php';

/**
 * NutriFit — Enfoque de actividad física (movimiento diario, no la rutina).
 * =====================================================================
 * Decide qué esperar del día a día del usuario: cuántos pasos tiene
 * sentido proponerle y con qué intención (gastar, sostener, empezar).
 *
 * Usa la meta de pasos REAL del perfil como base — no la pisa. Lo que
 * agrega es una lectura honesta del historial: si alguien declaró
 * "muy activo" pero hace una semana que no registra nada, el plan lo
 * dice en `estado_reciente` en vez de asumir que cumple.
 */
final class PersonalizadorActividad
{
    /** Enfoque por familia de objetivo. */
    private const ENFOQUE = [
        PerfilObjetivo::FAMILIA_PERDER => 'gasto_calorico_diario',
        PerfilObjetivo::FAMILIA_GANAR => 'actividad_moderada_sin_interferir',
        PerfilObjetivo::FAMILIA_MANTENER => 'sostener_nivel_actual',
        PerfilObjetivo::FAMILIA_HABITOS => 'construir_movimiento_diario',
        PerfilObjetivo::FAMILIA_SALUD => 'movimiento_suave_constante',
    ];

    /** @return array<string, mixed> */
    public function calcular(ContextoUsuario $ctx): array
    {
        $familia = PerfilObjetivo::familia($ctx->objetivo);
        $motivos = [];

        $motivos[] = [
            'codigo' => 'enfoque_actividad',
            'motivo' => 'derivado_de_objetivo',
            'datos' => ['objetivo' => $ctx->objetivo, 'familia' => $familia],
        ];

        // Lectura del historial: sólo si hay datos. Nunca se inventa.
        $estadoReciente = null;
        if ($ctx->reciente !== null) {
            $promedio = $ctx->reciente->pasosPromedioDiario;
            $cumple = $promedio >= $ctx->metaPasosDiaria;

            $estadoReciente = [
                'dias_ventana' => $ctx->reciente->diasVentana,
                'pasos_promedio_diario' => $promedio,
                'pasos_totales' => $ctx->reciente->pasosTotales,
                'sesiones_actividad' => $ctx->reciente->sesionesActividad,
                'dias_activos' => $ctx->reciente->diasActivos,
                'cumple_meta_pasos' => $cumple,
                'tiene_datos' => $ctx->reciente->tieneDatosSuficientes(),
            ];

            if (!$ctx->reciente->tieneDatosSuficientes()) {
                $motivos[] = [
                    'codigo' => 'sin_actividad_registrada',
                    'motivo' => 'ventana_sin_registros',
                    'datos' => ['dias_ventana' => $ctx->reciente->diasVentana],
                ];
            } elseif (!$cumple) {
                $motivos[] = [
                    'codigo' => 'pasos_por_debajo_de_meta',
                    'motivo' => 'promedio_reciente_menor_a_meta',
                    'datos' => [
                        'promedio' => $promedio,
                        'meta' => $ctx->metaPasosDiaria,
                    ],
                ];
            }
        }

        // Coherencia declarada vs registrada — dato útil, sin juzgar.
        $coherencia = null;
        if ($ctx->nivelActividad !== null && $estadoReciente !== null && $estadoReciente['tiene_datos']) {
            $declaradoAlto = in_array($ctx->nivelActividad, ['moderado', 'muy_activo'], true);
            $coherencia = ($declaradoAlto && !$estadoReciente['cumple_meta_pasos'])
                ? 'declarado_mayor_que_registrado'
                : 'coherente';
        }

        return [
            'meta_pasos_diaria' => $ctx->metaPasosDiaria,
            'nivel_actividad_declarado' => $ctx->nivelActividad,
            'enfoque' => self::ENFOQUE[$familia] ?? 'construir_movimiento_diario',
            'estado_reciente' => $estadoReciente,
            'coherencia_declarado_vs_registrado' => $coherencia,
            'motivos' => $motivos,
        ];
    }
}
