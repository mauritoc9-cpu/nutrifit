<?php
declare(strict_types=1);

require_once __DIR__ . '/AlimentoRepository.php';
require_once __DIR__ . '/TraductorAlimentos.php';
require_once __DIR__ . '/MatchScorer.php';

/**
 * NutriFit — Motor nutricional único para cámara + IA y buscador manual.
 *
 * Es el ÚNICO punto de entrada que orquesta: caché local -> fuentes
 * externas (USDA/Open Food Facts) -> traducción a español -> validación
 * nutricional -> deduplicación -> scoring de relevancia. Tanto
 * analizar_foto.php como buscar_alimentos.php pasan por acá, así el
 * comportamiento (y las mejoras futuras) es uno solo para toda la app.
 */
class NutricionResolver
{
    private const LIMITE_CANDIDATOS_LOCAL = 40;
    private const LIMITE_CANDIDATOS_EXTERNOS = 12;
    // Umbral para decidir si el catálogo local YA alcanza y no hace falta
    // pegarle a USDA/OFF. Es independiente de cuántos resultados se
    // terminan mostrando al usuario (eso lo decide el límite final de
    // cada método) — acá solo importa "¿ya tengo material de sobra?".
    private const LOCAL_SUFICIENTE = 20;
    // Por debajo de este score, la cámara igual muestra el resultado (el
    // usuario sacó una foto y espera una respuesta) pero marcado como
    // confianza baja, para que el frontend pueda invitar a revisar/editar.
    private const UMBRAL_CONFIANZA_ALTA = 55.0;

    private PDO $db;
    private AlimentoRepository $repo;
    private TraductorAlimentos $traductor;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->repo = new AlimentoRepository($db);
        $this->traductor = new TraductorAlimentos($db);
    }

    /**
     * Resuelve UN alimento detectado por la cámara a su mejor coincidencia
     * nutricional. El nombre que ve el usuario sigue siendo el que dio la
     * IA de visión (ya en español) — acá solo se buscan los macros.
     * Devuelve null si no se encontró ninguna coincidencia razonable.
     */
    public function resolverParaCamara(string $nombreDetectado, bool $envasado): ?array
    {
        $candidatos = $this->recolectarYPrepararCandidatos($nombreDetectado, $envasado, self::LIMITE_CANDIDATOS_EXTERNOS);
        if ($candidatos === []) {
            $this->registrarNoResuelto($nombreDetectado, 'camara');
            return null;
        }

        usort($candidatos, fn ($a, $b) => $b['score'] <=> $a['score']);
        $mejor = $candidatos[0];

        return [
            'alimento_id' => (int) $mejor['id'],
            'nombre_mostrado' => $nombreDetectado,
            'calorias_por_100g' => (float) $mejor['calorias_por_100g'],
            'proteinas' => (float) $mejor['proteinas'],
            'carbohidratos' => (float) $mejor['carbohidratos'],
            'grasas' => (float) $mejor['grasas'],
            'confianza' => $mejor['score'] >= self::UMBRAL_CONFIANZA_ALTA ? 'alta' : 'baja',
        ];
    }

    /**
     * Busca alimentos para el buscador manual: lista ordenada por
     * relevancia, deduplicada, siempre con nombre en español.
     *
     * @return array<int, array<string, mixed>>
     */
    public function buscarParaUsuario(string $consulta, int $limite = 10): array
    {
        $candidatos = $this->recolectarYPrepararCandidatos($consulta, false, self::LOCAL_SUFICIENTE);
        if ($candidatos === []) {
            $this->registrarNoResuelto($consulta, 'buscador');
            return [];
        }

        $candidatos = $this->deduplicar($candidatos);
        usort($candidatos, fn ($a, $b) => $b['score'] <=> $a['score']);

        $candidatos = array_slice($candidatos, 0, $limite);

        return array_map(fn ($c) => [
            'id' => (int) $c['id'],
            'nombre_mostrado' => $c['nombre_mostrado'],
            'categoria' => $c['categoria'],
            'calorias_por_100g' => (float) $c['calorias_por_100g'],
            'proteinas' => (float) $c['proteinas'],
            'carbohidratos' => (float) $c['carbohidratos'],
            'grasas' => (float) $c['grasas'],
        ], $candidatos);
    }

    /** @return array<int, array<string, mixed>> candidatos ya validados, traducidos y con `score` */
    private function recolectarYPrepararCandidatos(string $consulta, bool $envasado, int $limite): array
    {
        $locales = $this->repo->buscarLocal($consulta, self::LIMITE_CANDIDATOS_LOCAL);

        // Solo se salta ir a buscar afuera si el catálogo local YA trae
        // material de sobra (>= $limite candidatos). Antes acá había un
        // atajo por "coincidencia fuerte" (un solo match exacto/prefijo)
        // que cortaba de más: alcanzaba con UN alimento tipo "Fideos" para
        // no ir nunca a buscar más variantes, aunque hubiera pocas.
        $externos = count($locales) >= $limite
            ? []
            : $this->repo->buscarExternos($consulta, $this->traductor->aIngles($consulta), $envasado, self::LIMITE_CANDIDATOS_EXTERNOS);

        $todos = array_merge($locales, $externos);
        if ($todos === []) {
            return [];
        }

        // Solo USDA es 100% inglés y necesita traducción sí o sí. Los
        // nombres de Open Food Facts YA vienen en español cuando existe
        // esa variante (se consulta con lc=es) o son nombres de marca que
        // no se traducen en ningún idioma (Coca-Cola, Oreo) — traducirlos
        // no tiene sentido y antes los escondía por completo si Gemini/
        // Claude no estaban disponibles, aunque el dato fuera perfectamente
        // válido para mostrar.
        $nombresATraducir = [];
        foreach ($todos as $c) {
            if (($c['nombre_mostrado'] ?? null) === null && ($c['fuente'] ?? 'local') === 'usda') {
                $nombresATraducir[] = $c['nombre'];
            }
        }
        $traducciones = $nombresATraducir !== [] ? $this->traductor->aEspanolLote($nombresATraducir) : [];

        $preparados = [];
        foreach ($todos as $c) {
            $nombreMostrado = $c['nombre_mostrado'];
            if ($nombreMostrado === null) {
                if (($c['fuente'] ?? 'local') === 'usda') {
                    $trad = $traducciones[$c['nombre']] ?? null;
                    if ($trad !== null && $trad['confiable']) {
                        $nombreMostrado = $trad['mostrado'];
                        if (isset($c['id'])) {
                            $this->repo->guardarNombreMostrado((int) $c['id'], $nombreMostrado);
                        }
                    } else {
                        // Traducción no disponible ahora (sin API key, sin
                        // cuota, error de red) — mejor mostrar el nombre
                        // original en inglés que ocultar un alimento válido.
                        // Se puede volver a intentar traducir en la próxima
                        // búsqueda porque no se guarda como definitivo.
                        $nombreMostrado = $c['nombre'];
                    }
                } else {
                    // local u openfoodfacts: se usa tal cual, sin pasar por traducción.
                    $nombreMostrado = $c['nombre'];
                }
            }

            if (!MatchScorer::esRelevante($consulta, $nombreMostrado)) {
                continue;
            }

            $original = [
                'calorias_por_100g' => (float) $c['calorias_por_100g'],
                'proteinas' => (float) $c['proteinas'],
                'carbohidratos' => (float) $c['carbohidratos'],
                'grasas' => (float) $c['grasas'],
            ];
            $validado = MatchScorer::validarNutricion($original);
            if ($validado === null) {
                continue;
            }
            // Autocorrección: si se detectó kJ guardado como si fuera kcal,
            // se persiste el valor corregido para que registrar_comida.php
            // también calcule bien de acá en adelante.
            if (isset($c['id']) && abs($validado['calorias_por_100g'] - $original['calorias_por_100g']) > 0.01) {
                $this->repo->corregirCalorias((int) $c['id'], $validado['calorias_por_100g']);
            }

            $score = MatchScorer::calcularScore($consulta, $nombreMostrado, $c['fuente'] ?? 'local');

            $preparados[] = array_merge($c, $validado, [
                'nombre_mostrado' => $nombreMostrado,
                'score' => $score,
            ]);
        }

        return $preparados;
    }

    /**
     * Colapsa duplicados (mismo alimento, distinta fuente) quedándose con
     * el de mayor score. Si dos candidatos comparten nombre pero sus
     * macros difieren de verdad, se intenta diferenciarlos por preparación
     * en vez de mostrarlos como si fueran lo mismo.
     *
     * @param array<int, array<string, mixed>> $candidatos
     * @return array<int, array<string, mixed>>
     */
    private function deduplicar(array $candidatos): array
    {
        $grupos = [];
        foreach ($candidatos as $c) {
            $grupos[MatchScorer::normalizar($c['nombre_mostrado'])][] = $c;
        }

        $resultado = [];
        foreach ($grupos as $items) {
            if (count($items) === 1) {
                $resultado[] = $items[0];
                continue;
            }

            usort($items, fn ($a, $b) => $b['score'] <=> $a['score']);
            $mantenidos = [$items[0]];
            for ($i = 1, $n = count($items); $i < $n; $i++) {
                $esDuplicado = false;
                foreach ($mantenidos as $m) {
                    if (MatchScorer::sonNutricionalmenteSimilares($items[$i], $m)) {
                        $esDuplicado = true;
                        break;
                    }
                }
                if (!$esDuplicado) {
                    $items[$i]['nombre_mostrado'] = $this->diferenciarNombre($items[$i]);
                    $mantenidos[] = $items[$i];
                }
            }
            $resultado = array_merge($resultado, $mantenidos);
        }

        return $resultado;
    }

    /** Agrega una pista de preparación (frita/al horno/etc.) tomada del nombre original, si la tiene. */
    private function diferenciarNombre(array $candidato): string
    {
        $original = MatchScorer::normalizar($candidato['nombre']);
        $mostradoNorm = MatchScorer::normalizar($candidato['nombre_mostrado']);
        $pistas = ['frita', 'frito', 'al horno', 'a la plancha', 'hervida', 'hervido', 'asada', 'asado', 'cruda', 'crudo'];
        foreach ($pistas as $pista) {
            if (str_contains($original, $pista) && !str_contains($mostradoNorm, $pista)) {
                return $candidato['nombre_mostrado'] . ' (' . $pista . ')';
            }
        }
        return $candidato['nombre_mostrado'];
    }

    private function registrarNoResuelto(string $termino, string $contexto): void
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO terminos_no_resueltos (termino, contexto) VALUES (:t, :c)
                 ON DUPLICATE KEY UPDATE veces = veces + 1'
            );
            $stmt->execute(['t' => mb_substr($termino, 0, 150), 'c' => $contexto]);
        } catch (Throwable $e) {
            error_log('[NutricionResolver] No se pudo registrar término no resuelto: ' . $e->getMessage());
        }
    }
}
