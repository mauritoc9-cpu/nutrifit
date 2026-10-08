<?php
declare(strict_types=1);
require_once __DIR__ . '/LogrosService.php';
require_once __DIR__ . '/RetosService.php';

/** Una reconciliación por acción. Las reglas y UNIQUE siguen en los servicios existentes. */
final class GamificacionService
{
    public function __construct(private PDO $db) {}
    public function estado(int $uid): array
    {
        $stmt = $this->db->prepare('SELECT id, nombre, xp_total, nivel, nutri_coins, racha_dias FROM usuarios WHERE id = :uid');
        $stmt->execute(['uid' => $uid]);
        $estado = $stmt->fetch();
        foreach (['id', 'xp_total', 'nivel', 'nutri_coins', 'racha_dias'] as $campo) $estado[$campo] = (int) $estado[$campo];
        $estado['progreso_nivel'] = (new SistemaXP($this->db))->progresoNivelActual($estado['xp_total']);
        $stmt = $this->db->prepare('SELECT ti.tipo, ti.nombre FROM inventario_usuario iu JOIN tienda_items ti ON ti.id = iu.item_id WHERE iu.usuario_id = :uid AND iu.equipado = 1 AND ti.tipo IN ("ropa_avatar", "aura", "marco_perfil", "titulo")');
        $stmt->execute(['uid' => $uid]);
        $estado['equipados'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return $estado;
    }
    public function reconciliar(int $uid, ?array $xpAccion = null): array
    {
        $propia = !$this->db->inTransaction();
        if ($propia) $this->db->beginTransaction();
        try {
            $lock = $this->db->prepare('SELECT id FROM usuarios WHERE id = :uid FOR UPDATE');
            $lock->execute(['uid' => $uid]);
            $logrosService = new LogrosService($this->db);
            $logros = $logrosService->evaluar($uid);
            $retos = (new RetosService($this->db))->listar($uid);
            $xpAcciones = isset($xpAccion['xp_ganado']) ? [$xpAccion] : array_values(array_filter($xpAccion ?? [], 'is_array'));
            $premios = array_merge($xpAcciones, array_column($logros, 'xp'), array_column($retos['nuevos'], 'xp'));
            $resultado = [
                'logros_nuevos' => $logros, 'retos_nuevos' => $retos['nuevos'],
                'xp_otorgado' => array_sum(array_column(array_filter($premios, 'is_array'), 'xp_ganado')),
                'coins_otorgadas' => array_sum(array_column(array_filter($premios, 'is_array'), 'coins_ganados')),
                'subio_de_nivel' => count(array_filter($premios, static fn ($p) => !empty($p['subio_de_nivel']))) > 0,
                'estado' => $this->estado($uid),
                'logros' => $logrosService->listarConEstado($uid, false),
                'retos' => $retos,
            ];
            if ($propia) $this->db->commit();
            return $resultado;
        } catch (Throwable $e) {
            if ($propia && $this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
}
