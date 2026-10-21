<?php
/* ================================================================
   jefe_agp_helpers.php — Helpers compartidos para Jefe de AGP
   ================================================================ */

function jefe_h($v) {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function jefe_require_role() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['id_usuario']))           { header('Location: login.php'); exit(); }
    if (($_SESSION['rol'] ?? '') !== 'jefe_agp')   { header('Location: login.php'); exit(); }
}

function jefe_user($conn) {
    $id   = (int)($_SESSION['id_usuario'] ?? 0);
    $stmt = $conn->prepare("SELECT id,nombres,ape_pat,ape_mat,dni,correo,telefono,rol FROM usuarios WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $u ?: [];
}

/* Renderiza la topbar usando el componente compartido.
   $titulo se mapea a $page_subtitle en el componente. */
function jefe_navbar($user, $titulo = 'Jefe de AGP') {
    $page_subtitle = $titulo;
    include __DIR__ . '/components/jefe_topbar.php';
}

function jefe_badge_estado($e) {
    return match($e) {
        'ENVIADO'    => 'bg-primary',
        'CONFORME'   => 'bg-success',
        'EN_REVISION'=> 'bg-secondary',
        'OBSERVADO'  => 'bg-warning text-dark',
        'RECHAZADO'  => 'bg-danger',
        'REVISADO'   => 'bg-success',
        default      => 'bg-light text-dark border',
    };
}

/* Scripts de pie de página: Bootstrap + auto-submit de filtros */
function jefe_footer_scripts() { ?>
<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
let submitTimer = null;
function scheduleFilterSubmit(form) {
    clearTimeout(submitTimer);
    submitTimer = setTimeout(() => form.submit(), 260);
}
document.querySelectorAll('[data-auto-submit="1"]').forEach(el => {
    el.addEventListener('change', () => scheduleFilterSubmit(el.form));
});
document.querySelectorAll('[data-filter-checkbox="1"]').forEach(cb => {
    cb.addEventListener('change', () => scheduleFilterSubmit(cb.form));
});
document.querySelectorAll('[data-clear-group]').forEach(cb => {
    cb.addEventListener('change', () => {
        const group = cb.getAttribute('data-clear-group');
        if (cb.checked) {
            document.querySelectorAll('input[name="' + group + '[]"]').forEach(x => x.checked = false);
        }
        scheduleFilterSubmit(cb.form);
    });
});
document.querySelectorAll('[data-filter-checkbox="1"]').forEach(cb => {
    cb.addEventListener('change', () => {
        const name = cb.name.replace('[]', '');
        const all  = document.querySelector('[data-clear-group="' + name + '"]');
        if (all) all.checked = false;
    });
});
</script>
<?php }

/* ── Helpers de filtros ────────────────────────────────────────── */

function jf_options($res, $valueKey, $labelCb) {
    $out = [];
    while ($r = $res->fetch_assoc()) {
        $out[] = ['value' => (string)$r[$valueKey], 'label' => $labelCb($r)];
    }
    return $out;
}

function jf_filter_dropdown($name, $label, $options, $selected, $allLabel = 'Todos') {
    $count = count($selected);
    $text  = $count === 0 ? $allLabel : ($count . ' seleccionado' . ($count > 1 ? 's' : ''));
    ?>
    <div class="dropdown w-100">
        <label class="form-label fw-bold text-success mb-1"><?= jefe_h($label) ?></label>
        <button class="filter-dd-btn" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside">
            <span><?= jefe_h($text) ?></span><i class="bi bi-chevron-down"></i>
        </button>
        <div class="dropdown-menu filter-menu p-2">
            <label class="filter-option fw-bold text-success border-bottom mb-1 pb-2">
                <input type="checkbox" data-clear-group="<?= jefe_h($name) ?>" <?= $count === 0 ? 'checked' : '' ?>>
                <?= jefe_h($allLabel) ?>
            </label>
            <?php foreach ($options as $op): ?>
            <label class="filter-option">
                <input type="checkbox"
                       name="<?= jefe_h($name) ?>[]"
                       value="<?= jefe_h($op['value']) ?>"
                       data-filter-checkbox="1"
                       <?= in_array((string)$op['value'], $selected, true) ? 'checked' : '' ?>>
                <span><?= jefe_h($op['label']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

/* ── Helpers de query ──────────────────────────────────────────── */

function jefe_arr($name) {
    $v = $_GET[$name] ?? [];
    if (!is_array($v)) $v = [$v];
    return array_values(array_filter(array_map('trim', $v), fn($x) => $x !== ''));
}

function jefe_add_in(&$where, &$params, &$types, $column, $values, $type = 'i') {
    if (!$values) return;
    $ph = implode(',', array_fill(0, count($values), '?'));
    $where[] = "$column IN ($ph)";
    foreach ($values as $v) {
        $params[] = $type === 'i' ? (int)$v : (string)$v;
        $types   .= $type;
    }
}

/* ── Descarga ZIP ──────────────────────────────────────────────── */

function jefe_zip_download($files, $zipName) {
    if (!class_exists('ZipArchive')) die('ZipArchive no está habilitado. Active la extensión zip en PHP.');
    $zipPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $zipName;
    $zip     = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) die('No se pudo crear el ZIP.');
    $added = 0;
    foreach ($files as $f) {
        $ruta = $f['ruta'] ?? '';
        if (!$ruta) continue;
        $full = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['../', '/'], ['', DIRECTORY_SEPARATOR], $ruta);
        if (is_file($full)) { $zip->addFile($full, ($f['nombre'] ?? basename($full))); $added++; }
    }
    $zip->close();
    if ($added === 0) die('No se encontraron archivos con los filtros seleccionados.');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zipName . '"');
    header('Content-Length: ' . filesize($zipPath));
    readfile($zipPath);
    @unlink($zipPath);
    exit;
}
