<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$personal = asistencia_personal_todos($conn, $idIe);
$edit = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($personal as $row) {
        if ((int)$row['id'] === $editId) {
            $edit = $row;
            break;
        }
    }
}

asistencia_layout_header('Personal de Asistencia', $mensual, $mes, $anio);
?>
<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success asistencia-alert shadow-sm">Operacion registrada correctamente.</div>
<?php endif; ?>
<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger asistencia-alert shadow-sm"><?php echo asistencia_h($_GET['error']); ?></div>
<?php endif; ?>
<div class="row g-4">
    <div class="col-12 col-xl-4">
        <div class="card asistencia-card">
            <div class="card-header">
                <h5 class="asistencia-title"><i class="bi bi-person-plus"></i> <?php echo $edit ? 'Editar trabajador' : 'Nuevo trabajador'; ?></h5>
            </div>
            <div class="card-body">
                <form action="guardar_personal.php" method="POST" class="row g-3">
                    <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
                    <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">
                    <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">
                    <div class="col-12">
                        <label class="form-label asistencia-form-label">DNI</label>
                        <input class="form-control" name="dni" required maxlength="20" value="<?php echo asistencia_h($edit['dni'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label asistencia-form-label">Apellidos y nombres</label>
                        <input class="form-control" name="apellidos_nombres" required maxlength="255" value="<?php echo asistencia_h($edit['apellidos_nombres'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label asistencia-form-label">Cargo</label>
                        <input class="form-control" name="cargo" required maxlength="120" value="<?php echo asistencia_h($edit['cargo'] ?? 'Docente'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label asistencia-form-label">Condicion</label>
                        <select class="form-select" name="condicion_laboral" required>
                            <?php foreach (['Nombrado', 'Contratado', 'Designado', 'Encargado'] as $op): ?>
                                <option <?php echo (($edit['condicion_laboral'] ?? '') === $op) ? 'selected' : ''; ?>><?php echo $op; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label asistencia-form-label">Jornada</label>
                        <select class="form-select" name="jornada_laboral" required>
                            <?php foreach (['10', '15', '24', '30', '40'] as $op): ?>
                                <option value="<?php echo $op; ?>" <?php echo (($edit['jornada_laboral'] ?? '') === $op) ? 'selected' : ''; ?>><?php echo $op; ?> horas</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label asistencia-form-label">Nivel educativo</label>
                        <select class="form-select" name="nivel_educativo" required>
                            <?php foreach (['Inicial', 'Primaria', 'Secundaria', 'CEBA', 'CETPRO', 'EBE', 'Administrativo'] as $op): ?>
                                <option <?php echo (($edit['nivel_educativo'] ?? '') === $op) ? 'selected' : ''; ?>><?php echo $op; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label asistencia-form-label">Observaciones</label>
                        <input class="form-control" name="observaciones" maxlength="255" value="<?php echo asistencia_h($edit['observaciones'] ?? ''); ?>">
                    </div>
                    <div class="col-12 d-grid">
                        <button class="btn btn-orange" type="submit"><i class="bi bi-save"></i> Guardar personal</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-8">
        <div class="card asistencia-card asistencia-table-card">
            <div class="card-header">
                <h5 class="asistencia-title"><i class="bi bi-card-checklist"></i> Personal registrado</h5>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>DNI</th><th>Trabajador</th><th>Cargo</th><th>Condicion</th><th>Estado</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($personal as $row): ?>
                            <tr>
                                <td><?php echo asistencia_h($row['dni']); ?></td>
                                <td class="fw-bold"><?php echo asistencia_h($row['apellidos_nombres']); ?><br><small class="text-muted"><?php echo asistencia_h($row['nivel_educativo']); ?></small></td>
                                <td><?php echo asistencia_h($row['cargo']); ?></td>
                                <td><?php echo asistencia_h($row['condicion_laboral']); ?></td>
                                <td><span class="badge <?php echo $row['estado'] === 'activo' ? 'bg-success' : 'bg-secondary'; ?>"><?php echo asistencia_h($row['estado']); ?></span></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo asistencia_url('personal.php', $mes, $anio) . '&edit=' . (int)$row['id']; ?>"><i class="bi bi-pencil"></i></a>
                                    <?php if ($row['estado'] === 'activo'): ?>
                                        <form action="guardar_personal.php" method="POST" class="d-inline" onsubmit="return confirm('Desea dar de baja este trabajador?');">
                                            <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
                                            <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">
                                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                                            <input type="hidden" name="accion" value="baja">
                                            <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-person-dash"></i></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$personal): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Aun no hay personal registrado.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php asistencia_layout_footer(); ?>
