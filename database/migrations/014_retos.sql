-- =====================================================================
-- NutriFit — 014: retos (desafíos con progreso real y recompensa)
-- =====================================================================
-- Reemplaza el placeholder de "Retos activos" del frontend.
--
-- Las DEFINICIONES de los retos (título, objetivo, recompensa, ventana)
-- viven en RetosService — no hay tabla de catálogo porque el progreso se
-- calcula SIEMPRE en vivo desde los datos reales (registros_entrenamiento,
-- registros_actividad, registros_hidratacion, usuarios.racha_dias), nunca
-- se guarda un contador que pudiera desincronizarse o farmearse.
--
-- Esta tabla sólo registra la FINALIZACIÓN de un reto para un período dado,
-- de modo que su recompensa de XP se otorgue UNA sola vez por período. Los
-- retos semanales usan `periodo` = año-semana ISO (ej. "2026-W41"), así que
-- vuelven a estar disponibles —y recompensables— cada semana nueva.
-- =====================================================================

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
