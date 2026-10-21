<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();
$user = jefe_user($conn);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Búsqueda de Fichas - Jefe de AGP';
    include 'components/head.php';
    ?>
</head>
<body>
<?php $page_subtitle = 'Búsqueda / Visualización'; include 'components/jefe_topbar.php'; ?>

<main class="ugel-shell dashboard-shell py-4 py-md-5">

    <div class="mb-4 px-1">
        <a href="jefe_ficha_menu.php" class="ugel-back-link"><i class="bi bi-arrow-left me-1"></i>Ficha de Monitoreo</a>
    </div>

    <!-- Hero de sección -->
    <div class="ugel-welcome mb-4 mb-md-5 px-1">
        <div class="d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
            <div>
                <h3 class="fw-bold mb-1" style="color:#0f172a;font-size:1.55rem;">
                    <i class="bi bi-search me-2" style="color:var(--ugel-green-dark);"></i>Búsqueda / Visualización
                </h3>
                <p class="text-muted mb-0" style="font-size:0.93rem;">Seleccione el tipo de información a consultar.</p>
            </div>
            <div class="ugel-stat-chip">
                <i class="bi bi-clipboard2-data"></i>
                <span><strong>Fichas de Monitoreo</strong></span>
            </div>
        </div>
    </div>

    <!-- Cabecera -->
    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <h5 class="ugel-section-title fw-bold mb-0">
            <i class="bi bi-grid-3x3-gap-fill me-2"></i>Opciones disponibles
        </h5>
        <span class="badge ugel-context-badge">Consultas</span>
    </div>

    <!-- Tarjetas -->
    <div class="row g-4 justify-content-center">

        <div class="col-12 col-sm-6">
            <div class="card-ref ugel-module-card is-green h-100">
                <div class="card-ref-header ugel-module-card__header">
                    <div class="header-icon-box ugel-module-card__icon">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <div class="ugel-module-card__title">
                        Autoevaluación de Directores
                        <div class="ugel-module-card__subtitle">Fichas enviadas</div>
                    </div>
                </div>
                <div class="card-ref-body ugel-module-card__body">
                    Consultar fichas enviadas por directores y subdirectores, con sus constancias de envío y estado de evaluación.
                </div>
                <a href="jefe_autoevaluacion_directores.php" class="card-ref-btn ugel-module-card__action btn-green-module">
                    Acceder <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        <div class="col-12 col-sm-6">
            <div class="card-ref ugel-module-card is-blue h-100">
                <div class="card-ref-header ugel-module-card__header">
                    <div class="header-icon-box ugel-module-card__icon">
                        <i class="bi bi-file-earmark-bar-graph"></i>
                    </div>
                    <div class="ugel-module-card__title">
                        Evaluación de Especialistas
                        <div class="ugel-module-card__subtitle">Fichas evaluadas</div>
                    </div>
                </div>
                <div class="card-ref-body ugel-module-card__body">
                    Consultar evaluaciones detalladas finalizadas por especialistas, con documentos y constancias generados.
                </div>
                <a href="jefe_evaluacion_especialistas.php" class="card-ref-btn ugel-module-card__action btn-blue">
                    Acceder <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

        <div class="col-12 col-sm-6">
            <div class="card-ref ugel-module-card is-purple h-100">
                <div class="card-ref-header ugel-module-card__header">
                    <div class="header-icon-box ugel-module-card__icon">
                        <i class="bi bi-bar-chart-line-fill"></i>
                    </div>
                    <div class="ugel-module-card__title">
                        Reporte Analítico
                        <div class="ugel-module-card__subtitle">Dashboard interactivo</div>
                    </div>
                </div>
                <div class="card-ref-body ugel-module-card__body">
                    Visualizar gráficos y estadísticas de todas las I.E. de la provincia: avance por distrito, nivel, logro alcanzado y más.
                </div>
                <a href="vista_reportes.php" class="card-ref-btn ugel-module-card__action btn-purple">
                    Generar Reporte <i class="bi bi-chevron-right"></i>
                </a>
            </div>
        </div>

    </div>
</main>

<style>
.ugel-welcome { border-bottom: 1px solid var(--ugel-border); padding-bottom: 1.5rem; }
.ugel-stat-chip { display:inline-flex;align-items:center;gap:7px;background:var(--ugel-green-light);border:1px solid var(--ugel-green-border);border-radius:6px;padding:7px 14px;font-size:.88rem;color:var(--ugel-green-dark);white-space:nowrap; }
.ugel-stat-chip i { font-size:1rem; }
.ugel-module-card__subtitle { font-size:.78rem;font-weight:400;color:var(--ugel-text-muted);margin-top:2px;line-height:1.3; }
/* Variante morada para tarjeta de Reporte */
.ugel-module-card.is-purple .header-icon-box { background:#f0ebff; color:#6d28d9; }
.ugel-module-card.is-purple .card-ref-header { border-bottom-color:#ede9fe; }
.btn-purple { background:#6d28d9 !important; border-color:#6d28d9 !important; color:#fff !important; }
.btn-purple:hover { background:#5b21b6 !important; border-color:#5b21b6 !important; }
</style>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
