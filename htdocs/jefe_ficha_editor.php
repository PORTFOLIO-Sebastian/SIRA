<?php
session_start();
require_once 'conexion.php';
require_once 'jefe_agp_helpers.php';

jefe_require_role();

$id_usuario = (int)$_SESSION['id_usuario'];
$id_version = (int)($_GET['id_version'] ?? $_POST['id_version'] ?? 0);
if ($id_version <= 0) { header('Location: jefe_crear_fichas.php'); exit(); }

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function table_has_column_local($conn, $table, $column) {
    $stmt = $conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss', $table, $column); $stmt->execute();
    $ok = ((int)$stmt->get_result()->fetch_assoc()['c']) > 0; $stmt->close(); return $ok;
}

function audit_fm($conn, $tabla, $id, $accion, $descripcion, $usuario, $antes = null, $nuevos = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? ''; $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $a  = $antes  ? json_encode($antes,  JSON_UNESCAPED_UNICODE) : null;
    $n  = $nuevos ? json_encode($nuevos, JSON_UNESCAPED_UNICODE) : null;
    $stmt = $conn->prepare("INSERT INTO fm_auditoria (tabla_afectada,id_registro_afectado,accion,descripcion,datos_anteriores,datos_nuevos,id_usuario,ip_usuario,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
    if ($stmt) { $stmt->bind_param('sissssiss', $tabla, $id, $accion, $descripcion, $a, $n, $usuario, $ip, $ua); $stmt->execute(); $stmt->close(); }
}

function cargarContexto($conn, $id_version) {
    $hasPlan    = table_has_column_local($conn, 'fm_tipos_ficha_versiones', 'total_items_planificados');
    $planFields = $hasPlan ? ", v.total_items_planificados, v.estructura_configurada" : ", NULL AS total_items_planificados, 0 AS estructura_configurada";
    $sql  = "SELECT v.* $planFields, tf.id AS id_tipo_ficha, tf.nombre, tf.slug, tf.anio, tf.descripcion AS descripcion_tipo, tf.estado AS estado_tipo, tf.fecha_inicio, tf.fecha_limite, tf.texto_consentimiento,
     (SELECT COUNT(*) FROM fm_registros r WHERE r.id_version_ficha=v.id OR r.id_tipo_ficha=tf.id) AS total_respuestas,
     (SELECT COUNT(*) FROM fm_items i WHERE i.id_version_ficha=v.id AND i.estado='ACTIVO') AS total_items
     FROM fm_tipos_ficha_versiones v JOIN fm_tipos_ficha tf ON tf.id=v.id_tipo_ficha WHERE v.id=? LIMIT 1";
    $stmt = $conn->prepare($sql); $stmt->bind_param('i', $id_version); $stmt->execute();
    $ctx  = $stmt->get_result()->fetch_assoc(); $stmt->close(); return $ctx;
}

$ctx      = cargarContexto($conn, $id_version);
if (!$ctx) { header('Location: jefe_crear_fichas.php'); exit(); }

$bloqueado = ((int)$ctx['total_respuestas'] > 0 || ($ctx['estado'] ?? '') === 'PUBLICADA');
$faltaSQL  = !table_has_column_local($conn, 'fm_tipos_ficha_versiones', 'total_items_planificados');
$mensaje   = ''; $error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_basico') {
    try {
        if ($bloqueado) throw new Exception('Esta versión ya fue publicada o tiene respuestas. Crea una nueva versión para modificarla.');
        $nombre      = trim($_POST['nombre']      ?? '');
        $descripcion = trim($_POST['descripcion'] ?? '');
        $anio        = (int)($_POST['anio']       ?? date('Y'));
        $fecha_inicio = trim($_POST['fecha_inicio'] ?? '') ?: null;
        $fecha_limite = trim($_POST['fecha_limite'] ?? '') ?: null;
        $cons         = trim($_POST['texto_consentimiento'] ?? '');
        $titulo       = trim($_POST['titulo_version'] ?? '');
        if ($nombre === '') throw new Exception('El nombre de la ficha es obligatorio.');
        if ($titulo === '') $titulo = $nombre . ' - Versión ' . (int)$ctx['version'];
        $stmt = $conn->prepare("UPDATE fm_tipos_ficha SET nombre=?,descripcion=?,anio=?,fecha_inicio=?,fecha_limite=?,texto_consentimiento=?,actualizado_por=?,fecha_actualizacion=NOW() WHERE id=?");
        $stmt->bind_param('ssisssii', $nombre, $descripcion, $anio, $fecha_inicio, $fecha_limite, $cons, $id_usuario, $ctx['id_tipo_ficha']); $stmt->execute(); $stmt->close();
        $stmt = $conn->prepare("UPDATE fm_tipos_ficha_versiones SET titulo=?,descripcion=? WHERE id=?");
        $stmt->bind_param('ssi', $titulo, $descripcion, $id_version); $stmt->execute(); $stmt->close();
        audit_fm($conn, 'fm_tipos_ficha', $ctx['id_tipo_ficha'], 'EDITAR', 'Actualizó datos básicos de ficha.', $id_usuario, $ctx, ['nombre'=>$nombre,'anio'=>$anio]);
        header('Location: jefe_ficha_editor.php?id_version=' . $id_version . '&ok=guardado'); exit();
    } catch (Exception $e) { $error = $e->getMessage(); }
}

if (isset($_GET['ok'])) {
    $mensaje = $_GET['ok'] === 'guardado' ? 'Borrador guardado correctamente.'
             : ($_GET['ok'] === 'creada'  ? 'Ficha creada. Ahora configura la estructura guiada.' : 'Operación completada.');
}

$user = jefe_user($conn);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Editor de Ficha - ' . ($ctx['nombre'] ?? 'Jefe AGP');
    $extra_head = '<link rel="stylesheet" href="assets/css/jefe.css">';
    include 'components/head.php';
    ?>
    <style>
    .sticky-actions { position:sticky;top:0;z-index:55;background:rgba(248,253,249,.97);backdrop-filter:blur(8px);border:1px solid #cfe8da;border-radius:18px;padding:14px;margin-bottom:18px;box-shadow:0 8px 24px rgba(0,0,0,.07); }
    .step { display:flex;gap:12px;align-items:flex-start;padding:14px;border:1px solid #d8eadf;border-radius:15px;background:#fff;margin-bottom:12px; }
    .step-num { width:36px;height:36px;border-radius:50%;background:#198754;color:#fff;display:grid;place-items:center;font-weight:900;flex:none; }
    .form-hint { font-size:12px;color:#6c757d; }
    .structure-line { border-left:4px solid #198754;padding:8px 10px;margin-bottom:8px;background:#f8fcf9;border-radius:10px; }
    .structure-line.sub { margin-left:22px;border-left-color:#72c39a; }
    .item-card { border:1px solid #d8eadf;border-radius:14px;padding:13px;margin-bottom:12px;background:#fff; }
    .item-num { background:#006b35;color:white;padding:4px 10px;border-radius:20px;font-weight:800; }
    .empty-state { text-align:center;padding:45px 20px;color:#6c757d; }
    .empty-state i { font-size:40px;color:#198754; }
    .toast-area { position:fixed;right:20px;bottom:20px;z-index:9999; }
    .locked { background:#fff3cd;border:1px solid #ffecb5;border-radius:14px;padding:14px; }
    .rubrica-box { background:#f7fbf8;border:1px dashed #9ed5b8;border-radius:12px;padding:12px; }
    .progress-soft { height:9px;border-radius:20px;background:#e8f3ec;overflow:hidden; }
    .progress-soft span { display:block;height:100%;background:#198754; }
    </style>
</head>
<body>
<?php $page_subtitle = 'Editor de Ficha'; include 'components/jefe_topbar.php'; ?>

<main class="container-fluid py-4 px-3 px-md-4">

    <?php if ($mensaje): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= h($mensaje) ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div><?php endif; ?>
    <?php if ($faltaSQL): ?><div class="alert alert-danger"><strong>Falta actualización de base de datos:</strong> importa el archivo <code>actualizar_editor_guiado.sql</code> antes de usar el editor guiado.</div><?php endif; ?>
    <?php if ($bloqueado): ?><div class="locked mb-3"><strong><i class="bi bi-lock"></i> Edición bloqueada:</strong> esta versión ya fue publicada o tiene respuestas. Para cambiar su estructura, crea una nueva versión desde el panel de fichas.</div><?php endif; ?>

    <form id="formGeneral" method="post">
        <input type="hidden" name="accion" value="guardar_basico">
        <input type="hidden" name="id_version" value="<?= (int)$id_version ?>">

        <!-- Barra de acciones sticky -->
        <div class="sticky-actions">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h4 class="mb-1 fw-bold" style="color:var(--ugel-green-dark);"><i class="bi bi-ui-checks-grid me-2"></i><?= h($ctx['nombre']) ?></h4>
                    <div class="small text-muted">
                        Estado: <strong><?= h($ctx['estado']) ?></strong> · Versión <?= (int)$ctx['version'] ?> · Ítems: <strong id="counterItems"><?= (int)$ctx['total_items'] ?></strong><?= (int)($ctx['total_items_planificados'] ?? 0) > 0 ? ' / ' . (int)$ctx['total_items_planificados'] : '' ?>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="jefe_crear_fichas.php" class="ugel-back-link border"><i class="bi bi-arrow-left me-1"></i>Volver</a>
                    <?php if (!$bloqueado): ?>
                    <button type="submit" class="btn btn-success fw-bold"><i class="bi bi-save me-1"></i>Guardar borrador</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <!-- Columna izquierda: datos + modo guiado -->
            <div class="col-lg-4">
                <div class="jefe-card mb-4">
                    <div class="jefe-card-head"><i class="bi bi-file-earmark-text me-2"></i>Datos generales</div>
                    <div class="p-3">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Nombre de ficha</label>
                            <input name="nombre" class="form-control" value="<?= h($ctx['nombre']) ?>" <?= $bloqueado ? 'disabled' : '' ?> required>
                        </div>
                        <div class="row">
                            <div class="col-4 mb-3">
                                <label class="form-label fw-bold">Año</label>
                                <input type="number" name="anio" class="form-control" value="<?= h($ctx['anio']) ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-8 mb-3">
                                <label class="form-label fw-bold">Título de versión</label>
                                <input name="titulo_version" class="form-control" value="<?= h($ctx['titulo']) ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descripción</label>
                            <textarea name="descripcion" class="form-control" rows="3" <?= $bloqueado ? 'disabled' : '' ?>><?= h($ctx['descripcion_tipo']) ?></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fecha inicio</label>
                                <input type="datetime-local" name="fecha_inicio" class="form-control" value="<?= $ctx['fecha_inicio'] ? h(str_replace(' ', 'T', substr($ctx['fecha_inicio'], 0, 16))) : '' ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label fw-bold">Fecha límite</label>
                                <input type="datetime-local" name="fecha_limite" class="form-control" value="<?= $ctx['fecha_limite'] ? h(str_replace(' ', 'T', substr($ctx['fecha_limite'], 0, 16))) : '' ?>" <?= $bloqueado ? 'disabled' : '' ?>>
                            </div>
                        </div>
                        <div class="mb-1">
                            <label class="form-label fw-bold">Texto de consentimiento</label>
                            <textarea name="texto_consentimiento" class="form-control" rows="5" <?= $bloqueado ? 'disabled' : '' ?>><?= h($ctx['texto_consentimiento']) ?></textarea>
                        </div>
                        <div class="form-hint mt-1">El guardado del borrador está en la barra superior.</div>
                    </div>
                </div>

                <div class="jefe-card">
                    <div class="jefe-card-head"><i class="bi bi-magic me-2"></i>Modo guiado</div>
                    <div class="p-3">
                        <div class="step"><div class="step-num">1</div><div><strong>Configura estructura</strong><div class="form-hint">Define total de ítems, secciones y subgrupos.</div></div></div>
                        <div class="step"><div class="step-num">2</div><div><strong>Crea ítems en orden</strong><div class="form-hint">El sistema asigna el número automáticamente.</div></div></div>
                        <div class="step"><div class="step-num">3</div><div><strong>Publica desde el panel</strong><div class="form-hint">La ficha solo aparece a directores cuando la publicas.</div></div></div>
                    </div>
                </div>
            </div>

            <!-- Columna derecha: estructura + ítems -->
            <div class="col-lg-8">
                <div class="jefe-card mb-4">
                    <div class="jefe-card-head"><i class="bi bi-diagram-3 me-2"></i>1. Estructura inicial obligatoria</div>
                    <div class="p-3">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Total de ítems de la ficha</label>
                                <input type="number" min="1" max="250" class="form-control" id="totalItems" value="<?= (int)($ctx['total_items_planificados'] ?? 0) ?: '' ?>" <?= ($bloqueado || (int)$ctx['total_items'] > 0) ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Cantidad de secciones</label>
                                <input type="number" min="0" max="50" class="form-control" id="totalSections" value="0" <?= ($bloqueado || (int)$ctx['total_items'] > 0) ? 'disabled' : '' ?>>
                            </div>
                            <div class="col-md-4">
                                <button type="button" class="btn btn-success fw-bold w-100" id="btnBuildSections" <?= ($bloqueado || (int)$ctx['total_items'] > 0) ? 'disabled' : '' ?>>
                                    <i class="bi bi-plus-circle"></i> Preparar secciones
                                </button>
                            </div>
                        </div>
                        <div id="sectionsPlanner" class="mt-3"></div>
                        <?php if (!$bloqueado && (int)$ctx['total_items'] === 0): ?>
                        <button type="button" class="btn btn-outline-success fw-bold mt-3" id="btnSaveStructure">
                            <i class="bi bi-check2-circle"></i> Guardar estructura guiada
                        </button>
                        <?php endif; ?>
                        <div class="form-hint mt-2">Puedes poner 0 secciones si tu ficha tendrá solo ítems generales.</div>
                    </div>
                </div>

                <div class="jefe-card mb-4">
                    <div class="jefe-card-head d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-plus-square me-2"></i>2. Crear ítems en orden</span>
                        <span class="badge bg-light text-success" id="badgeNextItem">Siguiente ítem</span>
                    </div>
                    <div class="p-3">
                        <form id="itemForm">
                            <input type="hidden" name="id_version" value="<?= (int)$id_version ?>">
                            <input type="hidden" name="action" value="add_item">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Ubicación del ítem</label>
                                    <select name="id_seccion" class="form-select" id="itemSectionSelect">
                                        <option value="">Sin sección</option>
                                    </select>
                                    <div class="form-hint">Puede estar sin sección, en una sección o en un subgrupo.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Tipo de respuesta</label>
                                    <select name="tipo_respuesta" class="form-select" id="tipoRespuesta">
                                        <option value="ESCALA_1_4">Escala 1 al 4</option>
                                        <option value="NUMERO">Número</option>
                                        <option value="TEXTO">Texto</option>
                                        <option value="SI_NO">Sí / No</option>
                                        <option value="FECHA">Fecha</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label fw-bold">Texto del ítem</label>
                                    <textarea name="pregunta" class="form-control" rows="3" required placeholder="Escribe el ítem que responderá el director/subdirector"></textarea>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-check mt-2">
                                        <input class="form-check-input" type="checkbox" name="permite_no_aplica" checked>
                                        <label class="form-check-label">Permite marcar NO APLICA</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Criterio/instrucción opcional</label>
                                    <input name="criterio_instruccion" class="form-control" placeholder="Ej: Adjuntar evidencia, indicar cantidad, etc.">
                                </div>
                                <div class="col-12 rubrica-box" id="rubricaBox">
                                    <label class="form-label fw-bold">Rúbricas para escala 1 al 4</label>
                                    <div class="row g-2">
                                        <div class="col-md-6"><textarea name="rubrica_1" class="form-control" rows="2" placeholder="1 = INICIO"></textarea></div>
                                        <div class="col-md-6"><textarea name="rubrica_2" class="form-control" rows="2" placeholder="2 = PROCESO"></textarea></div>
                                        <div class="col-md-6"><textarea name="rubrica_3" class="form-control" rows="2" placeholder="3 = LOGRADO"></textarea></div>
                                        <div class="col-md-6"><textarea name="rubrica_4" class="form-control" rows="2" placeholder="4 = DESTACADO"></textarea></div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-success fw-bold" <?= ($bloqueado || $faltaSQL) ? 'disabled' : '' ?>>
                                        <i class="bi bi-save"></i> Guardar ítem actual
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div id="structureContainer">
                    <div class="jefe-card"><div class="p-4 text-center text-muted">Cargando editor...</div></div>
                </div>
            </div>
        </div>
    </form>

</main>

<div class="toast-area" id="toastArea"></div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
const ID_VERSION = <?= (int)$id_version ?>;
const BLOQUEADO  = <?= $bloqueado ? 'true' : 'false' ?>;

function toast(msg, type = 'success') {
    const area = document.getElementById('toastArea');
    const el   = document.createElement('div');
    el.className = 'alert alert-' + (type === 'error' ? 'danger' : 'success') + ' shadow';
    el.innerHTML = msg;
    area.appendChild(el);
    setTimeout(() => el.remove(), 4500);
}

async function api(data) {
    const fd = new FormData();
    Object.entries(data).forEach(([k, v]) => fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v));
    const r = await fetch('ajax_jefe_ficha_editor.php', { method: 'POST', body: fd });
    return await r.json();
}

function refreshSectionSelect(sections) {
    const sel     = document.getElementById('itemSectionSelect');
    const current = sel.value;
    sel.innerHTML  = '<option value="">Sin sección</option>';
    (sections || []).forEach(s => {
        const opt      = document.createElement('option');
        opt.value      = s.id;
        opt.textContent = (s.id_seccion_padre ? '— Subgrupo: ' : 'Sección: ') + s.titulo;
        sel.appendChild(opt);
    });
    sel.value = current;
}

function updateCounters(ctx) {
    if (!ctx) return;
    document.getElementById('counterItems').textContent = ctx.total_items || 0;
    const total  = parseInt(ctx.total_items_planificados || 0);
    const actual = parseInt(ctx.total_items || 0);
    document.getElementById('badgeNextItem').textContent = total
        ? (actual >= total ? 'Ítems completos' : 'Siguiente: ítem ' + (actual + 1) + ' de ' + total)
        : 'Siguiente ítem';
}

async function loadEditor() {
    const res = await api({ action: 'render', id_version: ID_VERSION });
    if (!res.ok) { toast(res.message, 'error'); return; }
    document.getElementById('structureContainer').innerHTML = res.html;
    refreshSectionSelect(res.sections);
    updateCounters(res.ctx);
}

document.getElementById('btnBuildSections')?.addEventListener('click', () => {
    const n   = parseInt(document.getElementById('totalSections').value || 0);
    const box = document.getElementById('sectionsPlanner');
    box.innerHTML = '';
    for (let i = 1; i <= n; i++) {
        const wrap = document.createElement('div');
        wrap.className = 'card border-success-subtle p-3 mb-2';
        wrap.innerHTML = `<div class="row g-2">
            <div class="col-md-8"><label class="form-label fw-bold">Nombre de sección ${i}</label><input class="form-control section-title-input" data-index="${i}" placeholder="Ej: Aspecto ${String(i).padStart(2,'0')}"></div>
            <div class="col-md-4"><label class="form-label fw-bold">Subgrupos</label><input type="number" min="0" max="30" class="form-control subgroup-count" data-index="${i}" value="0"></div>
            <div class="col-12 subgroup-holder" data-index="${i}"></div>
        </div>`;
        box.appendChild(wrap);
    }
    document.querySelectorAll('.subgroup-count').forEach(inp => inp.addEventListener('input', function () {
        const idx    = this.dataset.index;
        const holder = document.querySelector('.subgroup-holder[data-index="' + idx + '"]');
        const c      = parseInt(this.value || 0);
        holder.innerHTML = '';
        for (let j = 1; j <= c; j++) {
            const div = document.createElement('div');
            div.className = 'ms-4 mt-2';
            div.innerHTML = `<input class="form-control sub-name-input" placeholder="Nombre del subgrupo ${j} de la sección ${idx}">`;
            holder.appendChild(div);
        }
    }));
});

document.getElementById('btnSaveStructure')?.addEventListener('click', async () => {
    const totalItems = parseInt(document.getElementById('totalItems').value || 0);
    if (!totalItems) { toast('Indica el total de ítems de la ficha.', 'error'); return; }
    const sections = [];
    document.querySelectorAll('.section-title-input').forEach(inp => {
        const titulo = inp.value.trim();
        if (!titulo) return;
        const subs = [];
        inp.closest('.card').querySelectorAll('.sub-name-input').forEach(s => { if (s.value.trim()) subs.push(s.value.trim()); });
        sections.push({ titulo, subgrupos: subs });
    });
    const res = await api({ action: 'create_structure', id_version: ID_VERSION, total_items: totalItems, sections_json: sections });
    if (!res.ok) { toast(res.message, 'error'); return; }
    toast(res.message);
    document.getElementById('structureContainer').innerHTML = res.html;
    refreshSectionSelect(res.sections);
    updateCounters(res.ctx);
});

document.getElementById('tipoRespuesta').addEventListener('change', function () {
    document.getElementById('rubricaBox').style.display = this.value === 'ESCALA_1_4' ? 'block' : 'none';
});

document.getElementById('itemForm').addEventListener('submit', async function (e) {
    e.preventDefault();
    const fd  = new FormData(this);
    const res = await fetch('ajax_jefe_ficha_editor.php', { method: 'POST', body: fd }).then(r => r.json());
    if (!res.ok) { toast(res.message, 'error'); return; }
    toast(res.message);
    this.reset();
    document.getElementById('tipoRespuesta').value = 'ESCALA_1_4';
    document.getElementById('rubricaBox').style.display = 'block';
    document.getElementById('structureContainer').innerHTML = res.html;
    refreshSectionSelect(res.sections);
    updateCounters(res.ctx);
});

loadEditor();
</script>
</body>
</html>
