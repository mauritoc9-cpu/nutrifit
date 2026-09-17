<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';

/**
 * NutriFit — Cuánto le falta (o le sobra) al usuario para sus metas de hoy.
 * =====================================================================
 * Existe para que el cálculo de "restantes" viva en UN solo lugar: lo usa
 * el endpoint para su payload `restante` y lo usa RecomendadorNutricional
 * para decidir. Antes estaba calculado una sola vez pero mezclado con la
 * cascada de reglas dentro del endpoint, así que no se podía reutilizar
 * sin copiar y pegar.
 *
 * Las fórmulas son EXACTAMENTE las que ya estaban en dashboard_diario.php,
 * incluidos sus redondeos y truncamientos (que no son uniformes a
 * propósito: se respetan tal cual para no mover ningún número).
 */
final class BalanceNutricionalDiario
{
    private function __construct(
        public int $restanteCalorias,
        public int $restanteProteinas,
        public int $restanteCarbohidratos,
        public int $restanteGrasas,
        public bool $excesoCalorico,
        public bool $macrosCubiertos,
        public float $caloriasConsumidas,
        public float $proteinasConsumidas,
        public float $carbohidratosConsumidas,
        public float $grasasConsumidas
    ) {
    }

    /** @param array{calorias: float, proteinas: float, carbohidratos: float, grasas: float} $consumido */
    public static function calcular(ContextoUsuario $ctx, array $consumido): self
    {
        $restanteProteinas = max(0, (int) round($ctx->metaProteinas - $consumido['proteinas']));
        $restanteCarbohidratos = max(0, (int) round($ctx->metaCarbohidratos - $consumido['carbohidratos']));
        $restanteGrasas = max(0, (int) round($ctx->metaGrasas - $consumido['grasas']));

        // Ojo: calorías trunca con (int) en vez de redondear. Es el
        // comportamiento histórico del endpoint y se mantiene igual.
        $restanteCalorias = max(0, $ctx->metaCalorias - (int) $consumido['calorias']);

        return new self(
            $restanteCalorias,
            $restanteProteinas,
            $restanteCarbohidratos,
            $restanteGrasas,
            $consumido['calorias'] > $ctx->metaCalorias * 1.05,
            $restanteProteinas <= 5 && $restanteCarbohidratos <= 10 && $restanteGrasas <= 5,
            $consumido['calorias'],
            $consumido['proteinas'],
            $consumido['carbohidratos'],
            $consumido['grasas']
        );
    }

    /** Mismo formato (claves y orden) que el campo `restante` del dashboard. */
    public function comoArrayRestante(): array
    {
        return [
            'calorias' => $this->restanteCalorias,
            'proteinas' => $this->restanteProteinas,
            'carbohidratos' => $this->restanteCarbohidratos,
            'grasas' => $this->restanteGrasas,
        ];
    }
}
