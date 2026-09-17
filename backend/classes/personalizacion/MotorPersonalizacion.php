<?php
declare(strict_types=1);

require_once __DIR__ . '/ContextoUsuario.php';
require_once __DIR__ . '/PlanPersonalizacion.php';
require_once __DIR__ . '/PerfilObjetivo.php';
require_once __DIR__ . '/CatalogoRestricciones.php';
require_once __DIR__ . '/PersonalizadorNutricion.php';
require_once __DIR__ . '/PersonalizadorActividad.php';
require_once __DIR__ . '/PersonalizadorEntrenamiento.php';
require_once __DIR__ . '/PersonalizadorHidratacion.php';

/**
 * NutriFit — Orquestador del Motor de Personalización.
 * =====================================================================
 * A propósito NO tiene reglas propias: su único trabajo es componer el
 * PlanPersonalizacion a partir de módulos especializados. Si mañana hay
 * que cambiar cómo se decide la hidratación, se toca
 * PersonalizadorHidratacion y este archivo queda igual.
 *
 *   ContextoUsuario → MotorPersonalizacion → PlanPersonalizacion
 *                          ├── PersonalizadorNutricion
 *                          ├── PersonalizadorActividad
 *                          ├── PersonalizadorEntrenamiento
 *                          └── PersonalizadorHidratacion
 *
 * Agregar un módulo nuevo (ej. PersonalizadorDescanso) = registrarlo acá
 * y sumar su sección al plan. No hay un God Object con cientos de if.
 */
final class MotorPersonalizacion
{
    private PersonalizadorNutricion $nutricion;
    private PersonalizadorActividad $actividad;
    private PersonalizadorEntrenamiento $entrenamiento;
    private PersonalizadorHidratacion $hidratacion;

    public function __construct(
        ?PersonalizadorNutricion $nutricion = null,
        ?PersonalizadorActividad $actividad = null,
        ?PersonalizadorEntrenamiento $entrenamiento = null,
        ?PersonalizadorHidratacion $hidratacion = null
    ) {
        $this->nutricion = $nutricion ?? new PersonalizadorNutricion();
        $this->actividad = $actividad ?? new PersonalizadorActividad();
        $this->entrenamiento = $entrenamiento ?? new PersonalizadorEntrenamiento();
        $this->hidratacion = $hidratacion ?? new PersonalizadorHidratacion();
    }

    public function generar(ContextoUsuario $ctx): PlanPersonalizacion
    {
        $nutricion = $this->nutricion->calcular($ctx);
        $actividad = $this->actividad->calcular($ctx);
        $entrenamiento = $this->entrenamiento->calcular($ctx);
        $hidratacion = $this->hidratacion->calcular($ctx);

        $objetivo = [
            'clave' => $ctx->objetivo,
            'normalizado' => PerfilObjetivo::familia($ctx->objetivo),
            'etiqueta' => PerfilObjetivo::etiqueta($ctx->objetivo),
            'reconocido' => PerfilObjetivo::existe($ctx->objetivo),
        ];

        $separadas = [
            'alimentarias' => $ctx->restriccionesAlimentarias,
            'condiciones' => $ctx->condiciones,
        ];
        $restricciones = [
            'alimentarias' => $separadas['alimentarias'],
            'alimentarias_etiquetas' => array_map(
                [CatalogoRestricciones::class, 'etiqueta'],
                $separadas['alimentarias']
            ),
            'condiciones' => $separadas['condiciones'],
            'condiciones_etiquetas' => array_map(
                [CatalogoRestricciones::class, 'etiqueta'],
                $separadas['condiciones']
            ),
            'advertencias' => CatalogoRestricciones::advertencias($separadas['condiciones']),
            'tiene_filtros_duros' => $separadas['alimentarias'] !== [],
        ];

        // Los motivos globales son la unión de los de cada módulo: así el
        // frontend puede mostrar "por qué" sin entrar a cada sección.
        $motivos = array_merge(
            $nutricion['motivos'],
            $actividad['motivos'],
            $entrenamiento['motivos'],
            $hidratacion['motivos']
        );

        return new PlanPersonalizacion(
            $ctx->usuarioId,
            $ctx->perfilCompleto,
            $objetivo,
            PerfilObjetivo::prioridades($ctx->objetivo),
            $nutricion,
            $actividad,
            $entrenamiento,
            $hidratacion,
            [
                'tipo_dieta' => $ctx->tipoDieta,
                'lugar_entreno' => $ctx->lugarEntreno,
                'nivel_actividad' => $ctx->nivelActividad,
            ],
            $restricciones,
            $this->herramientas($ctx),
            $motivos,
            $this->datosFaltantes($ctx)
        );
    }

    /**
     * Herramientas priorizadas según el objetivo. El orden es el que
     * define PerfilObjetivo; acá sólo se le agrega el motivo.
     *
     * @return array<int, array{codigo: string, orden: int, motivo: string}>
     */
    private function herramientas(ContextoUsuario $ctx): array
    {
        $salida = [];
        $orden = 1;
        foreach (PerfilObjetivo::herramientas($ctx->objetivo) as $codigo) {
            $salida[] = [
                'codigo' => $codigo,
                'orden' => $orden,
                'motivo' => 'prioridad_por_objetivo',
            ];
            $orden++;
        }

        return $salida;
    }

    /**
     * Qué NO sabemos del usuario. Se expone explícitamente para que el
     * resto del sistema (y el frontend) no confunda "sin dato" con "cero".
     *
     * @return string[]
     */
    private function datosFaltantes(ContextoUsuario $ctx): array
    {
        $faltantes = [];

        if (!$ctx->perfilCompleto) {
            $faltantes[] = 'perfil_biometrico';
        }
        if ($ctx->pesoActualKg === null) {
            $faltantes[] = 'peso_actual';
        }
        if ($ctx->pesoObjetivoKg === null) {
            $faltantes[] = 'peso_objetivo';
        }
        if ($ctx->alturaCm === null) {
            $faltantes[] = 'altura';
        }
        if ($ctx->edad === null) {
            $faltantes[] = 'edad';
        }
        if ($ctx->nivelActividad === null) {
            $faltantes[] = 'nivel_actividad';
        }
        if ($ctx->lugarEntreno === null) {
            $faltantes[] = 'lugar_entreno';
        }
        if ($ctx->reciente !== null && !$ctx->reciente->tieneDatosSuficientes()) {
            $faltantes[] = 'historial_reciente';
        }

        return $faltantes;
    }
}
