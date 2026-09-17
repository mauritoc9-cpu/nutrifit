-- =====================================================================
-- Migración: módulo general de Actividad Física (caminata/carrera/
-- ciclismo, preparado para sumar gimnasio/fútbol/pádel/etc. sin nuevas
-- migraciones estructurales) — reemplaza conceptualmente a
-- `registros_pasos` sin borrarla (queda como respaldo histórico, pero
-- deja de ser la fuente de verdad: `pasos.php` pasa a leer/escribir acá).
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- =====================================================================

USE nutrifit_db;
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Catálogo de tipos de actividad — agregar gimnasio/fútbol/pádel en
--    el futuro es un INSERT acá, no una migración de columnas. Los flags
--    `usa_*` le dicen al backend/frontend qué campos tienen sentido para
--    cada tipo (bicicleta no usa pasos, caminata no usa velocidad, etc.).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_actividad (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(30) NOT NULL,
  nombre          VARCHAR(60) NOT NULL,
  categoria       ENUM('cardio','fuerza','deporte','otro') NOT NULL DEFAULT 'cardio',
  usa_pasos       TINYINT(1) NOT NULL DEFAULT 0,
  usa_distancia   TINYINT(1) NOT NULL DEFAULT 0,
  usa_velocidad   TINYINT(1) NOT NULL DEFAULT 0,
  usa_ritmo       TINYINT(1) NOT NULL DEFAULT 0,
  met_valor       DECIMAL(4,2) NOT NULL DEFAULT 4.00 COMMENT 'Equivalente metabólico para estimar calorías: kcal = MET × peso_kg × horas',
  activo          TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_tipo_actividad_clave (clave)
) ENGINE=InnoDB;

INSERT INTO tipos_actividad (clave, nombre, categoria, usa_pasos, usa_distancia, usa_velocidad, usa_ritmo, met_valor)
SELECT * FROM (SELECT
    'caminata' AS clave, 'Caminata' AS nombre, 'cardio' AS categoria, 1 AS usa_pasos, 1 AS usa_distancia, 0 AS usa_velocidad, 0 AS usa_ritmo, 3.50 AS met_valor
  UNION ALL SELECT 'carrera', 'Carrera', 'cardio', 1, 1, 0, 1, 9.00
  UNION ALL SELECT 'ciclismo', 'Bicicleta', 'cardio', 0, 1, 1, 0, 7.50
) semilla
WHERE NOT EXISTS (SELECT 1 FROM tipos_actividad WHERE tipos_actividad.clave = semilla.clave);

-- ---------------------------------------------------------------------
-- 2. Registros de actividad — una fila por SESIÓN (no un contador que se
--    va acumulando), igual a como reportan Health Connect/HealthKit.
--    Excepción controlada: el pedómetro web y la carga manual rápida de
--    pasos acumulan sobre la fila del día vía upsert de aplicación (ver
--    RegistroActividadRepository::sumarPasosLocal) para no romper el
--    comportamiento actual de +500/+1000 — no es una segunda fuente de
--    verdad, es la MISMA tabla, solo que esas dos fuentes puntuales usan
--    upsert en vez de insert-por-sesión.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS registros_actividad (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id            INT UNSIGNED NOT NULL,
  tipo_actividad_id     INT UNSIGNED NOT NULL,
  fecha                 DATE NOT NULL,
  hora_inicio           DATETIME NULL,
  duracion_segundos     INT UNSIGNED NULL,
  pasos                 INT UNSIGNED NULL,
  distancia_metros      DECIMAL(9,2) NULL,
  velocidad_media_kmh   DECIMAL(5,2) NULL,
  ritmo_seg_por_km      INT UNSIGNED NULL,
  calorias_kcal         DECIMAL(7,2) NULL,
  fuente                ENUM('manual','sensor_web','health_connect','healthkit') NOT NULL DEFAULT 'manual',
  fuente_registro_id    VARCHAR(120) NULL COMMENT 'ID externo estable de Health Connect/HealthKit, para deduplicar al sincronizar',
  xp_otorgado           TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Evita otorgar XP dos veces por la misma sesión',
  creado_en             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_actividad_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_actividad_tipo FOREIGN KEY (tipo_actividad_id) REFERENCES tipos_actividad(id),
  -- NULL en fuente_registro_id no colisiona entre sí en MySQL/MariaDB, así
  -- que esta clave solo protege de verdad a las sesiones con id externo
  -- real (health_connect/healthkit) — exactamente lo que se necesita.
  UNIQUE KEY uq_actividad_fuente_externa (usuario_id, fuente, fuente_registro_id),
  INDEX idx_actividad_usuario_fecha (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Meta de pasos como preferencia del usuario (antes vivía repetida en
--    cada fila de `registros_pasos`, siempre en 10000 porque nunca hubo
--    forma de cambiarla). Una sola fuente de verdad para la meta.
-- ---------------------------------------------------------------------
ALTER TABLE perfiles_biometricos
  ADD COLUMN IF NOT EXISTS meta_pasos_diaria INT UNSIGNED NOT NULL DEFAULT 10000;

-- ---------------------------------------------------------------------
-- 4. Backfill: histórico de `registros_pasos` -> `registros_actividad`,
--    para no perder datos ya cargados. `registros_pasos` NO se borra
--    (queda como respaldo), pero deja de leerse/escribirse desde acá.
-- ---------------------------------------------------------------------
INSERT INTO registros_actividad (usuario_id, tipo_actividad_id, fecha, pasos, fuente, fuente_registro_id)
SELECT rp.usuario_id, (SELECT id FROM tipos_actividad WHERE clave = 'caminata'), rp.fecha, rp.pasos, 'sensor_web', NULL
FROM registros_pasos rp
WHERE rp.pasos > 0
  AND NOT EXISTS (
    SELECT 1 FROM registros_actividad ra
    WHERE ra.usuario_id = rp.usuario_id AND ra.fecha = rp.fecha
      AND ra.tipo_actividad_id = (SELECT id FROM tipos_actividad WHERE clave = 'caminata')
      AND ra.fuente = 'sensor_web' AND ra.fuente_registro_id IS NULL
  );
