-- 016: un único vínculo por par, preservando solicitudes/amistades en archivo.
CREATE TABLE IF NOT EXISTS amigos_ranking_archivo LIKE amigos_ranking;
-- Conserva la fila de menor id. Si cualquier dirección estaba aceptada, sigue aceptada.
UPDATE amigos_ranking a JOIN (
    SELECT LEAST(usuario_id, amigo_id) AS menor, GREATEST(usuario_id, amigo_id) AS mayor,
           MIN(id) AS conservar, MAX(estado = 'aceptado') AS aceptado
    FROM amigos_ranking GROUP BY menor, mayor HAVING COUNT(*) > 1
) pares ON a.id = pares.conservar
SET a.estado = IF(pares.aceptado, 'aceptado', a.estado);
INSERT IGNORE INTO amigos_ranking_archivo (id, usuario_id, amigo_id, estado, fecha_solicitud)
SELECT a.id, a.usuario_id, a.amigo_id, a.estado, a.fecha_solicitud FROM amigos_ranking a JOIN amigos_ranking b
ON LEAST(a.usuario_id, a.amigo_id) = LEAST(b.usuario_id, b.amigo_id)
AND GREATEST(a.usuario_id, a.amigo_id) = GREATEST(b.usuario_id, b.amigo_id) AND a.id > b.id;
DELETE a FROM amigos_ranking a JOIN amigos_ranking b
ON LEAST(a.usuario_id, a.amigo_id) = LEAST(b.usuario_id, b.amigo_id)
AND GREATEST(a.usuario_id, a.amigo_id) = GREATEST(b.usuario_id, b.amigo_id) AND a.id > b.id;
ALTER TABLE amigos_ranking ADD COLUMN IF NOT EXISTS usuario_menor INT UNSIGNED GENERATED ALWAYS AS (LEAST(usuario_id, amigo_id)) STORED;
ALTER TABLE amigos_ranking ADD COLUMN IF NOT EXISTS usuario_mayor INT UNSIGNED GENERATED ALWAYS AS (GREATEST(usuario_id, amigo_id)) STORED;
CREATE UNIQUE INDEX IF NOT EXISTS uq_amistad_par ON amigos_ranking (usuario_menor, usuario_mayor);
