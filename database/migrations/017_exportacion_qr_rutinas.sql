-- Aditiva: no altera rutinas, ejercicios, XP ni registros anteriores.
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
