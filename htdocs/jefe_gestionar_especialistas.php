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

    // CREAR ESPECIALISTA
    if ($action === 'crear') {
        $dni = trim($_POST['dni'] ?? '');
        $nombres = trim($_POST['nombres'] ?? '');
        $ape_pat = trim($_POST['ape_pat'] ?? '');
        $ape_mat = trim($_POST['ape_mat'] ?? '');
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

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
                $st = $conn->prepare("INSERT INTO usuarios(dni, password, nombres, ape_pat, ape_mat, correo, telefono, rol, estado) VALUES(?, ?, ?, ?, ?, ?, ?, 'especialista', 'activo')");
                $st->bind_param('sssssss', $dni, $hash, $nombres, $ape_pat, $ape_mat, $correo, $telefono);
                if ($st->execute()) {
                    $success = 'Especialista registrado exitosamente. La contraseña predeterminada es su DNI.';
                } else {
                    $error = 'Error al registrar especialista: ' . $conn->error;
                }
                $st->close();
            }
        }
    }

    // TOGGLE ESTADO (Activar / Desactivar)
    elseif ($action === 'toggle_status') {
        $id_esp = (int)($_POST['id_especialista'] ?? 0);
        $nuevo_estado = $_POST['estado'] === 'activo' ? 'activo' : 'inactivo';

        $st = $conn->prepare("UPDATE usuarios SET estado = ? WHERE id = ? AND rol = 'especialista'");
        $st->bind_param('si', $nuevo_estado, $id_esp);
        if ($st->execute()) {
            if ($nuevo_estado === 'inactivo') {
                // Si se inactiva, se finalizan todas sus asignaciones activas de colegios
                $st_asig = $conn->prepare("UPDATE asignaciones_especialistas SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id_usuario = ? AND estado = 'activo'");
                $st_asig->bind_param('i', $id_esp);
                $st_asig->execute();
                $st_asig->close();
                $success = 'Especialista desactivado con éxito. Sus colegios asignados han quedado liberados.';
            } else {
                $success = 'Especialista activado con éxito.';
            }
        } else {
            $error = 'Error al actualizar estado del especialista.';
        }
        $st->close();
    }

    // ASIGNAR COLEGIO
    elseif ($action === 'asignar_colegio') {
        $id_esp = (int)($_POST['id_especialista'] ?? 0);
        $id_ie = (int)($_POST['id_ie'] ?? 0);
        $sub_tipo = trim($_POST['sub_tipo'] ?? 'Pedagógico');

        if ($id_esp > 0 && $id_ie > 0) {
            // Verificar si la IE ya tiene un especialista activo
            $st_check = $conn->prepare("SELECT id, id_usuario FROM asignaciones_especialistas WHERE id_institucion = ? AND estado = 'activo' LIMIT 1");
            $st_check->bind_param('i', $id_ie); $st_check->execute();
            $res = $st_check->get_result()->fetch_assoc();
            $st_check->close();

            if ($res) {
                // Cambiar el especialista anterior a inactivo
                $st_old = $conn->prepare("UPDATE asignaciones_especialistas SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id = ?");
                $st_old->bind_param('i', $res['id']); $st_old->execute(); $st_old->close();
            }

            // Insertar la nueva asignación
            $st_new = $conn->prepare("INSERT INTO asignaciones_especialistas (id_usuario, id_institucion, sub_tipo, fecha_inicio, estado) VALUES (?, ?, ?, CURDATE(), 'activo')");
            $st_new->bind_param('iis', $id_esp, $id_ie, $sub_tipo);
            if ($st_new->execute()) {
                $success = 'Institución educativa asignada exitosamente.';
            } else {
                $error = 'Error al asignar la institución educativa.';
            }
            $st_new->close();
        } else {
            $error = 'Debe seleccionar una institución educativa válida.';
        }
    }

    // QUITAR COLEGIO (DESASIGNAR)
    elseif ($action === 'quitar_colegio') {
        $id_asig = (int)($_POST['id_asignacion'] ?? 0);
        if ($id_asig > 0) {
            $st = $conn->prepare("UPDATE asignaciones_especialistas SET estado = 'inactivo', fecha_fin = CURDATE() WHERE id = ?");
            $st->bind_param('i', $id_asig);
            if ($st->execute()) {
                $success = 'Asignación de colegio finalizada exitosamente.';
            } else {
                $error = 'Error al finalizar la asignación.';
            }
            $st->close();
        }
    }
}

// Parámetros de paginación y filtros
$page_esp = max(1, (int)($_GET['page_esp'] ?? 1));
$limit = 10;
$offset_esp = ($page_esp - 1) * $limit;

$search_esp = trim($_GET['search_esp'] ?? '');
$estado_esp = trim($_GET['estado_esp'] ?? '');
$colegios_esp = trim($_GET['colegios_esp'] ?? '');

$where_esp = "WHERE u.rol = 'especialista'";
$params_esp = [];
$types_esp = "";

if ($search_esp !== '') {
    $where_esp .= " AND (u.nombres LIKE ? OR u.ape_pat LIKE ? OR u.ape_mat LIKE ? OR u.dni LIKE ? OR u.correo LIKE ?)";
    $term = "%$search_esp%";
    $params_esp[] = $term; $params_esp[] = $term; $params_esp[] = $term; $params_esp[] = $term; $params_esp[] = $term;
    $types_esp .= "sssss";
}
if ($estado_esp !== '') {
    $where_esp .= " AND u.estado = ?";
    $params_esp[] = $estado_esp;
    $types_esp .= "s";
}

$having_esp = "";
if ($colegios_esp === 'con') {
    $having_esp = "HAVING total_colegios > 0";
} elseif ($colegios_esp === 'sin') {
    $having_esp = "HAVING total_colegios = 0";
}

// Count Specialists
$count_query_esp = "
    SELECT COUNT(*) as total FROM (
        SELECT u.id, COUNT(DISTINCT CASE WHEN ae.estado = 'activo' THEN ae.id_institucion END) AS total_colegios
        FROM usuarios u
        LEFT JOIN asignaciones_especialistas ae ON ae.id_usuario = u.id
        $where_esp
        GROUP BY u.id
        $having_esp
    ) as sub
";
$stmt = $conn->prepare($count_query_esp);
if (!empty($params_esp)) {
    $stmt->bind_param($types_esp, ...$params_esp);
}
$stmt->execute();
$total_esp_rows = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$total_esp_pages = max(1, ceil($total_esp_rows / $limit));
if ($page_esp > $total_esp_pages) {
    $page_esp = $total_esp_pages;
    $offset_esp = ($page_esp - 1) * $limit;
}

// Fetch Specialists
$data_query_esp = "
    SELECT u.*, 
           COUNT(DISTINCT CASE WHEN ae.estado = 'activo' THEN ae.id_institucion END) AS total_colegios
    FROM usuarios u
    LEFT JOIN asignaciones_especialistas ae ON ae.id_usuario = u.id
    $where_esp
    GROUP BY u.id
    $having_esp
    ORDER BY u.estado ASC, u.ape_pat ASC, u.nombres ASC
    LIMIT ? OFFSET ?
";
$stmt = $conn->prepare($data_query_esp);
$params_esp[] = $limit;
$params_esp[] = $offset_esp;
$types_esp .= "ii";
$stmt->bind_param($types_esp, ...$params_esp);
$stmt->execute();
$res = $stmt->get_result();
$especialistas = [];
while ($row = $res->fetch_assoc()) {
    $especialistas[] = $row;
}
$stmt->close();

// Obtener todas las instituciones educativas para los select de asignación
$instituciones = [];
$res_ie = $conn->query("SELECT id, codigo_local, nombre_ie, distrito FROM instituciones ORDER BY nombre_ie ASC");
if ($res_ie) {
    while ($row = $res_ie->fetch_assoc()) {
        $instituciones[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Gestionar Especialistas - Jefe de AGP';
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
        .spec-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #edebe9;
            color: #323130;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 600;
            margin-right: 10px;
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
            gap: 6px;
            margin-top: 24px;
        }
        .fluent-page-link {
            text-decoration: none;
            color: #323130;
            background: #f3f2f1;
            border: none;
            border-radius: 4px;
            padding: 8px 14px;
            font-weight: 600;
            font-size: 0.9rem;
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
            padding: 8px 6px;
            color: #605e5c;
            font-weight: 600;
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
                <i class="bi bi-person-badge-fill me-2" style="color: var(--ugel-green-dark);"></i>Gestionar Especialistas
            </h3>
            <p class="text-muted mb-0 small">Administración, filtros avanzados y asignación de colegios a especialistas de la UGEL.</p>
        </div>
        <div>
            <button class="btn btn-success fw-bold d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#modalCrear">
                <i class="bi bi-person-plus-fill"></i> Registrar Especialista
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

    <!-- Fluent Card containing Search and Table -->
    <div class="fluent-card">
        
        <!-- Search and Filters bar -->
        <form method="GET" class="row g-3 mb-4">
            <input type="hidden" name="page_esp" value="1">
            <div class="col-12 col-md-5">
                <div class="fluent-input-group">
                    <i class="bi bi-search"></i>
                    <input type="text" name="search_esp" class="form-control" placeholder="Buscar por Nombre, Apellidos, DNI o Correo..." value="<?= htmlspecialchars($search_esp) ?>">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <select name="estado_esp" class="form-select fluent-select" onchange="this.form.submit()">
                    <option value="">-- Todos los Estados --</option>
                    <option value="activo" <?= $estado_esp === 'activo' ? 'selected' : '' ?>>Activos</option>
                    <option value="inactivo" <?= $estado_esp === 'inactivo' ? 'selected' : '' ?>>Inactivos</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <select name="colegios_esp" class="form-select fluent-select" onchange="this.form.submit()">
                    <option value="">-- Todas las Asignaciones --</option>
                    <option value="con" <?= $colegios_esp === 'con' ? 'selected' : '' ?>>Con Colegios Asignados</option>
                    <option value="sin" <?= $colegios_esp === 'sin' ? 'selected' : '' ?>>Sin Colegios Asignados</option>
                </select>
            </div>
            <div class="col-12 col-md-1">
                <button type="submit" class="btn btn-outline-secondary w-100 fw-bold" style="height:36px;">Filtrar</button>
            </div>
        </form>

        <!-- Table of Specialists -->
        <div class="table-responsive">
            <table class="fluent-table">
                <thead>
                    <tr>
                        <th>Especialista</th>
                        <th>DNI</th>
                        <th>Contacto</th>
                        <th>Colegios</th>
                        <th>Estado</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($especialistas)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted p-4">No se encontraron especialistas registrados.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($especialistas as $esp): ?>
                            <?php
                            $iniciales = strtoupper(substr($esp['nombres'], 0, 1) . substr($esp['ape_pat'], 0, 1));
                            $full_name = htmlspecialchars(trim($esp['nombres'] . ' ' . $esp['ape_pat'] . ' ' . ($esp['ape_mat'] ?? '')), ENT_QUOTES, 'UTF-8');
                            $is_active = $esp['estado'] === 'activo';
                            $total_col = (int)$esp['total_colegios'];
                            ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div class="spec-avatar"><?= $iniciales ?></div>
                                        <span class="fw-bold" style="color: #323130;"><?= $full_name ?></span>
                                    </div>
                                </td>
                                <td><code><?= htmlspecialchars($esp['dni']) ?></code></td>
                                <td>
                                    <small class="text-muted d-block"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($esp['correo'] ?: 'Sin correo') ?></small>
                                    <small class="text-muted d-block"><i class="bi bi-phone me-1"></i><?= htmlspecialchars($esp['telefono'] ?: 'Sin teléfono') ?></small>
                                </td>
                                <td>
                                    <span class="badge <?= $total_col > 0 ? 'bg-secondary' : 'bg-light text-dark border' ?>">
                                        <?= $total_col ?> Colegio<?= $total_col === 1 ? '' : 's' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge <?= $is_active ? 'status-active' : 'status-inactive' ?>">
                                        <?= $is_active ? 'Activo' : 'Inactivo' ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="d-flex justify-content-center gap-2">
                                        <?php if ($is_active): ?>
                                            <button class="btn btn-sm btn-outline-primary fw-bold" onclick="abrirAsignaciones(<?= $esp['id'] ?>, '<?= addslashes($full_name) ?>')">
                                                <i class="bi bi-building"></i> Asignar Colegios
                                            </button>
                                        <?php endif; ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Está seguro de cambiar el estado de este especialista?');">
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="id_especialista" value="<?= $esp['id'] ?>">
                                            <input type="hidden" name="estado" value="<?= $is_active ? 'inactivo' : 'activo' ?>">
                                            <button type="submit" class="btn btn-sm <?= $is_active ? 'btn-outline-danger' : 'btn-outline-success' ?> fw-bold">
                                                <i class="bi <?= $is_active ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                                <?= $is_active ? 'Desactivar' : 'Activar' ?>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Paginación Fluent Design -->
        <?php if ($total_esp_pages > 1): ?>
            <div class="fluent-pagination">
                <?php if ($page_esp > 1): ?>
                    <a href="?page_esp=1&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" class="fluent-page-link">&laquo;</a>
                    <a href="?page_esp=<?= $page_esp - 1 ?>&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" class="fluent-page-link">&lsaquo;</a>
                <?php endif; ?>

                <?php
                $start = max(1, $page_esp - 2);
                $end = min($total_esp_pages, $page_esp + 2);
                for ($i = $start; $i <= $end; $i++): ?>
                    <a href="?page_esp=<?= $i ?>&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" 
                       class="fluent-page-link <?= $i === $page_esp ? 'active' : '' ?>">
                       <?= $i ?>
                    </a>
                <?php endfor; ?>

                <?php if ($end < $total_esp_pages): ?>
                    <?php if ($end < $total_esp_pages - 1): ?>
                        <span class="fluent-page-dots">...</span>
                    <?php endif; ?>
                    <a href="?page_esp=<?= $total_esp_pages ?>&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" class="fluent-page-link"><?= $total_esp_pages ?></a>
                <?php endif; ?>

                <?php if ($page_esp < $total_esp_pages): ?>
                    <a href="?page_esp=<?= $page_esp + 1 ?>&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" class="fluent-page-link">&rsaquo;</a>
                    <a href="?page_esp=<?= $total_esp_pages ?>&search_esp=<?= urlencode($search_esp) ?>&estado_esp=<?= urlencode($estado_esp) ?>&colegios_esp=<?= urlencode($colegios_esp) ?>" class="fluent-page-link">Last &raquo;</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

<!-- MODAL CREAR ESPECIALISTA -->
<div class="modal fade" id="modalCrear" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST">
            <input type="hidden" name="action" value="crear">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i>Registrar Especialista</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-bold">DNI *</label>
                    <input type="text" class="form-control" name="dni" required pattern="[0-9]{8,20}" title="DNI numérico">
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
                <button type="submit" class="btn btn-success fw-bold">Guardar Especialista</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL ASIGNACIONES -->
<div class="modal fade" id="modalAsignar" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header modal-header-custom">
                <h5 class="modal-title fw-bold"><i class="bi bi-building me-2"></i>Asignar Colegios a: <span id="asigNombre"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Formulario de nueva asignación -->
                <form method="POST" class="row g-2 mb-4 align-items-end p-3 bg-light rounded border">
                    <input type="hidden" name="action" value="asignar_colegio">
                    <input type="hidden" name="id_especialista" id="asigIdEsp">
                    <div class="col-md-7">
                        <label class="form-label small fw-bold">Seleccionar Colegio / I.E.</label>
                        <select class="form-select select2" name="id_ie" required style="width:100%;">
                            <option value="">-- Seleccione una I.E. --</option>
                            <?php foreach ($instituciones as $ie): ?>
                                <option value="<?= $ie['id'] ?>">
                                    <?= htmlspecialchars($ie['nombre_ie'] . ' (' . $ie['codigo_local'] . ') - ' . $ie['distrito']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-bold">Sub-Tipo</label>
                        <select class="form-select" name="sub_tipo">
                            <option value="Pedagógico">Pedagógico</option>
                            <option value="Administrativo">Administrativo</option>
                            <option value="General">General</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary fw-bold w-100"><i class="bi bi-plus-lg"></i> Asignar</button>
                    </div>
                </form>

                <!-- Listado de asignaciones actuales -->
                <h6 class="fw-bold mb-3"><i class="bi bi-list-check me-1"></i>Colegios asignados actualmente:</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Nombre I.E.</th>
                                <th>Código Local</th>
                                <th>Distrito</th>
                                <th>Sub-Tipo</th>
                                <th>F. Inicio</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody id="asigTablaCuerpo">
                            <!-- Se llena por JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
function abrirAsignaciones(id, nombre) {
    document.getElementById('asigNombre').innerText = nombre;
    document.getElementById('asigIdEsp').value = id;
    
    // Limpiar tabla
    const tbody = document.getElementById('asigTablaCuerpo');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Cargando...</td></tr>';
    
    // Abrir modal
    const modal = new bootstrap.Modal(document.getElementById('modalAsignar'));
    modal.show();
    
    // Cargar asignaciones vía Fetch
    fetch('ajax_jefe_ficha_editor.php?action=get_especialista_asignaciones&id_usuario=' + id)
        .then(res => res.json())
        .then(data => {
            tbody.innerHTML = '';
            if (data.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No tiene colegios asignados.</td></tr>';
                return;
            }
            data.forEach(asig => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td><strong>${asig.nombre_ie}</strong></td>
                    <td><code>${asig.codigo_local}</code></td>
                    <td>${asig.distrito}</td>
                    <td><span class="badge bg-light text-dark border">${asig.sub_tipo}</span></td>
                    <td>${asig.fecha_inicio}</td>
                    <td class="text-center">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Está seguro de quitar esta asignación?');">
                            <input type="hidden" name="action" value="quitar_colegio">
                            <input type="hidden" name="id_asignacion" value="${asig.id}">
                            <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Quitar</button>
                        </form>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        })
        .catch(err => {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error al cargar asignaciones.</td></tr>';
        });
}
</script>
</body>
</html>
