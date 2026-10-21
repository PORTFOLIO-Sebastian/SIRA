<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();

if (!asistencia_db_ready($conn)) {
    $ie = asistencia_ie($conn, $idIe);
    $mensual = ['nombre_ie' => $ie['nombre_ie'], 'codigo_local' => $ie['codigo_local']];
    asistencia_layout_header('Modulo de Asistencia', $mensual, $mes, $anio);
    echo '<div class="alert alert-warning shadow-sm"><h5 class="fw-bold">Migracion pendiente</h5><p class="mb-2">Importe primero <code>htdocs/asistencia/schema_asistencia.sql</code> despues de <code>awardspace_mysql8_ugel.sql</code>.</p><p class="mb-0">El modulo esta aislado y no modifica las tablas actuales.</p></div>';
    asistencia_layout_footer();
    exit();
}

$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$personal = asistencia_personal_activo($conn, $idIe);
$calendario = asistencia_calendario($mes, $anio);
$detalle = asistencia_detalle_map($conn, (int)$mensual['id']);
[$completo, $mensajeCompleto] = asistencia_validar_completo($conn, $mensual, $personal, $calendario);
$registrados = 0;
foreach ($detalle as $dias) {
    $registrados += count($dias);
}

asistencia_layout_header('Resumen de Asistencia', $mensual, $mes, $anio);
?>
<div class="row g-3">
    <div class="col-12 col-xl-8">
        <div class="card asistencia-card">
            <div class="card-header">
                <h5 class="asistencia-title"><i class="bi bi-calendar2-check"></i> Periodo mensual</h5>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="GET">
                    <div class="col-12 col-md-4">
                        <label class="form-label asistencia-form-label">Mes</label>
                        <select class="form-select" name="mes">
                            <?php foreach (asistencia_mes_nombres() as $num => $nombre): ?>
                                <option value="<?php echo $num; ?>" <?php echo $num === $mes ? 'selected' : ''; ?>><?php echo asistencia_h($nombre); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label asistencia-form-label">Anio</label>
                        <input class="form-control" type="number" name="anio" min="2020" max="2100" value="<?php echo (int)$anio; ?>">
                    </div>
                    <div class="col-12 col-md-3 col-xl-2">
                        <button class="btn btn-orange w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Cargar</button>
                    </div>
                </form>
                <hr class="my-3">
                <div class="asistencia-info-grid">
                    <div class="asistencia-info-item">
                        <span class="asistencia-info-label">Institución educativa</span>
                        <span class="asistencia-info-value"><?php echo asistencia_h($mensual['nombre_ie']); ?></span>
                    </div>
                    <div class="asistencia-info-item">
                        <span class="asistencia-info-label">Codigo local</span>
                        <span class="asistencia-info-value"><?php echo asistencia_h($mensual['codigo_local']); ?></span>
                    </div>
                    <div class="asistencia-info-item">
                        <span class="asistencia-info-label">Nivel/modalidad</span>
                        <span class="asistencia-info-value"><?php echo asistencia_h($mensual['nivel_modalidad']); ?></span>
                    </div>
                    <div class="asistencia-info-item">
                        <span class="asistencia-info-label">Ubicacion</span>
                        <span class="asistencia-info-value"><?php echo asistencia_h($mensual['region'] . ' / ' . $mensual['provincia'] . ' / ' . $mensual['distrito']); ?></span>
                    </div>
                    <div class="asistencia-info-item">
                        <span class="asistencia-info-label">Estado</span>
                        <span class="badge bg-secondary asistencia-status"><?php echo asistencia_h($mensual['estado']); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-4">
        <div class="card asistencia-card h-100">
            <div class="card-header">
                <h5 class="asistencia-title"><i class="bi bi-speedometer2"></i> Avance</h5>
            </div>
            <div class="card-body">
                <div class="asistencia-progress-row"><span>Personal activo</span><strong><?php echo count($personal); ?></strong></div>
                <div class="asistencia-progress-row"><span>Dias del mes</span><strong><?php echo count($calendario); ?></strong></div>
                <div class="asistencia-progress-row"><span>Celdas registradas</span><strong><?php echo $registrados; ?></strong></div>
                <div class="mt-3 alert asistencia-alert <?php echo $completo ? 'alert-success' : 'alert-warning'; ?> mb-0"><?php echo asistencia_h($completo ? 'Listo para generar envio.' : $mensajeCompleto); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mt-0">
    <div class="col-md-4">
        <a class="card asistencia-action-card text-decoration-none h-100" href="<?php echo asistencia_url('personal.php', $mes, $anio); ?>">
            <div class="card-body">
                <span class="asistencia-action-icon bg-primary-subtle text-primary"><i class="bi bi-people-fill"></i></span>
                <h5 class="asistencia-action-title">Gestionar personal</h5>
                <p class="asistencia-muted mb-0">Registre DNI, cargo, condicion, jornada, nivel y observaciones.</p>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card asistencia-action-card text-decoration-none h-100" href="<?php echo asistencia_url('registro.php', $mes, $anio); ?>">
            <div class="card-body">
                <span class="asistencia-action-icon bg-warning-subtle text-warning"><i class="bi bi-grid-3x3-gap-fill"></i></span>
                <h5 class="asistencia-action-title">Registrar asistencia</h5>
                <p class="asistencia-muted mb-0">Complete la grilla mensual con codigos oficiales UGEL.</p>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card asistencia-action-card text-decoration-none h-100" href="<?php echo asistencia_url('reportes.php', $mes, $anio); ?>">
            <div class="card-body">
                <span class="asistencia-action-icon bg-success-subtle text-success"><i class="bi bi-file-earmark-spreadsheet-fill"></i></span>
                <h5 class="asistencia-action-title">Generar anexos</h5>
                <p class="asistencia-muted mb-0">Revise Anexo 03, Anexo 04 y registre el envio mensual.</p>
            </div>
        </a>
    </div>
</div>
<?php asistencia_layout_footer(); ?>
