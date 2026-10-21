<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(403);
    exit('Sesión no válida.');
}

$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['especialista', 'jefe_agp', 'admin'], true)) {
    http_response_code(403);
    exit('No autorizado.');
}

$id_usuario = (int) $_SESSION['id_usuario'];
$filtro_estado = $_GET['estado'] ?? 'TODOS';
$filtro_anio = (int) ($_GET['anio'] ?? date('Y'));

// Query de proyectos
$sql = "SELECT p.*, i.nombre_ie, i.codigo_local, i.distrito,
               u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni, u.telefono AS dir_telefono,
               esp.nombres AS esp_nombres, esp.ape_pat AS esp_ape_pat
        FROM proyectos_innovacion p
        INNER JOIN instituciones i ON i.id = p.id_institucion
        INNER JOIN usuarios u ON u.id = p.id_usuario_director
        LEFT JOIN usuarios esp ON esp.id = p.id_especialista_asignado
        WHERE p.activo = 1";

$params = [];
$types = "";

if ($rol === 'especialista') {
    $sql .= " AND i.id IN (SELECT id_institucion FROM asignaciones_especialistas WHERE id_usuario = ? AND estado = 'activo')";
    $params[] = $id_usuario;
    $types .= "i";
}

if ($filtro_estado !== 'TODOS' && $filtro_estado !== '') {
    $sql .= " AND p.estado = ?";
    $params[] = $filtro_estado;
    $types .= "s";
}

if ($filtro_anio > 0) {
    $sql .= " AND p.anio = ?";
    $params[] = $filtro_anio;
    $types .= "i";
}

$sql .= " ORDER BY p.id DESC";

if (!empty($params)) {
    $st = $conn->prepare($sql);
    $st->bind_param($types, ...$params);
    $st->execute();
    $rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
    $st->close();
} else {
    $rs = $conn->query($sql);
    $rows = $rs ? $rs->fetch_all(MYSQLI_ASSOC) : [];
}

$lineas_map = [
    'INNOVACION_PEDAGOGICA' => 'Innovación Pedagógica en Aula',
    'TECNOLOGIA_EDUCATIVA_TIC' => 'Tecnología Educativa / TIC y STEM',
    'CONVIVENCIA_CLIMA_ESCOLAR' => 'Clima Escolar, Tutoría y Convivencia',
    'CIENCIA_AMBIENTE_SOSTENIBILIDAD' => 'Ciencia, Ambiente y Sostenibilidad',
    'ARTE_CULTURA_DEPORTE' => 'Arte, Cultura y Deporte',
    'INCLUSION_ATENCION_DIVERSIDAD' => 'Inclusión y Atención a la Diversidad'
];

$filename = 'Reporte_Proyectos_Innovacion_UGEL_Chincha_' . date('Ymd_His') . '.xls';

header('Content-Type: application/vnd.ms-excel; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

echo "\xEF\xBB\xBF"; // UTF-8 BOM para que Excel respete tildes y caracteres especiales
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        th { background-color: #137e4b; color: #ffffff; font-weight: bold; border: 1px solid #000; text-align: center; }
        td { border: 1px solid #cbd5e1; vertical-align: top; }
        .title { font-size: 14pt; font-weight: bold; color: #137e4b; text-align: center; }
        .subtitle { font-size: 10pt; color: #475569; text-align: center; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td colspan="12" class="title">GOBIERNO REGIONAL DE ICA - DIRECCIÓN REGIONAL DE EDUCACIÓN DE ICA</td>
        </tr>
        <tr>
            <td colspan="12" class="title">UNIDAD DE GESTIÓN EDUCATIVA LOCAL DE CHINCHA</td>
        </tr>
        <tr>
            <td colspan="12" class="subtitle">ÁREA DE GESTIÓN PEDAGÓGICA (AGP) - REPORTE CONSOLIDADO DE PROYECTOS DE INNOVACIÓN (FONDEP)</td>
        </tr>
        <tr>
            <td colspan="12" style="text-align: right; font-size: 9pt; color: #64748b;">Fecha de emisión: <?= date('d/m/Y H:i:s') ?></td>
        </tr>
        <tr><td colspan="12"></td></tr>
        <thead>
            <tr>
                <th>N°</th>
                <th>CÓDIGO PROYECTO</th>
                <th>INSTITUCIÓN EDUCATIVA</th>
                <th>CÓD. LOCAL</th>
                <th>DISTRITO</th>
                <th>DIRECTOR / RESPONSABLE</th>
                <th>ESPECIALISTA ASIGNADO</th>
                <th>TÍTULO DE LA PROPUESTA</th>
                <th>LÍNEA TEMÁTICA</th>
                <th>PRESUPUESTO (S/)</th>
                <th>AVANCE (%)</th>
                <th>ESTADO ACTUAL</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="12" style="text-align:center;">No se encontraron registros de proyectos para exportar.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($rows as $i => $r): 
                $linea_nombre = $lineas_map[$r['linea_tematica']] ?? $r['linea_tematica'];
                $dir_nom = trim($r['dir_nombres'] . ' ' . $r['dir_ape_pat'] . ' ' . $r['dir_ape_mat']);
                $esp_nom = trim(($r['esp_nombres'] ?? '') . ' ' . ($r['esp_ape_pat'] ?? '')) ?: 'Sin asignar';
            ?>
            <tr>
                <td style="text-align: center;"><?= $i + 1 ?></td>
                <td style="font-weight: bold; text-align: center;"><?= htmlspecialchars($r['codigo_proyecto']) ?></td>
                <td><?= htmlspecialchars($r['nombre_ie']) ?></td>
                <td style="text-align: center;"><?= htmlspecialchars($r['codigo_local']) ?></td>
                <td><?= htmlspecialchars($r['distrito']) ?></td>
                <td><?= htmlspecialchars($dir_nom) ?></td>
                <td><?= htmlspecialchars($esp_nom) ?></td>
                <td><?= htmlspecialchars($r['titulo']) ?></td>
                <td><?= htmlspecialchars($linea_nombre) ?></td>
                <td style="text-align: right;"><?= number_format((float)$r['presupuesto_referencial'], 2) ?></td>
                <td style="text-align: center; font-weight: bold;"><?= number_format((float)$r['porcentaje_avance'], 1) ?>%</td>
                <td style="text-align: center; font-weight: bold;"><?= htmlspecialchars($r['estado']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</body>
</html>
