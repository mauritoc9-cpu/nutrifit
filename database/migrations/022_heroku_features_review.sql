-- NutriFit: QR social, gamification and assistant. MySQL 8.4.
-- Run against the NutriFit schema after a fresh backup. No users, friendships or health records deleted.
-- Routine QR tables were already installed by migration 021.
-- Seleccioná la base de datos de destino en tu cliente antes de ejecutar (sin USE fijo).

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='nivel_maximo_alcanzado')>0, 'SELECT 1', 'ALTER TABLE usuarios ADD COLUMN nivel_maximo_alcanzado INT UNSIGNED NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='usuarios' AND COLUMN_NAME='reset_token_hash')>0, 'SELECT 1', 'ALTER TABLE usuarios ADD COLUMN reset_token_hash CHAR(64) NULL');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

WITH RECURSIVE historial AS (
 SELECT u.id, CAST(u.xp_total + COALESCE(SUM(t.costo_xp),0) AS SIGNED) AS saldo, 1 AS nivel
 FROM usuarios u LEFT JOIN inventario_usuario i ON i.usuario_id=u.id
 LEFT JOIN tienda_items t ON t.id=i.item_id
 WHERE u.nivel_maximo_alcanzado IS NULL GROUP BY u.id,u.xp_total
 UNION ALL SELECT id, saldo-ROUND(100*POW(nivel,1.4)), nivel+1
 FROM historial WHERE saldo>=ROUND(100*POW(nivel,1.4)) AND nivel<999
), maximos AS (SELECT id,MAX(nivel) AS nivel FROM historial GROUP BY id)
UPDATE usuarios u JOIN maximos m ON m.id=u.id
SET u.nivel_maximo_alcanzado=GREATEST(u.nivel,m.nivel)
WHERE u.nivel_maximo_alcanzado IS NULL;

CREATE TABLE IF NOT EXISTS xp_otorgado_diario (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    categoria VARCHAR(30) NOT NULL,
    veces INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_fecha_categoria (usuario_id, fecha, categoria),
    CONSTRAINT fk_xp_diario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logros (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    clave           VARCHAR(50) NOT NULL,
    titulo          VARCHAR(80) NOT NULL,
    descripcion     VARCHAR(160) NOT NULL,
    icono           VARCHAR(40) NOT NULL DEFAULT 'award',
    categoria       VARCHAR(30) NOT NULL DEFAULT 'general',
    xp_recompensa   INT UNSIGNED NOT NULL DEFAULT 0,
    orden           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    activo          TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_logro_clave (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS logros_usuario (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id      INT UNSIGNED NOT NULL,
    logro_clave     VARCHAR(50) NOT NULL,
    desbloqueado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_logro (usuario_id, logro_clave),
    KEY idx_logro_clave (logro_clave),
    CONSTRAINT fk_logro_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO logros (clave, titulo, descripcion, icono, categoria, xp_recompensa, orden) VALUES
    ('primer_entrenamiento', 'Primer entrenamiento', 'Completá tu primer entrenamiento', 'dumbbell', 'entrenamiento', 20, 1),
    ('primera_actividad',    'En movimiento',        'Registrá tu primera actividad física', 'activity', 'actividad', 20, 2),
    ('primera_comida',       'Primer registro',      'Registrá tu primera comida', 'utensils', 'nutricion', 20, 3),
    ('meta_agua',            'Bien hidratado',       'Cumplí tu meta de agua en un día', 'droplet', 'hidratacion', 20, 4),
    ('racha_3',              'Arranque constante',   'Alcanzá una racha de 3 días', 'flame', 'racha', 30, 5),
    ('racha_7',             'Racha de fuego',        'Alcanzá una racha de 7 días', 'flame', 'racha', 50, 6),
    ('entrenamientos_5',     'Disciplinado',         'Completá 5 entrenamientos', 'trophy', 'entrenamiento', 50, 7),
    ('actividad_3_dias',     'Movimiento diario',    'Registrá actividad en 3 días distintos', 'calendar-check', 'actividad', 40, 8)
ON DUPLICATE KEY UPDATE
    titulo = VALUES(titulo), descripcion = VALUES(descripcion), icono = VALUES(icono),
    categoria = VALUES(categoria), xp_recompensa = VALUES(xp_recompensa), orden = VALUES(orden);

CREATE TABLE IF NOT EXISTS retos_usuario (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    INT UNSIGNED NOT NULL,
    reto_clave    VARCHAR(50) NOT NULL,
    periodo       VARCHAR(16) NOT NULL,
    completado_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_reto_periodo (usuario_id, reto_clave, periodo),
    CONSTRAINT fk_reto_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS perfiles_comparticiones (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id INT UNSIGNED NOT NULL UNIQUE,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    creada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_perfil_comparticion FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Unicidad del par de amistad. MySQL no admite columnas STORED sobre columnas con FK ... ON DELETE CASCADE
-- (ERROR 1215), así que se usan columnas VIRTUAL + índice único. Si ya existiesen pares duplicados el ALTER falla
-- sin modificar nada: no se borra ni se fusiona ninguna amistad.

SET @nf_ddl = IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='amigos_ranking' AND COLUMN_NAME='usuario_menor')>0, 'SELECT 1', 'ALTER TABLE amigos_ranking ADD COLUMN usuario_menor INT UNSIGNED GENERATED ALWAYS AS (LEAST(usuario_id,amigo_id)) VIRTUAL, ADD COLUMN usuario_mayor INT UNSIGNED GENERATED ALWAYS AS (GREATEST(usuario_id,amigo_id)) VIRTUAL, ADD UNIQUE KEY uq_amistad_par (usuario_menor,usuario_mayor)');

PREPARE nf_stmt FROM @nf_ddl;

EXECUTE nf_stmt;

DEALLOCATE PREPARE nf_stmt;

SELECT DATABASE() AS base_actual, 'Funciones preparadas' AS resultado;
