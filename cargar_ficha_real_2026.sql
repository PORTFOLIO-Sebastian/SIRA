-- ============================================================
-- CARGA DE LA FICHA DE MONITOREO REAL 2026 (CGE 3)
-- Sistema de Monitoreo — UGEL Chincha
-- ============================================================
-- Este script inserta la ficha oficial completa con sus 2 Aspectos,
-- los 19 ítems normativos y las rúbricas (Escala 1 al 4).
-- Permite que Directores y Especialistas puedan simular el proceso de inmediato.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- 1. Tipo de Ficha (Cabecera Principal)
INSERT INTO fm_tipos_ficha (id, nombre, slug, descripcion, anio, estado, fecha_inicio, fecha_limite, texto_consentimiento, creado_por)
VALUES (
  1,
  'Ficha de Monitoreo a las Prácticas de Gestión - Condiciones Operativas (CGE 3)',
  'monitoreo-cge3-condiciones-operativas-2026',
  'Monitoreo a las condiciones operativas orientadas al sostenimiento del servicio educativo ofrecido por la IE (CGE 3) en el marco de la R.M. N° 189-2021-MINEDU y R.D. N° 1971-2026-UGELCH.',
  2026,
  'ACTIVO',
  '2026-03-01',
  '2026-12-31',
  'Declaro bajo juramento que las respuestas, evidencias e información consignadas en la presente ficha de monitoreo son verídicas y reflejan la situación real de la Institución Educativa a mi cargo.',
  1
) ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  descripcion = VALUES(descripcion),
  estado = 'ACTIVO',
  fecha_limite = VALUES(fecha_limite),
  texto_consentimiento = VALUES(texto_consentimiento);

-- 2. Versión 1 de la Ficha (Estado: PUBLICADA)
INSERT INTO fm_tipos_ficha_versiones (id, id_tipo_ficha, version, titulo, descripcion, estado, motivo_version, total_items_planificados, estructura_configurada, creado_por, publicado_por, fecha_publicacion)
VALUES (
  1,
  1,
  1,
  'Versión 1.0 - Oficial 2026 (19 Ítems)',
  'Instrumento estándar de monitoreo con 2 aspectos y 19 ítems de escala 1 a 4.',
  'PUBLICADA',
  'Aprobación y publicación de la ficha institucional 2026',
  19,
  1,
  1,
  1,
  NOW()
) ON DUPLICATE KEY UPDATE
  estado = 'PUBLICADA',
  total_items_planificados = 19,
  estructura_configurada = 1;

-- 3. Secciones / Aspectos de la Ficha
DELETE FROM fm_secciones WHERE id_version_ficha = 1;

INSERT INTO fm_secciones (id, id_version_ficha, id_seccion_padre, titulo, descripcion, orden, nivel, estado) VALUES
(1, 1, NULL, 'Aspecto 01: DESARROLLO INTEGRAL DE LAS Y LOS ESTUDIANTES', 'Seguimiento a logros de aprendizaje, metas anualizadas y prevención del riesgo de deserción escolar.', 1, 1, 'ACTIVO'),
(2, 1, NULL, 'Aspecto 02: GESTIÓN DE CONDICIONES OPERATIVAS', 'Comité de condiciones operativas, calendarización, matrícula, asistencia, infraestructura, materiales y recursos.', 2, 1, 'ACTIVO');

-- 4. Los 19 Ítems de la Ficha
DELETE FROM fm_items WHERE id_version_ficha = 1;

INSERT INTO fm_items (id, id_version_ficha, id_seccion, numero_item, orden, titulo, pregunta, tipo_respuesta, obligatorio, permite_no_aplica, puntaje_maximo, criterio_instruccion, estado) VALUES
-- Aspecto 01 (Ítems 1 al 3)
(1, 1, 1, '1', 1, 'Información sistematizada de aprendizajes',
 'El director cuenta con información sistematizada sobre los niveles de logro de aprendizaje de los estudiantes a partir de las evaluaciones aplicadas por la IE y plantea estrategias para promover el progreso de los estudiantes en el desarrollo de sus competencias.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar informes docentes consolidados, cuadros estadísticos y plan de mejora.', 'ACTIVO'),

(2, 1, 1, '2', 2, 'Metas de aprendizaje en PEI y PAT',
 'El director cuenta con las metas de aprendizaje anualizadas, como producto de las jornadas de reflexión y/o RTC institucional, incluidas en el PEI y PAT.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar metas cuantitativas en PEI y PAT articuladas a evaluación diagnóstica.', 'ACTIVO'),

(3, 1, 1, '3', 3, 'Prevención de deserción escolar',
 'El director y el CGCO identifica a los estudiantes que están en riesgo de deserción escolar, vulnerabilidad y barreras educativas; analiza sus causas y propone estrategias para garantizar su permanencia.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar nómina de estudiantes en riesgo, seguimiento de asistencia y planes de tutoría.', 'ACTIVO'),

-- Aspecto 02 (Ítems 4 al 19)
(4, 1, 2, '4', 4, 'Comité de Condiciones Operativas',
 'El director de la IE ha constituido el Comité de Gestión de Condiciones Operativas debidamente reconocido por Resolución Directoral.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar RD de conformación y cargo de remisión a la UGEL Chincha.', 'ACTIVO'),

(5, 1, 2, '5', 5, 'Organización del tiempo anual',
 'El director o equipo directivo ha organizado el tiempo anual de la IE: semanas de gestión (8) y semanas lectivas (36) de acuerdo a la calendarización oficial.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar calendarización escolar difundida a la comunidad educativa.', 'ACTIVO'),

(6, 1, 2, '6', 6, 'Actividades de semanas de gestión',
 'El director o equipo directivo ha propuesto actividades a realizar durante las semanas de gestión según sus demandas o necesidades institucionales articuladas al PAT.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar planificador de actividades de semanas de gestión y actas de trabajo.', 'ACTIVO'),

(7, 1, 2, '7', 7, 'Reprogramación y recuperación del tiempo',
 'El director o equipo directivo ha previsto la reprogramación y recuperación del tiempo lectivo y de gestión que se suspendan por eventos de fuerza mayor.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar plan de contingencia o reprogramación escolar en caso aplique.', 'ACTIVO'),

(8, 1, 2, '8', 8, 'Proceso de matrícula regular y excepcional',
 'El director o equipo directivo ha organizado el proceso de matrícula escolar teniendo en cuenta el proceso regular y excepcional, garantizando gratuidad y sin condicionamientos.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar cronograma de matrícula publicado y registro en SIAGIE.', 'ACTIVO'),

(9, 1, 2, '9', 9, 'Emisión de documentos de trayectoria educativa',
 'El director o equipo directivo emite oportunamente los documentos asociados a la trayectoria educativa: certificados de estudio SIAGIE, Constancia de Logros de Aprendizaje (CLA) e informes de progreso.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar actas consolidadas, nóminas oficiales y emisión de certificados.', 'ACTIVO'),

(10, 1, 2, '10', 10, 'Organización de la jornada laboral',
 'El director o equipo directivo ha organizado la jornada laboral del personal de la IE: directivo, docente, auxiliar, personal administrativo y otros, incorporado en el Reglamento Interno.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar cuadro de distribución de horas y horarios de trabajo en RI.', 'ACTIVO'),

(11, 1, 2, '11', 11, 'Control de asistencia del personal y reporte a UGEL',
 'El director o equipo directivo ha establecido el mecanismo para el control de la asistencia del personal de la IE e informa a la UGEL en el plazo establecido de acuerdo a la norma técnica.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar partes de asistencia mensual y cargos de envío oportuno a la UGEL.', 'ACTIVO'),

(12, 1, 2, '12', 12, 'Plan de Gestión del Riesgo de Desastres',
 'El director o equipo directivo, junto a su Comité de Gestión de Condiciones Operativas, cuenta con Plan de Gestión del Riesgo de Desastres aprobado por RD y cronograma de simulacros.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar RD de aprobación del Plan de GRD y reportes de simulacros.', 'ACTIVO'),

(13, 1, 2, '13', 13, 'Identificación de peligros y vulnerabilidades',
 'El director o equipo directivo ha identificado peligros y vulnerabilidades que afecten o expongan a la IE ante emergencias o desastres, reportando las necesidades a la UGEL.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar mapa de riesgos, señalización de seguridad y oficios a UGEL.', 'ACTIVO'),

(14, 1, 2, '14', 14, 'Gestión de quioscos, cafeterías y comedores',
 'El director o equipo directivo ha organizado el proceso de adjudicación y cuenta con cronograma de supervisión de quioscos y comedores según la normativa sanitaria vigente (sin octógonos).',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar contrato de quiosco, actas con CONEI/Salud y verificación de alimentos saludables.', 'ACTIVO'),

(15, 1, 2, '15', 15, 'Recepción y distribución de materiales educativos',
 'El director o equipo directivo ha organizado la gestión de los recursos y materiales educativos para su entrega oportuna: recepción y asignación registrada en SIAGIE.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar pecosas de recepción, padrón de entrega a estudiantes y registro en SIAGIE.', 'ACTIVO'),

(16, 1, 2, '16', 16, 'Uso pedagógico y conservación de materiales',
 'El director o equipo directivo promueve el uso pedagógico de los recursos y materiales educativos en las sesiones de aprendizaje, así como su cuidado y conservación.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar plan de uso de biblioteca/laboratorio y monitoreo a aulas.', 'ACTIVO'),

(17, 1, 2, '17', 17, 'Diagnóstico de infraestructura y mantenimiento',
 'El director o equipo directivo ha formulado el diagnóstico de necesidades de infraestructura del local escolar y ejecuta el programa de mantenimiento con rendición en la plataforma Mi Mantenimiento.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar ficha técnica aprobada, declaración de gastos y panel fotográfico.', 'ACTIVO'),

(18, 1, 2, '18', 18, 'Plan de gestión de recursos propios',
 'El director o equipo directivo cuenta con su Plan de gestión de recursos propios y actividades productivas y empresariales de la IE aprobado por RD, y lleva libro caja al día.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar RD de aprobación, libro caja y balance de ingresos/egresos.', 'ACTIVO'),

(19, 1, 2, '19', 19, 'Rendición de cuentas de la gestión escolar',
 'El director o equipo directivo ha organizado las jornadas de rendición de cuentas sobre los recursos obtenidos o asignados y los resultados de aprendizaje ante la comunidad educativa.',
 'ESCALA_1_4', 1, 1, 4.00, 'Verificar actas de asamblea con APAFA/CONEI e informe económico presentado.', 'ACTIVO');

-- 5. Rúbricas para cada Ítem (Escala 1 al 4)
DELETE FROM fm_rubricas WHERE id_item BETWEEN 1 AND 19;

INSERT INTO fm_rubricas (id_item, valor, descripcion)
SELECT i.id, 1.00, '1 = INICIO: La IE no cuenta con la evidencia o la práctica no ha sido implementada según los criterios normativos.'
FROM fm_items i WHERE i.id_version_ficha = 1
UNION ALL
SELECT i.id, 2.00, '2 = PROCESO: La IE cuenta con avances parciales o documentos en elaboración, pero no consolidados o difundidos formalmente.'
FROM fm_items i WHERE i.id_version_ficha = 1
UNION ALL
SELECT i.id, 3.00, '3 = LOGRADO: La IE cumple satisfactoriamente con la práctica de gestión y cuenta con las evidencias y respaldos formales.'
FROM fm_items i WHERE i.id_version_ficha = 1
UNION ALL
SELECT i.id, 4.00, '4 = DESTACADO: La IE supera los estándares exigidos, evidencia innovación institucional y sostenibilidad en su implementación.'
FROM fm_items i WHERE i.id_version_ficha = 1;

SET FOREIGN_KEY_CHECKS = 1;
