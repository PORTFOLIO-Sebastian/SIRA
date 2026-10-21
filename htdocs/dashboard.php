<?php
session_start();
include 'conexion.php'; 

// 1. Validar sesion y proteger ruta
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}
if ($_SESSION['rol'] === 'especialista') {
    header("Location: ver_envios.php");
    exit();
}

// 2. Obtener datos del usuario y su IE
$id_usuario = $_SESSION['id_usuario'];

$query = "SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni, i.id AS id_ie, i.nombre_ie, i.codigo_local 
          FROM usuarios u
          LEFT JOIN asignaciones a ON u.id = a.id_usuario AND a.estado = 'activo'
          LEFT JOIN instituciones i ON a.id_ie = i.id
          WHERE u.id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

if (!empty($user_data['id_ie'])) {
    $_SESSION['id_ie'] = $user_data['id_ie'];
}

$nombre_completo = $user_data['nombres'] . " " . $user_data['ape_pat'] . " " . $user_data['ape_mat'];
$nombre_corto    = htmlspecialchars($user_data['nombres'] ?? 'Director', ENT_QUOTES, 'UTF-8');
$nombre_ie       = $user_data['nombre_ie'] ?? 'Sin Asignar'; 
$codigo_local    = $user_data['codigo_local'] ?? 'N/A';

$hora   = (int)date('H');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$dias_semana = ['Sunday'=>'Domingo','Monday'=>'Lunes','Tuesday'=>'Martes','Wednesday'=>'Miércoles','Thursday'=>'Jueves','Friday'=>'Viernes','Saturday'=>'Sábado'];
$meses = ['January'=>'enero','February'=>'febrero','March'=>'marzo','April'=>'abril','May'=>'mayo','June'=>'junio','July'=>'julio','August'=>'agosto','September'=>'septiembre','October'=>'octubre','November'=>'noviembre','December'=>'diciembre'];
$fecha_es = $dias_semana[date('l')] . ', ' . date('d') . ' de ' . $meses[date('F')] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Inicio – Sistema IE · Director';
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
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Director</small>
            </div>
        </a>

        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Director</small>
                        <strong><?php echo $nombre_corto; ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2 shadow">
                    <li class="text-center mb-4">
                        <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                        <h6 class="fw-bold mb-0 text-success"><?php echo htmlspecialchars($nombre_completo); ?></h6>
                        <small class="text-muted"><?php echo htmlspecialchars($user_data['correo'] ?? ''); ?></small>
                    </li>
                    <li class="px-2">
                        <p class="info-label">DNI</p>
                        <p class="info-value"><?php echo htmlspecialchars($user_data['dni'] ?? ''); ?></p>
                        <p class="info-label">IE</p>
                        <p class="info-value fw-bold" style="color: var(--ugel-green-dark);"><?php echo htmlspecialchars($nombre_ie); ?></p>
                    </li>
                    <li><hr class="dropdown-divider mt-4 mb-3"></li>
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
    $sidebar_role   = 'director';
    $sidebar_active = 'dashboard';
    $sidebar_label  = $nombre_ie;
    include 'components/sidebar.php';
    ?>

    <!-- ── Contenido principal ── -->
    <main class="ugel-main-content">

        <!-- Bienvenida -->
        <div class="ugel-welcome-shell">
            <div>
                <div class="ugel-welcome-shell__greeting">
                    <i class="bi bi-calendar3"></i> <?php echo $fecha_es; ?>
                </div>
                <h2 class="ugel-welcome-shell__name"><?php echo $saludo; ?>, <?php echo $nombre_corto; ?> 👋</h2>
                <p class="ugel-welcome-shell__desc">Gestiona los documentos y módulos de tu institución educativa.</p>
            </div>
            <div class="ugel-stat-chip">
                <i class="bi bi-building"></i>
                <span><strong><?php echo htmlspecialchars($nombre_ie); ?></strong></span>
            </div>
        </div>

        <!-- Cabecera de sección -->
        <div class="ugel-page-header mb-4">
            <div>
                <h1 class="ugel-page-title">Módulos de Envío Digital</h1>
                <p class="ugel-page-subtitle">Selecciona un módulo para continuar</p>
            </div>
        </div>

        <!-- Módulos principales -->
        <div class="row g-4 mb-4">

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-ref ugel-module-card is-orange">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon"><i class="bi bi-calendar-check"></i></div>
                        <div class="header-title-box ugel-module-card__title">Asistencia<br>Mensual</div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Envío mensual de reportes de asistencia en formatos PDF y Excel.
                    </div>
                    <a href="enviar_documento.php?tipo=Mensual" class="card-ref-btn ugel-module-card__action btn-orange">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-ref ugel-module-card is-blue" style="opacity: 0.5; pointer-events: none;">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon"><i class="bi bi-folder-check"></i></div>
                        <div class="header-title-box ugel-module-card__title">Gestión<br>(II.GG/DG)</div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Documentos de gestión anual: PCI, PEI, RI, PAT y otros.
                    </div>
                    <a href="enviar_documento.php?tipo=Gestion" class="card-ref-btn ugel-module-card__action btn-blue">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-ref ugel-module-card is-red">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon"><i class="bi bi-lightbulb"></i></div>
                        <div class="header-title-box ugel-module-card__title">Proyectos de<br>Innovación</div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Registro y seguimiento de Proyectos de Innovación Pedagógica (FONDEP / MINEDU).
                    </div>
                    <a href="director_proyectos.php" class="card-ref-btn ugel-module-card__action btn-red">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-ref ugel-module-card is-yellow" style="opacity: 0.5; pointer-events: none;">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon"><i class="bi bi-file-earmark-bar-graph"></i></div>
                        <div class="header-title-box ugel-module-card__title">Resultados<br>ECRE</div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Envío de informes y resultados de evaluación institucional.
                    </div>
                    <a href="enviar_documento.php?tipo=EPLA" class="card-ref-btn ugel-module-card__action btn-yellow">
                        Acceder <i class="bi bi-chevron-right"></i>
                    </a>
                </div>
            </div>

        </div>

        <!-- Segunda fila -->
        <div class="ugel-label-divider mb-3">Herramientas adicionales</div>
        <div class="row g-3">

            <div class="col-12 col-md-6">
                <div class="ugel-action-card action-banner bg-white border p-3 h-100 d-flex flex-column flex-sm-row align-items-center justify-content-between gap-3">
                    <div class="d-flex align-items-center text-start w-100">
                        <div class="flex-shrink-0 ugel-action-icon rounded d-flex align-items-center justify-content-center me-3">
                            <i class="bi bi-megaphone fs-4"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold text-dark">Normas y Comunicados</h5>
                            <span class="text-muted small d-block ugel-text-tight">Revisión de oficios y normativas.</span>
                        </div>
                    </div>
                    <a href="comunicados.php" class="btn btn-outline-success ugel-action-button fw-bold px-4 py-2 text-nowrap d-flex align-items-center justify-content-center w-100 w-sm-auto">
                        Acceder <i class="bi bi-chevron-right ms-2 fs-6"></i>
                    </a>
                </div>
            </div>

            <div class="col-12 col-md-6">
                <div class="card-ref ugel-module-card is-green card-ref-compact h-100">
                    <div class="card-ref-header ugel-module-card__header">
                        <div class="header-icon-box ugel-module-card__icon"><i class="bi bi-display"></i></div>
                        <div class="header-title-box ugel-module-card__title">Ficha de<br>Monitoreo 2026</div>
                    </div>
                    <div class="card-ref-body ugel-module-card__body">
                        Llenado interno de ficha institucional.
                    </div>
                    <a href="ficha_monitoreo_2026.php" class="card-ref-btn ugel-module-card__action btn-green-module">
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
    max-width: 280px;
    overflow: hidden;
    text-overflow: ellipsis;
}
.ugel-stat-chip i { font-size: 1rem; }
</style>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
