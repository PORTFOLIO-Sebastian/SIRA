<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    die("Acceso restringido. Por favor inicie sesión.");
}

$id_usuario = (int) $_SESSION['id_usuario'];
$rol = $_SESSION['rol'] ?? '';
$id_proyecto = (int) ($_GET['id'] ?? 0);
$descargar = isset($_GET['download']) && $_GET['download'] === '1';

if ($id_proyecto <= 0) {
    die("ID de proyecto inválido.");
}

function h($str): string {
    return htmlspecialchars((string) ($str ?? ''), ENT_QUOTES, 'UTF-8');
}

// Consultar datos completos del proyecto
$sql = "SELECT p.*, 
               i.nombre_ie, i.codigo_local, i.distrito,
               u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni, u.correo AS dir_correo, u.telefono AS dir_telefono,
               esp.nombres AS esp_nombres, esp.ape_pat AS esp_ape_pat, esp.ape_mat AS esp_ape_mat, esp.dni AS esp_dni, esp.correo AS esp_correo
        FROM proyectos_innovacion p
        INNER JOIN instituciones i ON i.id = p.id_institucion
        INNER JOIN usuarios u ON u.id = p.id_usuario_director
        LEFT JOIN usuarios esp ON esp.id = p.id_especialista_asignado
        WHERE p.id = ? AND p.activo = 1 LIMIT 1";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_proyecto);
$stmt->execute();
$proy = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$proy) {
    die("No se encontró el proyecto solicitado o no está activo.");
}

// Consultar cronograma de actividades
$rs_act = $conn->query("SELECT * FROM proyectos_actividades WHERE id_proyecto = $id_proyecto ORDER BY numero_actividad ASC");
$actividades = $rs_act ? $rs_act->fetch_all(MYSQLI_ASSOC) : [];

// Consultar evidencias registradas
$rs_ev = $conn->query("SELECT * FROM proyectos_evidencias WHERE id_proyecto = $id_proyecto ORDER BY id ASC");
$evidencias = $rs_ev ? $rs_ev->fetch_all(MYSQLI_ASSOC) : [];

// Consultar historial
$rs_hist = $conn->query("SELECT h.*, u.nombres, u.ape_pat, u.rol FROM proyectos_historial h INNER JOIN usuarios u ON u.id = h.id_usuario WHERE h.id_proyecto = $id_proyecto ORDER BY h.id ASC");
$historial = $rs_hist ? $rs_hist->fetch_all(MYSQLI_ASSOC) : [];

$lineas_map = [
    'INNOVACION_PEDAGOGICA' => 'Innovación Pedagógica en Aula',
    'TECNOLOGIA_EDUCATIVA_TIC' => 'Tecnología Educativa / TIC y STEM',
    'CONVIVENCIA_CLIMA_ESCOLAR' => 'Clima Escolar, Tutoría y Convivencia',
    'CIENCIA_AMBIENTE_SOSTENIBILIDAD' => 'Ciencia, Ambiente y Sostenibilidad',
    'ARTE_CULTURA_DEPORTE' => 'Arte, Cultura y Deporte',
    'INCLUSION_ATENCION_DIVERSIDAD' => 'Inclusión y Atención a la Diversidad'
];

$nombre_linea = $lineas_map[$proy['linea_tematica']] ?? $proy['linea_tematica'];
$dir_completo = trim($proy['dir_nombres'] . ' ' . $proy['dir_ape_pat'] . ' ' . $proy['dir_ape_mat']);
$esp_completo = trim(($proy['esp_nombres'] ?? '') . ' ' . ($proy['esp_ape_pat'] ?? '') . ' ' . ($proy['esp_ape_mat'] ?? ''));
if (!$esp_completo) $esp_completo = 'Especialista AGP UGEL Chincha (En asignación)';

// Construcción del HTML Oficial del Documento
$filas_actividades = '';
if (empty($actividades)) {
    $filas_actividades = '<tr><td colspan="6" style="text-align:center; padding: 10px; color: #64748b;">No se han registrado actividades detalladas aún.</td></tr>';
} else {
    foreach ($actividades as $act) {
        $estado_color = $act['estado'] === 'COMPLETADA' ? '#166534' : ($act['estado'] === 'EN_PROCESO' ? '#854d0e' : '#475569');
        $estado_bg = $act['estado'] === 'COMPLETADA' ? '#dcfce7' : ($act['estado'] === 'EN_PROCESO' ? '#fef9c3' : '#f1f5f9');
        $filas_actividades .= '<tr>
            <td style="text-align:center; font-weight: bold; width: 30px;">' . (int)$act['numero_actividad'] . '</td>
            <td><strong>' . h($act['descripcion']) . '</strong>' . ($act['evidencia_resumen'] ? '<br><small style="color:#64748b;">Sustento: ' . h($act['evidencia_resumen']) . '</small>' : '') . '</td>
            <td style="font-size: 8.5px;">' . h($act['responsable']) . '</td>
            <td style="text-align:center; font-size: 8px; white-space:nowrap;">' . h($act['fecha_inicio_programada']) . '<br>al<br>' . h($act['fecha_fin_programada']) . '</td>
            <td style="text-align:center; font-weight: bold;">' . (int)$act['porcentaje_avance'] . '%</td>
            <td style="text-align:center;"><span style="display:inline-block; padding: 3px 6px; border-radius: 4px; font-size: 7.5px; font-weight: bold; color: ' . $estado_color . '; background-color: ' . $estado_bg . ';">' . h($act['estado']) . '</span></td>
        </tr>';
    }
}

$filas_evidencias = '';
if (empty($evidencias)) {
    $filas_evidencias = '<tr><td colspan="4" style="text-align:center; padding: 8px; color: #64748b;">Sin documentos o evidencias complementarias registradas al momento.</td></tr>';
} else {
    foreach ($evidencias as $idx => $ev) {
        $filas_evidencias .= '<tr>
            <td style="text-align:center; font-weight: bold; width: 30px;">' . ($idx + 1) . '</td>
            <td><strong>' . h($ev['titulo']) . '</strong>' . ($ev['descripcion'] ? '<br><small style="color:#64748b;">' . h($ev['descripcion']) . '</small>' : '') . '</td>
            <td style="text-align:center; font-size: 8.5px;">' . h($ev['tipo_archivo']) . '</td>
            <td style="font-size: 8px; color:#475569;">' . h($ev['fecha_registro']) . '</td>
        </tr>';
    }
}

$fecha_emision = date('d/m/Y H:i');
$anio_actual = $proy['anio'] ?? date('Y');

$html = '<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha Técnica - ' . h($proy['codigo_proyecto']) . '</title>
    <style>
        @page { margin: 15mm 12mm 15mm 12mm; }
        body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; line-height: 1.35; color: #1e293b; }
        .header-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
        .header-table td { vertical-align: middle; border: none; }
        .inst-title { font-size: 9px; font-weight: bold; color: #0f5b2d; text-align: center; text-transform: uppercase; margin: 1px 0; }
        .doc-title { font-size: 13px; font-weight: 900; color: #991b1b; text-align: center; text-transform: uppercase; margin: 6px 0 2px; }
        .doc-subtitle { font-size: 8.5px; color: #475569; text-align: center; margin-bottom: 6px; }
        .code-box { display: inline-block; background: #fee2e2; border: 1.5px solid #dc2626; color: #991b1b; font-weight: 900; font-size: 11px; padding: 4px 12px; border-radius: 6px; }
        
        .section-header { background: #137e4b; color: #ffffff; font-weight: bold; font-size: 9px; text-transform: uppercase; padding: 5px 8px; margin-top: 10px; margin-bottom: 4px; border-radius: 4px; }
        table.data-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 8.5px; }
        table.data-table th, table.data-table td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: top; }
        table.data-table th { background: #f1f5f9; font-weight: bold; color: #334155; }
        .label-cell { background: #f8fafc; font-weight: bold; color: #1e293b; width: 22%; }
        
        .box-info { border: 1px solid #cbd5e1; background: #f8fafc; border-radius: 6px; padding: 8px; margin-bottom: 6px; font-size: 8.5px; }
        .box-info h6 { margin: 0 0 4px; font-size: 9px; color: #0f5b2d; font-weight: bold; }
        
        .badge-status { display: inline-block; padding: 3px 8px; border-radius: 12px; font-weight: bold; font-size: 8.5px; color: #fff; }
        .status-aprobado { background: #16a34a; }
        .status-enviado { background: #2563eb; }
        .status-observado { background: #d97706; }
        .status-formulacion { background: #64748b; }
        
        .signatures { width: 100%; margin-top: 25px; border-collapse: collapse; }
        .signatures td { width: 50%; text-align: center; border: none; vertical-align: bottom; }
        .sig-line { width: 70%; margin: 0 auto; border-top: 1px solid #334155; padding-top: 4px; font-size: 8.5px; font-weight: bold; }
        
        .footer-note { margin-top: 15px; border-top: 1px solid #e2e8f0; padding-top: 6px; text-align: center; font-size: 7.5px; color: #64748b; }
    </style>
</head>
<body>

    <!-- Membrete Oficial UGEL Chincha -->
    <table class="header-table">
        <tr>
            <td style="text-align: center;">
                <div class="inst-title">GOBIERNO REGIONAL DE ICA &bull; DIRECCIÓN REGIONAL DE EDUCACIÓN DE ICA</div>
                <div class="inst-title">UNIDAD DE GESTIÓN EDUCATIVA LOCAL DE CHINCHA</div>
                <div class="inst-title" style="color: #64748b; font-size: 8px;">ÁREA DE GESTIÓN PEDAGÓGICA (AGP) &bull; AÑO ' . $anio_actual . '</div>
                <div class="doc-title">FICHA TÉCNICA OFICIAL DE PROYECTO DE INNOVACIÓN PEDAGÓGICA</div>
                <div class="doc-subtitle">En el marco del Fondo Nacional de Desarrollo de la Educación Peruana (FONDEP) y CGE 4</div>
                <div style="margin-top: 4px;">
                    <span class="code-box">' . h($proy['codigo_proyecto']) . '</span>
                </div>
            </td>
        </tr>
    </table>

    <!-- I. DATOS DE LA INSTITUCIÓN Y RESPONSABLES -->
    <div class="section-header">I. DATOS GENERALES DE LA INSTITUCIÓN EDUCATIVA Y RESPONSABLES</div>
    <table class="data-table">
        <tr>
            <td class="label-cell">Institución Educativa:</td>
            <td><strong>' . h($proy['nombre_ie']) . '</strong></td>
            <td class="label-cell">Código Local:</td>
            <td>' . h($proy['codigo_local']) . '</td>
        </tr>
        <tr>
            <td class="label-cell">Distrito:</td>
            <td>' . h($proy['distrito']) . '</td>
            <td class="label-cell">Año de Ejecución:</td>
            <td>' . h($proy['anio']) . '</td>
        </tr>
        <tr>
            <td class="label-cell">Director(a) / Responsable:</td>
            <td>' . h($dir_completo) . '</td>
            <td class="label-cell">DNI / Contacto:</td>
            <td>DNI: ' . h($proy['dir_dni']) . ' | Tel: ' . h($proy['dir_telefono'] ?: 'No registrado') . '</td>
        </tr>
        <tr>
            <td class="label-cell">Especialista UGEL Asignado:</td>
            <td colspan="3">' . h($esp_completo) . '</td>
        </tr>
    </table>

    <!-- II. IDENTIFICACIÓN DE LA PROPUESTA -->
    <div class="section-header">II. IDENTIFICACIÓN Y LÍNEA TEMÁTICA DEL PROYECTO</div>
    <table class="data-table">
        <tr>
            <td class="label-cell">Título del Proyecto:</td>
            <td colspan="3" style="font-size: 10.5px; font-weight: bold; color: #0f172a;">' . h($proy['titulo']) . '</td>
        </tr>
        <tr>
            <td class="label-cell">Línea Temática MINEDU:</td>
            <td><strong>' . h($nombre_linea) . '</strong></td>
            <td class="label-cell">Estado Actual:</td>
            <td><span class="badge-status status-' . strtolower($proy['estado']) . '">' . h($proy['estado']) . '</span></td>
        </tr>
        <tr>
            <td class="label-cell">Vigencia Programada:</td>
            <td>Del <strong>' . h($proy['fecha_inicio']) . '</strong> al <strong>' . h($proy['fecha_fin']) . '</strong></td>
            <td class="label-cell">Porcentaje de Avance:</td>
            <td><strong>' . number_format($proy['porcentaje_avance'], 1) . '%</strong> de actividades concluidas</td>
        </tr>
    </table>

    <!-- III. DIAGNÓSTICO Y MATRIZ DE OBJETIVOS -->
    <div class="section-header">III. DIAGNÓSTICO, OBJETIVOS Y BENEFICIARIOS</div>
    <div class="box-info">
        <h6>1. Problema Pedagógico Identificado (Diagnóstico):</h6>
        <div>' . nl2br(h($proy['problema_identificado'])) . '</div>
    </div>

    <div class="box-info">
        <h6>2. Objetivo General:</h6>
        <div>' . nl2br(h($proy['objetivo_general'])) . '</div>
    </div>

    ' . (!empty($proy['objetivos_especificos']) ? '
    <div class="box-info">
        <h6>3. Objetivos Específicos:</h6>
        <div>' . nl2br(h($proy['objetivos_especificos'])) . '</div>
    </div>' : '') . '

    <table class="data-table" style="text-align: center; margin-top: 4px;">
        <thead>
            <tr>
                <th>Estudiantes Beneficiados</th>
                <th>Docentes Participantes</th>
                <th>Presupuesto Referencial Estimado</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td style="font-size: 11px; font-weight: bold;">' . number_format($proy['beneficiarios_estudiantes']) . ' estudiantes</td>
                <td style="font-size: 11px; font-weight: bold;">' . number_format($proy['beneficiarios_docentes']) . ' docentes</td>
                <td style="font-size: 11px; font-weight: bold; color: #15803d;">S/ ' . number_format($proy['presupuesto_referencial'], 2) . '</td>
            </tr>
        </tbody>
    </table>

    <!-- IV. CRONOGRAMA DE ACTIVIDADES E HITOS -->
    <div class="section-header">IV. CRONOGRAMA DE ACTIVIDADES E HITOS DE IMPLEMENTACIÓN</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25px;">#</th>
                <th>Descripción de la Actividad</th>
                <th>Responsable</th>
                <th style="width: 75px;">Fechas</th>
                <th style="width: 45px;">Avance</th>
                <th style="width: 60px;">Estado</th>
            </tr>
        </thead>
        <tbody>
            ' . $filas_actividades . '
        </tbody>
    </table>

    <!-- V. RESUMEN DE EVIDENCIAS Y EXPEDIENTE -->
    <div class="section-header">V. DOCUMENTACIÓN Y EVIDENCIAS ADJUNTAS AL EXPEDIENTE</div>
    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 25px;">#</th>
                <th>Documento / Evidencia</th>
                <th style="width: 60px;">Formato</th>
                <th style="width: 90px;">Fecha Registro</th>
            </tr>
        </thead>
        <tbody>
            ' . $filas_evidencias . '
        </tbody>
    </table>

    <!-- VI. DICTAMEN TÉCNICO Y EVALUACIÓN UGEL -->
    <div class="section-header">VI. DICTAMEN TÉCNICO PEDAGÓGICO DE LA UGEL CHINCHA</div>
    <table class="data-table">
        <tr>
            <td class="label-cell">Dictamen de Evaluación:</td>
            <td><strong>' . h($proy['estado']) . '</strong></td>
            <td class="label-cell">Fecha de Dictamen:</td>
            <td>' . h($proy['fecha_aprobacion'] ?: ($proy['fecha_envio'] ?: 'Pendiente')) . '</td>
        </tr>
        <tr>
            <td class="label-cell">Observaciones y Aportes Técnicos del Especialista:</td>
            <td colspan="3">' . nl2br(h($proy['observaciones_revision'] ?: 'Sin observaciones registradas.')) . '</td>
        </tr>
    </table>

    <!-- Firmas Oficiales -->
    <table class="signatures">
        <tr>
            <td>
                <br><br><br>
                <div class="sig-line">
                    ' . h($dir_completo) . '<br>
                    Director(a) de la I.E. ' . h($proy['nombre_ie']) . '<br>
                    DNI: ' . h($proy['dir_dni']) . '
                </div>
            </td>
            <td>
                <br><br><br>
                <div class="sig-line">
                    ' . h($esp_completo) . '<br>
                    Especialista en Educación - AGP<br>
                    UGEL Chincha
                </div>
            </td>
        </tr>
    </table>

    <div class="footer-note">
        Documento emitido formalmente por el Sistema Integrado de Gestión Educativa - UGEL Chincha &bull; Fecha de emisión: ' . $fecha_emision . ' &bull; Código de Validación: ' . sha1($proy['codigo_proyecto'] . $proy['fecha_creacion']) . '
    </div>

</body>
</html>';

// Si no se tiene Dompdf o si falla, permitir visualización / impresión directa
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    echo $html;
    exit();
}

require_once $autoload;

try {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    
    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filename = 'Ficha_Tecnica_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $proy['codigo_proyecto']) . '.pdf';
    
    // Guardar copia local en uploads/proyectos_pdf/
    $dir_pdf = __DIR__ . '/uploads/proyectos_pdf/';
    if (!is_dir($dir_pdf)) {
        @mkdir($dir_pdf, 0777, true);
    }
    @file_put_contents($dir_pdf . $filename, $dompdf->output());

    // Stream al navegador
    $dompdf->stream($filename, ["Attachment" => $descargar ? 1 : 0]);
    exit();

} catch (Throwable $e) {
    // Si ocurre un error con el renderizado binario de Dompdf, mostrar vista HTML imprimible fiel
    echo $html;
}
