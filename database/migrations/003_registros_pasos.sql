-- =====================================================================
-- Migración: contador de pasos diarios
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- =====================================================================

USE nutrifit_db;

CREATE TABLE IF NOT EXISTS registros_pasos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  fecha       DATE NOT NULL,
  pasos       INT UNSIGNED NOT NULL DEFAULT 0,
  meta_pasos  INT UNSIGNED NOT NULL DEFAULT 10000,
  CONSTRAINT fk_pasos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_fecha_pasos (usuario_id, fecha)
) ENGINE=InnoDB;
