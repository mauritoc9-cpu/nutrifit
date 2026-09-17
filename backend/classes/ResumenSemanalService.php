<?php
declare(strict_types=1);

require_once __DIR__ . '/RegistroActividadRepository.php';

/**
 * NutriFit — Resumen agregado para Mi Progreso (semanal o del rango que se
 * pida). NO recalcula nada por su cuenta: junta lo que ya calculan las
 * mismas tablas/fuentes que usan Home y Nutrición día a día
 * (`registros_actividad`, `registros_comidas`, `registros_hidratacion`,
 * `progreso_peso`, `usuarios`) agregado sobre un rango de fechas. Si un
 * dato no se puede obtener de verdad (ej. "XP ganado en el rango", que no
 * existe como historial — solo hay un total acumulado), no se inventa: se
 * devuelve la métrica real disponible más cercana, aclarada como tal.
 */
class ResumenSemanalService
{
    private PDO $db;
    private RegistroActividadRepository $actividadRepo;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->actividadRepo = new RegistroActividadRepository($db);
    }

    public function generar(int $usuarioId, string $desde, string $hasta): array
    {
        $desdeDt = new DateTimeImmutable($desde);
        $hastaDt = new DateTimeImmutable($hasta);
        $dias = $hastaDt->diff($desdeDt)->days + 1;

        return [
            'rango' => ['desde' => $desde, 'hasta' => $hasta, 'dias' => $dias],
            'actividad' => $this->resumenActividad($usuarioId, $desde, $hasta, $dias),
            'nutricion' => $this->resumenNutricion($usuarioId, $desde, $hasta),
            'hidratacion' => $this->resumenHidratacion($usuarioId, $desde, $hasta),
            'peso' => $this->resumenPeso($usuarioId, $desde, $hasta),
            'xp' => $this->resumenXP($usuarioId, $desde, $hasta),
        ];
    }

    /** Misma agregación que ya usa resumenDia/pasos.php (RegistroActividadRepository), solo que sobre el rango completo.
     *  Suma (aditivo, sin tocar el contrato previo): cantidad de sesiones totales, entrenamientos de rutina
     *  completados en el rango (tabla `registros_entrenamiento`, ya usada por completar_entrenamiento.php/
     *  rutina_recomendada.php — no es una fuente nueva) y un desglose por día (para graficar distribución). */
    private function resumenActividad(int $usuarioId, string $desde, string $hasta, int $dias): array
    {
        $resumen = $this->actividadRepo->resumenRango($usuarioId, $desde, $hasta);
        $sesionesTotales = (int) array_sum(array_column($resumen['por_tipo'], 'sesiones'));

        $stmtEntrenos = $this->db->prepare(
            'SELECT COUNT(*) FROM registros_entrenamiento WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta'
        );
        $stmtEntrenos->execute(['uid' => $usuarioId, 'desde' => $desde, 'hasta' => $hasta]);

        $stmtPorDia = $this->db->prepare(
            'SELECT fecha, COALESCE(SUM(pasos), 0) AS pasos, COALESCE(SUM(calorias_kcal), 0) AS calorias_kcal, COUNT(*) AS sesiones
             FROM registros_actividad WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta
             GROUP BY fecha ORDER BY fecha ASC'
        );
        $stmtPorDia->execute(['uid' => $usuarioId, 'desde' => $desde, 'hasta' => $hasta]);

        return [
            'pasos_totales' => $resumen['pasos'],
            'pasos_promedio_diario' => round($resumen['pasos'] / $dias, 1),
            'distancia_metros_total' => $resumen['distancia_metros'],
            'minutos_totales' => round($resumen['duracion_segundos'] / 60, 1),
            'calorias_totales' => $resumen['calorias_kcal'],
            'por_tipo' => $resumen['por_tipo'],
            'sesiones_totales' => $sesionesTotales,
            'entrenamientos_completados' => (int) $stmtEntrenos->fetchColumn(),
            'por_dia' => $stmtPorDia->fetchAll(),
        ];
    }

    /**
     * Promedio SOLO sobre los días que el usuario realmente registró algo
     * — un día sin ningún alimento cargado no es "0 calorías", es "sin
     * dato", y promediarlo como cero sería inventar información.
     */
    private function resumenNutricion(int $usuarioId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT fecha, SUM(calorias_totales) AS cal, SUM(proteinas_totales) AS prot,
                    SUM(carbohidratos_totales) AS carb, SUM(grasas_totales) AS gras
             FROM registros_comidas
             WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta
             GROUP BY fecha'
        );
        $stmt->execute(['uid' => $usuarioId, 'desde' => $desde, 'hasta' => $hasta]);
        $porDia = $stmt->fetchAll();

        $stmtMeta = $this->db->prepare('SELECT meta_calorias, meta_proteinas, meta_carbohidratos, meta_grasas FROM perfiles_biometricos WHERE usuario_id = :uid');
        $stmtMeta->execute(['uid' => $usuarioId]);
        $meta = $stmtMeta->fetch() ?: null;
        $metas = [
            'meta_calorias' => $meta['meta_calorias'] ?? null,
            'meta_proteinas' => $meta['meta_proteinas'] ?? null,
            'meta_carbohidratos' => $meta['meta_carbohidratos'] ?? null,
            'meta_grasas' => $meta['meta_grasas'] ?? null,
        ];

        $diasConRegistro = count($porDia);
        if ($diasConRegistro === 0) {
            return array_merge([
                'dias_con_registro' => 0,
                'calorias_promedio' => null,
                'proteinas_promedio' => null,
                'carbohidratos_promedio' => null,
                'grasas_promedio' => null,
            ], $metas);
        }

        $sumar = fn (string $campo) => array_sum(array_column($porDia, $campo));

        return array_merge([
            'dias_con_registro' => $diasConRegistro,
            'calorias_promedio' => round($sumar('cal') / $diasConRegistro, 1),
            'proteinas_promedio' => round($sumar('prot') / $diasConRegistro, 1),
            'carbohidratos_promedio' => round($sumar('carb') / $diasConRegistro, 1),
            'grasas_promedio' => round($sumar('gras') / $diasConRegistro, 1),
        ], $metas);
    }

    /** Mismo criterio que nutrición: promedio sobre días con registro, no sobre todo el rango. */
    private function resumenHidratacion(int $usuarioId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT vasos, meta_vasos FROM registros_hidratacion WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta'
        );
        $stmt->execute(['uid' => $usuarioId, 'desde' => $desde, 'hasta' => $hasta]);
        $filas = $stmt->fetchAll();

        if ($filas === []) {
            return ['dias_con_registro' => 0, 'vasos_promedio' => null, 'ml_promedio' => null, 'meta_vasos' => 8, 'dias_meta_cumplida' => 0];
        }

        $vasosPromedio = array_sum(array_column($filas, 'vasos')) / count($filas);
        $diasMetaCumplida = count(array_filter($filas, fn ($f) => (int) $f['vasos'] >= (int) $f['meta_vasos']));

        return [
            'dias_con_registro' => count($filas),
            'vasos_promedio' => round($vasosPromedio, 1),
            'ml_promedio' => round($vasosPromedio * 250, 0),
            'meta_vasos' => (int) $filas[count($filas) - 1]['meta_vasos'],
            'dias_meta_cumplida' => $diasMetaCumplida,
        ];
    }

    /** Historial crudo del rango + objetivo — el cálculo de variación/tendencia lo hace el frontend (misma función que ya usa Home, sin duplicarla en el backend). */
    private function resumenPeso(int $usuarioId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare(
            'SELECT peso_registrado, fecha FROM progreso_peso WHERE usuario_id = :uid AND fecha BETWEEN :desde AND :hasta ORDER BY fecha ASC'
        );
        $stmt->execute(['uid' => $usuarioId, 'desde' => $desde, 'hasta' => $hasta]);
        $historial = $stmt->fetchAll();

        $stmtMetas = $this->db->prepare('SELECT peso_actual, peso_objetivo FROM perfiles_biometricos WHERE usuario_id = :uid');
        $stmtMetas->execute(['uid' => $usuarioId]);
        $metas = $stmtMetas->fetch() ?: null;

        return [
            'historial' => $historial,
            'peso_actual' => $metas['peso_actual'] ?? null,
            'peso_objetivo' => $metas['peso_objetivo'] ?? null,
        ];
    }

    /**
     * XP/nivel/racha son un ACUMULADO total, no hay un historial de "XP
     * ganado por día" guardado en ningún lado — por eso esto es una foto
     * del estado ACTUAL, no "lo ganado en el rango" (eso sería inventado).
     * Lo único real y propio del rango es cuántos días hubo algún
     * registro (proxy honesto de constancia, no de XP).
     */
    private function resumenXP(int $usuarioId, string $desde, string $hasta): array
    {
        $stmt = $this->db->prepare('SELECT xp_total, nivel, racha_dias FROM usuarios WHERE id = :uid');
        $stmt->execute(['uid' => $usuarioId]);
        $usuario = $stmt->fetch() ?: ['xp_total' => 0, 'nivel' => 1, 'racha_dias' => 0];

        $stmtDias = $this->db->prepare(
            'SELECT COUNT(DISTINCT fecha) FROM (
                SELECT fecha FROM registros_actividad WHERE usuario_id = :uid1 AND fecha BETWEEN :desde1 AND :hasta1
                UNION SELECT fecha FROM registros_comidas WHERE usuario_id = :uid2 AND fecha BETWEEN :desde2 AND :hasta2
                UNION SELECT fecha FROM registros_hidratacion WHERE usuario_id = :uid3 AND fecha BETWEEN :desde3 AND :hasta3
             ) dias_activos'
        );
        $stmtDias->execute([
            'uid1' => $usuarioId, 'desde1' => $desde, 'hasta1' => $hasta,
            'uid2' => $usuarioId, 'desde2' => $desde, 'hasta2' => $hasta,
            'uid3' => $usuarioId, 'desde3' => $desde, 'hasta3' => $hasta,
        ]);

        return [
            'xp_total' => (int) $usuario['xp_total'],
            'nivel' => (int) $usuario['nivel'],
            'racha_dias' => (int) $usuario['racha_dias'],
            'dias_con_actividad_en_rango' => (int) $stmtDias->fetchColumn(),
        ];
    }
}
