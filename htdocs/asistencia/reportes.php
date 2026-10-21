<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$personal = asistencia_personal_activo($conn, $idIe);
$calendario = asistencia_calendario($mes, $anio);
$detalle = asistencia_detalle_map($conn, (int)$mensual['id']);
$resumen = asistencia_resumen($conn, (int)$mensual['id']);
[$completo, $mensajeCompleto] = asistencia_validar_completo($conn, $mensual, $personal, $calendario);

asistencia_layout_header('Reportes de Asistencia', $mensual, $mes, $anio);
?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger asistencia-alert shadow-sm"><?php echo asistencia_h($_GET['error']); ?></div>
<?php endif; ?>
<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success asistencia-alert shadow-sm">Envio mensual generado y registrado correctamente.</div>
<?php endif; ?>
<div class="card asistencia-card mb-4">
    <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <div>
            <h5 class="asistencia-title"><i class="bi bi-file-earmark-check"></i> Generacion de anexos</h5>
            <p class="asistencia-muted mb-0 mt-2">Se generara PDF y Excel, y luego se registrara el envio en la tabla actual <code>envios</code>.</p>
        </div>
        <form action="generar_envio.php" method="POST">
            <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
            <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">
            <button class="btn btn-success btn-lg" type="submit" <?php echo $completo ? '' : 'disabled'; ?>>
                <i class="bi bi-file-earmark-check"></i> Generar y registrar envio
            </button>
        </form>
        <?php if (!$completo): ?>
            <div class="alert alert-warning asistencia-alert w-100 mb-0"><?php echo asistencia_h($mensajeCompleto); ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="card asistencia-card asistencia-table-card mb-4">
    <div class="card-header"><h5 class="asistencia-title"><i class="bi bi-table"></i> Vista previa Anexo 03</h5></div>
    <div class="table-responsive">
        <table class="table table-bordered table-sm asistencia-grid mb-0">
            <thead class="table-light">
                <tr>
                    <th>N</th><th>DNI</th><th>Apellidos y nombres</th><th>Cargo</th><th>Condicion</th><th>Jornada</th><th>Nivel</th>
                    <?php foreach ($calendario as $dia): ?><th class="<?php echo $dia['laborable'] ? '' : 'weekend'; ?>"><?php echo (int)$dia['dia']; ?></th><?php endforeach; ?>
                    <th>Obs.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($personal as $idx => $trabajador): ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo asistencia_h($trabajador['dni']); ?></td>
                        <td><?php echo asistencia_h($trabajador['apellidos_nombres']); ?></td>
                        <td><?php echo asistencia_h($trabajador['cargo']); ?></td>
                        <td><?php echo asistencia_h($trabajador['condicion_laboral']); ?></td>
                        <td><?php echo asistencia_h($trabajador['jornada_laboral']); ?></td>
                        <td><?php echo asistencia_h($trabajador['nivel_educativo']); ?></td>
                        <?php foreach ($calendario as $dia): ?>
                            <td class="text-center <?php echo $dia['laborable'] ? '' : 'weekend'; ?>"><?php echo $dia['laborable'] ? asistencia_h($detalle[(int)$trabajador['id']][(int)$dia['dia']] ?? '') : ''; ?></td>
                        <?php endforeach; ?>
                        <td><?php echo asistencia_h($trabajador['observaciones']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card asistencia-card asistencia-table-card">
    <div class="card-header"><h5 class="asistencia-title"><i class="bi bi-clipboard-data"></i> Vista previa Anexo 04</h5></div>
    <div class="table-responsive">
        <table class="table table-striped mb-0">
            <thead><tr><th>N</th><th>DNI</th><th>Apellidos y nombres</th><th>I</th><th>T</th><th>P</th><th>H</th><th>Observaciones</th></tr></thead>
            <tbody>
                <?php foreach ($personal as $idx => $trabajador): ?>
                    <?php $tot = $resumen[(int)$trabajador['id']] ?? ['I' => 0, 'T' => 0, 'P' => 0, 'H' => 0]; ?>
                    <tr>
                        <td><?php echo $idx + 1; ?></td>
                        <td><?php echo asistencia_h($trabajador['dni']); ?></td>
                        <td><?php echo asistencia_h($trabajador['apellidos_nombres']); ?></td>
                        <td><?php echo (int)$tot['I']; ?></td>
                        <td><?php echo (int)$tot['T']; ?></td>
                        <td><?php echo (int)$tot['P']; ?></td>
                        <td><?php echo (int)$tot['H']; ?></td>
                        <td><?php echo asistencia_h($trabajador['observaciones']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php asistencia_layout_footer(); ?>
