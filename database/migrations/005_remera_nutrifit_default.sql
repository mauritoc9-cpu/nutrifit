-- =====================================================================
-- Migración: ítem inicial de avatar — Remera NutriFit
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
-- =====================================================================

USE nutrifit_db;

INSERT INTO tienda_items (nombre, tipo, costo_xp, costo_coins, nivel_requerido, imagen_asset)
SELECT 'Remera NutriFit', 'ropa_avatar', 0, 0, 0, 'avatar/remeras/remera-nutrifit.svg'
WHERE NOT EXISTS (SELECT 1 FROM tienda_items WHERE nombre = 'Remera NutriFit');

-- Otorga y equipa la remera a todos los usuarios existentes que todavía
-- no tengan ninguna ropa_avatar equipada (no pisa una elección ya hecha).
INSERT INTO inventario_usuario (usuario_id, item_id, equipado)
SELECT u.id,
  (SELECT id FROM tienda_items WHERE nombre = 'Remera NutriFit' LIMIT 1),
  IF (
    EXISTS (
      SELECT 1 FROM inventario_usuario iu2
      JOIN tienda_items ti2 ON ti2.id = iu2.item_id
      WHERE iu2.usuario_id = u.id AND ti2.tipo = 'ropa_avatar' AND iu2.equipado = 1
    ), 0, 1
  )
FROM usuarios u
ON DUPLICATE KEY UPDATE equipado = equipado;
