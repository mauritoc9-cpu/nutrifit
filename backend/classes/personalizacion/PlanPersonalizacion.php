<?php
declare(strict_types=1);

/**
 * NutriFit — Resultado estructurado del Motor de Personalización.
 * =====================================================================
 * NO es un mensaje ni un texto: es la decisión completa, en datos, para
 * que la consuman el backend, el frontend web y el futuro cliente React
 * Native sin repetir reglas.
 *
 *   objetivo       → qué quiere lograr el usuario (normalizado)
 *   prioridades[]  → en qué orden importa cada cosa para ESE objetivo
 *   nutricion      → metas + enfoque + por qué
 *   actividad      → meta de pasos + enfoque + lectura del historial
 *   entrenamiento  → lugar, enfoque, frecuencia y nivel sugerido
 *   hidratacion    → objetivo de agua y de dónde sale ese número
 *   restricciones  → filtros duros + condiciones + advertencias
 *   herramientas[] → qué secciones conviene empujar primero
 *   motivos[]      → explicabilidad global (código + motivo + datos)
 *   datosFaltantes[] → qué NO sabemos (para no fingir que sí)
 *
 * Cada decisión relevante viaja con su `motivo`, así el frontend o una
 * IA redactora pueden convertirla a lenguaje natural sin que la decisión
 * deje de ser determinista.
 */
final class PlanPersonalizacion
{
    /**
     * @param array<string, mixed> $objetivo
     * @param array<int, array<string, mixed>> $prioridades
     * @param array<string, mixed> $nutricion
     * @param array<string, mixed> $actividad
     * @param array<string, mixed> $entrenamiento
     * @param array<string, mixed> $hidratacion
     * @param array<string, mixed> $preferencias
     * @param array<string, mixed> $restricciones
     * @param array<int, array<string, mixed>> $herramientas
     * @param array<int, array<string, mixed>> $motivos
     * @param string[] $datosFaltantes
     */
    public function __construct(
        public int $usuarioId,
        public bool $perfilCompleto,
        public array $objetivo,
        public array $prioridades,
        public array $nutricion,
        public array $actividad,
        public array $entrenamiento,
        public array $hidratacion,
        public array $preferencias,
        public array $restricciones,
        public array $herramientas,
        public array $motivos,
        public array $datosFaltantes
    ) {
    }

    /** Serialización estable para la API (web y futuro cliente móvil). */
    public function comoArray(): array
    {
        return [
            'usuario_id' => $this->usuarioId,
            'perfil_completo' => $this->perfilCompleto,
            'objetivo' => $this->objetivo,
            'prioridades' => $this->prioridades,
            'nutricion' => $this->nutricion,
            'actividad' => $this->actividad,
            'entrenamiento' => $this->entrenamiento,
            'hidratacion' => $this->hidratacion,
            'preferencias' => $this->preferencias,
            'restricciones' => $this->restricciones,
            'herramientas' => $this->herramientas,
            'motivos' => $this->motivos,
            'datos_faltantes' => $this->datosFaltantes,
        ];
    }
}
