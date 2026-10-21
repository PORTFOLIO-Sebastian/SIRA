<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) { header('Location: login.php'); exit(); }
if ($_SESSION['rol'] !== 'especialista') { header('Location: dashboard.php'); exit(); }

$_SESSION['especialista_fichas_query'] = $_SERVER['QUERY_STRING'] ?? '';

$id_usuario = (int)$_SESSION['id_usuario'];
$mensaje    = '';
$error      = '';

function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function badgeClase(string $estado): string {
    return match($estado) {
        'ENVIADO'     => 'ugel-badge is-blue',
        'EN_REVISION' => 'ugel-badge is-orange',
        'OBSERVADO'   => 'ugel-badge is-yellow',
        'RECHAZADO'   => 'ugel-badge is-red',
        'CONFORME'    => 'ugel-badge is-green',
        'REVISADO'    => 'ugel-badge is-green',
        default       => 'ugel-badge',
    };
}

// Datos del usuario
$stmt = $conn->prepare("SELECT nombres, ape_pat, ape_mat, dni FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user  = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nombre       = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = h($user['nombres'] ?? 'Usuario');


// Filtros GET
$q      = trim($_GET['q'] ?? '');
$tipo   = (int)($_GET['tipo'] ?? 0);
$estado = $_GET['estado'] ?? '';

// Tipos de ficha
$tipos = [];
$rs    = $conn->query("SELECT id, nombre, anio FROM fm_tipos_ficha WHERE estado = 'ACTIVO' ORDER BY anio DESC, nombre ASC");
while ($r = $rs->fetch_assoc()) $tipos[] = $r;

function obtenerFichas($conn, $id_usuario, $q, $tipo, $estadoFiltro, $estadosPermitidos): mysqli_result {
    $where  = ["r.activo = 1"];
    $params = [$id_usuario];
    $types  = 'i';

    $placeholders = implode(',', array_fill(0, count($estadosPermitidos), '?'));
    $where[] = "r.estado IN ($placeholders)";
    foreach ($estadosPermitidos as $e) { $params[] = $e; $types .= 's'; }

    if ($q !== '') {
        $like    = '%' . $q . '%';
        $where[] = "(i.nombre_ie LIKE ? OR i.codigo_local LIKE ? OR IFNULL(i.codigo_institucion,'') LIKE ? OR u.dni LIKE ? OR CONCAT(u.nombres,' ',u.ape_pat,' ',IFNULL(u.ape_mat,'')) LIKE ? OR tf.nombre LIKE ? OR r.codigo_envio LIKE ?)";
        for ($i = 0; $i < 7; $i++) { $params[] = $like; $types .= 's'; }
    }
    if ($tipo > 0) { $where[] = "r.id_tipo_ficha = ?"; $params[] = $tipo; $types .= 'i'; }
    if ($estadoFiltro !== '' && in_array($estadoFiltro, $estadosPermitidos, true)) {
        $where[] = "r.estado = ?"; $params[] = $estadoFiltro; $types .= 's';
    }

    $whereSql = implode(' AND ', $where);
    $sql = "SELECT r.id, r.codigo_envio, r.estado, r.fecha_envio, r.porcentaje, r.escala_valorativa, r.observacion_general,
                   tf.nombre AS tipo_ficha, tf.anio,
                   i.nombre_ie, i.codigo_local, IFNULL(i.codigo_institucion,'') AS codigo_institucion, i.distrito,
                   u.nombres, u.ape_pat, u.ape_mat, u.dni,
                   docf.ruta_archivo AS ruta_pdf, docc.ruta_archivo AS ruta_constancia,
                   doce.ruta_archivo AS ruta_pdf_evaluada, docce.ruta_archivo AS ruta_constancia_evaluacion
            FROM fm_registros r
            INNER JOIN asignaciones_especialistas ae ON ae.id_institucion = r.id_institucion AND ae.id_usuario = ? AND ae.estado = 'activo'
            INNER JOIN fm_tipos_ficha tf ON tf.id = r.id_tipo_ficha
            INNER JOIN instituciones i ON i.id = r.id_institucion
            INNER JOIN usuarios u ON u.id = r.id_usuario
            LEFT JOIN fm_documentos docf  ON docf.id_registro  = r.id AND docf.tipo_documento  = 'PDF_FICHA_DIRECTOR'
            LEFT JOIN fm_documentos docc  ON docc.id_registro  = r.id AND docc.tipo_documento  = 'CONSTANCIA_ENVIO_DIRECTOR'
            LEFT JOIN fm_documentos doce  ON doce.id_registro  = r.id AND doce.tipo_documento  = 'PDF_FICHA_EVALUADA'
            LEFT JOIN fm_documentos docce ON docce.id_registro = r.id AND docce.tipo_documento = 'CONSTANCIA_EVALUACION'
            WHERE $whereSql
            ORDER BY r.fecha_envio DESC, r.id DESC
            LIMIT 100";

    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    return $st->get_result();
}

$fichas            = obtenerFichas($conn, $id_usuario, $q, $tipo, $estado, ['ENVIADO', 'CONFORME', 'EN_REVISION']);
$fichas_array      = [];
while ($row = $fichas->fetch_assoc()) {
    $fichas_array[] = $row;
}
$fichas_observadas = obtenerFichas($conn, $id_usuario, $q, $tipo, '', ['OBSERVADO', 'RECHAZADO']);
$fichas_revisadas  = obtenerFichas($conn, $id_usuario, $q, $tipo, '', ['REVISADO']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Fichas de Monitoreo - Especialista';
    include 'components/head.php';
    ?>
    <style>
        /* ── Secciones ── */
        .section-card { margin-bottom: 2.5rem; }

        .section-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 0.92rem;
            color: #fff;
            border-radius: var(--ugel-radius-card) var(--ugel-radius-card) 0 0;
        }
        .section-card-header.green  { background: var(--ugel-green-dark); }
        .section-card-header.orange { background: var(--ugel-orange); }

        .count-pill {
            margin-left: auto;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 2px 10px;
            font-size: 0.78rem;
            font-weight: 500;
        }

        /* Contenido directo bajo el header */
        .section-body {
            background: var(--ugel-surface);
            border-radius: 0 0 var(--ugel-radius-card) var(--ugel-radius-card);
            padding: 20px 24px;
        }
        .section-table {
            background: var(--ugel-surface);
            border-radius: 0 0 var(--ugel-radius-card) var(--ugel-radius-card);
            overflow: hidden;
        }

        /* ── Tablas ── */
        .ugel-table tbody td { vertical-align: middle; }
        .ie-name    { font-weight: 600; font-size: 0.9rem; color: #0f172a; }
        .ie-meta    { font-size: 0.78rem; color: var(--ugel-text-muted); margin-top: 1px; }
        .dir-name   { font-size: 0.88rem; color: #0f172a; }
        .ficha-tipo { font-weight: 600; font-size: 0.88rem; }
        .ficha-meta { font-size: 0.78rem; color: var(--ugel-text-muted); margin-top: 1px; }
        .motivo-cell { max-width: 280px; white-space: normal; font-size: 0.83rem; color: var(--ugel-text-muted); line-height: 1.4; }

        /* ── Modal ── */
        .modal-xl { --bs-modal-width: 1100px; }
        .pdf-frame {
            width: 100%;
            height: 68vh;
            border: 1px solid var(--ugel-border);
            border-radius: var(--ugel-radius-card);
            background: var(--ugel-bg);
        }
        /* ── Formulario de revisión dentro del modal ── */
        .review-form .form-label { font-size: 0.88rem; margin-bottom: 4px; }
        .review-form textarea { resize: vertical; min-height: 80px; }
        .review-form .btn-guardar-revision {
            min-width: 130px;
            padding: 10px 20px;
            font-size: 0.92rem;
        }
        /* ── Bloqueo de formulario hasta ver PDF ── */
        .review-lock-wrapper { position: relative; }
        .review-lock-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(3px);
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border-radius: 8px;
            text-align: center;
            padding: 20px;
            transition: opacity 0.35s ease;
        }
        .review-lock-overlay.unlocked {
            opacity: 0;
            pointer-events: none;
            display: none !important;
        }
        .review-lock-overlay .lock-icon { font-size: 2rem; color: var(--ugel-green-dark); }
        .review-lock-overlay .lock-msg  { font-size: 0.88rem; color: #374151; font-weight: 600; line-height: 1.4; }
        /* Pulso animado en el botón rojo de PDF */
        .btn-pdf-required {
            animation: pdf-pulse 1.8s ease-in-out infinite;
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.5);
        }
        @keyframes pdf-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.55); }
            60%  { box-shadow: 0 0 0 10px rgba(220, 53, 69, 0); }
            100% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0); }
        }
        /* Al hacer clic, se detiene el pulso */
        .btn-pdf-required.pdf-opened {
            animation: none;
            background-color: #198754 !important;
            border-color: #198754 !important;
        }
        @media (max-width: 768px) {
            .table { font-size: 0.84rem; }
            .pdf-frame { display: none !important; }
            .modal-xl {
                --bs-modal-width: 100vw !important;
                margin: 0 !important;
                max-width: 100vw !important;
            }
            .modal-dialog {
                margin: 0 !important;
                max-width: 100vw !important;
                border-radius: 0 !important;
            }
            .modal-content {
                border-radius: 0 !important;
                min-height: 100dvh;
            }
            .modal-body { padding: 14px !important; }
            .modal-header { padding: 12px 14px !important; }
            .modal-footer { padding: 10px 14px !important; flex-wrap: wrap; gap: 6px; }
            .modal-footer .btn { width: 100%; }
            /* Formulario revisión: apilado vertical en móvil */
            .review-form .row { flex-direction: column; }
            .review-form .col-12,
            .review-form [class*='col-md'] { width: 100% !important; flex: 0 0 100% !important; max-width: 100% !important; }
            .review-form textarea { min-height: 90px; font-size: 0.95rem; }
            .review-form .form-select { font-size: 0.95rem; padding: 10px 12px; }
            .review-form .btn-guardar-revision { width: 100% !important; padding: 12px; font-size: 1rem; margin-top: 4px; }
            .section-card { border-radius: 0 !important; }
            .section-card-header { font-size: 0.85rem !important; padding: 10px 14px !important; }


            /* Hacer la tabla totalmente responsive transformándola en bloques/tarjetas */
            .table-responsive {
                overflow-x: visible !important;
                border: none !important;
            }
            .ugel-table thead {
                display: none;
            }
            .ugel-table, 
            .ugel-table tbody, 
            .ugel-table tr, 
            .ugel-table td {
                display: block;
                width: 100% !important;
            }
            .ugel-table tr {
                margin-bottom: 1.25rem;
                border: 1px solid var(--ugel-border, #e2e8f0);
                border-radius: var(--ugel-radius-card, 8px);
                padding: 12px 16px;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            }
            .ugel-table td {
                text-align: left !important;
                padding: 8px 0 !important;
                border: none;
                border-bottom: 1px solid #f1f5f9;
            }
            .ugel-table td:last-child {
                border-bottom: none;
                padding-bottom: 0 !important;
            }
            /* Mostrar el título de la columna usando data-label */
            .ugel-table td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 0.72rem;
                font-weight: 700;
                text-transform: uppercase;
                color: var(--ugel-text-muted, #64748b);
                margin-bottom: 3px;
                letter-spacing: 0.5px;
            }
            
            /* Ajustes para celdas simples y de acciones: se muestran alineadas a la derecha, etiqueta a la izquierda */
            .ugel-table td[data-label="Fecha envío"],
            .ugel-table td[data-label="Estado"],
            .ugel-table td[data-label="Ficha"],
            .ugel-table td[data-label="Constancia"],
            .ugel-table td[data-label="Acciones"],
            .ugel-table td[data-label="PDF evaluado"],
            .ugel-table td[data-label="Constancia evaluación"],
            .ugel-table td[data-label="Informe Word"] {
                display: flex !important;
                justify-content: space-between;
                align-items: center;
                flex-wrap: wrap;
                gap: 8px;
                width: 100% !important;
                padding: 8px 0 !important;
                border-bottom: 1px solid #f1f5f9;
            }
            .ugel-table td[data-label="Fecha envío"]::before,
            .ugel-table td[data-label="Estado"]::before,
            .ugel-table td[data-label="Ficha"]::before,
            .ugel-table td[data-label="Constancia"]::before,
            .ugel-table td[data-label="Acciones"]::before,
            .ugel-table td[data-label="PDF evaluado"]::before,
            .ugel-table td[data-label="Constancia evaluación"]::before,
            .ugel-table td[data-label="Informe Word"]::before {
                display: inline-block !important;
                margin-bottom: 0 !important;
            }
            .motivo-cell {
                max-width: none !important;
            }
        }

        /* Reglas específicas para pantallas muy delgadas (celulares) */
        @media (max-width: 480px) {
            .ugel-shell,
            .dashboard-shell {
                width: calc(100% - 16px) !important;
                padding-left: 8px !important;
                padding-right: 8px !important;
            }
            .section-body {
                padding: 12px 16px !important;
            }
            .ugel-table tr {
                padding: 8px 12px !important;
            }
            .ie-name {
                font-size: 0.85rem !important;
            }
            .dir-name, .ficha-tipo {
                font-size: 0.82rem !important;
            }
            .ie-meta, .ficha-meta {
                font-size: 0.74rem !important;
            }
            .ugel-brand-title {
                font-size: 0.85rem !important;
            }
            .ugel-brand-subtitle {
                display: none !important;
            }
            .ugel-brand-icon {
                width: 30px !important;
                height: 30px !important;
                font-size: 1rem !important;
            }
            .ugel-topbar, .navbar-custom {
                min-height: 52px !important;
            }
            .container-fluid {
                padding-left: 8px !important;
                padding-right: 8px !important;
            }
        }
    </style>
</head>
<body>

<!-- ───────────────────── TOPBAR ───────────────────── -->
<nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top">
    <div class="container-fluid px-3 px-md-4">
        <!-- Hamburger (mobile) -->
        <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_especialista.php">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Especialista</small>
            </div>
        </a>

        <div class="ms-auto d-flex align-items-center gap-2">
            <a href="dashboard_especialista.php" class="btn btn-sm d-none d-md-inline-flex align-items-center gap-1"
               style="background:rgba(255,255,255,0.12);color:#fff;border:0;border-radius:5px;font-size:0.84rem;">
                <i class="bi bi-house-door"></i> Inicio
            </a>
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Especialista</small>
                        <strong><?= $nombre_corto ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2">
                    <li class="text-center mb-4">
                        <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                        <h6 class="fw-bold mb-1"><?= h($nombre) ?></h6>
                        <small><?= h($user['dni'] ?? '') ?></small>
                    </li>
                    <li><hr class="dropdown-divider my-2"></li>
                    <li>
                        <a class="btn btn-outline-primary ugel-menu-action w-100 fw-bold mb-2" href="panel_usuario.php">
                            <i class="bi bi-person"></i> Perfil
                        </a>
                    </li>
                    <li>
                        <a class="btn btn-outline-danger ugel-menu-action w-100 fw-bold" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- App Shell con Sidebar -->
<div class="ugel-app-shell">
    <?php
    $sidebar_role   = 'especialista';
    $sidebar_active = 'fichas';
    $sidebar_label  = 'Especialista UGEL';
    include 'components/sidebar.php';
    ?>

    <main class="ugel-main-content">

    <!-- Encabezado de página -->
    <div class="mb-4 px-1 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
        <div>
            <a href="dashboard_especialista.php" class="d-inline-flex align-items-center gap-1 mb-2 text-decoration-none"
               style="font-size:0.88rem;color:var(--ugel-green-dark);font-weight:500;">
                <i class="bi bi-arrow-left"></i> Volver al panel
            </a>
            <h3 class="ugel-section-title fw-bold mb-1">
                <i class="bi bi-clipboard2-check me-2"></i>Fichas de Monitoreo
            </h3>
            <p class="text-muted mb-0" style="font-size:0.93rem;">
                Busca y revisa las fichas enviadas por las instituciones asignadas.
            </p>
        </div>
        <div>
            <a href="vista_reportes.php" class="btn btn-blue d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold"
               style="border-radius: 6px !important; font-size: 0.9rem;">
                <i class="bi bi-bar-chart-line-fill"></i> Generar Reporte
            </a>
        </div>
    </div>

    <!-- Alertas -->
    <?php if ($mensaje): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-check-circle-fill"></i> <?= h($mensaje) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
            <i class="bi bi-exclamation-triangle-fill"></i> <?= h($error) ?>
        </div>
    <?php endif; ?>

    <!-- ── FILTROS ── -->
    <div class="section-card mb-4">
        <div class="section-card-header green">
            <i class="bi bi-funnel-fill"></i> Filtros de búsqueda
        </div>
        <div class="section-body">
            <form class="row g-2 align-items-end" method="GET">
                <div class="col-12 col-md-5">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.84rem;">Búsqueda</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="q" class="form-control" value="<?= h($q) ?>"
                               placeholder="I.E., DNI, código modular o código de envío">
                    </div>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.84rem;">Tipo de ficha</label>
                    <select name="tipo" class="form-select">
                        <option value="0">Todas las fichas</option>
                        <?php foreach ($tipos as $t): ?>
                            <option value="<?= (int)$t['id'] ?>" <?= $tipo === (int)$t['id'] ? 'selected' : '' ?>>
                                <?= h($t['nombre']) ?> - <?= h($t['anio']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label fw-semibold mb-1" style="font-size:0.84rem;">Estado</label>
                    <select name="estado" class="form-select">
                        <option value="">Todos</option>
                        <option value="ENVIADO"     <?= $estado === 'ENVIADO'     ? 'selected' : '' ?>>Enviado</option>
                        <option value="EN_REVISION" <?= $estado === 'EN_REVISION' ? 'selected' : '' ?>>En revisión</option>
                        <option value="CONFORME"    <?= $estado === 'CONFORME'    ? 'selected' : '' ?>>Conforme</option>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button class="btn btn-success flex-grow-1" type="submit">
                        <i class="bi bi-search me-1"></i> Buscar
                    </button>
                    <a href="especialista_fichas.php" class="btn btn-outline-secondary" title="Limpiar">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── TABLA 1: Fichas pendientes / conformes / en revisión ── -->
    <div class="section-card">
        <div class="section-card-header green">
            <i class="bi bi-hourglass-split"></i> Fichas pendientes, conformes o en revisión
            <span class="count-pill"><?= count($fichas_array) ?> registro<?= count($fichas_array) !== 1 ? 's' : '' ?></span>
        </div>
        <div class="section-table table-responsive">
            <table class="table table-hover align-middle mb-0 ugel-table">
                <thead>
                    <tr>
                        <th>Institución Educativa</th>
                        <th>Director / Subdirector</th>
                        <th>Tipo de ficha</th>
                        <th>Fecha envío</th>
                        <th>Estado</th>
                        <th class="text-center">Ficha</th>
                        <th class="text-center">Constancia</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($fichas_array) === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                                No se encontraron fichas enviadas, conformes o en revisión para sus instituciones asignadas.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($fichas_array as $f):
                        $mid = 'modalFicha' . (int)$f['id'];
                    ?>
                        <tr>
                            <td data-label="Institución Educativa">
                                <div class="ie-name"><?= h($f['nombre_ie']) ?></div>
                                <div class="ie-meta">
                                    Cód. <?= h($f['codigo_local'] ?: $f['codigo_institucion']) ?>
                                    &bull; <?= h($f['distrito']) ?>
                                </div>
                            </td>
                            <td data-label="Director / Subdirector">
                                <div class="dir-name"><?= h(trim($f['nombres'] . ' ' . $f['ape_pat'] . ' ' . $f['ape_mat'])) ?></div>
                                <div class="ie-meta">DNI: <?= h($f['dni']) ?></div>
                            </td>
                            <td data-label="Tipo de ficha">
                                <div class="ficha-tipo"><?= h($f['tipo_ficha']) ?></div>
                                <div class="ficha-meta">Año <?= h($f['anio']) ?> &bull; <?= h($f['codigo_envio']) ?></div>
                            </td>
                            <td data-label="Fecha envío" style="white-space:nowrap;font-size:0.86rem;"><?= h($f['fecha_envio']) ?></td>
                            <td data-label="Estado">
                                <span class="badge <?= badgeClase($f['estado']) ?>">
                                    <?= h($f['estado']) ?>
                                </span>
                            </td>
                            <td data-label="Ficha" class="text-center">
                                <a class="btn btn-sm <?= $f['ruta_pdf'] ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                   href="revision_documental.php?id=<?= (int)$f['id'] ?>"
                                   title="<?= $f['ruta_pdf'] ? 'Ver documento de ficha' : 'Revisión documental' ?>">
                                    <i class="bi bi-eye"></i> Ver
                                </a>
                            </td>
                            <td data-label="Constancia" class="text-center">
                                <?php if ($f['ruta_constancia']): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($f['ruta_constancia']) ?>" target="_blank">
                                        <i class="bi bi-file-earmark-pdf"></i> PDF
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Acciones" class="text-center">
                                <?php if ($f['estado'] === 'ENVIADO'): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="revision_documental.php?id=<?= (int)$f['id'] ?>"
                                       title="Revisar documentación y aprobar">
                                        <i class="bi bi-clipboard-check"></i> Revisar
                                    </a>
                                <?php elseif ($f['estado'] === 'CONFORME'): ?>
                                    <a class="btn btn-sm btn-success" href="evaluar_ficha.php?id=<?= (int)$f['id'] ?>">
                                        <i class="bi bi-clipboard2-check"></i> Evaluar
                                    </a>
                                <?php elseif ($f['estado'] === 'EN_REVISION'): ?>
                                    <a class="btn btn-sm btn-success" href="evaluar_ficha.php?id=<?= (int)$f['id'] ?>">
                                        <i class="bi bi-arrow-repeat"></i> Continuar
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- ── TABLA 2: Fichas observadas / rechazadas ── -->
    <div class="section-card">
        <div class="section-card-header orange">
            <i class="bi bi-exclamation-diamond-fill"></i> Fichas observadas o rechazadas
            <span class="count-pill"><?= $fichas_observadas->num_rows ?> registro<?= $fichas_observadas->num_rows !== 1 ? 's' : '' ?></span>
        </div>
        <div class="section-table table-responsive">
            <table class="table table-hover align-middle mb-0 ugel-table">
                <thead>
                    <tr>
                        <th>Institución Educativa</th>
                        <th>Director / Subdirector</th>
                        <th>Tipo de ficha</th>
                        <th>Fecha envío</th>
                        <th>Estado</th>
                        <th>Motivo</th>
                        <th class="text-center">Ficha</th>
                        <th class="text-center">Constancia</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($fichas_observadas->num_rows === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-check2-circle fs-2 d-block mb-2 opacity-50"></i>
                                No hay fichas observadas o rechazadas para mostrar.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php while ($f = $fichas_observadas->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Institución Educativa">
                                <div class="ie-name"><?= h($f['nombre_ie']) ?></div>
                                <div class="ie-meta">
                                    Cód. <?= h($f['codigo_local'] ?: $f['codigo_institucion']) ?>
                                    &bull; <?= h($f['distrito']) ?>
                                </div>
                            </td>
                            <td data-label="Director / Subdirector">
                                <div class="dir-name"><?= h(trim($f['nombres'] . ' ' . $f['ape_pat'] . ' ' . $f['ape_mat'])) ?></div>
                                <div class="ie-meta">DNI: <?= h($f['dni']) ?></div>
                            </td>
                            <td data-label="Tipo de ficha">
                                <div class="ficha-tipo"><?= h($f['tipo_ficha']) ?></div>
                                <div class="ficha-meta">Año <?= h($f['anio']) ?> &bull; <?= h($f['codigo_envio']) ?></div>
                            </td>
                            <td data-label="Fecha envío" style="white-space:nowrap;font-size:0.86rem;"><?= h($f['fecha_envio']) ?></td>
                            <td data-label="Estado">
                                <span class="badge <?= badgeClase($f['estado']) ?>">
                                    <?= h($f['estado']) ?>
                                </span>
                            </td>
                            <td data-label="Motivo" class="motivo-cell"><?= h($f['observacion_general'] ?: 'Sin motivo registrado') ?></td>
                            <td data-label="Ficha" class="text-center">
                                <?php if ($f['ruta_pdf']): ?>
                                    <a class="btn btn-sm btn-outline-success"
                                       href="revision_documental.php?id=<?= (int)$f['id'] ?>">
                                        <i class="bi bi-eye"></i> Ver
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Constancia" class="text-center">
                                <?php if ($f['ruta_constancia']): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($f['ruta_constancia']) ?>" target="_blank">
                                        <i class="bi bi-file-earmark-pdf"></i> PDF
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

    <!-- ── TABLA 3: Fichas revisadas / finalizadas ── -->
    <div class="section-card mb-0">
        <div class="section-card-header green">
            <i class="bi bi-patch-check-fill"></i> Fichas con evaluación finalizada
            <span class="count-pill"><?= $fichas_revisadas->num_rows ?> registro<?= $fichas_revisadas->num_rows !== 1 ? 's' : '' ?></span>
        </div>
        <div class="section-table table-responsive">
            <table class="table table-hover align-middle mb-0 ugel-table">
                <thead>
                    <tr>
                        <th>Institución Educativa</th>
                        <th>Director / Subdirector</th>
                        <th>Tipo de ficha</th>
                        <th>Fecha envío</th>
                        <th>Estado</th>
                        <th class="text-center">PDF evaluado</th>
                        <th class="text-center">Constancia evaluación</th>
                        <th class="text-center">Informe Word</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($fichas_revisadas->num_rows === 0): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                <i class="bi bi-journal-check fs-2 d-block mb-2 opacity-50"></i>
                                No hay fichas revisadas o finalizadas para mostrar.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php while ($f = $fichas_revisadas->fetch_assoc()): ?>
                        <tr>
                            <td data-label="Institución Educativa">
                                <div class="ie-name"><?= h($f['nombre_ie']) ?></div>
                                <div class="ie-meta">
                                    Cód. <?= h($f['codigo_local'] ?: $f['codigo_institucion']) ?>
                                    &bull; <?= h($f['distrito']) ?>
                                </div>
                            </td>
                            <td data-label="Director / Subdirector">
                                <div class="dir-name"><?= h(trim($f['nombres'] . ' ' . $f['ape_pat'] . ' ' . $f['ape_mat'])) ?></div>
                                <div class="ie-meta">DNI: <?= h($f['dni']) ?></div>
                            </td>
                            <td data-label="Tipo de ficha">
                                <div class="ficha-tipo"><?= h($f['tipo_ficha']) ?></div>
                                <div class="ficha-meta">Año <?= h($f['anio']) ?> &bull; <?= h($f['codigo_envio']) ?></div>
                            </td>
                            <td data-label="Fecha envío" style="white-space:nowrap;font-size:0.86rem;"><?= h($f['fecha_envio']) ?></td>
                            <td data-label="Estado">
                                <span class="badge <?= badgeClase($f['estado']) ?>">
                                    <?= h($f['estado']) ?>
                                </span>
                            </td>
                            <td data-label="PDF evaluado" class="text-center">
                                <?php if ($f['ruta_pdf_evaluada']): ?>
                                    <a class="btn btn-sm btn-outline-success" href="<?= h($f['ruta_pdf_evaluada']) ?>" target="_blank">
                                        <i class="bi bi-eye"></i> Ver
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Constancia evaluación" class="text-center">
                                <?php if ($f['ruta_constancia_evaluacion']): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= h($f['ruta_constancia_evaluacion']) ?>" target="_blank">
                                        <i class="bi bi-file-earmark-pdf"></i> PDF
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td data-label="Informe Word" class="text-center">
                                <a class="btn btn-sm btn-outline-primary" href="generar_informe_docx.php?id_registro=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-word"></i> Generar
                                </a>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
</div><!-- /.ugel-app-shell -->

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</script>
</body>
</html>
