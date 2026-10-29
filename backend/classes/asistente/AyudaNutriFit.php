<?php
declare(strict_types=1);

/** Ayuda determinista de la aplicación. No consulta perfiles ni servicios externos. */
final class AyudaNutriFit
{
    public static function normalizar(string $text): string
    {
        $text = strtr(mb_strtolower($text, 'UTF-8'), [
            'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        ]);
        return trim(preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '');
    }

    public static function responder(string $text): array
    {
        $t = self::normalizar($text);
        $answers = [
            'nutrifit' => 'NutriFit es un proyecto de tesis de estudiantes que reúne alimentación, hidratación, actividad física y seguimiento de hábitos. Podés registrar tus hábitos, consultar gráficos y personalizar un avatar.',
            'alimentos' => 'En Inicio tocá Registrar comida para abrir Nutrición. Allí buscá el alimento y agregalo al diario. Buscá el alimento, indicá la cantidad y el tipo de comida, y confirmá el registro. Después podés consultar tu diario y sus calorías. Este asistente de ayuda no registra alimentos por vos.',
            'agua' => 'En Inicio tocá la tarjeta Agua para sumar un vaso. En Nutrición también podés consultar el control de hidratación y sumar o quitar vasos. Los cambios se guardan en tu cuenta.',
            'rutinas' => 'En Inicio encontrás Entrenamiento de hoy: podés revisar los ejercicios y abrir «Ver cómo hacerlo». Marcá el entrenamiento como completado cuando lo hayas realizado. En Actividad también podés registrar sesiones de caminata, carrera y bicicleta.',
            'actividad' => 'Entrá en Actividad y elegí Registrar actividad. Seleccioná caminata, carrera o bicicleta y completá los datos de la sesión. Consultá tu historial en esa sección. No confundir el registro manual con un seguimiento GPS en tiempo real.',
            'avatar' => 'Entrá en Perfil y tocá Personalizar junto a tu avatar 3D. Allí podés elegir entre los elementos disponibles de tu colección. La tienda está en Comunidad; los objetos adquiridos se pueden equipar desde la personalización.',
            'puntos' => 'NutriFit usa XP para avanzar de nivel y NutriCoins para la tienda. Algunas acciones registradas, logros y retos dan recompensas, con límites definidos por la app. Consultá tu nivel, logros y retos en Perfil, y la tienda y el ranking en Comunidad.',
            'progreso' => 'Abrí Progreso para ver tus registros y gráficos. Elegí el período disponible para consultar la evolución del peso y los resúmenes de actividad. Los gráficos muestran información registrada: si todavía no cargaste datos, pueden aparecer vacíos.',
            'mapa' => 'Entrá en Perfil y elegí Explorar mapa para buscar gimnasios y comercios. Podés explorar el mapa; la ubicación es opcional y requiere permiso. El mapa y las búsquedas de lugares sí necesitan Internet, aunque este asistente funciona sin IA externa.',
            'qr' => 'Para rutinas, en Inicio usá Compartir rutina o Importar rutina; podés compartir el QR o copiar su código. El código de rutina vence a los siete días y puede revocarse. Para amigos, Perfil tiene Mi QR y Escanear QR: es un QR Social distinto del QR de rutinas.',
            'exportar' => 'Entrá en Progreso y buscá Exportar progreso. Elegí Últimos 7 días, Últimos 30 días o Todo el historial, y tocá Descargar PDF o Descargar CSV. Se exportan tus registros reales.',
            'correo' => 'Las cuentas nuevas deben verificar su correo con un código de ocho dígitos antes de iniciar sesión. Vence en diez minutos. Si no llegó, revisá spam y solicitá otro desde la pantalla de verificación. En localhost hace falta configurar SMTP real; este asistente no puede enviar ni revelar códigos.',
            'login' => 'Desde la landing elegí Iniciar sesión o Crear cuenta. Ingresá tu correo y contraseña. El ojo del login permite mostrar u ocultar la contraseña. Si tu cuenta nueva está pendiente, primero completá la verificación del correo.',
        ];
        // Palabras completas y frases específicas: "aguacate", "mapache" o "paso"
        // no deben disparar respuestas de agua, mapa o puntos.
        $rules = [
            'correo' => '/\b(correo|verificacion|verificar|smtp|email)\b/',
            'alimentos' => '/\b(alimentos?|comidas?|calorias?|nutricion|diario|registrar comida|cargar comida)\b/',
            'agua' => '/\b(agua|hidratacion|hidratarme|vasos?)\b/',
            'rutinas' => '/\b(rutinas?|entrenamientos?|ejercicios?|entrenar)\b/',
            'actividad' => '/\b(actividad fisica|registrar actividad|caminatas?|carreras?|bicicletas?|ciclismo)\b/',
            'avatar' => '/\b(avatar|robot|personalizar|personalizacion|ropa|coleccion)\b/',
            'puntos' => '/\b(puntos?|xp|niveles?|nutricoins?|monedas?|logros?|retos?|ranking|tienda)\b/',
            'progreso' => '/\b(progreso|graficos?|estadisticas?|evolucion|peso)\b/',
            'mapa' => '/\b(mapa|explorar|gimnasios?|comercios?|lugares|ubicacion)\b/',
            'qr' => '/\b(qr|compartir rutina|compartir rutinas|importar rutina|importar rutinas)\b/',
            'exportar' => '/\b(exportar|exporto|exportamos|exportacion|exportaciones|pdf|csv|descargar informe)\b/',
            'login' => '/\b(iniciar sesion|inicio sesion|inicio de sesion|entrar a nutrifit|login|registrarme|crear cuenta|contrasena)\b/',
        ];
        if (preg_match('/\b(que es|que hace|para que sirve|de que trata|que significa) nutrifit\b|^nutrifit$/', $t)) {
            return self::out('nutrifit', $answers['nutrifit']);
        }
        if (preg_match('/\b(diagnostico|enfermedad|medicamentos?|me duele|dolor|tratamiento)\b/', $t)) {
            return self::out('fuera_de_alcance', 'Soy el asistente de ayuda de NutriFit y explico cómo usar la aplicación. No doy diagnósticos ni tratamientos. Para esas consultas, acudí a un profesional de salud. Podés preguntarme «¿Cómo registro una comida?» o «¿Dónde veo mi progreso?».');
        }
        $matches = [];
        foreach ($rules as $intent => $pattern) if (preg_match($pattern, $t)) $matches[] = $intent;
        // Las consultas de compartir/exportar no necesitan una explicación genérica
        // de rutinas/progreso sólo porque incluyen esos sustantivos.
        if (in_array('qr', $matches, true)) $matches = array_values(array_diff($matches, ['rutinas']));
        if (in_array('exportar', $matches, true)) $matches = array_values(array_diff($matches, ['progreso']));
        if ($matches) {
            $selected = array_slice($matches, 0, 3);
            return self::out(implode('+', $selected), implode("\n\n", array_map(static fn($i) => $answers[$i], $selected)));
        }
        if (preg_match('/^(hola|buenas|buen dia|buenos dias|buenas tardes|buenas noches|hey)( nutrifit)?$/', $t)) {
            return self::out('saludo', '¡Hola! Soy el asistente de ayuda de NutriFit. Respondo con preguntas frecuentes locales, sin IA generativa. Preguntame «¿Cómo registro agua?», «¿Cómo uso las rutinas?» o «¿Cómo exporto mi progreso?».');
        }
        if (preg_match('/^(gracias|muchas gracias|chau|adios|hasta luego)$/', $t)) return self::out('despedida', '¡De nada! Cuando necesites ayuda con NutriFit, podés volver a preguntar.');
        $help = 'Puedo ayudarte con alimentos, agua, actividad, rutinas, avatar, puntos y niveles, progreso, mapa, QR, exportaciones y acceso. Probá «¿Cómo agrego una comida?», «¿Dónde personalizo el avatar?» o «¿Cómo descargo un PDF?».';
        if (preg_match('/^(ayuda|help|que puedes hacer|que podes hacer|como funciona nutrifit|necesito ayuda)$/', $t)) return self::out('ayuda', $help);
        return self::out('no_entendido', 'No entendí esa consulta sobre la aplicación. ' . $help);
    }

    private static function out(string $intent, string $text): array
    {
        return ['texto' => $text, 'intencion' => $intent, 'origen' => 'local', 'codigo' => 'ok'];
    }
}
