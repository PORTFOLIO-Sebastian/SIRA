<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
include 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$user         = jefe_user($conn);
$nombre       = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = htmlspecialchars($user['nombres'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');

$hora   = (int)date('H');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');

$dias_semana = ['Sunday' => 'Domingo', 'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles', 'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado'];
$meses = ['January' => 'enero', 'February' => 'febrero', 'March' => 'marzo', 'April' => 'abril', 'May' => 'mayo', 'June' => 'junio', 'July' => 'julio', 'August' => 'agosto', 'September' => 'septiembre', 'October' => 'octubre', 'November' => 'noviembre', 'December' => 'diciembre'];
$fecha_es = $dias_semana[date('l')] . ', ' . date('d') . ' de ' . $meses[date('F')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Panel Jefe de AGP - Sistema IE';
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
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_jefe_agp.php">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Jefe de AGP</small>
            </div>
        </a>

        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Jefe de AGP</small>
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
                        <p class="info-label">Rol</p>
                        <p class="info-value fw-bold" style="color: var(--ugel-green-dark);">Jefe de AGP</p>
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
    $sidebar_role   = 'jefe_agp';
    $sidebar_active = 'dashboard';
    $sidebar_label  = 'UGEL Chincha · AGP';
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
                <p class="ugel-welcome-shell__desc">Acceso global a monitoreo, especialistas e instituciones educativas.</p>
            </div>
            <div class="ugel-stat-chip">
                <i class="bi bi-person-workspace"></i>
                <span><strong>Jefe de AGP</strong></span>
            </div>
        </div>

        <!-- Cabecera -->
        <div class="ugel-page-header mb-4">
            <div>
                <h1 class="ugel-page-title">Módulos disponibles</h1>
                <p class="ugel-page-subtitle">Gestión provincial de la UGEL Chincha</p>
            </div>
            <span class="badge ugel-context-badge">Jefe de AGP</span>
        </div>

        <!-- Tarjetas de módulos -->
        <div class="row g-4">

            <!-- Módulo Ficha de Monitoreo -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card-ref ugel-module-card is-green h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-clipboard2-data"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Ficha de Monitoreo
                            <div class="ugel-module-card__subtitle">Gestión y evaluación</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Búsqueda, visualización, evaluación y gestión de fichas de monitoreo institucional a nivel global.
                    </div>
                    <a href="jefe_ficha_menu.php" class="card-ref-btn ugel-module-card__action btn-green-module">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Módulo Gestionar Especialistas -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card-ref ugel-module-card is-blue h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Gestionar Especialistas
                            <div class="ugel-module-card__subtitle">Administración</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Administración de especialistas, asignaciones a instituciones educativas y gestión de sus perfiles.
                    </div>
                    <a href="jefe_gestionar_especialistas.php" class="card-ref-btn ugel-module-card__action btn-blue-module">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Módulo Gestionar I.E. -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card-ref ugel-module-card is-blue h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-building"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Gestionar I.E.
                            <div class="ugel-module-card__subtitle">Instituciones educativas</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Consulta y administración de instituciones educativas, datos de contacto y reportes agregados.
                    </div>
                    <a href="jefe_gestionar_ie.php" class="card-ref-btn ugel-module-card__action btn-blue-module">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <!-- Módulo Proyectos de Innovación -->
            <div class="col-12 col-sm-6 col-lg-3">
                <div class="card-ref ugel-module-card is-red h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-lightbulb"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Proyectos de Innovación
                            <div class="ugel-module-card__subtitle">Supervisión provincial</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Tablero de control y seguimiento a nivel provincial de proyectos pedagógicos y fondos concursables (FONDEP).
                    </div>
                    <a href="jefe_proyectos.php" class="card-ref-btn ugel-module-card__action btn-red">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

        </div>

        <!-- Segunda fila: Vista Reportes y Autoevaluación -->
        <div class="ugel-label-divider mt-4 mb-3">Reportes y evaluación</div>
        <div class="row g-4">

            <div class="col-12 col-md-6">
                <div class="card-ref ugel-module-card is-orange h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-bar-chart-line"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Vista de Reportes
                            <div class="ugel-module-card__subtitle">Estadísticas generales</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Reporte consolidado de envíos documentarios y estado de cumplimiento por institución educativa.
                    </div>
                    <a href="vista_reportes.php" class="card-ref-btn ugel-module-card__action btn-orange">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card-ref ugel-module-card is-yellow h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon">
                            <i class="bi bi-file-earmark-check"></i>
                        </div>
                        <div class="ugel-module-card__title">
                            Autoevaluación Directores
                            <div class="ugel-module-card__subtitle">Seguimiento</div>
                        </div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Revisión de instrumentos de autoevaluación completados por los directores de las instituciones educativas.
                    </div>
                    <a href="jefe_autoevaluacion_directores.php" class="card-ref-btn ugel-module-card__action btn-yellow">
                        Acceder <i class="bi bi-chevron-right"></i>
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
.ugel-card-soon { opacity: 0.72; pointer-events: none; }
</style>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
