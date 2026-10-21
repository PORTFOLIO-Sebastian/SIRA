<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit();
}
$es_especialista = ($_SESSION['rol'] === 'especialista');
$es_jefe = ($_SESSION['rol'] === 'jefe_agp');

if (!$es_especialista && !$es_jefe) {
    header('Location: dashboard.php');
    exit();
}

$id_usuario = (int) $_SESSION['id_usuario'];

// --- Variables dinámicas por rol ---
if ($es_jefe) {
    $dashboard_url = 'dashboard_jefe_agp.php';
    $volver_url = 'jefe_ficha_busqueda_menu.php';
    $volver_texto = 'Volver a Búsqueda / Visualización';
    $rol_label = 'JEFE DE AGP';
    $total_label = 'Total I.E.';
} else {
    $dashboard_url = 'dashboard_especialista.php';
    $volver_url = 'especialista_fichas.php';
    $volver_texto = 'Volver a Fichas';
    $rol_label = 'Especialista';
    $total_label = 'IE Asignadas';
}

function h_r($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Datos del usuario
$stmt = $conn->prepare("SELECT nombres, ape_pat, ape_mat, dni, correo FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nombre = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = h_r($user['nombres'] ?? 'Usuario');

// --- QUERY PRINCIPAL: Ajustada por Rol ---
$sql_datos = "
    SELECT
        i.id                                        AS id_ie,
        IFNULL(i.codigo_local, '')                  AS cod_local,
        IFNULL(i.codigo_institucion,'')             AS cod_institucion,
        i.nombre_ie                                 AS nombre,
        UPPER(TRIM(IFNULL(i.nivel_modalidad,'')))   AS nivel,
        UPPER(TRIM(IFNULL(i.distrito,'')))           AS distrito,

        r.id                                        AS id_registro,
        r.estado                                    AS estado_registro,
        r.fecha_envio                               AS autoeval_fecha,
        r.porcentaje                                AS score_dir,
        r.escala_valorativa                         AS logro_dir,
        CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS autoevaluada,

        e.id                                        AS id_evaluacion,
        e.fecha_finalizacion                        AS eval_fecha,
        e.porcentaje_eval                           AS score_esp,
        e.escala_valorativa_eval                    AS logro_esp,
        CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END AS evaluada_especialista
    FROM instituciones i
";

if ($es_especialista) {
    $sql_datos .= " INNER JOIN asignaciones_especialistas ae ON ae.id_institucion = i.id AND ae.id_usuario = ? AND ae.estado = 'activo' ";
}

$sql_datos .= "
    LEFT JOIN fm_registros r ON r.id_institucion = i.id
        AND r.activo = 1
        AND r.estado IN ('ENVIADO','CONFORME','REVISADO','OBSERVADO','EN_REVISION','RECHAZADO')
        AND r.id = (
            SELECT r2.id FROM fm_registros r2
            WHERE r2.id_institucion = i.id AND r2.activo = 1
            ORDER BY r2.fecha_envio DESC LIMIT 1
        )
    LEFT JOIN fm_evaluaciones e ON e.id_registro = r.id
        AND e.estado_evaluacion = 'FINALIZADA'
    ORDER BY i.distrito ASC, i.nombre_ie ASC
";

$st = $conn->prepare($sql_datos);
if ($es_especialista) {
    $st->bind_param('i', $id_usuario);
}
$st->execute();
$res_datos = $st->get_result();
$instituciones_data = [];
while ($row = $res_datos->fetch_assoc()) {
    $instituciones_data[] = $row;
}
$st->close();

$total_ie = count($instituciones_data);

function normalizar_logro(?string $raw): ?string
{
    if ($raw === null)
        return null;
    $r = strtoupper(trim($raw));
    if (str_contains($r, 'DESTACADO'))
        return 'DESTACADO';
    if (str_contains($r, 'LOGRADO') || str_contains($r, 'PREVISTO'))
        return 'LOGRADO';
    if (str_contains($r, 'PROCESO'))
        return 'PROCESO';
    if (str_contains($r, 'INICIO'))
        return 'INICIO';
    return null;
}

function extraer_tipo_institucion(?string $cod_local, ?string $cod_institucion): string
{
    $codigo = strtoupper(trim((string) ($cod_local ?: $cod_institucion)));
    if (preg_match('/(?:^|_)(EBR|CEBA|ETP)$/', $codigo, $m)) {
        return $m[1];
    }
    return 'OTRO';
}

$js_data = [];
foreach ($instituciones_data as $r) {
    $logro_norm = normalizar_logro($r['logro_esp'] ?? null) ?? normalizar_logro($r['logro_dir'] ?? null);
    $js_data[] = [
        'cod_local' => $r['cod_local'] ?: ($r['cod_institucion'] ?: '—'),
        'tipo_institucion' => extraer_tipo_institucion($r['cod_local'] ?? '', $r['cod_institucion'] ?? ''),
        'nombre' => $r['nombre'],
        'nivel' => $r['nivel'] ?: 'OTRO',
        'distrito' => $r['distrito'] ?: 'SIN DISTRITO',
        'estado_registro' => $r['estado_registro'] ?? null,
        'autoevaluada' => (bool) $r['autoevaluada'],
        'autoevaluada_fecha' => $r['autoeval_fecha'] ? date('Y-m-d', strtotime($r['autoeval_fecha'])) : null,
        'score_dir' => $r['score_dir'] !== null ? round((float) $r['score_dir'], 1) : null,
        'evaluada_especialista' => (bool) $r['evaluada_especialista'],
        'evaluada_fecha' => $r['eval_fecha'] ? date('Y-m-d', strtotime($r['eval_fecha'])) : null,
        'score_esp' => $r['score_esp'] !== null ? round((float) $r['score_esp'], 1) : null,
        'logro' => $logro_norm,
        '_id_registro' => (int) ($r['id_registro'] ?? 0),
        '_id_evaluacion' => (int) ($r['id_evaluacion'] ?? 0),
    ];
}

// --- ANÁLISIS REAL DE ÍTEMS Y SECCIONES --------------------------------
$reg_ids = array_filter(array_column($js_data, '_id_registro'), fn($v) => $v > 0);
$eval_ids = array_filter(array_column($js_data, '_id_evaluacion'), fn($v) => $v > 0);

$items_por_registro = [];
$items_por_evaluacion = [];
$secciones_por_registro = [];
$secciones_por_evaluacion = [];

if (!empty($reg_ids)) {
    $in_reg = implode(',', array_map('intval', $reg_ids));
    $rq = $conn->query("
        SELECT rd.id_registro, rd.id_item, rd.respuesta_escala,
               fi.pregunta, fi.numero_item, fs.titulo AS seccion_titulo
        FROM fm_respuestas_director rd
        INNER JOIN fm_items fi ON fi.id = rd.id_item
        LEFT JOIN fm_secciones fs ON fs.id = fi.id_seccion
        WHERE rd.id_registro IN ($in_reg)
          AND (rd.no_aplica = 0 OR rd.no_aplica IS NULL)
          AND rd.respuesta_escala IS NOT NULL
          AND fi.tipo_respuesta = 'ESCALA_1_4'
    ");
    if ($rq) {
        while ($rr = $rq->fetch_assoc()) {
            $items_por_registro[(int) $rr['id_registro']][] = [
                'pregunta' => $rr['pregunta'],
                'escala' => (int) $rr['respuesta_escala'],
                'num' => $rr['numero_item'],
            ];
            $seccion_t = $rr['seccion_titulo'] ?: 'Sin Sección';
            $secciones_por_registro[(int) $rr['id_registro']][$seccion_t][] = (float) $rr['respuesta_escala'];
        }
    }
}

if (!empty($eval_ids)) {
    $in_eval = implode(',', array_map('intval', $eval_ids));
    $eq = $conn->query("
        SELECT re.id_evaluacion, re.id_item, re.respuesta_escala,
               fi.pregunta, fi.numero_item, fs.titulo AS seccion_titulo
        FROM fm_respuestas_especialista re
        INNER JOIN fm_items fi ON fi.id = re.id_item
        LEFT JOIN fm_secciones fs ON fs.id = fi.id_seccion
        WHERE re.id_evaluacion IN ($in_eval)
          AND (re.no_aplica = 0 OR re.no_aplica IS NULL)
          AND re.respuesta_escala IS NOT NULL
          AND fi.tipo_respuesta = 'ESCALA_1_4'
    ");
    if ($eq) {
        while ($rr = $eq->fetch_assoc()) {
            $items_por_evaluacion[(int) $rr['id_evaluacion']][] = [
                'pregunta' => $rr['pregunta'],
                'escala' => (int) $rr['respuesta_escala'],
                'num' => $rr['numero_item'],
            ];
            $seccion_t = $rr['seccion_titulo'] ?: 'Sin Sección';
            $secciones_por_evaluacion[(int) $rr['id_evaluacion']][$seccion_t][] = (float) $rr['respuesta_escala'];
        }
    }
}

function extraer_extremos(array $lista): array
{
    if (empty($lista)) {
        return ['alto' => null, 'bajo' => null];
    }
    
    $altos = [];
    $bajos = [];
    
    // Ordenar los ítems por su número para que aparezcan en orden correlativo (Ítem 1, Ítem 2, etc.)
    usort($lista, function($a, $b) {
        $numA = isset($a['num']) ? (int)$a['num'] : 0;
        $numB = isset($b['num']) ? (int)$b['num'] : 0;
        return $numA <=> $numB;
    });
    
    foreach ($lista as $it) {
        $num = $it['num'] ?? '?';
        $escala = (int) $it['escala'];
        $pregunta = $it['pregunta'] ?? '';
        
        $texto = 'Ítem ' . $num . ' (Nivel ' . $escala . '): ' . $pregunta;
        
        if ($escala === 3 || $escala === 4) {
            $altos[] = $texto;
        } elseif ($escala === 1 || $escala === 2) {
            $bajos[] = $texto;
        }
    }
    
    return [
        'alto' => !empty($altos) ? implode("\n", $altos) : null,
        'bajo' => !empty($bajos) ? implode("\n", $bajos) : null,
    ];
}

foreach ($js_data as &$entry) {
    $id_reg = (int) $entry['_id_registro'];
    $id_eval = (int) $entry['_id_evaluacion'];

    // Preferir respuestas del especialista si existen
    if ($id_eval > 0 && isset($items_por_evaluacion[$id_eval])) {
        $extremos = extraer_extremos($items_por_evaluacion[$id_eval]);
    } elseif ($id_reg > 0 && isset($items_por_registro[$id_reg])) {
        $extremos = extraer_extremos($items_por_registro[$id_reg]);
    } else {
        $extremos = ['alto' => null, 'bajo' => null];
    }

    // Calcular promedios por sección
    $seccion_avg_dir = [];
    if ($id_reg > 0 && isset($secciones_por_registro[$id_reg])) {
        foreach ($secciones_por_registro[$id_reg] as $sec => $scores) {
            $seccion_avg_dir[$sec] = array_sum($scores) / count($scores);
        }
    }

    $seccion_avg_esp = [];
    if ($id_eval > 0 && isset($secciones_por_evaluacion[$id_eval])) {
        foreach ($secciones_por_evaluacion[$id_eval] as $sec => $scores) {
            $seccion_avg_esp[$sec] = array_sum($scores) / count($scores);
        }
    }

    // Respuestas detalladas por item (escala 1..4)
    $respuestas_items = [];
    if ($id_eval > 0 && isset($items_por_evaluacion[$id_eval])) {
        foreach ($items_por_evaluacion[$id_eval] as $it) {
            if ($it['num'] !== null) {
                $respuestas_items[(int)$it['num']] = (int)$it['escala'];
            }
        }
    } elseif ($id_reg > 0 && isset($items_por_registro[$id_reg])) {
        foreach ($items_por_registro[$id_reg] as $it) {
            if ($it['num'] !== null) {
                $respuestas_items[(int)$it['num']] = (int)$it['escala'];
            }
        }
    }

    $entry['item_alto'] = $extremos['alto'];
    $entry['item_bajo'] = $extremos['bajo'];
    $entry['secciones_dir'] = $seccion_avg_dir;
    $entry['secciones_esp'] = $seccion_avg_esp;
    $entry['respuestas_items'] = (object)$respuestas_items;

    unset($entry['_id_registro'], $entry['_id_evaluacion']);
}
unset($entry);

$distritos = array_values(array_unique(array_filter(array_column($js_data, 'distrito'), fn($d) => $d !== 'SIN DISTRITO')));
sort($distritos);
$niveles = array_values(array_unique(array_filter(array_column($js_data, 'nivel'), fn($n) => $n !== 'OTRO')));
sort($niveles);

if (empty($distritos)) {
    $distritos = ['ALTO LARAN', 'CHAVIN', 'CHINCHA ALTA', 'EL CARMEN', 'GROCIO PRADO', 'PUEBLO NUEVO', 'SAN JUAN DE YANAC', 'SAN PEDRO DE HUACARPANA', 'SUNAMPE', 'TAMBO DE MORA'];
}
if (empty($niveles)) {
    $niveles = ['INICIAL', 'PRIMARIA', 'SECUNDARIA'];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <?php
    $page_title = $es_jefe ? 'Reporte General de Monitoreo - Jefe AGP' : 'Reporte de Monitoreo - Especialista';
    $extra_head = '
    <!-- Carga Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .ugel-shell {
            max-width: 1300px;
            margin: 0 auto;
        }
        .report-page-title { color: #0f172a; letter-spacing: 0; }
        .report-actions { flex-wrap: wrap; }
        .report-action-btn { min-height: 38px; border-radius: 4px !important; }
        .chart-plot { position: relative; width: 100%; height: 280px; }
        .chart-plot.is-tall { height: 330px; }
        .chart-plot.is-wide { height: 300px; }
        .report-chart-row { align-items: stretch; }
        .report-table-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        @media (max-width: 991.98px) { .chart-plot, .chart-plot.is-tall, .chart-plot.is-wide { height: 300px; } }
        @media (max-width: 575.98px) { .chart-plot, .chart-plot.is-tall, .chart-plot.is-wide { height: 260px; } }
        /* Dashboard premium custom styles */
        .metric-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px 18px;
            position: relative;
            overflow: hidden;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            min-height: 126px;
        }
        .metric-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -2px rgba(0,0,0,0.04);
            border-color: var(--ugel-green-border);
        }
        .metric-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--ugel-green-dark);
            opacity: 0;
            transition: opacity 0.2s;
        }
        .metric-card:hover::before {
            opacity: 1;
        }
        .metric-card.is-blue::before { background: var(--ugel-blue); }
        .metric-card.is-orange::before { background: var(--ugel-orange); }
        .metric-card.is-yellow::before { background: var(--ugel-yellow); }
        
        .metric-icon-wrapper {
            width: 44px;
            height: 44px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin-bottom: 12px;
        }
        .metric-icon-green { background: #e8f5ee; color: var(--ugel-green-dark); }
        .metric-icon-blue { background: #e8f0fe; color: var(--ugel-blue); }
        .metric-icon-orange { background: #fff3e8; color: var(--ugel-orange); }
        .metric-icon-yellow { background: #fffde7; color: var(--ugel-yellow); }

        .metric-value {
            font-size: 1.85rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1.2;
        }
        .metric-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 500;
            margin-bottom: 0;
        }
        
        .chart-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
            height: 100%;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chart-card-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Logros Badges */
        .badge-logro {
            font-size: 0.75rem;
            padding: 5px 9px;
            border-radius: 4px;
            font-weight: 600;
            display: inline-block;
        }
        .bg-destacado { background-color: #dff6dd; color: #107c41; border: 1px solid #a8d5b5; }
        .bg-previsto, .bg-logrado { background-color: #e8f0fe; color: #1a56db; border: 1px solid #adc6ff; }
        .bg-proceso { background-color: #fffde7; color: #b8860b; border: 1px solid #ffd54f; }
        .bg-inicio { background-color: #fdecea; color: #c62828; border: 1px solid #ffcdd2; }

        .filter-section {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        
        /* Table enhancements */
        .table-custom { border-collapse: separate; border-spacing: 0; }
        .table-custom th {
            font-weight: 600;
            color: #475569;
            background-color: #f8fafc;
            border-bottom: 2px solid #e2e8f0;
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            white-space: nowrap;
            padding: 12px 14px;
            vertical-align: middle;
        }
        .table-custom td {
            font-size: 0.86rem;
            color: #334155;
            vertical-align: middle;
            border-bottom: 1px solid #f1f5f9;
            padding: 12px 14px;
        }
        .table-custom tbody tr { transition: background-color 0.15s ease; }
        .table-custom tbody tr:hover { background-color: #f8fafc; }

        /* Código local: chip neutro (en vez de <code> rosado por defecto) */
        .cod-chip {
            display: inline-block;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: 0.78rem;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 2px 8px;
            white-space: nowrap;
        }
        .ie-name { font-weight: 600; color: #1e293b; }
        .distrito-text { color: #475569; white-space: nowrap; }

        /* Badge de nivel */
        .nivel-badge {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 600;
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            border-radius: 5px;
            padding: 3px 9px;
            white-space: nowrap;
            letter-spacing: 0.02em;
        }

        /* Pills de estado (celdas pendientes / no realizadas) */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 500;
            color: #64748b;
            white-space: nowrap;
        }
        .status-pill::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #cbd5e1;
            flex: 0 0 auto;
        }
        .status-pill.is-pending::before { background: #f59e0b; }

        /* Celda de indicadores destacados / críticos */
        .indic-cell { min-width: 250px; }
        .indic-line { display: flex; align-items: flex-start; gap: 6px; line-height: 1.35; font-size: 0.82rem; white-space: pre-line; }
        .indic-line + .indic-line { margin-top: 5px; }
        .indic-line i { margin-top: 2px; flex: 0 0 auto; }
        .indic-empty { color: #94a3b8; font-style: italic; font-size: 0.82rem; }
        
        .progress-bar-thin {
            height: 6px;
            border-radius: 3px;
        }

        .toast-notification {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e293b;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            gap: 8px;
            z-index: 1050;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .toast-notification.show {
            transform: translateY(0);
            opacity: 1;
        }
        @media (min-width: 768px) {
            .ugel-radar-legend {
                border-left: 1px solid #e2e8f0;
                padding-left: 15px;
            }
        }
        .radar-legend-item {
            margin-bottom: 8px;
            line-height: 1.3;
        }
        /* Personalizar scrollbar de la leyenda */
        .ugel-radar-legend::-webkit-scrollbar {
            width: 4px;
        }
        .ugel-radar-legend::-webkit-scrollbar-track {
            background: #f1f5f9;
        }
        .ugel-radar-legend::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 2px;
        }

        /* ---------- Paginación Fluent Design ---------- */
        .fluent-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 6px;
            margin-top: 10px;
            margin-bottom: 10px;
        }

        .fluent-page-link {
            text-decoration: none;
            color: #323130;
            background: #f3f2f1;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.1s ease;
            cursor: pointer;
        }

        .fluent-page-link:hover {
            background: #edebe9;
            color: #323130;
        }

        .fluent-page-link.active {
            background: #107c41;
            color: #ffffff !important;
        }

        .fluent-page-dots {
            padding: 6px 4px;
            color: #605e5c;
            font-weight: 600;
        }
    </style>
    ';
    include 'components/head.php';
    ?>
</head>

<body>

    <!-- --------------------- TOPBAR --------------------- -->
    <nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top">
        <div class="container-fluid px-3 px-md-4">
            <!-- Hamburger (mobile) -->
            <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center gap-2" href="<?= $dashboard_url ?>">
                <i class="bi bi-shield-check ugel-brand-icon"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                    <small class="ugel-brand-subtitle">Sistema IE · <?= h_r($rol_label) ?></small>
                </div>
            </a>

            <div class="ms-auto">
                <div class="dropdown">
                    <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <div class="text-end d-none d-sm-block">
                            <small class="d-block text-uppercase"><?= h_r($rol_label) ?></small>
                            <strong><?= $nombre_corto ?></strong>
                        </div>
                        <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2">
                        <li class="text-center mb-4">
                            <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                            <h6 class="fw-bold mb-1"><?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?></h6>
                            <small><?= htmlspecialchars($user['correo'] ?? '', ENT_QUOTES, 'UTF-8') ?></small>
                        </li>
                        <li class="px-2">
                            <p class="info-label">DNI</p>
                            <p class="info-value"><?= htmlspecialchars($user['dni'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="info-label"><?= h_r($total_label) ?></p>
                            <p class="info-value fw-bold" style="color: var(--ugel-green-dark);"><?= $total_ie ?></p>
                        </li>
                        <li>
                            <hr class="dropdown-divider my-3">
                        </li>
                        <li>
                            <a class="btn btn-outline-primary ugel-menu-action w-100 fw-bold mb-2"
                                href="panel_usuario.php">
                                <i class="bi bi-person"></i> Perfil
                            </a>
                        </li>
                        <li>
                            <a class="btn btn-outline-danger ugel-menu-action w-100 fw-bold" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- App Shell con Sidebar -->
    <div class="ugel-app-shell">
        <?php
        $sidebar_role   = $es_jefe ? 'jefe_agp' : 'especialista';
        $sidebar_active = 'reportes';
        $sidebar_label  = $es_jefe ? 'UGEL Chincha · AGP' : 'Especialista';
        include 'components/sidebar.php';
        ?>

        <main class="ugel-main-content">

        <!-- Encabezado de página -->
        <div class="mb-4 d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
            <div>
                <a href="<?= $volver_url ?>" class="d-inline-flex align-items-center gap-1 mb-2 text-decoration-none"
                    style="font-size:0.88rem;color:var(--ugel-green-dark);font-weight:500;">
                    <i class="bi bi-arrow-left"></i> <?= h_r($volver_texto) ?>
                </a>
                <h3 class="ugel-section-title report-page-title fw-bold mb-1">
                    <i class="bi bi-bar-chart-line-fill me-2"></i>Dashboard de Reportes y Monitoreo
                </h3>
                <p class="text-muted mb-0" style="font-size:0.93rem;">
                    <?= $es_jefe ? 'Visualiza el avance y rendimiento de <strong>todas las I.E.</strong> de la provincia.' : 'Visualiza y analiza el avance de las I.E. asignadas a tu sector.' ?>
                </p>
            </div>
            <div class="d-flex gap-2 report-actions">
                <button onclick="submitReportExport('excel')"
                    class="btn btn-success report-action-btn d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                    <i class="bi bi-file-earmark-excel-fill"></i> Exportar Excel
                </button>
                <button onclick="submitReportExport('pdf')"
                    class="btn btn-danger report-action-btn d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                    <i class="bi bi-file-earmark-pdf-fill"></i> Exportar PDF
                </button>
            </div>
        </div>

        <!-- -- SECCIÓN DE FILTROS INTERACTIVOS -- -->
        <div class="filter-section mb-4">
            <div class="d-flex align-items-center gap-2 mb-3 border-bottom pb-2">
                <i class="bi bi-funnel-fill text-success"></i>
                <h6 class="fw-bold mb-0 text-dark">Filtros dinámicos de consulta</h6>
            </div>
            <div class="row g-3">
                <!-- Tipo de institucion -->
                <div class="col-12 col-sm-6 col-lg">
                    <label class="form-label small fw-bold text-secondary">Tipo de institucion</label>
                    <select id="filter-tipo" class="form-select fluent-select" onchange="applyFilters()">
                        <option value="">-- Todos los tipos --</option>
                        <option value="EBR">EBR</option>
                        <option value="CEBA">CEBA</option>
                        <option value="ETP">ETP</option>
                    </select>
                </div>

                <!-- Distrito -->
                <div class="col-12 col-sm-6 col-lg">
                    <label class="form-label small fw-bold text-secondary">Distrito</label>
                    <select id="filter-distrito" class="form-select fluent-select" onchange="applyFilters()">
                        <option value="">-- Todos los distritos --</option>
                        <?php foreach ($distritos as $dist): ?>
                            <option value="<?= htmlspecialchars($dist) ?>"><?= htmlspecialchars($dist) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nivel -->
                <div class="col-12 col-sm-6 col-lg d-none" id="filter-nivel-wrap">
                    <label class="form-label small fw-bold text-secondary">Nivel de educacion</label>
                    <select id="filter-nivel" class="form-select fluent-select" onchange="applyFilters()" disabled>
                        <option value="">-- Todos los niveles --</option>
                        <?php foreach ($niveles as $niv): ?>
                            <option value="<?= htmlspecialchars($niv) ?>"><?= htmlspecialchars($niv) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Resultado Logro -->
                <div class="col-12 col-sm-6 col-lg">
                    <label class="form-label small fw-bold text-secondary">Resultado de evaluacion</label>
                    <select id="filter-logro" class="form-select fluent-select" onchange="applyFilters()">
                        <option value="">-- Todos los resultados --</option>
                        <option value="INICIO">INICIO (0% - 25%)</option>
                        <option value="PROCESO">PROCESO (26% - 50%)</option>
                        <option value="LOGRADO">LOGRADO (51% - 75%)</option>
                        <option value="DESTACADO">DESTACADO (76% - 100%)</option>
                    </select>
                </div>

                <!-- Origen Ficha -->
                <div class="col-12 col-sm-6 col-lg">
                    <label class="form-label small fw-bold text-secondary">Fichas Evaluadas / Autoevaluadas</label>
                    <select id="filter-origen" class="form-select fluent-select" onchange="applyFilters()">
                        <option value="">-- Todas las fichas --</option>
                        <option value="AUTOEVALUADA">Solo autoevaluadas por Directores</option>
                        <option value="EVALUADA">Solo evaluadas por Especialistas</option>
                        <option value="PENDIENTE">Monitoreo pendiente</option>
                    </select>
                </div>

                <!-- Estado de la Ficha -->
                <div class="col-12 col-sm-6 col-lg">
                    <label class="form-label small fw-bold text-secondary">Estado de la Ficha</label>
                    <select id="filter-estado" class="form-select fluent-select" onchange="applyFilters()">
                        <option value="">-- Todos los estados --</option>
                        <option value="ENVIADO">📤 Enviado (esperando evaluación)</option>
                        <option value="EN_REVISION">🔍 En Revisión</option>
                        <option value="CONFORME">✅ Conforme</option>
                        <option value="OBSERVADO">⚠️ Observado</option>
                        <option value="RECHAZADO">❌ Rechazado</option>
                        <option value="REVISADO">📋 Revisado</option>
                        <option value="PENDIENTE_ENVIO">⏳ Pendiente de Envío</option>
                    </select>
                </div>
            </div>
            <div class="d-flex justify-content-end mt-3 gap-2">
                <button onclick="resetFilters()"
                    class="btn btn-outline-secondary d-inline-flex align-items-center gap-1">
                    <i class="bi bi-x-circle"></i> Limpiar filtros
                </button>
            </div>
        </div>

        <!-- -- TARJETAS DE MÉTRICAS (ESTADÍSTICAS) -- -->
        <div class="row g-3 mb-4">
            <!-- Total I.E. -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="metric-card">
                    <div class="metric-icon-wrapper metric-icon-green">
                        <i class="bi bi-building"></i>
                    </div>
                    <div class="metric-value" id="stat-total-ie">0</div>
                    <p class="metric-label">Instituciones en el Ámbito</p>
                </div>
            </div>

            <!-- Fichas Autoevaluadas -->
            <div class="col-12 col-sm-6 col-md-3" id="card-autoevaluadas-container">
                <div class="metric-card is-blue">
                    <div class="metric-icon-wrapper metric-icon-blue">
                        <i class="bi bi-file-earmark-person"></i>
                    </div>
                    <div class="metric-value" id="stat-autoevaluadas">0</div>
                    <p class="metric-label">Autoevaluadas por Directores</p>
                </div>
            </div>

            <!-- Fichas Evaluadas -->
            <div class="col-12 col-sm-6 col-md-3" id="card-evaluadas-container">
                <div class="metric-card is-orange">
                    <div class="metric-icon-wrapper metric-icon-orange">
                        <i class="bi bi-patch-check"></i>
                    </div>
                    <div class="metric-value" id="stat-evaluadas">0</div>
                    <p class="metric-label">Evaluadas por Especialistas</p>
                </div>
            </div>

            <!-- Cobertura -->
            <div class="col-12 col-sm-6 col-md-3">
                <div class="metric-card is-yellow">
                    <div class="metric-icon-wrapper metric-icon-yellow">
                        <i class="bi bi-percent"></i>
                    </div>
                    <div class="metric-value" id="stat-cobertura">0%</div>
                    <p class="metric-label">Porcentaje de Cobertura</p>
                </div>
            </div>
        </div>

        <!-- -- GRÁFICOS INTERACTIVOS -- -->
        <div class="row g-3 mb-3 report-chart-row">
            <!-- Gráfico 1: Barras Comparativas por Distrito -->
            <div class="col-12">
                <div class="chart-card">
                    <div class="chart-card-title">
                        <i class="bi bi-bar-chart-steps text-success"></i> Monitoreo de distritos
                    </div>
                    <div class="chart-plot is-wide">
                        <canvas id="chart-distritos"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfico 2: Barra Horizontal Niveles de Logro -->
            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-card-title">
                        <i class="bi bi-speedometer2 text-success"></i> Escalas de Logro Alcanzadas
                    </div>
                    <div class="chart-plot is-tall">
                        <canvas id="chart-logro"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfico 3: Torta Niveles Educativos -->
            <div class="col-12 col-lg-6">
                <div class="chart-card">
                    <div class="chart-card-title">
                        <i class="bi bi-pie-chart-fill text-success"></i> Distribución por Nivel Educativo
                    </div>
                    <div class="chart-plot is-tall">
                        <canvas id="chart-niveles"></canvas>
                    </div>
                </div>
            </div>

            <!-- Gráfico 4: Detalle escalas de Logro Alcanzadas por Item -->
            <div class="col-12">
                <div class="chart-card">
                    <div class="chart-card-title">
                        <i class="bi bi-bar-chart-line text-success"></i> Detalle escalas de Logro Alcanzadas por Item
                    </div>
                    <div class="chart-plot is-wide" style="height: 380px;">
                        <canvas id="chart-items-logro"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <!-- -- TABLA DE DETALLES Y AGRUPACIONES -- -->
        <div class="card-ref report-table-card mb-4">
            <div
                class="p-3 border-bottom d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-table text-success fs-5"></i>
                    <h6 class="fw-bold mb-0 text-dark">Detalle de Monitoreo Institucional</h6>
                </div>
                <span class="badge bg-light text-secondary border px-3 py-2" id="filtered-count-badge">Mostrando 0
                    registros</span>
            </div>
            <div class="table-responsive">
                <table class="table table-custom table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Cód. Local</th>
                            <th>Institución Educativa</th>
                            <th>Nivel</th>
                            <th>Distrito</th>
                            <th class="text-center">Autoevaluación (Dir.)</th>
                            <th class="text-center">Evaluación (Esp.)</th>
                            <th class="text-center">Nivel Logro</th>
                            <th>Indicadores Destacados / Críticos</th>
                        </tr>
                    </thead>
                    <tbody id="table-body">
                        <!-- Filas dinámicas -->
                    </tbody>
                </table>
            </div>
            <!-- PAGINACIÓN CARD FOOTER -->
            <div class="card-footer bg-white py-3 border-top-0 d-flex flex-column flex-sm-row justify-content-between align-items-center gap-3"
                id="pagination-container"
                style="border-radius: 0 0 8px 8px; border-top: 1px solid #e2e8f0; display: none;">
                <small class="text-muted text-center" id="pagination-info">Mostrando registros del 0 al 0 de un total de
                    0</small>
                <div class="fluent-pagination" id="pagination-controls">
                    <!-- Botones generados dinámicamente -->
                </div>
            </div>
        </div>

    </main>
    </div><!-- /.ugel-app-shell -->

    <!-- -- NOTIFICACIÓN TOAST DE EXPORTACIÓN -- -->
    <div id="export-toast" class="toast-notification">
        <i class="bi bi-check-circle-fill text-success fs-5"></i>
        <span id="toast-message">Exportación realizada exitosamente</span>
    </div>

    <!-- --------------------- CAPA DE CONTROL DE JAVASCRIPT (DATOS E INTERACCIÓN) --------------------- -->
    <script>
        // 1. Dataset de I.E. cargado desde PHP (datos reales de la base de datos)
        const rawInstituciones = <?php echo json_encode($js_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;

        // 2. Variables de Gráficos globales para poder redibujarlos (destroy/create)
        let chartDistritosInst = null;
        let chartNivelesInst = null;
        let chartLogroInst = null;
        let chartItemsLogroInst = null;

        // 2b. Estado de paginación
        const ROWS_PER_PAGE = 15;
        let currentPage = 1;
        let currentFilteredData = [];

        // Inicialización al cargar la página
        document.addEventListener("DOMContentLoaded", function () {
            applyFilters();
        });

        // 3. Función para aplicar filtros e interactividad
        function applyFilters() {
            const selTipo = document.getElementById("filter-tipo").value;
            updateNivelFilterVisibility(selTipo);
            const selDistrito = document.getElementById("filter-distrito").value.toUpperCase();
            const selNivel = selTipo === "EBR" ? document.getElementById("filter-nivel").value.toUpperCase() : "";
            const selLogro = document.getElementById("filter-logro").value;
            const selOrigen = document.getElementById("filter-origen").value;
            const selEstado = document.getElementById("filter-estado") ? document.getElementById("filter-estado").value : "";

            // Filtrado de datos
            const filteredData = rawInstituciones.filter(item => {
                if (selTipo && item.tipo_institucion !== selTipo) return false;
                if (selDistrito && item.distrito !== selDistrito) return false;
                if (selNivel && item.nivel !== selNivel) return false;
                if (selLogro && item.logro !== selLogro) return false;

                if (selOrigen === "AUTOEVALUADA" && !item.autoevaluada) return false;
                if (selOrigen === "EVALUADA" && !item.evaluada_especialista) return false;
                if (selOrigen === "PENDIENTE" && (item.autoevaluada || item.evaluada_especialista)) return false;

                // Filtro por estado de la ficha
                if (selEstado === "PENDIENTE_ENVIO" && item.estado_registro !== null) return false;
                if (selEstado && selEstado !== "PENDIENTE_ENVIO" && item.estado_registro !== selEstado) return false;

                return true;
            });

            // Guardar datos filtrados y resetear a página 1
            currentFilteredData = filteredData;
            currentPage = 1;

            // Actualizar tarjetas de métricas
            updateMetrics(filteredData);

            // Actualizar la Tabla con paginación
            updateTable(filteredData, currentPage);

            // Renderizar/Actualizar Gráficos
            renderCharts(filteredData);
        }

        // 4. Actualizar tarjetas
        function updateMetrics(data) {
            const total = data.length;
            const autoevaluadas = data.filter(i => i.autoevaluada).length;
            const evaluadas = data.filter(i => i.evaluada_especialista).length;

            let cobertura = 0;
            if (total > 0) {
                cobertura = Math.round((evaluadas / total) * 100);
            }

            document.getElementById("stat-total-ie").innerText = total;
            document.getElementById("stat-autoevaluadas").innerText = autoevaluadas;
            document.getElementById("stat-evaluadas").innerText = evaluadas;
            document.getElementById("stat-cobertura").innerText = cobertura + "%";
            document.getElementById("filtered-count-badge").innerText = `Mostrando ${total} de ${rawInstituciones.length} I.E.`;
        }

        // 5. Actualizar la tabla de resultados (con paginación)
        function updateTable(data, page) {
            const tbody = document.getElementById("table-body");
            tbody.innerHTML = "";

            if (data.length === 0) {
                tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        <i class="bi bi-info-circle me-1"></i> No hay registros que coincidan con los filtros seleccionados.
                    </td>
                </tr>`;
                // Ocultar paginación cuando no hay datos
                document.getElementById("pagination-container").style.display = "none";
                return;
            }

            const totalPages = Math.ceil(data.length / ROWS_PER_PAGE);
            const safePage = Math.max(1, Math.min(page, totalPages));
            const start = (safePage - 1) * ROWS_PER_PAGE;
            const end = Math.min(start + ROWS_PER_PAGE, data.length);
            const pageData = data.slice(start, end);

            pageData.forEach(item => {
                // Autoevaluación Badge
                const autoevaluacionCol = item.autoevaluada
                    ? `<div class="text-center"><span class="badge bg-light text-primary border" style="font-size:0.78rem;">Sí <small class="text-muted">(${item.autoevaluada_fecha})</small></span><br><small class="text-muted fw-bold">Puntaje: ${item.score_dir}%</small></div>`
                    : '<div class="text-center"><span class="status-pill">No realizada</span></div>';

                // Evaluación Especialista Badge
                const evaluacionCol = item.evaluada_especialista
                    ? `<div class="text-center"><span class="badge bg-light text-success border" style="font-size:0.78rem;">Sí <small class="text-muted">(${item.evaluada_fecha})</small></span><br><small class="text-muted fw-bold">Puntaje: ${item.score_esp}%</small></div>`
                    : '<div class="text-center"><span class="status-pill is-pending">Pendiente</span></div>';

                // Escala Logro Badge
                let logroBadge = '<span class="text-muted small">N/A</span>';
                if (item.logro) {
                    const badgeCls = item.logro === 'DESTACADO' ? 'bg-destacado' : (item.logro === 'LOGRADO' ? 'bg-logrado' : (item.logro === 'PROCESO' ? 'bg-proceso' : 'bg-inicio'));
                    logroBadge = `<span class="badge-logro ${badgeCls}">${item.logro}</span>`;
                }

                // Indicadores destacados / críticos
                let indicadoresCol;
                if (item.item_alto === null && item.item_bajo === null) {
                    indicadoresCol = '<span class="indic-empty">Sin datos de ítems</span>';
                } else {
                    indicadoresCol = `
                    <div class="indic-line"><i class="bi bi-arrow-up-circle-fill text-success"></i><span><strong class="text-success">Fortaleza:</strong> ${item.item_alto !== null ? item.item_alto : '—'}</span></div>
                    <div class="indic-line"><i class="bi bi-arrow-down-circle-fill text-danger"></i><span><strong class="text-danger">Oportunidad:</strong> ${item.item_bajo !== null ? item.item_bajo : '—'}</span></div>`;
                }

                tbody.innerHTML += `
                <tr>
                    <td><span class="cod-chip">${item.cod_local}</span></td>
                    <td><span class="ie-name">${item.nombre}</span></td>
                    <td><span class="nivel-badge">${item.nivel}</span></td>
                    <td><span class="distrito-text">${item.distrito}</span></td>
                    <td>${autoevaluacionCol}</td>
                    <td>${evaluacionCol}</td>
                    <td class="text-center">${logroBadge}</td>
                    <td class="indic-cell">${indicadoresCol}</td>
                </tr>
            `;
            });

            // Actualizar footer de paginación
            renderPagination(data.length, safePage, totalPages, start, end);
        }

        // 5b. Renderizar controles de paginación Fluent
        function renderPagination(total, page, totalPages, start, end) {
            const container = document.getElementById("pagination-container");
            const info = document.getElementById("pagination-info");
            const controls = document.getElementById("pagination-controls");

            // Mostrar / ocultar contenedor
            container.style.display = (totalPages > 1) ? "flex" : "none";

            if (totalPages <= 1) return;

            info.innerText = `Mostrando registros del ${start + 1} al ${end} de un total de ${total}`;

            controls.innerHTML = "";

            // Botón «Primera
            if (page > 1) {
                const btn = document.createElement("button");
                btn.className = "fluent-page-link";
                btn.innerHTML = "&laquo;";
                btn.title = "Primera página";
                btn.onclick = () => goToPage(1);
                controls.appendChild(btn);

                // Botón ‹Anterior
                const prev = document.createElement("button");
                prev.className = "fluent-page-link";
                prev.innerHTML = "&lsaquo;";
                prev.title = "Página anterior";
                prev.onclick = () => goToPage(page - 1);
                controls.appendChild(prev);
            }

            // Números de página (ventana de ±2 alrededor de la página actual)
            const rangeStart = Math.max(1, page - 2);
            const rangeEnd = Math.min(totalPages, page + 2);

            if (rangeStart > 1) {
                appendPageBtn(controls, 1, page);
                if (rangeStart > 2) {
                    const dots = document.createElement("span");
                    dots.className = "fluent-page-dots";
                    dots.textContent = "…";
                    controls.appendChild(dots);
                }
            }

            for (let p = rangeStart; p <= rangeEnd; p++) {
                appendPageBtn(controls, p, page);
            }

            if (rangeEnd < totalPages) {
                if (rangeEnd < totalPages - 1) {
                    const dots = document.createElement("span");
                    dots.className = "fluent-page-dots";
                    dots.textContent = "…";
                    controls.appendChild(dots);
                }
                appendPageBtn(controls, totalPages, page);
            }

            // Botón ›Siguiente
            if (page < totalPages) {
                const next = document.createElement("button");
                next.className = "fluent-page-link";
                next.innerHTML = "&rsaquo;";
                next.title = "Página siguiente";
                next.onclick = () => goToPage(page + 1);
                controls.appendChild(next);

                // Botón »Última
                const last = document.createElement("button");
                last.className = "fluent-page-link";
                last.innerHTML = "&raquo;";
                last.title = "Última página";
                last.onclick = () => goToPage(totalPages);
                controls.appendChild(last);
            }
        }

        function appendPageBtn(container, p, activePage) {
            const btn = document.createElement("button");
            btn.className = "fluent-page-link" + (p === activePage ? " active" : "");
            btn.textContent = p;
            btn.onclick = () => goToPage(p);
            container.appendChild(btn);
        }

        function goToPage(p) {
            currentPage = p;
            updateTable(currentFilteredData, currentPage);
            // Scroll suave hasta la tabla
            document.querySelector(".card-ref").scrollIntoView({ behavior: "smooth", block: "start" });
        }

        function updateNivelFilterVisibility(tipo) {
            const wrap = document.getElementById("filter-nivel-wrap");
            const select = document.getElementById("filter-nivel");
            const showNivel = tipo === "EBR";

            wrap.classList.toggle("d-none", !showNivel);
            select.disabled = !showNivel;
            if (!showNivel) {
                select.value = "";
            }
        }

        // 6. Resetear filtros
        function resetFilters() {
            document.getElementById("filter-tipo").value = "";
            document.getElementById("filter-distrito").value = "";
            document.getElementById("filter-nivel").value = "";
            document.getElementById("filter-logro").value = "";
            document.getElementById("filter-origen").value = "";
            const elEstado = document.getElementById("filter-estado");
            if (elEstado) elEstado.value = "";
            updateNivelFilterVisibility("");
            applyFilters();
        }

        // 7. Renderizar y actualizar los gráficos Chart.js
        function renderCharts(data) {
            const distritosList = [...new Set(rawInstituciones.map(i => i.distrito))];
            const evaluadasByDist = distritosList.map(d => data.filter(i => i.distrito === d && i.evaluada_especialista).length);
            const noEvaluadasByDist = distritosList.map(d => data.filter(i => i.distrito === d && !i.evaluada_especialista).length);
            const maxDistrito = Math.max(...evaluadasByDist, ...noEvaluadasByDist, 0);

            if (chartDistritosInst) chartDistritosInst.destroy();
            const ctxDist = document.getElementById("chart-distritos").getContext("2d");
            chartDistritosInst = new Chart(ctxDist, {
                type: "bar",
                data: {
                    labels: distritosList.map(d => d.charAt(0) + d.slice(1).toLowerCase()),
                    datasets: [
                        { label: "Evaluadas", data: evaluadasByDist, backgroundColor: "#107c41", borderRadius: 4 },
                        { label: "No evaluadas", data: noEvaluadasByDist, backgroundColor: "#dc3545", borderRadius: 4 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: "top", labels: { boxWidth: 12, font: { family: "Segoe UI" } } } },
                    scales: { y: { beginAtZero: true, suggestedMax: Math.max(maxDistrito + 1, 5), ticks: { stepSize: 1 } } }
                }
            });

            const nivelesList = ["INICIAL", "PRIMARIA", "SECUNDARIA"];
            const countByNivel = nivelesList.map(n => data.filter(i => i.nivel === n).length);

            if (chartNivelesInst) chartNivelesInst.destroy();
            const ctxNiv = document.getElementById("chart-niveles").getContext("2d");
            chartNivelesInst = new Chart(ctxNiv, {
                type: "doughnut",
                data: {
                    labels: ["Inicial", "Primaria", "Secundaria"],
                    datasets: [{ data: countByNivel, backgroundColor: ["#ffc107", "#1a5c38", "#1a56db"], borderWidth: 2, borderColor: "#ffffff" }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: "right", labels: { boxWidth: 12, font: { family: "Segoe UI" } } } },
                    cutout: "60%"
                }
            });

            const logrosList = ["DESTACADO", "LOGRADO", "PROCESO", "INICIO"];
            const countByLogro = logrosList.map(l => data.filter(i => i.logro === l).length);
            const maxLogro = Math.max(...countByLogro, 0);

            if (chartLogroInst) chartLogroInst.destroy();
            const ctxLogro = document.getElementById("chart-logro").getContext("2d");
            chartLogroInst = new Chart(ctxLogro, {
                type: "bar",
                data: {
                    labels: ["Destacado", "Logrado", "Proceso", "Inicio"],
                    datasets: [{ label: "Instituciones", data: countByLogro, backgroundColor: ["#107c41", "#1a56db", "#b8860b", "#c62828"], borderRadius: 4 }]
                },
                options: {
                    indexAxis: "y",
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, suggestedMax: Math.max(maxLogro + 1, 5), ticks: { stepSize: 1 } } }
                }
            });

            // Gráfico 4: Detalle escalas de Logro Alcanzadas por Item
            const itemNumbers = Array.from({ length: 19 }, (_, i) => i + 1);
            const counts = {
                1: Array(19).fill(0), // Inicio
                2: Array(19).fill(0), // Proceso
                3: Array(19).fill(0), // Logrado
                4: Array(19).fill(0)  // Destacado
            };

            data.forEach(item => {
                if (item.respuestas_items) {
                    itemNumbers.forEach(num => {
                        const val = item.respuestas_items[num];
                        if (val >= 1 && val <= 4) {
                            counts[val][num - 1]++;
                        }
                    });
                }
            });

            const maxItemCount = Math.max(
                ...itemNumbers.map(num => {
                    const idx = num - 1;
                    return counts[1][idx] + counts[2][idx] + counts[3][idx] + counts[4][idx];
                }),
                0
            );

            if (chartItemsLogroInst) chartItemsLogroInst.destroy();
            const ctxItemsLogro = document.getElementById("chart-items-logro").getContext("2d");
            chartItemsLogroInst = new Chart(ctxItemsLogro, {
                type: "bar",
                data: {
                    labels: itemNumbers.map(num => `Ítem ${num}`),
                    datasets: [
                        {
                            label: "Inicio",
                            data: counts[1],
                            backgroundColor: "#c62828",
                            borderRadius: 4
                        },
                        {
                            label: "Proceso",
                            data: counts[2],
                            backgroundColor: "#b8860b",
                            borderRadius: 4
                        },
                        {
                            label: "Logrado",
                            data: counts[3],
                            backgroundColor: "#1a56db",
                            borderRadius: 4
                        },
                        {
                            label: "Destacado",
                            data: counts[4],
                            backgroundColor: "#107c41",
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            grid: {
                                display: false
                            }
                        },
                        y: {
                            stacked: true,
                            beginAtZero: true,
                            suggestedMax: Math.max(maxItemCount + 1, 5),
                            ticks: {
                                stepSize: 1
                            }
                        }
                    },
                    plugins: {
                        legend: {
                            position: "top",
                            labels: {
                                boxWidth: 12,
                                font: {
                                    family: "Segoe UI"
                                }
                            }
                        }
                    }
                }
            });
        }
        // 8. Exportacion de la tabla y notificaciones
        function reportTableRows() {
            return currentFilteredData.map(item => {
                // Columna E - Autoevaluación Director (3 subcampos)
                const fecha_autoevaluacion = item.autoevaluada ? (item.autoevaluada_fecha || '-') : 'No realizada';
                const puntaje_autoevaluacion = item.autoevaluada ? `${item.score_dir ?? '-'}%` : '-';
                const logro_autoevaluacion = item.autoevaluada ? (item.logro || 'N/A') : '-';

                // Columna F - Evaluación Especialista (2 subcampos)
                const fecha_evaluacion = item.evaluada_especialista ? (item.evaluada_fecha || '-') : 'Pendiente';
                const puntaje_evaluacion = item.evaluada_especialista ? `${item.score_esp ?? '-'}%` : '-';

                // Columna G - Nivel Logro Evaluación Especialista
                const logro_evaluacion = item.evaluada_especialista ? (item.logro || 'N/A') : '-';

                // Columna H - Items destacados e items críticos (separados)
                const items_destacados = item.item_alto ? item.item_alto : 'Sin datos';
                const items_criticos   = item.item_bajo ? item.item_bajo : 'Sin datos';

                return {
                    cod_local: item.cod_local || '',
                    nombre: item.nombre || '',
                    nivel: item.nivel || '',
                    distrito: item.distrito || '',
                    fecha_autoevaluacion,
                    puntaje_autoevaluacion,
                    logro_autoevaluacion,
                    fecha_evaluacion,
                    puntaje_evaluacion,
                    logro_evaluacion,
                    items_destacados,
                    items_criticos
                };
            });
        }

        async function submitReportExport(format) {
            const rows = reportTableRows();
            const prettyFormat = format === 'pdf' ? 'PDF' : 'Excel';
            const button = document.querySelector(`button[onclick="submitReportExport('${format}')"]`);

            if (rows.length === 0) {
                showExportToast(`No hay registros en la tabla para exportar en <strong>${prettyFormat}</strong>.`, false);
                return;
            }

            const formData = new FormData();
            formData.append('format', format);
            formData.append('rows', JSON.stringify(rows));

            try {
                if (button) button.disabled = true;

                const response = await fetch('exportar_vista_reportes.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    const errorText = await response.text();
                    throw new Error(errorText || `No se pudo generar el archivo ${prettyFormat}.`);
                }

                const buffer = await response.arrayBuffer();
                if (!buffer.byteLength) {
                    throw new Error(`El archivo ${prettyFormat} se generó vacío.`);
                }

                if (format === 'excel') {
                    const zipSignature = new Uint8Array(buffer.slice(0, 2));
                    const textSignature = new TextDecoder('utf-8').decode(buffer.slice(0, 120)).trimStart();
                    const isXlsx = zipSignature[0] === 0x50 && zipSignature[1] === 0x4b;
                    const isExcelXml = textSignature.indexOf('xml') > -1 || textSignature.indexOf('Workbook') > -1;

                    if (!isXlsx && !isExcelXml) {
                        const errorText = new TextDecoder('utf-8').decode(buffer.slice(0, 500));
                        throw new Error(errorText || 'Respuesta no es archivo Excel válido');
                    }
                }
                if (format === 'pdf') {
                    const signature = new TextDecoder('ascii').decode(buffer.slice(0, 4));
                    if (signature !== '%PDF') {
                        const errorText = new TextDecoder('utf-8').decode(buffer.slice(0, 500));
                        throw new Error(errorText || 'Respuesta no es archivo PDF válido');
                    }
                }

                const mimeType = format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                const blob = new Blob([buffer], {
                    type: mimeType
                });
                const disposition = response.headers.get('Content-Disposition') || '';
                const filenameMatch = disposition.match(/filename="?([^";]+)"?/i);
                const fallbackName = `reporte_monitoreo_institucional.${format === 'pdf' ? 'pdf' : 'xlsx'}`;
                const filename = filenameMatch ? filenameMatch[1] : fallbackName;
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');

                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                URL.revokeObjectURL(url);

                showExportToast(`Descargando la tabla filtrada en formato <strong>${prettyFormat}</strong>.`, true);
            } catch (error) {
                showExportToast(`No se pudo exportar en <strong>${prettyFormat}</strong>: ${error.message}`, false);
            } finally {
                if (button) button.disabled = false;
            }
        }
        function showExportToast(message, success = true) {
            const toast = document.getElementById("export-toast");
            const msg = document.getElementById("toast-message");
            const icon = success
                ? '<i class="bi bi-file-earmark-arrow-down-fill text-success fs-5"></i>'
                : '<i class="bi bi-exclamation-circle-fill text-warning fs-5"></i>';

            msg.innerHTML = `${icon} ${message}`;
            toast.classList.add("show");

            setTimeout(() => {
                toast.classList.remove("show");
            }, 4000);
        }</script>

    <!-- Carga Bootstrap Bundle (con dropdowns, etc.) -->
    <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>

</html>