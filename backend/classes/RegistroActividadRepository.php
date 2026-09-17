<?php
declare(strict_types=1);

/**
 * NutriFit — Acceso a datos del módulo de Actividad Física.
 *
 * `registros_actividad` guarda una fila por SESIÓN (igual a como reportan
 * Health Connect/HealthKit), con dos excepciones controladas que acumulan
 * sobre la fila del día en vez de insertar una fila nueva por llamado:
 * el pedómetro web (que manda pasos cada pocos segundos) y la carga
 * manual rápida de pasos (+500/+1000). Es la MISMA tabla — no hay una
 * segunda fuente de verdad — solo cambia si se hace INSERT o UPDATE según
 * el caso de uso.
 */
class RegistroActividadRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** @return array<int, array<string, mixed>> */
    public function obtenerTipos(): array
    {
        return $this->db->query('SELECT * FROM tipos_actividad WHERE activo = 1 ORDER BY id')->fetchAll();
    }

    public function obtenerTipoPorClave(string $clave): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tipos_actividad WHERE clave = :clave AND activo = 1');
        $stmt->execute(['clave' => $clave]);
        return $stmt->fetch() ?: null;
    }

    public function obtenerTipoPorId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tipos_actividad WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** Altura/peso con defaults seguros — mismo criterio que ya usaba `pasos.php` si no hay onboarding completo. */
    public function obtenerAlturaPeso(int $usuarioId): array
    {
        $stmt = $this->db->prepare('SELECT altura, peso_actual FROM perfiles_biometricos WHERE usuario_id = :uid');
        $stmt->execute(['uid' => $usuarioId]);
        $perfil = $stmt->fetch();

        return [
            'altura' => $perfil ? (float) $perfil['altura'] : 170.0,
            'peso' => $perfil ? (float) $perfil['peso_actual'] : 70.0,
        ];
    }

    public function obtenerMetaPasos(int $usuarioId): int
    {
        $stmt = $this->db->prepare('SELECT meta_pasos_diaria FROM perfiles_biometricos WHERE usuario_id = :uid');
        $stmt->execute(['uid' => $usuarioId]);
        $valor = $stmt->fetchColumn();
        return $valor !== false ? (int) $valor : 10000;
    }

    /** Para responder de forma idempotente si Health Connect/HealthKit reenvía la misma sesión. */
    public function buscarPorFuenteExterna(int $usuarioId, string $fuente, string $fuenteRegistroId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM registros_actividad WHERE usuario_id = :uid AND fuente = :fuente AND fuente_registro_id = :fid'
        );
        $stmt->execute(['uid' => $usuarioId, 'fuente' => $fuente, 'fid' => $fuenteRegistroId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Inserta una sesión nueva. Puede tirar PDOException por la UNIQUE key
     * (usuario_id, fuente, fuente_registro_id) si es un duplicado real de
     * sincronización — lo captura quien orquesta (el endpoint), no acá,
     * para poder responder distinto según el caso.
     */
    public function insertarSesion(array $datos): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO registros_actividad
               (usuario_id, tipo_actividad_id, fecha, hora_inicio, duracion_segundos, pasos,
                distancia_metros, velocidad_media_kmh, ritmo_seg_por_km, calorias_kcal,
                fuente, fuente_registro_id)
             VALUES
               (:usuario_id, :tipo_actividad_id, :fecha, :hora_inicio, :duracion_segundos, :pasos,
                :distancia_metros, :velocidad_media_kmh, :ritmo_seg_por_km, :calorias_kcal,
                :fuente, :fuente_registro_id)'
        );
        $stmt->execute([
            'usuario_id' => $datos['usuario_id'],
            'tipo_actividad_id' => $datos['tipo_actividad_id'],
            'fecha' => $datos['fecha'],
            'hora_inicio' => $datos['hora_inicio'] ?? null,
            'duracion_segundos' => $datos['duracion_segundos'] ?? null,
            'pasos' => $datos['pasos'] ?? null,
            'distancia_metros' => $datos['distancia_metros'] ?? null,
            'velocidad_media_kmh' => $datos['velocidad_media_kmh'] ?? null,
            'ritmo_seg_por_km' => $datos['ritmo_seg_por_km'] ?? null,
            'calorias_kcal' => $datos['calorias_kcal'] ?? null,
            'fuente' => $datos['fuente'],
            'fuente_registro_id' => $datos['fuente_registro_id'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function marcarXPOtorgado(int $registroId): void
    {
        $this->db->prepare('UPDATE registros_actividad SET xp_otorgado = 1 WHERE id = :id')
            ->execute(['id' => $registroId]);
    }

    /** Cuenta cuántas sesiones YA otorgaron XP hoy — protección anti-farming (ver CriteriosActividadXP). */
    public function contarSesionesConXPHoy(int $usuarioId, string $fecha): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM registros_actividad WHERE usuario_id = :uid AND fecha = :fecha AND xp_otorgado = 1'
        );
        $stmt->execute(['uid' => $usuarioId, 'fecha' => $fecha]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Upsert de la fila "acumulada del día" para fuentes locales sin id
     * externo (pedómetro web / carga manual rápida de pasos) — mantiene el
     * comportamiento de "+500 pasos" de siempre sin crear una fila nueva
     * por cada click o cada flush del sensor.
     *
     * @return array{id: int, pasos: int, distancia_metros: float, calorias_kcal: float}
     */
    public function sumarPasosLocal(
        int $usuarioId,
        string $fecha,
        int $tipoActividadId,
        string $fuente,
        int $cantidadPasos,
        float $distanciaAdicionalMetros,
        float $caloriasAdicionales
    ): array {
        $stmt = $this->db->prepare(
            'SELECT id, pasos, distancia_metros, calorias_kcal FROM registros_actividad
             WHERE usuario_id = :uid AND fecha = :fecha AND tipo_actividad_id = :tipo
               AND fuente = :fuente AND fuente_registro_id IS NULL
             LIMIT 1'
        );
        $stmt->execute(['uid' => $usuarioId, 'fecha' => $fecha, 'tipo' => $tipoActividadId, 'fuente' => $fuente]);
        $existente = $stmt->fetch();

        if ($existente) {
            $nuevosPasos = (int) $existente['pasos'] + $cantidadPasos;
            $nuevaDistancia = (float) $existente['distancia_metros'] + $distanciaAdicionalMetros;
            $nuevasCalorias = (float) $existente['calorias_kcal'] + $caloriasAdicionales;

            $this->db->prepare(
                'UPDATE registros_actividad
                 SET pasos = :pasos, distancia_metros = :distancia, calorias_kcal = :calorias
                 WHERE id = :id'
            )->execute([
                'pasos' => $nuevosPasos,
                'distancia' => $nuevaDistancia,
                'calorias' => $nuevasCalorias,
                'id' => $existente['id'],
            ]);

            return ['id' => (int) $existente['id'], 'pasos' => $nuevosPasos, 'distancia_metros' => $nuevaDistancia, 'calorias_kcal' => $nuevasCalorias];
        }

        $id = $this->insertarSesion([
            'usuario_id' => $usuarioId,
            'tipo_actividad_id' => $tipoActividadId,
            'fecha' => $fecha,
            'pasos' => $cantidadPasos,
            'distancia_metros' => $distanciaAdicionalMetros,
            'calorias_kcal' => $caloriasAdicionales,
            'fuente' => $fuente,
        ]);

        return ['id' => $id, 'pasos' => $cantidadPasos, 'distancia_metros' => $distanciaAdicionalMetros, 'calorias_kcal' => $caloriasAdicionales];
    }

    /** Resumen del día: pasos totales (caminata+carrera) + totales por sesión, para Home y para el wrapper de `pasos.php`. */
    public function resumenDia(int $usuarioId, string $fecha): array
    {
        return $this->resumenPorCondicion($usuarioId, 'ra.fecha = :fecha', ['fecha' => $fecha]);
    }

    /** Mismo agregado que resumenDia pero sobre un rango de fechas (inclusive) — usado por el resumen semanal de Mi Progreso. */
    public function resumenRango(int $usuarioId, string $desde, string $hasta): array
    {
        return $this->resumenPorCondicion($usuarioId, 'ra.fecha BETWEEN :desde AND :hasta', ['desde' => $desde, 'hasta' => $hasta]);
    }

    /** @param array<string, string> $parametrosFecha */
    private function resumenPorCondicion(int $usuarioId, string $condicionFecha, array $parametrosFecha): array
    {
        $stmt = $this->db->prepare(
            "SELECT ta.clave,
                    COALESCE(SUM(ra.pasos), 0) AS pasos,
                    COALESCE(SUM(ra.distancia_metros), 0) AS distancia_metros,
                    COALESCE(SUM(ra.calorias_kcal), 0) AS calorias_kcal,
                    COALESCE(SUM(ra.duracion_segundos), 0) AS duracion_segundos,
                    COUNT(*) AS sesiones
             FROM registros_actividad ra
             JOIN tipos_actividad ta ON ta.id = ra.tipo_actividad_id
             WHERE ra.usuario_id = :uid AND $condicionFecha
             GROUP BY ta.clave"
        );
        $stmt->execute(array_merge(['uid' => $usuarioId], $parametrosFecha));
        $porTipo = $stmt->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);

        $pasosTotales = (int) (($porTipo['caminata']['pasos'] ?? 0) + ($porTipo['carrera']['pasos'] ?? 0));
        $caloriasTotales = array_sum(array_column($porTipo, 'calorias_kcal'));
        $distanciaTotales = array_sum(array_column($porTipo, 'distancia_metros'));
        $duracionTotal = array_sum(array_column($porTipo, 'duracion_segundos'));

        return [
            'pasos' => $pasosTotales,
            'calorias_kcal' => round((float) $caloriasTotales, 1),
            'distancia_metros' => round((float) $distanciaTotales, 1),
            'duracion_segundos' => (int) $duracionTotal,
            'por_tipo' => $porTipo,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function historial(int $usuarioId, int $limite = 30): array
    {
        $stmt = $this->db->prepare(
            'SELECT ra.*, ta.clave AS tipo_clave, ta.nombre AS tipo_nombre
             FROM registros_actividad ra
             JOIN tipos_actividad ta ON ta.id = ra.tipo_actividad_id
             WHERE ra.usuario_id = :uid
             ORDER BY ra.fecha DESC, ra.creado_en DESC
             LIMIT :lim'
        );
        $stmt->bindValue('uid', $usuarioId, PDO::PARAM_INT);
        $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
