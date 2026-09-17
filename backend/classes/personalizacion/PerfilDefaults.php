<?php
declare(strict_types=1);

/**
 * NutriFit — Valores por defecto del perfil, en UN solo lugar.
 * =====================================================================
 * Antes de esta clase, el mismo "dato inventado cuando el usuario no
 * completó el onboarding" estaba escrito a mano en 6 archivos distintos,
 * con el riesgo de que se fueran desincronizando entre sí.
 *
 * IMPORTANTE (paso 0 del Motor de Personalización): los valores de acá
 * son EXACTAMENTE los que ya estaban en producción. Esta clase no cambia
 * ningún default, sólo los centraliza. Cualquier discusión sobre si 8
 * vasos o 10.000 pasos son los números correctos es de una etapa
 * posterior (MotorHidratacion / MotorMetaPasos).
 *
 * Estado de la migración:
 *   [x] api/nutricion/dashboard_diario.php  → vía ContextoUsuarioRepository
 *   [ ] api/nutricion/lista_compras.php     → todavía tiene 2000 a mano
 *   [ ] api/nutricion/hidratacion.php       → todavía tiene 8 a mano
 *   [ ] classes/RegistroActividadRepository → todavía tiene 10000 / 170 / 70
 *   [ ] classes/FiltroAlimentario           → todavía tiene 'omnivora' / []
 * Se migran en pasos siguientes, de a uno, para no mover todo de golpe.
 */
final class PerfilDefaults
{
    /** Metas nutricionales usadas cuando no hay fila en perfiles_biometricos. */
    public const META_CALORIAS = 2000;
    public const META_PROTEINAS = 120;
    public const META_CARBOHIDRATOS = 220;
    public const META_GRASAS = 60;

    /** Objetivo neutro: no aplica déficit ni superávit. */
    public const OBJETIVO = 'mejorar_habitos';

    /** Perfil alimentario sin restricciones declaradas. */
    public const TIPO_DIETA = 'omnivora';
    public const RESTRICCIONES = [];

    /** Meta de pasos (columna meta_pasos_diaria, default de la DB). */
    public const META_PASOS_DIARIA = 10000;

    /** Meta de hidratación (columna meta_vasos de registros_hidratacion). */
    public const META_VASOS = 8;

    /** Biometría de respaldo para fórmulas que no pueden dividir por null. */
    public const ALTURA_CM = 170.0;
    public const PESO_KG = 70.0;
}
