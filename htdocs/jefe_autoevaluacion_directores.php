<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();
$user = jefe_user($conn);

$iesSel   = jefe_arr('ies');
$tiposSel = jefe_arr('tipos');
$distSel  = jefe_arr('distritos');
$espSel   = jefe_arr('especialistas');
$estadoSel= jefe_arr('estados');
$desde    = trim($_GET['desde'] ?? '');
$hasta    = trim($_GET['hasta'] ?? '');

$iesOps   = jf_options($conn->query("SELECT id,nombre_ie,codigo_local,distrito FROM instituciones ORDER BY nombre_ie"), 'id', fn($r) => $r['nombre_ie'] . ' - ' . $r['codigo_local']);
$tiposOps = jf_options($conn->query("SELECT DISTINCT tf.id,tf.nombre,tf.anio FROM fm_tipos_ficha tf JOIN fm_tipos_ficha_versiones v ON v.id_tipo_ficha=tf.id WHERE tf.estado='ACTIVO' AND v.estado='PUBLICADA' ORDER BY tf.anio DESC,tf.nombre"), 'id', fn($r) => $r['nombre'] . ' - ' . $r['anio']);
$distOps  = jf_options($conn->query("SELECT DISTINCT distrito FROM instituciones WHERE distrito IS NOT NULL AND distrito<>'' ORDER BY distrito"), 'distrito', fn($r) => $r['distrito']);
$espOps   = jf_options($conn->query("SELECT DISTINCT u.id,u.nombres,u.ape_pat,u.ape_mat FROM usuarios u JOIN asignaciones_especialistas ae ON ae.id_usuario=u.id ORDER BY u.ape_pat,u.nombres"), 'id', fn($r) => trim($r['nombres'] . ' ' . $r['ape_pat'] . ' ' . $r['ape_mat']));
$estadoOps= [['value'=>'REVISADO','label'=>'REVISADO'],['value'=>'OBSERVADO','label'=>'OBSERVADO'],['value'=>'RECHAZADO','label'=>'RECHAZADO']];

$where  = ["r.fecha_envio IS NOT NULL", "r.estado IN ('ENVIADO','CONFORME','EN_REVISION','OBSERVADO','RECHAZADO','REVISADO')"];
$params = []; $types = '';
jefe_add_in($where, $params, $types, 'i.id',            $iesSel,    'i');
jefe_add_in($where, $params, $types, 'tf.id',           $tiposSel,  'i');
jefe_add_in($where, $params, $types, 'i.distrito',      $distSel,   's');
jefe_add_in($where, $params, $types, 'r.id_especialista',$espSel,   'i');
jefe_add_in($where, $params, $types, 'r.estado',        $estadoSel, 's');
if ($desde !== '') { $where[] = "DATE(r.fecha_envio)>=?"; $params[] = $desde; $types .= 's'; }
if ($hasta !== '') { $where[] = "DATE(r.fecha_envio)<=?"; $params[] = $hasta; $types .= 's'; }

$whereSql = implode(' AND ', $where);
$sql = "SELECT r.id,r.codigo_envio,r.estado,r.fecha_envio,r.id_especialista,tf.nombre tipo_ficha,tf.anio,
 r.latitud,r.longitud,r.precision_metros,
 i.codigo_local,IFNULL(i.codigo_institucion,'') codigo_institucion,i.nombre_ie,i.direccion,i.distrito,
 CONCAT(IFNULL(ue.nombres,''),' ',IFNULL(ue.ape_pat,''),' ',IFNULL(ue.ape_mat,'')) especialista,
 CONCAT(IFNULL(ud.nombres,''),' ',IFNULL(ud.ape_pat,''),' ',IFNULL(ud.ape_mat,'')) director, ud.dni dni_director,
 conf.fecha_conformidad, ev.fecha_evaluacion,
 CASE WHEN ev.id_registro IS NULL THEN 'NO' ELSE 'SI' END evaluada,
 docf.ruta_archivo pdf_director, docc.ruta_archivo constancia_director
FROM fm_registros r
JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha
JOIN instituciones i ON i.id=r.id_institucion
LEFT JOIN usuarios ue ON ue.id=r.id_especialista
LEFT JOIN usuarios ud ON ud.id=r.id_usuario
LEFT JOIN (SELECT id_registro, MAX(fecha_cambio) fecha_conformidad FROM fm_historial_estados WHERE estado_nuevo='CONFORME' GROUP BY id_registro) conf ON conf.id_registro=r.id
LEFT JOIN (SELECT id_registro, MAX(fecha_finalizacion) fecha_evaluacion FROM fm_evaluaciones WHERE estado_evaluacion='FINALIZADA' GROUP BY id_registro) ev ON ev.id_registro=r.id
LEFT JOIN fm_documentos docf ON docf.id_registro=r.id AND docf.tipo_documento='PDF_FICHA_DIRECTOR'
LEFT JOIN fm_documentos docc ON docc.id_registro=r.id AND docc.tipo_documento='CONSTANCIA_ENVIO_DIRECTOR'
WHERE $whereSql ORDER BY r.fecha_envio DESC,r.id DESC LIMIT 500";

$st = $conn->prepare($sql);
if ($params) $st->bind_param($types, ...$params);
$st->execute();
$res  = $st->get_result();
$data = [];
while ($r = $res->fetch_assoc()) $data[] = $r;
$totalResultados = count($data);
$qs = http_build_query($_GET);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Autoevaluaciones Directores - Jefe AGP';
    $extra_head = '<link rel="stylesheet" href="assets/css/jefe.css">';
    include 'components/head.php';
    ?>
</head>
<body>
<?php $page_subtitle = 'Autoevaluación de Directores'; include 'components/jefe_topbar.php'; ?>

<main class="container-fluid py-4 px-3 px-md-4">

    <div class="mb-3">
        <a href="jefe_ficha_busqueda_menu.php" class="ugel-back-link"><i class="bi bi-arrow-left me-1"></i>Búsqueda / Visualización</a>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4 gap-2">
        <div>
            <h4 class="fw-bold mb-1" style="color:#0f172a;"><i class="bi bi-person-check me-2" style="color:var(--ugel-green-dark);"></i>Autoevaluación de Directores</h4>
            <p class="text-muted mb-0 small">Resultados globales filtrables de fichas enviadas por directores/subdirectores.</p>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" class="filter-box mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-xl-2 col-lg-3"><?php jf_filter_dropdown('ies','Instituciones Educativas',$iesOps,$iesSel,'Todas las I.E.'); ?></div>
            <div class="col-xl-2 col-lg-3"><?php jf_filter_dropdown('tipos','Tipo de ficha',$tiposOps,$tiposSel,'Todas'); ?></div>
            <div class="col-xl-2 col-lg-3"><?php jf_filter_dropdown('distritos','Distritos',$distOps,$distSel,'Todos'); ?></div>
            <div class="col-xl-2 col-lg-3"><?php jf_filter_dropdown('especialistas','Especialistas',$espOps,$espSel,'Todos'); ?></div>
            <div class="col-xl-2 col-lg-3"><?php jf_filter_dropdown('estados','Estado',$estadoOps,$estadoSel,'Todos'); ?></div>
            <div class="col-xl-2 col-lg-3">
                <label class="form-label fw-bold text-success">Fecha de envío</label>
                <div class="input-group">
                    <input type="date" name="desde" value="<?= jefe_h($desde) ?>" class="form-control" data-auto-submit="1">
                    <input type="date" name="hasta" value="<?= jefe_h($hasta) ?>" class="form-control" data-auto-submit="1">
                </div>
            </div>
            <div class="col-12 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="result-counter">
                    <span class="fw-bold text-success"><i class="bi bi-funnel me-1"></i>Total de resultados:</span>
                    <span class="filter-count"><?= (int)$totalResultados ?></span>
                </div>
                <a href="jefe_autoevaluacion_directores.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Limpiar filtros</a>
            </div>
        </div>
    </form>

    <!-- Descargas -->
    <div class="zipbar mb-3 d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div class="fw-bold text-success"><i class="bi bi-download me-1"></i>Descargas masivas según filtros actuales</div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm btn-success" href="jefe_descargar_zip.php?categoria=autoevaluacion&doc=pdf&<?= jefe_h($qs) ?>"><i class="bi bi-file-earmark-zip me-1"></i>Descargar PDFs</a>
            <a class="btn btn-sm btn-danger"  href="jefe_descargar_zip.php?categoria=autoevaluacion&doc=constancia&<?= jefe_h($qs) ?>"><i class="bi bi-file-earmark-zip me-1"></i>Descargar constancias</a>
        </div>
    </div>

    <!-- Tabla -->
    <div class="jefe-card">
        <div class="jefe-card-head"><i class="bi bi-table me-2"></i>Resultados encontrados: <?= (int)$totalResultados ?></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 jefe-table">
                <thead>
                    <tr>
                        <th>Fecha envío</th><th>Fecha conformidad</th><th>Tipo de ficha</th><th>Estado</th>
                        <th>Especialista</th><th>Cód. local</th><th>I.E.</th><th>Dirección</th>
                        <th>Distrito</th><th>Evaluada</th><th>Fecha evaluación</th><th class="text-center">Mapa</th><th>PDF autoev.</th><th>Constancia</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($totalResultados === 0): ?>
                    <tr><td colspan="14" class="text-center text-muted py-5">No hay resultados con los filtros aplicados.</td></tr>
                <?php endif; ?>
                <?php foreach ($data as $f):
                    $mid = 'pdfAuto' . (int)$f['id'];
                    $mc  = 'constAuto' . (int)$f['id'];
                ?>
                    <tr>
                        <td><?= jefe_h($f['fecha_envio']) ?></td>
                        <td><?= jefe_h($f['fecha_conformidad'] ?: '—') ?></td>
                        <td><b><?= jefe_h($f['tipo_ficha']) ?></b><br><small class="small-muted">Año <?= jefe_h($f['anio']) ?> · <?= jefe_h($f['codigo_envio']) ?></small></td>
                        <td><span class="badge <?= jefe_badge_estado($f['estado']) ?> state-badge"><?= jefe_h($f['estado']) ?></span></td>
                        <td><?= jefe_h(trim($f['especialista']) ?: 'Sin asignar') ?></td>
                        <td><?= jefe_h($f['codigo_local'] ?: $f['codigo_institucion']) ?></td>
                        <td><b><?= jefe_h($f['nombre_ie']) ?></b><br><small class="small-muted"><?= jefe_h($f['director']) ?> · DNI <?= jefe_h($f['dni_director']) ?></small></td>
                        <td><?= jefe_h($f['direccion']) ?></td>
                        <td><?= jefe_h($f['distrito']) ?></td>
                        <td><?= jefe_h($f['evaluada']) ?></td>
                        <td><?= jefe_h($f['fecha_evaluacion'] ?: '—') ?></td>
                        <td class="text-center">
                            <?php if ($f['latitud'] !== null && $f['longitud'] !== null): ?>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="ver_mapa.php?lat=<?= (float)$f['latitud'] ?>&lng=<?= (float)$f['longitud'] ?>&precision=<?= $f['precision_metros'] !== null ? (float)$f['precision_metros'] : '' ?>&title=<?= urlencode($f['nombre_ie']) ?>"
                                   target="_blank">
                                    <i class="bi bi-geo-alt-fill"></i> Ver
                                </a>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($f['pdf_director']): ?>
                                <button class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#<?= $mid ?>"><i class="bi bi-eye"></i></button>
                                <a class="btn btn-sm btn-outline-primary" href="<?= jefe_h($f['pdf_director']) ?>" target="_blank"><i class="bi bi-download"></i></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($f['constancia_director']): ?>
                                <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#<?= $mc ?>"><i class="bi bi-eye"></i></button>
                                <a class="btn btn-sm btn-outline-primary" href="<?= jefe_h($f['constancia_director']) ?>" target="_blank"><i class="bi bi-download"></i></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($f['pdf_director']): ?>
                    <div class="modal fade" id="<?= $mid ?>"><div class="modal-dialog modal-xl"><div class="modal-content">
                        <div class="modal-header bg-success text-white"><h5 class="modal-title">PDF Autoevaluación</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body"><iframe class="pdf-frame" src="<?= jefe_h($f['pdf_director']) ?>"></iframe></div>
                    </div></div></div>
                    <?php endif; ?>
                    <?php if ($f['constancia_director']): ?>
                    <div class="modal fade" id="<?= $mc ?>"><div class="modal-dialog modal-xl"><div class="modal-content">
                        <div class="modal-header bg-danger text-white"><h5 class="modal-title">Constancia</h5><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                        <div class="modal-body"><iframe class="pdf-frame" src="<?= jefe_h($f['constancia_director']) ?>"></iframe></div>
                    </div></div></div>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</main>
<?php jefe_footer_scripts(); ?>
</body>
</html>
