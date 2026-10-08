-- =====================================================================
-- NutriFit — 012: marcado individual de ejercicios dentro de una rutina
-- =====================================================================
-- ADITIVA. Antes, qué ejercicios de la rutina de hoy estaban "hechos"
-- vivía solo en memoria del navegador (se perdía al recargar) y el
-- selector que debía actualizar la fila tocada estaba roto (buscaba
-- `.exercise-row[onclick=...]`, pero el onclick vive en un div hijo, no
-- en `.exercise-row`), así que ningún ejercicio se marcaba individual-
-- mente de verdad. Esta tabla persiste el check por usuario/ejercicio/
-- día. NO otorga XP por sí sola — el XP de entrenamiento sigue siendo
-- exclusivo de `completar_entrenamiento.php` (rutina completa), así que
-- marcar/desmarcar un ejercicio no es una vía de farming.
-- =====================================================================
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
