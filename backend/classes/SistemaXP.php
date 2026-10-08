<?php
declare(strict_types=1);

class SistemaXP
{
    private PDO $db;
    public const XP_COMIDA = 10;
    public const XP_HIDRATACION = 5;
    public const XP_ENTRENAMIENTO = 25;
    public const XP_PASOS = 15;
    public const XP_ACTIVIDAD_SESION = 20;
    public const BONO_RACHA_PORCENTAJE = 0.10;
    public const MAX_COMIDA_XP_DIA = 6;
    public const MAX_HIDRATACION_XP_DIA = 4;
    public function __construct(PDO $db) { $this->db = $db; }
    public function xpRequeridoParaNivel(int $nivel): int { return (int) round(100 * ($nivel ** 1.4)); }
    public function calcularNivelDesdeXP(int $xpTotal): int {
        $nivel = 1;
        while ($xpTotal >= $this->xpRequeridoParaNivel($nivel)) {
            $xpTotal -= $this->xpRequeridoParaNivel($nivel++);
        }
        return $nivel;
    }
    public function progresoNivelActual(int $xpTotal): array {
        $nivel = $this->calcularNivelDesdeXP($xpTotal);
        $consumido = 0;
        for ($i = 1; $i < $nivel; $i++) $consumido += $this->xpRequeridoParaNivel($i);
        $enNivel = $xpTotal - $consumido;
        $siguiente = $this->xpRequeridoParaNivel($nivel);
        return ['nivel' => $nivel, 'xp_en_nivel' => $enNivel, 'xp_para_siguiente' => $siguiente,
            'porcentaje' => round($enNivel / $siguiente * 100, 1)];
    }
    // Una racha se mantiene hasta ayer; leer/recompensar un logro no crea actividad.
    public function rachaVigente(int $racha, ?string $ultimaActividad): int {
        $hoy = new DateTimeImmutable('today');
        return $ultimaActividad !== null && in_array($ultimaActividad,
            [$hoy->format('Y-m-d'), $hoy->modify('-1 day')->format('Y-m-d')], true) ? max(0, $racha) : 0;
    }
    public function actualizarRachaVigente(int $usuarioId): int {
        $stmt = $this->db->prepare('UPDATE usuarios SET racha_dias = 0 WHERE id = :id AND (ultima_actividad IS NULL OR ultima_actividad < DATE_SUB(CURDATE(), INTERVAL 1 DAY) OR ultima_actividad > CURDATE())');
        $stmt->execute(['id' => $usuarioId]);
        $stmt = $this->db->prepare('SELECT racha_dias FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $usuarioId]);
        return (int) $stmt->fetchColumn();
    }
    // Compatible con transacciones de compras, registros y logros. Siempre bloquea usuario.
    public function otorgarXP(int $usuarioId, int $xpBase, bool $cuentaActividad = true): array {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT xp_total, nivel, nivel_maximo_alcanzado, racha_dias, ultima_actividad FROM usuarios WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $usuarioId]);
            $u = $stmt->fetch();
            if (!$u || $xpBase <= 0) throw new RuntimeException('Otorgamiento de XP inválido.');
            $racha = $this->rachaVigente((int) $u['racha_dias'], $u['ultima_actividad']);
            if ($cuentaActividad && $u['ultima_actividad'] !== date('Y-m-d')) $racha++;
            if ($cuentaActividad) $racha = max(1, $racha);
            $multiplicador = 1 + $racha * self::BONO_RACHA_PORCENTAJE;
            $ganado = (int) round($xpBase * $multiplicador);
            $total = (int) $u['xp_total'] + $ganado;
            $nivel = $this->calcularNivelDesdeXP($total);
            $maximo = max((int) $u['nivel_maximo_alcanzado'], (int) $u['nivel']);
            $coins = 100 * max(0, $nivel - $maximo);
            $sql = 'UPDATE usuarios SET xp_total = :xp, nivel = :nivel, nivel_maximo_alcanzado = :maximo, racha_dias = :racha, nutri_coins = nutri_coins + :coins';
            if ($cuentaActividad) $sql .= ', ultima_actividad = CURDATE()';
            $sql .= ' WHERE id = :id';
            $this->db->prepare($sql)->execute(['xp' => $total, 'nivel' => $nivel, 'maximo' => max($maximo, $nivel), 'racha' => $racha, 'coins' => $coins, 'id' => $usuarioId]);
            if ($propia) $this->db->commit();
            return ['xp_ganado' => $ganado, 'multiplicador' => round($multiplicador, 2), 'xp_total' => $total,
                'nivel' => $nivel, 'subio_de_nivel' => $nivel > (int) $u['nivel'], 'coins_ganados' => $coins,
                'racha_dias' => $racha, 'progreso' => $this->progresoNivelActual($total)];
        } catch (Throwable $e) { if ($propia && $this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
    public function otorgarXPConLimite(int $usuarioId, int $xpBase, string $categoria, int $maxPorDia): ?array {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            // Orden de bloqueo común a todos los premios y compras: usuario primero.
            $lock = $this->db->prepare('SELECT id FROM usuarios WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $usuarioId]);
            $stmt = $this->db->prepare('INSERT IGNORE INTO xp_otorgado_diario (usuario_id, fecha, categoria, veces) VALUES (:uid, CURDATE(), :cat, 0)');
            $stmt->execute(['uid' => $usuarioId, 'cat' => $categoria]);
            $stmt = $this->db->prepare('UPDATE xp_otorgado_diario SET veces = veces + 1 WHERE usuario_id = :uid AND fecha = CURDATE() AND categoria = :cat AND veces < :maximo');
            $stmt->execute(['uid' => $usuarioId, 'cat' => $categoria, 'maximo' => $maxPorDia]);
            $resultado = $stmt->rowCount() === 1 ? $this->otorgarXP($usuarioId, $xpBase) : null;
            if ($propia) $this->db->commit();
            return $resultado;
        } catch (Throwable $e) { if ($propia && $this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
