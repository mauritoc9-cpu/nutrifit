<?php
declare(strict_types=1);

/**
 * NutriFit — Filtro de compatibilidad alimentaria.
 *
 * Determina si un alimento es apto para el tipo de dieta y las alergias/
 * intolerancias de un usuario, a partir de su nombre/categoría.
 *
 * Es una heurística por palabras clave (no una base de datos certificada
 * de alérgenos) — pensada para dar un filtrado razonable en el buscador
 * y la lista de compras, no como garantía médica.
 */
class FiltroAlimentario
{
    // El catálogo cachea productos de Open Food Facts en varios idiomas
    // (predominan español e inglés), así que cada lista incluye ambos.
    private const NO_VEGANO = [
        'pollo', 'chicken', 'carne', 'meat', 'beef', 'ternera', 'vaca', 'pescado', 'fish',
        'salmón', 'salmon', 'atún', 'atun', 'tuna', 'huevo', 'egg', 'yogur', 'yogurt', 'leche', 'milk',
        'queso', 'cheese', 'manteca', 'mantequilla', 'butter', 'miel', 'honey', 'jamón', 'jamon', 'ham',
        'cerdo', 'pork', 'bacon', 'tocino', 'res', 'pavo', 'turkey', 'cordero', 'lamb',
        'camarón', 'camaron', 'shrimp', 'mariscos', 'seafood', 'gelatina', 'gelatin',
    ];

    private const NO_VEGETARIANO = [
        'pollo', 'chicken', 'carne', 'meat', 'beef', 'ternera', 'vaca', 'pescado', 'fish',
        'salmón', 'salmon', 'atún', 'atun', 'tuna', 'jamón', 'jamon', 'ham',
        'cerdo', 'pork', 'bacon', 'tocino', 'res', 'pavo', 'turkey', 'cordero', 'lamb',
        'camarón', 'camaron', 'shrimp', 'mariscos', 'seafood',
    ];

    // Pescatariana permite pescado/mariscos, pero no otras carnes.
    private const NO_PESCATARIANO = [
        'pollo', 'chicken', 'carne', 'meat', 'beef', 'ternera', 'vaca', 'jamón', 'jamon', 'ham',
        'cerdo', 'pork', 'bacon', 'tocino', 'res', 'pavo', 'turkey', 'cordero', 'lamb',
    ];

    // Alto en carbohidratos — no apto para dieta keto.
    private const ALTO_CARBOHIDRATO = [
        'arroz', 'rice', 'pan', 'bread', 'pasta', 'fideos', 'noodles', 'azúcar', 'azucar', 'sugar',
        'papa', 'patata', 'potato', 'avena', 'oat',
    ];

    private const CONTIENE_GLUTEN = [
        'trigo', 'wheat', 'avena', 'oat', 'cebada', 'barley', 'centeno', 'rye',
        'pan', 'bread', 'fideos', 'noodles', 'pasta', 'harina', 'flour', 'galleta', 'cookie', 'biscuit', 'cerveza', 'beer',
    ];

    private const CONTIENE_LACTOSA = [
        'leche', 'milk', 'yogur', 'yogurt', 'queso', 'cheese', 'manteca', 'mantequilla', 'butter',
        'crema', 'cream', 'lácteo', 'lacteo', 'dairy',
    ];

    private const CONTIENE_FRUTOS_SECOS = [
        'maní', 'mani', 'peanut', 'almendra', 'almond', 'nuez', 'nueces', 'nut', 'avellana', 'hazelnut',
        'pistacho', 'pistachio', 'castaña', 'castana', 'chestnut',
    ];

    private const CONTIENE_MARISCOS = [
        'camarón', 'camaron', 'shrimp', 'prawn', 'langosta', 'lobster', 'cangrejo', 'crab',
        'mejillón', 'mejillon', 'mussel', 'calamar', 'squid', 'pulpo', 'octopus', 'mariscos', 'seafood',
    ];

    private const CONTIENE_HUEVO = ['huevo', 'egg'];

    public static function esCompatible(string $nombre, ?string $categoria, string $tipoDieta, array $alergias): bool
    {
        $texto = mb_strtolower($nombre . ' ' . ($categoria ?? ''));

        $dietaOk = match ($tipoDieta) {
            'vegana'       => !self::contieneAlguna($texto, self::NO_VEGANO),
            'vegetariana'  => !self::contieneAlguna($texto, self::NO_VEGETARIANO),
            'pescatariana' => !self::contieneAlguna($texto, self::NO_PESCATARIANO),
            'keto'         => !self::contieneAlguna($texto, self::ALTO_CARBOHIDRATO),
            'sin_gluten'   => !self::contieneAlguna($texto, self::CONTIENE_GLUTEN),
            default        => true, // omnivora: sin restricción
        };

        if (!$dietaOk) {
            return false;
        }

        foreach ($alergias as $alergia) {
            $palabrasExcluidas = match ($alergia) {
                'celiaco'      => self::CONTIENE_GLUTEN,
                'lactosa'      => self::CONTIENE_LACTOSA,
                'frutos_secos' => self::CONTIENE_FRUTOS_SECOS,
                'mariscos'     => self::CONTIENE_MARISCOS,
                'huevo'        => self::CONTIENE_HUEVO,
                default        => [], // diabetes/hipertension/ninguna no excluyen por nombre
            };
            if (self::contieneAlguna($texto, $palabrasExcluidas)) {
                return false;
            }
        }

        return true;
    }

    /** Trae tipo_dieta + alergias del perfil de un usuario, con defaults seguros si no completó el onboarding. */
    public static function obtenerPerfilAlimentario(PDO $db, int $usuarioId): array
    {
        $stmt = $db->prepare('SELECT tipo_dieta, alergias FROM perfiles_biometricos WHERE usuario_id = :uid');
        $stmt->execute(['uid' => $usuarioId]);
        $perfil = $stmt->fetch();

        if (!$perfil) {
            return ['tipo_dieta' => 'omnivora', 'alergias' => []];
        }

        $alergias = json_decode($perfil['alergias'] ?? '[]', true);
        $alergias = is_array($alergias) ? array_diff($alergias, ['ninguna']) : [];

        return ['tipo_dieta' => $perfil['tipo_dieta'], 'alergias' => array_values($alergias)];
    }

    private static function contieneAlguna(string $texto, array $palabras): bool
    {
        foreach ($palabras as $palabra) {
            if (str_contains($texto, $palabra)) {
                return true;
            }
        }
        return false;
    }
}
