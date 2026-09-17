<?php
declare(strict_types=1);

/**
 * NutriFit — Fórmulas de estimación para el módulo de Actividad Física.
 * Mismo criterio que ya usaba `pasos.php` (fórmulas estándar de libro, sin
 * wearables): zancada ≈ altura_cm × 0.00415, calorías vía MET (kcal =
 * MET × peso_kg × horas). Centralizado acá para que caminata/carrera/
 * ciclismo — y lo que se sume después (gimnasio, fútbol, pádel) — usen el
 * mismo cálculo en vez de fórmulas sueltas repetidas por endpoint.
 */
class CalculadoraActividad
{
    public static function calcularCalorias(float $metValor, float $pesoKg, int $duracionSegundos): float
    {
        $horas = $duracionSegundos / 3600;
        return round($metValor * $pesoKg * $horas, 1);
    }

    /** Distancia estimada a partir de pasos, cuando no viene reportada directamente (GPS/Health Connect). */
    public static function distanciaDesdePasos(int $pasos, float $alturaCm): float
    {
        $zancadaM = $alturaCm * 0.00415;
        return round($pasos * $zancadaM, 1);
    }

    public static function velocidadMediaKmh(float $distanciaMetros, int $duracionSegundos): ?float
    {
        if ($duracionSegundos <= 0 || $distanciaMetros <= 0) {
            return null;
        }
        return round(($distanciaMetros / 1000) / ($duracionSegundos / 3600), 2);
    }

    /** Ritmo en segundos por kilómetro (para mostrarlo como "5:30 min/km" en el frontend). */
    public static function ritmoSegPorKm(float $distanciaMetros, int $duracionSegundos): ?int
    {
        if ($distanciaMetros <= 0 || $duracionSegundos <= 0) {
            return null;
        }
        return (int) round($duracionSegundos / ($distanciaMetros / 1000));
    }
}
