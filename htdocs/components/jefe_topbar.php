<?php
/**
 * Topbar compartida para todas las páginas del Jefe de AGP.
 *
 * Variables requeridas:
 *   $user          — array con datos del usuario (de jefe_user())
 *
 * Variables opcionales:
 *   $page_subtitle — texto que aparece tras "Sistema IE ·"  (default: 'Jefe de AGP')
 */
$page_subtitle   = $page_subtitle ?? 'Jefe de AGP';
$_jt_nombre      = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$_jt_corto       = htmlspecialchars($user['nombres'] ?? 'Usuario', ENT_QUOTES, 'UTF-8');
$_jt_nombre_esc  = htmlspecialchars($_jt_nombre,        ENT_QUOTES, 'UTF-8');
$_jt_correo      = htmlspecialchars($user['correo'] ?? '', ENT_QUOTES, 'UTF-8');
$_jt_dni         = htmlspecialchars($user['dni']    ?? '', ENT_QUOTES, 'UTF-8');
$_jt_subtitle    = htmlspecialchars($page_subtitle,       ENT_QUOTES, 'UTF-8');
?>
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
                <small class="ugel-brand-subtitle">Sistema IE · <?= $_jt_subtitle ?></small>
            </div>
        </a>

        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Jefe de AGP</small>
                        <strong><?= $_jt_corto ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2">
                    <li class="text-center mb-4">
                        <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                        <h6 class="fw-bold mb-1"><?= $_jt_nombre_esc ?></h6>
                        <small><?= $_jt_correo ?></small>
                    </li>
                    <li class="px-2">
                        <p class="info-label">DNI</p>
                        <p class="info-value"><?= $_jt_dni ?></p>
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
