-- ============================================================
-- DEMO: FICHAS DE MONITOREO SIMULADAS - Sprint 1
-- Sistema de Monitoreo - UGEL Chincha
-- ============================================================
-- Carga 3 fichas en distintos estados para demostracion del flujo completo:
--   REGISTRO 1 - IE 1 (IE Simulada San Pedro) - Estado: ENVIADO
--               El Director ya autoevaluo, listo para que el Especialista evalue.
--
--   REGISTRO 2 - IE 2 (IE Simulada Santa Rosa) - Estado: CONFORME
--               Flujo completo: Director autoevaluo + Especialista evaluo.
--
--   REGISTRO 3 - IE 3 (IE Simulada Jose Olaya) - Estado: BORRADOR
--               El Director esta llenando la ficha, no ha enviado aun.
--
-- PRE-REQUISITO: Haber ejecutado cargar_ficha_real_2026.sql antes de este script.
-- ============================================================

SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;

-- Limpieza segura de demos anteriores
DELETE FROM fm_respuestas_especialista
WHERE
    id_evaluacion IN (
        SELECT id
        FROM fm_evaluaciones
        WHERE
            codigo_evaluacion LIKE 'EVAL-DEMO-%'
    );

DELETE FROM fm_evaluacion_visitas
WHERE
    id_evaluacion IN (
        SELECT id
        FROM fm_evaluaciones
        WHERE
            codigo_evaluacion LIKE 'EVAL-DEMO-%'
    );

DELETE FROM fm_evaluaciones
WHERE
    codigo_evaluacion LIKE 'EVAL-DEMO-%';

DELETE FROM fm_respuestas_director
WHERE
    id_registro IN (
        SELECT id
        FROM fm_registros
        WHERE
            codigo_envio LIKE 'FM-DEMO-%'
    );

DELETE FROM fm_historial_estados
WHERE
    id_registro IN (
        SELECT id
        FROM fm_registros
        WHERE
            codigo_envio LIKE 'FM-DEMO-%'
    );

DELETE FROM fm_consentimientos
WHERE
    id_tipo_ficha = 1
    AND id_usuario IN (2, 3, 5);

DELETE FROM fm_registros WHERE codigo_envio LIKE 'FM-DEMO-%';

-- ============================================================
-- CONSENTIMIENTOS
-- ============================================================
INSERT INTO
    fm_consentimientos (
        id_tipo_ficha,
        id_usuario,
        id_institucion,
        aceptado,
        texto_consentimiento,
        ip_usuario,
        fecha_registro
    )
VALUES (
        1,
        2,
        1,
        1,
        'Declaro bajo juramento que las respuestas, evidencias e informacion consignadas en la presente ficha de monitoreo son veridicas.',
        '127.0.0.1',
        '2026-05-10 09:00:00'
    ),
    (
        1,
        3,
        2,
        1,
        'Declaro bajo juramento que las respuestas, evidencias e informacion consignadas en la presente ficha de monitoreo son veridicas.',
        '127.0.0.1',
        '2026-04-15 09:00:00'
    ),
    (
        1,
        5,
        3,
        1,
        'Declaro bajo juramento que las respuestas, evidencias e informacion consignadas en la presente ficha de monitoreo son veridicas.',
        '127.0.0.1',
        '2026-06-01 10:00:00'
    );

-- ============================================================
-- REGISTRO 1: IE San Pedro - ENVIADO (listo para evaluacion del especialista)
-- Director: Maria Torres (id=2) | IE id=1 | Especialista: Ana Perez (id=4)
-- Puntaje: 58/76 = 76.32% -> LOGRADO
-- ============================================================
INSERT INTO
    fm_registros (
        id,
        codigo_envio,
        id_tipo_ficha,
        id_version_ficha,
        id_usuario,
        id_institucion,
        id_especialista,
        version_envio,
        estado,
        fuera_plazo,
        fecha_borrador,
        fecha_envio,
        puntaje_total,
        puntaje_maximo,
        porcentaje,
        escala_valorativa,
        activo
    )
VALUES (
        1,
        'FM-DEMO-2026-001',
        1,
        1,
        2,
        1,
        4,
        1,
        'ENVIADO',
        0,
        '2026-05-10 10:15:00',
        '2026-05-12 11:30:00',
        58.00,
        76.00,
        76.32,
        'LOGRADO',
        1
    ) ON DUPLICATE KEY
UPDATE estado =
VALUES (estado),
    puntaje_total =
VALUES (puntaje_total),
    porcentaje =
VALUES (porcentaje),
    escala_valorativa =
VALUES (escala_valorativa);

INSERT INTO
    fm_respuestas_director (
        id_registro,
        id_item,
        no_aplica,
        respuesta_escala,
        observacion,
        fecha_registro
    )
VALUES (
        1,
        1,
        0,
        4.00,
        'Contamos con informes trimestrales consolidados por grado.',
        '2026-05-12 11:00:00'
    ),
    (
        1,
        2,
        0,
        3.00,
        'Metas incluidas en PEI y PAT, pendiente de socializacion ampliada.',
        '2026-05-12 11:01:00'
    ),
    (
        1,
        3,
        0,
        4.00,
        'CGCO activo, nomina de seguimiento actualizada mensualmente.',
        '2026-05-12 11:02:00'
    ),
    (
        1,
        4,
        0,
        3.00,
        'Comite constituido por RD, pendiente remision a UGEL.',
        '2026-05-12 11:03:00'
    ),
    (
        1,
        5,
        0,
        4.00,
        'Calendarizacion difundida a la comunidad.',
        '2026-05-12 11:04:00'
    ),
    (
        1,
        6,
        0,
        3.00,
        'Actividades planificadas, faltan 2 actas de reunion.',
        '2026-05-12 11:05:00'
    ),
    (
        1,
        7,
        0,
        3.00,
        'Plan de contingencia basico aprobado.',
        '2026-05-12 11:06:00'
    ),
    (
        1,
        8,
        0,
        4.00,
        'Matricula 2026 sin condicionamiento, SIAGIE al dia.',
        '2026-05-12 11:07:00'
    ),
    (
        1,
        9,
        0,
        3.00,
        'CLA emitidas, certificados en proceso.',
        '2026-05-12 11:08:00'
    ),
    (
        1,
        10,
        0,
        4.00,
        'RI actualizado, horarios publicados en vitrina institucional.',
        '2026-05-12 11:09:00'
    ),
    (
        1,
        11,
        0,
        4.00,
        'Partes de asistencia enviados oportunamente a la UGEL.',
        '2026-05-12 11:10:00'
    ),
    (
        1,
        12,
        0,
        3.00,
        'Plan GRD aprobado, 1 simulacro ejecutado.',
        '2026-05-12 11:11:00'
    ),
    (
        1,
        13,
        0,
        3.00,
        'Mapa de riesgos elaborado, senalizacion en proceso.',
        '2026-05-12 11:12:00'
    ),
    (
        1,
        14,
        0,
        2.00,
        'Quiosco en proceso de adjudicacion.',
        '2026-05-12 11:13:00'
    ),
    (
        1,
        15,
        0,
        4.00,
        'Materiales recibidos y distribuidos, SIAGIE completo.',
        '2026-05-12 11:14:00'
    ),
    (
        1,
        16,
        0,
        3.00,
        'Uso de biblioteca activo, faltan registros de laboratorio.',
        '2026-05-12 11:15:00'
    ),
    (
        1,
        17,
        0,
        3.00,
        'Diagnostico elaborado, mantenimiento en ejecucion 70%.',
        '2026-05-12 11:16:00'
    ),
    (
        1,
        18,
        0,
        2.00,
        'Plan de recursos propios en borrador, no aprobado aun.',
        '2026-05-12 11:17:00'
    ),
    (
        1,
        19,
        0,
        4.00,
        'Rendicion de cuentas realizada con APAFA en asamblea del 30 de abril.',
        '2026-05-12 11:18:00'
    );

INSERT INTO
    fm_historial_estados (
        id_registro,
        estado_anterior,
        estado_nuevo,
        comentario,
        cambiado_por,
        fecha_cambio
    )
VALUES (
        1,
        NULL,
        'BORRADOR',
        'Inicio de llenado de ficha',
        2,
        '2026-05-10 10:15:00'
    ),
    (
        1,
        'BORRADOR',
        'ENVIADO',
        'Autoevaluacion enviada por la directora',
        2,
        '2026-05-12 11:30:00'
    );

-- ============================================================
-- REGISTRO 2: IE Santa Rosa - CONFORME (Flujo Completo)
-- Director: Luis Garcia (id=3) | IE id=2
-- Dir: 65/76 = 85.53% -> DESTACADO | Esp: 60/76 = 78.95% -> LOGRADO
-- ============================================================
INSERT INTO
    fm_registros (
        id,
        codigo_envio,
        id_tipo_ficha,
        id_version_ficha,
        id_usuario,
        id_institucion,
        id_especialista,
        version_envio,
        estado,
        fuera_plazo,
        fecha_borrador,
        fecha_envio,
        puntaje_total,
        puntaje_maximo,
        porcentaje,
        escala_valorativa,
        activo
    )
VALUES (
        2,
        'FM-DEMO-2026-002',
        1,
        1,
        3,
        2,
        4,
        1,
        'CONFORME',
        0,
        '2026-04-15 08:00:00',
        '2026-04-18 14:00:00',
        65.00,
        76.00,
        85.53,
        'DESTACADO',
        1
    ) ON DUPLICATE KEY
UPDATE estado =
VALUES (estado),
    puntaje_total =
VALUES (puntaje_total),
    porcentaje =
VALUES (porcentaje),
    escala_valorativa =
VALUES (escala_valorativa);

INSERT INTO
    fm_respuestas_director (
        id_registro,
        id_item,
        no_aplica,
        respuesta_escala,
        observacion,
        fecha_registro
    )
VALUES (
        2,
        1,
        0,
        4.00,
        'Informes completos con cuadros estadisticos por grado y seccion.',
        '2026-04-18 13:30:00'
    ),
    (
        2,
        2,
        0,
        4.00,
        'Metas en PEI y PAT articuladas a evaluacion diagnostica.',
        '2026-04-18 13:31:00'
    ),
    (
        2,
        3,
        0,
        4.00,
        'CGCO realiza seguimiento semanal de asistencia estudiantil.',
        '2026-04-18 13:32:00'
    ),
    (
        2,
        4,
        0,
        4.00,
        'Comite constituido y RD remitida a la UGEL el 15/03/2026.',
        '2026-04-18 13:33:00'
    ),
    (
        2,
        5,
        0,
        4.00,
        'Calendarizacion publicada y difundida en todos los canales.',
        '2026-04-18 13:34:00'
    ),
    (
        2,
        6,
        0,
        4.00,
        'Actividades de semanas de gestion ejecutadas con actas firmadas.',
        '2026-04-18 13:35:00'
    ),
    (
        2,
        7,
        0,
        3.00,
        'Plan de contingencia aprobado, un evento reprogramado.',
        '2026-04-18 13:36:00'
    ),
    (
        2,
        8,
        0,
        4.00,
        'Matricula ejecutada en SIAGIE sin condicionamientos.',
        '2026-04-18 13:37:00'
    ),
    (
        2,
        9,
        0,
        4.00,
        'Certificados y CLA emitidos oportunamente.',
        '2026-04-18 13:38:00'
    ),
    (
        2,
        10,
        0,
        4.00,
        'RI actualizado con horarios de todo el personal.',
        '2026-04-18 13:39:00'
    ),
    (
        2,
        11,
        0,
        3.00,
        'Partes mensuales enviados, un mes con 2 dias de retraso.',
        '2026-04-18 13:40:00'
    ),
    (
        2,
        12,
        0,
        3.00,
        'Plan GRD aprobado, 2 simulacros ejecutados.',
        '2026-04-18 13:41:00'
    ),
    (
        2,
        13,
        0,
        4.00,
        'Mapa de riesgos actualizado, senalizacion completa.',
        '2026-04-18 13:42:00'
    ),
    (
        2,
        14,
        0,
        3.00,
        'Contrato con quiosco firmado, supervision mensual activa.',
        '2026-04-18 13:43:00'
    ),
    (
        2,
        15,
        0,
        4.00,
        'Materiales distribuidos y registrados en SIAGIE.',
        '2026-04-18 13:44:00'
    ),
    (
        2,
        16,
        0,
        3.00,
        'Biblioteca activa con registro de prestamos.',
        '2026-04-18 13:45:00'
    ),
    (
        2,
        17,
        0,
        4.00,
        'Mantenimiento al 100%, rendicion en Mi Mantenimiento aprobada.',
        '2026-04-18 13:46:00'
    ),
    (
        2,
        18,
        0,
        3.00,
        'Plan aprobado por RD, libro caja actualizado.',
        '2026-04-18 13:47:00'
    ),
    (
        2,
        19,
        0,
        4.00,
        'Rendicion semestral realizada con APAFA/CONEI el 10 de abril.',
        '2026-04-18 13:48:00'
    );

INSERT INTO
    fm_evaluaciones (
        id,
        codigo_evaluacion,
        id_registro,
        id_especialista,
        estado_evaluacion,
        resultado_estado,
        observacion_general,
        compromiso_director,
        compromiso_especialista,
        puntaje_total_eval,
        puntaje_maximo_eval,
        porcentaje_eval,
        escala_valorativa_eval,
        fecha_inicio,
        fecha_finalizacion
    )
VALUES (
        1,
        'EVAL-DEMO-2026-001',
        2,
        4,
        'FINALIZADA',
        'CONFORME',
        'La institucion educativa demuestra una gestion eficiente. Se recomienda fortalecer el plan de recursos propios.',
        'La directora se compromete a concluir el plan de recursos propios antes del 30 de junio de 2026.',
        'El especialista realizara acompanamiento de seguimiento en julio 2026.',
        60.00,
        76.00,
        78.95,
        'LOGRADO',
        '2026-05-20 09:00:00',
        '2026-05-20 16:30:00'
    ) ON DUPLICATE KEY
UPDATE estado_evaluacion =
VALUES (estado_evaluacion),
    porcentaje_eval =
VALUES (porcentaje_eval),
    escala_valorativa_eval =
VALUES (escala_valorativa_eval);

INSERT INTO
    fm_evaluacion_visitas (
        id_evaluacion,
        numero_visita,
        fecha_visita,
        estado_visita,
        iniciada_por,
        fecha_inicio,
        fecha_cierre
    )
VALUES (
        1,
        1,
        '2026-05-20',
        'CERRADA',
        4,
        '2026-05-20 09:00:00',
        '2026-05-20 16:30:00'
    ) ON DUPLICATE KEY
UPDATE estado_visita = 'CERRADA';

INSERT INTO
    fm_respuestas_especialista (
        id_evaluacion,
        id_item,
        id_visita,
        no_aplica,
        respuesta_escala,
        observacion,
        evidencia,
        fecha_registro
    )
VALUES (
        1,
        1,
        1,
        0,
        4.00,
        'Informes verificados in situ, cuadros estadisticos completos.',
        'Carpeta de informes docentes 2026.',
        '2026-05-20 10:00:00'
    ),
    (
        1,
        2,
        1,
        0,
        4.00,
        'Metas en PEI y PAT concordadas con diagnostico.',
        'PEI 2024-2026 y PAT 2026.',
        '2026-05-20 10:10:00'
    ),
    (
        1,
        3,
        1,
        0,
        4.00,
        'Nomina de estudiantes en riesgo verificada.',
        'Nomina con 8 estudiantes en seguimiento activo.',
        '2026-05-20 10:20:00'
    ),
    (
        1,
        4,
        1,
        0,
        4.00,
        'RD de Comite verificada con cargo de envio a UGEL.',
        'RD N 015-2026-IE-SR.',
        '2026-05-20 10:30:00'
    ),
    (
        1,
        5,
        1,
        0,
        4.00,
        'Calendarizacion publicada en panel y WhatsApp institucional.',
        'Foto del panel institucional.',
        '2026-05-20 10:40:00'
    ),
    (
        1,
        6,
        1,
        0,
        3.00,
        'Actividades planificadas, acta de semana de gestion verificada.',
        'Acta del 6 al 10 de enero 2026.',
        '2026-05-20 10:50:00'
    ),
    (
        1,
        7,
        1,
        0,
        3.00,
        'Plan de contingencia observado con un dia de recuperacion.',
        'Plan de contingencia 2026.',
        '2026-05-20 11:00:00'
    ),
    (
        1,
        8,
        1,
        0,
        4.00,
        'SIAGIE verificado, matricula sin condicionamientos.',
        'Reporte SIAGIE matricula 2026.',
        '2026-05-20 11:10:00'
    ),
    (
        1,
        9,
        1,
        0,
        3.00,
        'CLA emitidas, algunos certificados de egreso pendientes.',
        'Registro de CLA emitidas.',
        '2026-05-20 11:20:00'
    ),
    (
        1,
        10,
        1,
        0,
        4.00,
        'RI actualizado con horario completo del personal.',
        'RI 2026 aprobado.',
        '2026-05-20 11:30:00'
    ),
    (
        1,
        11,
        1,
        0,
        3.00,
        'Partes de asistencia verificados, leve retraso en febrero.',
        'Cargos de envio mensual 2026.',
        '2026-05-20 11:40:00'
    ),
    (
        1,
        12,
        1,
        0,
        3.00,
        'Plan GRD aprobado, simulacro de sismo verificado.',
        'RD Plan GRD y reporte simulacro.',
        '2026-05-20 12:00:00'
    ),
    (
        1,
        13,
        1,
        0,
        3.00,
        'Mapa de riesgos actualizado, senalizacion en 80%.',
        'Mapa de riesgos y fotografias.',
        '2026-05-20 12:10:00'
    ),
    (
        1,
        14,
        1,
        0,
        2.00,
        'Quiosco en operacion, no hay evidencia de supervision formal.',
        'Contrato de quiosco 2026.',
        '2026-05-20 12:20:00'
    ),
    (
        1,
        15,
        1,
        0,
        4.00,
        'Distribucion completa y SIAGIE actualizado.',
        'PECOSA y padron de entrega.',
        '2026-05-20 13:00:00'
    ),
    (
        1,
        16,
        1,
        0,
        3.00,
        'Uso de biblioteca activo, laboratorio subutilizado.',
        'Registro de prestamos de biblioteca.',
        '2026-05-20 13:10:00'
    ),
    (
        1,
        17,
        1,
        0,
        4.00,
        'Mantenimiento ejecutado al 100%, rendicion aprobada.',
        'Declaracion de gastos en Mi Mantenimiento.',
        '2026-05-20 13:20:00'
    ),
    (
        1,
        18,
        1,
        0,
        2.00,
        'Plan aprobado pero libro caja con registros incompletos.',
        'Plan de recursos propios 2026.',
        '2026-05-20 13:30:00'
    ),
    (
        1,
        19,
        1,
        0,
        4.00,
        'Rendicion ante APAFA/CONEI documentada con acta firmada.',
        'Acta de rendicion de cuentas 10/04/2026.',
        '2026-05-20 13:40:00'
    );

INSERT INTO
    fm_historial_estados (
        id_registro,
        id_evaluacion,
        estado_anterior,
        estado_nuevo,
        comentario,
        cambiado_por,
        fecha_cambio
    )
VALUES (
        2,
        NULL,
        NULL,
        'BORRADOR',
        'Inicio de llenado de ficha',
        3,
        '2026-04-15 08:00:00'
    ),
    (
        2,
        NULL,
        'BORRADOR',
        'ENVIADO',
        'Autoevaluacion enviada por el subdirector',
        3,
        '2026-04-18 14:00:00'
    ),
    (
        2,
        1,
        'ENVIADO',
        'EN_REVISION',
        'Especialista inicia evaluacion de campo',
        4,
        '2026-05-20 09:00:00'
    ),
    (
        2,
        1,
        'EN_REVISION',
        'CONFORME',
        'Evaluacion finalizada. Resultado: LOGRADO',
        4,
        '2026-05-20 16:30:00'
    );

-- ============================================================
-- REGISTRO 3: IE Jose Olaya - BORRADOR (llenado parcial, 12/19 items)
-- Director: Carlos Mendoza (id=5) | IE id=3
-- ============================================================
INSERT INTO
    fm_registros (
        id,
        codigo_envio,
        id_tipo_ficha,
        id_version_ficha,
        id_usuario,
        id_institucion,
        id_especialista,
        version_envio,
        estado,
        fuera_plazo,
        fecha_borrador,
        fecha_envio,
        puntaje_total,
        puntaje_maximo,
        porcentaje,
        escala_valorativa,
        activo
    )
VALUES (
        3,
        'FM-DEMO-2026-003',
        1,
        1,
        5,
        3,
        4,
        1,
        'BORRADOR',
        0,
        '2026-06-01 10:00:00',
        NULL,
        36.00,
        76.00,
        0.00,
        NULL,
        1
    ) ON DUPLICATE KEY
UPDATE estado =
VALUES (estado),
    puntaje_total =
VALUES (puntaje_total),
    escala_valorativa =
VALUES (escala_valorativa);

INSERT INTO
    fm_respuestas_director (
        id_registro,
        id_item,
        no_aplica,
        respuesta_escala,
        observacion,
        fecha_registro
    )
VALUES (
        3,
        1,
        0,
        3.00,
        'En proceso de sistematizar la informacion.',
        '2026-06-01 10:30:00'
    ),
    (
        3,
        2,
        0,
        2.00,
        'Metas en borrador, pendiente aprobacion.',
        '2026-06-01 10:31:00'
    ),
    (
        3,
        3,
        0,
        3.00,
        'Seguimiento mensual activo.',
        '2026-06-01 10:32:00'
    ),
    (
        3,
        4,
        0,
        2.00,
        'Comite en proceso de conformacion.',
        '2026-06-01 10:33:00'
    ),
    (
        3,
        5,
        0,
        3.00,
        'Calendarizacion difundida a padres.',
        '2026-06-01 10:34:00'
    ),
    (
        3,
        6,
        0,
        2.00,
        'Actividades en planificacion.',
        '2026-06-01 10:35:00'
    ),
    (
        3,
        7,
        0,
        2.00,
        'Plan de contingencia en elaboracion.',
        '2026-06-01 10:36:00'
    ),
    (
        3,
        8,
        0,
        3.00,
        'Matricula completada sin problemas.',
        '2026-06-01 10:37:00'
    ),
    (
        3,
        9,
        0,
        3.00,
        'CLA emitidas al 80% de estudiantes.',
        '2026-06-01 10:38:00'
    ),
    (
        3,
        10,
        0,
        3.00,
        'RI actualizado con horarios.',
        '2026-06-01 10:39:00'
    ),
    (
        3,
        11,
        0,
        3.00,
        'Partes de asistencia enviados.',
        '2026-06-01 10:40:00'
    ),
    (
        3,
        12,
        0,
        3.00,
        'Plan GRD aprobado por RD.',
        '2026-06-01 10:41:00'
    );
-- Items 13-19: aun no llenados por el director

INSERT INTO
    fm_historial_estados (
        id_registro,
        estado_anterior,
        estado_nuevo,
        comentario,
        cambiado_por,
        fecha_cambio
    )
VALUES (
        3,
        NULL,
        'BORRADOR',
        'Inicio de llenado de ficha (en progreso)',
        5,
        '2026-06-01 10:00:00'
    );

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- CREDENCIALES PARA PROBAR EL SISTEMA
-- ============================================================
-- Director (IE 1 - San Pedro):   DNI 11111111 / pass 123456 -> ve REGISTRO 1 (ENVIADO)
-- Sub-Director (IE 2 - St.Rosa): DNI 22222222 / pass 123456 -> ve REGISTRO 2 (CONFORME)
-- Especialista:                  DNI 33333333 / pass 123456 -> puede evaluar REGISTRO 1
-- Jefe AGP:                      DNI 77777777 / pass 123456 -> reporte global de 3 IEs
-- ============================================================