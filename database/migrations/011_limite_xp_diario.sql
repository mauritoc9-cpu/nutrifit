-- =====================================================================
-- NutriFit — 011: tope diario de XP por categoría (anti-farming)
-- =====================================================================
-- ADITIVA. Contador de XP otorgado por usuario/día/categoría. A
-- diferencia de `registros_actividad.xp_otorgado` (atado a una fila que
-- se puede borrar), este contador NUNCA se decrementa: aunque se borre
-- el registro que originó el XP (una comida, un vaso de agua) y se
-- vuelva a crear, el tope diario ya consumido no se libera. Cierra el
-- farming por "registrar/eliminar repetidamente" detectado en la
-- auditoría de Fase 2, sin tocar la fórmula de XP/nivel/racha existente
-- (SistemaXP::otorgarXP sigue intacta).
-- =====================================================================
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
