<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) { header('Location: login.php'); exit(); }
if ($_SESSION['rol'] !== 'especialista') { header('Location: dashboard.php'); exit(); }

$id_usuario = (int)$_SESSION['id_usuario'];

function h_r($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

// Datos del usuario
$stmt = $conn->prepare("SELECT nombres, ape_pat, ape_mat, dni, correo FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user  = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nombre       = trim(($user['nombres'] ?? '') . ' ' . ($user['ape_pat'] ?? '') . ' ' . ($user['ape_mat'] ?? ''));
$nombre_corto = h_r($user['nombres'] ?? 'Usuario');

// ─── QUERY PRINCIPAL: Todas las I.E. asignadas + autoevaluacion + evaluacion especialista ───
$sql_datos = "
    SELECT
        i.id                                        AS id_ie,
        IFNULL(i.codigo_local, '')                  AS cod_local,
        IFNULL(i.codigo_institucion,'')             AS cod_institucion,
        i.nombre_ie                                 AS nombre,
        UPPER(TRIM(IFNULL(i.nivel_modalidad,'')))   AS nivel,
        UPPER(TRIM(IFNULL(i.distrito,'')))           AS distrito,

        r.id                                        AS id_registro,
        r.fecha_envio                               AS autoeval_fecha,
        r.porcentaje                                AS score_dir,
        r.escala_valorativa                         AS logro_dir,
        CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END AS autoevaluada,

        e.id                                        AS id_evaluacion,
        e.fecha_finalizacion                        AS eval_fecha,
        e.porcentaje_eval                           AS score_esp,
        e.escala_valorativa_eval                    AS logro_esp,
        CASE WHEN e.id IS NOT NULL THEN 1 ELSE 0 END AS evaluada_especialista

    FROM asignaciones_especialistas ae
    INNER JOIN instituciones i ON i.id = ae.id_institucion
    LEFT JOIN fm_registros r ON r.id_institucion = i.id
        AND r.activo = 1
        AND r.estado IN ('ENVIADO','CONFORME','REVISADO','OBSERVADO','EN_REVISION','RECHAZADO')
        AND r.id = (
            SELECT r2.id FROM fm_registros r2
            WHERE r2.id_institucion = i.id AND r2.activo = 1
              AND r2.estado IN ('ENVIADO','CONFORME','REVISADO','OBSERVADO','EN_REVISION','RECHAZADO')
            ORDER BY r2.fecha_envio DESC LIMIT 1
        )
    LEFT JOIN fm_evaluaciones e ON e.id_registro = r.id
        AND e.id_especialista = ae.id_usuario
        AND e.estado_evaluacion = 'FINALIZADA'

    WHERE ae.id_usuario = ?
      AND ae.estado = 'activo'
    ORDER BY i.distrito ASC, i.nombre_ie ASC
";

$st = $conn->prepare($sql_datos);
$st->bind_param('i', $id_usuario);
$st->execute();
$res_datos = $st->get_result();
$instituciones_data = [];
while ($row = $res_datos->fetch_assoc()) {
    $instituciones_data[] = $row;
}
$st->close();

$total_ie = count($instituciones_data);

// Función para normalizar escala valorativa al valor estándar del dashboard
function normalizar_logro(?string $raw): ?string {
    if ($raw === null) return null;
    $r = strtoupper(trim($raw));
    if (str_contains($r, 'DESTACADO')) return 'DESTACADO';
    if (str_contains($r, 'PREVISTO'))  return 'PREVISTO';
    if (str_contains($r, 'PROCESO'))   return 'EN PROCESO';
    if (str_contains($r, 'INICIO'))    return 'EN INICIO';
    return null;
}

// Construir el array JSON para JS
$js_data = [];
foreach ($instituciones_data as $r) {
    $logro_norm = normalizar_logro($r['logro_esp'] ?? null) ?? normalizar_logro($r['logro_dir'] ?? null);

    $js_data[] = [
        'cod_local'             => $r['cod_local'] ?: ($r['cod_institucion'] ?: '—'),
        'nombre'                => $r['nombre'],
        'nivel'                 => $r['nivel'] ?: 'OTRO',
        'distrito'              => $r['distrito'] ?: 'SIN DISTRITO',
        'autoevaluada'          => (bool)$r['autoevaluada'],
        'autoevaluada_fecha'    => $r['autoeval_fecha'] ? date('Y-m-d', strtotime($r['autoeval_fecha'])) : null,
        'score_dir'             => $r['score_dir'] !== null ? round((float)$r['score_dir'], 1) : null,
        'evaluada_especialista' => (bool)$r['evaluada_especialista'],
        'evaluada_fecha'        => $r['eval_fecha'] ? date('Y-m-d', strtotime($r['eval_fecha'])) : null,
        'score_esp'             => $r['score_esp'] !== null ? round((float)$r['score_esp'], 1) : null,
        'logro'                 => $logro_norm,
        'item_alto'             => '—',
        'item_bajo'             => '—',
    ];
}

// Listas de filtros desde datos reales
$distritos = array_values(array_unique(array_filter(array_column($js_data, 'distrito'), fn($d) => $d !== 'SIN DISTRITO')));
sort($distritos);
$niveles = array_values(array_unique(array_filter(array_column($js_data, 'nivel'), fn($n) => $n !== 'OTRO')));
sort($niveles);

// Fallbacks si no hay datos
if (empty($distritos)) {
    $distritos = ['ALTO LARAN','CHAVIN','CHINCHA ALTA','EL CARMEN','GROCIO PRADO','PUEBLO NUEVO','SAN JUAN DE YANAC','SAN PEDRO DE HUACARPANA','SUNAMPE','TAMBO DE MORA'];
}
if (empty($niveles)) {
    $niveles = ['INICIAL','PRIMARIA','SECUNDARIA'];
}
