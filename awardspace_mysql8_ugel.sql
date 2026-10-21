-- UGEL Chincha - esquema MySQL 8.0 para AwardSpace.
-- Importar este archivo en el gestor MySQL/phpMyAdmin de AwardSpace.
-- No incluye CREATE DATABASE porque AwardSpace crea la base desde el panel.

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS fm_auditoria;
DROP TABLE IF EXISTS fm_documentos;
DROP TABLE IF EXISTS fm_historial_estados;
DROP TABLE IF EXISTS fm_respuestas_especialista;
DROP TABLE IF EXISTS fm_evaluacion_visitas;
DROP TABLE IF EXISTS fm_evaluaciones;
DROP TABLE IF EXISTS fm_respuestas_director;
DROP TABLE IF EXISTS fm_registros;
DROP TABLE IF EXISTS fm_consentimientos;
DROP TABLE IF EXISTS fm_prorrogas;
DROP TABLE IF EXISTS fm_rubricas;
DROP TABLE IF EXISTS fm_items;
DROP TABLE IF EXISTS fm_secciones;
DROP TABLE IF EXISTS fm_tipos_ficha_versiones;
DROP TABLE IF EXISTS fm_tipos_ficha;
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
  rol ENUM('administrador','director','sub-director','docente','administrativo','especialista','jefe_agp','inactivo') NOT NULL DEFAULT 'director',
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  encargatura VARCHAR(150) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comunicados_vistos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_comunicado INT NOT NULL,
  id_ie INT NOT NULL,
  fecha_visto DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cv_comunicado FOREIGN KEY (id_comunicado) REFERENCES comunicados(id) ON DELETE CASCADE,
  CONSTRAINT fk_cv_ie FOREIGN KEY (id_ie) REFERENCES instituciones(id) ON DELETE CASCADE,
  UNIQUE KEY uq_comunicado_ie (id_comunicado, id_ie)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE data_direccion (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dni VARCHAR(20) NOT NULL,
  nombres VARCHAR(120) NOT NULL,
  ape_paterno VARCHAR(120) NOT NULL,
  ape_materno VARCHAR(120) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_data_direccion_dni (dni),
  INDEX idx_data_direccion_apellidos (ape_paterno, ape_materno)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_tipos_ficha (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL UNIQUE,
  descripcion TEXT DEFAULT NULL,
  anio YEAR NOT NULL,
  estado ENUM('BORRADOR','ACTIVO','INACTIVO') NOT NULL DEFAULT 'BORRADOR',
  fecha_inicio DATE DEFAULT NULL,
  fecha_limite DATE DEFAULT NULL,
  texto_consentimiento TEXT DEFAULT NULL,
  creado_por INT DEFAULT NULL,
  actualizado_por INT DEFAULT NULL,
  fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_tipo_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_fm_tipo_actualizado_por FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_tipos_estado (estado),
  INDEX idx_fm_tipos_anio (anio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_tipos_ficha_versiones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_tipo_ficha INT NOT NULL,
  version INT NOT NULL DEFAULT 1,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  estado ENUM('BORRADOR','PUBLICADA','INACTIVA') NOT NULL DEFAULT 'BORRADOR',
  motivo_version TEXT DEFAULT NULL,
  total_items_planificados INT DEFAULT NULL,
  estructura_configurada TINYINT(1) NOT NULL DEFAULT 0,
  creado_por INT DEFAULT NULL,
  publicado_por INT DEFAULT NULL,
  fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  fecha_publicacion DATETIME DEFAULT NULL,
  CONSTRAINT fk_fm_version_tipo FOREIGN KEY (id_tipo_ficha) REFERENCES fm_tipos_ficha(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_version_creado_por FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_fm_version_publicado_por FOREIGN KEY (publicado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fm_version_tipo_num (id_tipo_ficha, version),
  INDEX idx_fm_version_tipo_estado (id_tipo_ficha, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_secciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_version_ficha INT NOT NULL,
  id_seccion_padre INT DEFAULT NULL,
  titulo VARCHAR(255) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  orden INT NOT NULL DEFAULT 1,
  nivel INT NOT NULL DEFAULT 1,
  estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  CONSTRAINT fk_fm_seccion_version FOREIGN KEY (id_version_ficha) REFERENCES fm_tipos_ficha_versiones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_seccion_padre FOREIGN KEY (id_seccion_padre) REFERENCES fm_secciones(id) ON DELETE CASCADE,
  INDEX idx_fm_secciones_version (id_version_ficha, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_version_ficha INT NOT NULL,
  id_seccion INT DEFAULT NULL,
  numero_item VARCHAR(30) NOT NULL,
  orden INT NOT NULL DEFAULT 1,
  titulo VARCHAR(255) DEFAULT NULL,
  pregunta TEXT NOT NULL,
  tipo_respuesta VARCHAR(60) NOT NULL,
  obligatorio TINYINT(1) NOT NULL DEFAULT 1,
  permite_no_aplica TINYINT(1) NOT NULL DEFAULT 0,
  puntaje_maximo DECIMAL(10,2) NOT NULL DEFAULT 0,
  criterio_instruccion TEXT DEFAULT NULL,
  estado ENUM('ACTIVO','INACTIVO') NOT NULL DEFAULT 'ACTIVO',
  CONSTRAINT fk_fm_item_version FOREIGN KEY (id_version_ficha) REFERENCES fm_tipos_ficha_versiones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_item_seccion FOREIGN KEY (id_seccion) REFERENCES fm_secciones(id) ON DELETE SET NULL,
  INDEX idx_fm_items_version_estado (id_version_ficha, estado),
  INDEX idx_fm_items_seccion (id_seccion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_rubricas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_item INT NOT NULL,
  valor DECIMAL(10,2) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  CONSTRAINT fk_fm_rubrica_item FOREIGN KEY (id_item) REFERENCES fm_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fm_rubrica_item_valor (id_item, valor)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_prorrogas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_tipo_ficha INT NOT NULL,
  id_institucion INT DEFAULT NULL,
  fecha_limite_original DATE DEFAULT NULL,
  nueva_fecha_limite DATETIME NOT NULL,
  motivo TEXT DEFAULT NULL,
  estado ENUM('ACTIVA','INACTIVA') NOT NULL DEFAULT 'ACTIVA',
  creado_por INT DEFAULT NULL,
  fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_prorroga_tipo FOREIGN KEY (id_tipo_ficha) REFERENCES fm_tipos_ficha(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_prorroga_ie FOREIGN KEY (id_institucion) REFERENCES instituciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_prorroga_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_prorrogas_tipo_estado (id_tipo_ficha, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_consentimientos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_tipo_ficha INT NOT NULL,
  id_usuario INT NOT NULL,
  id_institucion INT NOT NULL,
  aceptado TINYINT(1) NOT NULL DEFAULT 0,
  texto_consentimiento TEXT DEFAULT NULL,
  ip_usuario VARCHAR(80) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_consent_tipo FOREIGN KEY (id_tipo_ficha) REFERENCES fm_tipos_ficha(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_consent_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_consent_ie FOREIGN KEY (id_institucion) REFERENCES instituciones(id) ON DELETE CASCADE,
  INDEX idx_fm_consent_lookup (id_tipo_ficha, id_usuario, id_institucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_registros (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_envio VARCHAR(120) NOT NULL UNIQUE,
  id_tipo_ficha INT NOT NULL,
  id_version_ficha INT NOT NULL,
  id_usuario INT NOT NULL,
  id_institucion INT NOT NULL,
  id_especialista INT DEFAULT NULL,
  version_envio INT NOT NULL DEFAULT 1,
  estado ENUM('BORRADOR','ENVIADO','CONFORME','EN_REVISION','OBSERVADO','RECHAZADO','REVISADO') NOT NULL DEFAULT 'BORRADOR',
  observacion_general TEXT DEFAULT NULL,
  fuera_plazo TINYINT(1) NOT NULL DEFAULT 0,
  fecha_borrador DATETIME DEFAULT NULL,
  fecha_envio DATETIME DEFAULT NULL,
  latitud DECIMAL(10,8) DEFAULT NULL,
  longitud DECIMAL(11,8) DEFAULT NULL,
  precision_metros DECIMAL(10,2) DEFAULT NULL,
  fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  puntaje_total DECIMAL(10,2) NOT NULL DEFAULT 0,
  puntaje_maximo DECIMAL(10,2) NOT NULL DEFAULT 0,
  porcentaje DECIMAL(10,2) NOT NULL DEFAULT 0,
  escala_valorativa VARCHAR(80) DEFAULT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  CONSTRAINT fk_fm_reg_tipo FOREIGN KEY (id_tipo_ficha) REFERENCES fm_tipos_ficha(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fm_reg_version FOREIGN KEY (id_version_ficha) REFERENCES fm_tipos_ficha_versiones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fm_reg_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fm_reg_ie FOREIGN KEY (id_institucion) REFERENCES instituciones(id) ON DELETE RESTRICT,
  CONSTRAINT fk_fm_reg_especialista FOREIGN KEY (id_especialista) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_reg_tipo_ie (id_tipo_ficha, id_institucion, activo),
  INDEX idx_fm_reg_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_respuestas_director (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_registro INT NOT NULL,
  id_item INT NOT NULL,
  no_aplica TINYINT(1) NOT NULL DEFAULT 0,
  respuesta_escala DECIMAL(10,2) DEFAULT NULL,
  respuesta_numero DECIMAL(14,2) DEFAULT NULL,
  respuesta_texto TEXT DEFAULT NULL,
  respuesta_si_no VARCHAR(20) DEFAULT NULL,
  respuesta_fecha DATE DEFAULT NULL,
  observacion TEXT DEFAULT NULL,
  fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_resp_dir_reg FOREIGN KEY (id_registro) REFERENCES fm_registros(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_resp_dir_item FOREIGN KEY (id_item) REFERENCES fm_items(id) ON DELETE CASCADE,
  UNIQUE KEY uq_fm_resp_dir (id_registro, id_item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_evaluaciones (
  id INT AUTO_INCREMENT PRIMARY KEY,
  codigo_evaluacion VARCHAR(120) NOT NULL UNIQUE,
  id_registro INT NOT NULL,
  id_especialista INT NOT NULL,
  estado_evaluacion ENUM('EN_REVISION','FINALIZADA') NOT NULL DEFAULT 'EN_REVISION',
  resultado_estado VARCHAR(60) DEFAULT NULL,
  observacion_general TEXT DEFAULT NULL,
  compromiso_director TEXT DEFAULT NULL,
  compromiso_especialista TEXT DEFAULT NULL,
  puntaje_total_eval DECIMAL(10,2) NOT NULL DEFAULT 0,
  puntaje_maximo_eval DECIMAL(10,2) NOT NULL DEFAULT 0,
  porcentaje_eval DECIMAL(10,2) NOT NULL DEFAULT 0,
  escala_valorativa_eval VARCHAR(80) DEFAULT NULL,
  fecha_inicio DATETIME DEFAULT NULL,
  latitud DECIMAL(10,8) DEFAULT NULL,
  longitud DECIMAL(11,8) DEFAULT NULL,
  precision_metros DECIMAL(10,2) DEFAULT NULL,
  fecha_creacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  fecha_actualizacion DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fecha_finalizacion DATETIME DEFAULT NULL,
  CONSTRAINT fk_fm_eval_reg FOREIGN KEY (id_registro) REFERENCES fm_registros(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_eval_especialista FOREIGN KEY (id_especialista) REFERENCES usuarios(id) ON DELETE RESTRICT,
  INDEX idx_fm_eval_registro (id_registro),
  INDEX idx_fm_eval_especialista (id_especialista)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_evaluacion_visitas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_evaluacion INT NOT NULL,
  numero_visita INT NOT NULL DEFAULT 1,
  fecha_visita DATE NOT NULL,
  estado_visita ENUM('EN_PROGRESO','CERRADA') NOT NULL DEFAULT 'EN_PROGRESO',
  iniciada_por INT DEFAULT NULL,
  fecha_inicio DATETIME DEFAULT NULL,
  fecha_guardado DATETIME DEFAULT NULL,
  fecha_cierre DATETIME DEFAULT NULL,
  CONSTRAINT fk_fm_visita_eval FOREIGN KEY (id_evaluacion) REFERENCES fm_evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_visita_usuario FOREIGN KEY (iniciada_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fm_visita_eval_num (id_evaluacion, numero_visita)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_respuestas_especialista (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_evaluacion INT NOT NULL,
  id_item INT NOT NULL,
  id_visita INT DEFAULT NULL,
  no_aplica TINYINT(1) NOT NULL DEFAULT 0,
  respuesta_escala DECIMAL(10,2) DEFAULT NULL,
  respuesta_numero DECIMAL(14,2) DEFAULT NULL,
  respuesta_texto TEXT DEFAULT NULL,
  respuesta_si_no VARCHAR(20) DEFAULT NULL,
  respuesta_fecha DATE DEFAULT NULL,
  observacion TEXT DEFAULT NULL,
  evidencia TEXT DEFAULT NULL,
  fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_resp_esp_eval FOREIGN KEY (id_evaluacion) REFERENCES fm_evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_resp_esp_item FOREIGN KEY (id_item) REFERENCES fm_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_resp_esp_visita FOREIGN KEY (id_visita) REFERENCES fm_evaluacion_visitas(id) ON DELETE SET NULL,
  UNIQUE KEY uq_fm_resp_esp (id_evaluacion, id_item)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_historial_estados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_registro INT NOT NULL,
  id_evaluacion INT DEFAULT NULL,
  estado_anterior VARCHAR(40) DEFAULT NULL,
  estado_nuevo VARCHAR(40) NOT NULL,
  comentario TEXT DEFAULT NULL,
  cambiado_por INT DEFAULT NULL,
  fecha_cambio DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_hist_reg FOREIGN KEY (id_registro) REFERENCES fm_registros(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_hist_eval FOREIGN KEY (id_evaluacion) REFERENCES fm_evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_hist_usuario FOREIGN KEY (cambiado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_hist_reg_fecha (id_registro, fecha_cambio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_documentos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_registro INT DEFAULT NULL,
  id_evaluacion INT DEFAULT NULL,
  tipo_documento VARCHAR(80) NOT NULL,
  codigo_documento VARCHAR(120) DEFAULT NULL,
  ruta_archivo VARCHAR(500) NOT NULL,
  generado_por INT DEFAULT NULL,
  fecha_generacion DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_doc_reg FOREIGN KEY (id_registro) REFERENCES fm_registros(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_doc_eval FOREIGN KEY (id_evaluacion) REFERENCES fm_evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_fm_doc_usuario FOREIGN KEY (generado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_doc_reg_tipo (id_registro, tipo_documento),
  INDEX idx_fm_doc_eval_tipo (id_evaluacion, tipo_documento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fm_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tabla_afectada VARCHAR(120) NOT NULL,
  id_registro_afectado INT DEFAULT NULL,
  accion VARCHAR(80) NOT NULL,
  descripcion TEXT DEFAULT NULL,
  datos_anteriores JSON DEFAULT NULL,
  datos_nuevos JSON DEFAULT NULL,
  id_usuario INT DEFAULT NULL,
  ip_usuario VARCHAR(80) DEFAULT NULL,
  user_agent TEXT DEFAULT NULL,
  fecha_accion DATETIME DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fm_audit_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_fm_audit_tabla_reg (tabla_afectada, id_registro_afectado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO usuarios (id, dni, password, nombres, ape_pat, ape_mat, correo, telefono, rol, estado, encargatura) VALUES
(1, '00000000', '123456', 'Admin', 'Sistema', 'Local', 'admin@local.test', '999999999', 'administrador', 'activo', 'Administrador'),
(2, '11111111', '123456', 'Maria', 'Torres', 'Quispe', 'directora@local.test', '988111111', 'director', 'activo', 'Directora'),
(3, '22222222', '123456', 'Luis', 'Garcia', 'Ramos', 'subdirector@local.test', '988222222', 'sub-director', 'activo', 'Sub Direccion'),
(4, '33333333', '123456', 'Ana', 'Perez', 'Loayza', 'especialista@local.test', '988333333', 'especialista', 'activo', 'Especialista AGP'),
(5, '44444444', '123456', 'Carlos', 'Mendoza', 'Flores', 'docente@local.test', '988444444', 'docente', 'activo', 'Docente'),
(6, '77777777', '123456', 'Jefe', 'AGP', 'Sistema', 'jefe.agp@local.test', '988777777', 'jefe_agp', 'activo', 'Jefe AGP');

INSERT INTO instituciones (id, codigo_local, nombre_ie, codigo_institucion, nivel_modalidad, tipo_gestion, direccion, centro_poblado, distrito, codigo_local_base) VALUES
(1, '18032026_EBR', 'IE Simulada San Pedro', 'IE001', 'Primaria', 'Publica', 'Av. Principal 123', 'Chincha Alta', 'Chincha Alta', '18032026'),
(2, '214204_EBR', 'IE Simulada Santa Rosa', 'IE002', 'Secundaria', 'Publica', 'Jr. Los Alamos 456', 'Pueblo Nuevo', 'Pueblo Nuevo', '214204'),
(3, '215840_EBR', 'IE Simulada Jose Olaya', 'IE003', 'Inicial', 'Publica', 'Calle Lima 789', 'Grocio Prado', 'Grocio Prado', '215840');

INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES
(2, 1, '2026-03-01', 'activo'),
(3, 2, '2026-03-01', 'activo'),
(5, 3, '2026-03-01', 'activo');

INSERT INTO asignaciones_especialistas (id_usuario, id_institucion, sub_tipo, fecha_inicio, estado) VALUES
(4, 1, 'Gestion Pedagogica', '2026-03-01', 'activo'),
(4, 2, 'Gestion Pedagogica', '2026-03-01', 'activo'),
(4, 3, 'Gestion Pedagogica', '2026-03-01', 'activo');

INSERT INTO envios (id_ie, id_usuario_envio, tipo_documento, mes, anio, ruta_archivo, ruta_excel, fecha_envio, codigo_constancia, estado, observacion) VALUES
(1, 2, 'Mensual', 3, 2026, 'uploads/18032026_EBR/2026/Asistencia mensual/demo.pdf', 'uploads/18032026_EBR/2026/Asistencia mensual/demo.xlsx', '2026-03-18 10:30:00', 'CONST-2026-1001-18032026_EBR', 'Pendiente', NULL),
(1, 2, 'RI', NULL, 2026, 'uploads/18032026_EBR/2026/Documentos de Gestion/demo_ri.pdf', NULL, '2026-03-19 11:00:00', 'CONST-2026-1002-18032026_EBR', 'Aprobado', 'Documento conforme'),
(2, 3, 'ECRE INICIO', NULL, 2026, 'N/A', 'uploads/214204_EBR/2026/Reportes ECRE/demo.xlsx', '2026-03-20 09:15:00', 'CONST-2026-1003-214204_EBR', 'Observado', 'Revisar formato del archivo');

INSERT INTO comunicados (titulo, tipo, id_usuario, ruta_archivo, extension, fecha_publicacion) VALUES
('Comunicado de prueba', 'Comunicado', 4, 'uploads/Publicaciones/Comunicados/Comunicado-00001-4.pdf', 'pdf', '2026-03-21 08:00:00'),
('Oficio de prueba', 'Oficio', 1, 'uploads/Publicaciones/Oficios/Oficio-00002-1.pdf', 'pdf', '2026-03-22 08:30:00');

INSERT INTO comunicados_vistos (id_comunicado, id_ie, fecha_visto) VALUES
(1, 1, '2026-03-22 09:00:00');

INSERT INTO data_direccion (dni, nombres, ape_paterno, ape_materno) VALUES
('55555555', 'Rosa', 'Salazar', 'Nunez'),
('66666666', 'Miguel', 'Vargas', 'Cruz');
