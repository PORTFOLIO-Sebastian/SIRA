<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) { header('Location: login.php'); exit(); }
if ($_SESSION['rol'] !== 'especialista') { header('Location: dashboard.php'); exit(); }

$id_usuario = (int)$_SESSION['id_usuario'];
$stmt = $conn->prepare("SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni,
                               COUNT(DISTINCT ae.id_institucion) AS total_ie
                        FROM usuarios u
                        LEFT JOIN asignaciones_especialistas ae ON ae.id_usuario = u.id AND ae.estado = 'activo'
                        WHERE u.id = ? GROUP BY u.id");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nombre       = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = htmlspecialchars($user['nombres'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
$total_ie     = (int)($user['total_ie'] ?? 0);

$hora = (int)date('H');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

$dias_semana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
$fecha_es = $dias_semana[date('l')] . ', ' . date('d') . ' de ' . $meses[date('F')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Panel del Especialista - Sistema IE';
    include 'components/head.php';
    ?>
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

        <div class="ms-auto">
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
                        <h6 class="fw-bold mb-1"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></h6>
                        <small><?= htmlspecialchars($user['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                    </li>
                    <li class="px-2">
                        <p class="info-label">DNI</p>
                        <p class="info-value"><?= htmlspecialchars($user['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="info-label">IE Asignadas</p>
                        <p class="info-value fw-bold" style="color: var(--ugel-green-dark);"><?= $total_ie ?></p>
                    </li>
                    <li><hr class="dropdown-divider my-3"></li>
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

<!-- ───────────────────── APP SHELL ───────────────────── -->
<div class="ugel-app-shell">
    <?php
    $sidebar_role   = 'especialista';
    $sidebar_active = 'dashboard';
    $sidebar_label  = 'Especialista · ' . $total_ie . ' IE';
    include 'components/sidebar.php';
    ?>

    <!-- ── Contenido principal ── -->
    <main class="ugel-main-content">

        <!-- Bienvenida -->
        <div class="ugel-welcome-shell">
            <div>
                <div class="ugel-welcome-shell__greeting">
                    <i class="bi bi-calendar3"></i> <?= $fecha_es ?>
                </div>
                <h2 class="ugel-welcome-shell__name"><?= $saludo ?>, <?= $nombre_corto ?> 👋</h2>
                <p class="ugel-welcome-shell__desc">Gestiona tus instituciones educativas y revisa los documentos asignados.</p>
            </div>
            <div class="ugel-stat-chip">
                <i class="bi bi-building"></i>
                <span><strong><?= $total_ie ?></strong> IE asignada<?= $total_ie !== 1 ? 's' : '' ?></span>
            </div>
        </div>

        <!-- Cabecera -->
        <div class="ugel-page-header mb-4">
            <div>
                <h1 class="ugel-page-title">Módulos disponibles</h1>
                <p class="ugel-page-subtitle">Selecciona un módulo para continuar</p>
            </div>
            <span class="badge ugel-context-badge">Especialista</span>
        </div>

        <!-- Tarjetas de módulos -->
        <div class="row g-4">

            <!-- Módulo Documentos -->
            <div class="col-12 col-md-4">
                <div class="card-ref ugel-module-card is-blue h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-folder-check"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Documentos
                            <div class="ugel-module-card__subtitle">Envíos y archivos</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Revisión de envíos documentarios, oficios, comunicados y archivos del sistema. Accede al historial completo de documentos asignados a tus instituciones.
                    </div>
                    <a href="ver_envios.php" class="card-ref-btn ugel-module-card__action btn-blue">
                        Ir a Documentos <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Módulo Fichas de Monitoreo -->
            <div class="col-12 col-md-4">
                <div class="card-ref ugel-module-card is-green h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-clipboard2-check"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Fichas de Monitoreo
                            <div class="ugel-module-card__subtitle">Evaluación institucional</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Búsqueda, revisión, evaluación y administración de fichas de monitoreo institucional. Genera reportes y realiza seguimiento a cada institución asignada.
                    </div>
                    <a href="especialista_fichas.php" class="card-ref-btn ugel-module-card__action btn-green-module">
                        Ir a Fichas <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Módulo Proyectos de Innovación -->
            <div class="col-12 col-md-4">
                <div class="card-ref ugel-module-card is-red h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-lightbulb"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Proyectos de Innovación
                            <div class="ugel-module-card__subtitle">FONDEP / MINEDU</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Revisión, dictamen técnico y supervisión de las propuestas de innovación pedagógica presentadas por las instituciones educativas asignadas.
                    </div>
                    <a href="especialista_proyectos.php" class="card-ref-btn ugel-module-card__action btn-red">
                        Ir a Proyectos <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

        </div>
    </main><!-- /.ugel-main-content -->
</div><!-- /.ugel-app-shell -->

<style>
.ugel-stat-chip {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: var(--ugel-green-light);
    border: 1px solid var(--ugel-green-border);
    border-radius: 6px;
    padding: 7px 14px;
    font-size: 0.88rem;
    color: var(--ugel-green-dark);
    white-space: nowrap;
}
.ugel-stat-chip i { font-size: 1rem; }
.ugel-module-card__subtitle {
    font-size: 0.78rem;
    font-weight: 400;
    color: var(--ugel-text-muted);
    margin-top: 2px;
    line-height: 1.3;
}
</style>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
