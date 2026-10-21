<?php
session_start();
include 'conexion.php';

// 1. VALIDAR SESIÓN Y ROL DE ADMINISTRADOR
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit();
}

// 2. OBTENER DATOS DEL ADMINISTRADOR PARA EL PERFIL
$id_usuario_sesion = $_SESSION['id_usuario'];
$query_admin = "SELECT nombres, ape_pat, ape_mat, rol, correo, dni FROM usuarios WHERE id = ?";
$stmt_admin = $conn->prepare($query_admin);
$stmt_admin->bind_param("i", $id_usuario_sesion);
$stmt_admin->execute();
$user_data = $stmt_admin->get_result()->fetch_assoc();
$nombre_completo = $user_data['nombres'] . " " . $user_data['ape_pat'] . " " . $user_data['ape_mat'];

// Control de navegación
$seccion = isset($_GET['view']) ? $_GET['view'] : 'usuarios';

// --- LÓGICA PARA EVALUAR DOCUMENTO (REVISIÓN DOCUMENTARIA) ---
if (isset($_POST['btn_guardar_evaluacion'])) {
    $id_envio = intval($_POST['id_envio']);
    $estado = $_POST['estado'];
    $observacion = $_POST['observacion'] ?? NULL;

    // Si el estado es Conforme o Pendiente, limpiamos la observación por si acaso
    if ($estado === 'Conforme' || $estado === 'Pendiente') {
        $observacion = NULL;
    }

    $sql = "UPDATE envios SET estado = ?, observacion = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);

    if ($stmt) {
        $stmt->bind_param("ssi", $estado, $observacion, $id_envio);
        if ($stmt->execute()) {
            header("Location: administrador.php?view=revision_documentaria&status=evaluated");
        } else {
            header("Location: administrador.php?view=revision_documentaria&status=error");
        }
        exit();
    }
}

// --- LÓGICA PARA GUARDAR O ACTUALIZAR INSTITUCIÓN ---
if (isset($_POST['btn_guardar_ie'])) {
    $id_ie = isset($_POST['id_ie']) ? intval($_POST['id_ie']) : 0;
    $cod_loc = $_POST['codigo_local'];
    $nom_ie = $_POST['nombre_ie'];
    $cod_inst = $_POST['codigo_institucion'];
    $nivel = $_POST['nivel_modalidad'];
    $gestion = $_POST['tipo_gestion'];
    $dir = $_POST['direccion'];
    $cp = $_POST['centro_poblado'];
    $dist = $_POST['distrito'];
    $base = $_POST['codigo_local_base'];

    if ($id_ie > 0) {
        $stmt = $conn->prepare("UPDATE instituciones SET codigo_local=?, nombre_ie=?, codigo_institucion=?, nivel_modalidad=?, tipo_gestion=?, direccion=?, centro_poblado=?, distrito=?, codigo_local_base=? WHERE id=?");
        $stmt->bind_param("ssssssssii", $cod_loc, $nom_ie, $cod_inst, $nivel, $gestion, $dir, $cp, $dist, $base, $id_ie);
        $status = $stmt->execute() ? 'updated' : 'error';
    } else {
        $stmt = $conn->prepare("INSERT INTO instituciones (codigo_local, nombre_ie, codigo_institucion, nivel_modalidad, tipo_gestion, direccion, centro_poblado, distrito, codigo_local_base) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("ssssssssi", $cod_loc, $nom_ie, $cod_inst, $nivel, $gestion, $dir, $cp, $dist, $base);
        $status = $stmt->execute() ? 'saved' : 'error';
    }
    header("Location: administrador.php?view=instituciones&status=$status");
    exit();
}

// --- LÓGICA DE ELIMINAR INSTITUCIÓN ---
if (isset($_GET['delete_ie']) && $seccion == 'instituciones') {
    $id_del_ie = intval($_GET['delete_ie']);
    $conn->query("DELETE FROM instituciones WHERE id = $id_del_ie");
    header("Location: administrador.php?view=instituciones&status=deleted");
    exit();
}

// --- LÓGICA DE CARGA PARA MODIFICAR INSTITUCIÓN ---
$edit_ie_data = null;
if (isset($_GET['edit_ie']) && $seccion == 'instituciones') {
    $id_edit_ie = intval($_GET['edit_ie']);
    $res_edit_ie = $conn->query("SELECT * FROM instituciones WHERE id = $id_edit_ie");
    $edit_ie_data = $res_edit_ie->fetch_assoc();
}

// --- LÓGICA PARA GUARDAR O ACTUALIZAR ASIGNACIÓN ---
if (isset($_POST['btn_guardar_asignacion'])) {
    $id_user_form = intval($_POST['id_usuario_val']);
    $id_ie_form = intval($_POST['id_ie_val']);
    $id_asignacion_form = isset($_POST['id_asignacion']) ? intval($_POST['id_asignacion']) : 0;
    $fecha_actual = date('Y-m-d');

    if ($id_user_form > 0 && $id_ie_form > 0) {

        // VALIDACIÓN: Verificar si el personal ya está vinculado a una institución
        $check_sql = "SELECT id FROM asignaciones WHERE id_usuario = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("ii", $id_user_form, $id_asignacion_form);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if ($check_res->num_rows > 0) {
            header("Location: administrador.php?view=Asignacion_ie&status=already_linked");
            exit();
        }

        if ($id_asignacion_form > 0) {
            $stmt_upd = $conn->prepare("UPDATE asignaciones SET id_usuario = ?, id_ie = ? WHERE id = ?");
            $stmt_upd->bind_param("iii", $id_user_form, $id_ie_form, $id_asignacion_form);
            if ($stmt_upd->execute()) {
                header("Location: administrador.php?view=Asignacion_ie&status=updated");
            } else {
                header("Location: administrador.php?view=Asignacion_ie&status=error");
            }
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES (?, ?, ?, 'activo')");
            $stmt_ins->bind_param("iis", $id_user_form, $id_ie_form, $fecha_actual);
            if ($stmt_ins->execute()) {
                header("Location: administrador.php?view=Asignacion_ie&status=saved");
            } else {
                header("Location: administrador.php?view=Asignacion_ie&status=error");
            }
        }
    } else {
        header("Location: administrador.php?view=Asignacion_ie&status=error");
    }
    exit();
}

// --- LÓGICA DE ELIMINAR ASIGNACIÓN ---
if (isset($_GET['delete_asignacion']) && $seccion == 'Asignacion_ie') {
    $id_del_asig = intval($_GET['delete_asignacion']);
    $conn->query("DELETE FROM asignaciones WHERE id = $id_del_asig");
    header("Location: administrador.php?view=Asignacion_ie&status=deleted");
    exit();
}

// --- LÓGICA DE CARGA PARA MODIFICAR ASIGNACIÓN ---
$edit_asignacion_data = null;
if (isset($_GET['edit_asignacion']) && $seccion == 'Asignacion_ie') {
    $id_edit_asig = intval($_GET['edit_asignacion']);
    $res_edit_asig = $conn->query("SELECT * FROM asignaciones WHERE id = $id_edit_asig");
    $edit_asignacion_data = $res_edit_asig->fetch_assoc();
}

// --- LÓGICA PARA GUARDAR O ACTUALIZAR ASIGNACIÓN ESPECIALISTA ---
if (isset($_POST['btn_guardar_asig_esp'])) {
    $id_usuario = intval($_POST['id_especialista_val']);
    $id_institucion = intval($_POST['id_ie_val']);
    $id_asig_esp = isset($_POST['id_asig_esp']) ? intval($_POST['id_asig_esp']) : 0;
    $fecha_actual = date('Y-m-d');
    $sub_tipo = $_POST['sub_tipo'] ?? ''; // NUEVO CAMPO OBTENIDO

    if ($id_usuario > 0 && $id_institucion > 0) {

        // VALIDACIÓN DE DUPLICADOS PARA ESPECIALISTA E INSTITUCIÓN
        $check_sql = "SELECT id FROM asignaciones_especialistas WHERE id_usuario = ? AND id_institucion = ? AND id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("iii", $id_usuario, $id_institucion, $id_asig_esp);
        $check_stmt->execute();
        $check_res = $check_stmt->get_result();

        if ($check_res->num_rows > 0) {
            header("Location: administrador.php?view=asignacion_especialistas&status=duplicate_esp");
            exit();
        }

        if ($id_asig_esp > 0) {
            $stmt_upd = $conn->prepare("UPDATE asignaciones_especialistas SET id_usuario = ?, id_institucion = ?, sub_tipo = ? WHERE id = ?");
            $stmt_upd->bind_param("iisi", $id_usuario, $id_institucion, $sub_tipo, $id_asig_esp);
            $status = $stmt_upd->execute() ? 'updated' : 'error';
        } else {
            $stmt_ins = $conn->prepare("INSERT INTO asignaciones_especialistas (id_usuario, id_institucion, sub_tipo, fecha_inicio, estado) VALUES (?, ?, ?, ?, 'activo')");
            $stmt_ins->bind_param("iiss", $id_usuario, $id_institucion, $sub_tipo, $fecha_actual);
            $status = $stmt_ins->execute() ? 'saved' : 'error';
        }

        // LÓGICA: ACTUALIZACIÓN MASIVA DE LA CATEGORÍA (sub_tipo) PARA TODOS LOS REGISTROS DEL USUARIO
        if ($status == 'updated' || $status == 'saved') {
            $stmt_global = $conn->prepare("UPDATE asignaciones_especialistas SET sub_tipo = ? WHERE id_usuario = ?");
            $stmt_global->bind_param("si", $sub_tipo, $id_usuario);
            $stmt_global->execute();
        }

        header("Location: administrador.php?view=asignacion_especialistas&status=$status");
    } else {
        header("Location: administrador.php?view=asignacion_especialistas&status=error");
    }
    exit();
}

// --- LÓGICA DE ELIMINAR ASIGNACIÓN ESPECIALISTA ---
if (isset($_GET['delete_asig_esp']) && $seccion == 'asignacion_especialistas') {
    $id_del = intval($_GET['delete_asig_esp']);
    $conn->query("DELETE FROM asignaciones_especialistas WHERE id = $id_del");
    header("Location: administrador.php?view=asignacion_especialistas&status=deleted");
    exit();
}

// --- LÓGICA DE CARGA PARA MODIFICAR ASIGNACIÓN ESPECIALISTA ---
$edit_asig_esp_data = null;
if (isset($_GET['edit_asig_esp']) && $seccion == 'asignacion_especialistas') {
    $id_edit = intval($_GET['edit_asig_esp']);
    $res_edit = $conn->query("SELECT * FROM asignaciones_especialistas WHERE id = $id_edit");
    $edit_asig_esp_data = $res_edit->fetch_assoc();
}

// --- LÓGICA DE ELIMINAR USUARIOS ---
if (isset($_GET['delete']) && $seccion == 'usuarios') {
    $id_del = intval($_GET['delete']);
    $conn->query("DELETE FROM usuarios WHERE id = $id_del");
    header("Location: administrador.php?view=usuarios&status=deleted");
    exit();
}

// --- LÓGICA DE CARGA PARA MODIFICAR USUARIOS ---
$edit_data = null;
if (isset($_GET['edit']) && $seccion == 'usuarios') {
    $id_edit = intval($_GET['edit']);
    $res_edit = $conn->query("SELECT * FROM usuarios WHERE id = $id_edit");
    $edit_data = $res_edit->fetch_assoc();
}
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <?php $page_title = 'Panel de Administración - UGEL Chincha';
    include 'components/head.php'; ?>
    <style>
        /* ===== Panel de Administración · Fluent Design (alineado a fluent.css) ===== */
        :root {
            --admin-sidebar-bg: #15241c;
            /* verde-carbón oscuro (familia de marca) */
            --admin-sidebar-text: #aebab3;
            --admin-sidebar-hover: rgba(255, 255, 255, 0.07);
            --admin-sidebar-accent: #7fd1a3;
        }

        body {
            background: var(--ugel-bg);
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        /* ---------- Sidebar ---------- */
        .sidebar {
            min-height: 100vh;
            background: var(--admin-sidebar-bg);
            color: #fff;
            transition: left 0.25s ease-in-out;
            z-index: 1045;
        }

        .sidebar .brand-logo {
            background: rgba(255, 255, 255, 0.08);
            border-radius: var(--ugel-radius-card);
            padding: 8px 12px;
            display: inline-flex;
        }

        .sidebar h6 {
            letter-spacing: 0.3px;
        }

        .sidebar small {
            color: var(--admin-sidebar-accent) !important;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1040;
        }

        .nav-link {
            color: var(--admin-sidebar-text);
            border-radius: var(--ugel-radius-sm);
            margin: 2px 12px;
            padding: 10px 14px;
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            border-left: 3px solid transparent;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .nav-link:hover {
            background: var(--admin-sidebar-hover);
            color: #fff;
        }

        .nav-link.active {
            background: var(--ugel-green-dark) !important;
            color: #fff !important;
            border-left-color: var(--admin-sidebar-accent);
            box-shadow: none;
        }

        .nav-link i {
            font-size: 1rem;
            width: 20px;
        }

        @media (max-width: 767.98px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 0;
                width: 260px;
                overflow-y: auto;
            }

            .sidebar.active {
                left: 0;
            }

            .sidebar-overlay.active {
                display: block;
            }
        }

        /* ---------- Header ---------- */
        .header-panel {
            background: #fff;
            padding: 0.85rem 1.75rem;
            border-bottom: 1px solid var(--ugel-border);
            box-shadow: var(--ugel-shadow-soft);
        }

        .header-panel h4 {
            color: #0f172a !important;
        }

        .header-panel h4 i {
            color: var(--ugel-green-dark) !important;
        }

        /* Botón de perfil sobre header blanco
           (corrige el override verde que fluent.css aplica para topbars) */
        .header-panel .profile-btn {
            background: var(--ugel-bg) !important;
            border: 1px solid var(--ugel-border) !important;
            border-radius: var(--ugel-radius-card) !important;
            padding: 6px 14px !important;
            transition: background 0.15s ease;
        }

        .header-panel .profile-btn:hover {
            background: #eef0f1 !important;
        }

        .header-panel .profile-btn small {
            color: var(--ugel-text-muted) !important;
        }

        .header-panel .profile-btn strong {
            color: var(--ugel-text) !important;
        }

        .header-panel .profile-btn .bi-person-circle {
            color: var(--ugel-green-dark) !important;
        }

        .dropdown-menu-profile {
            min-width: 280px;
        }

        .icon-box {
            width: 56px;
            height: 56px;
            background: var(--ugel-green-light);
            color: var(--ugel-green-dark);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--ugel-text-muted);
            font-weight: 700;
            margin-bottom: 0;
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--ugel-text);
            margin-bottom: 8px;
        }

        /* ---------- Tarjetas (planas, estilo Fluent) ---------- */
        .card {
            border: 1px solid var(--ugel-border);
            border-radius: var(--ugel-radius-card);
            box-shadow: var(--ugel-shadow-soft);
        }

        .card-header {
            background: #fff;
            border-bottom: 1px solid var(--ugel-border);
        }

        .card-header h5 {
            font-size: 1.02rem;
        }

        /* ---------- Formularios ---------- */
        .form-control,
        .form-select {
            height: 36px !important;
            border: 1px solid #a19f9d !important;
            border-radius: 4px !important;
            font-size: 0.9rem !important;
            color: #323130 !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--ugel-green-dark) !important;
            box-shadow: 0 0 0 2px rgba(26, 92, 56, 0.16) !important;
            outline: 0;
        }

        .form-control[readonly] {
            background-color: #f3f2f1 !important;
            border-color: #e1dfdd !important;
            color: #605e5c !important;
        }

        .form-label {
            font-weight: 600 !important;
            color: #323130 !important;
            font-size: 0.85rem !important;
            margin-bottom: 6px !important;
            display: block !important;
        }

        /* ---------- Select2 Customizations ---------- */
        .select2-container--default .select2-selection--single {
            height: 36px !important;
            border: 1px solid #a19f9d !important;
            border-radius: 4px !important;
            display: flex !important;
            align-items: center !important;
            background-color: #fff !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #323130 !important;
            font-size: 0.9rem !important;
            padding-left: 12px !important;
            padding-right: 30px !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 34px !important;
            right: 8px !important;
        }

        .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: var(--ugel-green-dark) !important;
            box-shadow: 0 0 0 2px rgba(26, 92, 56, 0.16) !important;
        }

        .select2-dropdown {
            border: 1px solid #a19f9d !important;
            border-radius: 4px !important;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1) !important;
        }

        /* ---------- Paginación Fluent Design ---------- */
        .fluent-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .fluent-page-link {
            text-decoration: none;
            color: #323130;
            background: #f3f2f1;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.1s ease;
        }

        .fluent-page-link:hover {
            background: #edebe9;
            color: #323130;
        }

        .fluent-page-link.active {
            background: #107c41;
            color: #ffffff !important;
        }

        .fluent-page-dots {
            padding: 6px 4px;
            color: #605e5c;
            font-weight: 600;
        }

        /* ---------- Fluent Input Group (Search boxes) ---------- */
        .fluent-input-group {
            position: relative;
            display: flex;
            align-items: center;
        }

        .fluent-input-group i {
            position: absolute;
            left: 12px;
            color: #a19f9d;
        }

        .fluent-input-group .form-control {
            padding-left: 36px;
            border-radius: 4px;
            border: 1px solid #a19f9d;
            height: 36px;
        }

        .fluent-input-group .form-control:focus {
            border-color: var(--ugel-green-dark);
            box-shadow: none;
        }

        /* ---------- Responsive Administrador ---------- */
        @media (max-width: 767.98px) {
            .header-panel {
                padding: 0.65rem 1rem;
                flex-wrap: wrap;
                gap: 8px;
            }

            .header-panel h4 {
                font-size: 1rem !important;
            }
        }

        @media (max-width: 480px) {
            .container-fluid {
                padding-left: 0 !important;
                padding-right: 0 !important;
            }

            .card-body {
                padding: 12px 14px !important;
            }

            .card-header {
                padding: 10px 14px !important;
            }

            .fluent-pagination {
                gap: 4px;
            }

            .fluent-page-link {
                padding: 5px 9px;
                font-size: 0.8rem;
            }

            /* Tabla admin responsive en bloques */
            .table-responsive table thead {
                display: none;
            }

            .table-responsive table,
            .table-responsive table tbody,
            .table-responsive table tr,
            .table-responsive table td {
                display: block;
                width: 100% !important;
            }

            .table-responsive table tr {
                margin-bottom: 1rem;
                border: 1px solid var(--ugel-border);
                border-radius: 6px;
                padding: 10px 12px;
                background: #fff;
            }

            .table-responsive table td {
                padding: 6px 0 !important;
                border: none;
                border-bottom: 1px solid #f1f5f9;
                font-size: 0.85rem;
            }

            .table-responsive table td:last-child {
                border-bottom: none;
            }

            .table-responsive table td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 0.7rem;
                font-weight: 700;
                text-transform: uppercase;
                color: var(--ugel-text-muted);
                margin-bottom: 2px;
            }
        }
    </style>
</head>

<body>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div class="container-fluid overflow-hidden">
        <div class="row">
            <div class="col-md-3 col-lg-2 p-0 sidebar shadow" id="mainSidebar">
                <div class="p-4 text-center position-relative">
                    <button class="btn btn-sm btn-outline-light border-0 position-absolute d-md-none"
                        id="closeSidebarBtn" style="top: 10px; right: 10px;">
                        <i class="bi bi-x-lg"></i>
                    </button>
                    <img src="https://img.icons8.com/fluency/48/administrative-tools.png" class="brand-logo mb-2"
                        alt="Logo">
                    <h6 class="fw-bold text-white mb-0">SIA UGEL CHINCHA</h6>
                    <small class="text-muted">Panel Administrativo</small>
                </div>
                <hr class="mx-3 opacity-25">
                <nav class="nav flex-column mt-3">
                    <a class="nav-link <?php echo $seccion == 'usuarios' ? 'active' : ''; ?>" href="?view=usuarios">
                        <i class="bi bi-people-fill me-2"></i> Usuarios
                    </a>
                    <a class="nav-link <?php echo $seccion == 'instituciones' ? 'active' : ''; ?>"
                        href="?view=instituciones">
                        <i class="bi bi-bank me-2"></i> Inst. Educativas
                    </a>
                    <a class="nav-link <?php echo $seccion == 'Asignacion_ie' ? 'active' : ''; ?>"
                        href="?view=Asignacion_ie">
                        <i class="bi bi-building-check me-2"></i> Asignación I.E
                    </a>
                    <a class="nav-link <?php echo $seccion == 'asignacion_especialistas' ? 'active' : ''; ?>"
                        href="?view=asignacion_especialistas">
                        <i class="bi bi-person-badge me-2"></i> Especialistas
                    </a>
                    <a class="nav-link <?php echo $seccion == 'revision_documentaria' ? 'active' : ''; ?>"
                        href="?view=revision_documentaria">
                        <i class="bi bi-folder-check me-2"></i> Revisión Documentaria
                    </a>
                    <a class="nav-link <?php echo $seccion == 'fichas_monitoreo' ? 'active' : ''; ?>"
                        href="?view=fichas_monitoreo">
                        <i class="bi bi-clipboard2-pulse me-2"></i> Fichas de Monitoreo
                    </a>
                </nav>
            </div>

            <div class="col-md-9 col-lg-10 p-0 overflow-auto vh-100">
                <div class="header-panel d-flex justify-content-between align-items-center mb-4 flex-wrap">
                    <div class="d-flex align-items-center mb-2 mb-sm-0">
                        <button class="btn btn-outline-secondary d-md-none me-2 border-0" id="sidebarToggle">
                            <i class="bi bi-list fs-4"></i>
                        </button>
                        <h4 class="text-uppercase fw-bold text-dark m-0 fs-5 fs-md-4" style="letter-spacing: -0.5px;">
                            <i class="bi bi-layers-half text-success me-2"></i>Administración
                        </h4>
                    </div>
                    <div class="ms-auto">
                        <div class="dropdown">
                            <button class="btn profile-btn dropdown-toggle d-flex align-items-center gap-2"
                                type="button" data-bs-toggle="dropdown">
                                <div class="text-end d-none d-sm-block">
                                    <small class="d-block text-muted"
                                        style="font-size: 0.65rem; text-transform: uppercase;">Sesión Activa</small>
                                    <strong
                                        style="font-size: 0.85rem; color: #333;"><?php echo htmlspecialchars($user_data['nombres']); ?></strong>
                                </div>
                                <i class="bi bi-person-circle fs-3 text-success"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile p-4 mt-2 shadow border-0">
                                <li class="text-center mb-4">
                                    <div class="icon-box mb-2"><i class="bi bi-shield-lock-fill fs-2"></i></div>
                                    <h6 class="fw-bold mb-0 text-success">
                                        <?php echo htmlspecialchars($nombre_completo); ?></h6>
                                    <small
                                        class="text-muted"><?php echo htmlspecialchars($user_data['correo']); ?></small>
                                </li>
                                <li class="px-2">
                                    <p class="info-label">Documento DNI</p>
                                    <p class="info-value"><?php echo htmlspecialchars($user_data['dni']); ?></p>
                                    <p class="info-label">Rol del Sistema</p>
                                    <p class="info-value"><span class="badge-soft px-3">ADMINISTRADOR</span></p>
                                </li>
                                <li>
                                    <hr class="dropdown-divider mt-4 mb-3">
                                </li>
                                <li><a class="btn btn-outline-primary w-100 fw-bold rounded-pill mb-2"
                                        href="panel_usuario.php">Perfil</a></li>
                                <li><a class="btn btn-outline-danger w-100 fw-bold rounded-pill"
                                        href="logout.php">Cerrar Sesión</a></li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div class="container-fluid px-2 px-md-4">
                    <?php if (isset($_GET['status'])): ?>
                        <?php
                        $status = $_GET['status'];
                        $alert_class = ($status == 'error' || $status == 'duplicate' || $status == 'duplicate_esp' || $status == 'already_linked') ? 'alert-danger' : 'alert-success';
                        $messages = [
                            'saved' => '¡Operación realizada exitosamente!',
                            'updated' => 'Se actualizó correctamente el registro',
                            'deleted' => '¡Registro eliminado correctamente!',
                            'duplicate' => 'Error: Registro duplicado',
                            'duplicate_esp' => 'Este especialista ya fue asignado a esta institucion.',
                            'already_linked' => 'el personal ya está vinculado a una institución.',
                            'evaluated' => 'Evaluación guardada correctamente.',
                            'error' => 'Ocurrió un error inesperado.'
                        ];
                        ?>
                        <div class="alert <?php echo $alert_class; ?> alert-dismissible fade show shadow-sm border-0 mb-4"
                            role="alert">
                            <div class="d-flex align-items-center">
                                <i
                                    class="bi <?php echo ($status == 'error' || $status == 'duplicate' || $status == 'duplicate_esp' || $status == 'already_linked') ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill'; ?> me-2 fs-5"></i>
                                <div><?php echo $messages[$status] ?? 'Estado desconocido'; ?></div>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($seccion == 'usuarios'): ?>
                        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
                            rel="stylesheet" />
                        <div id="formulario-usuarios" class="card mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="m-0 fw-bold <?php echo $edit_data ? 'text-warning' : 'text-success'; ?>">
                                    <i class="bi bi-pencil-square"></i>
                                    <?php echo $edit_data ? 'Modificar Usuario' : 'Registrar Nuevo Usuario'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form action="procesar_usuario.php" method="POST">
                                    <input type="hidden" name="id_usuario" value="<?php echo $edit_data['id'] ?? ''; ?>">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">ID</label>
                                            <input type="text" class="form-control bg-light"
                                                value="<?php echo $edit_data['id'] ?? 'Auto'; ?>" readonly>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label class="form-label">DNI (Usuario)</label>
                                            <select name="dni" id="dni_select" class="form-select" style="width: 100%;"
                                                required>
                                                <option value="">Buscar por DNI...</option>
                                                <?php
                                                $res_dir = $conn->query("SELECT dni, nombres, ape_paterno, ape_materno FROM data_direccion ORDER BY ape_paterno ASC");
                                                if ($res_dir) {
                                                    while ($dir = $res_dir->fetch_assoc()) {
                                                        $sel = (isset($edit_data['dni']) && $edit_data['dni'] == $dir['dni']) ? 'selected' : '';
                                                        echo "<option value='{$dir['dni']}' $sel data-nom='{$dir['nombres']}' data-pat='{$dir['ape_paterno']}' data-mat='{$dir['ape_materno']}'>{$dir['dni']} - {$dir['ape_paterno']} {$dir['ape_materno']}, {$dir['nombres']}</option>";
                                                    }
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label class="form-label">Contraseña</label>
                                            <input type="text" name="password" class="form-control" required
                                                value="<?php echo $edit_data['password'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Nombres</label>
                                            <input type="text" name="nombres" class="form-control" required
                                                value="<?php echo $edit_data['nombres'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Apellido Paterno</label>
                                            <input type="text" name="ape_pat" class="form-control" required
                                                value="<?php echo $edit_data['ape_pat'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Apellido Materno</label>
                                            <input type="text" name="ape_mat" class="form-control" required
                                                value="<?php echo $edit_data['ape_mat'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Correo Electrónico</label>
                                            <input type="email" name="correo" class="form-control" required
                                                value="<?php echo $edit_data['correo'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Teléfono</label>
                                            <input type="text" name="telefono" class="form-control"
                                                value="<?php echo $edit_data['telefono'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Rol</label>
                                            <select name="rol" class="form-select" required>
                                                <?php $roles = ['director', 'sub-director', 'especialista', 'administrador'];
                                                foreach ($roles as $r) {
                                                    $s = (isset($edit_data['rol']) && $edit_data['rol'] == $r) ? 'selected' : '';
                                                    echo "<option value='$r' $s>" . ucfirst($r) . "</option>";
                                                } ?>
                                            </select>
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Estado</label>
                                            <select name="estado" class="form-select">
                                                <option value="activo" <?php echo (isset($edit_data['estado']) && $edit_data['estado'] == 'activo') ? 'selected' : ''; ?>>Activo</option>
                                                <option value="inactivo" <?php echo (isset($edit_data['estado']) && $edit_data['estado'] == 'inactivo') ? 'selected' : ''; ?>>Inactivo
                                                </option>
                                            </select>
                                        </div>
                                        <div
                                            class="col-12 text-end mt-4 d-flex flex-column flex-sm-row justify-content-end gap-2">
                                            <?php if ($edit_data): ?>
                                                <a href="?view=usuarios" class="btn btn-secondary px-4 fw-bold">Cancelar</a>
                                            <?php else: ?>
                                                <button type="reset"
                                                    class="btn btn-outline-secondary px-4 fw-bold">Limpiar</button>
                                            <?php endif; ?>
                                            <button type="submit" class="btn btn-success px-5 fw-bold">
                                                <?php echo $edit_data ? 'Actualizar Cambios' : 'Guardar Usuario'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div
                                class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                <h5 class="m-0 fw-bold text-dark w-100"><i class="bi bi-table me-2"></i>Listado de usuarios
                                </h5>
                                <?php $search_user = isset($_GET['search_user']) ? $_GET['search_user'] : ''; ?>
                                <form action="" method="GET" class="d-flex w-100"
                                    style="max-width: 400px; margin-left: auto;">
                                    <input type="hidden" name="view" value="usuarios">
                                    <div class="fluent-input-group me-2">
                                        <i class="bi bi-search"></i>
                                        <input type="text" name="search_user" class="form-control"
                                            placeholder="Buscar DNI o Nombres..."
                                            value="<?php echo htmlspecialchars($search_user); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success px-3 fw-bold">Buscar</button>
                                    <?php if ($search_user != ''): ?>
                                        <a href="?view=usuarios" class="btn btn-outline-secondary px-3 ms-1 fw-bold"><i
                                                class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th class="ps-3">ID</th>
                                                <th>DNI</th>
                                                <th>Nombres y Apellidos</th>
                                                <th>Contacto</th>
                                                <th>Rol/Estado</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // CONFIGURACIÓN DE PAGINACIÓN PARA USUARIOS
                                            $registros_por_pagina_user = 10;
                                            $pagina_actual_user = isset($_GET['page_user']) ? (int) $_GET['page_user'] : 1;
                                            if ($pagina_actual_user < 1)
                                                $pagina_actual_user = 1;
                                            $offset_user = ($pagina_actual_user - 1) * $registros_por_pagina_user;
                                            $filter_user = "";
                                            if ($search_user != '') {
                                                $s_u = $conn->real_escape_string($search_user);
                                                $filter_user = " WHERE dni LIKE '%$s_u%' OR nombres LIKE '%$s_u%' OR ape_pat LIKE '%$s_u%' OR ape_mat LIKE '%$s_u%'";
                                            }

                                            // Contar total para paginación
                                            $total_res_u = $conn->query("SELECT COUNT(*) as total FROM usuarios $filter_user");
                                            $total_registros_u = $total_res_u->fetch_assoc()['total'];
                                            $total_paginas_u = ceil($total_registros_u / $registros_por_pagina_user);

                                            $usuarios = $conn->query("SELECT * FROM usuarios $filter_user ORDER BY id DESC LIMIT $offset_user, $registros_por_pagina_user");
                                            if ($usuarios->num_rows > 0):
                                                while ($u = $usuarios->fetch_assoc()):
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold"><?php echo $u['id']; ?></td>
                                                        <td><span
                                                                class="badge bg-light text-dark border"><?php echo $u['dni']; ?></span>
                                                        </td>
                                                        <td><?php echo "{$u['nombres']} {$u['ape_pat']} {$u['ape_mat']}"; ?></td>
                                                        <td>
                                                            <div class="small fw-bold text-primary"><?php echo $u['correo']; ?>
                                                            </div>
                                                            <div class="small text-muted"><?php echo $u['telefono'] ?: 'No reg.'; ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span
                                                                class="badge bg-info bg-opacity-10 text-info border px-2 text-uppercase me-1"><?php echo $u['rol']; ?></span>
                                                            <span
                                                                class="status-badge <?php echo $u['estado'] == 'activo' ? 'status-active' : 'status-inactive'; ?>"><?php echo ucfirst($u['estado']); ?></span>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex justify-content-center gap-1">
                                                                <a href="?view=usuarios&edit=<?php echo $u['id']; ?>&page_user=<?php echo $pagina_actual_user; ?>#formulario-usuarios"
                                                                    class="btn btn-sm btn-outline-success" title="Editar">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?view=usuarios&delete=<?php echo $u['id']; ?>&page_user=<?php echo $pagina_actual_user; ?>"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('¿Eliminar usuario?')" title="Eliminar">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php
                                                endwhile;
                                            else:
                                                echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No se encontraron registros</td></tr>";
                                            endif;
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div
                                class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted text-center">Mostrando registros del
                                    <?php echo ($offset_user + 1); ?> al
                                    <?php echo min($offset_user + $registros_por_pagina_user, $total_registros_u); ?> de un
                                    total de <?php echo $total_registros_u; ?></small>
                                <?php if ($total_paginas_u > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($pagina_actual_user > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=usuarios&page_user=1&search_user=<?php echo urlencode($search_user); ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=usuarios&page_user=<?php echo $pagina_actual_user - 1; ?>&search_user=<?php echo urlencode($search_user); ?>">&lsaquo;</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $pagina_actual_user - 2);
                                        $end = min($total_paginas_u, $pagina_actual_user + 2);
                                        for ($p = $start; $p <= $end; $p++): ?>
                                            <a class="fluent-page-link <?php echo ($p == $pagina_actual_user) ? 'active' : ''; ?>"
                                                href="?view=usuarios&page_user=<?php echo $p; ?>&search_user=<?php echo urlencode($search_user); ?>"><?php echo $p; ?></a>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual_user < $total_paginas_u): ?>
                                            <a class="fluent-page-link"
                                                href="?view=usuarios&page_user=<?php echo $pagina_actual_user + 1; ?>&search_user=<?php echo urlencode($search_user); ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=usuarios&page_user=<?php echo $total_paginas_u; ?>&search_user=<?php echo urlencode($search_user); ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
                        <script>
                            $(document).ready(function () {
                                $('#dni_select').select2({
                                    placeholder: "Buscar DNI o ingresar nuevo...",
                                    allowClear: true,
                                    tags: true // Modificación implementada: Permite ingresar DNI manual
                                }).on('change', function () {
                                    var opt = $(this).find(':selected');
                                    var dni = $(this).val(); // Obtenemos el valor directo
                                    if (dni) {
                                        $('input[name="password"]').val(dni);
                                        // Solo autocompleta si es una opción que ya traía data
                                        if (opt.data('nom') !== undefined) {
                                            $('input[name="nombres"]').val(opt.data('nom') || '');
                                            $('input[name="ape_pat"]').val(opt.data('pat') || '');
                                            $('input[name="ape_mat"]').val(opt.data('mat') || '');
                                        }
                                    }
                                });
                                <?php if ($edit_data): ?>
                                    // Asegurar que el DNI a editar se muestre aunque se haya ingresado manualmente
                                    if ($('#dni_select').find("option[value='<?php echo $edit_data['dni']; ?>']").length === 0) {
                                        var newOption = new Option("<?php echo $edit_data['dni']; ?>", "<?php echo $edit_data['dni']; ?>", true, true);
                                        $('#dni_select').append(newOption);
                                    }
                                    $('#dni_select').trigger('change.select2');
                                <?php endif; ?>
                            });
                        </script>

                    <?php elseif ($seccion == 'instituciones'): ?>
                        <div id="formulario-ie" class="card mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="m-0 fw-bold <?php echo $edit_ie_data ? 'text-warning' : 'text-success'; ?>">
                                    <i class="bi bi-bank2"></i>
                                    <?php echo $edit_ie_data ? 'Modificar Institución' : 'Registrar Nueva Institución'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <form action="administrador.php?view=instituciones" method="POST">
                                    <input type="hidden" name="id_ie" value="<?php echo $edit_ie_data['id'] ?? ''; ?>">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-2">
                                            <label class="form-label">Cód. Local</label>
                                            <input type="text" name="codigo_local" class="form-control" required
                                                value="<?php echo $edit_ie_data['codigo_local'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-6">
                                            <label class="form-label">Nombre de la I.E.</label>
                                            <input type="text" name="nombre_ie" class="form-control" required
                                                value="<?php echo $edit_ie_data['nombre_ie'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Cód. Modular / Inst.</label>
                                            <input type="text" name="codigo_institucion" class="form-control" required
                                                value="<?php echo $edit_ie_data['codigo_institucion'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Nivel / Modalidad</label>
                                            <input type="text" name="nivel_modalidad" class="form-control"
                                                value="<?php echo $edit_ie_data['nivel_modalidad'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Tipo Gestión</label>
                                            <input type="text" name="tipo_gestion" class="form-control"
                                                value="<?php echo $edit_ie_data['tipo_gestion'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Distrito</label>
                                            <input type="text" name="distrito" class="form-control"
                                                value="<?php echo $edit_ie_data['distrito'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-5">
                                            <label class="form-label">Dirección</label>
                                            <input type="text" name="direccion" class="form-control"
                                                value="<?php echo $edit_ie_data['direccion'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <label class="form-label">Centro Poblado</label>
                                            <input type="text" name="centro_poblado" class="form-control"
                                                value="<?php echo $edit_ie_data['centro_poblado'] ?? ''; ?>">
                                        </div>
                                        <div class="col-12 col-md-3">
                                            <label class="form-label">Cód. Local Base</label>
                                            <input type="number" name="codigo_local_base" class="form-control"
                                                value="<?php echo $edit_ie_data['codigo_local_base'] ?? ''; ?>">
                                        </div>
                                        <div
                                            class="col-12 text-end mt-4 d-flex flex-column flex-sm-row justify-content-end gap-2">
                                            <?php if ($edit_ie_data): ?>
                                                <a href="?view=instituciones"
                                                    class="btn btn-secondary px-4 fw-bold">Cancelar</a>
                                            <?php else: ?>
                                                <button type="reset"
                                                    class="btn btn-outline-secondary px-4 fw-bold">Limpiar</button>
                                            <?php endif; ?>
                                            <button type="submit" name="btn_guardar_ie"
                                                class="btn btn-success px-5 fw-bold">
                                                <?php echo $edit_ie_data ? 'Actualizar Cambios' : 'Guardar Institución'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div
                                class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                <h5 class="m-0 fw-bold text-dark w-100"><i class="bi bi-table me-2"></i>Listado de
                                    Instituciones</h5>
                                <?php $search_ie = isset($_GET['search_ie']) ? $_GET['search_ie'] : ''; ?>
                                <form action="" method="GET" class="d-flex w-100"
                                    style="max-width: 400px; margin-left: auto;">
                                    <input type="hidden" name="view" value="instituciones">
                                    <div class="fluent-input-group me-2">
                                        <i class="bi bi-search"></i>
                                        <input type="text" name="search_ie" class="form-control"
                                            placeholder="Buscar por Nombre, Cód. o Distrito..."
                                            value="<?php echo htmlspecialchars($search_ie); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success px-3 fw-bold">Buscar</button>
                                    <?php if ($search_ie != ''): ?>
                                        <a href="?view=instituciones" class="btn btn-outline-secondary px-3 ms-1 fw-bold"><i
                                                class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="min-width: 800px;">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th class="ps-3">Cód. Local</th>
                                                <th>Nombre Institución</th>
                                                <th>Cód. Modular</th>
                                                <th>Nivel/Gestión</th>
                                                <th>Ubicación</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // CONFIGURACIÓN DE PAGINACIÓN
                                            $registros_por_pagina = 10;
                                            $pagina_actual = isset($_GET['page']) ? (int) $_GET['page'] : 1;
                                            if ($pagina_actual < 1)
                                                $pagina_actual = 1;
                                            $offset = ($pagina_actual - 1) * $registros_por_pagina;
                                            $filter = "";
                                            if ($search_ie != '') {
                                                $s = $conn->real_escape_string($search_ie);
                                                $filter = " WHERE nombre_ie LIKE '%$s%' OR codigo_local LIKE '%$s%' OR distrito LIKE '%$s%'";
                                            }

                                            // Contar total para paginación
                                            $total_res = $conn->query("SELECT COUNT(*) as total FROM instituciones $filter");
                                            $total_registros = $total_res->fetch_assoc()['total'];
                                            $total_paginas = ceil($total_registros / $registros_por_pagina);

                                            $ie_res = $conn->query("SELECT * FROM instituciones $filter ORDER BY nombre_ie ASC LIMIT $offset, $registros_por_pagina");
                                            if ($ie_res->num_rows > 0):
                                                while ($i = $ie_res->fetch_assoc()):
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold text-success"><?php echo $i['codigo_local']; ?></td>
                                                        <td>
                                                            <div class="fw-bold"><?php echo $i['nombre_ie']; ?></div><small
                                                                class="text-muted"><?php echo $i['direccion']; ?></small>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-light text-dark border"><?php echo $i['codigo_institucion']; ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="small fw-bold"><?php echo $i['nivel_modalidad']; ?></div>
                                                            <div class="small text-muted"><?php echo $i['tipo_gestion']; ?></div>
                                                        </td>
                                                        <td>
                                                            <div class="small fw-bold text-primary"><?php echo $i['distrito']; ?>
                                                            </div>
                                                            <small class="text-muted"><i class="bi bi-geo-alt"></i>
                                                                <?php echo $i['centro_poblado']; ?></small>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex justify-content-center gap-1">
                                                                <a href="?view=instituciones&edit_ie=<?php echo $i['id']; ?>&page=<?php echo $pagina_actual; ?>#formulario-ie"
                                                                    class="btn btn-sm btn-outline-success" title="Editar">
                                                                    <i class="bi bi-pencil"></i>
                                                                </a>
                                                                <a href="?view=instituciones&delete_ie=<?php echo $i['id']; ?>&page=<?php echo $pagina_actual; ?>"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('¿Eliminar Institución?')"
                                                                    title="Eliminar">
                                                                    <i class="bi bi-trash"></i>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php
                                                endwhile;
                                            else:
                                                echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No se encontraron registros</td></tr>";
                                            endif;
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div
                                class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted text-center">Mostrando registros del <?php echo ($offset + 1); ?>
                                    al <?php echo min($offset + $registros_por_pagina, $total_registros); ?> de un total de
                                    <?php echo $total_registros; ?></small>
                                <?php if ($total_paginas > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($pagina_actual > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=instituciones&page=1&search_ie=<?php echo urlencode($search_ie); ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=instituciones&page=<?php echo $pagina_actual - 1; ?>&search_ie=<?php echo urlencode($search_ie); ?>">&lsaquo;</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $pagina_actual - 2);
                                        $end = min($total_paginas, $pagina_actual + 2);
                                        for ($p = $start; $p <= $end; $p++): ?>
                                            <a class="fluent-page-link <?php echo ($p == $pagina_actual) ? 'active' : ''; ?>"
                                                href="?view=instituciones&page=<?php echo $p; ?>&search_ie=<?php echo urlencode($search_ie); ?>"><?php echo $p; ?></a>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual < $total_paginas): ?>
                                            <a class="fluent-page-link"
                                                href="?view=instituciones&page=<?php echo $pagina_actual + 1; ?>&search_ie=<?php echo urlencode($search_ie); ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=instituciones&page=<?php echo $total_paginas; ?>&search_ie=<?php echo urlencode($search_ie); ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    <?php elseif ($seccion == 'Asignacion_ie'): ?>
                        <div id="formulario-asignaciones" class="card shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5
                                    class="m-0 fw-bold <?php echo $edit_asignacion_data ? 'text-warning' : 'text-success'; ?>">
                                    <i
                                        class="bi bi-person-check-fill me-2"></i><?php echo $edit_asignacion_data ? 'Modificar Asignación' : 'Asignación de Personal a I.E.'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
                                    rel="stylesheet" />
                                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                                <script
                                    src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

                                <form action="administrador.php?view=Asignacion_ie" method="POST">
                                    <input type="hidden" name="id_asignacion"
                                        value="<?php echo $edit_asignacion_data['id'] ?? ''; ?>">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-bold text-muted text-uppercase">Buscador de Personal
                                                (Director / Sub-director)</label>
                                            <select id="responsable_select" class="form-select" style="width: 100%;">
                                                <option value="">Buscar por DNI o Apellidos...</option>
                                                <?php
                                                $res_usuarios = $conn->query("SELECT id, dni, nombres, ape_pat, ape_mat, correo, telefono, rol FROM usuarios WHERE estado = 'activo' AND (rol = 'director' OR rol = 'sub-director') ORDER BY ape_pat ASC");
                                                while ($u = $res_usuarios->fetch_assoc()) {
                                                    $selected_usr = (isset($edit_asignacion_data['id_usuario']) && $edit_asignacion_data['id_usuario'] == $u['id']) ? 'selected' : '';
                                                    $label = "DNI: {$u['dni']} - {$u['ape_pat']} {$u['ape_mat']}, {$u['nombres']}";
                                                    echo "<option value='{$u['id']}' $selected_usr data-id='{$u['id']}' data-dni='{$u['dni']}' data-nombres='{$u['nombres']}' data-pat='{$u['ape_pat']}' data-mat='{$u['ape_mat']}' data-correo='{$u['correo']}' data-tel='{$u['telefono']}' data-rol='{$u['rol']}'>$label</option>";
                                                } ?>
                                            </select>
                                            <input type="hidden" name="id_usuario_val" id="id_usuario_val"
                                                value="<?php echo $edit_asignacion_data['id_usuario'] ?? ''; ?>">
                                        </div>

                                        <div class="col-12 col-md-2"><label class="form-label">DNI</label><input type="text"
                                                id="v_dni" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Nombres</label><input
                                                type="text" id="v_nom" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Ap. Paterno</label><input
                                                type="text" id="v_pat" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Ap. Materno</label><input
                                                type="text" id="v_mat" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Rol</label><input type="text"
                                                id="v_rol" class="form-control bg-light fw-bold text-success text-uppercase"
                                                readonly></div>

                                        <div class="col-12">
                                            <hr class="my-2 opacity-25">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label fw-bold text-muted text-uppercase">Buscador de
                                                Institución Educativa</label>
                                            <select id="institucion_select" class="form-select" style="width: 100%;">
                                                <option value="">Buscar por Nombre o Código...</option>
                                                <?php
                                                $res_ie = $conn->query("SELECT id, nombre_ie, codigo_local, codigo_institucion, nivel_modalidad, distrito FROM instituciones ORDER BY nombre_ie ASC");
                                                while ($ie = $res_ie->fetch_assoc()) {
                                                    $selected_ie = (isset($edit_asignacion_data['id_ie']) && $edit_asignacion_data['id_ie'] == $ie['id']) ? 'selected' : '';
                                                    $label_ie = "{$ie['nombre_ie']} - Cód: {$ie['codigo_local']} ({$ie['nivel_modalidad']})";
                                                    echo "<option value='{$ie['id']}' $selected_ie data-idie='{$ie['id']}' data-nomie='{$ie['nombre_ie']}' data-codloc='{$ie['codigo_local']}' data-codinst='{$ie['codigo_institucion']}' data-nivel='{$ie['nivel_modalidad']}' data-distrito='{$ie['distrito']}'>$label_ie</option>";
                                                } ?>
                                            </select>
                                            <input type="hidden" name="id_ie_val" id="id_ie_val"
                                                value="<?php echo $edit_asignacion_data['id_ie'] ?? ''; ?>">
                                        </div>

                                        <div class="col-12 col-md-4"><label class="form-label">Nombre I.E.</label><input
                                                type="text" id="i_nom" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Cód. Local</label><input
                                                type="text" id="i_loc" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Nivel /
                                                Modalidad</label><input type="text" id="i_niv" class="form-control bg-light"
                                                readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Distrito</label><input
                                                type="text" id="i_dis" class="form-control bg-light" readonly></div>

                                        <div
                                            class="col-12 text-end mt-4 d-flex flex-column flex-sm-row justify-content-end gap-2">
                                            <?php if ($edit_asignacion_data): ?>
                                                <a href="?view=Asignacion_ie"
                                                    class="btn btn-secondary px-4 fw-bold">Cancelar</a>
                                            <?php endif; ?>
                                            <button type="submit" name="btn_guardar_asignacion"
                                                class="btn btn-success px-5 fw-bold">
                                                <?php echo $edit_asignacion_data ? 'Actualizar Cambios' : 'Guardar Asignación'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm mt-4">
                            <div
                                class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                <h5 class="m-0 fw-bold text-dark w-100"><i class="bi bi-table me-2"></i>Registros de
                                    Asignaciones</h5>
                                <?php $search_term = isset($_GET['search']) ? $_GET['search'] : ''; ?>
                                <form action="" method="GET" class="d-flex w-100"
                                    style="max-width: 400px; margin-left: auto;">
                                    <input type="hidden" name="view" value="Asignacion_ie">
                                    <div class="fluent-input-group me-2">
                                        <i class="bi bi-search"></i>
                                        <input type="text" name="search" class="form-control"
                                            placeholder="Buscar por DNI, I.E o Código..."
                                            value="<?php echo htmlspecialchars($search_term); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success px-3 fw-bold">Buscar</button>
                                    <?php if ($search_term != ''): ?>
                                        <a href="?view=Asignacion_ie" class="btn btn-outline-secondary px-3 ms-1 fw-bold"><i
                                                class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="min-width: 800px;">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th class="ps-3">ID</th>
                                                <th>Usuario Referenciado</th>
                                                <th>Institución Referenciada</th>
                                                <th>Fecha Inicio</th>
                                                <th>Fecha Fin</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // CONFIGURACIÓN DE PAGINACIÓN PARA ASIGNACIÓN I.E
                                            $registros_por_pagina_asig = 10;
                                            $pagina_actual_asig = isset($_GET['page_asig']) ? (int) $_GET['page_asig'] : 1;
                                            if ($pagina_actual_asig < 1)
                                                $pagina_actual_asig = 1;
                                            $offset_asig = ($pagina_actual_asig - 1) * $registros_por_pagina_asig;
                                            $search_query = "";
                                            if ($search_term != '') {
                                                $safe_search = $conn->real_escape_string($search_term);
                                                $search_query = " AND (u.dni LIKE '%$safe_search%' OR i.nombre_ie LIKE '%$safe_search%' OR i.codigo_local LIKE '%$safe_search%')";
                                            }

                                            // Contar total para paginación
                                            $total_res_asig = $conn->query("SELECT COUNT(*) as total FROM asignaciones a LEFT JOIN usuarios u ON a.id_usuario = u.id LEFT JOIN instituciones i ON a.id_ie = i.id WHERE 1=1 $search_query");
                                            $total_registros_asig = $total_res_asig->fetch_assoc()['total'];
                                            $total_paginas_asig = ceil($total_registros_asig / $registros_por_pagina_asig);
                                            $sql_asignaciones = "SELECT a.id, a.fecha_inicio, a.fecha_fin, u.dni, u.nombres, u.ape_pat, u.ape_mat, i.nombre_ie, i.codigo_local, i.nivel_modalidad, i.distrito FROM asignaciones a LEFT JOIN usuarios u ON a.id_usuario = u.id LEFT JOIN instituciones i ON a.id_ie = i.id WHERE 1=1 $search_query ORDER BY a.id DESC LIMIT $offset_asig, $registros_por_pagina_asig";
                                            $query_asignaciones = $conn->query($sql_asignaciones);

                                            if ($query_asignaciones->num_rows > 0):
                                                while ($a = $query_asignaciones->fetch_assoc()):
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold"><?php echo $a['id']; ?></td>
                                                        <td><span
                                                                class="badge bg-light text-dark border mb-1"><?php echo $a['dni']; ?></span><br><small
                                                                class="fw-bold"><?php echo "{$a['nombres']} {$a['ape_pat']} {$a['ape_mat']}"; ?></small>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold text-primary mb-1"><?php echo $a['nombre_ie']; ?>
                                                            </div><span
                                                                class="badge bg-secondary bg-opacity-10 text-secondary border px-2">Cód:
                                                                <?php echo $a['codigo_local']; ?></span><small
                                                                class="text-muted d-block mt-1"><?php echo $a['nivel_modalidad']; ?>
                                                                | <?php echo $a['distrito']; ?></small>
                                                        </td>
                                                        <td><?php echo $a['fecha_inicio']; ?></td>
                                                        <td><?php echo $a['fecha_fin'] ? $a['fecha_fin'] : '<span class="status-badge status-active">Vigente</span>'; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex justify-content-center gap-1">
                                                                <a href="?view=Asignacion_ie&edit_asignacion=<?php echo $a['id']; ?>&page_asig=<?php echo $pagina_actual_asig; ?>#formulario-asignaciones"
                                                                    class="btn btn-sm btn-outline-success" title="Editar"><i
                                                                        class="bi bi-pencil"></i></a>
                                                                <a href="?view=Asignacion_ie&delete_asignacion=<?php echo $a['id']; ?>&page_asig=<?php echo $pagina_actual_asig; ?>"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('¿Eliminar registro?')"
                                                                    title="Eliminar"><i class="bi bi-trash"></i></a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php
                                                endwhile;
                                            else:
                                                echo "<tr><td colspan='6' class='text-center py-4 text-muted'>No se encontraron registros</td></tr>";
                                            endif;
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div
                                class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted text-center">Mostrando registros del
                                    <?php echo ($offset_asig + 1); ?> al
                                    <?php echo min($offset_asig + $registros_por_pagina_asig, $total_registros_asig); ?> de
                                    un total de <?php echo $total_registros_asig; ?></small>
                                <?php if ($total_paginas_asig > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($pagina_actual_asig > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=Asignacion_ie&page_asig=1&search=<?php echo urlencode($search_term); ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=Asignacion_ie&page_asig=<?php echo $pagina_actual_asig - 1; ?>&search=<?php echo urlencode($search_term); ?>">&lsaquo;</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $pagina_actual_asig - 2);
                                        $end = min($total_paginas_asig, $pagina_actual_asig + 2);
                                        for ($p = $start; $p <= $end; $p++): ?>
                                            <a class="fluent-page-link <?php echo ($p == $pagina_actual_asig) ? 'active' : ''; ?>"
                                                href="?view=Asignacion_ie&page_asig=<?php echo $p; ?>&search=<?php echo urlencode($search_term); ?>"><?php echo $p; ?></a>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual_asig < $total_paginas_asig): ?>
                                            <a class="fluent-page-link"
                                                href="?view=Asignacion_ie&page_asig=<?php echo $pagina_actual_asig + 1; ?>&search=<?php echo urlencode($search_term); ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=Asignacion_ie&page_asig=<?php echo $total_paginas_asig; ?>&search=<?php echo urlencode($search_term); ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <script>
                            $(document).ready(function () {
                                function updateResponsableView(opt) {
                                    $('#id_usuario_val').val(opt.data('id') || '');
                                    $('#v_dni').val(opt.data('dni') || '');
                                    $('#v_nom').val(opt.data('nombres') || '');
                                    $('#v_pat').val(opt.data('pat') || '');
                                    $('#v_mat').val(opt.data('mat') || '');
                                    $('#v_rol').val(opt.data('rol') || '');
                                }
                                function updateInstitucionView(opt) {
                                    $('#id_ie_val').val(opt.data('idie') || '');
                                    $('#i_nom').val(opt.data('nomie') || '');
                                    $('#i_loc').val(opt.data('codloc') || '');
                                    $('#i_niv').val(opt.data('nivel') || '');
                                    $('#i_dis').val(opt.data('distrito') || '');
                                }
                                $('#responsable_select').select2({ placeholder: "Buscar personal...", allowClear: true }).on('change', function () {
                                    updateResponsableView($(this).find(':selected'));
                                });
                                $('#institucion_select').select2({ placeholder: "Buscar institución...", allowClear: true }).on('change', function () {
                                    updateInstitucionView($(this).find(':selected'));
                                });
                                <?php if ($edit_asignacion_data): ?>
                                    updateResponsableView($('#responsable_select').find(':selected'));
                                    updateInstitucionView($('#institucion_select').find(':selected'));
                                <?php endif; ?>
                            });
                        </script>

                    <?php elseif ($seccion == 'asignacion_especialistas'): ?>
                        <div id="formulario-especialistas" class="card shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5
                                    class="m-0 fw-bold <?php echo $edit_asig_esp_data ? 'text-warning' : 'text-success'; ?>">
                                    <i
                                        class="bi bi-person-badge-fill me-2"></i><?php echo $edit_asig_esp_data ? 'Modificar Asignación Especialista' : 'Asignación de Especialista'; ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css"
                                    rel="stylesheet" />
                                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                                <script
                                    src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

                                <form action="administrador.php?view=asignacion_especialistas" method="POST">
                                    <input type="hidden" name="id_asig_esp"
                                        value="<?php echo $edit_asig_esp_data['id'] ?? ''; ?>">
                                    <div class="row g-3">
                                        <div class="col-12">
                                            <label class="form-label fw-bold text-muted text-uppercase">Buscador de
                                                Especialista</label>
                                            <select id="esp_select" class="form-select" style="width: 100%;">
                                                <option value="">Buscar por DNI o Apellidos...</option>
                                                <?php
                                                $res_usuarios = $conn->query("SELECT id, dni, nombres, ape_pat, ape_mat, correo, telefono, rol FROM usuarios WHERE estado = 'activo' AND rol = 'especialista' ORDER BY ape_pat ASC");
                                                while ($u = $res_usuarios->fetch_assoc()) {
                                                    $selected_usr = (isset($edit_asig_esp_data['id_usuario']) && $edit_asig_esp_data['id_usuario'] == $u['id']) ? 'selected' : '';
                                                    $label = "DNI: {$u['dni']} - {$u['ape_pat']} {$u['ape_mat']}, {$u['nombres']}";
                                                    echo "<option value='{$u['id']}' $selected_usr data-id='{$u['id']}' data-dni='{$u['dni']}' data-nombres='{$u['nombres']}' data-pat='{$u['ape_pat']}' data-mat='{$u['ape_mat']}' data-correo='{$u['correo']}' data-tel='{$u['telefono']}' data-rol='{$u['rol']}'>$label</option>";
                                                } ?>
                                            </select>
                                            <input type="hidden" name="id_especialista_val" id="id_especialista_val"
                                                value="<?php echo $edit_asig_esp_data['id_usuario'] ?? ''; ?>">
                                        </div>

                                        <div class="col-12 col-md-2"><label class="form-label">DNI</label><input type="text"
                                                id="e_dni" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Nombres</label><input
                                                type="text" id="e_nom" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Ap. Paterno</label><input
                                                type="text" id="e_pat" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Ap. Materno</label><input
                                                type="text" id="e_mat" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Rol</label><input type="text"
                                                id="e_rol" class="form-control bg-light fw-bold text-success text-uppercase"
                                                readonly></div>
                                        <div class="col-12 col-md-2">
                                            <label class="form-label text-success fw-bold">Categoría</label>
                                            <select name="sub_tipo" id="e_sub_tipo" class="form-select border-success"
                                                required>
                                                <option value="" disabled <?php echo (!isset($edit_asig_esp_data['sub_tipo']) || empty($edit_asig_esp_data['sub_tipo'])) ? 'selected' : ''; ?>>
                                                    Seleccione...</option>
                                                <option value="AGP" <?php echo (isset($edit_asig_esp_data['sub_tipo']) && $edit_asig_esp_data['sub_tipo'] == 'AGP') ? 'selected' : ''; ?>>AGP
                                                </option>
                                                <option value="Administrativo" <?php echo (isset($edit_asig_esp_data['sub_tipo']) && $edit_asig_esp_data['sub_tipo'] == 'Administrativo') ? 'selected' : ''; ?>>Administrativo</option>
                                                <option value="AGI" <?php echo (isset($edit_asig_esp_data['sub_tipo']) && $edit_asig_esp_data['sub_tipo'] == 'AGI') ? 'selected' : ''; ?>>AGI
                                                </option>
                                            </select>
                                        </div>

                                        <div class="col-12">
                                            <hr class="my-2 opacity-25">
                                        </div>

                                        <div class="col-12">
                                            <label class="form-label fw-bold text-muted text-uppercase">Buscador de
                                                Institución Educativa</label>
                                            <select id="ie_select" class="form-select" style="width: 100%;">
                                                <option value="">Buscar por Nombre o Código...</option>
                                                <?php
                                                $res_ie = $conn->query("SELECT id, nombre_ie, codigo_local, codigo_institucion, nivel_modalidad, distrito FROM instituciones ORDER BY nombre_ie ASC");
                                                while ($ie = $res_ie->fetch_assoc()) {
                                                    $selected_ie = (isset($edit_asig_esp_data['id_institucion']) && $edit_asig_esp_data['id_institucion'] == $ie['id']) ? 'selected' : '';
                                                    $label_ie = "{$ie['nombre_ie']} - Cód: {$ie['codigo_local']} ({$ie['nivel_modalidad']})";
                                                    echo "<option value='{$ie['id']}' $selected_ie data-idie='{$ie['id']}' data-nomie='{$ie['nombre_ie']}' data-codloc='{$ie['codigo_local']}' data-codinst='{$ie['codigo_institucion']}' data-nivel='{$ie['nivel_modalidad']}' data-distrito='{$ie['distrito']}'>$label_ie</option>";
                                                } ?>
                                            </select>
                                            <input type="hidden" name="id_ie_val" id="id_ie_val"
                                                value="<?php echo $edit_asig_esp_data['id_institucion'] ?? ''; ?>">
                                        </div>

                                        <div class="col-12 col-md-4"><label class="form-label">Nombre I.E.</label><input
                                                type="text" id="ie_nom" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-2"><label class="form-label">Cód. Local</label><input
                                                type="text" id="ie_loc" class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Nivel /
                                                Modalidad</label><input type="text" id="ie_niv"
                                                class="form-control bg-light" readonly></div>
                                        <div class="col-12 col-md-3"><label class="form-label">Distrito</label><input
                                                type="text" id="ie_dis" class="form-control bg-light" readonly></div>

                                        <div
                                            class="col-12 text-end mt-4 d-flex flex-column flex-sm-row justify-content-end gap-2">
                                            <?php if ($edit_asig_esp_data): ?>
                                                <a href="?view=asignacion_especialistas"
                                                    class="btn btn-secondary px-4 fw-bold">Cancelar</a>
                                            <?php endif; ?>
                                            <button type="submit" name="btn_guardar_asig_esp"
                                                class="btn btn-success px-5 fw-bold">
                                                <?php echo $edit_asig_esp_data ? 'Actualizar Cambios' : 'Guardar Asignación'; ?>
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm mt-4">
                            <div
                                class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                <h5 class="m-0 fw-bold text-dark w-100"><i class="bi bi-table me-2"></i>Registros de
                                    Asignaciones (Especialistas)</h5>
                                <?php $search_esp = isset($_GET['search_esp']) ? $_GET['search_esp'] : ''; ?>
                                <form action="" method="GET" class="d-flex w-100"
                                    style="max-width: 400px; margin-left: auto;">
                                    <input type="hidden" name="view" value="asignacion_especialistas">
                                    <div class="fluent-input-group me-2">
                                        <i class="bi bi-search"></i>
                                        <input type="text" name="search_esp" class="form-control"
                                            placeholder="Buscar por DNI, I.E o Código..."
                                            value="<?php echo htmlspecialchars($search_esp); ?>">
                                    </div>
                                    <button type="submit" class="btn btn-success px-3 fw-bold">Buscar</button>
                                    <?php if ($search_esp != ''): ?>
                                        <a href="?view=asignacion_especialistas"
                                            class="btn btn-outline-secondary px-3 ms-1 fw-bold"><i class="bi bi-x-lg"></i></a>
                                    <?php endif; ?>
                                </form>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="min-width: 800px;">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th class="ps-3">ID</th>
                                                <th>Especialista Referenciado</th>
                                                <th>Categoría</th>
                                                <th>Institución Referenciada</th>
                                                <th>Fecha Inicio</th>
                                                <th>Fecha Fin</th>
                                                <th class="text-center">Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // LÓGICA DE PAGINACIÓN PARA ESPECIALISTAS
                                            $registros_por_pagina_esp = 10;
                                            $pagina_actual_esp = isset($_GET['page_esp']) ? (int) $_GET['page_esp'] : 1;
                                            if ($pagina_actual_esp < 1)
                                                $pagina_actual_esp = 1;
                                            $offset_esp = ($pagina_actual_esp - 1) * $registros_por_pagina_esp;
                                            $search_query_esp = "";
                                            if ($search_esp != '') {
                                                $safe_search_esp = $conn->real_escape_string($search_esp);
                                                $search_query_esp = " AND (u.dni LIKE '%$safe_search_esp%' OR i.nombre_ie LIKE '%$safe_search_esp%' OR i.codigo_local LIKE '%$safe_search_esp%')";
                                            }

                                            // Contar total para paginación
                                            $total_res_esp = $conn->query("SELECT COUNT(*) as total FROM asignaciones_especialistas a LEFT JOIN usuarios u ON a.id_usuario = u.id LEFT JOIN instituciones i ON a.id_institucion = i.id WHERE 1=1 $search_query_esp");
                                            $total_registros_esp = $total_res_esp->fetch_assoc()['total'];
                                            $total_paginas_esp = ceil($total_registros_esp / $registros_por_pagina_esp);

                                            // OBTENEMOS EL SUB_TIPO Y LOS DEMÁS DATOS
                                            $sql_asig_esp = "SELECT a.id, a.sub_tipo, a.fecha_inicio, a.fecha_fin, u.dni, u.nombres, u.ape_pat, u.ape_mat, i.nombre_ie, i.codigo_local, i.nivel_modalidad, i.distrito FROM asignaciones_especialistas a LEFT JOIN usuarios u ON a.id_usuario = u.id LEFT JOIN instituciones i ON a.id_institucion = i.id WHERE 1=1 $search_query_esp ORDER BY a.id DESC LIMIT $offset_esp, $registros_por_pagina_esp";
                                            $query_asig_esp = $conn->query($sql_asig_esp);

                                            if ($query_asig_esp->num_rows > 0):
                                                while ($a = $query_asig_esp->fetch_assoc()):
                                                    ?>
                                                    <tr>
                                                        <td class="ps-3 fw-bold"><?php echo $a['id']; ?></td>
                                                        <td><span
                                                                class="badge bg-light text-dark border mb-1"><?php echo $a['dni']; ?></span><br><small
                                                                class="fw-bold"><?php echo "{$a['nombres']} {$a['ape_pat']} {$a['ape_mat']}"; ?></small>
                                                        </td>
                                                        <td><span
                                                                class="badge bg-info bg-opacity-10 text-info border px-2 text-uppercase"><?php echo htmlspecialchars($a['sub_tipo'] ?? 'No Asignado'); ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="fw-bold text-primary mb-1"><?php echo $a['nombre_ie']; ?>
                                                            </div><span
                                                                class="badge bg-secondary bg-opacity-10 text-secondary border px-2">Cód:
                                                                <?php echo $a['codigo_local']; ?></span><small
                                                                class="text-muted d-block mt-1"><?php echo $a['nivel_modalidad']; ?>
                                                                | <?php echo $a['distrito']; ?></small>
                                                        </td>
                                                        <td><?php echo $a['fecha_inicio']; ?></td>
                                                        <td><?php echo $a['fecha_fin'] ? $a['fecha_fin'] : '<span class="status-badge status-active">Vigente</span>'; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="d-flex justify-content-center gap-1">
                                                                <a href="?view=asignacion_especialistas&edit_asig_esp=<?php echo $a['id']; ?>&page_esp=<?php echo $pagina_actual_esp; ?>#formulario-especialistas"
                                                                    class="btn btn-sm btn-outline-success" title="Editar"><i
                                                                        class="bi bi-pencil"></i></a>
                                                                <a href="?view=asignacion_especialistas&delete_asig_esp=<?php echo $a['id']; ?>&page_esp=<?php echo $pagina_actual_esp; ?>"
                                                                    class="btn btn-sm btn-outline-danger"
                                                                    onclick="return confirm('¿Eliminar registro?')"
                                                                    title="Eliminar"><i class="bi bi-trash"></i></a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php
                                                endwhile;
                                            else:
                                                echo "<tr><td colspan='7' class='text-center py-4 text-muted'>No se encontraron registros</td></tr>";
                                            endif;
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div
                                class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted text-center">Mostrando registros del
                                    <?php echo ($offset_esp + 1); ?> al
                                    <?php echo min($offset_esp + $registros_por_pagina_esp, $total_registros_esp); ?> de un
                                    total de <?php echo $total_registros_esp; ?></small>
                                <?php if ($total_paginas_esp > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($pagina_actual_esp > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=asignacion_especialistas&page_esp=1&search_esp=<?php echo urlencode($search_esp); ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=asignacion_especialistas&page_esp=<?php echo $pagina_actual_esp - 1; ?>&search_esp=<?php echo urlencode($search_esp); ?>">&lsaquo;</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $pagina_actual_esp - 2);
                                        $end = min($total_paginas_esp, $pagina_actual_esp + 2);
                                        for ($p = $start; $p <= $end; $p++): ?>
                                            <a class="fluent-page-link <?php echo ($p == $pagina_actual_esp) ? 'active' : ''; ?>"
                                                href="?view=asignacion_especialistas&page_esp=<?php echo $p; ?>&search_esp=<?php echo urlencode($search_esp); ?>"><?php echo $p; ?></a>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual_esp < $total_paginas_esp): ?>
                                            <a class="fluent-page-link"
                                                href="?view=asignacion_especialistas&page_esp=<?php echo $pagina_actual_esp + 1; ?>&search_esp=<?php echo urlencode($search_esp); ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=asignacion_especialistas&page_esp=<?php echo $total_paginas_esp; ?>&search_esp=<?php echo urlencode($search_esp); ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <script>
                            $(document).ready(function () {
                                function updateEspView(opt) {
                                    $('#id_especialista_val').val(opt.data('id') || '');
                                    $('#e_dni').val(opt.data('dni') || '');
                                    $('#e_nom').val(opt.data('nombres') || '');
                                    $('#e_pat').val(opt.data('pat') || '');
                                    $('#e_mat').val(opt.data('mat') || '');
                                    $('#e_rol').val(opt.data('rol') || '');
                                }
                                function updateIeView(opt) {
                                    $('#id_ie_val').val(opt.data('idie') || '');
                                    $('#ie_nom').val(opt.data('nomie') || '');
                                    $('#ie_loc').val(opt.data('codloc') || '');
                                    $('#ie_niv').val(opt.data('nivel') || '');
                                    $('#ie_dis').val(opt.data('distrito') || '');
                                }
                                $('#esp_select').select2({ placeholder: "Buscar especialista...", allowClear: true }).on('change', function () {
                                    updateEspView($(this).find(':selected'));
                                });
                                $('#ie_select').select2({ placeholder: "Buscar institución...", allowClear: true }).on('change', function () {
                                    updateIeView($(this).find(':selected'));
                                });
                                <?php if ($edit_asig_esp_data): ?>
                                    updateEspView($('#esp_select').find(':selected'));
                                    updateIeView($('#ie_select').find(':selected'));
                                <?php endif; ?>
                            });
                        </script>

                    <?php elseif ($seccion == 'revision_documentaria'): ?>
                        <?php
                        // Lógica copiada y adaptada desde ver_envios.php 
                        // Se remueve la limitante de asignación por ID de usuario.
                    
                        // Construir la consulta dinámica para los filtros de búsqueda
                        $where_clauses = ["1=1"];

                        if (!empty($_GET['nombre_ie'])) {
                            $nombre_ie = $conn->real_escape_string($_GET['nombre_ie']);
                            $where_clauses[] = "i.nombre_ie LIKE '%$nombre_ie%'";
                        }
                        if (!empty($_GET['codigo_local'])) {
                            $codigo_local = $conn->real_escape_string($_GET['codigo_local']);
                            $where_clauses[] = "i.codigo_local = '$codigo_local'";
                        }
                        if (!empty($_GET['mes'])) {
                            $mes = $conn->real_escape_string($_GET['mes']);
                            $where_clauses[] = "e.mes = '$mes'";
                        }
                        if (!empty($_GET['tipo'])) {
                            $tipo = $conn->real_escape_string($_GET['tipo']);
                            $where_clauses[] = "e.tipo_documento = '$tipo'";
                        }

                        $where_sql = implode(" AND ", $where_clauses);

                        $registros_por_pagina = 10;
                        $pagina_actual = isset($_GET['page']) ? (int) $_GET['page'] : 1;
                        if ($pagina_actual < 1)
                            $pagina_actual = 1;
                        $offset = ($pagina_actual - 1) * $registros_por_pagina;

                        // Contar total para paginación
                        $sql_count = "SELECT COUNT(*) as total FROM envios e 
                                INNER JOIN (
                                    SELECT MAX(id) as max_id 
                                    FROM envios 
                                    GROUP BY id_ie, anio, tipo_documento, IFNULL(mes, 0)
                                ) as ultimos ON e.id = ultimos.max_id
                                LEFT JOIN instituciones i ON e.id_ie = i.id 
                                WHERE $where_sql";

                        $res_count = $conn->query($sql_count);
                        $total_registros = $res_count->fetch_assoc()['total'];
                        $total_paginas = ceil($total_registros / $registros_por_pagina);

                        $sql = "SELECT e.*, i.codigo_local, i.nombre_ie 
                            FROM envios e 
                            INNER JOIN (
                                SELECT MAX(id) as max_id 
                                FROM envios 
                                GROUP BY id_ie, anio, tipo_documento, IFNULL(mes, 0)
                            ) as ultimos ON e.id = ultimos.max_id
                            LEFT JOIN instituciones i ON e.id_ie = i.id 
                            WHERE $where_sql
                            ORDER BY e.fecha_envio DESC
                            LIMIT $offset, $registros_por_pagina";

                        $resultado = $conn->query($sql);
                        ?>

                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h5 class="m-0 fw-bold text-success"><i class="bi bi-funnel me-2"></i>Búsqueda Avanzada de
                                    Documentos</h5>
                            </div>
                            <div class="card-body">
                                <form method="GET" action="administrador.php" class="row g-3 align-items-end">
                                    <input type="hidden" name="view" value="revision_documentaria">
                                    <div class="col-12 col-md-3">
                                        <label class="form-label text-success fw-bold small">Institución Educativa
                                            (IE)</label>
                                        <input type="text" class="form-control border-success border-opacity-50"
                                            name="nombre_ie" placeholder="Nombre de la IE"
                                            value="<?= isset($_GET['nombre_ie']) ? htmlspecialchars($_GET['nombre_ie']) : '' ?>">
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label text-success fw-bold small">Cód. Modular</label>
                                        <input type="text" class="form-control border-success border-opacity-50"
                                            name="codigo_local" placeholder="Ej: 123456"
                                            value="<?= isset($_GET['codigo_local']) ? htmlspecialchars($_GET['codigo_local']) : '' ?>">
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label text-success fw-bold small">Filtrar por Mes</label>
                                        <select class="form-select border-success border-opacity-50" name="mes">
                                            <option value="">Todos</option>
                                            <?php
                                            $meses_arr = [1 => "Enero", 2 => "Febrero", 3 => "Marzo", 4 => "Abril", 5 => "Mayo", 6 => "Junio", 7 => "Julio", 8 => "Agosto", 9 => "Septiembre", 10 => "Octubre", 11 => "Noviembre", 12 => "Diciembre"];
                                            foreach ($meses_arr as $num => $nom) {
                                                $selected = (isset($_GET['mes']) && $_GET['mes'] == $num) ? 'selected' : '';
                                                echo "<option value='$num' $selected>$nom</option>";
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label text-success fw-bold small">Tipo de Documento</label>
                                        <select class="form-select border-success border-opacity-50" name="tipo">
                                            <option value="">Todos los tipos</option>
                                            <option value="Mensual" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Mensual') ? 'selected' : '' ?>>Asistencia Mensual</option>
                                            <option value="RI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'RI') ? 'selected' : '' ?>>Reglamento Interno (RI)</option>
                                            <option value="PAT" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'PAT') ? 'selected' : '' ?>>Plan Anual de Trabajo (PAT)</option>
                                            <option value="PSI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'PSI') ? 'selected' : '' ?>>Proyecto Curricular Institucional (PSI)</option>
                                            <option value="PI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'PI') ? 'selected' : '' ?>>Plan de Innovación (PI)</option>
                                            <option value="RD_CONEI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'RD_CONEI') ? 'selected' : '' ?>>R.D de CONEI</option>
                                            <option value="DocGestion" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'DocGestion') ? 'selected' : '' ?>>Documentos de Gestión
                                            </option>
                                            <option value="Innovacion" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Innovacion') ? 'selected' : '' ?>>Proyectos de Innovación
                                            </option>
                                            <option value="EPLA" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'EPLA') ? 'selected' : '' ?>>Resultados EPLA</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-2 d-flex flex-wrap flex-md-nowrap gap-2 mt-3 mt-md-0">
                                        <button type="submit" class="btn btn-success w-100 fw-bold shadow-sm flex-grow-1"><i
                                                class="bi bi-search me-1"></i> Buscar</button>
                                        <a href="?view=revision_documentaria"
                                            class="btn btn-outline-secondary shadow-sm flex-shrink-0"
                                            title="Limpiar filtros"><i class="bi bi-arrow-clockwise"></i></a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div
                                class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2">
                                <h5 class="m-0 fw-bold text-dark w-100"><i class="bi bi-table me-2"></i>Listado de
                                    Documentos Enviados</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0" style="min-width: 800px;">
                                        <thead class="bg-light text-secondary">
                                            <tr>
                                                <th>Cód. Modular</th>
                                                <th>Institución Educativa (IE)</th>
                                                <th>Tipo Documento</th>
                                                <th>Periodo</th>
                                                <th>Fecha de Envío</th>
                                                <th class="text-center">Estado / Revisión</th>
                                                <th class="text-center" style="width: 200px;">Archivos</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            if ($resultado && $resultado->num_rows > 0) {
                                                while ($row = $resultado->fetch_assoc()) {

                                                    // 1. Formatear el periodo
                                                    if ($row['tipo_documento'] === 'Mensual') {
                                                        $meses = [1 => "Enero", 2 => "Febrero", 3 => "Marzo", 4 => "Abril", 5 => "Mayo", 6 => "Junio", 7 => "Julio", 8 => "Agosto", 9 => "Septiembre", 10 => "Octubre", 11 => "Noviembre", 12 => "Diciembre"];
                                                        $nombre_mes = $meses[$row['mes']] ?? "Desconocido";
                                                        $periodo = $nombre_mes . " - " . $row['anio'];
                                                    } else {
                                                        $periodo = "Año " . $row['anio'];
                                                    }

                                                    // 2. Configurar colores del estado
                                                    $estado = $row['estado'] ?? 'Pendiente';
                                                    if ($estado === 'Conforme') {
                                                        $color_badge = 'bg-success';
                                                    } elseif ($estado === 'Observado') {
                                                        $color_badge = 'bg-warning text-dark';
                                                    } elseif ($estado === 'Rechazado') {
                                                        $color_badge = 'bg-danger';
                                                    } else {
                                                        $color_badge = 'bg-secondary';
                                                    }

                                                    // 3. Etiqueta tipo documento
                                                    $tipo_doc = $row['tipo_documento'];
                                                    $color_tipo = ($tipo_doc == 'Mensual') ? 'bg-info text-dark' : 'bg-success text-white';

                                                    // 4. Lógica de botones de descarga (Administrador sin límites)
                                                    $btn_pdf = "<a href='{$row['ruta_archivo']}' class='btn btn-outline-danger btn-sm fw-bold' target='_blank' title='Ver PDF o Archivo'><i class='bi bi-file-earmark-pdf-fill fs-6'></i></a>";

                                                    $btn_excel = "";
                                                    if (!empty($row['ruta_excel'])) {
                                                        if (strpos($row['ruta_excel'], ',') !== false) {
                                                            $btn_excel = "<button class='btn btn-outline-success btn-sm fw-bold' onclick=\"alert('Contiene múltiples archivos EPLA. Visualizar directamente en el servidor.');\" title='Múltiples Excel'><i class='bi bi-file-earmark-excel-fill fs-6'></i></button>";
                                                        } else {
                                                            $btn_excel = "<a href='{$row['ruta_excel']}' class='btn btn-outline-success btn-sm fw-bold' download title='Descargar Excel'><i class='bi bi-file-earmark-excel-fill fs-6'></i></a>";
                                                        }
                                                    } elseif ($tipo_doc === 'Mensual') {
                                                        $btn_excel = "<button class='btn btn-outline-secondary btn-sm opacity-50' disabled title='Sin Excel'><i class='bi bi-file-earmark-excel-fill fs-6'></i></button>";
                                                    }

                                                    $btn_constancia = "<a href='ver_constancia.php?id={$row['id']}' class='btn btn-outline-dark btn-sm fw-bold' target='_blank' title='Ver Constancia'><i class='bi bi-receipt fs-6'></i></a>";

                                                    echo "<tr>";
                                                    echo "<td class='fw-bold text-success text-nowrap'>{$row['codigo_local']}</td>";
                                                    echo "<td><strong class='text-dark d-block text-wrap'>{$row['nombre_ie']}</strong></td>";
                                                    echo "<td><span class='badge {$color_tipo} px-2 py-1 shadow-sm text-wrap'>{$tipo_doc}</span></td>";
                                                    echo "<td><small class='fw-bold text-muted text-nowrap'>{$periodo}</small></td>";
                                                    echo "<td class='text-nowrap'><small class='text-muted'><i class='bi bi-clock me-1'></i>" . date("d/m/Y h:i A", strtotime($row['fecha_envio'])) . "</small></td>";

                                                    $obs_js = htmlspecialchars($row['observacion'] ?? '', ENT_QUOTES);
                                                    echo "<td class='text-center bg-light'>
                                                        <span class='badge {$color_badge} mb-2 fs-6 shadow-sm w-75'>{$estado}</span><br>
                                                        <button type='button' class='btn btn-success btn-sm fw-bold shadow-sm w-100' onclick=\"abrirModal({$row['id']}, '{$estado}', '{$obs_js}')\">
                                                            <i class='bi bi-pencil-square me-1'></i> Evaluar
                                                        </button>
                                                      </td>";

                                                    echo "<td class='text-center'>
                                                        <div class='d-flex flex-wrap justify-content-center gap-2'>
                                                            {$btn_pdf}
                                                            {$btn_excel}
                                                            {$btn_constancia}
                                                        </div>
                                                      </td>";
                                                    echo "</tr>";
                                                }
                                            } else {
                                                echo "<tr><td colspan='7' class='text-center py-5 text-muted'><i class='bi bi-folder-x fs-1 d-block mb-2'></i>No se encontraron envíos que coincidan con la búsqueda.</td></tr>";
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div
                                class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted text-center">Mostrando registros del <?php echo ($offset + 1); ?>
                                    al <?php echo min($offset + $registros_por_pagina, $total_registros); ?> de un total de
                                    <?php echo $total_registros; ?></small>
                                <?php if ($total_paginas > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($pagina_actual > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=revision_documentaria&page=1&nombre_ie=<?= urlencode($_GET['nombre_ie'] ?? '') ?>&codigo_local=<?= urlencode($_GET['codigo_local'] ?? '') ?>&mes=<?= urlencode($_GET['mes'] ?? '') ?>&tipo=<?= urlencode($_GET['tipo'] ?? '') ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=revision_documentaria&page=<?php echo $pagina_actual - 1; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie'] ?? '') ?>&codigo_local=<?= urlencode($_GET['codigo_local'] ?? '') ?>&mes=<?= urlencode($_GET['mes'] ?? '') ?>&tipo=<?= urlencode($_GET['tipo'] ?? '') ?>">&lsaquo;</a>
                                        <?php endif; ?>

                                        <?php
                                        $start = max(1, $pagina_actual - 2);
                                        $end = min($total_paginas, $pagina_actual + 2);
                                        for ($p = $start; $p <= $end; $p++): ?>
                                            <a class="fluent-page-link <?php echo ($p == $pagina_actual) ? 'active' : ''; ?>"
                                                href="?view=revision_documentaria&page=<?php echo $p; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie'] ?? '') ?>&codigo_local=<?= urlencode($_GET['codigo_local'] ?? '') ?>&mes=<?= urlencode($_GET['mes'] ?? '') ?>&tipo=<?= urlencode($_GET['tipo'] ?? '') ?>"><?php echo $p; ?></a>
                                        <?php endfor; ?>

                                        <?php if ($pagina_actual < $total_paginas): ?>
                                            <a class="fluent-page-link"
                                                href="?view=revision_documentaria&page=<?php echo $pagina_actual + 1; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie'] ?? '') ?>&codigo_local=<?= urlencode($_GET['codigo_local'] ?? '') ?>&mes=<?= urlencode($_GET['mes'] ?? '') ?>&tipo=<?= urlencode($_GET['tipo'] ?? '') ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=revision_documentaria&page=<?php echo $total_paginas; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie'] ?? '') ?>&codigo_local=<?= urlencode($_GET['codigo_local'] ?? '') ?>&mes=<?= urlencode($_GET['mes'] ?? '') ?>&tipo=<?= urlencode($_GET['tipo'] ?? '') ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="modal fade" id="modalEvaluacion" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content border-0 shadow">
                                    <form action="administrador.php?view=revision_documentaria" method="POST">
                                        <div class="modal-header bg-success text-white border-0">
                                            <h5 class="modal-title fw-bold"><i
                                                    class="bi bi-clipboard-check me-2"></i>Evaluar Documento</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                                                aria-label="Close"></button>
                                        </div>
                                        <div class="modal-body p-4">
                                            <input type="hidden" name="id_envio" id="modal_id_envio">

                                            <div class="mb-3">
                                                <label class="form-label text-success fw-bold">Estado de la Revisión</label>
                                                <select class="form-select border-success shadow-sm" name="estado"
                                                    id="modal_estado" required onchange="toggleObservacion()">
                                                    <option value="Pendiente">Pendiente</option>
                                                    <option value="Conforme">🟢 Conforme</option>
                                                    <option value="Observado">🟡 Observado</option>
                                                    <option value="Rechazado">🔴 Rechazado</option>
                                                </select>
                                            </div>

                                            <div class="mb-3" id="div_observacion" style="display: none;">
                                                <label class="form-label text-danger fw-bold">Mensaje / Observación para el
                                                    Director</label>
                                                <textarea class="form-control border-danger shadow-sm" name="observacion"
                                                    id="modal_observacion" rows="4"
                                                    placeholder="Indique claramente qué debe corregir la IE..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-light border-0">
                                            <button type="button" class="btn btn-secondary fw-bold"
                                                data-bs-dismiss="modal">Cancelar</button>
                                            <button type="submit" name="btn_guardar_evaluacion"
                                                class="btn btn-success fw-bold px-4">Guardar Evaluación</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>

                        <script>
                            function abrirModal(id, estado, observacion) {
                                document.getElementById('modal_id_envio').value = id;
                                document.getElementById('modal_estado').value = estado;
                                document.getElementById('modal_observacion').value = observacion;
                                toggleObservacion();

                                var myModal = new bootstrap.Modal(document.getElementById('modalEvaluacion'));
                                myModal.show();
                            }

                            function toggleObservacion() {
                                var estado = document.getElementById('modal_estado').value;
                                var divObs = document.getElementById('div_observacion');
                                var txtObs = document.getElementById('modal_observacion');

                                if (estado === 'Observado' || estado === 'Rechazado') {
                                    divObs.style.display = 'block';
                                    txtObs.setAttribute('required', 'required');
                                } else {
                                    divObs.style.display = 'none';
                                    txtObs.value = '';
                                    txtObs.removeAttribute('required');
                                }
                            }
                        </script>
                    <?php elseif ($seccion == 'fichas_monitoreo'): ?>
                        <?php
                        // ── Filtros de búsqueda ──
                        $fm_q = trim($_GET['fm_q'] ?? '');
                        $fm_tipo = (int) ($_GET['fm_tipo'] ?? 0);
                        $fm_estado = trim($_GET['fm_estado'] ?? '');

                        // ── Tipos de ficha disponibles ──
                        $fm_tipos = [];
                        $rs_tipos = $conn->query("SELECT id, nombre, anio FROM fm_tipos_ficha WHERE estado='ACTIVO' ORDER BY anio DESC, nombre ASC");
                        while ($r = $rs_tipos->fetch_assoc())
                            $fm_tipos[] = $r;

                        // ── Paginación ──
                        $fm_rpp = 15;
                        $fm_page = max(1, (int) ($_GET['fm_page'] ?? 1));
                        $fm_offset = ($fm_page - 1) * $fm_rpp;

                        // ── Construir WHERE dinámico ──
                        $fm_where = ["r.activo = 1"];
                        $fm_params = [];
                        $fm_types = '';

                        if ($fm_q !== '') {
                            $like = '%' . $conn->real_escape_string($fm_q) . '%';
                            $fm_where[] = "(i.nombre_ie LIKE ? OR i.codigo_local LIKE ? OR IFNULL(i.codigo_institucion,'') LIKE ? OR u.dni LIKE ? OR CONCAT(u.nombres,' ',u.ape_pat,' ',IFNULL(u.ape_mat,'')) LIKE ? OR tf.nombre LIKE ? OR r.codigo_envio LIKE ?)";
                            for ($fi = 0; $fi < 7; $fi++) {
                                $fm_params[] = $like;
                                $fm_types .= 's';
                            }
                        }
                        if ($fm_tipo > 0) {
                            $fm_where[] = "r.id_tipo_ficha = ?";
                            $fm_params[] = $fm_tipo;
                            $fm_types .= 'i';
                        }
                        $fm_estados_validos = ['ENVIADO', 'CONFORME', 'EN_REVISION', 'OBSERVADO', 'RECHAZADO', 'REVISADO'];
                        if ($fm_estado !== '' && in_array($fm_estado, $fm_estados_validos, true)) {
                            $fm_where[] = "r.estado = ?";
                            $fm_params[] = $fm_estado;
                            $fm_types .= 's';
                        }

                        $fm_where_sql = implode(' AND ', $fm_where);

                        // ── Contar total ──
                        $fm_count_sql = "SELECT COUNT(*) AS total
                        FROM fm_registros r
                        INNER JOIN fm_tipos_ficha tf ON tf.id = r.id_tipo_ficha
                        INNER JOIN instituciones i ON i.id = r.id_institucion
                        INNER JOIN usuarios u ON u.id = r.id_usuario
                        WHERE $fm_where_sql";
                        $fm_st_count = $conn->prepare($fm_count_sql);
                        if ($fm_params) {
                            $fm_st_count->bind_param($fm_types, ...$fm_params);
                        }
                        $fm_st_count->execute();
                        $fm_total = (int) $fm_st_count->get_result()->fetch_assoc()['total'];
                        $fm_st_count->close();
                        $fm_total_pages = max(1, (int) ceil($fm_total / $fm_rpp));

                        // ── Query principal ──
                        $fm_sql = "SELECT r.id, r.codigo_envio, r.estado, r.fecha_envio, r.porcentaje, r.escala_valorativa, r.observacion_general,
                                      tf.nombre AS tipo_ficha, tf.anio,
                                      i.nombre_ie, i.codigo_local, IFNULL(i.codigo_institucion,'') AS codigo_institucion, i.distrito,
                                      u.nombres, u.ape_pat, u.ape_mat, u.dni,
                                      docf.ruta_archivo  AS ruta_pdf,
                                      docc.ruta_archivo  AS ruta_constancia,
                                      doce.ruta_archivo  AS ruta_pdf_evaluada,
                                      docce.ruta_archivo AS ruta_constancia_eval,
                                      esp.nombres AS esp_nombres, esp.ape_pat AS esp_ape_pat
                               FROM fm_registros r
                               INNER JOIN fm_tipos_ficha tf  ON tf.id  = r.id_tipo_ficha
                               INNER JOIN instituciones i    ON i.id   = r.id_institucion
                               INNER JOIN usuarios u         ON u.id   = r.id_usuario
                               LEFT JOIN asignaciones_especialistas ae ON ae.id_institucion = r.id_institucion AND ae.estado='activo'
                               LEFT JOIN usuarios esp ON esp.id = ae.id_usuario
                               LEFT JOIN fm_documentos docf  ON docf.id_registro  = r.id AND docf.tipo_documento  = 'PDF_FICHA_DIRECTOR'
                               LEFT JOIN fm_documentos docc  ON docc.id_registro  = r.id AND docc.tipo_documento  = 'CONSTANCIA_ENVIO_DIRECTOR'
                               LEFT JOIN fm_documentos doce  ON doce.id_registro  = r.id AND doce.tipo_documento  = 'PDF_FICHA_EVALUADA'
                               LEFT JOIN fm_documentos docce ON docce.id_registro = r.id AND docce.tipo_documento = 'CONSTANCIA_EVALUACION'
                               WHERE $fm_where_sql
                               GROUP BY r.id
                               ORDER BY r.fecha_envio DESC, r.id DESC
                               LIMIT ?, ?";
                        $fm_params_paginated = array_merge($fm_params, [$fm_offset, $fm_rpp]);
                        $fm_types_paginated = $fm_types . 'ii';
                        $fm_st = $conn->prepare($fm_sql);
                        if ($fm_params_paginated) {
                            $fm_st->bind_param($fm_types_paginated, ...$fm_params_paginated);
                        }
                        $fm_st->execute();
                        $fm_result = $fm_st->get_result();

                        function fm_admin_badge(string $estado): string
                        {
                            return match ($estado) {
                                'ENVIADO' => 'ugel-badge is-blue',
                                'EN_REVISION' => 'ugel-badge is-orange',
                                'OBSERVADO' => 'ugel-badge is-yellow',
                                'RECHAZADO' => 'ugel-badge is-red',
                                'CONFORME' => 'ugel-badge is-green',
                                'REVISADO' => 'ugel-badge is-green',
                                default => 'ugel-badge',
                            };
                        }
                        ?>

                        <!-- Encabezado -->
                        <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
                            <div>
                                <h5 class="fw-bold mb-1 text-dark"><i
                                        class="bi bi-clipboard2-pulse me-2 text-success"></i>Fichas de Monitoreo</h5>
                                <p class="text-muted mb-0" style="font-size:0.88rem;">Vista completa de todas las fichas de
                                    monitoreo del sistema (todas las I.E.)</p>
                            </div>
                            <span class="badge-soft px-3 py-2" style="font-size:0.85rem;"><?php echo $fm_total; ?>
                                ficha<?php echo $fm_total !== 1 ? 's' : ''; ?>
                                encontrada<?php echo $fm_total !== 1 ? 's' : ''; ?></span>
                        </div>

                        <!-- Filtros -->
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-white py-3">
                                <h6 class="m-0 fw-bold text-dark"><i class="bi bi-funnel me-2 text-success"></i>Filtros de
                                    búsqueda</h6>
                            </div>
                            <div class="card-body">
                                <form method="GET" action="administrador.php" class="row g-3 align-items-end">
                                    <input type="hidden" name="view" value="fichas_monitoreo">
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">Búsqueda libre</label>
                                        <div class="fluent-input-group">
                                            <i class="bi bi-search"></i>
                                            <input type="text" name="fm_q" class="form-control"
                                                placeholder="I.E., DNI, código, nombre director..."
                                                value="<?php echo htmlspecialchars($fm_q); ?>">
                                        </div>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Tipo de ficha</label>
                                        <select name="fm_tipo" class="form-select">
                                            <option value="0">Todas las fichas</option>
                                            <?php foreach ($fm_tipos as $ft): ?>
                                                <option value="<?php echo (int) $ft['id']; ?>" <?php echo $fm_tipo === (int) $ft['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($ft['nombre']); ?> -
                                                    <?php echo htmlspecialchars($ft['anio']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-2">
                                        <label class="form-label">Estado</label>
                                        <select name="fm_estado" class="form-select">
                                            <option value="">Todos</option>
                                            <option value="ENVIADO" <?php echo $fm_estado === 'ENVIADO' ? 'selected' : ''; ?>>
                                                Enviado</option>
                                            <option value="CONFORME" <?php echo $fm_estado === 'CONFORME' ? 'selected' : ''; ?>>Conforme</option>
                                            <option value="EN_REVISION" <?php echo $fm_estado === 'EN_REVISION' ? 'selected' : ''; ?>>En Revisión</option>
                                            <option value="OBSERVADO" <?php echo $fm_estado === 'OBSERVADO' ? 'selected' : ''; ?>>Observado</option>
                                            <option value="RECHAZADO" <?php echo $fm_estado === 'RECHAZADO' ? 'selected' : ''; ?>>Rechazado</option>
                                            <option value="REVISADO" <?php echo $fm_estado === 'REVISADO' ? 'selected' : ''; ?>>Revisado / Finalizado</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3 d-flex gap-2 align-items-end">
                                        <button type="submit" class="btn btn-success fw-bold flex-grow-1">
                                            <i class="bi bi-search me-1"></i> Buscar
                                        </button>
                                        <a href="?view=fichas_monitoreo" class="btn btn-outline-secondary"
                                            title="Limpiar filtros">
                                            <i class="bi bi-x-lg"></i>
                                        </a>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <!-- Tabla de resultados -->
                        <div class="card shadow-sm">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover align-middle mb-0 ugel-table" style="min-width: 900px;">
                                        <thead>
                                            <tr>
                                                <th class="ps-3">Institución Educativa</th>
                                                <th>Director / Subdirector</th>
                                                <th>Especialista Asignado</th>
                                                <th>Tipo de Ficha</th>
                                                <th>Fecha Envío</th>
                                                <th>Estado</th>
                                                <th class="text-center">Ficha PDF</th>
                                                <th class="text-center">PDF Evaluado</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($fm_total === 0): ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-5 text-muted">
                                                        <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                                                        No se encontraron fichas de monitoreo con los filtros aplicados.
                                                    </td>
                                                </tr>
                                            <?php endif; ?>

                                            <?php while ($fm = $fm_result->fetch_assoc()): ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold" style="font-size:0.9rem;">
                                                            <?php echo htmlspecialchars($fm['nombre_ie']); ?></div>
                                                        <div style="font-size:0.78rem;color:var(--ugel-text-muted);">
                                                            Cód.
                                                            <?php echo htmlspecialchars($fm['codigo_local'] ?: $fm['codigo_institucion']); ?>
                                                            &bull; <?php echo htmlspecialchars($fm['distrito']); ?>
                                                        </div>
                                                    </td>
                                                    <td>
                                                        <div style="font-size:0.88rem;">
                                                            <?php echo htmlspecialchars(trim($fm['nombres'] . ' ' . $fm['ape_pat'] . ' ' . $fm['ape_mat'])); ?>
                                                        </div>
                                                        <div style="font-size:0.78rem;color:var(--ugel-text-muted);">DNI:
                                                            <?php echo htmlspecialchars($fm['dni']); ?></div>
                                                    </td>
                                                    <td>
                                                        <?php if ($fm['esp_nombres']): ?>
                                                            <div style="font-size:0.88rem;">
                                                                <?php echo htmlspecialchars(trim($fm['esp_nombres'] . ' ' . $fm['esp_ape_pat'])); ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted small">Sin asignar</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="fw-bold" style="font-size:0.88rem;">
                                                            <?php echo htmlspecialchars($fm['tipo_ficha']); ?></div>
                                                        <div style="font-size:0.78rem;color:var(--ugel-text-muted);">
                                                            Año <?php echo htmlspecialchars($fm['anio']); ?> &bull;
                                                            <?php echo htmlspecialchars($fm['codigo_envio']); ?>
                                                        </div>
                                                    </td>
                                                    <td style="white-space:nowrap;font-size:0.86rem;">
                                                        <?php echo $fm['fecha_envio'] ? date('d/m/Y H:i', strtotime($fm['fecha_envio'])) : '—'; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge <?php echo fm_admin_badge($fm['estado']); ?>">
                                                            <?php echo htmlspecialchars($fm['estado']); ?>
                                                        </span>
                                                        <?php if ($fm['porcentaje'] !== null): ?>
                                                            <div
                                                                style="font-size:0.75rem;color:var(--ugel-text-muted);margin-top:2px;">
                                                                <?php echo number_format((float) $fm['porcentaje'], 1); ?>% —
                                                                <?php echo htmlspecialchars($fm['escala_valorativa'] ?? ''); ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($fm['ruta_pdf']): ?>
                                                            <a class="btn btn-sm btn-outline-success"
                                                                href="<?php echo htmlspecialchars($fm['ruta_pdf']); ?>"
                                                                target="_blank" title="Ver ficha original">
                                                                <i class="bi bi-eye"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center">
                                                        <?php if ($fm['ruta_pdf_evaluada']): ?>
                                                            <a class="btn btn-sm btn-outline-success"
                                                                href="<?php echo htmlspecialchars($fm['ruta_pdf_evaluada']); ?>"
                                                                target="_blank" title="Ver ficha evaluada">
                                                                <i class="bi bi-file-earmark-check"></i>
                                                            </a>
                                                        <?php elseif ($fm['ruta_constancia']): ?>
                                                            <a class="btn btn-sm btn-outline-secondary"
                                                                href="<?php echo htmlspecialchars($fm['ruta_constancia']); ?>"
                                                                target="_blank" title="Ver constancia">
                                                                <i class="bi bi-file-earmark-pdf"></i>
                                                            </a>
                                                        <?php else: ?>
                                                            <span class="text-muted small">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endwhile; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <!-- Paginación -->
                            <div
                                class="card-footer bg-white py-3 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3">
                                <small class="text-muted">
                                    Mostrando
                                    <?php echo min($fm_offset + 1, $fm_total); ?>–<?php echo min($fm_offset + $fm_rpp, $fm_total); ?>
                                    de <?php echo $fm_total; ?> fichas
                                </small>
                                <?php if ($fm_total_pages > 1): ?>
                                    <div class="fluent-pagination">
                                        <?php if ($fm_page > 1): ?>
                                            <a class="fluent-page-link"
                                                href="?view=fichas_monitoreo&fm_page=1&fm_q=<?php echo urlencode($fm_q); ?>&fm_tipo=<?php echo $fm_tipo; ?>&fm_estado=<?php echo urlencode($fm_estado); ?>">&laquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=fichas_monitoreo&fm_page=<?php echo $fm_page - 1; ?>&fm_q=<?php echo urlencode($fm_q); ?>&fm_tipo=<?php echo $fm_tipo; ?>&fm_estado=<?php echo urlencode($fm_estado); ?>">&lsaquo;</a>
                                        <?php endif; ?>
                                        <?php
                                        $fm_start = max(1, $fm_page - 2);
                                        $fm_end = min($fm_total_pages, $fm_page + 2);
                                        for ($fp = $fm_start; $fp <= $fm_end; $fp++): ?>
                                            <a class="fluent-page-link <?php echo $fp === $fm_page ? 'active' : ''; ?>"
                                                href="?view=fichas_monitoreo&fm_page=<?php echo $fp; ?>&fm_q=<?php echo urlencode($fm_q); ?>&fm_tipo=<?php echo $fm_tipo; ?>&fm_estado=<?php echo urlencode($fm_estado); ?>">
                                                <?php echo $fp; ?>
                                            </a>
                                        <?php endfor; ?>
                                        <?php if ($fm_page < $fm_total_pages): ?>
                                            <a class="fluent-page-link"
                                                href="?view=fichas_monitoreo&fm_page=<?php echo $fm_page + 1; ?>&fm_q=<?php echo urlencode($fm_q); ?>&fm_tipo=<?php echo $fm_tipo; ?>&fm_estado=<?php echo urlencode($fm_estado); ?>">&rsaquo;</a>
                                            <a class="fluent-page-link"
                                                href="?view=fichas_monitoreo&fm_page=<?php echo $fm_total_pages; ?>&fm_q=<?php echo urlencode($fm_q); ?>&fm_tipo=<?php echo $fm_tipo; ?>&fm_estado=<?php echo urlencode($fm_estado); ?>">&raquo;</a>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>

    <script>
        document.addEventListener("DOMContentLoaded", function () {
            const sidebarToggle = document.getElementById("sidebarToggle");
            const closeSidebarBtn = document.getElementById("closeSidebarBtn");
            const sidebar = document.getElementById("mainSidebar");
            const overlay = document.getElementById("sidebarOverlay");

            function toggleSidebar() {
                sidebar.classList.toggle("active");
                overlay.classList.toggle("active");
            }

            if (sidebarToggle) {
                sidebarToggle.addEventListener("click", toggleSidebar);
            }

            if (closeSidebarBtn) {
                closeSidebarBtn.addEventListener("click", toggleSidebar);
            }

            if (overlay) {
                overlay.addEventListener("click", toggleSidebar);
            }
        });
    </script>

</body>

</html>