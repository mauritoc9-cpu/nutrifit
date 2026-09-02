<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../classes/MotorMetabolico.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, null, 'Método no permitido.', 405);
}

$usuarioId = requireAuth();
$body = getJsonBody();

$camposRequeridos = ['edad', 'genero', 'peso_actual', 'altura', 'objetivo', 'nivel_actividad', 'lugar_entreno', 'tipo_dieta'];
foreach ($camposRequeridos as $campo) {
    if (!isset($body[$campo]) || $body[$campo] === '') {
        respond(false, null, "Falta el campo obligatorio: {$campo}.", 422);
    }
}

$edad = (int) $body['edad'];
$genero = (string) $body['genero'];
$pesoActual = (float) $body['peso_actual'];
$pesoObjetivo = isset($body['peso_objetivo']) && $body['peso_objetivo'] !== ''
    ? (float) $body['peso_objetivo']
    : null;
$altura = (float) $body['altura'];
$objetivo = (string) $body['objetivo'];
$nivelActividad = (string) $body['nivel_actividad'];
$lugarEntreno = (string) $body['lugar_entreno'];
$tipoDieta = (string) $body['tipo_dieta'];
$alergiasEnviadas = is_array($body['alergias'] ?? null) ? $body['alergias'] : [];

$generosValidos = ['masculino', 'femenino', 'otro'];
$objetivosValidos = ['ganar_peso', 'perder_grasa', 'ganar_musculo', 'mejorar_habitos', 'mantener_peso', 'monitorear_salud'];
$actividadesValidas = ['sedentario', 'ligero', 'moderado', 'muy_activo'];
$lugaresValidos = ['casa', 'gimnasio'];
$dietasValidas = ['omnivora', 'vegetariana', 'vegana', 'pescatariana', 'keto', 'sin_gluten'];
$alergiasValidas = ['celiaco', 'lactosa', 'frutos_secos', 'mariscos', 'huevo', 'diabetes', 'hipertension', 'ninguna'];

$alergias = array_values(array_intersect($alergiasEnviadas, $alergiasValidas));
if ($alergias === []) {
    $alergias = ['ninguna'];
}

if (!in_array($genero, $generosValidos, true)
    || !in_array($objetivo, $objetivosValidos, true)
    || !in_array($nivelActividad, $actividadesValidas, true)
    || !in_array($lugarEntreno, $lugaresValidos, true)
    || !in_array($tipoDieta, $dietasValidas, true)
    || $edad < 12 || $edad > 100
    || $pesoActual <= 0 || $altura <= 0
) {
    respond(false, null, 'Alguno de los valores enviados no es válido.', 422);
}

// El motor metabólico usa 'mejorar_habitos' como ajuste neutro; para la fórmula
// de Mifflin-St Jeor sólo importan género/edad/peso/altura, no el objetivo.
$motor = new MotorMetabolico();
$resultado = $motor->calcularPerfilCompleto($pesoActual, $altura, $edad, $genero, $nivelActividad, $objetivo);

$db = (new Database())->getConnection();

$sql = 'INSERT INTO perfiles_biometricos
        (usuario_id, edad, genero, peso_actual, peso_objetivo, altura, objetivo,
         nivel_actividad, lugar_entreno, tipo_dieta, alergias, tmb, get_total, meta_calorias,
         meta_proteinas, meta_carbohidratos, meta_grasas, meta_fibra)
        VALUES
        (:usuario_id, :edad, :genero, :peso_actual, :peso_objetivo, :altura, :objetivo,
         :nivel_actividad, :lugar_entreno, :tipo_dieta, :alergias, :tmb, :get_total, :meta_calorias,
         :meta_proteinas, :meta_carbohidratos, :meta_grasas, :meta_fibra)
        ON DUPLICATE KEY UPDATE
         edad = VALUES(edad), genero = VALUES(genero), peso_actual = VALUES(peso_actual),
         peso_objetivo = VALUES(peso_objetivo), altura = VALUES(altura), objetivo = VALUES(objetivo),
         nivel_actividad = VALUES(nivel_actividad), lugar_entreno = VALUES(lugar_entreno),
         tipo_dieta = VALUES(tipo_dieta), alergias = VALUES(alergias),
         tmb = VALUES(tmb), get_total = VALUES(get_total), meta_calorias = VALUES(meta_calorias),
         meta_proteinas = VALUES(meta_proteinas), meta_carbohidratos = VALUES(meta_carbohidratos),
         meta_grasas = VALUES(meta_grasas), meta_fibra = VALUES(meta_fibra)';

$stmt = $db->prepare($sql);
$stmt->execute([
    'usuario_id'          => $usuarioId,
    'edad'                => $edad,
    'genero'              => $genero,
    'peso_actual'         => $pesoActual,
    'peso_objetivo'       => $pesoObjetivo,
    'altura'              => $altura,
    'objetivo'            => $objetivo,
    'nivel_actividad'     => $nivelActividad,
    'lugar_entreno'       => $lugarEntreno,
    'tipo_dieta'          => $tipoDieta,
    'alergias'            => json_encode($alergias, JSON_UNESCAPED_UNICODE),
    'tmb'                 => $resultado['tmb'],
    'get_total'           => $resultado['get_total'],
    'meta_calorias'       => $resultado['meta_calorias'],
    'meta_proteinas'      => $resultado['meta_proteinas'],
    'meta_carbohidratos'  => $resultado['meta_carbohidratos'],
    'meta_grasas'         => $resultado['meta_grasas'],
    'meta_fibra'          => $resultado['meta_fibra'],
]);

// Registra el peso también en el historial de progreso. Este endpoint se
// reutiliza para editar el perfil después del onboarding, así que puede
// llamarse varias veces el mismo día: si ya hay un registro de hoy, lo
// actualiza en vez de fallar por la restricción UNIQUE (usuario_id, fecha).
$db->prepare(
    'INSERT INTO progreso_peso (usuario_id, peso_registrado, fecha) VALUES (:uid, :peso, CURDATE())
     ON DUPLICATE KEY UPDATE peso_registrado = VALUES(peso_registrado)'
)->execute(['uid' => $usuarioId, 'peso' => $pesoActual]);

respond(true, $resultado, 'Perfil biométrico guardado y plan nutricional generado.', 201);
