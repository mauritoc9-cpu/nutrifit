-- Comidas favoritas del usuario
CREATE TABLE IF NOT EXISTS comidas_favoritas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  nombre_personalizado VARCHAR(120) NOT NULL,
  alimento_id     INT UNSIGNED NULL COMMENT 'Si es un alimento simple',
  gramos_base     DECIMAL(6,2) NOT NULL COMMENT 'Cantidad base guardada',
  unidad          VARCHAR(20) DEFAULT 'g' COMMENT 'g, ml, unidad, etc.',
  calorias_base   DECIMAL(7,2) NOT NULL COMMENT 'Para esta cantidad',
  proteinas_base  DECIMAL(7,2) NOT NULL,
  carbohidratos_base DECIMAL(7,2) NOT NULL,
  grasas_base     DECIMAL(7,2) NOT NULL,
  es_compuesto    BOOLEAN DEFAULT FALSE COMMENT 'Si contiene múltiples ingredientes',
  origen          VARCHAR(50) DEFAULT 'manual' COMMENT 'manual, escaner_ia, recomendacion',
  metadata        JSON NULL COMMENT 'ingredientes, pasos, etc. si es compuesto',
  veces_usado     INT DEFAULT 0,
  ultima_usado    DATETIME NULL,
  creado_en       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_favorito_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_favorito_alimento FOREIGN KEY (alimento_id) REFERENCES alimentos(id) ON DELETE SET NULL,
  INDEX idx_usuario (usuario_id),
  UNIQUE KEY uq_favorito (usuario_id, nombre_personalizado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
