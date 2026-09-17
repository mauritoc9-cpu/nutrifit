<?php
declare(strict_types=1);

require_once __DIR__ . '/EjercicioCatalogoRepository.php';
require_once __DIR__ . '/../personalizacion/PlanPersonalizacion.php';

/**
 * NutriFit — Arma la rutina del día a partir del PlanPersonalizacion.
 * =====================================================================
 * NO es un sistema paralelo de entrenamientos: la rutina generada se
 * PERSISTE en las mismas tablas de siempre (`rutinas_ejercicios` +
 * `ejercicios`), con `usuario_id` y `origen='generada'`. Por eso
 * completar_entrenamiento, el XP, la racha y el historial siguen
 * funcionando sin cambios: para ellos es una rutina más.
 *
 * La composición de cada rutina es DATA (self::COMPOSICION), no una
 * cadena de if: cada enfoque define qué "slots" llenar y con qué.
 *
 * Determinismo: mismo perfil → misma rutina (mismo `plan_hash`). Se
 * reutiliza la ya generada en vez de crear filas nuevas cada vez. La
 * variación entre sesiones queda para una etapa posterior (B7, IA).
 */
final class GeneradorRutinasPersonalizadas
{
    /**
     * Slots por enfoque de entrenamiento. Cada slot pide N ejercicios de
     * ciertos tipos/grupos. El orden define el orden de la rutina.
     */
    private const COMPOSICION = [
        'fuerza_hipertrofia' => [
            'titulo' => 'Fuerza e hipertrofia',
            'series_delta' => 1,
            'slots' => [
                ['tipos' => ['fuerza'], 'grupos' => ['piernas'], 'cantidad' => 2],
                ['tipos' => ['fuerza'], 'grupos' => ['pecho', 'espalda'], 'cantidad' => 2],
                ['tipos' => ['fuerza'], 'grupos' => ['hombros', 'brazos'], 'cantidad' => 1],
                ['tipos' => ['core'], 'grupos' => ['core'], 'cantidad' => 1],
            ],
        ],
        'fuerza_y_gasto' => [
            'titulo' => 'Fuerza y gasto calórico',
            'series_delta' => 0,
            'slots' => [
                ['tipos' => ['fuerza'], 'grupos' => ['piernas'], 'cantidad' => 2],
                ['tipos' => ['fuerza'], 'grupos' => ['pecho', 'espalda', 'brazos'], 'cantidad' => 1],
                ['tipos' => ['core'], 'grupos' => ['core'], 'cantidad' => 1],
                ['tipos' => ['cardio'], 'grupos' => [], 'cantidad' => 1],
            ],
        ],
        'mixto_equilibrado' => [
            'titulo' => 'Rutina equilibrada',
            'series_delta' => 0,
            'slots' => [
                ['tipos' => ['fuerza'], 'grupos' => ['piernas'], 'cantidad' => 1],
                ['tipos' => ['fuerza'], 'grupos' => ['pecho', 'espalda', 'hombros', 'brazos'], 'cantidad' => 2],
                ['tipos' => ['core'], 'grupos' => ['core'], 'cantidad' => 1],
                ['tipos' => ['cardio'], 'grupos' => [], 'cantidad' => 1],
            ],
        ],
        'accesible_progresivo' => [
            'titulo' => 'Rutina accesible',
            'series_delta' => 0,
            'slots' => [
                ['tipos' => ['movilidad'], 'grupos' => [], 'cantidad' => 1],
                ['tipos' => ['fuerza'], 'grupos' => ['piernas'], 'cantidad' => 1],
                ['tipos' => ['fuerza'], 'grupos' => ['pecho', 'espalda', 'brazos'], 'cantidad' => 1],
                ['tipos' => ['core'], 'grupos' => ['core'], 'cantidad' => 1],
                ['tipos' => ['cardio'], 'grupos' => [], 'cantidad' => 1],
            ],
        ],
        'suave_movilidad' => [
            'titulo' => 'Movilidad y movimiento suave',
            'series_delta' => 0,
            'slots' => [
                ['tipos' => ['movilidad'], 'grupos' => [], 'cantidad' => 1],
                ['tipos' => ['fuerza'], 'grupos' => ['piernas'], 'cantidad' => 1],
                ['tipos' => ['core'], 'grupos' => ['core'], 'cantidad' => 1],
                ['tipos' => ['cardio'], 'grupos' => [], 'cantidad' => 1],
            ],
        ],
    ];

    private PDO $db;
    private EjercicioCatalogoRepository $catalogo;

    public function __construct(PDO $db, ?EjercicioCatalogoRepository $catalogo = null)
    {
        $this->db = $db;
        $this->catalogo = $catalogo ?? new EjercicioCatalogoRepository($db);
    }

    /**
     * Devuelve la rutina personalizada del usuario, generándola si hace
     * falta. null = no se pudo componer (catálogo insuficiente): quien
     * llama debe caer a la rutina estática de siempre.
     */
    public function obtenerOGenerar(int $usuarioId, PlanPersonalizacion $plan): ?array
    {
        $entrenamiento = $plan->entrenamiento;
        $lugar = (string) $entrenamiento['lugar'];
        $nivel = (string) $entrenamiento['nivel_sugerido'];
        $enfoque = (string) $entrenamiento['enfoque'];
        $conservador = (bool) $entrenamiento['intensidad_conservadora'];

        $hash = $this->calcularHash($plan->objetivo['clave'], $lugar, $nivel, $enfoque, $conservador);

        $existente = $this->buscarPorHash($usuarioId, $hash);
        if ($existente !== null) {
            return $existente;
        }

        $seleccion = $this->seleccionarEjercicios($lugar, $nivel, $enfoque, $conservador);
        if ($seleccion === []) {
            return null;
        }

        $rutinaId = $this->persistir($usuarioId, $plan, $enfoque, $nivel, $hash, $seleccion);

        return $this->buscarPorId($rutinaId);
    }

    /** @return array<int, array<string, mixed>> */
    private function seleccionarEjercicios(string $lugar, string $nivel, string $enfoque, bool $conservador): array
    {
        $composicion = self::COMPOSICION[$enfoque] ?? self::COMPOSICION['accesible_progresivo'];
        $disponibles = $this->catalogo->compatibles($lugar, $nivel, $conservador);

        if ($disponibles === []) {
            return [];
        }

        $usados = [];
        $seleccion = [];

        foreach ($composicion['slots'] as $slot) {
            $candidatos = array_filter($disponibles, function ($e) use ($slot, $usados) {
                if (in_array($e['id'], $usados, true)) {
                    return false;
                }
                if ($slot['tipos'] !== [] && !in_array($e['tipo'], $slot['tipos'], true)) {
                    return false;
                }
                if ($slot['grupos'] !== [] && !in_array($e['grupo_muscular'], $slot['grupos'], true)) {
                    return false;
                }
                return true;
            });

            // Especificidad de lugar primero. Sin esto, los ejercicios
            // marcados 'ambos' (peso corporal) ganaban SIEMPRE por orden
            // de catálogo y un usuario de gimnasio terminaba recibiendo
            // exactamente la misma rutina que uno de casa — que es justo
            // lo que esta capa tiene que evitar.
            $candidatos = array_values($candidatos);
            usort($candidatos, static function ($a, $b) use ($lugar) {
                $exactoA = $a['lugar'] === $lugar ? 0 : 1;
                $exactoB = $b['lugar'] === $lugar ? 0 : 1;
                if ($exactoA !== $exactoB) {
                    return $exactoA <=> $exactoB;
                }
                // Empate: se mantiene el orden estable del catálogo.
                return $a['id'] <=> $b['id'];
            });

            $tomados = array_slice($candidatos, 0, (int) $slot['cantidad']);

            foreach ($tomados as $ejercicio) {
                $usados[] = $ejercicio['id'];
                $ejercicio['series_aplicadas'] = max(1, $ejercicio['series_sugeridas'] + (int) $composicion['series_delta']);
                $seleccion[] = $ejercicio;
            }
        }

        return $seleccion;
    }

    /** Persiste la rutina generada en las tablas de siempre. */
    private function persistir(
        int $usuarioId,
        PlanPersonalizacion $plan,
        string $enfoque,
        string $nivel,
        string $hash,
        array $seleccion
    ): int {
        $composicion = self::COMPOSICION[$enfoque] ?? self::COMPOSICION['accesible_progresivo'];
        $lugar = (string) $plan->entrenamiento['lugar'];

        // `objetivo` de rutinas_ejercicios es un ENUM de 4 valores que no
        // cubre mantener_peso/monitorear_salud. Se mapea a la opción
        // compatible más cercana para no romper el esquema existente.
        $objetivoRutina = $this->objetivoCompatible($plan->objetivo['clave']);

        $titulo = $composicion['titulo'] . ($lugar === 'casa' ? ' en casa' : ' en gimnasio');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO rutinas_ejercicios
                    (usuario_id, titulo, lugar, objetivo, nivel_dificultad, descripcion, origen, plan_hash, generada_en)
                 VALUES (:uid, :titulo, :lugar, :objetivo, :nivel, :descripcion, :origen, :hash, NOW())'
            );
            $stmt->execute([
                'uid' => $usuarioId,
                'titulo' => $titulo,
                'lugar' => $lugar,
                'objetivo' => $objetivoRutina,
                'nivel' => $nivel,
                'descripcion' => 'Rutina generada según tu objetivo, tu lugar de entrenamiento y tu nivel.',
                'origen' => 'generada',
                'hash' => $hash,
            ]);
            $rutinaId = (int) $this->db->lastInsertId();

            $stmtEj = $this->db->prepare(
                'INSERT INTO ejercicios (rutina_id, catalogo_id, nombre, series, repeticiones, descanso_segundos, orden)
                 VALUES (:rid, :cid, :nombre, :series, :reps, :descanso, :orden)'
            );

            $orden = 1;
            foreach ($seleccion as $ejercicio) {
                $stmtEj->execute([
                    'rid' => $rutinaId,
                    'cid' => $ejercicio['id'],
                    'nombre' => $ejercicio['nombre'],
                    'series' => $ejercicio['series_aplicadas'],
                    'reps' => $ejercicio['repeticiones_sugeridas'],
                    'descanso' => $ejercicio['descanso_segundos'],
                    'orden' => $orden,
                ]);
                $orden++;
            }

            $this->db->commit();

            return $rutinaId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** ENUM de rutinas_ejercicios: ganar_peso|perder_grasa|ganar_musculo|mejorar_habitos. */
    private function objetivoCompatible(string $objetivo): string
    {
        $permitidos = ['ganar_peso', 'perder_grasa', 'ganar_musculo', 'mejorar_habitos'];
        if (in_array($objetivo, $permitidos, true)) {
            return $objetivo;
        }

        // mantener_peso y monitorear_salud no existen en ese ENUM.
        return 'mejorar_habitos';
    }

    private function calcularHash(string $objetivo, string $lugar, string $nivel, string $enfoque, bool $conservador): string
    {
        // La versión al final invalida las rutinas generadas con reglas
        // viejas sin tener que borrar filas (v2 = prioridad por lugar).
        return substr(sha1(implode('|', [$objetivo, $lugar, $nivel, $enfoque, $conservador ? '1' : '0', 'v2'])), 0, 40);
    }

    private function buscarPorHash(int $usuarioId, string $hash): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id FROM rutinas_ejercicios
             WHERE usuario_id = :uid AND plan_hash = :hash AND origen = 'generada'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['uid' => $usuarioId, 'hash' => $hash]);
        $id = $stmt->fetchColumn();

        return $id ? $this->buscarPorId((int) $id) : null;
    }

    /** Devuelve la rutina con sus ejercicios, en el mismo shape que el endpoint ya usaba. */
    private function buscarPorId(int $rutinaId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM rutinas_ejercicios WHERE id = :id');
        $stmt->execute(['id' => $rutinaId]);
        $rutina = $stmt->fetch();

        if (!$rutina) {
            return null;
        }

        $stmtEj = $this->db->prepare(
            'SELECT e.*, c.clave AS catalogo_clave, c.grupo_muscular, c.equipamiento, c.tipo
             FROM ejercicios e
             LEFT JOIN ejercicios_catalogo c ON c.id = e.catalogo_id
             WHERE e.rutina_id = :rid ORDER BY e.orden ASC'
        );
        $stmtEj->execute(['rid' => $rutinaId]);
        $rutina['ejercicios'] = $stmtEj->fetchAll();

        return $rutina;
    }
}
