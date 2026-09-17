<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/BalanceNutricionalDiario.php';
require_once __DIR__ . '/Recomendacion.php';

/**
 * NutriFit — Recomendación nutricional del día (100% determinista).
 * =====================================================================
 * Esta clase es la cascada de reglas que hasta ahora vivía inline dentro
 * de api/nutricion/dashboard_diario.php. El endpoint ya NO conserva otra
 * copia: pide el contexto, pide el balance y delega acá.
 *
 * NO usa IA, a pesar de que el campo que el frontend consume se llame
 * `recomendacion_ia` (alias legacy, ver el endpoint). Son reglas sobre
 * datos propios del usuario.
 *
 * Orden de precedencia (gana la primera que aplica):
 *   1. exceso calórico
 *   2. macros ya cubiertos
 *   3. carbohidratos altos con proteína pendiente
 *   4. proteína pendiente importante
 *   5. grasas pendientes (sólo si el objetivo es ganar músculo)
 *   6. meta calórica alcanzada
 *   7. en camino (mensaje según objetivo)
 *
 * Nada de lo que devuelve es un diagnóstico: son sugerencias de hábito
 * sobre las metas que el propio usuario configuró.
 */
final class RecomendadorNutricional
{
    public function evaluar(ContextoUsuario $ctx, BalanceNutricionalDiario $balance): Recomendacion
    {
        $sugerencia = $this->sugerenciaProteina($ctx);

        // 1 — Se pasó de la meta calórica (con 5% de tolerancia).
        if ($balance->excesoCalorico) {
            $mensaje = $ctx->objetivo === 'perder_grasa'
                ? 'Ya superaste tu meta calórica de hoy. Si vas a comer algo más, priorizá volumen y proteína magra (vegetales, '
                    . "{$sugerencia}) antes que algo denso en calorías."
                : 'Ya superaste tu meta calórica de hoy — no pasa nada. Para lo que resta del día, priorizá comidas livianas.';

            return new Recomendacion(
                'exceso_calorico',
                1,
                Recomendacion::SEVERIDAD_ATENCION,
                [
                    'objetivo' => $ctx->objetivo,
                    'meta_calorias' => $ctx->metaCalorias,
                    'calorias_consumidas' => $balance->caloriasConsumidas,
                    'sugerencia_proteina' => $sugerencia,
                ],
                null,
                $mensaje
            );
        }

        // 2 — Ya cubrió proteínas, carbohidratos y grasas.
        if ($balance->macrosCubiertos) {
            return new Recomendacion(
                'macros_cubiertos',
                2,
                Recomendacion::SEVERIDAD_INFO,
                [],
                null,
                'Ya cubriste tus macros de hoy. Si comés algo más, que sea liviano — una infusión, fruta o un '
                    . 'yogur natural alcanza.'
            );
        }

        // 3 — Muchos carbohidratos y todavía falta bastante proteína.
        if ($balance->carbohidratosConsumidas > ($ctx->metaCarbohidratos * 0.6) && $balance->restanteProteinas > 20) {
            return new Recomendacion(
                'carbohidratos_altos',
                3,
                Recomendacion::SEVERIDAD_INFO,
                [
                    'restante_proteinas' => $balance->restanteProteinas,
                    'sugerencia_proteina' => $sugerencia,
                ],
                'registrar_comida',
                'Ya llevás un buen nivel de carbohidratos hoy. Para tu próxima comida te recomendamos '
                    . "priorizar proteína magra ({$sugerencia}) con vegetales de bajo índice calórico."
            );
        }

        // 4 — Falta proteína suficiente como para mencionarlo.
        if ($balance->restanteProteinas > 40) {
            return new Recomendacion(
                'proteina_pendiente',
                4,
                Recomendacion::SEVERIDAD_INFO,
                [
                    'restante_proteinas' => $balance->restanteProteinas,
                    'sugerencia_proteina' => $sugerencia,
                ],
                'registrar_comida',
                "Aún te faltan aproximadamente {$balance->restanteProteinas}g de proteína para hoy. Considerá "
                    . "incluir una porción generosa de {$sugerencia} en tu próxima comida."
            );
        }

        // 5 — Grasas pendientes, sólo relevante cuando busca ganar masa.
        if ($balance->restanteGrasas > 15 && $ctx->objetivo === 'ganar_musculo') {
            return new Recomendacion(
                'grasas_pendientes',
                5,
                Recomendacion::SEVERIDAD_INFO,
                ['restante_grasas' => $balance->restanteGrasas],
                'registrar_comida',
                'Te faltan grasas saludables para hoy — un puñado de frutos secos, palta o aceite de oliva '
                    . 'te ayuda a llegar a tu meta calórica para ganar masa sin necesidad de comer de más.'
            );
        }

        // 6 — Llegó justo a la meta calórica (sin pasarse).
        if ($balance->restanteCalorias <= 0) {
            return new Recomendacion(
                'meta_calorica_alcanzada',
                6,
                Recomendacion::SEVERIDAD_INFO,
                ['meta_calorias' => $ctx->metaCalorias],
                null,
                'Ya alcanzaste tu meta calórica del día. Si vas a comer algo más, optá por algo ligero '
                    . 'como una ensalada verde o yogur natural.'
            );
        }

        // 7 — Todo en orden: mensaje según el objetivo declarado.
        $mensaje = $this->mensajeEnCamino($ctx->objetivo, $sugerencia);

        return new Recomendacion(
            'en_camino',
            7,
            Recomendacion::SEVERIDAD_INFO,
            [
                'objetivo' => $ctx->objetivo,
                'sugerencia_proteina' => $sugerencia,
            ],
            'registrar_comida',
            $mensaje
        );
    }

    /**
     * Fuentes de proteína compatibles con la dieta del usuario, filtradas
     * además por sus restricciones reales (un omnívoro alérgico al huevo
     * no debería recibir "huevo" como sugerencia).
     */
    public function sugerenciaProteina(ContextoUsuario $ctx): string
    {
        $fuentes = match ($ctx->tipoDieta) {
            'vegana' => ['lentejas', 'garbanzos', 'tofu', 'tempeh'],
            'vegetariana' => ['huevo', 'lentejas', 'garbanzos', 'queso'],
            'pescatariana' => ['pescado', 'salmón', 'huevo'],
            default => ['pollo', 'pescado', 'huevo'],
        };

        $fuentes = array_values(array_filter($fuentes, function ($fuente) use ($ctx) {
            if ($ctx->tieneRestriccion('huevo') && $fuente === 'huevo') {
                return false;
            }
            if ($ctx->tieneRestriccion('mariscos') && in_array($fuente, ['pescado', 'salmón'], true)) {
                return false;
            }
            if ($ctx->tieneRestriccion('lactosa') && $fuente === 'queso') {
                return false;
            }
            if ($ctx->tieneRestriccion('frutos_secos') && $fuente === 'tempeh') {
                return false;
            }
            return true;
        }));

        if ($fuentes === []) {
            return 'fuentes de proteína acordes a tu dieta';
        }

        $ultima = array_pop($fuentes);

        return $fuentes === [] ? $ultima : implode(', ', $fuentes) . ' o ' . $ultima;
    }

    private function mensajeEnCamino(string $objetivo, string $sugerencia): string
    {
        return match ($objetivo) {
            'perder_grasa' => 'Vas bien encaminado. Para tu próxima comida, priorizá proteína y vegetales antes que '
                . 'carbohidratos refinados, así llegás a tu meta calórica sin pasarte.',
            'ganar_musculo' => "Vas bien encaminado. Todavía tenés margen calórico — sumar {$sugerencia} en tu "
                . 'próxima comida te ayuda a llegar a tu meta de proteína para ganar masa.',
            'monitorear_salud' => 'Vas bien encaminado. Mantené un balance entre proteína, carbohidratos y vegetales en tu '
                . 'próxima comida, acorde a lo que veas con tu profesional de salud.',
            default => 'Vas bien encaminado. Mantené un balance entre proteína y vegetales en tu próxima comida '
                . 'para cumplir tus macros del día.',
        };
    }
}
