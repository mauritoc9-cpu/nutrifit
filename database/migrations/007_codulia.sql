-- =====================================================================
-- Migración: soporte para Codulia (nutricion-api-arg.fly.dev) en el
-- motor nutricional. Ejecutar UNA VEZ contra una base `nutrifit_db` ya
-- existente.
--
-- Agrega columnas nullable a `alimentos` para conservar datos que
-- Codulia (y potencialmente otras fuentes futuras) proveen y que hoy se
-- perdían: marca comercial, código de barras y porción sugerida. No
-- afecta filas existentes (quedan NULL) ni ninguna consulta actual.
-- =====================================================================

USE nutrifit_db;
SET NAMES utf8mb4;

ALTER TABLE alimentos
  ADD COLUMN IF NOT EXISTS marca VARCHAR(80) NULL
    COMMENT 'Marca comercial, cuando la fuente la provee (Codulia, Open Food Facts)',
  ADD COLUMN IF NOT EXISTS codigo_barras VARCHAR(64) NULL
    COMMENT 'Código de barras del producto, cuando existe',
  ADD COLUMN IF NOT EXISTS porcion_sugerida_gramos DECIMAL(6,2) NULL
    COMMENT 'Porción típica sugerida por la fuente, en gramos (ej. "1 milanesa mediana" = 120g)',
  ADD COLUMN IF NOT EXISTS porcion_sugerida_label VARCHAR(80) NULL
    COMMENT 'Etiqueta de esa porción tal como la da la fuente (ej. "1 milanesa mediana")';
