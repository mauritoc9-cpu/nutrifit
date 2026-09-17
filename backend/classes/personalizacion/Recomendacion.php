<?php
declare(strict_types=1);

/**
 * NutriFit — Contrato de salida de cualquier recomendador.
 * =====================================================================
 * La idea central: una recomendación es una DECISIÓN ESTRUCTURADA, no un
 * string. El texto es sólo una de sus representaciones.
 *
 * Eso es lo que más adelante va a permitir, sin tocar las reglas:
 *   - que la IA REDACTE el mensaje (recibiendo codigo + datos ya
 *     decididos, sin poder cambiar la decisión);
 *   - traducir a otro idioma;
 *   - que el frontend pinte un ícono/color según `severidad`;
 *   - que un botón se arme solo a partir de `accion`;
 *   - loguear qué se recomendó sin guardar párrafos completos.
 */
final class Recomendacion
{
    public const SEVERIDAD_INFO = 'info';
    public const SEVERIDAD_ATENCION = 'atencion';

    /** @param array<string, mixed> $datos */
    public function __construct(
        /** Identificador estable de la situación detectada (ej. 'proteina_pendiente'). */
        public string $codigo,
        /** Orden en que se evaluó la regla: 1 = la de mayor precedencia. */
        public int $prioridad,
        /** self::SEVERIDAD_* — hoy sólo informativo, nunca clínico. */
        public string $severidad,
        /** Valores que originaron la decisión (los que el mensaje interpola). */
        public array $datos,
        /** Acción sugerida para la UI, o null si no hay uno natural. */
        public ?string $accion,
        /**
         * Texto ya redactado. Se mantiene acá porque hoy es lo único que
         * consume el frontend; cuando exista el redactor con IA, este
         * campo pasa a ser "el texto por defecto/determinista".
         */
        public string $mensaje
    ) {
    }
}
