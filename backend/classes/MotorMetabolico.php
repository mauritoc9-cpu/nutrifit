<?php
declare(strict_types=1);

/**
 * Clase MotorMetabolico
 * Calcula TMB (Mifflin-St Jeor), GET y el reparto de macronutrientes
 * según el objetivo del usuario definido en el onboarding.
 */
class MotorMetabolico
{
    private const FACTOR_ACTIVIDAD = [
        'sedentario' => 1.2,
        'ligero'     => 1.375,
        'moderado'   => 1.55,
        'muy_activo' => 1.725,
    ];

    // Ajuste calórico según el objetivo principal del usuario.
    private const AJUSTE_OBJETIVO = [
        'perder_grasa'      => -0.20, // déficit 20%
        'ganar_musculo'     => 0.12,  // superávit 12%
        'ganar_peso'        => 0.15,  // superávit 15%
        'mejorar_habitos'   => 0.0,   // mantenimiento
        'mantener_peso'     => 0.0,   // mantenimiento
        'monitorear_salud'  => 0.0,   // sin ajuste agresivo por defecto (condición médica)
    ];

    /**
     * Fórmula de Mifflin-St Jeor:
     * Hombres:  TMB = 10*peso + 6.25*altura - 5*edad + 5
     * Mujeres:  TMB = 10*peso + 6.25*altura - 5*edad - 161
     */
    public function calcularTMB(float $pesoKg, float $alturaCm, int $edad, string $genero): float
    {
        $base = (10 * $pesoKg) + (6.25 * $alturaCm) - (5 * $edad);
        $tmb = $genero === 'masculino' ? $base + 5 : $base - 161;
        return round($tmb, 2);
    }

    public function calcularGET(float $tmb, string $nivelActividad): float
    {
        $factor = self::FACTOR_ACTIVIDAD[$nivelActividad] ?? 1.2;
        return round($tmb * $factor, 2);
    }

    public function calcularMetaCalorica(float $get, string $objetivo): int
    {
        $ajuste = self::AJUSTE_OBJETIVO[$objetivo] ?? 0.0;
        return (int) round($get * (1 + $ajuste));
    }

    /**
     * Reparto de macros en gramos según el objetivo.
     * Proteína prioriza retención/ganancia muscular; grasas en 25-30%;
     * el resto se asigna a carbohidratos.
     */
    public function calcularMacros(int $metaCalorias, float $pesoKg, string $objetivo): array
    {
        $gramosProteina = match ($objetivo) {
            'ganar_musculo'  => $pesoKg * 2.2,
            'perder_grasa'   => $pesoKg * 2.0,
            default          => $pesoKg * 1.6,
        };

        $caloriasProteina = $gramosProteina * 4;
        $caloriasGrasas   = $metaCalorias * 0.28;
        $gramosGrasas     = $caloriasGrasas / 9;

        $caloriasRestantes = max(0, $metaCalorias - $caloriasProteina - $caloriasGrasas);
        $gramosCarbohidratos = $caloriasRestantes / 4;

        return [
            'proteinas'     => (int) round($gramosProteina),
            'grasas'        => (int) round($gramosGrasas),
            'carbohidratos' => (int) round($gramosCarbohidratos),
        ];
    }

    /** Recomendación estándar: ~14g de fibra por cada 1000 kcal (guía IOM/USDA). */
    public function calcularFibra(int $metaCalorias): int
    {
        return (int) round(($metaCalorias / 1000) * 14);
    }

    /** Ejecuta el cálculo completo y devuelve todos los resultados listos para guardar. */
    public function calcularPerfilCompleto(
        float $pesoKg,
        float $alturaCm,
        int $edad,
        string $genero,
        string $nivelActividad,
        string $objetivo
    ): array {
        $tmb = $this->calcularTMB($pesoKg, $alturaCm, $edad, $genero);
        $get = $this->calcularGET($tmb, $nivelActividad);
        $metaCalorias = $this->calcularMetaCalorica($get, $objetivo);
        $macros = $this->calcularMacros($metaCalorias, $pesoKg, $objetivo);

        return [
            'tmb'                => $tmb,
            'get_total'          => $get,
            'meta_calorias'      => $metaCalorias,
            'meta_proteinas'     => $macros['proteinas'],
            'meta_carbohidratos' => $macros['carbohidratos'],
            'meta_grasas'        => $macros['grasas'],
            'meta_fibra'         => $this->calcularFibra($metaCalorias),
        ];
    }
}
