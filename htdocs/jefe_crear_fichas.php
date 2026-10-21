<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$id_usuario = (int)$_SESSION['id_usuario'];

function slugify_fm($text) {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $map  = ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u'];
    $text = strtr($text, $map);
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text);
    return trim($text, '-') ?: 'ficha';
}

function audit_fm($conn, $tabla, $id, $accion, $descripcion, $usuario, $antes = null, $nuevos = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $a  = $antes  ? json_encode($antes,  JSON_UNESCAPED_UNICODE) : null;
    $n  = $nuevos ? json_encode($nuevos, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO fm_auditoria (tabla_afectada,id_registro_afectado,accion,descripcion,datos_anteriores,datos_nuevos,id_usuario,ip_usuario,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
    if ($stmt) { $stmt->bind_param('sissssiss', $tabla, $id, $accion, $descripcion, $a, $n, $usuario, $ip, $ua); $stmt->execute(); $stmt->close(); }
}

function crear_version_copiada($conn, $id_tipo_ficha, $id_usuario) {
    $stmt = $conn->prepare("SELECT MAX(version) AS maxv FROM fm_tipos_ficha_versiones WHERE id_tipo_ficha=?");
    $stmt->bind_param('i', $id_tipo_ficha); $stmt->execute();
    $maxv  = (int)($stmt->get_result()->fetch_assoc()['maxv'] ?? 0); $stmt->close();
    $nueva = $maxv + 1;
    $stmt  = $conn->prepare("SELECT * FROM fm_tipos_ficha_versiones WHERE id_tipo_ficha=? ORDER BY version DESC LIMIT 1");
    $stmt->bind_param('i', $id_tipo_ficha); $stmt->execute();
    $base  = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$base) return false;
    $titulo = preg_replace('/\s+-\s+Versi[oó]n\s+\d+$/i', '', $base['titulo'] ?? 'Ficha');
    $titulo = trim($titulo) . ' - Versión ' . $nueva;
    $motivo = 'Nueva versión creada para actualización de ficha sin afectar respuestas históricas.';
    $stmt   = $conn->prepare("INSERT INTO fm_tipos_ficha_versiones (id_tipo_ficha,version,titulo,descripcion,estado,motivo_version,creado_por) VALUES (?,?,?,?,'BORRADOR',?,?)");
    $desc   = $base['descripcion'] ?? '';
    $stmt->bind_param('iisssi', $id_tipo_ficha, $nueva, $titulo, $desc, $motivo, $id_usuario);
    $stmt->execute(); $id_new = $stmt->insert_id; $stmt->close();

    $sectionMap = [];
    $res = $conn->query("SELECT * FROM fm_secciones WHERE id_version_ficha=" . (int)$base['id'] . " ORDER BY nivel,orden,id");
    while ($s = $res->fetch_assoc()) {
        $old = (int)$s['id'];
        $newParent = $s['id_seccion_padre'] ? ($sectionMap[(int)$s['id_seccion_padre']] ?? null) : null;
        $stmt = $conn->prepare("INSERT INTO fm_secciones (id_version_ficha,id_seccion_padre,titulo,descripcion,orden,nivel,estado) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param('iisssis', $id_new, $newParent, $s['titulo'], $s['descripcion'], $s['orden'], $s['nivel'], $s['estado']);
        $stmt->execute(); $sectionMap[$old] = $stmt->insert_id; $stmt->close();
    }
    $itemMap = [];
    $res = $conn->query("SELECT * FROM fm_items WHERE id_version_ficha=" . (int)$base['id'] . " ORDER BY orden,id");
    while ($it = $res->fetch_assoc()) {
        $newSec = $it['id_seccion'] ? ($sectionMap[(int)$it['id_seccion']] ?? null) : null;
        $stmt   = $conn->prepare("INSERT INTO fm_items (id_version_ficha,id_seccion,numero_item,orden,titulo,pregunta,tipo_respuesta,obligatorio,permite_no_aplica,puntaje_maximo,criterio_instruccion,estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisisssiidss', $id_new, $newSec, $it['numero_item'], $it['orden'], $it['titulo'], $it['pregunta'], $it['tipo_respuesta'], $it['obligatorio'], $it['permite_no_aplica'], $it['puntaje_maximo'], $it['criterio_instruccion'], $it['estado']);
        $stmt->execute(); $itemMap[(int)$it['id']] = $stmt->insert_id; $stmt->close();
    }
    foreach ($itemMap as $oldItem => $newItem) {
        $res = $conn->query("SELECT * FROM fm_rubricas WHERE id_item=" . (int)$oldItem . " ORDER BY valor");
        while ($r = $res->fetch_assoc()) {
            $stmt = $conn->prepare("INSERT INTO fm_rubricas (id_item,valor,descripcion) VALUES (?,?,?)");
            $stmt->bind_param('iss', $newItem, $r['valor'], $r['descripcion']);
            $stmt->execute(); $stmt->close();
        }
    }
    return $id_new;
}

$mensaje = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear_ficha') {
            $nombre   = trim($_POST['nombre']   ?? '');
            $descripcion = trim($_POST['descripcion'] ?? '');
            $anio     = (int)($_POST['anio']    ?? date('Y'));
            $fecha_inicio = trim($_POST['fecha_inicio'] ?? '') ?: null;
            $fecha_limite = trim($_POST['fecha_limite'] ?? '') ?: null;
            $texto_consentimiento = trim($_POST['texto_consentimiento'] ?? '');
            if ($nombre === '') throw new Exception('El nombre de la ficha es obligatorio.');
            $baseSlug = slugify_fm($nombre . '-' . $anio); $slug = $baseSlug; $i = 2;
            $stmt = $conn->prepare("SELECT id FROM fm_tipos_ficha WHERE slug=? LIMIT 1");
            while (true) { $stmt->bind_param('s', $slug); $stmt->execute(); if (!$stmt->get_result()->fetch_assoc()) break; $slug = $baseSlug . '-' . $i++; }
            $stmt->close();
            $estado = 'INACTIVO';
            $stmt   = $conn->prepare("INSERT INTO fm_tipos_ficha (nombre,slug,descripcion,anio,estado,fecha_inicio,fecha_limite,texto_consentimiento,creado_por) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssissssi', $nombre, $slug, $descripcion, $anio, $estado, $fecha_inicio, $fecha_limite, $texto_consentimiento, $id_usuario);
            $stmt->execute(); $id_tipo = $stmt->insert_id; $stmt->close();
            $titulo  = $nombre . ' - Versión 1'; $version = 1; $estadoV = 'BORRADOR'; $motivo = 'Creación inicial de ficha.';
            $stmt    = $conn->prepare("INSERT INTO fm_tipos_ficha_versiones (id_tipo_ficha,version,titulo,descripcion,estado,motivo_version,creado_por) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iissssi', $id_tipo, $version, $titulo, $descripcion, $estadoV, $motivo, $id_usuario);
            $stmt->execute(); $id_version = $stmt->insert_id; $stmt->close();
            audit_fm($conn, 'fm_tipos_ficha', $id_tipo, 'CREAR', 'Creó una nueva ficha de monitoreo en borrador.', $id_usuario, null, ['nombre'=>$nombre,'anio'=>$anio,'version'=>$version]);
            header('Location: jefe_ficha_editor.php?id_version=' . $id_version . '&ok=creada'); exit();
        }
        if ($accion === 'publicar') {
            $id_version = (int)($_POST['id_version'] ?? 0);
            $stmt = $conn->prepare("SELECT v.*,tf.id AS id_tipo,tf.nombre FROM fm_tipos_ficha_versiones v JOIN fm_tipos_ficha tf ON tf.id=v.id_tipo_ficha WHERE v.id=? LIMIT 1");
            $stmt->bind_param('i', $id_version); $stmt->execute(); $v = $stmt->get_result()->fetch_assoc(); $stmt->close();
            if (!$v) throw new Exception('Versión no encontrada.');
            $stmt = $conn->prepare("SELECT COUNT(*) c FROM fm_items WHERE id_version_ficha=? AND estado='ACTIVO'");
            $stmt->bind_param('i', $id_version); $stmt->execute(); $items = (int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
            if ($items <= 0) throw new Exception('No se puede publicar una ficha sin ítems activos.');
            $conn->query("UPDATE fm_tipos_ficha_versiones SET estado='INACTIVA' WHERE id_tipo_ficha=" . (int)$v['id_tipo_ficha'] . " AND id<>$id_version AND estado='PUBLICADA'");
            $stmt = $conn->prepare("UPDATE fm_tipos_ficha_versiones SET estado='PUBLICADA',publicado_por=?,fecha_publicacion=NOW() WHERE id=?");
            $stmt->bind_param('ii', $id_usuario, $id_version); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare("UPDATE fm_tipos_ficha SET estado='ACTIVO',actualizado_por=?,fecha_actualizacion=NOW() WHERE id=?");
            $stmt->bind_param('ii', $id_usuario, $v['id_tipo_ficha']); $stmt->execute(); $stmt->close();
            audit_fm($conn, 'fm_tipos_ficha_versiones', $id_version, 'PUBLICAR', 'Publicó una versión de ficha.', $id_usuario, null, ['id_tipo_ficha'=>$v['id_tipo_ficha']]);
            $mensaje = 'Ficha publicada correctamente.';
        }
        if ($accion === 'inactivar') {
            $id_tipo = (int)($_POST['id_tipo_ficha'] ?? 0);
            $stmt = $conn->prepare("UPDATE fm_tipos_ficha SET estado='INACTIVO',actualizado_por=?,fecha_actualizacion=NOW() WHERE id=?");
            $stmt->bind_param('ii', $id_usuario, $id_tipo); $stmt->execute(); $stmt->close();
            $stmt = $conn->prepare("UPDATE fm_tipos_ficha_versiones SET estado='INACTIVA' WHERE id_tipo_ficha=? AND estado='PUBLICADA'");
            $stmt->bind_param('i', $id_tipo); $stmt->execute(); $stmt->close();
            audit_fm($conn, 'fm_tipos_ficha', $id_tipo, 'INACTIVAR', 'Inactivó una ficha de monitoreo.', $id_usuario);
            $mensaje = 'Ficha inactivada correctamente.';
        }
        if ($accion === 'nueva_version') {
            $id_tipo = (int)($_POST['id_tipo_ficha'] ?? 0);
            $id_new  = crear_version_copiada($conn, $id_tipo, $id_usuario);
            if (!$id_new) throw new Exception('No se pudo crear la nueva versión.');
            audit_fm($conn, 'fm_tipos_ficha_versiones', $id_new, 'CREAR', 'Creó nueva versión editable de una ficha existente.', $id_usuario, null, ['id_tipo_ficha'=>$id_tipo]);
            header('Location: jefe_ficha_editor.php?id_version=' . $id_new . '&ok=nueva_version'); exit();
        }
    } catch (Exception $e) { $error = $e->getMessage(); }
}

$user = jefe_user($conn);

$sql   = "SELECT tf.*, v.id AS id_version, v.version, v.titulo AS titulo_version, v.estado AS estado_version, v.fecha_publicacion,
       (SELECT COUNT(*) FROM fm_items i WHERE i.id_version_ficha=v.id AND i.estado='ACTIVO') AS total_items,
       (SELECT COUNT(*) FROM fm_registros r WHERE r.id_tipo_ficha=tf.id) AS total_respuestas,
       (SELECT COUNT(*) FROM fm_prorrogas p WHERE p.id_tipo_ficha=tf.id AND p.estado='ACTIVA') AS total_prorrogas
FROM fm_tipos_ficha tf
LEFT JOIN fm_tipos_ficha_versiones v ON v.id = (
    SELECT vv.id FROM fm_tipos_ficha_versiones vv WHERE vv.id_tipo_ficha=tf.id
    ORDER BY FIELD(vv.estado,'BORRADOR','PUBLICADA','INACTIVA'), vv.version DESC LIMIT 1
)
ORDER BY tf.anio DESC, tf.fecha_creacion DESC";
$fichas = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Gestión de Fichas - Jefe AGP';
    $extra_head = '<link rel="stylesheet" href="assets/css/jefe.css">';
    include 'components/head.php';
    ?>
</head>
<body>
<?php $page_subtitle = 'Gestión / Creación de Fichas'; include 'components/jefe_topbar.php'; ?>

<main class="container-fluid px-3 px-md-4 py-4">

    <div class="mb-3">
        <a href="jefe_ficha_menu.php" class="ugel-back-link"><i class="bi bi-arrow-left me-1"></i>Ficha de Monitoreo</a>
    </div>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="color:#0f172a;"><i class="bi bi-clipboard2-plus me-2" style="color:var(--ugel-green-dark);"></i>Gestión / Creación de Fichas</h4>
            <p class="text-muted mb-0 small">Administra tipos de ficha, versiones, estructura, rúbricas y prórrogas.</p>
        </div>
        <button class="btn btn-success fw-bold" data-bs-toggle="modal" data-bs-target="#modalCrear">
            <i class="bi bi-plus-circle me-1"></i>Nueva ficha
        </button>
    </div>

    <?php if ($mensaje): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= jefe_h($mensaje) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= jefe_h($error) ?></div><?php endif; ?>

    <div class="jefe-card">
        <div class="jefe-card-head"><i class="bi bi-table me-2"></i>Fichas registradas</div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 jefe-table">
                <thead>
                    <tr>
                        <th>Ficha</th><th>Año</th><th>Versión</th><th>Estado ficha</th>
                        <th>Estado versión</th><th>Ítems</th><th>Respuestas</th><th>Prórrogas</th>
                        <th class="text-end">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($fichas && $fichas->num_rows): while ($f = $fichas->fetch_assoc()): $respuestas = (int)$f['total_respuestas']; ?>
                <tr>
                    <td>
                        <strong><?= jefe_h($f['nombre']) ?></strong>
                        <div class="small-muted"><?= jefe_h($f['descripcion']) ?></div>
                    </td>
                    <td><?= (int)$f['anio'] ?></td>
                    <td><span class="badge bg-light text-dark border">v<?= (int)$f['version'] ?></span></td>
                    <td><span class="badge <?= $f['estado'] === 'ACTIVO' ? 'bg-success' : 'bg-secondary' ?>"><?= jefe_h($f['estado']) ?></span></td>
                    <td><span class="badge <?= $f['estado_version'] === 'PUBLICADA' ? 'bg-primary' : ($f['estado_version'] === 'BORRADOR' ? 'bg-warning text-dark' : 'bg-secondary') ?>"><?= jefe_h($f['estado_version']) ?></span></td>
                    <td><?= (int)$f['total_items'] ?></td>
                    <td><?= $respuestas ?></td>
                    <td><?= (int)$f['total_prorrogas'] ?></td>
                    <td class="text-end">
                        <div class="d-flex flex-wrap justify-content-end gap-1">
                            <?php if ($f['id_version']): ?>
                            <a class="btn btn-sm btn-outline-success action-btn" href="jefe_ficha_editor.php?id_version=<?= (int)$f['id_version'] ?>"><i class="bi bi-pencil-square"></i> Editar</a>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline-primary action-btn" href="jefe_prorrogas.php?id_tipo_ficha=<?= (int)$f['id'] ?>"><i class="bi bi-calendar-plus"></i> Prórrogas</a>
                            <?php if (($f['estado_version'] ?? '') === 'BORRADOR'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('¿Publicar esta ficha? Aparecerá para directores/subdirectores desde ese momento.');">
                                <input type="hidden" name="accion" value="publicar">
                                <input type="hidden" name="id_version" value="<?= (int)$f['id_version'] ?>">
                                <button class="btn btn-sm btn-success action-btn"><i class="bi bi-megaphone"></i> Publicar</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Se creará una nueva versión en borrador. ¿Continuar?');">
                                <input type="hidden" name="accion" value="nueva_version">
                                <input type="hidden" name="id_tipo_ficha" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-warning action-btn"><i class="bi bi-layers"></i> Nueva versión</button>
                            </form>
                            <?php if ($f['estado'] === 'ACTIVO'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('¿Inactivar esta ficha? No se eliminará información, solo dejará de mostrarse.');">
                                <input type="hidden" name="accion" value="inactivar">
                                <input type="hidden" name="id_tipo_ficha" value="<?= (int)$f['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger action-btn"><i class="bi bi-slash-circle"></i> Inactivar</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-4">Aún no hay fichas registradas.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>

<!-- Modal: Nueva ficha -->
<div class="modal fade" id="modalCrear" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="accion" value="crear_ficha">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Nueva ficha de monitoreo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-bold">Nombre de la ficha *</label>
                        <input name="nombre" class="form-control" required placeholder="Ej. GESTIÓN DE CONDICIONES OPERATIVAS">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Año de gestión *</label>
                        <input type="number" name="anio" class="form-control" value="<?= date('Y') ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Fecha de inicio</label>
                        <input type="datetime-local" name="fecha_inicio" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Fecha límite</label>
                        <input type="datetime-local" name="fecha_limite" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Descripción</label>
                        <textarea name="descripcion" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-bold">Texto de consentimiento</label>
                        <textarea name="texto_consentimiento" class="form-control" rows="4" placeholder="Texto que aceptará el director/subdirector antes de responder la ficha."></textarea>
                        <div class="small-muted mt-1">La ficha se guardará en BORRADOR; no aparecerá hasta publicarla.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button class="btn btn-success fw-bold"><i class="bi bi-save me-1"></i>Crear ficha</button>
            </div>
        </form>
    </div>
</div>

<?php jefe_footer_scripts(); ?>
</body>
</html>
