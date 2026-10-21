<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$user         = jefe_user($conn);
$nombre       = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = htmlspecialchars($user['nombres'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Ficha de Monitoreo - Jefe de AGP';
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
                <small class="ugel-brand-subtitle">Sistema IE · Ficha de Monitoreo</small>
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

<!-- App Shell con Sidebar -->
<div class="ugel-app-shell">
    <?php
    $sidebar_role   = 'jefe_agp';
    $sidebar_active = 'fichas';
    $sidebar_label  = 'UGEL Chincha · AGP';
    include 'components/sidebar.php';
    ?>

    <main class="ugel-main-content">

    <!-- Breadcrumb / volver -->
    <div class="ugel-breadcrumb mb-4 px-1">
        <a href="dashboard_jefe_agp.php" class="ugel-back-link">
            <i class="bi bi-arrow-left me-1"></i>Panel Jefe de AGP
        </a>
    </div>

    <!-- Hero de sección -->
    <div class="ugel-welcome mb-4 mb-md-5 px-1">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <h3 class="fw-bold mb-1" style="color: #0f172a; font-size: 1.55rem;">
                    <i class="bi bi-clipboard2-data me-2" style="color: var(--ugel-green-dark);"></i>Ficha de Monitoreo
                </h3>
                <p class="text-muted mb-0" style="font-size: 0.93rem;">
                    Seleccione la función que desea realizar.
                </p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <div class="ugel-stat-chip">
                    <i class="bi bi-person-workspace"></i>
                    <span><strong>Jefe de AGP</strong></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Cabecera de sección -->
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <h5 class="ugel-section-title fw-bold mb-0">
            <i class="bi bi-grid-3x3-gap-fill me-2"></i>Opciones disponibles
        </h5>
        <span class="badge ugel-context-badge">Monitoreo</span>
    </div>

    <!-- Tarjetas de módulos -->
    <div class="row g-4 justify-content-center">

        <!-- Búsqueda / Visualización -->
        <div class="col-12 col-sm-6">
            <div class="card-ref ugel-module-card is-blue h-100">
                <div class="card-ref-header ugel-module-card__header">
                    <div class="header-icon-box ugel-module-card__icon">
                        <i class="bi bi-search"></i>
                    </div>
                    <div class="ugel-module-card__title">
                        Búsqueda / Visualización
                        <div class="ugel-module-card__subtitle">Consulta de registros</div>
                    </div>
                </div>
                <div class="card-ref-body ugel-module-card__body">
                    Visualizar autoevaluaciones de directores y evaluaciones de especialistas. Filtra, consulta y descarga fichas de monitoreo.
                </div>
                <a href="jefe_ficha_busqueda_menu.php" class="card-ref-btn ugel-module-card__action btn-blue">
                    Acceder <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        <!-- Gestión / Creación de Fichas -->
        <div class="col-12 col-sm-6">
            <div class="card-ref ugel-module-card is-green h-100">
                <div class="card-ref-header ugel-module-card__header">
                    <div class="header-icon-box ugel-module-card__icon">
                        <i class="bi bi-ui-checks-grid"></i>
                    </div>
                    <div class="ugel-module-card__title">
                        Gestión / Creación de Fichas
                        <div class="ugel-module-card__subtitle">Estructura y rúbricas</div>
                    </div>
                </div>
                <div class="card-ref-body ugel-module-card__body">
                    Crear fichas, editar estructura, rúbricas, versiones y prórrogas del proceso de monitoreo institucional.
                </div>
                <a href="jefe_crear_fichas.php" class="card-ref-btn ugel-module-card__action btn-green-module">
                    Acceder <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

    </div>
</main>
</div><!-- /.ugel-app-shell -->

<style>
.ugel-breadcrumb {
    display: flex;
    align-items: center;
}

.ugel-back-link {
    display: inline-flex;
    align-items: center;
    font-size: 0.88rem;
    font-weight: 600;
    color: var(--ugel-green-dark);
    text-decoration: none;
    gap: 4px;
    padding: 4px 10px 4px 6px;
    border-radius: 6px;
    transition: background 0.15s;
}

.ugel-back-link:hover {
    background: var(--ugel-green-light);
    color: var(--ugel-green-dark);
}

.ugel-welcome {
    border-bottom: 1px solid var(--ugel-border);
    padding-bottom: 1.5rem;
}

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

.ugel-stat-chip i {
    font-size: 1rem;
}

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
