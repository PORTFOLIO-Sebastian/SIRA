<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$user = jefe_user($conn);
$nombre_corto = htmlspecialchars($user['nombres'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');

$error = '';
$success = '';

// Procesar Acciones POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREAR DIRECTOR
    if ($action === 'crear_director') {
        $dni = trim($_POST['dni'] ?? '');
        $nombres = trim($_POST['nombres'] ?? '');
        $ape_pat = trim($_POST['ape_pat'] ?? '');
        $ape_mat = trim($_POST['ape_mat'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $rol = trim($_POST['rol'] ?? 'director');

        if (empty($dni) || empty($nombres) || empty($ape_pat)) {
            $error = 'DNI, Nombres y Apellido Paterno son obligatorios.';
        } else {
            // Verificar si el DNI ya existe
            $st = $conn->prepare("SELECT id FROM usuarios WHERE DNI = ? LIMIT 1");
            $st->bind_param('s', $dni); $st->execute(); $st->store_result();
            if ($st->num_rows > 0) {
                $error = 'Ya existe un usuario registrado con ese DNI.';
            } $st->close();

            if (empty($error)) {
                $hash = password_hash($dni, PASSWORD_DEFAULT);
                $st = $conn->prepare("INSERT INTO usuarios(dni, password, nombres, ape_pat, ape_mat, correo, telefono, rol, estado) VALUES(?, ?, ?, ?, ?, ?, ?, ?, 'activo')");
                $st->bind_param('ssssssss', $dni, $hash, $nombres, $ape_pat, $ape_mat, $correo, $telefono, $rol);
                if ($st->execute()) {
                    $success = 'Director/Subdirector registrado exitosamente. La contraseña es su DNI.';
                } else {
                    $error = 'Error al registrar: ' . $conn->error;
                }
                $st->close();
            }
        }
    }

    // TOGGLE ESTADO DIRECTOR
    elseif ($action === 'toggle_status') {
        $id_dir = (int)($_POST['id_director'] ?? 0);
        $nuevo_estado = $_POST['estado'] === 'activo' ? 'activo' : 'inactivo';

        $st = $conn->prepare("UPDATE usuarios SET estado = ? WHERE id = ? AND rol IN ('director', 'sub-director', 'subdirector', 'docente', 'administrativo')");
        $st->bind_param('si', $nuevo_estado, $id_dir);
        if ($st->execute()) {
            if ($nuevo_estado === 'inactivo') {
                // Finalizar asignación activa
                $st_asig = $conn->prepare("UPDATE asignaciones SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_usuario = ? AND estado = 'activo'");
                $st_asig->bind_param('i', $id_dir);
                $st_asig->execute();
                $st_asig->close();
                $success = 'Usuario desactivado y liberado de su I.E.';
            } else {
                $success = 'Usuario activado con éxito.';
            }
        } else {
            $error = 'Error al actualizar estado.';
        }
        $st->close();
    }

    // ASIGNAR DIRECTOR A COLEGIO
    elseif ($action === 'asignar_director') {
        $id_ie = (int)($_POST['id_ie'] ?? 0);
        $id_dir = (int)($_POST['id_director'] ?? 0);

        if ($id_ie > 0 && $id_dir > 0) {
            $conn->begin_transaction();
            try {
                // 1. Desactivar asignación previa del director
                $st_dir = $conn->prepare("UPDATE asignaciones SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_usuario = ? AND estado = 'activo'");
                $st_dir->bind_param('i', $id_dir); $st_dir->execute(); $st_dir->close();

                // 2. Desactivar asignación previa del colegio
                $st_ie = $conn->prepare("UPDATE asignaciones SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_ie = ? AND estado = 'activo'");
                $st_ie->bind_param('i', $id_ie); $st_ie->execute(); $st_ie->close();

                // 3. Crear nueva asignación
                $st_new = $conn->prepare("INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES (?, ?, CURDATE(), 'activo')");
                $st_new->bind_param('ii', $id_dir, $id_ie); $st_new->execute(); $st_new->close();

                $conn->commit();
                $success = 'Director asignado exitosamente.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error al realizar asignación: ' . $e->getMessage();
            }
        } else {
            $error = 'Seleccione una institución y un director válidos.';
        }
    }

    // INTERCAMBIAR DIRECTORES (SWAP)
    elseif ($action === 'intercambiar_directores') {
        $id_ie1 = (int)($_POST['id_ie1'] ?? 0);
        $id_ie2 = (int)($_POST['id_ie2'] ?? 0);

        if ($id_ie1 > 0 && $id_ie2 > 0 && $id_ie1 !== $id_ie2) {
            $conn->begin_transaction();
            try {
                // Obtener directores activos de ambos colegios
                $st1 = $conn->prepare("SELECT id_usuario FROM asignaciones WHERE id_ie = ? AND estado = 'activo' LIMIT 1");
                $st1->bind_param('i', $id_ie1); $st1->execute();
                $r1 = $st1->get_result()->fetch_assoc(); $st1->close();

                $st2 = $conn->prepare("SELECT id_usuario FROM asignaciones WHERE id_ie = ? AND estado = 'activo' LIMIT 1");
                $st2->bind_param('i', $id_ie2); $st2->execute();
                $r2 = $st2->get_result()->fetch_assoc(); $st2->close();

                $dir1 = $r1 ? (int)$r1['id_usuario'] : 0;
                $dir2 = $r2 ? (int)$r2['id_usuario'] : 0;

                if ($dir1 === 0 && $dir2 === 0) {
                    throw new Exception('Ninguno de los colegios seleccionados tiene un director activo asignado.');
                }

                // Desactivar asignaciones actuales de ambos
                if ($dir1 > 0) {
                    $st = $conn->prepare("UPDATE asignaciones SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_ie = ? AND estado = 'activo'");
                    $st->bind_param('i', $id_ie1); $st->execute(); $st->close();
                }
                if ($dir2 > 0) {
                    $st = $conn->prepare("UPDATE asignaciones SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_ie = ? AND estado = 'activo'");
                    $st->bind_param('i', $id_ie2); $st->execute(); $st->close();
                }

                // Realizar el intercambio
                if ($dir1 > 0) {
                    // El director 1 va al colegio 2
                    $st = $conn->prepare("INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES (?, ?, CURDATE(), 'activo')");
                    $st->bind_param('ii', $dir1, $id_ie2); $st->execute(); $st->close();
                }
                if ($dir2 > 0) {
                    // El director 2 va al colegio 1
                    $st = $conn->prepare("INSERT INTO asignaciones (id_usuario, id_ie, fecha_inicio, estado) VALUES (?, ?, CURDATE(), 'activo')");
                    $st->bind_param('ii', $dir2, $id_ie1); $st->execute(); $st->close();
                }

                $conn->commit();
                $success = 'Intercambio de directores realizado exitosamente.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Error al intercambiar: ' . $e->getMessage();
            }
        } else {
            $error = 'Debe seleccionar dos colegios distintos para realizar el intercambio.';
        }
    }
}

// Parámetros de paginación y tab activa
$active_tab = $_GET['tab'] ?? 'colegios';
$limit = 10;

// 1. Obtener lista de Distritos única para filtros
$distritos = [];
$res_dist = $conn->query("SELECT DISTINCT distrito FROM instituciones WHERE distrito IS NOT NULL AND distrito != '' ORDER BY distrito ASC");
if ($res_dist) {
    while ($r_dist = $res_dist->fetch_assoc()) {
        $distritos[] = $r_dist['distrito'];
    }
}

// ==========================================
// PAGINACIÓN Y FILTROS: COLEGIOS
// ==========================================
$page_col = max(1, (int)($_GET['page_col'] ?? 1));
$offset_col = ($page_col - 1) * $limit;

$search_col = trim($_GET['search_col'] ?? '');
$distrito_col = trim($_GET['distrito_col'] ?? '');
$dir_status_col = trim($_GET['dir_status_col'] ?? '');

$where_col = "WHERE 1=1";
$params_col = [];
$types_col = "";

if ($search_col !== '') {
    $where_col .= " AND (i.nombre_ie LIKE ? OR i.codigo_local LIKE ? OR u.nombres LIKE ? OR u.ape_pat LIKE ?)";
    $term = "%$search_col%";
    $params_col[] = $term; $params_col[] = $term; $params_col[] = $term; $params_col[] = $term;
    $types_col .= "ssss";
}
if ($distrito_col !== '') {
    $where_col .= " AND i.distrito = ?";
    $params_col[] = $distrito_col;
    $types_col .= "s";
}
if ($dir_status_col === 'con') {
    $where_col .= " AND u.id IS NOT NULL";
} elseif ($dir_status_col === 'sin') {
    $where_col .= " AND u.id IS NULL";
}

// Count Colegios
$count_query_col = "
    SELECT COUNT(DISTINCT i.id) as total
    FROM instituciones i
    LEFT JOIN asignaciones a ON a.id_ie = i.id AND a.estado = 'activo'
    LEFT JOIN usuarios u ON u.id = a.id_usuario
    $where_col
";
$stmt = $conn->prepare($count_query_col);
if (!empty($params_col)) {
    $stmt->bind_param($types_col, ...$params_col);
}
$stmt->execute();
$total_col_rows = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_col_pages = max(1, ceil($total_col_rows / $limit));
if ($page_col > $total_col_pages) {
    $page_col = $total_col_pages;
    $offset_col = ($page_col - 1) * $limit;
}

// Fetch Colegios
$data_query_col = "
    SELECT i.*, u.id AS id_director, u.nombres, u.ape_pat, u.ape_mat, u.dni, u.estado AS estado_director
    FROM instituciones i
    LEFT JOIN asignaciones a ON a.id_ie = i.id AND a.estado = 'activo'
    LEFT JOIN usuarios u ON u.id = a.id_usuario
    $where_col
    ORDER BY i.nombre_ie ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($data_query_col);
$params_col[] = $limit;
$params_col[] = $offset_col;
$types_col .= "ii";
$stmt->bind_param($types_col, ...$params_col);
$stmt->execute();
$colegios = [];
$res_col = $stmt->get_result();
while ($row = $res_col->fetch_assoc()) {
    $colegios[] = $row;
}
$stmt->close();


// ==========================================
// PAGINACIÓN Y FILTROS: DIRECTORES
// ==========================================
$page_dir = max(1, (int)($_GET['page_dir'] ?? 1));
$offset_dir = ($page_dir - 1) * $limit;

$search_dir = trim($_GET['search_dir'] ?? '');
$dir_status = trim($_GET['dir_status'] ?? '');
$asig_status = trim($_GET['asig_status'] ?? '');

$where_dir = "WHERE u.rol IN ('director', 'sub-director', 'subdirector', 'docente', 'administrativo')";
$params_dir = [];
$types_dir = "";

if ($search_dir !== '') {
    $where_dir .= " AND (u.nombres LIKE ? OR u.ape_pat LIKE ? OR u.ape_mat LIKE ? OR u.dni LIKE ? OR i.nombre_ie LIKE ?)";
    $term = "%$search_dir%";
    $params_dir[] = $term; $params_dir[] = $term; $params_dir[] = $term; $params_dir[] = $term; $params_dir[] = $term;
    $types_dir .= "sssss";
}
if ($dir_status !== '') {
    $where_dir .= " AND u.estado = ?";
    $params_dir[] = $dir_status;
    $types_dir .= "s";
}
if ($asig_status === 'con') {
    $where_dir .= " AND i.id IS NOT NULL";
} elseif ($asig_status === 'sin') {
    $where_dir .= " AND i.id IS NULL";
}

// Count Directores
$count_query_dir = "
    SELECT COUNT(DISTINCT u.id) as total
    FROM usuarios u
    LEFT JOIN asignaciones a ON a.id_usuario = u.id AND a.estado = 'activo'
    LEFT JOIN instituciones i ON i.id = a.id_ie
    $where_dir
";
$stmt = $conn->prepare($count_query_dir);
if (!empty($params_dir)) {
    $stmt->bind_param($types_dir, ...$params_dir);
}
$stmt->execute();
$total_dir_rows = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_dir_pages = max(1, ceil($total_dir_rows / $limit));
if ($page_dir > $total_dir_pages) {
    $page_dir = $total_dir_pages;
    $offset_dir = ($page_dir - 1) * $limit;
}

// Fetch Directores
$data_query_dir = "
    SELECT u.*, i.id AS id_ie, i.nombre_ie, i.codigo_local
    FROM usuarios u
    LEFT JOIN asignaciones a ON a.id_usuario = u.id AND a.estado = 'activo'
    LEFT JOIN instituciones i ON i.id = a.id_ie
    $where_dir
    ORDER BY u.estado ASC, u.ape_pat ASC, u.nombres ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($data_query_dir);
$params_dir[] = $limit;
$params_dir[] = $offset_dir;
$types_dir .= "ii";
$stmt->bind_param($types_dir, ...$params_dir);
$stmt->execute();
$directores = [];
$res_dir = $stmt->get_result();
while ($row = $res_dir->fetch_assoc()) {
    $directores[] = $row;
}
$stmt->close();


// ==========================================
// CONSULTAS AUXILIARES PARA MODALES
// ==========================================
// Obtener todos los directores activos para el select de asignación
$all_active_directores = [];
$res_all_dir = $conn->query("
    SELECT id, nombres, ape_pat, ape_mat, dni FROM usuarios 
    WHERE estado='activo' AND rol IN ('director', 'sub-director', 'subdirector', 'docente', 'administrativo')
    ORDER BY ape_pat ASC, nombres ASC
");
if ($res_all_dir) {
    while ($row = $res_all_dir->fetch_assoc()) {
        $all_active_directores[] = $row;
    }
}

// Obtener todos los colegios con directores activos asignados para el select de intercambio
$colegios_con_director = [];
$res_col_dir = $conn->query("
    SELECT i.id, i.nombre_ie, u.nombres, u.ape_pat
    FROM instituciones i
    JOIN asignaciones a ON a.id_ie = i.id AND a.estado = 'activo'
    JOIN usuarios u ON u.id = a.id_usuario
    ORDER BY i.nombre_ie ASC
");
if ($res_col_dir) {
    while ($row = $res_col_dir->fetch_assoc()) {
        $colegios_con_director[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Gestionar I.E. y Directores - Jefe de AGP';
    include 'components/head.php';
    ?>
    <style>
        /* Estilos Fluent Design System */
        .fluent-card {
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.08);
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
            border-radius: 8px;
            padding: 24px;
        }
        .nav-tabs-custom .nav-link {
            border: none;
            color: #475569;
            font-weight: 600;
            padding: 12px 20px;
            border-bottom: 3px solid transparent;
            border-radius: 0;
            transition: all 0.15s ease;
            font-size: 0.95rem;
        }
        .nav-tabs-custom .nav-link.active {
            color: var(--ugel-green-dark);
            border-bottom-color: var(--ugel-green-dark);
            background: transparent;
        }
        .fluent-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        .fluent-table th {
            font-weight: 600;
            color: #323130;
            background-color: #f3f2f1;
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e1dfdd;
        }
        .fluent-table td {
            padding: 12px 16px;
            color: #605e5c;
            border-bottom: 1px solid #edebe9;
            vertical-align: middle;
        }
        .fluent-table tr:hover {
            background-color: #f3f2f1;
        }
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
        .fluent-select {
            border-radius: 4px;
            border: 1px solid #a19f9d;
            height: 36px;
            font-size: 0.9rem;
            color: #323130;
        }
        .fluent-select:focus {
            border-color: var(--ugel-green-dark);
            box-shadow: none;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            display: inline-block;
        }
        .status-active {
            background-color: #dff6dd;
            color: #107c41;
        }
        .status-inactive {
            background-color: #fde7e9;
            color: #a80000;
        }
        .modal-header-custom {
            background-color: var(--ugel-green-dark);
            color: #fff;
            border-bottom: none;
        }
        /* Paginación Fluent Design */
        .fluent-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 4px;
            margin-top: 24px;
        }
        .fluent-page-link {
            text-decoration: none;
            color: #323130;
            background: #ffffff;
            border: 1px solid #d2d0ce;
            border-radius: 4px;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.1s ease;
        }
        .fluent-page-link:hover {
            background: #f3f2f1;
            border-color: #a19f9d;
            color: #323130;
        }
        .fluent-page-link.active {
            background: var(--ugel-green-dark);
            border-color: var(--ugel-green-dark);
            color: #ffffff;
        }
    </style>
</head>
<body>

<!-- TOPBAR -->
<nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_jefe_agp.php">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Jefe de AGP</small>
            </div>
        </a>
        <div class="ms-auto">
            <button class="btn profile-btn ugel-profile-button d-flex align-items-center gap-2" disabled>
                <strong><?= $nombre_corto ?></strong>
                <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
            </button>
        </div>
    </div>
</nav>

<!-- MAIN CONTENT -->
<main class="ugel-shell py-4 py-md-5">
    <div class="ugel-breadcrumb mb-4 px-1">
        <a href="dashboard_jefe_agp.php" class="ugel-back-link">
            <i class="bi bi-arrow-left me-1"></i>Volver al Panel
        </a>
    </div>

    <!-- Header Section -->
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4 px-1">
        <div>
            <h3 class="fw-bold mb-1" style="color: #0f172a; font-size: 1.6rem;">
                <i class="bi bi-building-fill me-2" style="color: var(--ugel-green-dark);"></i>Gestionar I.E. y Directores
            </h3>
            <p class="text-muted mb-0 small">Administración de Colegios y Directores con paginación inteligente de 10 en 10.</p>
        </div>
        <div class="d-flex gap-2">
            <button class="btn btn-primary fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalIntercambiar">
                <i class="bi bi-arrow-left-right"></i> Intercambiar Directores
            </button>
            <button class="btn btn-success fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalCrear">
                <i class="bi bi-person-plus-fill"></i> Registrar Director
            </button>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
        <div class="alert alert-danger mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="alert alert-success mb-4"><i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Tabs Navigation -->
    <ul class="nav nav-tabs nav-tabs-custom border-bottom mb-4" id="adminTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $active_tab === 'colegios' ? 'active' : '' ?>" href="?tab=colegios"><i class="bi bi-building me-2"></i>Instituciones Educativas (Colegios)</a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link <?= $active_tab === 'directores' ? 'active' : '' ?>" href="?tab=directores"><i class="bi bi-people me-2"></i>Directores y Subdirectores</a>
        </li>
    </ul>

    <!-- Tabs Content -->
    <div class="tab-content" id="adminTabsContent">
        
        <!-- PANE: COLEGIOS -->
        <?php if ($active_tab === 'colegios'): ?>
        <div class="tab-pane fade show active" id="colegios-pane" role="tabpanel">
            <div class="fluent-card">
                
                <!-- Search and Filters Form -->
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="tab" value="colegios">
                    <input type="hidden" name="page_col" value="1">
                    <div class="col-12 col-md-5">
                        <div class="fluent-input-group">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search_col" class="form-control" placeholder="Buscar por Nombre, Código Local o Director..." value="<?= htmlspecialchars($search_col) ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="distrito_col" class="form-select fluent-select" onchange="this.form.submit()">
                            <option value="">-- Todos los Distritos --</option>
                            <?php foreach ($distritos as $dist): ?>
                                <option value="<?= htmlspecialchars($dist) ?>" <?= $dist === $distrito_col ? 'selected' : '' ?>><?= htmlspecialchars($dist) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="dir_status_col" class="form-select fluent-select" onchange="this.form.submit()">
                            <option value="">-- Asignación de Director --</option>
                            <option value="con" <?= $dir_status_col === 'con' ? 'selected' : '' ?>>Con Director Asignado</option>
                            <option value="sin" <?= $dir_status_col === 'sin' ? 'selected' : '' ?>>Sin Director Asignado</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-1">
                        <button type="submit" class="btn btn-outline-secondary w-100 fw-bold" style="height: 36px;">Filtrar</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="fluent-table">
                        <thead>
                            <tr>
                                <th>Nombre I.E.</th>
                                <th>Código Local</th>
                                <th>Nivel/Modalidad</th>
                                <th>Distrito</th>
                                <th>Director / Subdirector Asignado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($colegios)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted p-4">No se encontraron colegios con los filtros seleccionados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($colegios as $col): ?>
                                    <?php
                                    $has_director = !empty($col['id_director']);
                                    $dir_name = $has_director ? htmlspecialchars($col['nombres'] . ' ' . $col['ape_pat'] . ' ' . ($col['ape_mat'] ?? '')) : 'Sin director asignado';
                                    $dir_state = $has_director && $col['estado_director'] === 'activo';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($col['nombre_ie']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($col['codigo_local']) ?></code></td>
                                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($col['nivel_modalidad'] ?: 'EBR') ?></span></td>
                                        <td><?= htmlspecialchars($col['distrito']) ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="<?= !$has_director ? 'text-danger small italic' : '' ?>"><?= $dir_name ?></span>
                                                <?php if ($has_director): ?>
                                                    <span class="status-badge <?= $dir_state ? 'status-active' : 'status-inactive' ?>" style="font-size:0.65rem;">
                                                        <?= $dir_state ? 'Activo' : 'Inactivo' ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="abrirAsignarDirector(<?= $col['id'] ?>, '<?= addslashes($col['nombre_ie']) ?>')">
                                                <i class="bi bi-pencil-square"></i> Asignar Director
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación Fluent Design -->
                <?php if ($total_col_pages > 1): ?>
                    <div class="fluent-pagination">
                        <?php if ($page_col > 1): ?>
                            <a href="?tab=colegios&page_col=1&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" class="fluent-page-link">&laquo;</a>
                            <a href="?tab=colegios&page_col=<?= $page_col - 1 ?>&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" class="fluent-page-link">&lsaquo;</a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page_col - 2);
                        $end = min($total_col_pages, $page_col + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <a href="?tab=colegios&page_col=<?= $i ?>&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" 
                               class="fluent-page-link <?= $i === $page_col ? 'active' : '' ?>">
                               <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end < $total_col_pages): ?>
                            <?php if ($end < $total_col_pages - 1): ?>
                                <span class="fluent-page-dots">...</span>
                            <?php endif; ?>
                            <a href="?tab=colegios&page_col=<?= $total_col_pages ?>&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" class="fluent-page-link"><?= $total_col_pages ?></a>
                        <?php endif; ?>

                        <?php if ($page_col < $total_col_pages): ?>
                            <a href="?tab=colegios&page_col=<?= $page_col + 1 ?>&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" class="fluent-page-link">&rsaquo;</a>
                            <a href="?tab=colegios&page_col=<?= $total_col_pages ?>&search_col=<?= urlencode($search_col) ?>&distrito_col=<?= urlencode($distrito_col) ?>&dir_status_col=<?= urlencode($dir_status_col) ?>" class="fluent-page-link">Last &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- PANE: DIRECTORES -->
        <?php if ($active_tab === 'directores'): ?>
        <div class="tab-pane fade show active" id="directores-pane" role="tabpanel">
            <div class="fluent-card">

                <!-- Search and Filters Form -->
                <form method="GET" class="row g-3 mb-4">
                    <input type="hidden" name="tab" value="directores">
                    <input type="hidden" name="page_dir" value="1">
                    <div class="col-12 col-md-5">
                        <div class="fluent-input-group">
                            <i class="bi bi-search"></i>
                            <input type="text" name="search_dir" class="form-control" placeholder="Buscar por Nombre, DNI o I.E. asignada..." value="<?= htmlspecialchars($search_dir) ?>">
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="dir_status" class="form-select fluent-select" onchange="this.form.submit()">
                            <option value="">-- Todos los Estados --</option>
                            <option value="activo" <?= $dir_status === 'activo' ? 'selected' : '' ?>>Activos</option>
                            <option value="inactivo" <?= $dir_status === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="asig_status" class="form-select fluent-select" onchange="this.form.submit()">
                            <option value="">-- Estado de Asignación --</option>
                            <option value="con" <?= $asig_status === 'con' ? 'selected' : '' ?>>Asignado a Colegio</option>
                            <option value="sin" <?= $asig_status === 'sin' ? 'selected' : '' ?>>Sin Colegio Asignado</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-1">
                        <button type="submit" class="btn btn-outline-secondary w-100 fw-bold" style="height: 36px;">Filtrar</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="fluent-table">
                        <thead>
                            <tr>
                                <th>DNI</th>
                                <th>Apellidos y Nombres</th>
                                <th>Cargo</th>
                                <th>Contacto</th>
                                <th>I.E. Asignada</th>
                                <th>Estado</th>
                                <th class="text-center">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($directores)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted p-4">No se encontraron directores con los filtros seleccionados.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($directores as $dir): ?>
                                    <?php
                                    $full_name = htmlspecialchars($dir['ape_pat'] . ' ' . ($dir['ape_mat'] ?? '') . ', ' . $dir['nombres'], ENT_QUOTES, 'UTF-8');
                                    $is_active = $dir['estado'] === 'activo';
                                    $has_ie = !empty($dir['nombre_ie']);
                                    ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars($dir['dni']) ?></code></td>
                                        <td><strong><?= $full_name ?></strong></td>
                                        <td><span class="badge bg-secondary"><?= htmlspecialchars(strtoupper($dir['rol'])) ?></span></td>
                                        <td>
                                            <small class="text-muted d-block"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($dir['correo'] ?: '-') ?></small>
                                            <small class="text-muted d-block"><i class="bi bi-phone me-1"></i><?= htmlspecialchars($dir['telefono'] ?: '-') ?></small>
                                        </td>
                                        <td>
                                            <?php if ($has_ie): ?>
                                                <strong><?= htmlspecialchars($dir['nombre_ie']) ?></strong> <code class="small">(<?= htmlspecialchars($dir['codigo_local']) ?>)</code>
                                            <?php else: ?>
                                                <span class="text-danger small">No asignado</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge <?= $is_active ? 'status-active' : 'status-inactive' ?>">
                                                <?= $is_active ? 'Activo' : 'Inactivo' ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Está seguro de cambiar el estado de este directivo?');">
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="id_director" value="<?= $dir['id'] ?>">
                                                <input type="hidden" name="estado" value="<?= $is_active ? 'inactivo' : 'activo' ?>">
                                                <button type="submit" class="btn btn-sm <?= $is_active ? 'btn-outline-danger' : 'btn-outline-success' ?> fw-bold">
                                                    <i class="bi <?= $is_active ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                    <?= $is_active ? 'Desactivar' : 'Activar' ?>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Paginación Fluent Design -->
                <?php if ($total_dir_pages > 1): ?>
                    <div class="fluent-pagination">
                        <?php if ($page_dir > 1): ?>
                            <a href="?tab=directores&page_dir=1&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" class="fluent-page-link">&laquo;</a>
                            <a href="?tab=directores&page_dir=<?= $page_dir - 1 ?>&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" class="fluent-page-link">&lsaquo;</a>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page_dir - 2);
                        $end = min($total_dir_pages, $page_dir + 2);
                        for ($i = $start; $i <= $end; $i++): ?>
                            <a href="?tab=directores&page_dir=<?= $i ?>&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" 
                               class="fluent-page-link <?= $i === $page_dir ? 'active' : '' ?>">
                               <?= $i ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($end < $total_dir_pages): ?>
                            <?php if ($end < $total_dir_pages - 1): ?>
                                <span class="fluent-page-dots">...</span>
                            <?php endif; ?>
                            <a href="?tab=directores&page_dir=<?= $total_dir_pages ?>&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" class="fluent-page-link"><?= $total_dir_pages ?></a>
                        <?php endif; ?>

                        <?php if ($page_dir < $total_dir_pages): ?>
                            <a href="?tab=directores&page_dir=<?= $page_dir + 1 ?>&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" class="fluent-page-link">&rsaquo;</a>
                            <a href="?tab=directores&page_dir=<?= $total_dir_pages ?>&search_dir=<?= urlencode($search_dir) ?>&dir_status=<?= urlencode($dir_status) ?>&asig_status=<?= urlencode($asig_status) ?>" class="fluent-page-link">Last &raquo;</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>

<!-- MODAL CREAR DIRECTOR -->
<div class="modal fade" id="modalCrear" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="crear_director">
            <div class="modal-header modal-header-custom bg-success text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Registrar Director/Subdirector</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">DNI *</label>
                    <input type="text" class="form-control" name="dni" required pattern="[0-9]{8,20}">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Nombres *</label>
                    <input type="text" class="form-control" name="nombres" required>
                </div>
                <div class="row">
                    <div class="col mb-3">
                        <label class="form-label fw-bold">Apellido Paterno *</label>
                        <input type="text" class="form-control" name="ape_pat" required>
                    </div>
                    <div class="col mb-3">
                        <label class="form-label fw-bold">Apellido Materno</label>
                        <input type="text" class="form-control" name="ape_mat">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Cargo (Rol) *</label>
                    <select class="form-select" name="rol" required>
                        <option value="director">Director</option>
                        <option value="sub-director">Subdirector</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Correo electrónico</label>
                    <input type="email" class="form-control" name="correo">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Teléfono</label>
                    <input type="text" class="form-control" name="telefono">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-success fw-bold">Guardar Directivo</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ASIGNAR DIRECTOR A COLEGIO -->
<div class="modal fade" id="modalAsignarDirector" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="asignar_director">
            <input type="hidden" name="id_ie" id="asigIdIe">
            <div class="modal-header modal-header-custom bg-primary text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Asignar Director a: <span id="asigNombreIe"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">Seleccionar Director / Subdirector</label>
                    <select class="form-select" name="id_director" required>
                        <option value="">-- Seleccione un director activo --</option>
                        <?php foreach ($all_active_directores as $dir): ?>
                            <option value="<?= $dir['id'] ?>">
                                <?= htmlspecialchars($dir['ape_pat'] . ' ' . $dir['nombres'] . ' (DNI: ' . $dir['dni'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="alert alert-warning small mb-0">
                    <i class="bi bi-info-circle-fill me-1"></i> Si el director seleccionado ya posee un colegio, será removido de este. Si la I.E. elegida posee un director asignado, será reemplazado.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold">Realizar Asignación</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL INTERCAMBIAR DIRECTORES -->
<div class="modal fade" id="modalIntercambiar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="intercambiar_directores">
            <div class="modal-header modal-header-custom bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-arrow-left-right me-2"></i>Intercambiar Directores de Colegios</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Esta función intercambia los directores activos asignados entre dos colegios distintos.</p>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Colegio 1</label>
                        <select class="form-select" name="id_ie1" required>
                            <option value="">-- Seleccione Colegio 1 --</option>
                            <?php foreach ($colegios_con_director as $col): ?>
                                <option value="<?= $col['id'] ?>">
                                    <?= htmlspecialchars($col['nombre_ie'] . ' - Dir: ' . $col['nombres'] . ' ' . $col['ape_pat']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Colegio 2</label>
                        <select class="form-select" name="id_ie2" required>
                            <option value="">-- Seleccione Colegio 2 --</option>
                            <?php foreach ($colegios_con_director as $col): ?>
                                <option value="<?= $col['id'] ?>">
                                    <?= htmlspecialchars($col['nombre_ie'] . ' - Dir: ' . $col['nombres'] . ' ' . $col['ape_pat']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary fw-bold">Efectuar Intercambio</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
function abrirAsignarDirector(idIe, nombreIe) {
    document.getElementById('asigNombreIe').innerText = nombreIe;
    document.getElementById('asigIdIe').value = idIe;
    const modal = new bootstrap.Modal(document.getElementById('modalAsignarDirector'));
    modal.show();
}
</script>
</body>
</html>
