CREATE TABLE IF NOT EXISTS asistencia_personal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_ie INT NOT NULL,
  dni VARCHAR(20) NOT NULL,
  apellidos_nombres VARCHAR(255) NOT NULL,
  cargo VARCHAR(120) NOT NULL,
  condicion_laboral VARCHAR(80) NOT NULL,
  jornada_laboral VARCHAR(30) NOT NULL,
  nivel_educativo VARCHAR(80) NOT NULL,
  observaciones VARCHAR(255) DEFAULT NULL,
  estado ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asistencia_personal_ie
    FOREIGN KEY (id_ie) REFERENCES instituciones(id)
    ON DELETE CASCADE,
  UNIQUE KEY uq_asistencia_personal_ie_dni (id_ie, dni),
  INDEX idx_asistencia_personal_ie_estado (id_ie, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_mensual (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_ie INT NOT NULL,
  mes TINYINT NOT NULL,
  anio YEAR NOT NULL,
  ugel VARCHAR(120) NOT NULL DEFAULT 'CHINCHA',
  region VARCHAR(120) DEFAULT 'ICA',
  provincia VARCHAR(120) DEFAULT 'CHINCHA',
  distrito VARCHAR(120) DEFAULT NULL,
  nombre_ie VARCHAR(255) NOT NULL,
  codigo_local VARCHAR(50) NOT NULL,
  nivel_modalidad VARCHAR(100) DEFAULT NULL,
  estado ENUM('borrador','generado','enviado','bloqueado') NOT NULL DEFAULT 'borrador',
  id_envio INT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asistencia_mensual_ie
    FOREIGN KEY (id_ie) REFERENCES instituciones(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_asistencia_mensual_usuario
    FOREIGN KEY (created_by) REFERENCES usuarios(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_asistencia_mensual_envio
    FOREIGN KEY (id_envio) REFERENCES envios(id)
    ON DELETE SET NULL,
  UNIQUE KEY uq_asistencia_mensual_ie_periodo (id_ie, mes, anio),
  INDEX idx_asistencia_mensual_estado (estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_detalle (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_asistencia_mensual INT NOT NULL,
  id_personal INT NOT NULL,
  fecha DATE NOT NULL,
  dia_mes TINYINT NOT NULL,
  codigo_asistencia CHAR(1) NOT NULL,
  registrado_por INT DEFAULT NULL,
  ip_registro VARCHAR(45) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_asistencia_detalle_mensual
    FOREIGN KEY (id_asistencia_mensual) REFERENCES asistencia_mensual(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_asistencia_detalle_personal
    FOREIGN KEY (id_personal) REFERENCES asistencia_personal(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_asistencia_detalle_usuario
    FOREIGN KEY (registrado_por) REFERENCES usuarios(id)
    ON DELETE SET NULL,
  UNIQUE KEY uq_asistencia_detalle_dia (id_asistencia_mensual, id_personal, dia_mes),
  INDEX idx_asistencia_detalle_personal (id_personal),
  INDEX idx_asistencia_detalle_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS asistencia_auditoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  tabla_afectada VARCHAR(80) NOT NULL,
  id_registro_afectado INT NOT NULL,
  accion VARCHAR(80) NOT NULL,
  valor_anterior TEXT DEFAULT NULL,
  valor_nuevo TEXT DEFAULT NULL,
  id_usuario INT DEFAULT NULL,
  ip_usuario VARCHAR(45) DEFAULT NULL,
  user_agent VARCHAR(255) DEFAULT NULL,
  fecha_accion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_asistencia_auditoria_usuario
    FOREIGN KEY (id_usuario) REFERENCES usuarios(id)
    ON DELETE SET NULL,
  INDEX idx_asistencia_auditoria_registro (tabla_afectada, id_registro_afectado),
  INDEX idx_asistencia_auditoria_fecha (fecha_accion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
