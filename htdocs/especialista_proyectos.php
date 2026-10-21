<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = (int) $_SESSION['id_usuario'];
$rol = $_SESSION['rol'] ?? '';

if ($rol !== 'especialista') {
    header("Location: dashboard.php");
    exit();
}

// Obtener datos del especialista logueado
$stmt = $conn->prepare("SELECT nombres, ape_pat, ape_mat, DNI, correo FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nombre_completo = trim(($user_data['nombres'] ?? '') . ' ' . ($user_data['ape_pat'] ?? '') . ' ' . ($user_data['ape_mat'] ?? ''));
$nombre_corto = htmlspecialchars($user_data['nombres'] ?? 'Especialista');

function h($str): string {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$mensaje = '';
$error = '';

// Procesar Evaluación del Especialista
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'evaluar_proyecto') {
    $id_proy = (int) ($_POST['id_proyecto'] ?? 0);
    $nuevo_estado = $_POST['nuevo_estado'] ?? '';
    $comentario = trim($_POST['comentario'] ?? '');

    $permitidos = ['APROBADO', 'OBSERVADO', 'RECHAZADO'];
    if (!in_array($nuevo_estado, $permitidos, true)) {
        $error = "Estado no válido.";
    } elseif (in_array($nuevo_estado, ['OBSERVADO', 'RECHAZADO'], true) && $comentario === '') {
        $error = "Debe registrar un comentario u observación detallada para el director.";
    } else {
        // Validar que la IE del proyecto esté asignada a este especialista
        $st_chk = $conn->prepare("SELECT p.id, p.estado, p.id_institucion 
                                  FROM proyectos_innovacion p
                                  INNER JOIN asignaciones_especialistas ae ON ae.id_institucion = p.id_institucion AND ae.id_usuario = ? AND ae.estado = 'activo'
                                  WHERE p.id = ? LIMIT 1");
        $st_chk->bind_param("ii", $id_usuario, $id_proy);
        $st_chk->execute();
        $proy_chk = $st_chk->get_result()->fetch_assoc();
        $st_chk->close();

        if (!$proy_chk) {
            $error = "No tiene permisos para evaluar este proyecto o no pertenece a sus instituciones asignadas.";
        } else {
            $estado_ant = $proy_chk['estado'];
            $st_up = $conn->prepare("UPDATE proyectos_innovacion SET estado = ?, observaciones_revision = ?, id_especialista_asignado = ?, fecha_aprobacion = (CASE WHEN ? = 'APROBADO' THEN NOW() ELSE fecha_aprobacion END), fecha_actualizacion = NOW() WHERE id = ?");
            $st_up->bind_param("ssisi", $nuevo_estado, $comentario, $id_usuario, $nuevo_estado, $id_proy);
            if ($st_up->execute()) {
                $st_up->close();

                // Registrar en historial
                $st_h = $conn->prepare("INSERT INTO proyectos_historial (id_proyecto, estado_anterior, estado_nuevo, comentario, id_usuario) VALUES (?, ?, ?, ?, ?)");
                $st_h->bind_param("isssi", $id_proy, $estado_ant, $nuevo_estado, $comentario, $id_usuario);
                $st_h->execute();
                $st_h->close();

                header("Location: especialista_proyectos.php?msg=evaluado&id=" . $id_proy);
                exit();
            } else {
                $error = "Error al actualizar el estado: " . $conn->error;
            }
        }
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'evaluado') {
    $mensaje = "La evaluación y dictamen técnico del proyecto ha sido registrada con éxito.";
}

// Filtros
$filtro_estado = $_GET['estado'] ?? 'TODOS';
$filtro_q = trim($_GET['q'] ?? '');

$sql_proys = "SELECT p.*, i.nombre_ie, i.codigo_local, i.distrito,
                     u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni,
                     (SELECT COUNT(*) FROM proyectos_actividades a WHERE a.id_proyecto = p.id) AS total_actividades,
                     (SELECT COUNT(*) FROM proyectos_actividades a WHERE a.id_proyecto = p.id AND a.estado = 'COMPLETADA') AS actividades_completadas
              FROM proyectos_innovacion p
              INNER JOIN instituciones i ON i.id = p.id_institucion
              INNER JOIN usuarios u ON u.id = p.id_usuario_director
              INNER JOIN asignaciones_especialistas ae ON ae.id_institucion = i.id AND ae.id_usuario = ? AND ae.estado = 'activo'
              WHERE p.activo = 1";

$params = [$id_usuario];
$types = "i";

if ($filtro_estado !== 'TODOS' && $filtro_estado !== '') {
    $sql_proys .= " AND p.estado = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}

if ($filtro_q !== '') {
    $sql_proys .= " AND (p.titulo LIKE ? OR i.nombre_ie LIKE ? OR p.codigo_proyecto LIKE ?)";
    $like = '%' . $filtro_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$sql_proys .= " ORDER BY CASE WHEN p.estado = 'ENVIADO' THEN 1 WHEN p.estado = 'OBSERVADO' THEN 2 ELSE 3 END, p.id DESC";

$stmt_p = $conn->prepare($sql_proys);
$stmt_p->bind_param($types, ...$params);
$stmt_p->execute();
$proyectos = $stmt_p->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt_p->close();

// Si se consulta el detalle de un proyecto
$proyecto_detalle = null;
$actividades_detalle = [];
$historial_detalle = [];
$ver_id = (int) ($_GET['ver'] ?? 0);

if ($ver_id > 0) {
    foreach ($proyectos as $p) {
        if ((int) $p['id'] === $ver_id) {
            $proyecto_detalle = $p;
            break;
        }
    }
    if ($proyecto_detalle) {
        $rs_act = $conn->query("SELECT * FROM proyectos_actividades WHERE id_proyecto = $ver_id ORDER BY numero_actividad ASC");
        $actividades_detalle = $rs_act ? $rs_act->fetch_all(MYSQLI_ASSOC) : [];

        $rs_ev = $conn->query("SELECT * FROM proyectos_evidencias WHERE id_proyecto = $ver_id ORDER BY id DESC");
        $evidencias_detalle = $rs_ev ? $rs_ev->fetch_all(MYSQLI_ASSOC) : [];

        $rs_hist = $conn->query("SELECT h.*, u.nombres, u.ape_pat, u.rol FROM proyectos_historial h INNER JOIN usuarios u ON u.id = h.id_usuario WHERE h.id_proyecto = $ver_id ORDER BY h.id DESC");
        $historial_detalle = $rs_hist ? $rs_hist->fetch_all(MYSQLI_ASSOC) : [];
    }
}

$lineas_map = [
    'INNOVACION_PEDAGOGICA' => 'Innovación Pedagógica en Aula',
    'TECNOLOGIA_EDUCATIVA_TIC' => 'Tecnología Educativa / TIC y STEM',
    'CONVIVENCIA_CLIMA_ESCOLAR' => 'Clima Escolar y Convivencia',
    'CIENCIA_AMBIENTE_SOSTENIBILIDAD' => 'Ciencia, Biohuertos y Ambiente',
    'ARTE_CULTURA_DEPORTE' => 'Arte, Cultura y Deporte',
    'INCLUSION_ATENCION_DIVERSIDAD' => 'Inclusión y Diversidad'
];

function badgeEstadoProy($est): string {
    switch ($est) {
        case 'FORMULACION': return 'bg-secondary text-white';
        case 'ENVIADO': return 'bg-primary text-white';
        case 'OBSERVADO': return 'bg-warning text-dark';
        case 'APROBADO': return 'bg-success text-white';
        case 'EN_EJECUCION': return 'bg-info text-dark';
        case 'CONCLUIDO': return 'bg-dark text-white';
        default: return 'bg-light text-dark';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php 
    $page_title = 'Revisión de Proyectos de Innovación — Especialista';
    include 'components/head.php'; 
    ?>
    <style>
        .proy-row:hover {
            background-color: #f8fafc;
        }
        .progress-thin {
            height: 7px;
            border-radius: 4px;
            background-color: #e2e8f0;
        }
    </style>
</head>
<body class="bg-light">

    <!-- Topbar -->
    <nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top shadow-sm">
        <div class="container-fluid px-3 px-md-4">
            <!-- Hamburger (mobile) -->
            <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_especialista.php">
                <i class="bi bi-lightbulb-fill text-warning fs-4"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="ugel-brand-title">EVALUACIÓN DE PROYECTOS DE INNOVACIÓN</span>
                    <small class="ugel-brand-subtitle">Panel Especialista · UGEL Chincha</small>
                </div>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a href="dashboard_especialista.php" class="btn btn-sm d-flex align-items-center gap-1"
                   style="background:rgba(255,255,255,0.15);color:#fff;border:0;border-radius:6px;">
                    <i class="bi bi-arrow-left"></i> Volver al panel
                </a>
            </div>
        </div>
    </nav>

    <!-- App Shell con Sidebar -->
    <div class="ugel-app-shell">
        <?php
        $sidebar_role   = 'especialista';
        $sidebar_active = 'proyectos';
        $sidebar_label  = 'Especialista UGEL';
        include 'components/sidebar.php';
        ?>

        <main class="ugel-main-content">

        <!-- Alertas -->
        <?php if ($mensaje): ?>
            <div class="alert alert-success d-flex align-items-center gap-2 shadow-sm mb-4">
                <i class="bi bi-check-circle-fill fs-5"></i>
                <div><?= h($mensaje) ?></div>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 shadow-sm mb-4">
                <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                <div><?= h($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- Encabezado -->
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-clipboard-check text-success me-2"></i>Revisión Técnica de Proyectos de Innovación
            </h3>
            <p class="text-muted mb-0">
                Supervisa, aprueba y acompaña la implementación de las propuestas pedagógicas de sus instituciones asignadas.
            </p>
        </div>

        <?php if ($proyecto_detalle): ?>
        <!-- ========================================== -->
        <!-- DETALLE Y EVALUACIÓN DEL PROYECTO          -->
        <!-- ========================================== -->
        <div class="card border-0 shadow-sm mb-5">
            <div class="card-header bg-white border-bottom p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <a href="especialista_proyectos.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a la lista
                    </a>
                    <span class="badge <?= badgeEstadoProy($proyecto_detalle['estado']) ?> fs-6 px-3 py-1">
                        <?= h($proyecto_detalle['estado']) ?>
                    </span>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="small text-muted me-2">
                        <i class="bi bi-building me-1"></i> <strong><?= h($proyecto_detalle['nombre_ie']) ?></strong> (Cód. <?= h($proyecto_detalle['codigo_local']) ?>)
                    </span>
                    <a href="proyectos_pdf.php?id=<?= (int)$proyecto_detalle['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger fw-bold shadow-sm">
                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> Ver Ficha PDF
                    </a>
                    <a href="proyectos_pdf.php?id=<?= (int)$proyecto_detalle['id'] ?>&download=1" class="btn btn-sm btn-danger fw-bold shadow-sm">
                        <i class="bi bi-download me-1"></i> Descargar Ficha Oficial
                    </a>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <div class="col-12 col-lg-7">
                        <h4 class="fw-bold text-dark mb-2"><?= h($proyecto_detalle['titulo']) ?></h4>
                        <p class="text-primary fw-semibold small mb-3">
                            <i class="bi bi-tag-fill me-1"></i> Línea: <?= h($lineas_map[$proyecto_detalle['linea_tematica']] ?? $proyecto_detalle['linea_tematica']) ?>
                        </p>

                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1">Diagnóstico / Problema Abordado:</h6>
                            <p class="mb-0 text-dark small" style="white-space: pre-line;"><?= h($proyecto_detalle['problema_identificado']) ?></p>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1">Objetivo General de la Propuesta:</h6>
                            <p class="mb-0 text-dark small"><?= h($proyecto_detalle['objetivo_general']) ?></p>
                        </div>

                        <?php if (!empty($proyecto_detalle['objetivos_especificos'])): ?>
                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1">Objetivos Específicos:</h6>
                            <p class="mb-0 text-dark small" style="white-space: pre-line;"><?= h($proyecto_detalle['objetivos_especificos']) ?></p>
                        </div>
                        <?php endif; ?>

                        <div class="row g-2 text-center mt-2">
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Estudiantes</small>
                                    <strong class="fs-5 text-dark"><?= number_format($proyecto_detalle['beneficiarios_estudiantes']) ?></strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Docentes</small>
                                    <strong class="fs-5 text-dark"><?= number_format($proyecto_detalle['beneficiarios_docentes']) ?></strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Presupuesto</small>
                                    <strong class="fs-5 text-success">S/ <?= number_format($proyecto_detalle['presupuesto_referencial'], 2) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Columna Evaluación / Dictamen -->
                    <div class="col-12 col-lg-5">
                        <div class="card border-0 shadow-sm bg-light mb-4">
                            <div class="card-body p-4">
                                <h5 class="fw-bold text-dark mb-3">
                                    <i class="bi bi-shield-check text-success me-2"></i>Dictamen Técnico Pedagógico
                                </h5>

                                <form method="POST">
                                    <input type="hidden" name="accion" value="evaluar_proyecto">
                                    <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_detalle['id'] ?>">

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Dictamen a Asignar</label>
                                        <select name="nuevo_estado" class="form-select" required>
                                            <option value="">Seleccione dictamen...</option>
                                            <option value="APROBADO" <?= $proyecto_detalle['estado'] === 'APROBADO' ? 'selected' : '' ?>>🟢 APROBADO (Cumple normativa FONDEP/MINEDU)</option>
                                            <option value="OBSERVADO" <?= $proyecto_detalle['estado'] === 'OBSERVADO' ? 'selected' : '' ?>>🟡 OBSERVADO (Requiere correcciones)</option>
                                            <option value="RECHAZADO" <?= $proyecto_detalle['estado'] === 'RECHAZADO' ? 'selected' : '' ?>>🔴 RECHAZADO (No viable pedagógicamente)</option>
                                        </select>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Observaciones / Recomendaciones Técnicas</label>
                                        <textarea name="comentario" class="form-control" rows="4" placeholder="Escriba los aportes pedagógicos, precisiones o motivos de observación para la IE..."><?= h($proyecto_detalle['observaciones_revision']) ?></textarea>
                                    </div>

                                    <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm">
                                        <i class="bi bi-save me-1"></i> Guardar Dictamen Técnico
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CRONOGRAMA DE ACTIVIDADES -->
                <hr class="my-4">
                <h5 class="fw-bold text-dark mb-3"><i class="bi bi-calendar3-range text-primary me-2"></i>Actividades Registradas por la Institución</h5>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Descripción de Actividad</th>
                                <th>Responsable</th>
                                <th>Programación</th>
                                <th style="width: 140px;">Avance</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($actividades_detalle)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-3">No hay actividades registradas por la IE.</td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($actividades_detalle as $act): ?>
                            <tr>
                                <td class="fw-bold text-center"><?= (int)$act['numero_actividad'] ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= h($act['descripcion']) ?></div>
                                    <?php if ($act['evidencia_resumen']): ?>
                                    <small class="text-muted"><i class="bi bi-paperclip"></i> <?= h($act['evidencia_resumen']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h($act['responsable']) ?></td>
                                <td class="small text-muted"><?= h($act['fecha_inicio_programada']) ?> &rarr; <?= h($act['fecha_fin_programada']) ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="progress w-100 progress-thin">
                                            <div class="progress-bar bg-success" style="width: <?= (float)$act['porcentaje_avance'] ?>%"></div>
                                        </div>
                                        <span class="small fw-bold"><?= (int)$act['porcentaje_avance'] ?>%</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= $act['estado'] === 'COMPLETADA' ? 'bg-success' : ($act['estado'] === 'EN_PROCESO' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                        <?= h($act['estado']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- EVIDENCIAS Y EXPEDIENTE DOCUMENTAL -->
                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0">
                        <i class="bi bi-folder2-open text-primary me-2"></i>Expediente y Documentación Oficial Adjunta
                    </h5>
                    <span class="badge bg-light text-dark border"><?= count($evidencias_detalle) ?> archivo(s)</span>
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Título de la Evidencia / Documento</th>
                                <th>Tipo</th>
                                <th>Fecha de Envío</th>
                                <th class="text-center" style="width: 140px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($evidencias_detalle)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-3">
                                    La institución educativa no ha adjuntado archivos complementarios a la fecha.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($evidencias_detalle as $idx => $ev): 
                                $icono_ev = 'bi-file-earmark-text';
                                $color_ev = 'text-primary';
                                if ($ev['tipo_archivo'] === 'PDF') { $icono_ev = 'bi-file-earmark-pdf-fill'; $color_ev = 'text-danger'; }
                                elseif ($ev['tipo_archivo'] === 'IMAGEN') { $icono_ev = 'bi-file-earmark-image-fill'; $color_ev = 'text-success'; }
                                elseif ($ev['tipo_archivo'] === 'ENLACE') { $icono_ev = 'bi-link-45deg'; $color_ev = 'text-info'; }
                            ?>
                            <tr>
                                <td class="text-center fw-bold"><?= $idx + 1 ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi <?= $icono_ev ?> fs-4 <?= $color_ev ?>"></i>
                                        <div>
                                            <strong class="text-dark d-block"><?= h($ev['titulo']) ?></strong>
                                            <?php if ($ev['descripcion']): ?>
                                            <small class="text-muted"><?= h($ev['descripcion']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border"><?= h($ev['tipo_archivo']) ?></span>
                                </td>
                                <td class="small text-muted">
                                    <?= h($ev['fecha_registro']) ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($ev['tipo_archivo'] === 'ENLACE'): ?>
                                    <a href="<?= h($ev['ruta_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-info fw-bold">
                                        <i class="bi bi-box-arrow-up-right me-1"></i> Abrir Enlace
                                    </a>
                                    <?php else: ?>
                                    <a href="<?= h($ev['ruta_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Visualizar documento">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="<?= h($ev['ruta_archivo']) ?>" download class="btn btn-sm btn-outline-success" title="Descargar archivo">
                                        <i class="bi bi-download"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
        <?php endif; ?>

        <!-- ========================================== -->
        <!-- BANDEJA DE PROYECTOS RECIBIDOS             -->
        <!-- ========================================== -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold small mb-1">Buscar</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Título, I.E. o código..." value="<?= h($filtro_q) ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label fw-semibold small mb-1">Estado</label>
                        <select name="estado" class="form-select form-select-sm">
                            <option value="TODOS" <?= $filtro_estado === 'TODOS' ? 'selected' : '' ?>>Todos los estados</option>
                            <option value="ENVIADO" <?= $filtro_estado === 'ENVIADO' ? 'selected' : '' ?>>Enviado (Pendiente)</option>
                            <option value="OBSERVADO" <?= $filtro_estado === 'OBSERVADO' ? 'selected' : '' ?>>Observado</option>
                            <option value="APROBADO" <?= $filtro_estado === 'APROBADO' ? 'selected' : '' ?>>Aprobado</option>
                            <option value="FORMULACION" <?= $filtro_estado === 'FORMULACION' ? 'selected' : '' ?>>En Formulación</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                    <div class="col-6 col-md-3">
                        <a href="exportar_proyectos_excel.php?estado=<?= urlencode($filtro_estado) ?>" class="btn btn-outline-success btn-sm w-100 fw-bold">
                            <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar a Excel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Institución Educativa</th>
                            <th>Código</th>
                            <th>Título de la Propuesta</th>
                            <th>Línea Temática</th>
                            <th>Avance</th>
                            <th>Estado</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($proyectos)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                                No se encontraron proyectos de innovación registrados en sus instituciones asignadas.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($proyectos as $p): ?>
                        <tr class="proy-row">
                            <td>
                                <strong class="text-dark d-block"><?= h($p['nombre_ie']) ?></strong>
                                <small class="text-muted">Cód. <?= h($p['codigo_local']) ?> &bull; <?= h($p['distrito']) ?></small>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border"><?= h($p['codigo_proyecto']) ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark" style="max-width: 320px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?= h($p['titulo']) ?>
                                </div>
                                <small class="text-muted">Dir: <?= h(trim($p['dir_nombres'] . ' ' . $p['dir_ape_pat'])) ?></small>
                            </td>
                            <td>
                                <span class="small text-muted"><?= h($lineas_map[$p['linea_tematica']] ?? $p['linea_tematica']) ?></span>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2" style="width: 100px;">
                                    <div class="progress w-100 progress-thin">
                                        <div class="progress-bar bg-success" style="width: <?= (float)$p['porcentaje_avance'] ?>%"></div>
                                    </div>
                                    <span class="small fw-bold"><?= (int)$p['porcentaje_avance'] ?>%</span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?= badgeEstadoProy($p['estado']) ?>">
                                    <?= h($p['estado']) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <a href="especialista_proyectos.php?ver=<?= (int)$p['id'] ?>" class="btn btn-sm <?= $p['estado'] === 'ENVIADO' ? 'btn-success fw-bold' : 'btn-outline-primary' ?>">
                                    <i class="bi <?= $p['estado'] === 'ENVIADO' ? 'bi-clipboard-check-fill' : 'bi-eye' ?> me-1"></i>
                                    <?= $p['estado'] === 'ENVIADO' ? 'Evaluar' : 'Revisar' ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
    </div><!-- /.ugel-app-shell -->

    <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
