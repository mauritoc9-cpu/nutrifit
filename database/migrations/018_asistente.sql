-- Asistente: sólo historial y control de uso; no altera datos de salud/XP.
CREATE TABLE IF NOT EXISTS asistente_conversaciones (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 usuario_id INT UNSIGNED NOT NULL,
 titulo VARCHAR(80) NOT NULL DEFAULT 'Nueva conversación',
 creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_asistente_usuario(usuario_id,actualizada_en),
 KEY idx_asistente_expira(actualizada_en),
 CONSTRAINT fk_asistente_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asistente_mensajes (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 conversacion_id INT UNSIGNED NOT NULL,
 solicitud CHAR(36) CHARACTER SET ascii NOT NULL,
 rol ENUM('user','assistant') NOT NULL,
 texto TEXT NOT NULL,
 intencion VARCHAR(32) NULL,
 origen VARCHAR(24) NULL,
 creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_asistente_solicitud(conversacion_id,solicitud,rol),
 CONSTRAINT fk_asistente_conversacion FOREIGN KEY(conversacion_id) REFERENCES asistente_conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asistente_control (
 usuario_id INT UNSIGNED PRIMARY KEY,
 ventana DATETIME NULL,
 solicitudes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 ocupado_hasta DATETIME NULL,
 propietario CHAR(36) CHARACTER SET ascii NULL,
 CONSTRAINT fk_asistente_control_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asistente_uso (
 usuario_id INT UNSIGNED NOT NULL,
 fecha DATE NOT NULL,
 generaciones SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(usuario_id,fecha),
 CONSTRAINT fk_asistente_uso_usuario FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
