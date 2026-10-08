<?php
declare(strict_types=1);

/**
 * NutriFit — Carga simple de variables de entorno desde ".env" en la raíz
 * del proyecto (sin dependencias externas — el SDK oficial de Composer para
 * esto requiere paquetes que no hacen falta para una sola clave).
 * El archivo ".env" nunca se sube a git (ver .gitignore); ".env.example"
 * documenta qué variables hacen falta.
 */
function env(string $key, ?string $default = null): ?string
{
    // Heroku config vars take precedence. An explicit empty value must not
    // silently reactivate an old credential from a local .env file.
    $processValue = getenv($key);
    if ($processValue !== false) {
        return $processValue;
    }

    static $vars = null;

    if ($vars === null) {
        $vars = [];
        $ruta = __DIR__ . '/../../.env';
        if (is_file($ruta)) {
            foreach (file($ruta, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $linea) {
                $linea = trim($linea);
                if ($linea === '' || str_starts_with($linea, '#') || !str_contains($linea, '=')) {
                    continue;
                }
                [$clave, $valor] = explode('=', $linea, 2);
                $vars[trim($clave)] = trim($valor, " \t\n\r\0\x0B\"'");
            }
        }
    }

    return $vars[$key] ?? $default;
}
