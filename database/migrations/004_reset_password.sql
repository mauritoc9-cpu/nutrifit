-- =====================================================================
-- Migración: recuperación de contraseña
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- =====================================================================

USE nutrifit_db;

ALTER TABLE usuarios
  ADD COLUMN reset_token VARCHAR(64) NULL AFTER password,
  ADD COLUMN reset_token_expira DATETIME NULL AFTER reset_token,
  ADD UNIQUE KEY uq_reset_token (reset_token);
