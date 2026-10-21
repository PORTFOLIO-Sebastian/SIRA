-- BD simulada reconstruida desde el código PHP en htdocs/
-- Pensada para ejecutar localmente en XAMPP / Laragon / MySQL.
-- No es el dump real del hosting; es una aproximación funcional para desarrollo.

CREATE DATABASE IF NOT EXISTS ugelcvda_bd_repositorio
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ugelcvda_bd_repositorio;

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS comunicados_vistos;
DROP TABLE IF EXISTS comunicados;
DROP TABLE IF EXISTS envios;
DROP TABLE IF EXISTS asignaciones_especialistas;
DROP TABLE IF EXISTS asignaciones;
DROP TABLE IF EXISTS instituciones;
DROP TABLE IF EXISTS usuarios;
DROP TABLE IF EXISTS data_direccion;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dni VARCHAR(20) NOT NULL UNIQUE,
  password VARCHAR(255) NOT NULL,
  nombres VARCHAR(120) NOT NULL,
  ape_pat VARCHAR(120) NOT NULL,
  ape_mat VARCHAR(120) DEFAULT NULL,
  correo VARCHAR(150) DEFAULT NULL,
  telefono VARCHAR(30) DEFAULT NULL,
  rol ENUM('administrador','director','sub-director','docente','administrativo','especialista','inactivo') NOT NULL DEFAULT 'director',
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  encargatura VARCHAR(150) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE instituciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_local VARCHAR(50) NOT NULL,
  nombre_ie VARCHAR(255) NOT NULL,
  codigo_institucion VARCHAR(50) DEFAULT NULL,
  nivel_modalidad VARCHAR(100) DEFAULT NULL,
  tipo_gestion VARCHAR(100) DEFAULT NULL,
  direccion VARCHAR(255) DEFAULT NULL,
  centro_poblado VARCHAR(150) DEFAULT NULL,
  distrito VARCHAR(150) DEFAULT NULL,
  codigo_local_base VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_codigo_local (codigo_local),
  INDEX idx_nombre_ie (nombre_ie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asignaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  id_ie INT NOT NULL,
  fecha_inicio DATE DEFAULT NULL,
  fecha_fin DATE DEFAULT NULL,
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asignaciones_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_asignaciones_ie FOREIGN KEY (id_ie) REFERENCES instituciones(id) ON DELETE CASCADE,
  INDEX idx_asig_usuario_estado (id_usuario, estado),
  INDEX idx_asig_ie (id_ie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE asignaciones_especialistas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_usuario INT NOT NULL,
  id_institucion INT NOT NULL,
  sub_tipo VARCHAR(150) DEFAULT NULL,
  fecha_inicio DATE DEFAULT NULL,
  fecha_fin DATE DEFAULT NULL,
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_ae_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_ae_institucion FOREIGN KEY (id_institucion) REFERENCES instituciones(id) ON DELETE CASCADE,
  INDEX idx_ae_usuario_estado (id_usuario, estado),
  INDEX idx_ae_institucion (id_institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE envios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_ie INT NOT NULL,
  id_usuario_envio INT DEFAULT NULL,
  tipo_documento VARCHAR(80) NOT NULL,
  mes TINYINT DEFAULT NULL,
  anio YEAR NOT NULL,
  ruta_archivo VARCHAR(500) DEFAULT NULL,
  ruta_excel TEXT DEFAULT NULL,
  fecha_envio DATETIME DEFAULT CURRENT_TIMESTAMP,
  codigo_constancia VARCHAR(120) DEFAULT NULL,
  estado ENUM('Pendiente','Aprobado','Observado','Rechazado') NOT NULL DEFAULT 'Pendiente',
  observacion TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_envios_ie FOREIGN KEY (id_ie) REFERENCES instituciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_envios_usuario FOREIGN KEY (id_usuario_envio) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_envios_ie_anio_tipo (id_ie, anio, tipo_documento),
  INDEX idx_envios_estado (estado),
  INDEX idx_envios_fecha (fecha_envio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  titulo VARCHAR(255) NOT NULL,
  tipo VARCHAR(80) NOT NULL,
  id_usuario INT DEFAULT NULL,
  ruta_archivo VARCHAR(500) DEFAULT NULL,
  extension VARCHAR(20) DEFAULT NULL,
  fecha_publicacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_comunicados_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_comunicados_fecha (fecha_publicacion),
  INDEX idx_comunicados_tipo (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE comunicados_vistos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_comunicado INT NOT NULL,
  id_ie INT NOT NULL,
  fecha_visto DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cv_comunicado FOREIGN KEY (id_comunicado) REFERENCES comunicados(id) ON DELETE CASCADE,
  CONSTRAINT fk_cv_ie FOREIGN KEY (id_ie) REFERENCES instituciones(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comunicado_ie (id_comunicado, id_ie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE data_direccion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dni VARCHAR(20) NOT NULL,
  nombres VARCHAR(120) NOT NULL,
  ape_paterno VARCHAR(120) NOT NULL,
  ape_materno VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_direccion_dni (dni),
  INDEX idx_data_direccion_apellidos (ape_paterno, ape_materno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Datos ficticios mínimos para probar login, dashboard, envíos, administración y comunicados.
INSERT INTO usuarios (id, dni, password, nombres, ape_pat, ape_mat, correo, telefono, rol, estado, encargatura) VALUES
(1, '00000000', '123456', 'Admin', 'Sistema', 'Local', 'admin@local.test', '999999999', 'administrador', 'activo', 'Administrador'),
(2, '11111111', '123456', 'María', 'Torres', 'Quispe', 'directora@local.test', '988111111', 'director', 'activo', 'Directora'),
(3, '22222222', '123456', 'Luis', 'García', 'Ramos', 'subdirector@local.test', '988222222', 'sub-director', 'activo', 'Sub Dirección'),
(4, '33333333', '123456', 'Ana', 'Pérez', 'Loayza', 'especialista@local.test', '988333333', 'especialista', 'activo', 'Especialista AGP'),
(5, '44444444', '123456', 'Carlos', 'Mendoza', 'Flores', 'docente@local.test', '988444444', 'docente', 'activo', 'Docente');

INSERT INTO instituciones (id, codigo_local, nombre_ie, codigo_institucion, nivel_modalidad, tipo_gestion, direccion, centro_poblado, distrito, codigo_local_base) VALUES
(1, '18032026_EBR', 'IE Simulada San Pedro', 'IE001', 'Primaria', 'Pública', 'Av. Principal 123', 'Chincha Alta', 'Chincha Alta', '18032026'),
(2, '214204_EBR', 'IE Simulada Santa Rosa', 'IE002', 'Secundaria', 'Pública', 'Jr. Los Álamos 456', 'Pueblo Nuevo', 'Pueblo Nuevo', '214204'),
(3, '215840_EBR', 'IE Simulada José Olaya', 'IE003', 'Inicial', 'Pública', 'Calle Lima 789', 'Grocio Prado', 'Grocio Prado', '215840');

INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES
(2, 1, '2026-03-01', 'activo'),
(3, 2, '2026-03-01', 'activo'),
(5, 3, '2026-03-01', 'activo');

INSERT INTO asignaciones_especialistas (id_usuario, id_institucion, sub_tipo, fecha_inicio, estado) VALUES
(4, 1, 'Gestión Pedagógica', '2026-03-01', 'activo'),
(4, 2, 'Gestión Pedagógica', '2026-03-01', 'activo'),
(4, 3, 'Gestión Pedagógica', '2026-03-01', 'activo');

INSERT INTO envios (id_ie, id_usuario_envio, tipo_documento, mes, anio, ruta_archivo, ruta_excel, fecha_envio, codigo_constancia, estado, observacion) VALUES
(1, 2, 'Mensual', 3, 2026, 'uploads/18032026_EBR/2026/Asistencia mensual/demo.pdf', 'uploads/18032026_EBR/2026/Asistencia mensual/demo.xlsx', '2026-03-18 10:30:00', 'CONST-2026-1001-18032026_EBR', 'Pendiente', NULL),
(1, 2, 'RI', NULL, 2026, 'uploads/18032026_EBR/2026/Documentos de Gestión/demo_ri.pdf', NULL, '2026-03-19 11:00:00', 'CONST-2026-1002-18032026_EBR', 'Aprobado', 'Documento conforme'),
(2, 3, 'ECRE INICIO', NULL, 2026, 'N/A', 'uploads/214204_EBR/2026/Reportes ECRE/demo.xlsx', '2026-03-20 09:15:00', 'CONST-2026-1003-214204_EBR', 'Observado', 'Revisar formato del archivo');

INSERT INTO comunicados (titulo, tipo, id_usuario, ruta_archivo, extension, fecha_publicacion) VALUES
('Comunicado de prueba', 'Comunicado', 4, 'uploads/Publicaciones/Comunicados/Comunicado-00001-4.pdf', 'pdf', '2026-03-21 08:00:00'),
('Oficio de prueba', 'Oficio', 1, 'uploads/Publicaciones/Oficios/Oficio-00002-1.pdf', 'pdf', '2026-03-22 08:30:00');

INSERT INTO comunicados_vistos (id_comunicado, id_ie, fecha_visto) VALUES
(1, 1, '2026-03-22 09:00:00');

INSERT INTO data_direccion (dni, nombres, ape_paterno, ape_materno) VALUES
('55555555', 'Rosa', 'Salazar', 'Núñez'),
('66666666', 'Miguel', 'Vargas', 'Cruz');
