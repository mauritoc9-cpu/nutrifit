<?php
declare(strict_types=1);

/**
 * NutriFit — Motor de relevancia y validación nutricional compartido por
 * la cámara con IA y el buscador manual (ver NutricionResolver).
 *
 * Reglas de diseño (por qué el score se arma así):
 *  - El ranking es por NIVELES con brechas grandes (exacto > empieza con
 *    la búsqueda > tiene todos los tokens > tiene algunos > piso mínimo).
 *    Ver el docblock de calcularScore() para el detalle — así "Chocolate"/
 *    "Chocolate amargo" quedan siempre por encima de "Surtido de
 *    chocolates", sin importar fuente o similitud de texto.
 *  - La comparación de "exacto"/"empieza con" es a nivel de TOKENS ya
 *    singularizados y sin palabras de enlace, no de string crudo — así
 *    singular/plural ("Milanesa" vs "Milanesas") no manda injustamente a
 *    un candidato a un nivel más bajo.
 *  - La POSICIÓN del token buscado dentro del nombre importa como
 *    desempate fino: si aparece primero ("Carne vacuna" para "carne")
 *    puntúa más que si aparece al final o en medio de un plato compuesto
 *    ("Canelones de carne") — pero esto nunca cruza de nivel.
 *  - La fuente importa como desempate fino: local > Codulia (catálogo
 *    argentino curado) > USDA > Open Food Facts (mezcla productos
 *    envasados de calidad variable).
 *  - Nada de esto reemplaza tener una buena semilla local: el algoritmo es
 *    general, pero rinde mejor cuanto más se curen los alimentos comunes
 *    como locales (ver migración 006_motor_nutricional.sql).
 */
class MatchScorer
{
    private const STOPWORDS = ['a', 'al', 'de', 'del', 'la', 'las', 'el', 'los', 'y', 'con', 'en', 'para'];

    private const PESO_FUENTE = [
        'local' => 15.0,
        // Codulia es un catálogo curado específicamente argentino: entre
        // resultados equivalentes, se prioriza por sobre USDA/OFF (pero
        // por debajo de lo curado a mano en `local`).
        'codulia' => 12.0,
        'usda' => 8.0,
        'openfoodfacts' => 3.0,
    ];

    /** Minúsculas, sin tildes, sin puntuación, espacios colapsados. */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));
        $texto = strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u',
        ]);
        $texto = preg_replace('/[^a-z0-9\s]/', ' ', $texto) ?? $texto;
        $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;
        return trim($texto);
    }

    /** @return array<int, string> tokens normalizados, sin stopwords, singularizados (heurística). */
    public static function tokenizar(string $normalizado): array
    {
        $tokens = array_filter(explode(' ', $normalizado), fn ($t) => $t !== '' && !in_array($t, self::STOPWORDS, true));
        return array_values(array_map([self::class, 'singularizar'], $tokens));
    }

    // Consonantes finales típicas de un singular que pluraliza agregando
    // "-es" (mujer->mujeres, flor->flores, pan->panes, reloj->relojes,
    // animal->animales, ciudad->ciudades). Si al quitar "es" NO queda uno
    // de estos finales, es más probable que el singular ya termine en
    // vocal y el plural solo haya sumado una "s" (chocolate->chocolates,
    // parte->partes, leche->leches) — ese es el caso más común en español.
    private const CONSONANTES_PLURAL_ES = ['r', 'l', 'n', 'd', 'j', 'z'];

    /** Heurística simple de singularización en español — no gramaticalmente perfecta, alcanza para deduplicar/comparar. */
    public static function singularizar(string $token): string
    {
        if (mb_strlen($token) > 4 && str_ends_with($token, 'es')) {
            $sinEs = mb_substr($token, 0, -2);
            if (in_array(mb_substr($sinEs, -1), self::CONSONANTES_PLURAL_ES, true)) {
                return $sinEs; // mujeres->mujer, flores->flor, panes->pan
            }
            return mb_substr($token, 0, -1); // chocolates->chocolate, partes->parte
        }
        if (mb_strlen($token) > 3 && str_ends_with($token, 's')) {
            return mb_substr($token, 0, -1);
        }
        return $token;
    }

    /**
     * Puntúa qué tan relevante es $nombreCandidato para la búsqueda $consulta.
     * $fuente: 'local' | 'codulia' | 'usda' | 'openfoodfacts'.
     *
     * Sistema de NIVELES con brechas grandes (1000 / 700 / 450 / 200 / 50):
     *   1. Coincidencia exacta (mismos tokens principales, sin importar
     *      mayúsculas/tildes/guiones/singular-plural/palabras de enlace).
     *   2. Empieza exactamente con los tokens de la búsqueda, en el mismo
     *      orden ("variante directa" — Chocolate amargo, Milanesa de pollo
     *      al horno). Cuantas más palabras se agregan después, más baja
     *      dentro de este nivel (así el genérico/base gana sobre variantes
     *      muy específicas sin dejar de estar en el mismo nivel "directo").
     *   3. Contiene TODOS los tokens principales de la búsqueda en
     *      cualquier posición ("Postre de dulce de leche" para "dulce de
     *      leche"). Dentro del nivel, aparecer más al principio y en
     *      nombres más cortos puntúa mejor.
     *   4. Contiene ALGUNOS (no todos) los tokens — cobertura parcial.
     *   5. Piso mínimo: no comparte ningún token como palabra completa,
     *      pero pasó el filtro de relevancia por similitud de caracteres
     *      (variantes de escritura raras, ej. "cocacola" vs "Coca Cola").
     *
     * Las señales finas (fuente, similitud, posición) solo desempatan
     * DENTRO de un nivel — nunca alcanzan para saltar de un nivel a otro,
     * así una coincidencia exacta de Open Food Facts sigue ganándole a una
     * coincidencia parcial del catálogo local.
     */
    public static function calcularScore(string $consulta, string $nombreCandidato, string $fuente): float
    {
        $tokensConsulta = self::tokenizar(self::normalizar($consulta));
        $tokensCandidato = self::tokenizar(self::normalizar($nombreCandidato));

        if ($tokensConsulta === [] || $tokensCandidato === []) {
            return 0.0;
        }

        $pesoFuente = self::PESO_FUENTE[$fuente] ?? 0.0;
        similar_text(implode(' ', $tokensConsulta), implode(' ', $tokensCandidato), $porcentajeSimilitud);
        $bonusSimilitud = ($porcentajeSimilitud / 100) * 8.0;
        $desempate = $pesoFuente + $bonusSimilitud;

        $nConsulta = count($tokensConsulta);
        $nCandidato = count($tokensCandidato);

        // Nivel 1: mismo conjunto y orden de tokens principales.
        if ($tokensConsulta === $tokensCandidato) {
            return round(1000.0 + $desempate, 3);
        }

        // Nivel 2: el candidato empieza con exactamente los tokens de la
        // búsqueda, en el mismo orden ("variante directa").
        if ($nCandidato > $nConsulta && array_slice($tokensCandidato, 0, $nConsulta) === $tokensConsulta) {
            $tokensExtra = $nCandidato - $nConsulta;
            // Piso de 620 para que ninguna cantidad de palabras extra tire
            // una variante directa por debajo del Nivel 3.
            $base = max(700.0 - ($tokensExtra * 15.0), 620.0);
            return round($base + $desempate, 3);
        }

        $interseccion = array_intersect($tokensConsulta, $tokensCandidato);
        $mejorPosicion = self::mejorPosicionToken($tokensConsulta, $tokensCandidato);

        // Nivel 3: están todos los tokens principales, en cualquier posición.
        if (count($interseccion) === $nConsulta) {
            $bonusPosicion = $mejorPosicion !== null ? (30.0 / ($mejorPosicion + 1)) : 0.0;
            $penalizacionLongitud = min(($nCandidato - $nConsulta) * 5.0, 100.0);
            return round(450.0 + $bonusPosicion - $penalizacionLongitud + $desempate, 3);
        }

        // Nivel 4: coincidencia parcial (algunos tokens, no todos).
        if ($interseccion !== []) {
            $cobertura = count($interseccion) / $nConsulta;
            $bonusPosicion = $mejorPosicion !== null ? (20.0 / ($mejorPosicion + 1)) : 0.0;
            return round(200.0 + ($cobertura * 80.0) + $bonusPosicion + $desempate, 3);
        }

        // Nivel 5: piso mínimo — pasó esRelevante() solo por similitud de
        // caracteres, sin compartir ningún token completo.
        return round(50.0 + $desempate, 3);
    }

    /** Posición (índice) del primer token de la consulta que aparece en el candidato, o null si ninguno aparece. */
    private static function mejorPosicionToken(array $tokensConsulta, array $tokensCandidato): ?int
    {
        $mejor = null;
        foreach ($tokensConsulta as $token) {
            $indice = array_search($token, $tokensCandidato, true);
            if ($indice !== false && ($mejor === null || $indice < $mejor)) {
                $mejor = $indice;
            }
        }
        return $mejor;
    }

    /**
     * Filtro de piso mínimo de relevancia: sin esto, un resultado externo
     * (USDA/OFF) "parecido" por casualidad pero que no comparte ninguna
     * palabra real con la búsqueda puede colarse solo por tener datos
     * nutricionales completos. Se aplica ANTES de ordenar por score.
     */
    public static function esRelevante(string $consulta, string $nombreCandidato): bool
    {
        $consultaNorm = self::normalizar($consulta);
        $candidatoNorm = self::normalizar($nombreCandidato);
        $tokensConsulta = self::tokenizar($consultaNorm);
        $tokensCandidato = self::tokenizar($candidatoNorm);

        if (array_intersect($tokensConsulta, $tokensCandidato) !== []) {
            return true;
        }
        if (str_starts_with($candidatoNorm, $consultaNorm) || str_starts_with($consultaNorm, $candidatoNorm)) {
            return true;
        }
        similar_text($consultaNorm, $candidatoNorm, $porcentaje);
        return $porcentaje >= 45.0;
    }

    /**
     * Valida y, si hace falta, corrige un candidato antes de mostrarlo.
     * Devuelve el candidato (posiblemente con `calorias_por_100g` corregido
     * si se detectó un caso de kJ interpretado como kcal), o null si los
     * datos son absurdos y no se pueden confiar.
     *
     * @param array{calorias_por_100g: float, proteinas: float, carbohidratos: float, grasas: float} $candidato
     */
    public static function validarNutricion(array $candidato): ?array
    {
        $kcal = (float) $candidato['calorias_por_100g'];
        $prot = (float) $candidato['proteinas'];
        $carb = (float) $candidato['carbohidratos'];
        $gras = (float) $candidato['grasas'];

        if ($prot < 0 || $carb < 0 || $gras < 0 || $kcal < 0) {
            return null;
        }
        // Ningún macro individual puede superar los 100g por cada 100g de alimento.
        if ($prot > 100 || $carb > 100 || $gras > 100) {
            return null;
        }
        // La suma de macros tampoco puede superar (con margen) los 100g totales.
        if (($prot + $carb + $gras) > 105) {
            return null;
        }

        $kcalAtwater = 4 * $prot + 4 * $carb + 9 * $gras;

        // Un alimento con macros reales no puede tener 0 kcal.
        if ($kcal === 0.0 && $kcalAtwater > 20) {
            return null;
        }

        // Caso típico del bug reportado: la API entregó kJ y se guardó como
        // si fuera kcal (ej. "2060 kcal/100g"). Si dividir por 4.184 lo deja
        // cerca del cálculo de Atwater, se corrige en vez de descartar.
        if ($kcalAtwater > 0 && $kcal > $kcalAtwater * 3.5) {
            $kcalConvertida = $kcal / 4.184;
            if (abs($kcalConvertida - $kcalAtwater) <= $kcalAtwater * 0.3) {
                $candidato['calorias_por_100g'] = round($kcalConvertida, 2);
                return $candidato;
            }
            // Desviación extrema y no explicada por kJ: dato corrupto.
            return null;
        }

        // Techo absoluto: ni el aceite puro llega a 900 kcal/100g.
        if ($kcal > 900) {
            return null;
        }

        // Desviación moderada de Atwater: señal de alerta, no descarte
        // automático (fibra/alcohol/redondeos pueden explicarla).
        // Se maneja como penalización de score en NutricionResolver, no acá.

        return $candidato;
    }

    /**
     * Compara dos candidatos ya normalizados para decidir si representan el
     * mismo alimento (duplicado a colapsar) o si son preparaciones distintas
     * que conviene diferenciar en el nombre mostrado.
     */
    public static function sonNutricionalmenteSimilares(array $a, array $b, float $tolerancia = 0.12): bool
    {
        $base = max((float) $a['calorias_por_100g'], (float) $b['calorias_por_100g'], 1.0);
        return abs((float) $a['calorias_por_100g'] - (float) $b['calorias_por_100g']) / $base <= $tolerancia;
    }
}
