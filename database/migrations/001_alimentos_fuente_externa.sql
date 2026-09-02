-- =====================================================================
-- Migración: soporte de caché para alimentos importados de una API externa
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- (Si vas a reimportar database/nutrifit_db.sql desde cero, no hace falta:
--  ya incluye estas columnas.)
-- =====================================================================

USE nutrifit_db;

ALTER TABLE alimentos
  ADD COLUMN fuente VARCHAR(20) NOT NULL DEFAULT 'local' COMMENT 'local | openfoodfacts' AFTER imagen,
  ADD COLUMN fuente_id VARCHAR(64) NULL COMMENT 'ID del producto en la fuente externa (codigo de barras OFF), NULL para alimentos locales' AFTER fuente,
  ADD COLUMN fuente_actualizado DATETIME NULL COMMENT 'Última vez que se sincronizó este registro con la fuente externa' AFTER fuente_id,
  ADD UNIQUE KEY uq_fuente (fuente, fuente_id);
