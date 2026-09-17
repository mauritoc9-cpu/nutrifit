<?php
declare(strict_types=1);

require_once __DIR__ . '/PerfilDefaults.php';
require_once __DIR__ . '/CatalogoRestricciones.php';
require_once __DIR__ . '/ResumenReciente.php';

/**
 * NutriFit — Contexto del usuario: la foto completa de "quién es esta
 * persona" que van a consumir todos los recomendadores.
 * =====================================================================
 * Es un objeto de datos (DTO). No consulta la base, no decide nada y no
 * tiene efectos: se construye una vez (ver ContextoUsuarioRepository) y
 * se pasa a quien lo necesite. Tratarlo como INMUTABLE — PHP 8.0 no
 * tiene `readonly`, así que es una convención, no algo que el lenguaje
 * garantice acá.
 *
 * Criterio con los datos faltantes: si el usuario todavía no completó el
 * onboarding, los campos que NO tenían un default en producción quedan
 * en null (edad, género, biometría, nivel de actividad, lugar de
 * entrenamiento). No se inventan valores: null significa "no lo sabemos"
 * y cada consumidor decide qué hacer. Los que SÍ tenían un default real
 * lo conservan exactamente igual (ver PerfilDefaults).
 */
final class ContextoUsuario
{
    /**
     * @param string[] $restricciones           lista cruda declarada (alimentarias + condiciones mezcladas)
     * @param string[] $restriccionesAlimentarias filtros duros sobre alimentos
     * @param string[] $condiciones             condiciones de salud declaradas
     */
    public function __construct(
        public int $usuarioId,
        /** false = no hay fila en perfiles_biometricos (onboarding incompleto). */
        public bool $perfilCompleto,

        // --- Identidad y biometría (null = sin dato real) ---
        public ?int $edad,
        public ?string $genero,
        public ?float $pesoActualKg,
        public ?float $pesoObjetivoKg,
        public ?float $alturaCm,

        // --- Objetivo y preferencias ---
        public string $objetivo,
        public ?string $nivelActividad,
        public ?string $lugarEntreno,
        public string $tipoDieta,

        /**
         * Lista declarada tal cual se guarda hoy (ya normalizada, sin el
         * centinela 'ninguna'). Se conserva porque RecomendadorNutricional
         * y los consumidores existentes la usan vía tieneRestriccion().
         * Para decidir, preferir las dos listas separadas de abajo.
         */
        public array $restricciones,

        /** Filtros DUROS: nunca se ofrece un alimento incompatible. */
        public array $restriccionesAlimentarias,

        /** Condiciones declaradas: flags/advertencias, jamás filtros por nombre. */
        public array $condiciones,

        // --- Metas (siempre con valor: o del perfil, o el default de siempre) ---
        public int $metaCalorias,
        public int $metaProteinas,
        public int $metaCarbohidratos,
        public int $metaGrasas,
        public ?int $metaFibra,
        public int $metaPasosDiaria,

        /** Historial agregado. null = no se pidió (ver obtenerConHistorial). */
        public ?ResumenReciente $reciente = null
    ) {
    }

    public function tieneRestriccion(string $clave): bool
    {
        return in_array($clave, $this->restricciones, true);
    }

    public function tieneCondicion(string $clave): bool
    {
        return in_array($clave, $this->condiciones, true);
    }

    /** true = el usuario declaró al menos un filtro alimentario duro. */
    public function tieneRestriccionesAlimentarias(): bool
    {
        return $this->restriccionesAlimentarias !== [];
    }

    /**
     * Metas en el MISMO formato (claves y orden) que dashboard_diario.php
     * viene devolviendo en su campo `metas`. Vive acá para que el día que
     * se cambie el contrato haya un solo lugar que tocar.
     *
     * @return array<string, int|string>
     */
    public function metasComoArray(): array
    {
        return [
            'meta_calorias' => $this->metaCalorias,
            'meta_proteinas' => $this->metaProteinas,
            'meta_carbohidratos' => $this->metaCarbohidratos,
            'meta_grasas' => $this->metaGrasas,
            'objetivo' => $this->objetivo,
        ];
    }
}
