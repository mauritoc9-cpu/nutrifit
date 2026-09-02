-- =====================================================================
-- Migración: onboarding ampliado (objetivo, nivel de actividad, dieta,
-- alergias/intolerancias, meta de fibra).
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- =====================================================================

USE nutrifit_db;

-- 'ganar_peso' se mantiene por compatibilidad con datos existentes aunque
-- ya no se ofrece como opción en el onboarding nuevo. Se suman
-- 'mantener_peso' y 'monitorear_salud'.
ALTER TABLE perfiles_biometricos
  MODIFY COLUMN objetivo ENUM(
    'ganar_peso','perder_grasa','ganar_musculo','mejorar_habitos',
    'mantener_peso','monitorear_salud'
  ) NOT NULL;

-- 'activo' pasa a llamarse 'muy_activo' y se suma 'ligero' como nivel intermedio.
UPDATE perfiles_biometricos SET nivel_actividad = 'muy_activo' WHERE nivel_actividad = 'activo';
ALTER TABLE perfiles_biometricos
  MODIFY COLUMN nivel_actividad ENUM('sedentario','ligero','moderado','muy_activo') NOT NULL;

ALTER TABLE perfiles_biometricos
  ADD COLUMN tipo_dieta ENUM('omnivora','vegetariana','vegana','pescatariana','keto','sin_gluten')
    NOT NULL DEFAULT 'omnivora' AFTER lugar_entreno,
  ADD COLUMN alergias JSON NULL COMMENT 'Array de strings: celiaco, lactosa, frutos_secos, mariscos, huevo, diabetes, hipertension, ninguna' AFTER tipo_dieta,
  ADD COLUMN meta_fibra INT UNSIGNED NULL COMMENT 'gramos/día' AFTER meta_grasas;
