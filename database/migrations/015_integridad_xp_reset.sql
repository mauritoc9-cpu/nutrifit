-- 015: integridad de recompensas y almacenamiento seguro del reset.
-- MariaDB/XAMPP. Aditiva e idempotente; no reaplica migraciones previas.
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS nivel_maximo_alcanzado INT UNSIGNED NULL;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS reset_token_hash CHAR(64) NULL;
-- Los tokens emitidos previamente eran públicos y quedan revocados.
UPDATE usuarios SET reset_token = NULL, reset_token_expira = NULL WHERE reset_token IS NOT NULL;
-- Reconstruye una cota conservadora desde saldo + XP gastado en inventario.
-- No existe historial de cobros antiguos repetidos: esa información no es recuperable.
DROP PROCEDURE IF EXISTS nutrifit_backfill_nivel_maximo;
DELIMITER $$
CREATE PROCEDURE nutrifit_backfill_nivel_maximo()
BEGIN
    DECLARE restantes INT DEFAULT 1;
    CREATE TEMPORARY TABLE nf_niveles_previos (
        usuario_id INT UNSIGNED PRIMARY KEY, xp BIGINT, nivel INT DEFAULT 1
    );
    INSERT INTO nf_niveles_previos (usuario_id, xp)
    SELECT u.id, u.xp_total + COALESCE(SUM(t.costo_xp), 0)
    FROM usuarios u LEFT JOIN inventario_usuario i ON i.usuario_id = u.id
    LEFT JOIN tienda_items t ON t.id = i.item_id WHERE u.nivel_maximo_alcanzado IS NULL GROUP BY u.id, u.xp_total;
    WHILE restantes > 0 DO
        UPDATE nf_niveles_previos SET xp = xp - ROUND(100 * POW(nivel, 1.4)), nivel = nivel + 1
        WHERE xp >= ROUND(100 * POW(nivel, 1.4));
        SET restantes = ROW_COUNT();
    END WHILE;
    UPDATE usuarios u JOIN nf_niveles_previos n ON n.usuario_id = u.id
    SET u.nivel_maximo_alcanzado = GREATEST(COALESCE(u.nivel_maximo_alcanzado, u.nivel), n.nivel);
    DROP TEMPORARY TABLE nf_niveles_previos;
END$$
DELIMITER ;
CALL nutrifit_backfill_nivel_maximo();
DROP PROCEDURE nutrifit_backfill_nivel_maximo;
-- Nuevas cuentas arrancan en nivel 1; el máximo existente nunca disminuye.
ALTER TABLE usuarios MODIFY nivel_maximo_alcanzado INT UNSIGNED NOT NULL DEFAULT 1;
