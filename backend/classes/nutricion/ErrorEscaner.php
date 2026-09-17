<?php
declare(strict_types=1);

/**
 * NutriFit — Taxonomía de errores del escáner de alimentos.
 * =====================================================================
 * Antes, cualquier problema (sin API key, cuota agotada, imagen rota,
 * caída de red) terminaba mostrándose al usuario como "no se detectaron
 * alimentos", que es un diagnóstico FALSO y que además escondía fallas
 * reales de configuración.
 *
 * Acá cada causa tiene su código estable + un mensaje entendible. El
 * código es para el cliente/logs; el mensaje es para la persona.
 *
 * Importante: `NO_ALIMENTOS` es el ÚNICO caso en que la respuesta es
 * correcta y simplemente no había comida reconocible en la foto.
 */
final class ErrorEscaner
{
    public const NO_ALIMENTOS = 'NO_ALIMENTOS';
    public const FORMATO_NO_COMPATIBLE = 'FORMATO_NO_COMPATIBLE';
    public const ARCHIVO_DEMASIADO_GRANDE = 'ARCHIVO_DEMASIADO_GRANDE';
    public const ERROR_SUBIDA = 'ERROR_SUBIDA';
    public const ERROR_SERVIDOR = 'ERROR_SERVIDOR';
    public const ERROR_GEMINI = 'ERROR_GEMINI';
    public const CUOTA_GEMINI = 'CUOTA_GEMINI';
    public const IA_NO_CONFIGURADA = 'IA_NO_CONFIGURADA';
    public const ERROR_CONEXION = 'ERROR_CONEXION';
    public const RESPUESTA_INVALIDA = 'RESPUESTA_INVALIDA';

    private const MENSAJES = [
        self::NO_ALIMENTOS => 'No encontramos alimentos reconocibles en esta foto. Probá con otra toma o buscá el alimento a mano.',
        self::FORMATO_NO_COMPATIBLE => 'El formato de esa imagen no es compatible. Probá con una foto JPG o PNG.',
        self::ARCHIVO_DEMASIADO_GRANDE => 'La imagen es demasiado grande. Probá con una foto de menor resolución.',
        self::ERROR_SUBIDA => 'No pudimos recibir la imagen completa. Revisá tu conexión e intentá de nuevo.',
        self::ERROR_SERVIDOR => 'Tuvimos un problema procesando la imagen. Intentá nuevamente en unos minutos.',
        self::ERROR_GEMINI => 'No pudimos analizar la imagen en este momento. Intentá nuevamente.',
        self::CUOTA_GEMINI => 'El análisis con IA alcanzó su límite por hoy. Podés buscar el alimento a mano.',
        self::IA_NO_CONFIGURADA => 'El análisis con IA no está configurado en el servidor.',
        self::ERROR_CONEXION => 'No pudimos conectarnos al servicio de análisis. Revisá tu conexión.',
        self::RESPUESTA_INVALIDA => 'Recibimos una respuesta inesperada del analizador. Intentá nuevamente.',
    ];

    public static function mensaje(string $codigo): string
    {
        return self::MENSAJES[$codigo] ?? self::MENSAJES[self::ERROR_SERVIDOR];
    }

    /**
     * Traduce el texto de error de la API de visión a un código propio.
     * Se mira el mensaje porque los clientes de visión ya normalizan el
     * error a RuntimeException con texto.
     */
    public static function desdeExcepcion(Throwable $e): string
    {
        $texto = mb_strtolower($e->getMessage());

        if (str_contains($texto, 'api key') || str_contains($texto, 'api_key') || str_contains($texto, 'unauthor')) {
            return self::IA_NO_CONFIGURADA;
        }
        if (str_contains($texto, 'quota') || str_contains($texto, 'cuota') || str_contains($texto, 'rate limit')
            || str_contains($texto, 'resource_exhausted') || str_contains($texto, '429')) {
            return self::CUOTA_GEMINI;
        }
        if (str_contains($texto, 'curl') || str_contains($texto, 'conectar') || str_contains($texto, 'timeout')) {
            return self::ERROR_CONEXION;
        }
        if (str_contains($texto, 'json') || str_contains($texto, 'no devolvió')) {
            return self::RESPUESTA_INVALIDA;
        }

        return self::ERROR_GEMINI;
    }

    /** ¿Tiene sentido que el cliente intente el reconocimiento local de respaldo? */
    public static function permiteFallbackLocal(string $codigo): bool
    {
        // Si la imagen en sí es inválida, el respaldo local tampoco va a
        // poder leerla: no tiene sentido intentarlo.
        return !in_array($codigo, [
            self::FORMATO_NO_COMPATIBLE,
            self::ARCHIVO_DEMASIADO_GRANDE,
            self::ERROR_SUBIDA,
        ], true);
    }
}
