<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PerfilDefaults.php';
require_once __DIR__ . '/CatalogoRestricciones.php';
require_once __DIR__ . '/ResumenReciente.php';
require_once __DIR__ . '/../FiltroAlimentario.php';
require_once __DIR__ . '/../ResumenSemanalService.php';

/**
 * NutriFit — Arma el ContextoUsuario con UNA sola consulta.
 * =====================================================================
 * Es el único lugar del proyecto que debería traducir "fila de
 * perfiles_biometricos" a "contexto del usuario", incluyendo qué pasa
 * cuando esa fila no existe.
 *
 * Dos modos de carga, a propósito:
 *   - obtener()            → sólo perfil + metas (1 consulta). Es lo que
 *                            usa dashboard_diario en el camino caliente.
 *   - obtenerConHistorial() → además agrega los últimos N días. Cuesta
 *                            más, así que sólo lo pide quien lo necesita
 *                            (el Motor de Personalización).
 *
 * El historial NO se recalcula acá: se delega en ResumenSemanalService,
 * que ya es el agregador oficial del proyecto (mismos promedios, mismo
 * criterio de "días con registro" que usa Mi Progreso).
 */
final class ContextoUsuarioRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function obtener(int $usuarioId): ContextoUsuario
    {
        $stmt = $this->db->prepare(
            'SELECT edad, genero, peso_actual, peso_objetivo, altura, objetivo, nivel_actividad,
                    lugar_entreno, tipo_dieta, alergias, meta_calorias, meta_proteinas,
                    meta_carbohidratos, meta_grasas, meta_fibra, meta_pasos_diaria
             FROM perfiles_biometricos WHERE usuario_id = :uid'
        );
        $stmt->execute(['uid' => $usuarioId]);
        $fila = $stmt->fetch();

        if (!$fila) {
            return $this->contextoSinPerfil($usuarioId);
        }

        $declaradas = FiltroAlimentario::normalizarRestricciones($fila['alergias']);
        $separadas = CatalogoRestricciones::separar($declaradas);

        return new ContextoUsuario(
            $usuarioId,
            true,
            $fila['edad'] !== null ? (int) $fila['edad'] : null,
            $fila['genero'] ?? null,
            $fila['peso_actual'] !== null ? (float) $fila['peso_actual'] : null,
            $fila['peso_objetivo'] !== null ? (float) $fila['peso_objetivo'] : null,
            $fila['altura'] !== null ? (float) $fila['altura'] : null,
            (string) ($fila['objetivo'] ?? PerfilDefaults::OBJETIVO),
            $fila['nivel_actividad'] ?? null,
            $fila['lugar_entreno'] ?? null,
            (string) ($fila['tipo_dieta'] ?? PerfilDefaults::TIPO_DIETA),
            $declaradas,
            $separadas['alimentarias'],
            $separadas['condiciones'],
            // Las metas pueden ser NULL en la fila (perfil viejo previo al
            // motor metabólico): ahí vale el mismo default de siempre.
            (int) ($fila['meta_calorias'] ?? PerfilDefaults::META_CALORIAS),
            (int) ($fila['meta_proteinas'] ?? PerfilDefaults::META_PROTEINAS),
            (int) ($fila['meta_carbohidratos'] ?? PerfilDefaults::META_CARBOHIDRATOS),
            (int) ($fila['meta_grasas'] ?? PerfilDefaults::META_GRASAS),
            $fila['meta_fibra'] !== null ? (int) $fila['meta_fibra'] : null,
            (int) ($fila['meta_pasos_diaria'] ?? PerfilDefaults::META_PASOS_DIARIA)
        );
    }

    /** Contexto + agregados de los últimos $dias días (incluye hoy). */
    public function obtenerConHistorial(int $usuarioId, int $dias = 7): ContextoUsuario
    {
        $contexto = $this->obtener($usuarioId);
        $contexto->reciente = $this->cargarReciente($usuarioId, $dias);

        return $contexto;
    }

    private function cargarReciente(int $usuarioId, int $dias): ResumenReciente
    {
        $hasta = date('Y-m-d');
        $desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));

        $resumen = (new ResumenSemanalService($this->db))->generar($usuarioId, $desde, $hasta);

        $actividad = $resumen['actividad'];
        $nutricion = $resumen['nutricion'];
        $hidratacion = $resumen['hidratacion'];
        $xp = $resumen['xp'];

        // Variación de peso dentro de la ventana: sólo si hay al menos dos
        // registros reales. Con uno solo no hay evolución que medir.
        $historialPeso = $resumen['peso']['historial'] ?? [];
        $pesoInicial = null;
        $variacion = null;
        if (count($historialPeso) >= 2) {
            $pesoInicial = (float) $historialPeso[0]['peso_registrado'];
            $pesoFinal = (float) $historialPeso[count($historialPeso) - 1]['peso_registrado'];
            $variacion = round($pesoFinal - $pesoInicial, 1);
        } elseif (count($historialPeso) === 1) {
            $pesoInicial = (float) $historialPeso[0]['peso_registrado'];
        }

        return new ResumenReciente(
            $dias,
            $desde,
            $hasta,
            (int) $actividad['pasos_totales'],
            (float) $actividad['pasos_promedio_diario'],
            (float) $actividad['distancia_metros_total'],
            (int) ($actividad['sesiones_totales'] ?? 0),
            (int) ($actividad['entrenamientos_completados'] ?? 0),
            (int) $nutricion['dias_con_registro'],
            $nutricion['calorias_promedio'] !== null ? (float) $nutricion['calorias_promedio'] : null,
            $nutricion['proteinas_promedio'] !== null ? (float) $nutricion['proteinas_promedio'] : null,
            (int) $hidratacion['dias_con_registro'],
            $hidratacion['vasos_promedio'] !== null ? (float) $hidratacion['vasos_promedio'] : null,
            (int) ($hidratacion['dias_meta_cumplida'] ?? 0),
            $pesoInicial,
            $variacion,
            (int) $xp['dias_con_actividad_en_rango'],
            (int) $xp['racha_dias']
        );
    }

    /** Usuario sin onboarding: mismos defaults que ya aplicaban los endpoints. */
    private function contextoSinPerfil(int $usuarioId): ContextoUsuario
    {
        return new ContextoUsuario(
            $usuarioId,
            false,
            null,
            null,
            null,
            null,
            null,
            PerfilDefaults::OBJETIVO,
            null,
            null,
            PerfilDefaults::TIPO_DIETA,
            PerfilDefaults::RESTRICCIONES,
            PerfilDefaults::RESTRICCIONES,
            [],
            PerfilDefaults::META_CALORIAS,
            PerfilDefaults::META_PROTEINAS,
            PerfilDefaults::META_CARBOHIDRATOS,
            PerfilDefaults::META_GRASAS,
            null,
            PerfilDefaults::META_PASOS_DIARIA
        );
    }
}
