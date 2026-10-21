<?php
/**
 * Generador del Informe de Monitoreo en Word (.docx)
 * --------------------------------------------------
 * Rellena la plantilla template/info_templeate.docx con los datos reales de
 * una ficha (registro) y la descarga.
 *
 * Datos: se prefiere la evaluación del ESPECIALISTA (fm_respuestas_especialista
 * vía fm_evaluaciones FINALIZADA); si la IE aún no fue evaluada, cae a la
 * autoevaluación del DIRECTOR (fm_respuestas_director).
 *
 * Uso:  generar_informe_docx.php?id_registro=123
 * Ver diseño: template/DISENO_19_ITEMS.md
 */

session_start();
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/fm_pdf_helpers.php'; // fm_pdf_fecha(), fm_pdf_valoracion()

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(403);
    exit('No autorizado.');
}
$rol = $_SESSION['rol'] ?? '';
if ($rol !== 'especialista' && $rol !== 'jefe_agp') {
    http_response_code(403);
    exit('Sin permisos.');
}

$id_registro = (int) ($_GET['id_registro'] ?? $_POST['id_registro'] ?? 0);
if ($id_registro <= 0) {
    http_response_code(400);
    exit('Falta id_registro.');
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('La extensión ZipArchive de PHP no está habilitada.');
}

/* ---------- Helpers ---------- */
function xe($s): string
{
    return htmlspecialchars((string) ($s ?? ''), ENT_QUOTES | ENT_XML1, 'UTF-8');
}
$ESCALA_LABEL = [1 => 'INICIO', 2 => 'PROCESO', 3 => 'LOGRADO', 4 => 'DESTACADO'];

/* ---------- 1) Registro + IE + director + especialista ---------- */
$sql = "SELECT r.id, r.id_institucion, r.id_version_ficha, r.fecha_envio, r.id_especialista,
               i.nombre_ie, i.distrito, i.codigo_local,
               u.nombres dir_n, u.ape_pat dir_ap, u.ape_mat dir_am,
               e.nombres esp_n, e.ape_pat esp_ap, e.ape_mat esp_am
        FROM fm_registros r
        INNER JOIN instituciones i ON i.id = r.id_institucion
        LEFT JOIN usuarios u ON u.id = r.id_usuario
        LEFT JOIN usuarios e ON e.id = r.id_especialista
        WHERE r.id = ? LIMIT 1";
$st = $conn->prepare($sql);
$st->bind_param('i', $id_registro);
$st->execute();
$reg = $st->get_result()->fetch_assoc();
$st->close();
if (!$reg) {
    http_response_code(404);
    exit('No se encontró el registro de ficha.');
}

$id_version = (int) $reg['id_version_ficha'];

/* ---------- 2) Evaluación finalizada del especialista (si existe) ---------- */
$id_evaluacion = 0;
$fecha_eval = null;
$st = $conn->prepare("SELECT id, fecha_finalizacion FROM fm_evaluaciones
                      WHERE id_registro = ? AND estado_evaluacion = 'FINALIZADA'
                      ORDER BY fecha_finalizacion DESC, id DESC LIMIT 1");
$st->bind_param('i', $id_registro);
$st->execute();
if ($ev = $st->get_result()->fetch_assoc()) {
    $id_evaluacion = (int) $ev['id'];
    $fecha_eval = $ev['fecha_finalizacion'];
}
$st->close();

/* ---------- 3) Ítems ESCALA_1_4 (orden de la ficha) ---------- */
$items = [];
$st = $conn->prepare("SELECT id, numero_item, pregunta FROM fm_items
                      WHERE id_version_ficha = ? AND estado = 'ACTIVO' AND tipo_respuesta = 'ESCALA_1_4'
                      ORDER BY orden, id");
$st->bind_param('i', $id_version);
$st->execute();
$rs = $st->get_result();
while ($row = $rs->fetch_assoc()) {
    $items[] = $row;
}
$st->close();

/* ---------- 4) Respuestas: especialista preferido, director fallback ---------- */
$resp = [];
$fuente = 'director';
if ($id_evaluacion > 0) {
    $st = $conn->prepare("SELECT id_item, respuesta_escala, no_aplica, observacion
                          FROM fm_respuestas_especialista WHERE id_evaluacion = ?");
    $st->bind_param('i', $id_evaluacion);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $resp[(int) $row['id_item']] = $row;
    }
    $st->close();
    if (!empty($resp)) {
        $fuente = 'especialista';
    }
}
if (empty($resp)) {
    $st = $conn->prepare("SELECT id_item, respuesta_escala, no_aplica, observacion
                          FROM fm_respuestas_director WHERE id_registro = ?");
    $st->bind_param('i', $id_registro);
    $st->execute();
    $rs = $st->get_result();
    while ($row = $rs->fetch_assoc()) {
        $resp[(int) $row['id_item']] = $row;
    }
    $st->close();
    $fuente = 'director';
}
if ($fuente === 'director' || $fecha_eval === null) {
    // si no hubo evaluación, la fecha del informe = fecha de envío del registro
    if ($fecha_eval === null) {
        $fecha_eval = $reg['fecha_envio'];
    }
}

/* ---------- 5) Construir escala/observación por ítem + métricas ---------- */
$escala_item = [];
$obs_item = [];
$puntaje = 0;
$n_valid = 0;
$n_critico = 0;
$fortalezas = [];
$debilidades = []; // [escala, pregunta]

$idx = 0;
foreach ($items as $it) {
    $idx++;
    $r = $resp[(int) $it['id']] ?? null;
    $noap = $r ? (int) ($r['no_aplica'] ?? 0) : 0;
    $esc = $r && !$noap ? (int) ($r['respuesta_escala'] ?? 0) : 0;
    $obs = $r ? trim((string) ($r['observacion'] ?? '')) : '';

    if ($noap) {
        $escala_item[$idx] = 'NO APLICA';
        $obs_item[$idx] = $obs !== '' ? $obs : 'No aplica.';
        continue;
    }
    if ($esc >= 1 && $esc <= 4) {
        $escala_item[$idx] = $ESCALA_LABEL[$esc];
        $obs_item[$idx] = $obs;
        $puntaje += $esc;
        $n_valid++;
        if ($esc >= 3) {
            $fortalezas[] = [$esc, $it['pregunta']];
        } else {
            $debilidades[] = [$esc, $it['pregunta']];
            $n_critico++;
        }
    } else {
        $escala_item[$idx] = '—';
        $obs_item[$idx] = $obs;
    }
}

$maxp = 4 * $n_valid;
$porc = $maxp > 0 ? (int) round($puntaje / $maxp * 100) : 0;
$escala_global = fm_pdf_valoracion($porc);
$critico_pct = $n_valid > 0 ? (int) round($n_critico / $n_valid * 100) : 0;

// Orden por escala: fortalezas 4->3, debilidades 1->2
usort($fortalezas, fn($a, $b) => $b[0] <=> $a[0]);
usort($debilidades, fn($a, $b) => $a[0] <=> $b[0]);
$fort_txt = array_map(fn($x) => $x[1], $fortalezas);
$debi_txt = array_map(fn($x) => $x[1], $debilidades);
if (empty($fort_txt)) {
    $fort_txt = ['[VACIO]'];
}
if (empty($debi_txt)) {
    $debi_txt = ['[VACIO]'];
}

/* ---------- 6) Datos de encabezado ---------- */
$ie_nombre = $reg['nombre_ie'] ?: '';
$distrito = $reg['distrito'] ?: '';
$fecha_txt = fm_pdf_fecha($fecha_eval);
$anio = $fecha_eval ? date('Y', strtotime($fecha_eval)) : date('Y');
$esp_nombre = trim(($reg['esp_n'] ?? '') . ' ' . ($reg['esp_ap'] ?? '') . ' ' . ($reg['esp_am'] ?? ''));
$dir_nombre = trim(($reg['dir_n'] ?? '') . ' ' . ($reg['dir_ap'] ?? '') . ' ' . ($reg['dir_am'] ?? ''));

/* ---------- 7) Cargar plantilla ---------- */
$tplPath = __DIR__ . '/template/info_templeate.docx';
if (!is_file($tplPath)) {
    http_response_code(500);
    exit('No se encontró la plantilla info_templeate.docx.');
}

$tmp = tempnam(sys_get_temp_dir(), 'inf') . '.docx';
if (!copy($tplPath, $tmp)) {
    http_response_code(500);
    exit('No se pudo preparar el archivo temporal.');
}

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) {
    @unlink($tmp);
    http_response_code(500);
    exit('No se pudo abrir la plantilla.');
}
$xml = $zip->getFromName('word/document.xml');
if ($xml === false) {
    $zip->close();
    @unlink($tmp);
    http_response_code(500);
    exit('Plantilla inválida.');
}

/* ---------- 8) Clonar bullets {{FORTALEZA}} / {{DEBILIDAD}} ---------- */
function clonar_bullet(string $xml, string $marker, array $valores): string
{
    $pat = '/<w:p[ >](?:(?!<\/w:p>).)*?' . preg_quote($marker, '/') . '(?:(?!<\/w:p>).)*?<\/w:p>/s';
    if (!preg_match($pat, $xml, $m)) {
        return $xml;
    }
    $tpl = $m[0];
    $out = '';
    foreach ($valores as $v) {
        $out .= str_replace($marker, xe($v), $tpl);
    }
    return str_replace($tpl, $out, $xml);
}
$xml = clonar_bullet($xml, '{{FORTALEZA}}', $fort_txt);
$xml = clonar_bullet($xml, '{{DEBILIDAD}}', $debi_txt);

/* ---------- 9) Reemplazo de marcadores simples ---------- */
// Insertar el símbolo % en la segunda ocurrencia de {{PORCENTAJE}} y {{PORCENTAJE_CRITICO}} en el XML
$pos_porc1 = strpos($xml, '{{PORCENTAJE}}');
if ($pos_porc1 !== false) {
    $pos_porc2 = strpos($xml, '{{PORCENTAJE}}', $pos_porc1 + 14);
    if ($pos_porc2 !== false) {
        $xml = substr_replace($xml, '{{PORCENTAJE}}%', $pos_porc2, 14);
    }
}
$pos_crit1 = strpos($xml, '{{PORCENTAJE_CRITICO}}');
if ($pos_crit1 !== false) {
    $pos_crit2 = strpos($xml, '{{PORCENTAJE_CRITICO}}', $pos_crit1 + 22);
    if ($pos_crit2 !== false) {
        $xml = substr_replace($xml, '{{PORCENTAJE_CRITICO}}%', $pos_crit2, 22);
    }
}

$map = [
    '{{IE_NOMBRE}}' => xe(mb_strtoupper($ie_nombre, 'UTF-8')),
    '{{DISTRITO}}' => xe(mb_strtoupper($distrito, 'UTF-8')),
    '{{FECHA_EVALUACION}}' => xe($fecha_txt),
    '{{ANIO}}' => xe($anio),
    '{{ESPECIALISTA_NOMBRE}}' => xe(mb_strtoupper($esp_nombre, 'UTF-8')),
    '{{DIRECTOR_NOMBRE}}' => xe(mb_strtoupper($dir_nombre, 'UTF-8')),
    '{{PUNTAJE_OBTENIDO}}' => xe($puntaje),
    '{{PORCENTAJE}}' => xe($porc),
    '{{ESCALA}}' => xe($escala_global),
    '{{PORCENTAJE_CRITICO}}' => xe($critico_pct),
];
for ($i = 1; $i <= 19; $i++) {
    $map['{{ESCALA_ITEM' . $i . '}}'] = xe($escala_item[$i] ?? '—');
    $map['{{OBS_ITEM' . $i . '}}'] = xe($obs_item[$i] ?? '');
}
$xml = strtr($xml, $map);

/* ---------- 10) Guardar y descargar ---------- */
$zip->addFromString('word/document.xml', $xml);
$zip->close();

$slug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $ie_nombre) ?: 'IE');
$slug = trim($slug, '_') ?: 'IE';
$filename = 'Informe_Monitoreo_' . $slug . '_' . date('Ymd') . '.docx';

if (ob_get_length()) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
