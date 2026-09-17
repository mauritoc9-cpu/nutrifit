<?php
declare(strict_types=1);

/**
 * Clase SistemaXP
 * Gestiona la suma de experiencia, el cálculo de nivel (fórmula
 * escalonada) y la racha diaria (streak) con su multiplicador.
 */
class SistemaXP
{
    private PDO $db;

    // XP base por acción, según la sección 2 del brief.
    public const XP_COMIDA        = 10;
    public const XP_HIDRATACION   = 5;   // al completar 3 vasos
    public const XP_ENTRENAMIENTO = 25;
    public const XP_PASOS         = 15;  // al alcanzar la meta diaria de pasos
    public const XP_ACTIVIDAD_SESION = 20; // al completar una sesión válida de actividad física (ver CriteriosActividadXP)
    public const BONO_RACHA_PORCENTAJE = 0.10; // +10% por día consecutivo

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Fórmula escalonada de nivel: cada nivel requiere más XP que el anterior.
     * XP requerido para pasar de nivel N a N+1 = 100 * N^1.4 (redondeado).
     */
    public function xpRequeridoParaNivel(int $nivel): int
    {
        return (int) round(100 * ($nivel ** 1.4));
    }

    /** Dado un XP total acumulado, determina el nivel actual. */
    public function calcularNivelDesdeXP(int $xpTotal): int
    {
        $nivel = 1;
        $xpRestante = $xpTotal;

        while ($xpRestante >= $this->xpRequeridoParaNivel($nivel)) {
            $xpRestante -= $this->xpRequeridoParaNivel($nivel);
            $nivel++;
        }

        return $nivel;
    }

    /** Progreso dentro del nivel actual, útil para la barra de XP del frontend. */
    public function progresoNivelActual(int $xpTotal): array
    {
        $nivel = $this->calcularNivelDesdeXP($xpTotal);
        $xpConsumido = 0;
        for ($i = 1; $i < $nivel; $i++) {
            $xpConsumido += $this->xpRequeridoParaNivel($i);
        }

        $xpEnNivel = $xpTotal - $xpConsumido;
        $xpParaSiguiente = $this->xpRequeridoParaNivel($nivel);

        return [
            'nivel'            => $nivel,
            'xp_en_nivel'      => $xpEnNivel,
            'xp_para_siguiente'=> $xpParaSiguiente,
            'porcentaje'       => $xpParaSiguiente > 0
                ? round(($xpEnNivel / $xpParaSiguiente) * 100, 1)
                : 0,
        ];
    }

    /**
     * Suma XP a un usuario, aplica el multiplicador de racha, recalcula
     * nivel y actualiza la racha diaria. Devuelve el nuevo estado.
     */
    public function otorgarXP(int $usuarioId, int $xpBase): array
    {
        $stmt = $this->db->prepare('SELECT xp_total, nivel, racha_dias, ultima_actividad, nutri_coins FROM usuarios WHERE id = :id');
        $stmt->execute(['id' => $usuarioId]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            throw new RuntimeException('Usuario no encontrado.');
        }

        [$nuevaRacha, $rachaContinua] = $this->calcularRacha(
            (int) $usuario['racha_dias'],
            $usuario['ultima_actividad']
        );

        $multiplicador = 1 + ($nuevaRacha * self::BONO_RACHA_PORCENTAJE);
        $xpGanado = (int) round($xpBase * $multiplicador);

        $nuevoXPTotal = (int) $usuario['xp_total'] + $xpGanado;
        $nuevoNivel = $this->calcularNivelDesdeXP($nuevoXPTotal);
        $subioDeNivel = $nuevoNivel > (int) $usuario['nivel'];

        // Otorga NutriCoins extra al subir de nivel (recompensa de progresión).
        $coinsGanados = $subioDeNivel ? 100 * ($nuevoNivel - (int) $usuario['nivel']) : 0;

        $update = $this->db->prepare(
            'UPDATE usuarios
             SET xp_total = :xp_total, nivel = :nivel, racha_dias = :racha,
                 ultima_actividad = CURDATE(), nutri_coins = nutri_coins + :coins
             WHERE id = :id'
        );
        $update->execute([
            'xp_total' => $nuevoXPTotal,
            'nivel'    => $nuevoNivel,
            'racha'    => $nuevaRacha,
            'coins'    => $coinsGanados,
            'id'       => $usuarioId,
        ]);

        return [
            'xp_ganado'     => $xpGanado,
            'multiplicador' => round($multiplicador, 2),
            'xp_total'      => $nuevoXPTotal,
            'nivel'         => $nuevoNivel,
            'subio_de_nivel'=> $subioDeNivel,
            'coins_ganados' => $coinsGanados,
            'racha_dias'    => $nuevaRacha,
            'progreso'      => $this->progresoNivelActual($nuevoXPTotal),
        ];
    }

    /**
     * Determina la nueva racha comparando la última fecha de actividad con hoy:
     * - Si fue ayer, la racha continúa (+1).
     * - Si fue hoy, se mantiene igual (ya sumó actividad hoy).
     * - Si fue antes de ayer o nunca, la racha se reinicia a 1.
     */
    private function calcularRacha(int $rachaActual, ?string $ultimaActividad): array
    {
        $hoy = new DateTimeImmutable('today');

        if ($ultimaActividad === null) {
            return [1, false];
        }

        $ultima = new DateTimeImmutable($ultimaActividad);
        $diferenciaDias = (int) $hoy->diff($ultima)->format('%a');

        if ($ultima->format('Y-m-d') === $hoy->format('Y-m-d')) {
            return [$rachaActual, true]; // ya registró actividad hoy
        }

        if ($diferenciaDias === 1) {
            return [$rachaActual + 1, true]; // día consecutivo
        }

        return [1, false]; // racha rota, reinicia
    }
}
