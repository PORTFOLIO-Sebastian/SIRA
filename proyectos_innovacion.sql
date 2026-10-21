-- ============================================================
-- SPRINT 2: MÓDULO DE PROYECTOS DE INNOVACIÓN PEDAGÓGICA
-- Normativa MINEDU / FONDEP - UGEL Chincha
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tabla Principal de Proyectos de Innovación
CREATE TABLE IF NOT EXISTS `proyectos_innovacion` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `codigo_proyecto` VARCHAR(30) NOT NULL UNIQUE,
    `id_institucion` INT NOT NULL,
    `id_usuario_director` INT NOT NULL,
    `id_especialista_asignado` INT NULL,
    `anio` YEAR NOT NULL DEFAULT 2026,
    `titulo` VARCHAR(255) NOT NULL,
    `linea_tematica` ENUM(
        'INNOVACION_PEDAGOGICA',
        'TECNOLOGIA_EDUCATIVA_TIC',
        'CONVIVENCIA_CLIMA_ESCOLAR',
        'CIENCIA_AMBIENTE_SOSTENIBILIDAD',
        'ARTE_CULTURA_DEPORTE',
        'INCLUSION_ATENCION_DIVERSIDAD'
    ) NOT NULL DEFAULT 'INNOVACION_PEDAGOGICA',
    `problema_identificado` TEXT NOT NULL,
    `objetivo_general` TEXT NOT NULL,
    `objetivos_especificos` TEXT NULL,
    `beneficiarios_estudiantes` INT NOT NULL DEFAULT 0,
    `beneficiarios_docentes` INT NOT NULL DEFAULT 0,
    `presupuesto_referencial` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `fecha_inicio` DATE NOT NULL,
    `fecha_fin` DATE NOT NULL,
    `porcentaje_avance` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `estado` ENUM(
        'FORMULACION',
        'ENVIADO',
        'OBSERVADO',
        'APROBADO',
        'EN_EJECUCION',
        'CONCLUIDO'
    ) NOT NULL DEFAULT 'FORMULACION',
    `observaciones_revision` TEXT NULL,
    `fecha_envio` DATETIME NULL,
    `fecha_aprobacion` DATETIME NULL,
    `activo` TINYINT(1) NOT NULL DEFAULT 1,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_actualizacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_pi_ie` (`id_institucion`),
    INDEX `idx_pi_usuario` (`id_usuario_director`),
    INDEX `idx_pi_estado` (`estado`),
    INDEX `idx_pi_anio` (`anio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Cronograma de Actividades del Proyecto
CREATE TABLE IF NOT EXISTS `proyectos_actividades` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_proyecto` INT NOT NULL,
    `numero_actividad` INT NOT NULL DEFAULT 1,
    `descripcion` VARCHAR(255) NOT NULL,
    `responsable` VARCHAR(150) NOT NULL,
    `fecha_inicio_programada` DATE NOT NULL,
    `fecha_fin_programada` DATE NOT NULL,
    `porcentaje_avance` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `estado` ENUM('PENDIENTE', 'EN_PROCESO', 'COMPLETADA', 'REPROGRAMADA') NOT NULL DEFAULT 'PENDIENTE',
    `evidencia_resumen` VARCHAR(255) NULL,
    `fecha_creacion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pa_proyecto` (`id_proyecto`),
    CONSTRAINT `fk_pa_proyecto` FOREIGN KEY (`id_proyecto`) 
        REFERENCES `proyectos_innovacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Evidencias y Documentación Adjunta
CREATE TABLE IF NOT EXISTS `proyectos_evidencias` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_proyecto` INT NOT NULL,
    `id_actividad` INT NULL,
    `titulo` VARCHAR(150) NOT NULL,
    `tipo_archivo` ENUM('PDF', 'IMAGEN', 'ENLACE', 'DOCUMENTO') NOT NULL DEFAULT 'PDF',
    `ruta_archivo` VARCHAR(255) NOT NULL,
    `descripcion` TEXT NULL,
    `subido_por` INT NOT NULL,
    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_pe_proyecto` (`id_proyecto`),
    CONSTRAINT `fk_pe_proyecto` FOREIGN KEY (`id_proyecto`) 
        REFERENCES `proyectos_innovacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Trazabilidad e Historial de Estados
CREATE TABLE IF NOT EXISTS `proyectos_historial` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `id_proyecto` INT NOT NULL,
    `estado_anterior` VARCHAR(50) NOT NULL,
    `estado_nuevo` VARCHAR(50) NOT NULL,
    `comentario` TEXT NULL,
    `id_usuario` INT NOT NULL,
    `fecha_registro` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ph_proyecto` (`id_proyecto`),
    CONSTRAINT `fk_ph_proyecto` FOREIGN KEY (`id_proyecto`) 
        REFERENCES `proyectos_innovacion` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DATOS INICIALES DE DEMOSTRACIÓN (Para visualización inmediata)
-- ============================================================

-- Proyecto 1: IE San Pedro (En formulación / enviado)
INSERT INTO `proyectos_innovacion` (
    `id`, `codigo_proyecto`, `id_institucion`, `id_usuario_director`, `id_especialista_asignado`,
    `anio`, `titulo`, `linea_tematica`, `problema_identificado`, `objetivo_general`,
    `objetivos_especificos`, `beneficiarios_estudiantes`, `beneficiarios_docentes`,
    `presupuesto_referencial`, `fecha_inicio`, `fecha_fin`, `porcentaje_avance`,
    `estado`, `observaciones_revision`, `fecha_envio`, `fecha_creacion`
) VALUES (
    1, 'PI-2026-CH-001', 1, 2, 4,
    2026, 'Biohuerto Escolar Pedagógico: Aprender Cultivando y Cuidando Nuestro Entorno',
    'CIENCIA_AMBIENTE_SOSTENIBILIDAD',
    'Bajo rendimiento en indagación científica y escasa conciencia ecológica en los estudiantes de secundaria.',
    'Implementar un biohuerto hidropónico y sustentable como laboratorio viviente para fortalecer competencias científicas y ambientales.',
    '- Habilitar 3 módulos de cultivo hidropónico y abono orgánico.\n- Articular 6 unidades de aprendizaje interdisciplinarias.\n- Promover brigadas estudiantiles de biohuerto sostenible.',
    420, 18, 4500.00, '2026-04-01', '2026-11-30', 35.00,
    'ENVIADO', 'Ficha y plan inicial cargados para revisión del especialista UGEL.', '2026-05-15 10:30:00', NOW()
) ON DUPLICATE KEY UPDATE `titulo` = VALUES(`titulo`);

-- Actividades Proyecto 1
INSERT INTO `proyectos_actividades` (
    `id_proyecto`, `numero_actividad`, `descripcion`, `responsable`,
    `fecha_inicio_programada`, `fecha_fin_programada`, `porcentaje_avance`, `estado`, `evidencia_resumen`
) VALUES
(1, 1, 'Sensibilización de la comunidad escolar y conformación del comité de biohuerto', 'Prof. Juan Pérez (Ciencia y Tecnología)', '2026-04-05', '2026-04-20', 100.00, 'COMPLETADA', 'Acta de asamblea y fotos'),
(1, 2, 'Construcción y acondicionamiento de bancales e instalación de riego por goteo', 'Comité Ambiental y Padres de Familia', '2026-04-22', '2026-05-20', 70.00, 'EN_PROCESO', 'Registro fotográfico de obras'),
(1, 3, 'Talleres pedagógicos de siembra, rotación y fichas de indagación vivencial', 'Docentes de CTA', '2026-05-25', '2026-09-30', 0.00, 'PENDIENTE', 'Pendiente de inicio');

-- Historial Proyecto 1
INSERT INTO `proyectos_historial` (`id_proyecto`, `estado_anterior`, `estado_nuevo`, `comentario`, `id_usuario`, `fecha_registro`)
VALUES
(1, 'FORMULACION', 'ENVIADO', 'Proyecto remitido para revisión de la UGEL Chincha.', 2, '2026-05-15 10:30:00');

-- Proyecto 2: IE Santa Rosa (Aprobado y en Ejecución)
INSERT INTO `proyectos_innovacion` (
    `id`, `codigo_proyecto`, `id_institucion`, `id_usuario_director`, `id_especialista_asignado`,
    `anio`, `titulo`, `linea_tematica`, `problema_identificado`, `objetivo_general`,
    `objetivos_especificos`, `beneficiarios_estudiantes`, `beneficiarios_docentes`,
    `presupuesto_referencial`, `fecha_inicio`, `fecha_fin`, `porcentaje_avance`,
    `estado`, `observaciones_revision`, `fecha_envio`, `fecha_aprobacion`, `fecha_creacion`
) VALUES (
    2, 'PI-2026-CH-002', 2, 3, 4,
    2026, 'Club de Robótica y Pensamiento Computacional para la Resolución de Problemas',
    'TECNOLOGIA_EDUCATIVA_TIC',
    'Dificultades en razonamiento lógico-matemático y escasa integración práctica de programación y tecnología.',
    'Desarrollar competencias de programación en bloques y robótica educativa articulada con matemática y comunicación.',
    '- Formación de 8 docentes en kits Arduino y Scratch.\n- Implementación de 2 talleres extracurriculares por grado.\n- Feria escolar de prototipos y soluciones tecnológicas.',
    560, 24, 7200.00, '2026-03-15', '2026-12-15', 60.00,
    'APROBADO', 'Proyecto validado y aprobado conforme a la normativa de innovación MINEDU/FONDEP.',
    '2026-03-20 09:00:00', '2026-03-25 15:00:00', NOW()
) ON DUPLICATE KEY UPDATE `titulo` = VALUES(`titulo`);

-- Actividades Proyecto 2
INSERT INTO `proyectos_actividades` (
    `id_proyecto`, `numero_actividad`, `descripcion`, `responsable`,
    `fecha_inicio_programada`, `fecha_fin_programada`, `porcentaje_avance`, `estado`, `evidencia_resumen`
) VALUES
(2, 1, 'Taller de capacitación docente en Scratch y robótica con Arduino', 'Coordinador DAIP / Innovación', '2026-04-01', '2026-04-18', 100.00, 'COMPLETADA', 'Lista de asistencia y certificados'),
(2, 2, 'Adquisición y acondicionamiento de kits de sensores y módulos', 'Comité de Gestión de Recursos', '2026-04-20', '2026-05-10', 100.00, 'COMPLETADA', 'Acta de recepción y pecosa'),
(2, 3, 'Sesiones de aula articuladas y desarrollo de proyectos por equipos', 'Docentes tutores', '2026-05-15', '2026-10-30', 40.00, 'EN_PROCESO', 'Guías de aprendizaje');

-- Historial Proyecto 2
INSERT INTO `proyectos_historial` (`id_proyecto`, `estado_anterior`, `estado_nuevo`, `comentario`, `id_usuario`, `fecha_registro`)
VALUES
(2, 'FORMULACION', 'ENVIADO', 'Presentación del proyecto para financiamiento y visto bueno UGEL.', 3, '2026-03-20 09:00:00'),
(2, 'ENVIADO', 'APROBADO', 'Proyecto aprobado con felicitación por su pertinencia pedagógica y enfoque STEM.', 4, '2026-03-25 15:00:00');

-- Evidencias Iniciales de Demostración
INSERT INTO `proyectos_evidencias` (`id`, `id_proyecto`, `id_actividad`, `titulo`, `tipo_archivo`, `ruta_archivo`, `descripcion`, `subido_por`, `fecha_registro`)
VALUES
(1, 1, 1, 'Plan de Trabajo y Matriz de Consistencia - Biohuerto Escolar', 'PDF', 'uploads/proyectos/1/PI_2026_CH_001/Plan_Trabajo_Biohuerto_2026.pdf', 'Documento formal de planificación pedagógica e indagación vivencial.', 2, '2026-05-12 10:00:00'),
(2, 2, 1, 'Proyecto Oficial FONDEP - Club de Robótica y Pensamiento Computacional', 'PDF', 'uploads/proyectos/2/PI_2026_CH_002/Proyecto_Robotica_STEM_2026.pdf', 'Expediente técnico completo aprobado por el comité de innovación y CONEI.', 3, '2026-03-18 11:30:00')
ON DUPLICATE KEY UPDATE `titulo` = VALUES(`titulo`);

SET FOREIGN_KEY_CHECKS = 1;

