<?php
declare(strict_types=1);

/**
 * NutriFit — Qué significa cada objetivo, como DATOS y no como if/else.
 * =====================================================================
 * Los 6 objetivos son los que ya existen en el ENUM de
 * `perfiles_biometricos.objetivo`. No se inventan identificadores
 * nuevos: se los agrupa en "familias" normalizadas para que los módulos
 * razonen sobre pocas categorías estables.
 *
 *   perder_grasa      → perder_peso
 *   ganar_musculo     → ganar_masa
 *   ganar_peso        → ganar_masa   (mismo sentido calórico, distinto énfasis)
 *   mantener_peso     → mantener
 *   mejorar_habitos   → mejorar_habitos
 *   monitorear_salud  → monitorear_salud  (el más conservador de todos)
 *
 * Cada objetivo define sus PRIORIDADES en orden. Ese orden es lo que
 * después hace que dos usuarios distintos reciban herramientas distintas.
 * Agregar un objetivo nuevo = agregar una entrada en esta tabla, sin
 * tocar los módulos que la consumen.
 */
final class PerfilObjetivo
{
    public const FAMILIA_PERDER = 'perder_peso';
    public const FAMILIA_GANAR = 'ganar_masa';
    public const FAMILIA_MANTENER = 'mantener';
    public const FAMILIA_HABITOS = 'mejorar_habitos';
    public const FAMILIA_SALUD = 'monitorear_salud';

    /**
     * Tabla maestra. `prioridades` va en orden de importancia; cada una
     * lleva su `motivo` para que la decisión sea explicable (A5).
     */
    private const MAPA = [
        'perder_grasa' => [
            'familia' => self::FAMILIA_PERDER,
            'etiqueta' => 'Perder grasa',
            'enfoque_nutricion' => 'deficit_con_proteina_alta',
            'enfoque_entrenamiento' => 'fuerza_y_gasto',
            'frecuencia_semanal' => 4,
            'prioridades' => [
                ['codigo' => 'adherencia_nutricional', 'motivo' => 'objetivo_perder_peso'],
                ['codigo' => 'actividad_diaria', 'motivo' => 'gasto_calorico_sostenido'],
                ['codigo' => 'entrenamiento_fuerza', 'motivo' => 'preservar_masa_magra'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
            ],
            'herramientas' => ['registro_comidas', 'recomendaciones_alimentos', 'actividad_diaria', 'rutina_hoy'],
        ],
        'ganar_musculo' => [
            'familia' => self::FAMILIA_GANAR,
            'etiqueta' => 'Ganar masa muscular',
            'enfoque_nutricion' => 'superavit_con_proteina_alta',
            'enfoque_entrenamiento' => 'fuerza_hipertrofia',
            'frecuencia_semanal' => 4,
            'prioridades' => [
                ['codigo' => 'entrenamiento_fuerza', 'motivo' => 'objetivo_ganar_masa'],
                ['codigo' => 'proteina_diaria', 'motivo' => 'sintesis_muscular'],
                ['codigo' => 'superavit_calorico', 'motivo' => 'objetivo_ganar_masa'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
            ],
            'herramientas' => ['rutina_hoy', 'registro_comidas', 'recomendaciones_alimentos', 'actividad_diaria'],
        ],
        'ganar_peso' => [
            'familia' => self::FAMILIA_GANAR,
            'etiqueta' => 'Ganar peso',
            'enfoque_nutricion' => 'superavit_progresivo',
            'enfoque_entrenamiento' => 'fuerza_basica',
            'frecuencia_semanal' => 3,
            'prioridades' => [
                ['codigo' => 'superavit_calorico', 'motivo' => 'objetivo_ganar_peso'],
                ['codigo' => 'constancia_comidas', 'motivo' => 'sostener_superavit'],
                ['codigo' => 'entrenamiento_fuerza', 'motivo' => 'orientar_ganancia_a_musculo'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
            ],
            'herramientas' => ['registro_comidas', 'recomendaciones_alimentos', 'rutina_hoy', 'actividad_diaria'],
        ],
        'mantener_peso' => [
            'familia' => self::FAMILIA_MANTENER,
            'etiqueta' => 'Mantener peso',
            'enfoque_nutricion' => 'equilibrio',
            'enfoque_entrenamiento' => 'mixto_equilibrado',
            'frecuencia_semanal' => 3,
            'prioridades' => [
                ['codigo' => 'equilibrio_nutricional', 'motivo' => 'objetivo_mantener'],
                ['codigo' => 'actividad_diaria', 'motivo' => 'sostener_gasto'],
                ['codigo' => 'constancia', 'motivo' => 'objetivo_mantener'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
            ],
            'herramientas' => ['actividad_diaria', 'registro_comidas', 'rutina_hoy', 'recomendaciones_alimentos'],
        ],
        'mejorar_habitos' => [
            'familia' => self::FAMILIA_HABITOS,
            'etiqueta' => 'Mejorar hábitos',
            'enfoque_nutricion' => 'habitos_sostenibles',
            'enfoque_entrenamiento' => 'accesible_progresivo',
            'frecuencia_semanal' => 3,
            'prioridades' => [
                ['codigo' => 'movimiento_diario', 'motivo' => 'objetivo_mejorar_habitos'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
                ['codigo' => 'registro_alimentacion', 'motivo' => 'tomar_conciencia'],
                ['codigo' => 'constancia', 'motivo' => 'objetivo_mejorar_habitos'],
            ],
            'herramientas' => ['actividad_diaria', 'registro_comidas', 'rutina_hoy', 'recomendaciones_alimentos'],
        ],
        'monitorear_salud' => [
            'familia' => self::FAMILIA_SALUD,
            'etiqueta' => 'Monitorear salud',
            'enfoque_nutricion' => 'conservador',
            'enfoque_entrenamiento' => 'suave_movilidad',
            'frecuencia_semanal' => 2,
            'prioridades' => [
                ['codigo' => 'registro_constante', 'motivo' => 'objetivo_monitorear_salud'],
                ['codigo' => 'hidratacion', 'motivo' => 'habito_base'],
                ['codigo' => 'movimiento_suave', 'motivo' => 'enfoque_conservador'],
                ['codigo' => 'seguimiento_profesional', 'motivo' => 'fuera_del_alcance_de_la_app'],
            ],
            'herramientas' => ['registro_comidas', 'actividad_diaria', 'rutina_hoy', 'recomendaciones_alimentos'],
        ],
    ];

    /** Objetivo de respaldo cuando llega uno desconocido (nunca rompe). */
    private const FALLBACK = 'mejorar_habitos';

    public static function existe(string $objetivo): bool
    {
        return isset(self::MAPA[$objetivo]);
    }

    /** @return array<string, mixed> */
    public static function definicion(string $objetivo): array
    {
        return self::MAPA[$objetivo] ?? self::MAPA[self::FALLBACK];
    }

    public static function familia(string $objetivo): string
    {
        return self::definicion($objetivo)['familia'];
    }

    public static function etiqueta(string $objetivo): string
    {
        return self::definicion($objetivo)['etiqueta'];
    }

    /** @return array<int, array{codigo: string, orden: int, motivo: string}> */
    public static function prioridades(string $objetivo): array
    {
        $salida = [];
        $orden = 1;
        foreach (self::definicion($objetivo)['prioridades'] as $p) {
            $salida[] = ['codigo' => $p['codigo'], 'orden' => $orden, 'motivo' => $p['motivo']];
            $orden++;
        }

        return $salida;
    }

    /** @return string[] */
    public static function herramientas(string $objetivo): array
    {
        return self::definicion($objetivo)['herramientas'];
    }
}
