-- =====================================================================
-- Migración: motor nutricional unificado (cámara + buscador manual)
-- Ejecutar UNA VEZ contra una base `nutrifit_db` ya existente.
--
-- Agrega:
--   1. `alimentos.nombre_mostrado` — nombre en español para la UI, separado
--      del `nombre` original (que puede venir en inglés desde USDA o mixto
--      desde Open Food Facts) para mantener trazabilidad de la fuente real.
--   2. `traducciones_alimentos` — caché ES<->EN reutilizado por
--      TraductorAlimentos (diccionario curado + lo que se va aprendiendo
--      con llamadas de texto a la IA, igual patrón "write-through" que ya
--      usa AlimentoRepository con Open Food Facts).
--   3. `terminos_no_resueltos` — log de búsquedas/alimentos detectados que
--      no lograron una coincidencia nutricional confiable, para revisión
--      manual y para ir ampliando el catálogo local con el tiempo.
--   4. Semilla de platos y variantes argentinas comunes (milanesas, cortes
--      de carne, pollo, papas) que hoy no existen en el catálogo local y
--      por eso siempre caían en Open Food Facts con resultados irrelevantes.
--   5. Semilla del diccionario ES<->EN con los términos más comunes que
--      devuelve USDA FoodData Central para la dieta argentina típica.
-- =====================================================================

USE nutrifit_db;

-- Fuerza utf8mb4 para esta sesión: sin esto, si el cliente mysql se
-- conecta con un charset por defecto distinto (ej. latin1), los acentos
-- de los VALUES de abajo (proteína, guarnición, preparación) quedan
-- doble-codificados en la tabla aunque el archivo esté en UTF-8 real.
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Nombre mostrado en español, separado del nombre original/fuente
-- ---------------------------------------------------------------------
ALTER TABLE alimentos
  ADD COLUMN IF NOT EXISTS nombre_mostrado VARCHAR(150) NULL
    COMMENT 'Nombre en español para la UI. Si es NULL, la app usa `nombre` (ya está en español para los alimentos locales curados).'
    AFTER nombre;

-- ---------------------------------------------------------------------
-- 2. Caché de traducciones ES<->EN
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS traducciones_alimentos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  en_normalizado  VARCHAR(150) NOT NULL COMMENT 'nombre en inglés normalizado (minúsculas, sin tildes) — clave para buscar EN->ES',
  es_normalizado  VARCHAR(150) NOT NULL COMMENT 'nombre en español normalizado — permite además buscar ES->EN',
  es_mostrado     VARCHAR(150) NOT NULL COMMENT 'nombre en español con capitalización lista para mostrar',
  origen          ENUM('diccionario','ia') NOT NULL DEFAULT 'diccionario',
  creado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_en_normalizado (en_normalizado),
  INDEX idx_es_normalizado (es_normalizado)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. Log de términos sin coincidencia confiable
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS terminos_no_resueltos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  termino       VARCHAR(150) NOT NULL,
  contexto      ENUM('camara','buscador') NOT NULL,
  veces         INT UNSIGNED NOT NULL DEFAULT 1,
  ultima_fecha  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_termino_contexto (termino, contexto)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. Semilla local: platos y cortes argentinos comunes
-- (fuente='local' les da la máxima prioridad en el scoring frente a
--  candidatos de USDA/Open Food Facts para las mismas búsquedas)
-- ---------------------------------------------------------------------
INSERT INTO alimentos (nombre, nombre_mostrado, categoria, calorias_por_100g, proteinas, carbohidratos, grasas, fuente)
SELECT * FROM (SELECT
    'Carne vacuna' AS nombre, NULL AS nombre_mostrado, 'proteína' AS categoria, 250 AS calorias_por_100g, 26.0 AS proteinas, 0.0 AS carbohidratos, 16.0 AS grasas, 'local' AS fuente
  UNION ALL SELECT 'Carne picada', NULL, 'proteína', 258, 25.0, 0.0, 17.0, 'local'
  UNION ALL SELECT 'Carne picada magra', NULL, 'proteína', 172, 26.0, 0.0, 7.0, 'local'
  UNION ALL SELECT 'Carne deshebrada', NULL, 'proteína', 220, 27.0, 0.0, 12.0, 'local'
  UNION ALL SELECT 'Hamburguesa de carne', NULL, 'proteína', 250, 25.0, 0.5, 17.0, 'local'
  UNION ALL SELECT 'Milanesa de carne', NULL, 'proteína', 250, 20.0, 12.0, 14.0, 'local'
  UNION ALL SELECT 'Milanesa de carne frita', NULL, 'proteína', 260, 19.0, 13.0, 15.5, 'local'
  UNION ALL SELECT 'Milanesa de carne al horno', NULL, 'proteína', 215, 21.0, 10.0, 10.0, 'local'
  UNION ALL SELECT 'Milanesa de pollo', NULL, 'proteína', 240, 19.0, 13.0, 13.0, 'local'
  UNION ALL SELECT 'Milanesa de pollo al horno', NULL, 'proteína', 210, 22.0, 11.0, 9.0, 'local'
  UNION ALL SELECT 'Milanesa de cerdo', NULL, 'proteína', 260, 20.0, 12.0, 15.0, 'local'
  UNION ALL SELECT 'Milanesa de soja', NULL, 'proteína', 200, 18.0, 15.0, 8.0, 'local'
  UNION ALL SELECT 'Milanesa a la napolitana', NULL, 'proteína', 280, 22.0, 10.0, 17.0, 'local'
  UNION ALL SELECT 'Pollo asado', NULL, 'proteína', 239, 27.0, 0.0, 14.0, 'local'
  UNION ALL SELECT 'Pollo a la plancha', NULL, 'proteína', 165, 31.0, 0.0, 3.6, 'local'
  UNION ALL SELECT 'Pollo frito', NULL, 'proteína', 290, 24.0, 11.0, 17.0, 'local'
  UNION ALL SELECT 'Papas fritas', NULL, 'guarnición', 312, 3.4, 41.0, 15.0, 'local'
  UNION ALL SELECT 'Papa al horno', NULL, 'guarnición', 94, 2.5, 21.0, 0.1, 'local'
  UNION ALL SELECT 'Puré de papas', NULL, 'guarnición', 105, 2.0, 17.0, 3.5, 'local'
  UNION ALL SELECT 'Empanada de carne', NULL, 'preparación', 250, 9.0, 24.0, 13.0, 'local'
  UNION ALL SELECT 'Guiso de lentejas', NULL, 'preparación', 130, 7.5, 15.0, 4.5, 'local'
  UNION ALL SELECT 'Tarta de verdura', NULL, 'preparación', 190, 6.5, 16.0, 11.0, 'local'
) semilla
WHERE NOT EXISTS (
  SELECT 1 FROM alimentos WHERE alimentos.nombre = semilla.nombre AND alimentos.fuente = 'local'
);

-- ---------------------------------------------------------------------
-- 5. Semilla del diccionario ES<->EN
-- ---------------------------------------------------------------------
INSERT INTO traducciones_alimentos (en_normalizado, es_normalizado, es_mostrado, origen)
SELECT * FROM (SELECT
    'chicken breast' AS en_normalizado, 'pechuga de pollo' AS es_normalizado, 'Pechuga de pollo' AS es_mostrado, 'diccionario' AS origen
  UNION ALL SELECT 'roasted chicken', 'pollo asado', 'Pollo asado', 'diccionario'
  UNION ALL SELECT 'grilled chicken', 'pollo a la plancha', 'Pollo a la plancha', 'diccionario'
  UNION ALL SELECT 'fried chicken', 'pollo frito', 'Pollo frito', 'diccionario'
  UNION ALL SELECT 'chicken thigh', 'muslo de pollo', 'Muslo de pollo', 'diccionario'
  UNION ALL SELECT 'chicken', 'pollo', 'Pollo', 'diccionario'
  UNION ALL SELECT 'ground beef', 'carne picada', 'Carne picada', 'diccionario'
  UNION ALL SELECT 'lean ground beef', 'carne picada magra', 'Carne picada magra', 'diccionario'
  UNION ALL SELECT 'beef steak', 'bife de carne', 'Bife de carne', 'diccionario'
  UNION ALL SELECT 'beef', 'carne vacuna', 'Carne vacuna', 'diccionario'
  UNION ALL SELECT 'pork chop', 'chuleta de cerdo', 'Chuleta de cerdo', 'diccionario'
  UNION ALL SELECT 'pork', 'cerdo', 'Cerdo', 'diccionario'
  UNION ALL SELECT 'white rice', 'arroz blanco', 'Arroz blanco', 'diccionario'
  UNION ALL SELECT 'brown rice', 'arroz integral', 'Arroz integral', 'diccionario'
  UNION ALL SELECT 'french fries', 'papas fritas', 'Papas fritas', 'diccionario'
  UNION ALL SELECT 'baked potato', 'papa al horno', 'Papa al horno', 'diccionario'
  UNION ALL SELECT 'mashed potatoes', 'pure de papas', 'Puré de papas', 'diccionario'
  UNION ALL SELECT 'potato', 'papa', 'Papa', 'diccionario'
  UNION ALL SELECT 'white bread', 'pan blanco', 'Pan blanco', 'diccionario'
  UNION ALL SELECT 'whole wheat bread', 'pan integral', 'Pan integral', 'diccionario'
  UNION ALL SELECT 'scrambled eggs', 'huevos revueltos', 'Huevos revueltos', 'diccionario'
  UNION ALL SELECT 'boiled egg', 'huevo duro', 'Huevo duro', 'diccionario'
  UNION ALL SELECT 'egg', 'huevo', 'Huevo', 'diccionario'
  UNION ALL SELECT 'salmon', 'salmon', 'Salmón', 'diccionario'
  UNION ALL SELECT 'tuna', 'atun', 'Atún', 'diccionario'
  UNION ALL SELECT 'shrimp', 'camarones', 'Camarones', 'diccionario'
  UNION ALL SELECT 'lettuce', 'lechuga', 'Lechuga', 'diccionario'
  UNION ALL SELECT 'tomato', 'tomate', 'Tomate', 'diccionario'
  UNION ALL SELECT 'onion', 'cebolla', 'Cebolla', 'diccionario'
  UNION ALL SELECT 'carrot', 'zanahoria', 'Zanahoria', 'diccionario'
  UNION ALL SELECT 'broccoli', 'brocoli', 'Brócoli', 'diccionario'
  UNION ALL SELECT 'spinach', 'espinaca', 'Espinaca', 'diccionario'
  UNION ALL SELECT 'apple', 'manzana', 'Manzana', 'diccionario'
  UNION ALL SELECT 'banana', 'banana', 'Banana', 'diccionario'
  UNION ALL SELECT 'orange', 'naranja', 'Naranja', 'diccionario'
  UNION ALL SELECT 'strawberry', 'frutilla', 'Frutilla', 'diccionario'
  UNION ALL SELECT 'milk', 'leche', 'Leche', 'diccionario'
  UNION ALL SELECT 'yogurt', 'yogur', 'Yogur', 'diccionario'
  UNION ALL SELECT 'cheese', 'queso', 'Queso', 'diccionario'
  UNION ALL SELECT 'butter', 'manteca', 'Manteca', 'diccionario'
  UNION ALL SELECT 'olive oil', 'aceite de oliva', 'Aceite de oliva', 'diccionario'
  UNION ALL SELECT 'pasta', 'fideos', 'Fideos', 'diccionario'
  UNION ALL SELECT 'lentils', 'lentejas', 'Lentejas', 'diccionario'
  UNION ALL SELECT 'beans', 'porotos', 'Porotos', 'diccionario'
  UNION ALL SELECT 'chickpeas', 'garbanzos', 'Garbanzos', 'diccionario'
  UNION ALL SELECT 'oats', 'avena', 'Avena', 'diccionario'
  UNION ALL SELECT 'avocado', 'palta', 'Palta', 'diccionario'
) semilla
WHERE NOT EXISTS (
  SELECT 1 FROM traducciones_alimentos WHERE traducciones_alimentos.en_normalizado = semilla.en_normalizado
);
