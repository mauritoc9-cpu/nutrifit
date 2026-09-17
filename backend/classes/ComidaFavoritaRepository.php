<?php
declare(strict_types=1);

class ComidaFavoritaRepository {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function obtenerPorUsuario(int $usuarioId): array {
        $stmt = $this->db->prepare(
            'SELECT * FROM comidas_favoritas WHERE usuario_id = :uid ORDER BY ultima_usado DESC, nombre_personalizado ASC'
        );
        $stmt->execute(['uid' => $usuarioId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function obtenerPorId(int $favoritoId, int $usuarioId): ?array {
        $stmt = $this->db->prepare(
            'SELECT * FROM comidas_favoritas WHERE id = :id AND usuario_id = :uid'
        );
        $stmt->execute(['id' => $favoritoId, 'uid' => $usuarioId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function crear(
        int $usuarioId,
        string $nombrePersonalizado,
        ?int $alimentoId,
        float $gramosBase,
        string $unidad,
        float $caloriasBase,
        float $proteinasBase,
        float $carbohidratosBase,
        float $grasasBase,
        bool $esCompuesto = false,
        string $origen = 'manual',
        ?array $metadata = null
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO comidas_favoritas
            (usuario_id, nombre_personalizado, alimento_id, gramos_base, unidad,
             calorias_base, proteinas_base, carbohidratos_base, grasas_base,
             es_compuesto, origen, metadata)
            VALUES
            (:uid, :nombre, :alimento_id, :gramos, :unidad, :calorias, :proteinas, :carbohidratos, :grasas, :compuesto, :origen, :metadata)'
        );

        $stmt->execute([
            'uid' => $usuarioId,
            'nombre' => $nombrePersonalizado,
            'alimento_id' => $alimentoId,
            'gramos' => $gramosBase,
            'unidad' => $unidad,
            'calorias' => $caloriasBase,
            'proteinas' => $proteinasBase,
            'carbohidratos' => $carbohidratosBase,
            'grasas' => $grasasBase,
            'compuesto' => (int) $esCompuesto,
            'origen' => $origen,
            'metadata' => $metadata ? json_encode($metadata) : null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function actualizar(
        int $favoritoId,
        int $usuarioId,
        string $nombrePersonalizado,
        float $gramosBase,
        float $caloriasBase,
        float $proteinasBase,
        float $carbohidratosBase,
        float $grasasBase
    ): bool {
        $stmt = $this->db->prepare(
            'UPDATE comidas_favoritas
            SET nombre_personalizado = :nombre,
                gramos_base = :gramos,
                calorias_base = :calorias,
                proteinas_base = :proteinas,
                carbohidratos_base = :carbohidratos,
                grasas_base = :grasas
            WHERE id = :id AND usuario_id = :uid'
        );

        return $stmt->execute([
            'id' => $favoritoId,
            'uid' => $usuarioId,
            'nombre' => $nombrePersonalizado,
            'gramos' => $gramosBase,
            'calorias' => $caloriasBase,
            'proteinas' => $proteinasBase,
            'carbohidratos' => $carbohidratosBase,
            'grasas' => $grasasBase,
        ]);
    }

    public function eliminar(int $favoritoId, int $usuarioId): bool {
        $stmt = $this->db->prepare(
            'DELETE FROM comidas_favoritas WHERE id = :id AND usuario_id = :uid'
        );
        return $stmt->execute(['id' => $favoritoId, 'uid' => $usuarioId]);
    }

    public function existeDuplicado(int $usuarioId, string $nombrePersonalizado, ?int $excluidoId = null): bool {
        $sql = 'SELECT 1 FROM comidas_favoritas WHERE usuario_id = :uid AND nombre_personalizado = :nombre';
        $params = ['uid' => $usuarioId, 'nombre' => $nombrePersonalizado];

        if ($excluidoId !== null) {
            $sql .= ' AND id != :excluido';
            $params['excluido'] = $excluidoId;
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return (bool) $stmt->fetch();
    }

    public function registrarUso(int $favoritoId, int $usuarioId): void {
        $stmt = $this->db->prepare(
            'UPDATE comidas_favoritas SET veces_usado = veces_usado + 1, ultima_usado = NOW() WHERE id = :id AND usuario_id = :uid'
        );
        $stmt->execute(['id' => $favoritoId, 'uid' => $usuarioId]);
    }
}
