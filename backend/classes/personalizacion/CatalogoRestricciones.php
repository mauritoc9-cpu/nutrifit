<?php
declare(strict_types=1);

/**
 * NutriFit — Separa lo que hoy la DB guarda mezclado.
 * =====================================================================
 * La columna `perfiles_biometricos.alergias` guarda en un mismo array
 * JSON dos cosas conceptualmente distintas:
 *
 *   - RESTRICCIONES ALIMENTARIAS (celiaco, lactosa, frutos_secos,
 *     mariscos, huevo) → son FILTROS DUROS: un alimento incompatible
 *     simplemente no se ofrece, nunca.
 *
 *   - CONDICIONES DE SALUD (diabetes, hipertension) → NO son
 *     ingredientes y no deben filtrar por nombre. Se usan de forma
 *     conservadora: para marcar flags, mostrar advertencias y limitar
 *     recomendaciones automáticas agresivas.
 *
 * Esta clase hace la separación EN LECTURA, sin migrar la base: la
 * columna sigue siendo la fuente de verdad y todo lo que ya escribe ahí
 * (onboarding) sigue funcionando igual. Si mañana se decide mover las
 * condiciones a su propia columna/tabla, este es el único lugar que
 * sabe a qué familia pertenece cada clave.
 *
 * NutriFit no diagnostica, no prescribe tratamientos y no opina sobre
 * medicación: las condiciones sólo vuelven más conservadoras las
 * sugerencias automáticas.
 */
final class CatalogoRestricciones
{
    /** Centinela que usa el onboarding cuando el usuario no declara nada. */
    public const NINGUNA = 'ninguna';

    /** Filtros duros sobre alimentos. */
    private const ALIMENTARIAS = [
        'celiaco' => 'Celíaco / Sin TACC',
        'lactosa' => 'Intolerancia a la lactosa',
        'frutos_secos' => 'Frutos secos',
        'mariscos' => 'Mariscos',
        'huevo' => 'Huevo',
    ];

    /** Condiciones declaradas: flags + advertencias, nunca filtros por nombre. */
    private const CONDICIONES = [
        'diabetes' => 'Diabetes',
        'hipertension' => 'Hipertensión',
    ];

    /**
     * Advertencia conservadora por condición. Es información general de
     * hábitos, NO una indicación médica ni un diagnóstico.
     */
    private const ADVERTENCIAS = [
        'diabetes' => [
            'codigo' => 'condicion_diabetes',
            'texto' => 'Configuraste diabetes en tu perfil: las sugerencias automáticas evitan priorizar azúcares '
                . 'simples. Cualquier cambio de plan conviene consultarlo con tu profesional de salud.',
        ],
        'hipertension' => [
            'codigo' => 'condicion_hipertension',
            'texto' => 'Configuraste hipertensión en tu perfil: las sugerencias automáticas se mantienen conservadoras '
                . 'con alimentos de alto contenido de sodio. Cualquier cambio de plan conviene consultarlo con tu '
                . 'profesional de salud.',
        ],
    ];

    public static function esAlimentaria(string $clave): bool
    {
        return isset(self::ALIMENTARIAS[$clave]);
    }

    public static function esCondicion(string $clave): bool
    {
        return isset(self::CONDICIONES[$clave]);
    }

    /**
     * Parte la lista declarada (ya normalizada, sin 'ninguna') en sus dos
     * familias. Lo que no se reconozca va a `desconocidas` en vez de
     * asumirse como alimento — así una clave nueva nunca se convierte por
     * accidente en un filtro que esconda comida.
     *
     * @param string[] $declaradas
     * @return array{alimentarias: string[], condiciones: string[], desconocidas: string[]}
     */
    public static function separar(array $declaradas): array
    {
        $alimentarias = [];
        $condiciones = [];
        $desconocidas = [];

        foreach ($declaradas as $clave) {
            if ($clave === self::NINGUNA) {
                continue;
            }
            if (self::esAlimentaria($clave)) {
                $alimentarias[] = $clave;
            } elseif (self::esCondicion($clave)) {
                $condiciones[] = $clave;
            } else {
                $desconocidas[] = $clave;
            }
        }

        return [
            'alimentarias' => $alimentarias,
            'condiciones' => $condiciones,
            'desconocidas' => $desconocidas,
        ];
    }

    public static function etiqueta(string $clave): string
    {
        return self::ALIMENTARIAS[$clave] ?? self::CONDICIONES[$clave] ?? $clave;
    }

    /**
     * @param string[] $condiciones
     * @return array<int, array{codigo: string, condicion: string, texto: string}>
     */
    public static function advertencias(array $condiciones): array
    {
        $salida = [];
        foreach ($condiciones as $condicion) {
            if (!isset(self::ADVERTENCIAS[$condicion])) {
                continue;
            }
            $salida[] = [
                'codigo' => self::ADVERTENCIAS[$condicion]['codigo'],
                'condicion' => $condicion,
                'texto' => self::ADVERTENCIAS[$condicion]['texto'],
            ];
        }

        return $salida;
    }
}
