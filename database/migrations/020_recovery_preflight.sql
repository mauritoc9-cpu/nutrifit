-- Read-only production preflight. Use the existing selected database; no USE name.
SELECT VERSION() AS server_version, DATABASE() AS selected_database;
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
('usuarios','alimentos','rutinas_ejercicios','ejercicios','comidas_favoritas','ejercicios_catalogo','ejercicios_completados','rutina_comparticiones','rutina_importaciones');
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
('usuarios','alimentos','rutinas_ejercicios','ejercicios','comidas_favoritas')
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN
('usuarios','alimentos','rutinas_ejercicios','ejercicios','comidas_favoritas')
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
AND REFERENCED_TABLE_NAME IS NOT NULL AND (TABLE_NAME IN
('usuarios','alimentos','rutinas_ejercicios','ejercicios','comidas_favoritas')
OR REFERENCED_TABLE_NAME IN ('usuarios','alimentos','rutinas_ejercicios','ejercicios','comidas_favoritas'));
