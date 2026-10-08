-- =====================================================================
-- NutriFit — 013: sistema de logros real y persistente
-- =====================================================================
-- Reemplaza los "logros" que antes estaban hardcodeados en el frontend
-- (obtenerLogrosPerfil: sólo 2, calculados al vuelo, sin persistencia).
--
--   logros          = catálogo (datos de presentación + recompensa). La
--                     CONDICIÓN de desbloqueo vive en LogrosService, keyed
--                     por `clave` — único lugar donde se evalúa.
--   logros_usuario  = qué logro desbloqueó cada usuario y cuándo. El UNIQUE
--                     (usuario_id, logro_clave) garantiza que un logro no se
--                     otorgue —ni recompense— dos veces (anti-farming).
-- =====================================================================

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

-- Catálogo inicial. Los títulos/descripciones/recompensas viven acá; la
-- condición de cada uno está en LogrosService::condiciones() con la MISMA
-- clave. Agregar un logro = 1 fila acá + 1 condición allá, nada más.
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
