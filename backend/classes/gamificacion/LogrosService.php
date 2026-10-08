<?php
declare(strict_types=1);

require_once __DIR__ . '/../SistemaXP.php';

/**
 * NutriFit — Logros reales y persistentes.
 * =====================================================================
 * Un logro se desbloquea automáticamente cuando el usuario cumple su
 * condición (evaluada contra datos REALES de la DB) y queda guardado en
 * `logros_usuario`. El UNIQUE (usuario_id, logro_clave) + el chequeo de
 * filas afectadas garantizan que:
 *   - un logro nunca se desbloquea dos veces;
 *   - su recompensa de XP se otorga EXACTAMENTE una vez (anti-farming
 *     absoluto, sin depender del frontend).
 *
 * evaluar() es idempotente: se puede llamar cuantas veces se quiera (al
 * abrir el perfil, después de una acción, etc.) y sólo desbloquea lo que
 * falte. También sirve para desbloqueo retroactivo de usuarios que ya
 * cumplían la condición antes de que existiera el sistema.
 *
 * Las CONDICIONES viven acá (condiciones()); los textos/recompensa en la
 * tabla `logros`. Las dos cosas comparten la `clave`.
 */
final class LogrosService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Desbloquea los logros recién cumplidos y devuelve los nuevos.
     * @return array<int, array<string, mixed>>
     */
    public function evaluar(int $usuarioId): array
    {
        (new SistemaXP($this->db))->actualizarRachaVigente($usuarioId);
        $stats = $this->calcularStats($usuarioId);
        $yaTiene = $this->clavesDesbloqueadas($usuarioId);
        $nuevos = [];

        foreach ($this->condiciones() as $clave => $cumple) {
            if (in_array($clave, $yaTiene, true)) {
                continue;
            }
            if ($cumple($stats)) {
                $logro = $this->desbloquear($usuarioId, $clave);
                if ($logro !== null) {
                    $nuevos[] = $logro;
                }
            }
        }

        return $nuevos;
    }

    /**
     * Lista completa del catálogo con el estado del usuario (desbloqueado
     * o no, y cuándo). Evalúa primero, así abrir la vista de logros ya
     * refleja cualquier logro recién cumplido.
     * @return array<int, array<string, mixed>>
     */
    public function listarConEstado(int $usuarioId, bool $evaluar = true): array
    {
        if ($evaluar) $this->evaluar($usuarioId);

        $stmt = $this->db->prepare(
            'SELECT l.clave, l.titulo, l.descripcion, l.icono, l.categoria, l.xp_recompensa, l.orden,
                    lu.desbloqueado_en
             FROM logros l
             LEFT JOIN logros_usuario lu ON lu.logro_clave = l.clave AND lu.usuario_id = :uid
             WHERE l.activo = 1
             ORDER BY l.orden ASC'
        );
        $stmt->execute(['uid' => $usuarioId]);
        $filas = $stmt->fetchAll();

        return array_map(static function ($f) {
            return [
                'clave' => $f['clave'],
                'titulo' => $f['titulo'],
                'descripcion' => $f['descripcion'],
                'icono' => $f['icono'],
                'categoria' => $f['categoria'],
                'xp_recompensa' => (int) $f['xp_recompensa'],
                'desbloqueado' => $f['desbloqueado_en'] !== null,
                'desbloqueado_en' => $f['desbloqueado_en'],
            ];
        }, $filas);
    }

    /**
     * Condiciones de desbloqueo, keyed por clave (misma que la tabla).
     * @return array<string, callable(array<string,int>): bool>
     */
    private function condiciones(): array
    {
        return [
            'primer_entrenamiento' => static fn (array $s) => $s['entrenamientos'] >= 1,
            'primera_actividad'    => static fn (array $s) => $s['actividades'] >= 1,
            'primera_comida'       => static fn (array $s) => $s['comidas'] >= 1,
            'meta_agua'            => static fn (array $s) => $s['dias_meta_agua'] >= 1,
            'racha_3'              => static fn (array $s) => $s['racha'] >= 3,
            'racha_7'              => static fn (array $s) => $s['racha'] >= 7,
            'entrenamientos_5'     => static fn (array $s) => $s['entrenamientos'] >= 5,
            'actividad_3_dias'     => static fn (array $s) => $s['dias_con_actividad'] >= 3,
        ];
    }

    /** @return array<string, int> */
    private function calcularStats(int $usuarioId): array
    {
        $uno = function (string $sql) use ($usuarioId): int {
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['uid' => $usuarioId]);
            return (int) $stmt->fetchColumn();
        };

        return [
            'entrenamientos'     => $uno('SELECT COUNT(*) FROM registros_entrenamiento WHERE usuario_id = :uid'),
            'actividades'        => $uno('SELECT COUNT(*) FROM registros_actividad WHERE usuario_id = :uid'),
            'comidas'            => $uno('SELECT COUNT(*) FROM registros_comidas WHERE usuario_id = :uid'),
            'dias_con_actividad' => $uno('SELECT COUNT(DISTINCT fecha) FROM registros_actividad WHERE usuario_id = :uid'),
            'dias_meta_agua'     => $uno('SELECT COUNT(*) FROM registros_hidratacion WHERE usuario_id = :uid AND vasos >= meta_vasos'),
            'racha'              => $uno('SELECT racha_dias FROM usuarios WHERE id = :uid'),
        ];
    }

    /** @return string[] */
    private function clavesDesbloqueadas(int $usuarioId): array
    {
        $stmt = $this->db->prepare('SELECT logro_clave FROM logros_usuario WHERE usuario_id = :uid');
        $stmt->execute(['uid' => $usuarioId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed>|null  null si ya lo tenía (carrera) o no existe en catálogo */
    private function desbloquear(int $usuarioId, string $clave): ?array
    {
        $stmtCat = $this->db->prepare('SELECT clave, titulo, descripcion, icono, xp_recompensa FROM logros WHERE clave = :c AND activo = 1');
        $stmtCat->execute(['c' => $clave]);
        $catalogo = $stmtCat->fetch();
        if (!$catalogo) {
            return null;
        }

        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $usuarioId]);
            $stmt = $this->db->prepare(
                'INSERT IGNORE INTO logros_usuario (usuario_id, logro_clave, desbloqueado_en) VALUES (:uid, :c, NOW())'
            );
            $stmt->execute(['uid' => $usuarioId, 'c' => $clave]);

            if ($stmt->rowCount() === 0) {
                // Otro request lo insertó primero: no se recompensa de nuevo.
                if ($propia) $this->db->commit();
                return null;
            }

            $xpResultado = null;
            $xpRecompensa = (int) $catalogo['xp_recompensa'];
            if ($xpRecompensa > 0) {
                // otorgarXP (no otorgarXPConLimite) corre dentro de esta
                // transacción; el UNIQUE de arriba ya garantiza "una vez".
                $xpResultado = (new SistemaXP($this->db))->otorgarXP($usuarioId, $xpRecompensa, false);
            }

            if ($propia) $this->db->commit();

            return [
                'clave' => $catalogo['clave'],
                'titulo' => $catalogo['titulo'],
                'descripcion' => $catalogo['descripcion'],
                'icono' => $catalogo['icono'],
                'xp_recompensa' => $xpRecompensa,
                'xp' => $xpResultado,
            ];
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
