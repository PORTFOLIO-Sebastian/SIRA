<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = (int) $_SESSION['id_usuario'];
$rol = $_SESSION['rol'] ?? '';

if (!in_array($rol, ['director', 'subdirector'], true)) {
    header("Location: dashboard.php");
    exit();
}

// Obtener datos del usuario y su IE
$query = "SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni, 
                 i.id AS id_ie, i.nombre_ie, i.codigo_local, i.distrito
          FROM usuarios u
          LEFT JOIN asignaciones a ON u.id = a.id_usuario AND a.estado = 'activo'
          LEFT JOIN instituciones i ON a.id_ie = i.id
          WHERE u.id = ? LIMIT 1";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$id_ie = (int) ($user_data['id_ie'] ?? ($_SESSION['id_ie'] ?? 0));
$nombre_ie = $user_data['nombre_ie'] ?? 'Institución Educativa';
$nombre_completo = trim(($user_data['nombres'] ?? '') . ' ' . ($user_data['ape_pat'] ?? '') . ' ' . ($user_data['ape_mat'] ?? ''));
$nombre_corto = htmlspecialchars($user_data['nombres'] ?? 'Usuario');

if ($id_ie <= 0) {
    die("Error: No tiene una institución educativa asignada activamente. Comuníquese con la UGEL.");
}

function h($str): string {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
}

$mensaje = '';
$error = '';

// Procesamiento de formularios POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';

    // 1. REGISTRAR NUEVO PROYECTO
    if ($accion === 'crear_proyecto') {
        $titulo = trim($_POST['titulo'] ?? '');
        $linea = $_POST['linea_tematica'] ?? 'INNOVACION_PEDAGOGICA';
        $problema = trim($_POST['problema_identificado'] ?? '');
        $objetivo_gen = trim($_POST['objetivo_general'] ?? '');
        $objetivos_esp = trim($_POST['objetivos_especificos'] ?? '');
        $estudiantes = (int) ($_POST['beneficiarios_estudiantes'] ?? 0);
        $docentes = (int) ($_POST['beneficiarios_docentes'] ?? 0);
        $presupuesto = (float) ($_POST['presupuesto_referencial'] ?? 0.00);
        $fecha_inicio = $_POST['fecha_inicio'] ?? date('Y-m-d');
        $fecha_fin = $_POST['fecha_fin'] ?? date('Y-m-d', strtotime('+6 months'));
        $anio = (int) date('Y');

        if ($titulo === '' || $problema === '' || $objetivo_gen === '') {
            $error = "Por favor complete todos los campos obligatorios del proyecto.";
        } else {
            // Generar código único de proyecto
            $codigo_proy = 'PI-' . $anio . '-CH-' . str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT);

            // Obtener especialista asignado a la IE si existe
            $id_esp = null;
            $st_esp = $conn->prepare("SELECT id_usuario FROM asignaciones_especialistas WHERE id_institucion = ? AND estado = 'activo' LIMIT 1");
            if ($st_esp) {
                $st_esp->bind_param("i", $id_ie);
                $st_esp->execute();
                $res_esp = $st_esp->get_result()->fetch_assoc();
                if ($res_esp) $id_esp = (int) $res_esp['id_usuario'];
                $st_esp->close();
            }

            $sql_ins = "INSERT INTO proyectos_innovacion 
                        (codigo_proyecto, id_institucion, id_usuario_director, id_especialista_asignado, anio, titulo, linea_tematica, problema_identificado, objetivo_general, objetivos_especificos, beneficiarios_estudiantes, beneficiarios_docentes, presupuesto_referencial, fecha_inicio, fecha_fin, estado, fecha_creacion)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'FORMULACION', NOW())";
            $st = $conn->prepare($sql_ins);
            if ($st) {
                $st->bind_param("siiiissssiiidss", $codigo_proy, $id_ie, $id_usuario, $id_esp, $anio, $titulo, $linea, $problema, $objetivo_gen, $objetivos_esp, $estudiantes, $docentes, $presupuesto, $fecha_inicio, $fecha_fin);
                if ($st->execute()) {
                    $nuevo_id = $st->insert_id;
                    $st->close();

                    // Registrar en historial
                    $com = "Creación del proyecto en estado formulación.";
                    $st_h = $conn->prepare("INSERT INTO proyectos_historial (id_proyecto, estado_anterior, estado_nuevo, comentario, id_usuario) VALUES (?, 'INICIO', 'FORMULACION', ?, ?)");
                    $st_h->bind_param("isi", $nuevo_id, $com, $id_usuario);
                    $st_h->execute();
                    $st_h->close();

                    header("Location: director_proyectos.php?msg=creado&id=" . $nuevo_id);
                    exit();
                } else {
                    $error = "Error al guardar el proyecto: " . $conn->error;
                }
            } else {
                $error = "Error en la consulta: " . $conn->error;
            }
        }
    }

    // 2. ENVIAR PROYECTO A LA UGEL
    if ($accion === 'enviar_ugel') {
        $id_proy = (int) ($_POST['id_proyecto'] ?? 0);
        $st = $conn->prepare("UPDATE proyectos_innovacion SET estado = 'ENVIADO', fecha_envio = NOW() WHERE id = ? AND id_institucion = ? AND estado IN ('FORMULACION', 'OBSERVADO')");
        if ($st) {
            $st->bind_param("ii", $id_proy, $id_ie);
            $st->execute();
            if ($st->affected_rows > 0) {
                $com = "Proyecto remitido a la UGEL Chincha para revisión técnica.";
                $st_h = $conn->prepare("INSERT INTO proyectos_historial (id_proyecto, estado_anterior, estado_nuevo, comentario, id_usuario) VALUES (?, 'FORMULACION', 'ENVIADO', ?, ?)");
                $st_h->bind_param("isi", $id_proy, $com, $id_usuario);
                $st_h->execute();
                $st_h->close();
                header("Location: director_proyectos.php?msg=enviado");
                exit();
            } else {
                $error = "No se pudo remitir el proyecto. Verifique que esté en formulación u observado.";
            }
            $st->close();
        }
    }

    // 3. AGREGAR ACTIVIDAD AL CRONOGRAMA
    if ($accion === 'agregar_actividad') {
        $id_proy = (int) ($_POST['id_proyecto'] ?? 0);
        $desc = trim($_POST['descripcion'] ?? '');
        $resp = trim($_POST['responsable'] ?? '');
        $f_ini = $_POST['fecha_inicio_programada'] ?? date('Y-m-d');
        $f_fin = $_POST['fecha_fin_programada'] ?? date('Y-m-d');

        if ($desc !== '' && $resp !== '' && $id_proy > 0) {
            // Obtener número consecutivo
            $rs_num = $conn->query("SELECT MAX(numero_actividad) AS max_n FROM proyectos_actividades WHERE id_proyecto = $id_proy");
            $next_n = 1;
            if ($rs_num && $r = $rs_num->fetch_assoc()) {
                $next_n = ((int) $r['max_n']) + 1;
            }

            $st_act = $conn->prepare("INSERT INTO proyectos_actividades (id_proyecto, numero_actividad, descripcion, responsable, fecha_inicio_programada, fecha_fin_programada, porcentaje_avance, estado) VALUES (?, ?, ?, ?, ?, ?, 0.00, 'PENDIENTE')");
            $st_act->bind_param("iissss", $id_proy, $next_n, $desc, $resp, $f_ini, $f_fin);
            $st_act->execute();
            $st_act->close();

            header("Location: director_proyectos.php?ver=" . $id_proy . "&msg=act_agregada");
            exit();
        }
    }

    // 4. ACTUALIZAR AVANCE DE ACTIVIDAD
    if ($accion === 'actualizar_actividad') {
        $id_act = (int) ($_POST['id_actividad'] ?? 0);
        $id_proy = (int) ($_POST['id_proyecto'] ?? 0);
        $avance = (float) ($_POST['porcentaje_avance'] ?? 0.00);
        $est_act = $_POST['estado_actividad'] ?? 'PENDIENTE';
        $resumen = trim($_POST['evidencia_resumen'] ?? '');

        if ($avance >= 100) {
            $est_act = 'COMPLETADA';
            $avance = 100.00;
        }

        $st = $conn->prepare("UPDATE proyectos_actividades SET porcentaje_avance = ?, estado = ?, evidencia_resumen = ? WHERE id = ? AND id_proyecto = ?");
        $st->bind_param("dssii", $avance, $est_act, $resumen, $id_act, $id_proy);
        $st->execute();
        $st->close();

        // Recalcular avance general del proyecto
        $conn->query("UPDATE proyectos_innovacion p 
                      SET p.porcentaje_avance = (
                          SELECT IFNULL(ROUND(AVG(a.porcentaje_avance), 2), 0) 
                          FROM proyectos_actividades a 
                          WHERE a.id_proyecto = p.id
                      ) WHERE p.id = $id_proy");

        header("Location: director_proyectos.php?ver=" . $id_proy . "&msg=act_actualizada");
        exit();
    }

    // 5. SUBIR EVIDENCIA O DOCUMENTO DE SUSTENTO
    if ($accion === 'subir_evidencia') {
        $id_proy = (int) ($_POST['id_proyecto'] ?? 0);
        $titulo = trim($_POST['titulo_evidencia'] ?? '');
        $tipo_ev = $_POST['tipo_evidencia'] ?? 'DOCUMENTO';
        $descripcion = trim($_POST['descripcion_evidencia'] ?? '');
        $enlace_url = trim($_POST['enlace_url'] ?? '');

        if ($id_proy > 0 && $titulo !== '') {
            $ruta_final = '';
            if ($tipo_ev === 'ENLACE') {
                if ($enlace_url === '') {
                    $error = "Debe ingresar una URL o enlace válido.";
                } else {
                    $ruta_final = $enlace_url;
                }
            } else {
                if (isset($_FILES['archivo_evidencia']) && $_FILES['archivo_evidencia']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['archivo_evidencia'];
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $permitidas = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'zip', 'rar'];
                    
                    if (!in_array($ext, $permitidas, true)) {
                        $error = "Formato de archivo no permitido (.$ext). Permitidos: PDF, Word, Excel, PowerPoint, Imágenes (JPG, PNG) y ZIP.";
                    } elseif ($file['size'] > 20 * 1024 * 1024) { // 20MB
                        $error = "El archivo excede el tamaño máximo permitido de 20MB.";
                    } else {
                        $st_p = $conn->prepare("SELECT codigo_proyecto FROM proyectos_innovacion WHERE id = ?");
                        $st_p->bind_param("i", $id_proy);
                        $st_p->execute();
                        $r_p = $st_p->get_result()->fetch_assoc();
                        $st_p->close();

                        $cod_folder = preg_replace('/[^A-Za-z0-9_-]/', '_', $r_p['codigo_proyecto'] ?? 'PROY');
                        $dir_upload = __DIR__ . "/uploads/proyectos/{$id_ie}/{$cod_folder}/";
                        if (!is_dir($dir_upload)) {
                            @mkdir($dir_upload, 0777, true);
                        }

                        $safe_name = preg_replace('/[^A-Za-z0-9_.-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
                        $nombre_archivo = time() . '_' . $safe_name . '.' . $ext;
                        $destino = $dir_upload . $nombre_archivo;

                        if (move_uploaded_file($file['tmp_name'], $destino)) {
                            $ruta_final = "uploads/proyectos/{$id_ie}/{$cod_folder}/" . $nombre_archivo;
                            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                                $tipo_ev = 'IMAGEN';
                            } elseif ($ext === 'pdf') {
                                $tipo_ev = 'PDF';
                            } else {
                                $tipo_ev = 'DOCUMENTO';
                            }
                        } else {
                            $error = "Error al guardar el archivo en el servidor. Verifique permisos de escritura.";
                        }
                    }
                } else {
                    $error = "Debe seleccionar un archivo válido para subir.";
                }
            }

            if ($ruta_final !== '') {
                $st_ins = $conn->prepare("INSERT INTO proyectos_evidencias (id_proyecto, titulo, tipo_archivo, ruta_archivo, descripcion, subido_por) VALUES (?, ?, ?, ?, ?, ?)");
                $st_ins->bind_param("issssi", $id_proy, $titulo, $tipo_ev, $ruta_final, $descripcion, $id_usuario);
                $st_ins->execute();
                $st_ins->close();

                header("Location: director_proyectos.php?ver=" . $id_proy . "&msg=evidencia_subida");
                exit();
            }
        } else {
            $error = "Debe ingresar el título de la evidencia.";
        }
    }

    // 6. ELIMINAR EVIDENCIA
    if ($accion === 'eliminar_evidencia') {
        $id_ev = (int) ($_POST['id_evidencia'] ?? 0);
        $id_proy = (int) ($_POST['id_proyecto'] ?? 0);

        $st = $conn->prepare("SELECT ruta_archivo, tipo_archivo FROM proyectos_evidencias WHERE id = ? AND id_proyecto = ?");
        $st->bind_param("ii", $id_ev, $id_proy);
        $st->execute();
        $res = $st->get_result()->fetch_assoc();
        $st->close();

        if ($res) {
            if ($res['tipo_archivo'] !== 'ENLACE' && file_exists(__DIR__ . '/' . $res['ruta_archivo'])) {
                @unlink(__DIR__ . '/' . $res['ruta_archivo']);
            }
            $conn->query("DELETE FROM proyectos_evidencias WHERE id = $id_ev");
            header("Location: director_proyectos.php?ver=" . $id_proy . "&msg=evidencia_eliminada");
            exit();
        }
    }
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'creado') $mensaje = '¡Proyecto de Innovación registrado exitosamente en estado de formulación!';
    if ($_GET['msg'] === 'enviado') $mensaje = 'El proyecto ha sido remitido con éxito a la UGEL Chincha para su evaluación.';
    if ($_GET['msg'] === 'act_agregada') $mensaje = 'Actividad incorporada al cronograma del proyecto.';
    if ($_GET['msg'] === 'act_actualizada') $mensaje = 'Avance y estado de la actividad actualizados correctamente.';
    if ($_GET['msg'] === 'evidencia_subida') $mensaje = 'Documento / Evidencia adjuntada correctamente al expediente del proyecto.';
    if ($_GET['msg'] === 'evidencia_eliminada') $mensaje = 'La evidencia ha sido eliminada del expediente.';
}

// Consultar proyectos de esta institución
$sql_proys = "SELECT p.*, 
                     (SELECT COUNT(*) FROM proyectos_actividades a WHERE a.id_proyecto = p.id) AS total_actividades,
                     (SELECT COUNT(*) FROM proyectos_actividades a WHERE a.id_proyecto = p.id AND a.estado = 'COMPLETADA') AS actividades_completadas
              FROM proyectos_innovacion p
              WHERE p.id_institucion = ? AND p.activo = 1
              ORDER BY p.id DESC";
$st_p = $conn->prepare($sql_proys);
$st_p->bind_param("i", $id_ie);
$st_p->execute();
$proyectos = $st_p->get_result()->fetch_all(MYSQLI_ASSOC);
$st_p->close();

// Si se solicita ver un proyecto específico
$proyecto_activo = null;
$actividades_activo = [];
$evidencias_activo = [];
$historial_activo = [];
$ver_id = (int) ($_GET['ver'] ?? 0);

if ($ver_id > 0) {
    foreach ($proyectos as $p) {
        if ((int) $p['id'] === $ver_id) {
            $proyecto_activo = $p;
            break;
        }
    }
    if ($proyecto_activo) {
        $rs_act = $conn->query("SELECT * FROM proyectos_actividades WHERE id_proyecto = $ver_id ORDER BY numero_actividad ASC");
        $actividades_activo = $rs_act ? $rs_act->fetch_all(MYSQLI_ASSOC) : [];

        $rs_ev = $conn->query("SELECT * FROM proyectos_evidencias WHERE id_proyecto = $ver_id ORDER BY id DESC");
        $evidencias_activo = $rs_ev ? $rs_ev->fetch_all(MYSQLI_ASSOC) : [];

        $rs_hist = $conn->query("SELECT h.*, u.nombres, u.ape_pat, u.rol FROM proyectos_historial h INNER JOIN usuarios u ON u.id = h.id_usuario WHERE h.id_proyecto = $ver_id ORDER BY h.id DESC");
        $historial_activo = $rs_hist ? $rs_hist->fetch_all(MYSQLI_ASSOC) : [];
    }
}

// Mapeos de etiquetas y badges
$lineas_map = [
    'INNOVACION_PEDAGOGICA' => ['nombre' => 'Innovación Pedagógica', 'color' => 'success', 'icono' => 'bi-lightbulb'],
    'TECNOLOGIA_EDUCATIVA_TIC' => ['nombre' => 'Tecnología Educativa / TIC', 'color' => 'primary', 'icono' => 'bi-laptop'],
    'CONVIVENCIA_CLIMA_ESCOLAR' => ['nombre' => 'Clima Escolar y Convivencia', 'color' => 'warning', 'icono' => 'bi-heart-half'],
    'CIENCIA_AMBIENTE_SOSTENIBILIDAD' => ['nombre' => 'Ciencia, Ambiente y Sostenibilidad', 'color' => 'teal', 'icono' => 'bi-tree'],
    'ARTE_CULTURA_DEPORTE' => ['nombre' => 'Arte, Cultura y Deporte', 'color' => 'danger', 'icono' => 'bi-palette'],
    'INCLUSION_ATENCION_DIVERSIDAD' => ['nombre' => 'Inclusión y Diversidad', 'color' => 'info', 'icono' => 'bi-universal-access']
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
    $page_title = 'Proyectos de Innovación — ' . h($nombre_ie);
    include 'components/head.php'; 
    ?>
    <style>
        .proy-card {
            border: 1px solid var(--ugel-border, #e2e8f0);
            border-radius: 12px;
            background: #fff;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            overflow: hidden;
        }
        .proy-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(0,0,0,0.08);
        }
        .linea-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 20px;
            background: #f1f5f9;
            color: #334155;
        }
        .progress-thin {
            height: 8px;
            border-radius: 4px;
            background-color: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            padding-left: 28px;
            margin-bottom: 16px;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 7px;
            top: 4px;
            bottom: -16px;
            width: 2px;
            background: #cbd5e1;
        }
        .timeline-item:last-child::before {
            display: none;
        }
        .timeline-dot {
            position: absolute;
            left: 0;
            top: 3px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--ugel-green-dark, #137e4b);
            border: 3px solid #fff;
            box-shadow: 0 0 0 1px #cbd5e1;
        }
    </style>
</head>
<body class="bg-light">

    <!-- Navbar Superior -->
    <nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top shadow-sm">
        <div class="container-fluid px-3 px-md-4">
            <!-- Hamburger (mobile) -->
            <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <i class="bi bi-lightbulb-fill text-warning fs-4"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="ugel-brand-title">PROYECTOS DE INNOVACIÓN</span>
                    <small class="ugel-brand-subtitle">FONDEP / MINEDU · UGEL Chincha</small>
                </div>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a href="dashboard.php" class="btn btn-sm d-flex align-items-center gap-1"
                   style="background:rgba(255,255,255,0.15);color:#fff;border:0;border-radius:6px;">
                    <i class="bi bi-arrow-left"></i> Inicio
                </a>
            </div>
        </div>
    </nav>

    <!-- App Shell con Sidebar -->
    <div class="ugel-app-shell">
        <?php
        $sidebar_role   = 'director';
        $sidebar_active = 'proyectos';
        $sidebar_label  = $nombre_ie;
        include 'components/sidebar.php';
        ?>

        <main class="ugel-main-content">

        <!-- Mensajes de Alerta -->
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

        <!-- Encabezado de Página -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-3 py-1 mb-2 fw-semibold">
                    <i class="bi bi-building me-1"></i> <?= h($nombre_ie) ?> &bull; Cód. <?= h($user_data['codigo_local'] ?? '') ?>
                </span>
                <h2 class="fw-bold text-dark mb-1">
                    <i class="bi bi-rocket-takeoff text-danger me-2"></i>Banco de Proyectos de Innovación
                </h2>
                <p class="text-muted mb-0">
                    Formulación, cronograma y seguimiento a las iniciativas de mejora e innovación pedagógica institucional.
                </p>
            </div>
            <div>
                <button type="button" class="btn btn-danger d-inline-flex align-items-center gap-2 px-3 py-2 fw-bold shadow-sm"
                        data-bs-toggle="modal" data-bs-target="#modalNuevoProyecto">
                    <i class="bi bi-plus-circle-fill"></i> Registrar Nuevo Proyecto
                </button>
            </div>
        </div>

        <?php if ($proyecto_activo): ?>
        <!-- ========================================== -->
        <!-- DETALLE DEL PROYECTO SELECCIONADO          -->
        <!-- ========================================== -->
        <div class="card border-0 shadow-sm mb-5">
            <div class="card-header bg-white border-bottom p-3 d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2">
                    <a href="director_proyectos.php" class="btn btn-sm btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Volver a la lista
                    </a>
                    <span class="badge <?= badgeEstadoProy($proyecto_activo['estado']) ?> fs-6 px-3 py-1">
                        <?= h($proyecto_activo['estado']) ?>
                    </span>
                    <span class="text-muted fw-bold"><?= h($proyecto_activo['codigo_proyecto']) ?></span>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <a href="proyectos_pdf.php?id=<?= (int)$proyecto_activo['id'] ?>" target="_blank" class="btn btn-sm btn-outline-danger fw-bold shadow-sm">
                        <i class="bi bi-file-earmark-pdf-fill me-1"></i> Ver Ficha PDF
                    </a>
                    <a href="proyectos_pdf.php?id=<?= (int)$proyecto_activo['id'] ?>&download=1" class="btn btn-sm btn-danger fw-bold shadow-sm">
                        <i class="bi bi-download me-1"></i> Descargar PDF Oficial
                    </a>
                    <?php if (in_array($proyecto_activo['estado'], ['FORMULACION', 'OBSERVADO'])): ?>
                    <form method="POST" class="d-inline" onsubmit="return confirm('¿Está seguro de enviar este proyecto a la UGEL para su revisión técnica?');">
                        <input type="hidden" name="accion" value="enviar_ugel">
                        <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_activo['id'] ?>">
                        <button type="submit" class="btn btn-success btn-sm fw-bold px-3 shadow-sm">
                            <i class="bi bi-send-check-fill me-1"></i> Remitir a la UGEL Chincha
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-4">
                <div class="row g-4">
                    <!-- Columna Izquierda: Información General -->
                    <div class="col-12 col-lg-7">
                        <h4 class="fw-bold text-dark mb-2"><?= h($proyecto_activo['titulo']) ?></h4>
                        <?php 
                        $lin = $lineas_map[$proyecto_activo['linea_tematica']] ?? ['nombre' => $proyecto_activo['linea_tematica'], 'icono' => 'bi-tag'];
                        ?>
                        <div class="mb-3">
                            <span class="linea-pill">
                                <i class="bi <?= $lin['icono'] ?> text-danger"></i> <?= h($lin['nombre']) ?>
                            </span>
                            <span class="text-muted ms-2 small">
                                <i class="bi bi-calendar-event me-1"></i> Del <?= h($proyecto_activo['fecha_inicio']) ?> al <?= h($proyecto_activo['fecha_fin']) ?>
                            </span>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1"><i class="bi bi-exclamation-circle text-warning me-1"></i> Problema Identificado:</h6>
                            <p class="mb-0 text-dark small" style="white-space: pre-line;"><?= h($proyecto_activo['problema_identificado']) ?></p>
                        </div>

                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1"><i class="bi bi-bullseye text-danger me-1"></i> Objetivo General:</h6>
                            <p class="mb-0 text-dark small"><?= h($proyecto_activo['objetivo_general']) ?></p>
                        </div>

                        <?php if (!empty($proyecto_activo['objetivos_especificos'])): ?>
                        <div class="bg-light p-3 rounded-3 mb-3">
                            <h6 class="fw-bold text-secondary mb-1"><i class="bi bi-list-check text-primary me-1"></i> Objetivos Específicos:</h6>
                            <p class="mb-0 text-dark small" style="white-space: pre-line;"><?= h($proyecto_activo['objetivos_especificos']) ?></p>
                        </div>
                        <?php endif; ?>

                        <!-- Métricas y Beneficiarios -->
                        <div class="row g-2 text-center mt-2">
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Estudiantes</small>
                                    <strong class="fs-5 text-dark"><?= number_format($proyecto_activo['beneficiarios_estudiantes']) ?></strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Docentes</small>
                                    <strong class="fs-5 text-dark"><?= number_format($proyecto_activo['beneficiarios_docentes']) ?></strong>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="border rounded p-2 bg-white">
                                    <small class="text-muted d-block">Presupuesto</small>
                                    <strong class="fs-5 text-success">S/ <?= number_format($proyecto_activo['presupuesto_referencial'], 2) ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Columna Derecha: Trazabilidad y Cronograma de Actividades -->
                    <div class="col-12 col-lg-5">
                        <!-- Barra de Avance General -->
                        <div class="border rounded-3 p-3 bg-white shadow-sm mb-4">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <strong class="text-dark"><i class="bi bi-speedometer2 text-primary me-1"></i> Avance General:</strong>
                                <span class="badge bg-success fs-6"><?= number_format($proyecto_activo['porcentaje_avance'], 1) ?>%</span>
                            </div>
                            <div class="progress progress-thin">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= (float)$proyecto_activo['porcentaje_avance'] ?>%"></div>
                            </div>
                        </div>

                        <!-- Historial y Comentarios UGEL -->
                        <div class="border rounded-3 p-3 bg-white shadow-sm mb-4">
                            <h6 class="fw-bold text-dark mb-3"><i class="bi bi-clock-history me-1"></i> Trazabilidad del Proyecto</h6>
                            <?php if (empty($historial_activo)): ?>
                                <p class="text-muted small mb-0">No hay movimientos registrados.</p>
                            <?php else: ?>
                                <div class="timeline">
                                    <?php foreach ($historial_activo as $h): ?>
                                    <div class="timeline-item">
                                        <div class="timeline-dot"></div>
                                        <div class="small fw-bold text-dark"><?= h($h['estado_nuevo']) ?></div>
                                        <div class="small text-muted"><?= h($h['comentario']) ?></div>
                                        <small class="text-secondary" style="font-size:0.75rem;">
                                            <?= h($h['nombres']) ?> (<?= h($h['rol']) ?>) &bull; <?= h($h['fecha_registro']) ?>
                                        </small>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- CRONOGRAMA DE ACTIVIDADES -->
                <hr class="my-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold text-dark mb-0"><i class="bi bi-calendar3-range text-danger me-2"></i>Cronograma de Actividades e Hitos</h5>
                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modalNuevaActividad">
                        <i class="bi bi-plus-circle me-1"></i> Agregar Actividad
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle border">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 50px;">#</th>
                                <th>Descripción de la Actividad</th>
                                <th>Responsable</th>
                                <th>Fechas Programadas</th>
                                <th style="width: 140px;">Avance</th>
                                <th>Estado</th>
                                <th class="text-center" style="width: 120px;">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($actividades_activo)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    No se han registrado actividades aún. Haga clic en "Agregar Actividad" para estructurar el plan.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($actividades_activo as $act): ?>
                            <tr>
                                <td class="fw-bold text-center"><?= (int)$act['numero_actividad'] ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= h($act['descripcion']) ?></div>
                                    <?php if ($act['evidencia_resumen']): ?>
                                    <small class="text-muted"><i class="bi bi-paperclip"></i> <?= h($act['evidencia_resumen']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= h($act['responsable']) ?></td>
                                <td class="small text-muted" style="white-space:nowrap;">
                                    <?= h($act['fecha_inicio_programada']) ?> &rarr; <?= h($act['fecha_fin_programada']) ?>
                                </td>
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
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalActualizarAct_<?= (int)$act['id'] ?>">
                                        <i class="bi bi-pencil-square"></i> Actualizar
                                    </button>
                                </td>
                            </tr>

                            <!-- Modal Actualizar Actividad -->
                            <div class="modal fade" id="modalActualizarAct_<?= (int)$act['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST">
                                            <input type="hidden" name="accion" value="actualizar_actividad">
                                            <input type="hidden" name="id_actividad" value="<?= (int)$act['id'] ?>">
                                            <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_activo['id'] ?>">
                                            <div class="modal-header">
                                                <h6 class="modal-title fw-bold">Actualizar Actividad #<?= (int)$act['numero_actividad'] ?></h6>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p class="small text-muted mb-3"><?= h($act['descripcion']) ?></p>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold small">Porcentaje de Avance (%)</label>
                                                    <input type="number" min="0" max="100" name="porcentaje_avance" class="form-control" value="<?= (float)$act['porcentaje_avance'] ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold small">Estado de la Actividad</label>
                                                    <select name="estado_actividad" class="form-select">
                                                        <option value="PENDIENTE" <?= $act['estado'] === 'PENDIENTE' ? 'selected' : '' ?>>Pendiente</option>
                                                        <option value="EN_PROCESO" <?= $act['estado'] === 'EN_PROCESO' ? 'selected' : '' ?>>En Proceso</option>
                                                        <option value="COMPLETADA" <?= $act['estado'] === 'COMPLETADA' ? 'selected' : '' ?>>Completada (100%)</option>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label fw-semibold small">Resumen de Evidencia o Comentario</label>
                                                    <input type="text" name="evidencia_resumen" class="form-control" value="<?= h($act['evidencia_resumen']) ?>" placeholder="Ej: Acta de asamblea y fotos adjuntas">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                                                <button type="submit" class="btn btn-primary btn-sm fw-bold">Guardar Avance</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- ========================================== -->
                <!-- EXPEDIENTE DOCUMENTAL Y EVIDENCIAS         -->
                <!-- ========================================== -->
                <hr class="my-4">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold text-dark mb-0">
                            <i class="bi bi-folder2-open text-primary me-2"></i>Expediente Documental y Evidencias Oficiales
                        </h5>
                        <small class="text-muted">Documento técnico del proyecto, actas de aprobación del CONEI, matrices curriculares y fotografías.</small>
                    </div>
                    <button class="btn btn-sm btn-outline-primary fw-bold" data-bs-toggle="modal" data-bs-target="#modalSubirEvidencia">
                        <i class="bi bi-cloud-arrow-up-fill me-1"></i> Adjuntar Evidencia / Archivo
                    </button>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover align-middle border">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 40px;">#</th>
                                <th>Documento / Sustento</th>
                                <th>Tipo de Archivo</th>
                                <th>Fecha de Registro</th>
                                <th class="text-center" style="width: 170px;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($evidencias_activo)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-file-earmark-arrow-up fs-2 d-block mb-2 opacity-50"></i>
                                    No se han adjuntado evidencias ni anexos aún.<br>
                                    Haga clic en <strong>"Adjuntar Evidencia / Archivo"</strong> para subir el proyecto en PDF/Word, actas o enlaces.
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($evidencias_activo as $idx => $ev): 
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
                                    <div class="d-flex justify-content-center gap-1">
                                        <?php if ($ev['tipo_archivo'] === 'ENLACE'): ?>
                                        <a href="<?= h($ev['ruta_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-info fw-bold" title="Abrir enlace externo">
                                            <i class="bi bi-box-arrow-up-right me-1"></i> Abrir
                                        </a>
                                        <?php else: ?>
                                        <a href="<?= h($ev['ruta_archivo']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="Visualizar documento">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="<?= h($ev['ruta_archivo']) ?>" download class="btn btn-sm btn-outline-success" title="Descargar archivo">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <?php endif; ?>

                                        <?php if (in_array($proyecto_activo['estado'], ['FORMULACION', 'OBSERVADO'])): ?>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('¿Está seguro de eliminar esta evidencia?');">
                                            <input type="hidden" name="accion" value="eliminar_evidencia">
                                            <input type="hidden" name="id_evidencia" value="<?= (int)$ev['id'] ?>">
                                            <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_activo['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar evidencia">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
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
        <!-- LISTADO DE PROYECTOS DE LA IE             -->
        <!-- ========================================== -->
        <div class="row g-4">
            <?php if (empty($proyectos)): ?>
            <div class="col-12">
                <div class="text-center py-5 bg-white rounded-3 border">
                    <i class="bi bi-lightbulb fs-1 text-muted d-block mb-3 opacity-50"></i>
                    <h5 class="fw-bold text-dark">Aún no hay proyectos de innovación registrados</h5>
                    <p class="text-muted mb-3">Comience registrando la primera propuesta pedagógica de su institución.</p>
                    <button class="btn btn-danger fw-bold" data-bs-toggle="modal" data-bs-target="#modalNuevoProyecto">
                        <i class="bi bi-plus-circle me-1"></i> Registrar Primer Proyecto
                    </button>
                </div>
            </div>
            <?php else: ?>
            <?php foreach ($proyectos as $proy): 
                $lin = $lineas_map[$proy['linea_tematica']] ?? ['nombre' => $proy['linea_tematica'], 'icono' => 'bi-tag'];
            ?>
            <div class="col-12 col-md-6 col-lg-4">
                <div class="proy-card h-100 d-flex flex-column">
                    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                        <span class="badge <?= badgeEstadoProy($proy['estado']) ?>">
                            <?= h($proy['estado']) ?>
                        </span>
                        <small class="text-muted fw-bold"><?= h($proy['codigo_proyecto']) ?></small>
                    </div>
                    <div class="p-3 flex-grow-1">
                        <div class="mb-2">
                            <span class="linea-pill">
                                <i class="bi <?= $lin['icono'] ?> text-danger"></i> <?= h($lin['nombre']) ?>
                            </span>
                        </div>
                        <h5 class="fw-bold text-dark mb-2" style="font-size:1.05rem; min-height: 50px;">
                            <?= h($proy['titulo']) ?>
                        </h5>
                        <p class="text-muted small mb-3" style="display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;">
                            <?= h($proy['objetivo_general']) ?>
                        </p>

                        <!-- Indicadores -->
                        <div class="d-flex justify-content-between align-items-center mb-1 small text-muted">
                            <span>Avance de Actividades:</span>
                            <span class="fw-bold text-dark"><?= number_format($proy['porcentaje_avance'], 0) ?>%</span>
                        </div>
                        <div class="progress progress-thin mb-3">
                            <div class="progress-bar bg-success" style="width: <?= (float)$proy['porcentaje_avance'] ?>%"></div>
                        </div>

                        <div class="d-flex justify-content-between small text-muted pt-2 border-top">
                            <span><i class="bi bi-people me-1"></i> <?= $proy['beneficiarios_estudiantes'] ?> est.</span>
                            <span class="text-success fw-bold">S/ <?= number_format($proy['presupuesto_referencial'], 2) ?></span>
                        </div>
                    </div>
                    <div class="p-3 bg-light border-top">
                        <a href="director_proyectos.php?ver=<?= (int)$proy['id'] ?>" class="btn btn-outline-danger w-100 btn-sm fw-bold">
                            <i class="bi bi-eye me-1"></i> Ver Proyecto y Actividades
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </main>
    </div><!-- /.ugel-app-shell -->

    <!-- ========================================== -->
    <!-- MODAL REGISTRO DE NUEVO PROYECTO           -->
    <!-- ========================================== -->
    <div class="modal fade" id="modalNuevoProyecto" tabindex="-1" aria-labelledby="modalNuevoProyectoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="accion" value="crear_proyecto">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title fw-bold" id="modalNuevoProyectoLabel">
                            <i class="bi bi-rocket-takeoff-fill me-2"></i>Registrar Proyecto de Innovación Educativa
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="alert alert-info py-2 small d-flex align-items-center gap-2 mb-3">
                            <i class="bi bi-info-circle-fill fs-5"></i>
                            <div>Los proyectos deben articularse a los Compromisos de Gestión Escolar (CGE) y lineamientos del FONDEP.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Título del Proyecto <span class="text-danger">*</span></label>
                            <input type="text" name="titulo" class="form-control" placeholder="Ej: Club de Robótica y Pensamiento Computacional..." required>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Línea Temática / Categoría <span class="text-danger">*</span></label>
                                <select name="linea_tematica" class="form-select" required>
                                    <option value="INNOVACION_PEDAGOGICA">Innovación Pedagógica en Aula</option>
                                    <option value="TECNOLOGIA_EDUCATIVA_TIC">Tecnología Educativa / TIC y STEM</option>
                                    <option value="CONVIVENCIA_CLIMA_ESCOLAR">Clima Escolar, Tutoría y Convivencia</option>
                                    <option value="CIENCIA_AMBIENTE_SOSTENIBILIDAD">Ciencia, Biohuertos y Ambiente</option>
                                    <option value="ARTE_CULTURA_DEPORTE">Arte, Cultura y Deporte</option>
                                    <option value="INCLUSION_ATENCION_DIVERSIDAD">Inclusión y Atención a la Diversidad</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label fw-bold">Presupuesto Referencial Estimado (S/)</label>
                                <input type="number" step="0.50" min="0" name="presupuesto_referencial" class="form-control" placeholder="0.00" value="0.00">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Problema Pedagógico Identificado <span class="text-danger">*</span></label>
                            <textarea name="problema_identificado" class="form-control" rows="3" placeholder="Describa el diagnóstico o dificultad en los aprendizajes o convivencia que motivó la propuesta..." required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Objetivo General <span class="text-danger">*</span></label>
                            <textarea name="objetivo_general" class="form-control" rows="2" placeholder="Qué se espera lograr con la implementación del proyecto..." required></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold">Objetivos Específicos</label>
                            <textarea name="objetivos_especificos" class="form-control" rows="2" placeholder="Resultados o metas parciales a alcanzar..."></textarea>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Estudiantes Beneficiados</label>
                                <input type="number" min="0" name="beneficiarios_estudiantes" class="form-control" value="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Docentes Participantes</label>
                                <input type="number" min="0" name="beneficiarios_docentes" class="form-control" value="0">
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Fecha de Inicio Programada</label>
                                <input type="date" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Fecha de Fin Programada</label>
                                <input type="date" name="fecha_fin" class="form-control" value="<?= date('Y-m-d', strtotime('+6 months')) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger fw-bold">Guardar Proyecto</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ========================================== -->
    <!-- MODAL AGREGAR ACTIVIDAD                    -->
    <!-- ========================================== -->
    <?php if ($proyecto_activo): ?>
    <div class="modal fade" id="modalNuevaActividad" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="accion" value="agregar_actividad">
                    <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_activo['id'] ?>">
                    <div class="modal-header bg-dark text-white">
                        <h6 class="modal-title fw-bold"><i class="bi bi-plus-circle me-1"></i> Nueva Actividad del Cronograma</h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Descripción de la Actividad <span class="text-danger">*</span></label>
                            <input type="text" name="descripcion" class="form-control" placeholder="Ej: Taller de capacitación docente en módulos STEM..." required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold small">Responsable de la Actividad <span class="text-danger">*</span></label>
                            <input type="text" name="responsable" class="form-control" placeholder="Ej: Docente coordinador / Prof. María Torres" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Fecha Inicio</label>
                                <input type="date" name="fecha_inicio_programada" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold small">Fecha Fin</label>
                                <input type="date" name="fecha_fin_programada" class="form-control" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" required>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-danger btn-sm fw-bold">Registrar Actividad</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- ========================================== -->
    <!-- MODAL ADJUNTAR EVIDENCIA / DOCUMENTO       -->
    <!-- ========================================== -->
    <div class="modal fade" id="modalSubirEvidencia" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="accion" value="subir_evidencia">
                    <input type="hidden" name="id_proyecto" value="<?= (int)$proyecto_activo['id'] ?>">
                    <div class="modal-header bg-primary text-white">
                        <h6 class="modal-title fw-bold">
                            <i class="bi bi-cloud-arrow-up-fill me-1"></i> Adjuntar Evidencia o Documento Oficial
                        </h6>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-4">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Nombre / Título del Documento <span class="text-danger">*</span></label>
                            <input type="text" name="titulo_evidencia" class="form-control" placeholder="Ej: Plan de Trabajo 2026, Acta CONEI, Registro Fotográfico..." required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Tipo de Sustento</label>
                            <select name="tipo_evidencia" id="selectTipoEvidencia" class="form-select" onchange="toggleTipoEvidencia(this.value)">
                                <option value="DOCUMENTO">Archivo Local (PDF, Word, Excel, ZIP)</option>
                                <option value="IMAGEN">Fotografía / Registro Visual (JPG, PNG)</option>
                                <option value="ENLACE">Enlace Web Externo (Google Drive / Carpeta en la nube)</option>
                            </select>
                        </div>

                        <div class="mb-3" id="campoArchivoLocal">
                            <label class="form-label fw-bold small">Seleccione Archivo (Máx. 20MB)</label>
                            <input type="file" name="archivo_evidencia" id="inputArchivoEvidencia" class="form-control" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.zip,.rar">
                            <div class="form-text">Formatos permitidos: PDF, Word, Excel, PowerPoint, Imágenes y archivos comprimidos.</div>
                        </div>

                        <div class="mb-3 d-none" id="campoEnlaceExterno">
                            <label class="form-label fw-bold small">Enlace URL / Carpeta Drive</label>
                            <input type="url" name="enlace_url" id="inputEnlaceUrl" class="form-control" placeholder="https://drive.google.com/drive/folders/...">
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Descripción / Observación Opcional</label>
                            <textarea name="descripcion_evidencia" class="form-control" rows="2" placeholder="Detalle adicional sobre el documento adjunto..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary btn-sm fw-bold">Subir Documento</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
    <script>
        function toggleTipoEvidencia(val) {
            const campoFile = document.getElementById('campoArchivoLocal');
            const campoUrl = document.getElementById('campoEnlaceExterno');
            const inputFile = document.getElementById('inputArchivoEvidencia');
            const inputUrl = document.getElementById('inputEnlaceUrl');

            if (val === 'ENLACE') {
                campoFile.classList.add('d-none');
                campoUrl.classList.remove('d-none');
                inputFile.removeAttribute('required');
                inputUrl.setAttribute('required', 'required');
            } else {
                campoFile.classList.remove('d-none');
                campoUrl.classList.add('d-none');
                inputFile.setAttribute('required', 'required');
                inputUrl.removeAttribute('required');
            }
        }
    </script>
</body>
</html>
