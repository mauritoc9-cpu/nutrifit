<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PerfilObjetivo.php';

/**
 * NutriFit — Qué tipo de entrenamiento le corresponde a este usuario.
 * =====================================================================
 * Define el "para qué" del entrenamiento (enfoque, frecuencia, nivel y
 * lugar). NO arma la rutina: eso lo hace GeneradorRutinasPersonalizadas
 * consumiendo justamente esta decisión.
 *
 * El nivel sugerido se deriva del nivel de actividad declarado y se
 * CORRIGE con el historial real de entrenamientos completados: alguien
 * que dice ser muy activo pero no entrenó ninguna vez en la ventana no
 * arranca en avanzado.
 *
 * `nivel_sugerido` usa exactamente los valores del ENUM que ya existe en
 * `rutinas_ejercicios.nivel_dificultad` (principiante/intermedio/avanzado),
 * para que el generador pueda filtrar el catálogo sin traducir nada.
 */
final class PersonalizadorEntrenamiento
{
    private const ENFOQUE = [
        PerfilObjetivo::FAMILIA_PERDER => 'fuerza_y_gasto',
        PerfilObjetivo::FAMILIA_GANAR => 'fuerza_hipertrofia',
        PerfilObjetivo::FAMILIA_MANTENER => 'mixto_equilibrado',
        PerfilObjetivo::FAMILIA_HABITOS => 'accesible_progresivo',
        PerfilObjetivo::FAMILIA_SALUD => 'suave_movilidad',
    ];

    private const NIVEL_BASE = [
        'sedentario' => 'principiante',
        'ligero' => 'principiante',
        'moderado' => 'intermedio',
        'muy_activo' => 'avanzado',
    ];

    /** @return array<string, mixed> */
    public function calcular(ContextoUsuario $ctx): array
    {
        $definicion = PerfilObjetivo::definicion($ctx->objetivo);
        $familia = $definicion['familia'];
        $motivos = [];

        // --- Nivel sugerido ---
        $nivel = self::NIVEL_BASE[$ctx->nivelActividad ?? ''] ?? 'principiante';
        $motivos[] = [
            'codigo' => 'nivel_base',
            'motivo' => $ctx->nivelActividad !== null ? 'nivel_actividad_declarado' : 'sin_nivel_declarado',
            'datos' => ['nivel_actividad' => $ctx->nivelActividad, 'nivel' => $nivel],
        ];

        if ($ctx->reciente !== null) {
            $entrenos = $ctx->reciente->entrenamientosCompletados;
            // Sin evidencia de entrenamiento no se sostiene "avanzado".
            if ($nivel === 'avanzado' && $entrenos < 2) {
                $nivel = 'intermedio';
                $motivos[] = [
                    'codigo' => 'nivel_ajustado_a_la_baja',
                    'motivo' => 'pocos_entrenamientos_registrados',
                    'datos' => ['entrenamientos_ventana' => $entrenos],
                ];
            } elseif ($nivel === 'principiante' && $entrenos >= 4) {
                $nivel = 'intermedio';
                $motivos[] = [
                    'codigo' => 'nivel_ajustado_al_alza',
                    'motivo' => 'constancia_demostrada',
                    'datos' => ['entrenamientos_ventana' => $entrenos],
                ];
            }
        }

        // --- Lugar ---
        // Sin lugar declarado se asume casa: es la opción que NO requiere
        // equipamiento, así que nunca sugiere algo imposible de hacer.
        $lugar = $ctx->lugarEntreno ?? 'casa';
        if ($ctx->lugarEntreno === null) {
            $motivos[] = [
                'codigo' => 'lugar_por_defecto',
                'motivo' => 'sin_lugar_declarado_se_asume_sin_equipamiento',
                'datos' => ['lugar' => $lugar],
            ];
        }

        $motivos[] = [
            'codigo' => 'enfoque_entrenamiento',
            'motivo' => 'derivado_de_objetivo',
            'datos' => ['objetivo' => $ctx->objetivo, 'familia' => $familia],
        ];

        // Condiciones declaradas → tono conservador, sin diagnosticar.
        $conservador = $ctx->condiciones !== [];
        if ($conservador) {
            $motivos[] = [
                'codigo' => 'intensidad_conservadora',
                'motivo' => 'condiciones_declaradas_en_perfil',
                'datos' => ['condiciones' => $ctx->condiciones],
            ];
        }

        return [
            'lugar' => $lugar,
            'enfoque' => self::ENFOQUE[$familia] ?? 'accesible_progresivo',
            'frecuencia_semanal_sugerida' => (int) $definicion['frecuencia_semanal'],
            'nivel_sugerido' => $nivel,
            'intensidad_conservadora' => $conservador,
            'entrenamientos_recientes' => $ctx->reciente->entrenamientosCompletados ?? null,
            'motivos' => $motivos,
        ];
    }
}
