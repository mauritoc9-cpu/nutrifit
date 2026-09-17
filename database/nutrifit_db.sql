-- =====================================================================
-- NUTRIFIT — Script de Base de Datos
-- Motor: MySQL / MariaDB (XAMPP / phpMyAdmin)
-- Codificación: utf8mb4 (soporte emojis para insignias, títulos, etc.)
-- =====================================================================



SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. usuarios — cuenta, credenciales y estado global de gamificación
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS usuarios;
CREATE TABLE usuarios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(80)  NOT NULL,
  email           VARCHAR(120) NOT NULL UNIQUE,
  password        VARCHAR(255) NOT NULL COMMENT 'Hash generado con password_hash() (BCRYPT)',
  reset_token     VARCHAR(64)  NULL UNIQUE,
  reset_token_expira DATETIME  NULL,
  fecha_registro  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  xp_total        INT UNSIGNED NOT NULL DEFAULT 0,
  nivel           INT UNSIGNED NOT NULL DEFAULT 1,
  racha_dias      INT UNSIGNED NOT NULL DEFAULT 0,
  ultima_actividad DATE        NULL COMMENT 'Usada para calcular si la racha continúa o se rompe',
  nutri_coins     INT UNSIGNED NOT NULL DEFAULT 0,
  avatar_config   JSON         NULL COMMENT 'Piezas equipadas del avatar 3D (ropa, aura, accesorios)',
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  INDEX idx_xp_total (xp_total)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. perfiles_biometricos — datos de onboarding + resultado metabólico
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS perfiles_biometricos;
CREATE TABLE perfiles_biometricos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  edad            TINYINT UNSIGNED NOT NULL,
  genero          ENUM('masculino','femenino','otro') NOT NULL,
  peso_actual     DECIMAL(5,2) NOT NULL COMMENT 'kg',
  peso_objetivo   DECIMAL(5,2) NULL COMMENT 'kg',
  altura          DECIMAL(5,2) NOT NULL COMMENT 'cm',
  objetivo        ENUM('ganar_peso','perder_grasa','ganar_musculo','mejorar_habitos','mantener_peso','monitorear_salud') NOT NULL,
  nivel_actividad ENUM('sedentario','ligero','moderado','muy_activo') NOT NULL,
  lugar_entreno   ENUM('casa','gimnasio') NOT NULL,
  tipo_dieta      ENUM('omnivora','vegetariana','vegana','pescatariana','keto','sin_gluten') NOT NULL DEFAULT 'omnivora',
  alergias        JSON NULL COMMENT 'Array de strings: celiaco, lactosa, frutos_secos, mariscos, huevo, diabetes, hipertension, ninguna',
  tmb             DECIMAL(7,2) NULL COMMENT 'Tasa Metabólica Basal (Mifflin-St Jeor)',
  get_total       DECIMAL(7,2) NULL COMMENT 'Gasto Energético Total (TMB * factor actividad)',
  meta_calorias   INT UNSIGNED NULL,
  meta_proteinas  INT UNSIGNED NULL COMMENT 'gramos/día',
  meta_carbohidratos INT UNSIGNED NULL COMMENT 'gramos/día',
  meta_grasas     INT UNSIGNED NULL COMMENT 'gramos/día',
  meta_fibra      INT UNSIGNED NULL COMMENT 'gramos/día',
  fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_perfil_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_perfil (usuario_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. alimentos — catálogo nutricional (base para buscador y cámara IA)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS alimentos;
CREATE TABLE alimentos (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre            VARCHAR(120) NOT NULL,
  categoria         VARCHAR(60)  NULL COMMENT 'ej: proteína, fruta, cereal',
  calorias_por_100g DECIMAL(6,2) NOT NULL,
  proteinas         DECIMAL(6,2) NOT NULL COMMENT 'g por 100g',
  carbohidratos     DECIMAL(6,2) NOT NULL COMMENT 'g por 100g',
  grasas            DECIMAL(6,2) NOT NULL COMMENT 'g por 100g',
  imagen            VARCHAR(255) NULL,
  fuente            VARCHAR(20)  NOT NULL DEFAULT 'local' COMMENT 'local | openfoodfacts',
  fuente_id         VARCHAR(64)  NULL COMMENT 'ID del producto en la fuente externa (code de barras OFF), NULL para alimentos locales',
  fuente_actualizado DATETIME    NULL COMMENT 'Última vez que se sincronizó este registro con la fuente externa',
  INDEX idx_nombre (nombre),
  UNIQUE KEY uq_fuente (fuente, fuente_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. registros_comidas — diario de comidas del usuario
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS registros_comidas;
CREATE TABLE registros_comidas (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  alimento_id     INT UNSIGNED NOT NULL,
  tipo_comida     ENUM('desayuno','almuerzo','merienda','cena','snack') NOT NULL,
  gramos          DECIMAL(6,2) NOT NULL,
  calorias_totales DECIMAL(7,2) NOT NULL,
  proteinas_totales DECIMAL(7,2) NOT NULL DEFAULT 0,
  carbohidratos_totales DECIMAL(7,2) NOT NULL DEFAULT 0,
  grasas_totales  DECIMAL(7,2) NOT NULL DEFAULT 0,
  origen          ENUM('manual','camara_ia') NOT NULL DEFAULT 'manual',
  fecha           DATE NOT NULL,
  hora_registro   TIME NOT NULL DEFAULT (CURRENT_TIME),
  CONSTRAINT fk_registro_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_registro_alimento FOREIGN KEY (alimento_id) REFERENCES alimentos(id),
  INDEX idx_usuario_fecha (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. rutinas_ejercicios — plantillas de rutina (casa / gimnasio)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS rutinas_ejercicios;
CREATE TABLE rutinas_ejercicios (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  titulo          VARCHAR(120) NOT NULL,
  lugar           ENUM('casa','gimnasio') NOT NULL,
  objetivo        ENUM('ganar_peso','perder_grasa','ganar_musculo','mejorar_habitos') NOT NULL,
  nivel_dificultad ENUM('principiante','intermedio','avanzado') NOT NULL,
  descripcion     TEXT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. ejercicios — ejercicios individuales dentro de una rutina
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS ejercicios;
CREATE TABLE ejercicios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rutina_id     INT UNSIGNED NOT NULL,
  nombre        VARCHAR(120) NOT NULL,
  series        TINYINT UNSIGNED NOT NULL,
  repeticiones  VARCHAR(20) NOT NULL COMMENT 'ej: "12-15" o "30 seg"',
  video_demo    VARCHAR(255) NULL,
  orden         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  CONSTRAINT fk_ejercicio_rutina FOREIGN KEY (rutina_id) REFERENCES rutinas_ejercicios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6b. registros_entrenamiento — historial de entrenamientos completados
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS registros_entrenamiento;
CREATE TABLE registros_entrenamiento (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  rutina_id   INT UNSIGNED NOT NULL,
  fecha       DATE NOT NULL,
  xp_ganado   SMALLINT UNSIGNED NOT NULL DEFAULT 25,
  CONSTRAINT fk_registroent_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_registroent_rutina FOREIGN KEY (rutina_id) REFERENCES rutinas_ejercicios(id),
  INDEX idx_usuario_fecha_ent (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6c. registros_hidratacion — vasos de agua consumidos por día
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS registros_hidratacion;
CREATE TABLE registros_hidratacion (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  fecha       DATE NOT NULL,
  vasos       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  meta_vasos  TINYINT UNSIGNED NOT NULL DEFAULT 8,
  CONSTRAINT fk_hidratacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_fecha_hidra (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6d. registros_pasos — contador de pasos diarios
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS registros_pasos;
CREATE TABLE registros_pasos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  fecha       DATE NOT NULL,
  pasos       INT UNSIGNED NOT NULL DEFAULT 0,
  meta_pasos  INT UNSIGNED NOT NULL DEFAULT 10000,
  CONSTRAINT fk_pasos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_fecha_pasos (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 7. progreso_peso — histórico de peso para el gráfico de progreso
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS progreso_peso;
CREATE TABLE progreso_peso (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id      INT UNSIGNED NOT NULL,
  peso_registrado DECIMAL(5,2) NOT NULL,
  fecha           DATE NOT NULL,
  CONSTRAINT fk_peso_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_fecha_peso (usuario_id, fecha)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 8. tienda_items — catálogo canjeable con NutriCoins / XP
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS tienda_items;
CREATE TABLE tienda_items (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre       VARCHAR(120) NOT NULL,
  tipo         ENUM('ropa_avatar','accesorio_avatar','aura','marco_perfil','titulo','cupon') NOT NULL,
  costo_xp     INT UNSIGNED NOT NULL DEFAULT 0,
  costo_coins  INT UNSIGNED NOT NULL DEFAULT 0,
  nivel_requerido TINYINT UNSIGNED NOT NULL DEFAULT 1,
  imagen_asset VARCHAR(255) NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 9. inventario_usuario — ítems que el usuario ya posee/equipa
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS inventario_usuario;
CREATE TABLE inventario_usuario (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  item_id     INT UNSIGNED NOT NULL,
  equipado    TINYINT(1) NOT NULL DEFAULT 0,
  fecha_obtenido DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inventario_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_inventario_item FOREIGN KEY (item_id) REFERENCES tienda_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_usuario_item (usuario_id, item_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 10. amigos_ranking — relaciones sociales y estado de solicitud
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS amigos_ranking;
CREATE TABLE amigos_ranking (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NOT NULL,
  amigo_id    INT UNSIGNED NOT NULL,
  estado      ENUM('pendiente','aceptado','rechazado') NOT NULL DEFAULT 'pendiente',
  fecha_solicitud DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_amigos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_amigos_amigo FOREIGN KEY (amigo_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  UNIQUE KEY uq_relacion (usuario_id, amigo_id)
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- DATOS SEMILLA (SEED DATA) — para probar la app inmediatamente
-- =====================================================================

INSERT INTO alimentos (nombre, categoria, calorias_por_100g, proteinas, carbohidratos, grasas, imagen) VALUES
('Pechuga de pollo a la plancha', 'proteína', 165, 31.0, 0.0, 3.6, 'pollo.jpg'),
('Arroz blanco cocido', 'cereal', 130, 2.7, 28.0, 0.3, 'arroz.jpg'),
('Huevo entero', 'proteína', 155, 13.0, 1.1, 11.0, 'huevo.jpg'),
('Avena en hojuelas', 'cereal', 389, 16.9, 66.3, 6.9, 'avena.jpg'),
('Plátano', 'fruta', 89, 1.1, 22.8, 0.3, 'platano.jpg'),
('Palta / Aguacate', 'fruta', 160, 2.0, 8.5, 14.7, 'palta.jpg'),
('Lentejas cocidas', 'legumbre', 116, 9.0, 20.1, 0.4, 'lentejas.jpg'),
('Salmón al horno', 'proteína', 208, 20.4, 0.0, 13.4, 'salmon.jpg'),
('Ensalada verde mixta', 'vegetal', 20, 1.5, 3.6, 0.2, 'ensalada.jpg'),
('Yogur griego natural', 'lácteo', 59, 10.0, 3.6, 0.4, 'yogur.jpg');

INSERT INTO rutinas_ejercicios (titulo, lugar, objetivo, nivel_dificultad, descripcion) VALUES
('Full Body Sin Equipo', 'casa', 'perder_grasa', 'principiante', 'Rutina de peso corporal para quemar grasa sin necesidad de máquinas.'),
('Fuerza de Empuje (Push Day)', 'gimnasio', 'ganar_musculo', 'intermedio', 'Pecho, hombro y tríceps con pesas libres y máquinas.'),
('Movilidad y Hábito Diario', 'casa', 'mejorar_habitos', 'principiante', 'Rutina corta para crear el hábito de moverse todos los días.'),
('Hipertrofia de Piernas', 'gimnasio', 'ganar_musculo', 'avanzado', 'Sentadilla, prensa y zancadas para volumen de tren inferior.');

INSERT INTO ejercicios (rutina_id, nombre, series, repeticiones, video_demo, orden) VALUES
(1, 'Sentadillas', 4, '15-20', NULL, 1),
(1, 'Flexiones de pecho', 3, '10-12', NULL, 2),
(1, 'Plancha abdominal', 3, '30 seg', NULL, 3),
(1, 'Zancadas alternas', 3, '12 por pierna', NULL, 4),
(2, 'Press de banca', 4, '8-10', NULL, 1),
(2, 'Press militar', 3, '10-12', NULL, 2),
(2, 'Fondos en paralelas', 3, '10-12', NULL, 3),
(3, 'Estiramiento dinámico', 1, '5 min', NULL, 1),
(3, 'Caminata rápida', 1, '10 min', NULL, 2),
(4, 'Sentadilla con barra', 4, '8-10', NULL, 1),
(4, 'Prensa de piernas', 4, '10-12', NULL, 2),
(4, 'Zancadas con mancuernas', 3, '12 por pierna', NULL, 3);

INSERT INTO tienda_items (nombre, tipo, costo_xp, costo_coins, nivel_requerido, imagen_asset) VALUES
('Remera NutriFit', 'ropa_avatar', 0, 0, 0, 'avatar/remeras/remera-nutrifit.svg'),
('Camiseta Neón Verde', 'ropa_avatar', 0, 150, 2, 'camiseta_verde.png'),
('Aura de Hidratación', 'aura', 0, 300, 4, 'aura_azul.png'),
('Título: "Disciplinado"', 'titulo', 500, 0, 3, NULL),
('Marco Dorado de Perfil', 'marco_perfil', 0, 600, 6, 'marco_dorado.png'),
('Cupón 10% Tienda Externa', 'cupon', 0, 800, 5, 'cupon10.png'),
('Aura de Nivel Diamante', 'aura', 0, 1200, 10, 'aura_diamante.png');

-- =====================================================================
-- FIN DEL SCRIPT
-- =====================================================================
