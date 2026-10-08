-- Prepared from restored production snapshot, MySQL 8.4.8.
-- NOT an automatic migration. Review live schema and verified backup before applying.
-- Select the intended database in your client. DDL commits implicitly.
-- Additive only: no seeds, UPDATE, DELETE, DROP, or changes to existing column definitions.
-- Idempotent: each ALTER is guarded via information_schema, so it can be re-run safely.

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alimentos' AND COLUMN_NAME='nombre_mostrado')>0, 'SELECT 1', 'ALTER TABLE alimentos ADD COLUMN nombre_mostrado VARCHAR(150) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alimentos' AND COLUMN_NAME='marca')>0, 'SELECT 1', 'ALTER TABLE alimentos ADD COLUMN marca VARCHAR(80) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alimentos' AND COLUMN_NAME='codigo_barras')>0, 'SELECT 1', 'ALTER TABLE alimentos ADD COLUMN codigo_barras VARCHAR(64) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alimentos' AND COLUMN_NAME='porcion_sugerida_gramos')>0, 'SELECT 1', 'ALTER TABLE alimentos ADD COLUMN porcion_sugerida_gramos DECIMAL(6,2) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='alimentos' AND COLUMN_NAME='porcion_sugerida_label')>0, 'SELECT 1', 'ALTER TABLE alimentos ADD COLUMN porcion_sugerida_label VARCHAR(80) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rutinas_ejercicios' AND COLUMN_NAME='generada_en')>0, 'SELECT 1', 'ALTER TABLE rutinas_ejercicios ADD COLUMN generada_en DATETIME NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

CREATE TABLE IF NOT EXISTS traducciones_alimentos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  en_normalizado  VARCHAR(150) NOT NULL COMMENT 'nombre en inglés normalizado (minúsculas, sin tildes) — clave para buscar EN->ES',
  es_normalizado  VARCHAR(150) NOT NULL COMMENT 'nombre en español normalizado — permite además buscar ES->EN',
  es_mostrado     VARCHAR(150) NOT NULL COMMENT 'nombre en español con capitalización lista para mostrar',
  origen          ENUM('diccionario','ia') NOT NULL DEFAULT 'diccionario',
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_en_normalizado (en_normalizado),
  INDEX idx_es_normalizado (es_normalizado)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS terminos_no_resueltos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  termino       VARCHAR(150) NOT NULL,
  contexto      ENUM('camara','buscador') NOT NULL,
  veces         INT UNSIGNED NOT NULL DEFAULT 1,
  ultima_fecha  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_termino_contexto (termino, contexto)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS ejercicios_completados (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    rutina_id INT UNSIGNED NOT NULL,
    ejercicio_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    creado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ejercicio_dia (usuario_id, ejercicio_id, fecha),
    KEY idx_rutina (rutina_id),
    CONSTRAINT fk_ejercicio_completado_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_ejercicio_completado_ejercicio FOREIGN KEY (ejercicio_id) REFERENCES ejercicios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rutina_comparticiones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    rutina_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contenido LONGTEXT NOT NULL,
    expira_en DATETIME NOT NULL,
    revocada_en DATETIME NULL,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rutina_token (token_hash),
    KEY idx_comparticion_usuario (usuario_id, rutina_id),
    CONSTRAINT fk_comparticion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_comparticion_rutina FOREIGN KEY (rutina_id) REFERENCES rutinas_ejercicios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rutina_importaciones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL,
    comparticion_id INT UNSIGNED NULL,
    contenido_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    rutina_id INT UNSIGNED NOT NULL,
    seleccionada TINYINT(1) NOT NULL DEFAULT 0,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_importacion_contenido (usuario_id, contenido_hash),
    UNIQUE KEY uq_importacion_rutina (rutina_id),
    CONSTRAINT fk_importacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_importacion_comparticion FOREIGN KEY (comparticion_id) REFERENCES rutina_comparticiones(id) ON DELETE SET NULL,
    CONSTRAINT fk_importacion_rutina FOREIGN KEY (rutina_id) REFERENCES rutinas_ejercicios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='rutinas_ejercicios' AND INDEX_NAME='idx_usuario_plan')>0, 'SELECT 1', 'ALTER TABLE rutinas_ejercicios ADD INDEX idx_usuario_plan (usuario_id, plan_hash)');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;
