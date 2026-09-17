-- =====================================================================
-- NutriFit — 009: Catálogo de ejercicios con metadata + rutinas generadas
-- =====================================================================
-- ADITIVA E IDEMPOTENTE. No borra ni recrea nada existente:
--   * `rutinas_ejercicios` y `ejercicios` conservan todas sus filas y
--     siguen funcionando como plantillas base.
--   * Se agregan columnas nullable, así las filas actuales quedan válidas.
--
-- Por qué un catálogo aparte: hoy `ejercicios` guarda una fila POR RUTINA
-- (mismo ejercicio repetido en varias rutinas, sin metadata). Para poder
-- ARMAR rutinas según perfil hace falta un catálogo maestro consultable
-- por grupo muscular / equipamiento / lugar / nivel.
-- =====================================================================

CREATE TABLE IF NOT EXISTS ejercicios_catalogo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave VARCHAR(80) NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    grupo_muscular ENUM('piernas','pecho','espalda','hombros','brazos','core','cuerpo_completo','cardio','movilidad') NOT NULL,
    equipamiento ENUM('ninguno','silla','esterilla','mancuernas','barra','maquina','banco') NOT NULL DEFAULT 'ninguno',
    lugar ENUM('casa','gimnasio','ambos') NOT NULL DEFAULT 'ambos',
    nivel ENUM('principiante','intermedio','avanzado') NOT NULL DEFAULT 'principiante',
    tipo ENUM('fuerza','cardio','movilidad','core') NOT NULL DEFAULT 'fuerza',
    series_sugeridas TINYINT UNSIGNED NOT NULL DEFAULT 3,
    repeticiones_sugeridas VARCHAR(20) NOT NULL DEFAULT '10-12',
    descanso_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 60,
    -- Instrucciones y errores como JSON (array de strings). Contenido
    -- curado y estándar; NO se generan con IA ni se inventan fuentes.
    instrucciones LONGTEXT NULL,
    errores_comunes LONGTEXT NULL,
    -- Preparado para GIF/vídeo/animación futura. Hoy NULL a propósito:
    -- no se incrustan URLs externas frágiles.
    recurso_tipo ENUM('gif','video','animacion') NULL,
    recurso_url VARCHAR(255) NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uk_ejercicio_clave (clave),
    KEY idx_filtro (lugar, nivel, grupo_muscular, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rutinas: permitir rutinas generadas por usuario, conservando las plantillas.
ALTER TABLE rutinas_ejercicios
    ADD COLUMN IF NOT EXISTS usuario_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN IF NOT EXISTS origen ENUM('catalogo','generada') NOT NULL DEFAULT 'catalogo' AFTER descripcion,
    ADD COLUMN IF NOT EXISTS plan_hash VARCHAR(64) NULL AFTER origen,
    ADD COLUMN IF NOT EXISTS generada_en DATETIME NULL AFTER plan_hash;

-- Índice para reutilizar la rutina ya generada del mismo perfil.
ALTER TABLE rutinas_ejercicios
    ADD KEY IF NOT EXISTS idx_usuario_plan (usuario_id, plan_hash);

-- Vincular los ítems de rutina con el catálogo (para instrucciones/demostración).
ALTER TABLE ejercicios
    ADD COLUMN IF NOT EXISTS catalogo_id INT UNSIGNED NULL AFTER rutina_id,
    ADD COLUMN IF NOT EXISTS descanso_segundos SMALLINT UNSIGNED NULL AFTER repeticiones;

ALTER TABLE ejercicios
    ADD KEY IF NOT EXISTS idx_catalogo (catalogo_id);

-- =====================================================================
-- Semilla del catálogo. Incluye los 12 ejercicios que ya existían en
-- `ejercicios` (enriquecidos con metadata real) + ejercicios estándar
-- necesarios para que "casa sin equipo" y "gimnasio" den resultados
-- realmente distintos. Todo contenido estándar y verificable.
-- INSERT IGNORE = idempotente por `clave`.
-- =====================================================================
INSERT IGNORE INTO ejercicios_catalogo
(clave, nombre, grupo_muscular, equipamiento, lugar, nivel, tipo, series_sugeridas, repeticiones_sugeridas, descanso_segundos, instrucciones, errores_comunes) VALUES

-- ---------- CASA / sin equipamiento ----------
('sentadillas','Sentadillas','piernas','ninguno','ambos','principiante','fuerza',4,'15-20',60,
 '["Pies al ancho de los hombros, puntas levemente hacia afuera.","Bajá empujando la cadera hacia atrás, como si te sentaras en una silla.","Descendé hasta que los muslos queden paralelos al piso, o hasta donde controles el movimiento.","Subí empujando con los talones, sin bloquear de golpe las rodillas."]',
 '["Que las rodillas se hundan hacia adentro.","Levantar los talones del piso.","Curvar la zona baja de la espalda al bajar."]'),

('flexiones_pecho','Flexiones de pecho','pecho','ninguno','ambos','principiante','fuerza',3,'10-12',60,
 '["Manos apoyadas algo más anchas que los hombros.","Cuerpo en línea recta desde la cabeza hasta los talones.","Bajá el pecho hacia el piso con los codos a unos 45 grados del torso.","Empujá hasta extender los brazos sin hundir la cadera."]',
 '["Dejar caer la cadera y arquear la espalda.","Abrir los codos a 90 grados.","Hacer un recorrido muy corto."]'),

('plancha_abdominal','Plancha abdominal','core','esterilla','ambos','principiante','core',3,'30 seg',45,
 '["Apoyá antebrazos y puntas de los pies.","Codos alineados debajo de los hombros.","Mantené el cuerpo en línea recta, con el abdomen y los glúteos activos.","Respirá de forma constante durante todo el tiempo."]',
 '["Elevar la cadera de más.","Hundir la zona lumbar.","Contener la respiración."]'),

('zancadas_alternas','Zancadas alternas','piernas','ninguno','ambos','principiante','fuerza',3,'12 por pierna',60,
 '["Parado, dá un paso al frente con una pierna.","Bajá hasta que ambas rodillas queden cerca de 90 grados.","La rodilla de atrás desciende sin tocar el piso.","Empujá con la pierna de adelante para volver y alterná."]',
 '["Que la rodilla de adelante pase muy por delante de la punta del pie.","Inclinar demasiado el torso.","Dar pasos demasiado cortos."]'),

('puente_gluteos','Puente de glúteos','piernas','esterilla','ambos','principiante','fuerza',3,'15',45,
 '["Acostado boca arriba, rodillas flexionadas y pies apoyados.","Empujá con los talones y elevá la cadera.","Apretá los glúteos arriba, sin arquear la zona lumbar.","Bajá con control sin apoyar del todo entre repeticiones."]',
 '["Arquear la espalda en lugar de activar glúteos.","Separar los pies del piso.","Subir de forma explosiva sin control."]'),

('elevacion_talones','Elevación de talones','piernas','ninguno','ambos','principiante','fuerza',3,'20',40,
 '["Parado con los pies al ancho de la cadera.","Elevá los talones apoyando el peso en la punta de los pies.","Sostené un segundo arriba.","Bajá con control."]',
 '["Rebotar sin pausa.","Apoyarse demasiado en un soporte.","Hacer el movimiento muy rápido."]'),

('superman','Superman','espalda','esterilla','casa','principiante','fuerza',3,'12',45,
 '["Boca abajo, brazos extendidos al frente.","Elevá al mismo tiempo brazos, pecho y piernas unos centímetros.","Sostené brevemente arriba.","Bajá con control sin dejarte caer."]',
 '["Forzar el cuello mirando hacia arriba.","Subir demasiado y comprimir la lumbar.","Hacer repeticiones a gran velocidad."]'),

('mountain_climbers','Mountain climbers','cardio','esterilla','ambos','intermedio','cardio',3,'30 seg',45,
 '["Empezá en posición de plancha alta.","Llevá una rodilla hacia el pecho.","Alterná las piernas de forma rítmica.","Mantené la cadera baja y estable."]',
 '["Elevar la cadera.","Perder la alineación de hombros y manos.","Ir tan rápido que se pierde la técnica."]'),

('fondos_silla','Fondos en silla','brazos','silla','casa','principiante','fuerza',3,'10-12',60,
 '["Sentado al borde de una silla firme, manos a los costados de la cadera.","Desplazá la cadera hacia adelante fuera del asiento.","Bajá flexionando los codos hacia atrás.","Empujá para volver a subir."]',
 '["Abrir los codos hacia los costados.","Bajar más de lo que el hombro tolera.","Usar una silla inestable o con ruedas."]'),

('abdominales_crunch','Abdominales crunch','core','esterilla','ambos','principiante','core',3,'15',45,
 '["Boca arriba, rodillas flexionadas y pies apoyados.","Manos a los costados de la cabeza sin tirar del cuello.","Elevá los hombros despegando las escápulas.","Bajá con control."]',
 '["Tirar del cuello con las manos.","Hacer el movimiento con impulso.","Levantar toda la espalda en lugar de flexionar."]'),

('burpees','Burpees','cuerpo_completo','ninguno','ambos','avanzado','cardio',3,'10',75,
 '["Desde parado, bajá a cuclillas y apoyá las manos.","Llevá los pies atrás a posición de plancha.","Volvé con los pies cerca de las manos.","Incorporate con un salto controlado."]',
 '["Perder la alineación del torso en la plancha.","Caer con las rodillas rígidas.","Hacer el movimiento sin controlar la respiración."]'),

('jumping_jacks','Jumping jacks','cardio','ninguno','ambos','principiante','cardio',3,'40 seg',45,
 '["Parado con pies juntos y brazos a los costados.","Saltá abriendo las piernas y subiendo los brazos.","Volvé a la posición inicial con otro salto.","Mantené un ritmo constante."]',
 '["Caer con las rodillas totalmente rígidas.","Encoger los hombros al subir los brazos.","Perder el ritmo respiratorio."]'),

('estiramiento_dinamico','Estiramiento dinámico','movilidad','ninguno','ambos','principiante','movilidad',1,'5 min',30,
 '["Hacé círculos de brazos hacia adelante y atrás.","Sumá elevaciones de rodilla alternadas.","Agregá rotaciones suaves de cadera y tobillos.","Movete dentro de un rango cómodo, sin rebotes."]',
 '["Hacer rebotes forzando el rango.","Estirar en frío de forma agresiva.","Sostener posiciones estáticas largas antes de entrenar."]'),

('caminata_rapida','Caminata rápida','cardio','ninguno','ambos','principiante','cardio',1,'10 min',0,
 '["Caminá a un ritmo que te acelere la respiración pero te permita hablar.","Mantené el torso erguido y la mirada al frente.","Acompañá el movimiento con los brazos.","Sostené el ritmo de forma constante."]',
 '["Empezar demasiado rápido y no poder sostenerlo.","Caminar encorvado mirando el piso.","Dar pasos excesivamente largos."]'),

('plancha_lateral','Plancha lateral','core','esterilla','ambos','intermedio','core',3,'25 seg por lado',45,
 '["De costado, apoyá el antebrazo con el codo bajo el hombro.","Elevá la cadera formando una línea recta.","Mantené el abdomen activo.","Repetí del otro lado."]',
 '["Dejar caer la cadera.","Rotar el torso hacia adelante.","Apoyar el codo por delante del hombro."]'),

-- ---------- GIMNASIO / con equipamiento ----------
('press_banca','Press de banca','pecho','barra','gimnasio','intermedio','fuerza',4,'8-10',90,
 '["Acostado en el banco, pies firmes en el piso.","Tomá la barra algo más ancho que los hombros.","Bajá la barra controlada hasta la altura del pecho.","Empujá hasta extender los brazos sin bloquear de golpe."]',
 '["Rebotar la barra en el pecho.","Despegar la cadera del banco.","Abrir los codos en exceso."]'),

('press_militar','Press militar','hombros','barra','gimnasio','intermedio','fuerza',3,'10-12',75,
 '["De pie o sentado, barra a la altura de los hombros.","Mantené el abdomen firme y la espalda neutra.","Empujá la barra hacia arriba hasta extender los brazos.","Bajá con control hasta los hombros."]',
 '["Arquear mucho la zona lumbar.","Empujar la barra hacia adelante.","Usar impulso de piernas sin buscarlo."]'),

('fondos_paralelas','Fondos en paralelas','brazos','maquina','gimnasio','intermedio','fuerza',3,'10-12',75,
 '["Sostenete en las paralelas con los brazos extendidos.","Bajá flexionando los codos, torso levemente inclinado.","Descendé hasta donde el hombro lo permita cómodamente.","Empujá hasta volver arriba."]',
 '["Bajar más allá del rango cómodo del hombro.","Encoger los hombros hacia las orejas.","Balancearse para tomar impulso."]'),

('sentadilla_barra','Sentadilla con barra','piernas','barra','gimnasio','avanzado','fuerza',4,'8-10',120,
 '["Barra apoyada sobre la parte alta de la espalda.","Pies al ancho de los hombros, abdomen firme.","Bajá con la cadera hacia atrás manteniendo la espalda neutra.","Subí empujando con los talones."]',
 '["Que las rodillas colapsen hacia adentro.","Redondear la espalda baja.","Levantar los talones."]'),

('prensa_piernas','Prensa de piernas','piernas','maquina','gimnasio','principiante','fuerza',4,'10-12',90,
 '["Sentado en la máquina, pies al ancho de los hombros en la plataforma.","Bajá la plataforma flexionando las rodillas de forma controlada.","No dejes que la zona lumbar se despegue del respaldo.","Empujá sin bloquear bruscamente las rodillas."]',
 '["Bajar tanto que la cadera se despegue.","Bloquear las rodillas de golpe.","Apoyar los pies demasiado bajo en la plataforma."]'),

('zancadas_mancuernas','Zancadas con mancuernas','piernas','mancuernas','gimnasio','intermedio','fuerza',3,'12 por pierna',75,
 '["Sostené una mancuerna en cada mano a los costados.","Dá un paso al frente y bajá con control.","Ambas rodillas cerca de 90 grados.","Empujá con la pierna delantera para volver."]',
 '["Inclinar el torso hacia adelante.","Perder el equilibrio al bajar.","Usar demasiado peso antes de dominar la técnica."]'),

('remo_barra','Remo con barra','espalda','barra','gimnasio','intermedio','fuerza',4,'10-12',75,
 '["De pie, incliná el torso hacia adelante con la espalda neutra.","Tomá la barra con las manos al ancho de los hombros.","Llevá la barra hacia el abdomen juntando las escápulas.","Bajá con control."]',
 '["Redondear la espalda.","Usar impulso del torso.","Encoger los hombros en lugar de retraer escápulas."]'),

('jalon_pecho','Jalón al pecho','espalda','maquina','gimnasio','principiante','fuerza',3,'10-12',75,
 '["Sentado, muslos asegurados bajo el apoyo.","Tomá la barra más ancho que los hombros.","Traé la barra hacia la parte alta del pecho.","Volvé arriba con control sin soltar la tensión."]',
 '["Llevar la barra por detrás de la nuca.","Inclinarse demasiado hacia atrás.","Subir de golpe soltando el peso."]'),

('peso_muerto_rumano','Peso muerto rumano','piernas','barra','gimnasio','avanzado','fuerza',4,'8-10',120,
 '["De pie con la barra cerca de los muslos.","Llevá la cadera hacia atrás bajando la barra pegada a las piernas.","Mantené la espalda neutra y las rodillas apenas flexionadas.","Subí empujando la cadera hacia adelante."]',
 '["Redondear la espalda baja.","Alejar la barra del cuerpo.","Flexionar las rodillas como en una sentadilla."]'),

('curl_biceps','Curl de bíceps','brazos','mancuernas','gimnasio','principiante','fuerza',3,'12',60,
 '["De pie, una mancuerna en cada mano con las palmas al frente.","Flexioná los codos subiendo el peso.","Mantené los codos pegados al torso.","Bajá con control."]',
 '["Balancear el torso para subir el peso.","Despegar los codos hacia adelante.","Bajar el peso sin control."]'),

('extension_triceps','Extensión de tríceps','brazos','mancuernas','gimnasio','principiante','fuerza',3,'12',60,
 '["Sostené una mancuerna con ambas manos por encima de la cabeza.","Bajá el peso por detrás flexionando los codos.","Mantené los codos apuntando al frente.","Extendé los brazos para volver arriba."]',
 '["Abrir los codos hacia los costados.","Arquear la zona lumbar.","Usar un peso que obligue a compensar con la espalda."]'),

('elevaciones_laterales','Elevaciones laterales','hombros','mancuernas','gimnasio','principiante','fuerza',3,'12-15',60,
 '["De pie, una mancuerna en cada mano a los costados.","Elevá los brazos hacia los lados hasta la altura de los hombros.","Codos apenas flexionados.","Bajá con control."]',
 '["Usar impulso del torso.","Subir por encima de los hombros encogiéndolos.","Usar demasiado peso y perder la técnica."]');
