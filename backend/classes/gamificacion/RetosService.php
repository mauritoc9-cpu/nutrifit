<?php
declare(strict_types=1);

require_once __DIR__ . '/../SistemaXP.php';

/**
 * NutriFit — Retos semanales con progreso real.
 * =====================================================================
 * Cada reto define un objetivo numérico cuyo progreso se calcula SIEMPRE
 * en vivo desde los datos reales del usuario (nunca un contador guardado
 * que pudiera desincronizarse o farmearse). Los retos son semanales:
 * se miden sobre la semana ISO vigente (lunes a domingo, zona de la app)
 * y se reinician solos cada semana.
 *
 * `retos_usuario` sólo registra la FINALIZACIÓN por período (año-semana
 * ISO), para que la recompensa de XP se otorgue una única vez por semana
 * aunque el usuario siga sumando progreso o abra la vista varias veces.
 *
 * listar() reconcilia (otorga la recompensa de lo recién completado) y
 * devuelve el estado para el frontend. Es idempotente.
 */
final class RetosService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Definiciones de los retos vigentes. objetivo en la unidad indicada.
     * @return array<int, array<string, mixed>>
     */
    private function definiciones(): array
    {
        return [
            [
                'clave' => 'sem_entrenamientos',
                'titulo' => 'Entrenador de la semana',
                'descripcion' => 'Completá 3 entrenamientos esta semana',
                'icono' => 'dumbbell',
                'objetivo' => 3,
                'unidad' => 'entrenamientos',
                'xp_recompensa' => 60,
            ],
            [
                'clave' => 'sem_distancia',
                'titulo' => 'En ruta',
                'descripcion' => 'Recorré 10 km entre caminata y carrera esta semana',
                'icono' => 'map-pin',
                'objetivo' => 10,
                'unidad' => 'km',
                'xp_recompensa' => 60,
            ],
            [
                'clave' => 'sem_actividad_dias',
                'titulo' => 'Movimiento constante',
                'descripcion' => 'Registrá actividad en 4 días distintos esta semana',
                'icono' => 'calendar-check',
                'objetivo' => 4,
                'unidad' => 'días',
                'xp_recompensa' => 50,
            ],
            [
                'clave' => 'sem_hidratacion',
                'titulo' => 'Bien hidratado',
                'descripcion' => 'Cumplí tu meta de agua 5 días esta semana',
                'icono' => 'droplet',
                'objetivo' => 5,
                'unidad' => 'días',
                'xp_recompensa' => 50,
            ],
            [
                'clave' => 'sem_racha',
                'titulo' => 'Sin parar',
                'descripcion' => 'Llegá a una racha de 7 días',
                'icono' => 'flame',
                'objetivo' => 7,
                'unidad' => 'días',
                'xp_recompensa' => 70,
            ],
        ];
    }

    /**
     * Lista los retos con su progreso actual, estado y recompensa,
     * reconciliando (otorgando XP una vez) los que se hayan completado.
     * @return array<string, mixed>
     */
    public function listar(int $usuarioId): array
    {
        (new SistemaXP($this->db))->actualizarRachaVigente($usuarioId);
        [$lunes, $domingo, $periodo] = $this->ventanaSemana();
        $progresos = $this->calcularProgresos($usuarioId, $lunes, $domingo);
        $completadosPrevios = $this->clavesCompletadas($usuarioId, $periodo);

        $retos = [];
        $nuevos = [];
        foreach ($this->definiciones() as $def) {
            $clave = $def['clave'];
            $progreso = $progresos[$clave] ?? 0;
            $objetivo = (int) $def['objetivo'];
            $cumplido = $progreso >= $objetivo;
            $yaRegistrado = in_array($clave, $completadosPrevios, true);

            // Reconciliación: si se cumplió y todavía no estaba registrado
            // para este período, se registra y recompensa (una sola vez).
            if ($cumplido && !$yaRegistrado) {
                $premio = $this->completar($usuarioId, $clave, $periodo, (int) $def['xp_recompensa']);
                if ($premio !== null) $nuevos[] = array_merge($def, ['periodo' => $periodo, 'xp' => $premio]);
                $yaRegistrado = true;
            }

            $retos[] = [
                'clave' => $clave,
                'titulo' => $def['titulo'],
                'descripcion' => $def['descripcion'],
                'icono' => $def['icono'],
                'objetivo' => $objetivo,
                'progreso' => min($progreso, $objetivo),
                'progreso_real' => $progreso,
                'unidad' => $def['unidad'],
                'xp_recompensa' => (int) $def['xp_recompensa'],
                'estado' => $yaRegistrado ? 'completado' : 'en_progreso',
                'porcentaje' => $objetivo > 0 ? min(100, (int) round(($progreso / $objetivo) * 100)) : 0,
            ];
        }

        return [
            'retos' => $retos,
            'nuevos' => $nuevos,
            'periodo' => $periodo,
            'desde' => $lunes,
            'hasta' => $domingo,
        ];
    }

    /** Semana ISO vigente en la zona de la app: [lunes, domingo, "YYYY-Www"]. */
    private function ventanaSemana(): array
    {
        $hoy = new DateTimeImmutable('today');
        $dow = (int) $hoy->format('N'); // 1=lunes ... 7=domingo
        $lunes = $hoy->modify('-' . ($dow - 1) . ' days');
        $domingo = $lunes->modify('+6 days');

        return [
            $lunes->format('Y-m-d'),
            $domingo->format('Y-m-d'),
            $hoy->format('o-\WW'), // año-semana ISO, ej. 2026-W41
        ];
    }

    /** @return array<string, int|float> progreso por clave de reto */
    private function calcularProgresos(int $usuarioId, string $lunes, string $domingo): array
    {
        $escalar = function (string $sql) use ($usuarioId, $lunes, $domingo) {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['uid' => $usuarioId, 'desde' => $lunes, 'hasta' => $domingo]);
            return $stmt->fetchColumn();
        };

        $entrenamientos = (int) $escalar(
            'SELECT COUNT(*) FROM registros_entrenamiento WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta'
        );

        // Distancia sólo de tipos que recorren distancia a pie (caminata/carrera),
        // nunca ciclismo — coherente con cómo el resto de la app trata los pasos.
        $metros = (float) $escalar(
            "SELECT COALESCE(SUM(ra.distancia_metros), 0)
             FROM registros_actividad ra
             JOIN tipos_actividad ta ON ta.id = ra.tipo_actividad_id
             WHERE ra.usuario_id = :uid AND ra.fecha BETWEEN :desde AND :hasta
               AND ta.clave IN ('caminata','carrera')"
        );

        $diasActividad = (int) $escalar(
            'SELECT COUNT(DISTINCT fecha) FROM registros_actividad WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta'
        );

        $diasAgua = (int) $escalar(
            'SELECT COUNT(*) FROM registros_hidratacion WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta AND vasos >= meta_vasos'
        );

        $stmtRacha = $this->db->prepare('SELECT racha_dias FROM usuarios WHERE id = :uid');
        $stmtRacha->execute(['uid' => $usuarioId]);
        $racha = (int) $stmtRacha->fetchColumn();

        return [
            'sem_entrenamientos' => $entrenamientos,
            'sem_distancia'      => (int) floor($metros / 1000), // km enteros recorridos
            'sem_actividad_dias' => $diasActividad,
            'sem_hidratacion'    => $diasAgua,
            'sem_racha'          => $racha,
        ];
    }

    /** @return string[] */
    private function clavesCompletadas(int $usuarioId, string $periodo): array
    {
        $stmt = $this->db->prepare('SELECT reto_clave FROM retos_usuario WHERE usuario_id = :uid AND periodo = :p');
        $stmt->execute(['uid' => $usuarioId, 'p' => $periodo]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function completar(int $usuarioId, string $clave, string $periodo, int $xpRecompensa): ?array
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $usuarioId]);
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO retos_usuario (usuario_id, reto_clave, periodo, completado_en) VALUES (:uid, :c, :p, NOW())'
            );
            $stmt->execute(['uid' => $usuarioId, 'c' => $clave, 'p' => $periodo]);

            if ($stmt->rowCount() === 0) {
                if ($propia) $this->db->commit();
                return null; // ya registrado este período (carrera): no recompensa de nuevo
            }

            $xp = null;
            if ($xpRecompensa > 0) {
                $xp = (new SistemaXP($this->db))->otorgarXP($usuarioId, $xpRecompensa, false);
            }

            if ($propia) $this->db->commit();
            return $xp ?? ['xp_ganado' => 0, 'coins_ganados' => 0];
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
