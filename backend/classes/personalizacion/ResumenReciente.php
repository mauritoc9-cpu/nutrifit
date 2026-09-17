<?php
declare(strict_types=1);

/**
 * NutriFit — Foto agregada de los últimos N días del usuario.
 * =====================================================================
 * Lo consume el Motor de Personalización para decidir con evidencia real
 * (no sólo con lo declarado en el onboarding): si alguien dijo "muy
 * activo" pero hace 7 días que no registra nada, el plan debería notarlo.
 *
 * NO se calcula acá: se arma a partir de ResumenSemanalService, que ya es
 * el agregador oficial del proyecto (mismo criterio de promedios, mismos
 * días con registro). Esta clase sólo le da forma tipada.
 *
 * Los campos que pueden no tener dato real son nullable a propósito:
 * `null` = "no hay registros", que es distinto de 0.
 */
final class ResumenReciente
{
    public function __construct(
        public int $diasVentana,
        public string $desde,
        public string $hasta,

        // --- Actividad ---
        public int $pasosTotales,
        public float $pasosPromedioDiario,
        public float $distanciaMetrosTotal,
        public int $sesionesActividad,
        public int $entrenamientosCompletados,

        // --- Nutrición (null si no registró comidas en la ventana) ---
        public int $diasConRegistroComidas,
        public ?float $caloriasPromedio,
        public ?float $proteinasPromedio,

        // --- Hidratación (null si no registró agua) ---
        public int $diasConRegistroHidratacion,
        public ?float $vasosPromedio,
        public int $diasMetaHidratacionCumplida,

        // --- Peso ---
        public ?float $pesoInicialVentana,
        public ?float $variacionPesoKg,

        // --- Constancia ---
        public int $diasActivos,
        public int $rachaDias
    ) {
    }

    /** ¿Hay suficiente evidencia como para decidir en base al historial? */
    public function tieneDatosSuficientes(): bool
    {
        return $this->diasActivos > 0;
    }
}
