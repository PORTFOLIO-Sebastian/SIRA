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

asistencia_layout_header('Registro de Asistencia', $mensual, $mes, $anio);
?>
<?php if (!empty($_GET['ok'])): ?>
    <div class="alert alert-success asistencia-alert shadow-sm"><i class="bi bi-check-circle-fill me-2"></i> Asistencia guardada correctamente.</div>
<?php endif; ?>

<style>
:root {
    --fluent-green-bg: #dff6dd;
    --fluent-green-fg: #107c41;
    --fluent-red-bg: #fde7e9;
    --fluent-red-fg: #a80000;
    --fluent-yellow-bg: #fff4ce;
    --fluent-yellow-fg: #814e00;
    --fluent-purple-bg: #eae1f4;
    --fluent-purple-fg: #5c2d91;
    --fluent-blue-bg: #deecf9;
    --fluent-blue-fg: #0078d4;
    --fluent-teal-bg: #daf3f0;
    --fluent-teal-fg: #008272;
    --fluent-dark-bg: #f3f2f1;
    --fluent-dark-fg: #323130;
}

.cell-laborable {
    width: 44px;
    height: 44px;
    min-width: 44px;
    text-align: center;
    vertical-align: middle;
    cursor: pointer;
    background: #ffffff;
    transition: all 0.1s ease;
    padding: 0 !important;
    position: relative;
    user-select: none;
    border: 1px solid #e0e0e0 !important;
}

.cell-laborable:hover {
    background: #f3f2f1;
}

.cell-laborable:focus {
    outline: 2px solid #0078d4 !important;
    outline-offset: -2px;
    box-shadow: inset 0 0 0 1px #0078d4;
    z-index: 10;
}

.cell-display {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    font-weight: 700;
}

/* Custom background and text colors based on Fluent Design */
.bg-a { background-color: var(--fluent-green-bg) !important; color: var(--fluent-green-fg) !important; }
.bg-i { background-color: var(--fluent-red-bg) !important; color: var(--fluent-red-fg) !important; }
.bg-j { background-color: var(--fluent-yellow-bg) !important; color: var(--fluent-yellow-fg) !important; }
.bg-l { background-color: var(--fluent-purple-bg) !important; color: var(--fluent-purple-fg) !important; }
.bg-p { background-color: var(--fluent-blue-bg) !important; color: var(--fluent-blue-fg) !important; }
.bg-t { background-color: var(--fluent-teal-bg) !important; color: var(--fluent-teal-fg) !important; }
.bg-h { background-color: var(--fluent-dark-bg) !important; color: var(--fluent-dark-fg) !important; }

/* Custom button styles in popover */
.btn-a { background-color: var(--fluent-green-bg) !important; color: var(--fluent-green-fg) !important; border: 1px solid #c3f2c0 !important; }
.btn-a:hover { background-color: #c9ebd0 !important; }
.btn-i { background-color: var(--fluent-red-bg) !important; color: var(--fluent-red-fg) !important; border: 1px solid #fad1d4 !important; }
.btn-i:hover { background-color: #f7ced2 !important; }
.btn-j { background-color: var(--fluent-yellow-bg) !important; color: var(--fluent-yellow-fg) !important; border: 1px solid #ffe9a6 !important; }
.btn-j:hover { background-color: #ffe08a !important; }
.btn-l { background-color: var(--fluent-purple-bg) !important; color: var(--fluent-purple-fg) !important; border: 1px solid #e1d3f0 !important; }
.btn-l:hover { background-color: #d1bfe5 !important; }
.btn-p { background-color: var(--fluent-blue-bg) !important; color: var(--fluent-blue-fg) !important; border: 1px solid #c7e0f4 !important; }
.btn-p:hover { background-color: #b4d6f1 !important; }
.btn-t { background-color: var(--fluent-teal-bg) !important; color: var(--fluent-teal-fg) !important; border: 1px solid #bfece5 !important; }
.btn-t:hover { background-color: #aae4da !important; }
.btn-h { background-color: var(--fluent-dark-bg) !important; color: var(--fluent-dark-fg) !important; border: 1px solid #e1dfdd !important; }
.btn-h:hover { background-color: #d3d1cf !important; }

.asistencia-legend .badge {
    padding: 6px 12px;
    font-size: 0.8rem;
}
.weekend {
    background-color: #f3f2f1 !important;
    color: #a19f9d !important;
    vertical-align: middle;
}
</style>

<div class="card asistencia-card asistencia-table-card">
    <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center bg-white border-bottom py-3">
        <div>
            <h5 class="asistencia-title fw-bold text-dark"><i class="bi bi-grid-3x3-gap-fill text-success"></i> Control de Asistencia</h5>
            <small class="asistencia-muted">Haz clic en una celda para cambiar el estado, usa el teclado (A, I, J, L, P, T, H, Supr) o las flechas de dirección para navegar rápido.</small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" id="btn-fill-all-a" class="btn btn-success fw-bold"><i class="bi bi-magic"></i> Auto-rellenar con "A"</button>
            <button type="button" id="btn-clear-all" class="btn btn-outline-danger"><i class="bi bi-trash"></i> Limpiar Todo</button>
            <a class="btn btn-outline-primary" href="<?php echo asistencia_url('personal.php', $mes, $anio); ?>"><i class="bi bi-people"></i> Personal</a>
        </div>
    </div>
    <div class="card-body">
        <div class="asistencia-legend mb-3">
            <strong class="me-2 text-muted">Leyenda:</strong>
            <span class="badge btn-a fw-bold">A - Dia laborado</span>
            <span class="badge btn-i fw-bold">I - Inasistencia</span>
            <span class="badge btn-j fw-bold">J - Justificada</span>
            <span class="badge btn-l fw-bold">L - Licencia</span>
            <span class="badge btn-p fw-bold">P - Permiso</span>
            <span class="badge btn-t fw-bold">T - Tardanza</span>
            <span class="badge btn-h fw-bold">H - Huelga/paro</span>
        </div>
        <?php if (!$personal): ?>
            <div class="alert alert-warning asistencia-alert">Primero registre personal activo para esta institucion.</div>
        <?php else: ?>
            <form action="guardar_asistencia.php" method="POST" id="asistenciaForm">
                <input type="hidden" name="mes" value="<?php echo (int)$mes; ?>">
                <input type="hidden" name="anio" value="<?php echo (int)$anio; ?>">
                <div class="table-responsive border rounded-2 shadow-sm" style="max-height:70vh;">
                    <table class="table table-bordered table-sm asistencia-grid table-sticky align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="worker-cell text-center" style="background:#f8f9fa; color:#333; font-weight:700;">Trabajador</th>
                                <?php foreach ($calendario as $dia): ?>
                                    <?php 
                                    $numClass = $dia['laborable'] ? 'text-white' : 'text-warning';
                                    $subStyle = $dia['laborable'] ? 'color: rgba(255,255,255,0.75);' : 'color: rgba(255,193,7,0.85);';
                                    $bgHeader = $dia['laborable'] ? '#1a5c38' : '#0d3c22';
                                    ?>
                                    <th class="text-center asistencia-day" style="font-size:0.75rem; background-color: <?php echo $bgHeader; ?> !important; vertical-align: middle;">
                                        <span class="fw-bold <?php echo $numClass; ?> d-block" style="font-size:0.95rem;"><?php echo (int)$dia['dia']; ?></span>
                                        <small style="<?php echo $subStyle; ?>" class="text-uppercase"><?php echo asistencia_h(substr($dia['semana'], 0, 2)); ?></small>
                                    </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($personal as $trabajador): ?>
                                <tr>
                                    <td class="worker-cell py-2 px-3">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div class="pe-2">
                                                <strong class="text-dark d-block" style="font-size:0.88rem;"><?php echo asistencia_h($trabajador['apellidos_nombres']); ?></strong>
                                                <small class="text-muted d-block" style="font-size:0.75rem;"><?php echo asistencia_h($trabajador['dni'] . ' - ' . $trabajador['cargo']); ?></small>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-outline-success py-1 px-2 btn-fill-row-a" data-row="<?php echo $trabajador['id']; ?>" title="Rellenar vacíos de este trabajador con 'A'">
                                                <i class="bi bi-lightning-fill"></i> Rell. A
                                            </button>
                                        </div>
                                        <div class="mt-2">
                                            <input type="text" name="observaciones[<?php echo $trabajador['id']; ?>]" class="form-control form-control-sm" placeholder="Obs. (Opcional)..." value="<?php echo htmlspecialchars($trabajador['observaciones'] ?? ''); ?>" style="font-size:0.75rem; height: 26px; min-height: 26px; padding: 2px 6px; border-color: #cbd5e1;">
                                        </div>
                                    </td>
                                    <?php foreach ($calendario as $dia): ?>
                                        <?php 
                                        $idx_row = $trabajador['id'];
                                        $idx_col = (int)$dia['dia'];
                                        $valor = $detalle[$idx_row][$idx_col] ?? ''; 
                                        ?>
                                        <td class="<?php echo $dia['laborable'] ? 'cell-laborable' : 'weekend text-center'; ?>" 
                                            <?php if ($dia['laborable']): ?>
                                                tabindex="0"
                                                data-row="<?php echo $idx_row; ?>"
                                                data-col="<?php echo $idx_col; ?>"
                                                data-personal="<?php echo $trabajador['id']; ?>"
                                                data-dia="<?php echo $dia['dia']; ?>"
                                            <?php endif; ?>>
                                            <?php if ($dia['laborable']): ?>
                                                <div class="cell-display"><?php echo $valor; ?></div>
                                                <input type="hidden" class="cell-input" name="asistencia[<?php echo $trabajador['id']; ?>][<?php echo $dia['dia']; ?>]" value="<?php echo $valor; ?>">
                                            <?php else: ?>
                                                <span class="text-muted fw-bold" style="font-size: 0.72rem; letter-spacing: 0.05em;">FIN</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-end mt-3">
                    <button class="btn btn-orange btn-lg px-4 shadow-sm" type="submit"><i class="bi bi-cloud-arrow-up-fill me-2"></i> Guardar Cambios</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<!-- Floating Micro-Popover Panel -->
<div id="cell-popover" class="shadow-lg border rounded-3 p-2 bg-white position-absolute d-none" style="z-index: 1050; min-width: 250px;">
    <div class="d-flex flex-wrap gap-1 justify-content-center">
        <button type="button" class="btn btn-sm btn-a fw-bold" data-code="A" style="width: 70px;">A</button>
        <button type="button" class="btn btn-sm btn-i fw-bold" data-code="I" style="width: 70px;">I</button>
        <button type="button" class="btn btn-sm btn-j fw-bold" data-code="J" style="width: 70px;">J</button>
        <button type="button" class="btn btn-sm btn-l fw-bold" data-code="L" style="width: 70px;">L</button>
        <button type="button" class="btn btn-sm btn-p fw-bold" data-code="P" style="width: 70px;">P</button>
        <button type="button" class="btn btn-sm btn-t fw-bold" data-code="T" style="width: 70px;">T</button>
        <button type="button" class="btn btn-sm btn-h fw-bold" data-code="H" style="width: 70px;">H</button>
        <button type="button" class="btn btn-sm btn-light border fw-bold text-danger w-100 mt-1" data-code="">Limpiar Celda</button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const popover = document.getElementById('cell-popover');
    let activeCell = null;

    // Helper to update cell styling based on value
    function updateCellVisual(cell, value) {
        // Remove old classes
        cell.classList.remove('bg-a', 'bg-i', 'bg-j', 'bg-l', 'bg-p', 'bg-t', 'bg-h');
        
        const display = cell.querySelector('.cell-display');
        const input = cell.querySelector('.cell-input');
        if (!display || !input) return;

        input.value = value;
        display.textContent = value || '';

        if (value) {
            cell.classList.add('bg-' + value.toLowerCase());
        }
    }

    // Initialize all cells on load
    document.querySelectorAll('.cell-laborable').forEach(cell => {
        const val = cell.querySelector('.cell-input').value;
        if (val) {
            cell.classList.add('bg-' + val.toLowerCase());
        }

        // Click to focus and open popover
        cell.addEventListener('click', function(e) {
            focusCell(cell);
            showPopover(cell);
            e.stopPropagation();
        });

        // Keydown handlers for arrow navigation and typing
        cell.addEventListener('keydown', function(e) {
            const row = cell.dataset.row;
            const col = parseInt(cell.dataset.col);
            let target = null;

            switch(e.key) {
                case 'ArrowLeft': {
                    let prevCol = col - 1;
                    while (prevCol >= 1) {
                        target = document.querySelector(`.cell-laborable[data-row="${row}"][data-col="${prevCol}"]`);
                        if (target) break;
                        prevCol--;
                    }
                    if (target) { target.focus(); e.preventDefault(); }
                    break;
                }
                case 'ArrowRight': {
                    let nextCol = col + 1;
                    const maxDays = 31;
                    while (nextCol <= maxDays) {
                        target = document.querySelector(`.cell-laborable[data-row="${row}"][data-col="${nextCol}"]`);
                        if (target) break;
                        nextCol++;
                    }
                    if (target) { target.focus(); e.preventDefault(); }
                    break;
                }
                case 'ArrowUp': {
                    const colCells = Array.from(document.querySelectorAll(`.cell-laborable[data-col="${col}"]`));
                    const idx = colCells.indexOf(cell);
                    if (idx > 0) {
                        colCells[idx - 1].focus();
                        e.preventDefault();
                    }
                    break;
                }
                case 'ArrowDown': {
                    const colCells = Array.from(document.querySelectorAll(`.cell-laborable[data-col="${col}"]`));
                    const idx = colCells.indexOf(cell);
                    if (idx !== -1 && idx < colCells.length - 1) {
                        colCells[idx + 1].focus();
                        e.preventDefault();
                    }
                    break;
                }
                case 'Backspace':
                case 'Delete':
                    updateCellVisual(cell, '');
                    e.preventDefault();
                    break;
                default:
                    const key = e.key.toUpperCase();
                    if (['A', 'I', 'J', 'L', 'P', 'T', 'H'].includes(key)) {
                        updateCellVisual(cell, key);
                        // Auto advance right
                        let nextCol = col + 1;
                        while (nextCol <= 31) {
                            target = document.querySelector(`.cell-laborable[data-row="${row}"][data-col="${nextCol}"]`);
                            if (target) break;
                            nextCol++;
                        }
                        if (target) { target.focus(); }
                        e.preventDefault();
                    }
                    break;
            }
        });
    });

    function focusCell(cell) {
        activeCell = cell;
        cell.focus();
    }

    function showPopover(cell) {
        popover.classList.remove('d-none');
        const rect = cell.getBoundingClientRect();
        const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        
        popover.style.top = (rect.bottom + scrollTop + 6) + 'px';
        popover.style.left = (rect.left + scrollLeft - 100) + 'px';
    }

    // Popover click buttons
    popover.querySelectorAll('button').forEach(btn => {
        btn.addEventListener('click', function() {
            if (activeCell) {
                const code = btn.dataset.code;
                updateCellVisual(activeCell, code);
                activeCell.focus();
            }
            popover.classList.add('d-none');
        });
    });

    // Close popover when clicking outside
    document.addEventListener('click', function(e) {
        if (!popover.contains(e.target) && (!activeCell || !activeCell.contains(e.target))) {
            popover.classList.add('d-none');
        }
    });

    // Row-level autofill
    document.querySelectorAll('.btn-fill-row-a').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            const rowId = btn.dataset.row;
            document.querySelectorAll(`.cell-laborable[data-personal="${rowId}"]`).forEach(cell => {
                const input = cell.querySelector('.cell-input');
                if (input && input.value === '') {
                    updateCellVisual(cell, 'A');
                }
            });
        });
    });

    // Global operations
    const btnFillAll = document.getElementById('btn-fill-all-a');
    if (btnFillAll) {
        btnFillAll.addEventListener('click', function() {
            if (confirm('¿Deseas rellenar todos los días laborables vacíos con "A" (Día laborado)?')) {
                document.querySelectorAll('.cell-laborable').forEach(cell => {
                    const input = cell.querySelector('.cell-input');
                    if (input && input.value === '') {
                        updateCellVisual(cell, 'A');
                    }
                });
            }
        });
    }

    const btnClearAll = document.getElementById('btn-clear-all');
    if (btnClearAll) {
        btnClearAll.addEventListener('click', function() {
            if (confirm('¿Deseas limpiar todos los registros de asistencia de esta grilla?')) {
                document.querySelectorAll('.cell-laborable').forEach(cell => {
                    updateCellVisual(cell, '');
                });
            }
        });
    }
});
</script>

<?php asistencia_layout_footer(); ?>
