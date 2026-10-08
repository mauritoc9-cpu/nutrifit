-- Local XAMPP: permisos explícitos, respuestas estructuradas y acciones idempotentes.
-- No altera registros nutricionales existentes. Aplicar después de 018_asistente.sql.
CREATE TABLE IF NOT EXISTS asistente_permisos (
 conversacion_id INT UNSIGNED PRIMARY KEY,
 mensajes_gemini TINYINT(1) NOT NULL DEFAULT 0,
 consultar_datos TINYINT(1) NOT NULL DEFAULT 0,
 enviar_datos TINYINT(1) NOT NULL DEFAULT 0,
 actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(conversacion_id) REFERENCES asistente_conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asistente_respuestas (
 conversacion_id INT UNSIGNED NOT NULL,
 solicitud CHAR(36) CHARACTER SET ascii NOT NULL,
 datos LONGTEXT NOT NULL,
 PRIMARY KEY(conversacion_id,solicitud),
 FOREIGN KEY(conversacion_id) REFERENCES asistente_conversaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS asistente_acciones (
 token CHAR(64) CHARACTER SET ascii PRIMARY KEY,
 conversacion_id INT UNSIGNED NOT NULL,
 usuario_id INT UNSIGNED NOT NULL,
 huella CHAR(64) CHARACTER SET ascii NOT NULL,
 propuesta LONGTEXT NOT NULL,
 resultado LONGTEXT NULL,
 creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expira_en DATETIME NOT NULL,
 UNIQUE KEY uq_asistente_comida(conversacion_id,huella),
 FOREIGN KEY(conversacion_id) REFERENCES asistente_conversaciones(id) ON DELETE CASCADE,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
