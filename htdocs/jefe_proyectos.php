<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = (int) $_SESSION['id_usuario'];
$rol = $_SESSION['rol'] ?? '';

if (!in_array($rol, ['jefe_agp', 'admin'], true)) {
    header("Location: dashboard.php");
    exit();
}

function h($str): string {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
}

// Estadísticas generales de innovación
$stats = [
    'total' => 0,
    'formulacion' => 0,
    'enviados' => 0,
    'aprobados' => 0,
    'inversion_total' => 0.00,
    'estudiantes_impacto' => 0
];

$rs_stats = $conn->query("SELECT 
    COUNT(*) AS total,
    SUM(CASE WHEN estado = 'FORMULACION' THEN 1 ELSE 0 END) AS formulacion,
    SUM(CASE WHEN estado = 'ENVIADO' THEN 1 ELSE 0 END) AS enviados,
    SUM(CASE WHEN estado = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
    SUM(presupuesto_referencial) AS inversion_total,
    SUM(beneficiarios_estudiantes) AS estudiantes_impacto
FROM proyectos_innovacion WHERE activo = 1");

if ($rs_stats && $row = $rs_stats->fetch_assoc()) {
    $stats['total'] = (int) $row['total'];
    $stats['formulacion'] = (int) $row['formulacion'];
    $stats['enviados'] = (int) $row['enviados'];
    $stats['aprobados'] = (int) $row['aprobados'];
    $stats['inversion_total'] = (float) $row['inversion_total'];
    $stats['estudiantes_impacto'] = (int) $row['estudiantes_impacto'];
}

// Filtros
$filtro_estado = $_GET['estado'] ?? 'TODOS';
$filtro_q = trim($_GET['q'] ?? '');

$sql = "SELECT p.*, i.nombre_ie, i.codigo_local, i.distrito,
               u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.dni AS dir_dni,
               esp.nombres AS esp_nombres, esp.ape_pat AS esp_ape_pat
        FROM proyectos_innovacion p
        INNER JOIN instituciones i ON i.id = p.id_institucion
        INNER JOIN usuarios u ON u.id = p.id_usuario_director
        LEFT JOIN usuarios esp ON esp.id = p.id_especialista_asignado
        WHERE p.activo = 1";

$params = [];
$types = "";

if ($filtro_estado !== 'TODOS' && $filtro_estado !== '') {
    $sql .= " AND p.estado = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}

if ($filtro_q !== '') {
    $sql .= " AND (p.titulo LIKE ? OR i.nombre_ie LIKE ? OR p.codigo_proyecto LIKE ?)";
    $like = '%' . $filtro_q . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $types .= "sss";
}

$sql .= " ORDER BY p.id DESC";

if (!empty($params)) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $proyectos = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $rs = $conn->query($sql);
    $proyectos = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
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
    $page_title = 'Supervisión Global de Proyectos de Innovación — Jefe AGP';
    include 'components/head.php'; 
    ?>
    <style>
        .stat-card {
            border: 1px solid var(--ugel-border, #e2e8f0);
            border-radius: 10px;
            background: #fff;
            padding: 16px;
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
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_jefe_agp.php">
                <i class="bi bi-lightbulb-fill text-warning fs-4"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="ugel-brand-title">MONITOREO DE PROYECTOS DE INNOVACIÓN</span>
                    <small class="ugel-brand-subtitle">Área de Gestión Pedagógica · UGEL Chincha</small>
                </div>
            </a>
            <div class="ms-auto d-flex align-items-center gap-2">
                <a href="dashboard_jefe_agp.php" class="btn btn-sm d-flex align-items-center gap-1"
                   style="background:rgba(255,255,255,0.15);color:#fff;border:0;border-radius:6px;">
                    <i class="bi bi-arrow-left"></i> Inicio
                </a>
            </div>
        </div>
    </nav>

    <!-- App Shell con Sidebar -->
    <div class="ugel-app-shell">
        <?php
        $sidebar_role   = 'jefe_agp';
        $sidebar_active = 'proyectos';
        $sidebar_label  = 'UGEL Chincha · AGP';
        include 'components/sidebar.php';
        ?>

        <main class="ugel-main-content">

        <!-- Encabezado -->
        <div class="mb-4">
            <h3 class="fw-bold text-dark mb-1">
                <i class="bi bi-bar-chart-steps text-danger me-2"></i>Tablero Provincial de Innovación Educativa
            </h3>
            <p class="text-muted mb-0">
                Supervisión de proyectos FONDEP, metodologías activas e iniciativas pedagógicas registradas por las II.EE.
            </p>
        </div>

        <!-- Tarjetas de Estadísticas Globales -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-card shadow-sm border-start border-4 border-danger">
                    <small class="text-muted d-block fw-semibold">Proyectos Registrados</small>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['total']) ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card shadow-sm border-start border-4 border-primary">
                    <small class="text-muted d-block fw-semibold">Enviados (En Revisión)</small>
                    <h3 class="fw-bold text-primary mb-0"><?= number_format($stats['enviados']) ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card shadow-sm border-start border-4 border-success">
                    <small class="text-muted d-block fw-semibold">Proyectos Aprobados</small>
                    <h3 class="fw-bold text-success mb-0"><?= number_format($stats['aprobados']) ?></h3>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-card shadow-sm border-start border-4 border-warning">
                    <small class="text-muted d-block fw-semibold">Estudiantes Beneficiados</small>
                    <h3 class="fw-bold text-dark mb-0"><?= number_format($stats['estudiantes_impacto']) ?></h3>
                </div>
            </div>
        </div>

        <!-- Filtros y Búsqueda -->
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-3">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-12 col-md-5">
                        <label class="form-label fw-semibold small mb-1">Buscar propuesta o IE</label>
                        <input type="text" name="q" class="form-control form-control-sm" placeholder="Título, I.E. o código..." value="<?= h($filtro_q) ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label fw-semibold small mb-1">Estado de Proyecto</label>
                        <select name="estado" class="form-select form-select-sm">
                            <option value="TODOS" <?= $filtro_estado === 'TODOS' ? 'selected' : '' ?>>Todos los estados</option>
                            <option value="FORMULACION" <?= $filtro_estado === 'FORMULACION' ? 'selected' : '' ?>>En Formulación</option>
                            <option value="ENVIADO" <?= $filtro_estado === 'ENVIADO' ? 'selected' : '' ?>>Enviado a UGEL</option>
                            <option value="APROBADO" <?= $filtro_estado === 'APROBADO' ? 'selected' : '' ?>>Aprobado</option>
                            <option value="OBSERVADO" <?= $filtro_estado === 'OBSERVADO' ? 'selected' : '' ?>>Observado</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <button type="submit" class="btn btn-danger btn-sm w-100 fw-bold">
                            <i class="bi bi-funnel me-1"></i> Filtrar
                        </button>
                    </div>
                    <div class="col-6 col-md-2">
                        <a href="exportar_proyectos_excel.php?estado=<?= urlencode($filtro_estado) ?>" class="btn btn-outline-success btn-sm w-100 fw-bold">
                            <i class="bi bi-file-earmark-excel-fill me-1"></i> Exportar a Excel
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Tabla Consolidada -->
        <div class="card border-0 shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th>Código</th>
                            <th>Institución Educativa</th>
                            <th>Título del Proyecto</th>
                            <th>Línea Temática</th>
                            <th>Presupuesto</th>
                            <th>Avance</th>
                            <th>Estado</th>
                            <th class="text-center" style="width: 120px;">Ficha Oficial</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($proyectos)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">
                                No se encontraron proyectos de innovación que coincidan con los filtros.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($proyectos as $p): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark border"><?= h($p['codigo_proyecto']) ?></span></td>
                            <td>
                                <strong class="text-dark d-block"><?= h($p['nombre_ie']) ?></strong>
                                <small class="text-muted">Cód. <?= h($p['codigo_local']) ?> &bull; <?= h($p['distrito']) ?></small>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark" style="max-width: 320px;"><?= h($p['titulo']) ?></div>
                                <small class="text-muted">Responsable: <?= h(trim($p['dir_nombres'] . ' ' . $p['dir_ape_pat'])) ?></small>
                            </td>
                            <td>
                                <span class="small text-muted"><?= h($lineas_map[$p['linea_tematica']] ?? $p['linea_tematica']) ?></span>
                            </td>
                            <td class="text-success fw-bold">
                                S/ <?= number_format($p['presupuesto_referencial'], 2) ?>
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
                                <div class="btn-group btn-group-sm">
                                    <a href="proyectos_pdf.php?id=<?= (int)$p['id'] ?>" target="_blank" class="btn btn-outline-danger" title="Ver Ficha Técnica PDF">
                                        <i class="bi bi-file-earmark-pdf-fill"></i> Ver
                                    </a>
                                    <a href="proyectos_pdf.php?id=<?= (int)$p['id'] ?>&download=1" class="btn btn-danger" title="Descargar Ficha Técnica PDF">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </div>
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
