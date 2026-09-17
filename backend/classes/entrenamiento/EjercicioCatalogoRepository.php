<?php
declare(strict_types=1);

/**
 * NutriFit — Acceso al catálogo maestro de ejercicios.
 * =====================================================================
 * Es la única puerta de entrada a `ejercicios_catalogo`. Encapsula los
 * filtros de compatibilidad (lugar / equipamiento / nivel) para que
 * ningún módulo tenga que repetir esas reglas en SQL suelto.
 */
final class EjercicioCatalogoRepository
{
    /**
     * Equipamiento realmente disponible por lugar de entrenamiento.
     * "casa" = sin equipamiento de gimnasio: sólo el propio cuerpo y
     * objetos domésticos. Esto es lo que hace que la diferencia casa vs
     * gimnasio sea REAL y no cosmética.
     */
    private const EQUIPAMIENTO_POR_LUGAR = [
        'casa' => ['ninguno', 'silla', 'esterilla'],
        'gimnasio' => ['ninguno', 'silla', 'esterilla', 'mancuernas', 'barra', 'maquina', 'banco'],
    ];

    /** Un nivel habilita el suyo y los anteriores (nunca los superiores). */
    private const NIVELES_PERMITIDOS = [
        'principiante' => ['principiante'],
        'intermedio' => ['principiante', 'intermedio'],
        'avanzado' => ['principiante', 'intermedio', 'avanzado'],
    ];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Ejercicios compatibles con el lugar y el nivel del usuario.
     *
     * @return array<int, array<string, mixed>>
     */
    public function compatibles(string $lugar, string $nivel, bool $excluirAvanzado = false): array
    {
        $equipamiento = self::EQUIPAMIENTO_POR_LUGAR[$lugar] ?? self::EQUIPAMIENTO_POR_LUGAR['casa'];
        $niveles = self::NIVELES_PERMITIDOS[$nivel] ?? self::NIVELES_PERMITIDOS['principiante'];

        if ($excluirAvanzado) {
            $niveles = array_values(array_diff($niveles, ['avanzado']));
            if ($niveles === []) {
                $niveles = ['principiante'];
            }
        }

        // `lugar` del catálogo: 'ambos' sirve siempre; si no, tiene que
        // coincidir exactamente con el lugar del usuario.
        $phEquip = implode(',', array_fill(0, count($equipamiento), '?'));
        $phNivel = implode(',', array_fill(0, count($niveles), '?'));

        $sql = "SELECT * FROM ejercicios_catalogo
                WHERE activo = 1
                  AND (lugar = 'ambos' OR lugar = ?)
                  AND equipamiento IN ($phEquip)
                  AND nivel IN ($phNivel)
                ORDER BY grupo_muscular, nivel, id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_merge([$lugar], $equipamiento, $niveles));

        return array_map([$this, 'hidratar'], $stmt->fetchAll());
    }

    public function porId(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ejercicios_catalogo WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $fila = $stmt->fetch();

        return $fila ? $this->hidratar($fila) : null;
    }

    public function porClave(string $clave): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM ejercicios_catalogo WHERE clave = :clave');
        $stmt->execute(['clave' => $clave]);
        $fila = $stmt->fetch();

        return $fila ? $this->hidratar($fila) : null;
    }

    /** Decodifica los campos JSON y normaliza tipos. */
    private function hidratar(array $fila): array
    {
        $fila['id'] = (int) $fila['id'];
        $fila['series_sugeridas'] = (int) $fila['series_sugeridas'];
        $fila['descanso_segundos'] = (int) $fila['descanso_segundos'];
        $fila['instrucciones'] = $this->json($fila['instrucciones'] ?? null);
        $fila['errores_comunes'] = $this->json($fila['errores_comunes'] ?? null);
        $fila['activo'] = (bool) $fila['activo'];

        return $fila;
    }

    /** @return string[] */
    private function json(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decodificado = json_decode($raw, true);

        return is_array($decodificado) ? $decodificado : [];
    }
}
