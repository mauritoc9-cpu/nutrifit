<?php
declare(strict_types=1);

require_once __DIR__ . '/OpenFoodFactsClient.php';

/**
 * NutriFit — Búsqueda de alimentos con caché local + fallback externo.
 *
 * Estrategia (write-through cache):
 *   1. Busca primero en la tabla local `alimentos` (rápido, sin red).
 *   2. Si faltan resultados, consulta Open Food Facts.
 *   3. Cada resultado externo se guarda (INSERT ... ON DUPLICATE KEY UPDATE)
 *      en `alimentos` antes de devolverlo, así siempre tiene un `id` local
 *      real. El resto de la app (registrar_comida.php, FK a alimentos,
 *      frontend) no necesita saber que ese alimento vino de una API externa.
 *
 * No se hace una sincronización masiva del catálogo completo de OFF: sería
 * inviable (millones de productos) e innecesario, porque el catálogo local
 * crece orgánicamente con lo que los usuarios van buscando.
 */
class AlimentoRepository
{
    private const COLUMNAS = 'id, nombre, categoria, calorias_por_100g, proteinas, carbohidratos, grasas, imagen';

    private PDO $db;
    private OpenFoodFactsClient $externo;

    public function __construct(PDO $db, ?OpenFoodFactsClient $externo = null)
    {
        $this->db = $db;
        $this->externo = $externo ?? new OpenFoodFactsClient();
    }

    /** @return array<int, array<string, mixed>> */
    public function buscar(string $query, int $limite = 15): array
    {
        $locales = $this->buscarLocal($query, $limite);

        $faltantes = $limite - count($locales);
        if ($faltantes <= 0) {
            return $locales;
        }

        $cacheados = $this->buscarYCachearExternos($query, $faltantes);

        return array_merge($locales, $cacheados);
    }

    /** @return array<int, array<string, mixed>> */
    private function buscarLocal(string $query, int $limite): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . '
             FROM alimentos WHERE nombre LIKE :q ORDER BY nombre ASC LIMIT :lim'
        );
        $stmt->bindValue('q', '%' . $query . '%');
        $stmt->bindValue('lim', $limite, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    private function buscarYCachearExternos(string $query, int $faltantes): array
    {
        try {
            // Se pide un poco de margen porque algunos productos externos
            // pueden no tener macros completas y quedan descartados.
            $candidatos = $this->externo->buscar($query, $faltantes + 5);
        } catch (Throwable $e) {
            // Un problema de red/API externa nunca debe romper la búsqueda:
            // el usuario se queda con los resultados locales que ya tiene.
            error_log('[AlimentoRepository] Open Food Facts falló: ' . $e->getMessage());
            return [];
        }

        $resultados = [];
        foreach (array_slice($candidatos, 0, $faltantes) as $candidato) {
            $id = $this->cachearAlimentoExterno($candidato);
            if ($id === null) {
                continue;
            }
            $resultados[] = [
                'id' => $id,
                'nombre' => $candidato['nombre'],
                'categoria' => $candidato['categoria'],
                'calorias_por_100g' => $candidato['calorias_por_100g'],
                'proteinas' => $candidato['proteinas'],
                'carbohidratos' => $candidato['carbohidratos'],
                'grasas' => $candidato['grasas'],
                'imagen' => $candidato['imagen'],
            ];
        }

        return $resultados;
    }

    private function cachearAlimentoExterno(array $alimento): ?int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO alimentos
                   (nombre, categoria, calorias_por_100g, proteinas, carbohidratos, grasas,
                    imagen, fuente, fuente_id, fuente_actualizado)
                 VALUES
                   (:nombre, :categoria, :cal, :prot, :carb, :gras, :imagen, \'openfoodfacts\', :fuente_id, NOW())
                 ON DUPLICATE KEY UPDATE
                   nombre = VALUES(nombre),
                   categoria = VALUES(categoria),
                   calorias_por_100g = VALUES(calorias_por_100g),
                   proteinas = VALUES(proteinas),
                   carbohidratos = VALUES(carbohidratos),
                   grasas = VALUES(grasas),
                   imagen = VALUES(imagen),
                   fuente_actualizado = VALUES(fuente_actualizado),
                   id = LAST_INSERT_ID(id)'
            );
            $stmt->execute([
                'nombre' => $alimento['nombre'],
                'categoria' => $alimento['categoria'],
                'cal' => $alimento['calorias_por_100g'],
                'prot' => $alimento['proteinas'],
                'carb' => $alimento['carbohidratos'],
                'gras' => $alimento['grasas'],
                'imagen' => $alimento['imagen'],
                'fuente_id' => $alimento['fuente_id'],
            ]);

            return (int) $this->db->lastInsertId();
        } catch (Throwable $e) {
            error_log('[AlimentoRepository] No se pudo cachear alimento externo: ' . $e->getMessage());
            return null;
        }
    }
}
