<?php
declare(strict_types=1);

/**
 * NutriFit — Criterios centralizados para decidir cuándo una sesión de
 * actividad física otorga XP, y protección anti-farming.
 *
 * Todo lo que decide "¿esto vale XP?" vive ACÁ, en un solo lugar fácil de
 * ajustar — no repartido entre el endpoint y el repositorio. Si mañana
 * hace falta afinar los mínimos (o moverlos a una tabla configurable desde
 * un panel de admin), este es el único archivo a tocar.
 */
class CriteriosActividadXP
{
    /**
     * Techo diario de sesiones que pueden otorgar XP, por usuario. Sin
     * esto, alguien podría partir una caminata de 40 minutos en 8 sesiones
     * de 5 minutos para juntar XP 8 veces por lo mismo.
     */
    public const MAX_SESIONES_XP_POR_DIA = 3;

    /**
     * Mínimos por tipo de actividad (clave de `tipos_actividad`) para que
     * una sesión sea válida — alcanza con cumplir UNO de los dos (duración
     * O distancia), porque un registro real de Health Connect/HealthKit
     * puede traer solo uno de los dos datos confiable.
     */
    private const MINIMOS = [
        'caminata' => ['duracion_segundos' => 600, 'distancia_metros' => 500.0],   // 10 min o 500m
        'carrera'  => ['duracion_segundos' => 480, 'distancia_metros' => 1000.0],  // 8 min o 1km
        'ciclismo' => ['duracion_segundos' => 600, 'distancia_metros' => 2000.0],  // 10 min o 2km
    ];

    /** Para tipos futuros (gimnasio, fútbol, pádel) sin mínimo propio todavía definido: solo duración. */
    private const MINIMO_DEFAULT = ['duracion_segundos' => 600, 'distancia_metros' => null];

    public static function esSesionValida(string $claveTipo, ?int $duracionSegundos, ?float $distanciaMetros): bool
    {
        $minimo = self::MINIMOS[$claveTipo] ?? self::MINIMO_DEFAULT;

        $cumpleDuracion = $minimo['duracion_segundos'] !== null
            && $duracionSegundos !== null
            && $duracionSegundos >= $minimo['duracion_segundos'];

        $cumpleDistancia = $minimo['distancia_metros'] !== null
            && $distanciaMetros !== null
            && $distanciaMetros >= $minimo['distancia_metros'];

        return $cumpleDuracion || $cumpleDistancia;
    }
}
