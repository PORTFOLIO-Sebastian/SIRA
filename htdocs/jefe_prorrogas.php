<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$id_usuario = (int)$_SESSION['id_usuario'];

function audit_fm_p($conn, $tabla, $id, $accion, $descripcion, $usuario, $antes = null, $nuevos = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $a  = $antes  ? json_encode($antes,  JSON_UNESCAPED_UNICODE) : null;
    $n  = $nuevos ? json_encode($nuevos, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO fm_auditoria (tabla_afectada,id_registro_afectado,accion,descripcion,datos_anteriores,datos_nuevos,id_usuario,ip_usuario,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
    if ($stmt) { $stmt->bind_param('sissssiss', $tabla, $id, $accion, $descripcion, $a, $n, $usuario, $ip, $ua); $stmt->execute(); $stmt->close(); }
}

$id_tipo = (int)($_GET['id_tipo_ficha'] ?? $_POST['id_tipo_ficha'] ?? 0);
if ($id_tipo <= 0) { header('Location: jefe_crear_fichas.php'); exit(); }

$stmt  = $conn->prepare("SELECT * FROM fm_tipos_ficha WHERE id=? LIMIT 1");
$stmt->bind_param('i', $id_tipo); $stmt->execute();
$ficha = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$ficha) { header('Location: jefe_crear_fichas.php'); exit(); }

$mensaje = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear_prorroga') {
            $alcance = $_POST['alcance'] ?? 'TODAS'; $id_ie = null;
            if ($alcance === 'IE') $id_ie = (int)($_POST['id_institucion'] ?? 0);
            if ($alcance === 'IE' && $id_ie <= 0) throw new Exception('Selecciona una institución educativa.');
            $original  = $ficha['fecha_limite'] ?: date('Y-m-d H:i:s');
            $nueva     = trim($_POST['nueva_fecha_limite'] ?? '');
            $motivo    = trim($_POST['motivo'] ?? '');
            if ($nueva  === '') throw new Exception('La nueva fecha límite es obligatoria.');
            if ($motivo === '') throw new Exception('El motivo de la prórroga es obligatorio.');
            $nuevaDB = str_replace('T', ' ', $nueva);
            if (strlen($nuevaDB) === 16) $nuevaDB .= ':00';
            $stmt = $conn->prepare("INSERT INTO fm_prorrogas (id_tipo_ficha,id_institucion,fecha_limite_original,nueva_fecha_limite,motivo,estado,creado_por) VALUES (?,?,?,?,?,'ACTIVA',?)");
            $stmt->bind_param('iisssi', $id_tipo, $id_ie, $original, $nuevaDB, $motivo, $id_usuario);
            $stmt->execute(); $id = $stmt->insert_id; $stmt->close();
            audit_fm_p($conn, 'fm_prorrogas', $id, 'CREAR_PRORROGA', 'Creó prórroga de ficha.', $id_usuario, null, ['id_tipo_ficha'=>$id_tipo,'id_institucion'=>$id_ie,'nueva_fecha_limite'=>$nuevaDB]);
            $mensaje = 'Prórroga creada correctamente.';
        }
        if ($accion === 'inactivar_prorroga') {
            $id   = (int)($_POST['id_prorroga'] ?? 0);
            $stmt = $conn->prepare("UPDATE fm_prorrogas SET estado='INACTIVA' WHERE id=? AND id_tipo_ficha=?");
            $stmt->bind_param('ii', $id, $id_tipo); $stmt->execute(); $stmt->close();
            audit_fm_p($conn, 'fm_prorrogas', $id, 'INACTIVAR', 'Inactivó una prórroga.', $id_usuario);
            $mensaje = 'Prórroga inactivada.';
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$user     = jefe_user($conn);
$ies      = $conn->query("SELECT id,nombre_ie,codigo_institucion,codigo_local,distrito FROM instituciones ORDER BY nombre_ie ASC");
$prorrogas= $conn->query("SELECT p.*, i.nombre_ie, i.codigo_institucion, i.codigo_local FROM fm_prorrogas p LEFT JOIN instituciones i ON i.id=p.id_institucion WHERE p.id_tipo_ficha=$id_tipo ORDER BY p.fecha_creacion DESC");
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Prórrogas - ' . ($ficha['nombre'] ?? 'Ficha');
    $extra_head = '<link rel="stylesheet" href="assets/css/jefe.css">';
    include 'components/head.php';
    ?>
</head>
<body>
<?php $page_subtitle = 'Prórrogas'; include 'components/jefe_topbar.php'; ?>

<main class="container-fluid px-3 px-md-4 py-4">

    <div class="mb-3">
        <a href="jefe_crear_fichas.php" class="ugel-back-link"><i class="bi bi-arrow-left me-1"></i>Gestión de Fichas</a>
    </div>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="color:#0f172a;"><i class="bi bi-calendar-plus me-2" style="color:var(--ugel-green-dark);"></i>Prórrogas</h4>
            <p class="text-muted mb-0 small"><?= jefe_h($ficha['nombre']) ?> · Fecha límite original: <strong><?= jefe_h($ficha['fecha_limite'] ?: 'No definida') ?></strong></p>
        </div>
    </div>

    <?php if ($mensaje): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= jefe_h($mensaje) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= jefe_h($error) ?></div><?php endif; ?>

    <div class="row g-4">

        <!-- Formulario nueva prórroga -->
        <div class="col-lg-4">
            <div class="jefe-card">
                <div class="jefe-card-head"><i class="bi bi-plus-circle me-2"></i>Nueva prórroga</div>
                <div class="p-3">
                    <form method="post">
                        <input type="hidden" name="accion" value="crear_prorroga">
                        <input type="hidden" name="id_tipo_ficha" value="<?= $id_tipo ?>">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Alcance</label>
                            <select name="alcance" id="alcance" class="form-select">
                                <option value="TODAS">Todas las I.E.</option>
                                <option value="IE">Una I.E. específica</option>
                            </select>
                        </div>
                        <div class="mb-3" id="boxIe" style="display:none;">
                            <label class="form-label fw-bold">Institución educativa</label>
                            <select name="id_institucion" class="form-select">
                                <option value="">Seleccionar I.E.</option>
                                <?php while ($ie = $ies->fetch_assoc()): ?>
                                <option value="<?= (int)$ie['id'] ?>">
                                    <?= jefe_h($ie['nombre_ie']) ?><?= $ie['codigo_institucion'] ? ' (' . jefe_h($ie['codigo_institucion']) . ')' : '' ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nueva fecha límite *</label>
                            <input type="datetime-local" name="nueva_fecha_limite" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Motivo *</label>
                            <textarea name="motivo" class="form-control" rows="4" required></textarea>
                        </div>
                        <button class="btn btn-success fw-bold w-100" onclick="return confirm('¿Crear esta prórroga?');">
                            <i class="bi bi-save me-1"></i>Crear prórroga
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Tabla de prórrogas -->
        <div class="col-lg-8">
            <div class="jefe-card">
                <div class="jefe-card-head"><i class="bi bi-list-ul me-2"></i>Prórrogas registradas</div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0 jefe-table">
                        <thead>
                            <tr><th>Alcance</th><th>Nueva fecha</th><th>Motivo</th><th>Estado</th><th>Acción</th></tr>
                        </thead>
                        <tbody>
                        <?php if ($prorrogas && $prorrogas->num_rows): while ($p = $prorrogas->fetch_assoc()): ?>
                        <tr>
                            <td><?= $p['id_institucion'] ? jefe_h($p['nombre_ie']) : '<strong>Todas las I.E.</strong>' ?></td>
                            <td><?= jefe_h($p['nueva_fecha_limite']) ?></td>
                            <td><?= jefe_h($p['motivo']) ?></td>
                            <td><span class="badge <?= $p['estado'] === 'ACTIVA' ? 'bg-success' : 'bg-secondary' ?>"><?= jefe_h($p['estado']) ?></span></td>
                            <td>
                                <?php if ($p['estado'] === 'ACTIVA'): ?>
                                <form method="post" onsubmit="return confirm('¿Inactivar esta prórroga?');">
                                    <input type="hidden" name="accion" value="inactivar_prorroga">
                                    <input type="hidden" name="id_tipo_ficha" value="<?= $id_tipo ?>">
                                    <input type="hidden" name="id_prorroga" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger">Inactivar</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No hay prórrogas registradas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</main>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
const alcance = document.getElementById('alcance');
const boxIe   = document.getElementById('boxIe');
alcance.addEventListener('change', () => { boxIe.style.display = alcance.value === 'IE' ? 'block' : 'none'; });
</script>
</body>
</html>
