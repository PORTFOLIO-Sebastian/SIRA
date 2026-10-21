<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    http_response_code(403);
    exit('Sesion no valida.');
}

$rol = $_SESSION['rol'] ?? '';
if (!in_array($rol, ['especialista', 'jefe_agp'], true)) {
    http_response_code(403);
    exit('No autorizado.');
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function vr_clear_output(): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}
function vr_clean_text($value): string {
    $text = trim((string)($value ?? ''));
    // Reemplazar múltiples espacios horizontales y tabulaciones por un espacio, preservando saltos de línea
    $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    return $text;
}

function vr_rows(): array {
    $raw = $_POST['rows'] ?? '[]';
    $rows = json_decode((string)$raw, true);
    if (!is_array($rows)) {
        return [];
    }

    $allowed = [
        'cod_local',
        'nombre',
        'nivel',
        'distrito',
        'fecha_autoevaluacion',
        'puntaje_autoevaluacion',
        'logro_autoevaluacion',
        'fecha_evaluacion',
        'puntaje_evaluacion',
        'logro_evaluacion',
        'items_destacados',
        'items_criticos',
    ];

    $clean = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $item = [];
        foreach ($allowed as $key) {
            $item[$key] = vr_clean_text($row[$key] ?? '');
        }
        $clean[] = $item;
    }
    return $clean;
}

function vr_filename(string $ext): string {
    return 'reporte_monitoreo_institucional_' . date('Ymd_His') . '.' . $ext;
}

function vr_headers(): array {
    return [
        'cod_local' => 'Cod. Local',
        'nombre' => 'Institucion Educativa',
        'nivel' => 'Nivel',
        'distrito' => 'Distrito',
        'fecha_autoevaluacion' => 'Fecha Autoevalacion (Dir.)',
        'puntaje_autoevaluacion' => 'Puntaje Alcanzado Autoevaluacion (Dir.)',
        'logro_autoevaluacion' => 'Nivel Logro Autoevaluacion (Dir.)',
        'fecha_evaluacion' => 'Fecha Evaluacion (Esp.)',
        'puntaje_evaluacion' => 'Puntaje Alcanzado Evaluacion (Esp.)',
        'logro_evaluacion' => 'Nivel Logro Evaluacion (Esp.)',
        'items_destacados' => 'Items destacados',
        'items_criticos' => 'Items críticos',
    ];
}
function vr_excel_xml(array $headers, array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    $xml .= '<Styles><Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#1A5C38" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center" ss:Vertical="Center"/></Style><Style ss:ID="Text"><Alignment ss:Vertical="Top" ss:WrapText="1"/></Style></Styles>';
    $xml .= '<Worksheet ss:Name="Detalle Monitoreo"><Table>';

    foreach ($headers as $label) {
        $xml .= '<Column ss:AutoFitWidth="1" ss:Width="140"/>';
    }

    $xml .= '<Row>';
    foreach ($headers as $label) {
        $xml .= '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($label, ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Data></Cell>';
    }
    $xml .= '</Row>';

    foreach ($rows as $row) {
        $xml .= '<Row>';
        foreach (array_keys($headers) as $key) {
            $xml .= '<Cell ss:StyleID="Text"><Data ss:Type="String">' . htmlspecialchars($row[$key] ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8') . '</Data></Cell>';
        }
        $xml .= '</Row>';
    }

    $xml .= '</Table><WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel"><FreezePanes/><FrozenNoSplit/><SplitHorizontal>1</SplitHorizontal><TopRowBottomPane>1</TopRowBottomPane><ActivePane>2</ActivePane></WorksheetOptions></Worksheet></Workbook>';
    return $xml;
}

$format = strtolower((string)($_POST['format'] ?? ''));
$rows = vr_rows();

if (empty($rows)) {
    http_response_code(400);
    exit('No hay datos de la tabla para exportar.');
}

$headers = vr_headers();

if ($format === 'excel') {
    if (!class_exists(Spreadsheet::class) || !class_exists(Xlsx::class)) {
        $excelXml = vr_excel_xml($headers, $rows);
        vr_clear_output();
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . vr_filename('xls') . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . strlen($excelXml));
        header('Cache-Control: max-age=0, must-revalidate');
        echo $excelXml;
        exit;
    }

    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Detalle Monitoreo');

    $col = 1;
    foreach ($headers as $label) {
        $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . '1', $label);
        $col++;
    }

    $rowNum = 2;
    foreach ($rows as $row) {
        $col = 1;
        foreach (array_keys($headers) as $key) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col) . $rowNum, $row[$key] ?? '');
            $col++;
        }
        $rowNum++;
    }

    $lastCol = $sheet->getHighestColumn();
    $lastRow = $sheet->getHighestRow();
    $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A5C38']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getStyle("A1:{$lastCol}{$lastRow}")->applyFromArray([
        'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D9E2EC']]],
        'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
    ]);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter("A1:{$lastCol}{$lastRow}");
    foreach (range('A', $lastCol) as $column) {
        $sheet->getColumnDimension($column)->setAutoSize(true);
    }

    $writer = new Xlsx($spreadsheet);
    $stream = fopen('php://temp', 'r+');
    $writer->save($stream);
    rewind($stream);
    $excelBinary = stream_get_contents($stream);
    fclose($stream);

    vr_clear_output();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . vr_filename('xlsx') . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . strlen($excelBinary));
    header('Cache-Control: max-age=0, must-revalidate');
    echo $excelBinary;
    exit;
}

if ($format === 'pdf') {
    $htmlRows = '';
    foreach ($rows as $row) {
        $htmlRows .= '<tr>';
        foreach (array_keys($headers) as $key) {
            $htmlRows .= '<td>' . htmlspecialchars($row[$key] ?? '', ENT_QUOTES, 'UTF-8') . '</td>';
        }
        $htmlRows .= '</tr>';
    }

    $htmlHeads = '';
    foreach ($headers as $label) {
        $htmlHeads .= '<th>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</th>';
    }

    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>
        @page { margin: 18px 16px; }
        body { font-family: DejaVu Sans, Arial, sans-serif; color: #1f2937; font-size: 9px; }
        h1 { color: #1a5c38; font-size: 18px; margin: 0 0 4px; }
        .meta { color: #64748b; margin-bottom: 12px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1a5c38; color: #fff; padding: 7px 6px; text-align: left; font-size: 8px; }
        td { border-bottom: 1px solid #e5e7eb; padding: 6px; vertical-align: top; }
        tr:nth-child(even) td { background: #f8fafc; }
    </style></head><body>
        <h1>Detalle de Monitoreo Institucional</h1>
        <div class="meta">Registros exportados: ' . count($rows) . ' | Generado: ' . date('d/m/Y H:i') . '</div>
        <table><thead><tr>' . $htmlHeads . '</tr></thead><tbody>' . $htmlRows . '</tbody></table>
    </body></html>';

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    vr_clear_output();
    $dompdf->stream(vr_filename('pdf'), ['Attachment' => true]);
    exit;
}

http_response_code(400);
exit('Formato no soportado.');


