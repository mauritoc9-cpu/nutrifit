-- =====================================================================
-- NutriFit — 010: cache de recetas generadas con IA
-- =====================================================================
-- ADITIVA. Guarda la receta YA VALIDADA (ingredientes verificados contra
-- el catálogo y macros calculados por el backend, no por la IA) para no
-- volver a gastar cuota de API ante el mismo contexto.
-- =====================================================================
CREATE TABLE IF NOT EXISTS recetas_ia (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NULL,
    contexto_hash VARCHAR(64) NOT NULL,
    objetivo VARCHAR(40) NOT NULL,
    tipo_dieta VARCHAR(30) NOT NULL,
    momento VARCHAR(20) NOT NULL,
    origen ENUM('ia','deterministica') NOT NULL DEFAULT 'ia',
    receta LONGTEXT NOT NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_contexto (contexto_hash),
    KEY idx_usuario (usuario_id, creada_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
