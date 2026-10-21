<?php
// CLI script to compare schools

if (php_sapi_name() !== 'cli') {
    die("Este script solo se puede ejecutar desde la consola.\n");
}

require_once __DIR__ . '/../../conexion.php';

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    die("Falta vendor/autoload.php. Ejecute composer install en htdocs.\n");
}
require_once $autoload;

$excel_file = __DIR__ . '/nexus al 28-04-2026 (3).xls';
if (!file_exists($excel_file)) {
    die("No se encontró el archivo Excel en: " . $excel_file . "\n");
}

echo "Leyendo archivo Excel...\n";
try {
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($excel_file);
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($excel_file);
    $sheet = $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray();
} catch (Exception $e) {
    die("Error al leer el archivo Excel: " . $e->getMessage() . "\n");
}

$headers = array_shift($rows);
$codigo_local_idx = array_search('CODIGO LOCAL', $headers);
$nombre_ie_idx = array_search('NOMBRE DE LA INSTITUCION EDUCATIVA', $headers);

if ($codigo_local_idx === false) {
    die("No se encontró la columna 'CODIGO LOCAL' en el Excel.\n");
}

// Collect unique codes from Excel
$excel_codes = [];
foreach ($rows as $row) {
    $code = trim((string)$row[$codigo_local_idx]);
    if ($code !== '' && strtolower($code) !== 'nan' && strtolower($code) !== 'null') {
        if (strpos($code, '.') !== false) {
            $code = explode('.', $code)[0];
        }
        $ie_name = $nombre_ie_idx !== false ? trim((string)$row[$nombre_ie_idx]) : 'Desconocida';
        $excel_codes[$code] = $ie_name;
    }
}

// Get all codes from DB
$db_codes = [];
$res = $conn->query("SELECT id, codigo_local, nombre_ie FROM instituciones");
if (!$res) {
    die("Error al consultar la tabla instituciones: " . $conn->error . "\n");
}

while ($db_row = $res->fetch_assoc()) {
    $code = trim((string)$db_row['codigo_local']);
    $db_codes[$code] = trim((string)$db_row['nombre_ie']);
}

// Compares
$missing_in_db = [];
foreach ($excel_codes as $code => $name) {
    if (!isset($db_codes[$code])) {
        $missing_in_db[$code] = $name;
    }
}

$missing_in_excel = [];
foreach ($db_codes as $code => $name) {
    if (!isset($excel_codes[$code])) {
        $missing_in_excel[$code] = $name;
    }
}

echo "\n==================================================\n";
echo " COMPARACIÓN DE COLEGIO EN LOCAL\n";
echo "==================================================\n";
echo "Colegios en Excel Nexus: " . count($excel_codes) . "\n";
echo "Colegios en la Base de Datos: " . count($db_codes) . "\n";
echo "--------------------------------------------------\n";

if (count($missing_in_db) === 0) {
    echo "✓ TODOS LOS COLEGIO DEL EXCEL EXISTEN EN LA BASE DE DATOS.\n";
} else {
    echo "✗ COLEGIO EN EL EXCEL QUE NO EXISTEN EN LA BASE DE DATOS (" . count($missing_in_db) . "):\n";
    foreach ($missing_in_db as $code => $name) {
        echo "  - Código: $code | Nombre: $name\n";
    }
}

echo "--------------------------------------------------\n";
if (count($missing_in_excel) === 0) {
    echo "✓ TODOS LOS COLEGIO DE LA BASE DE DATOS ESTÁN EN EL EXCEL.\n";
} else {
    echo "i COLEGIO EN LA BASE DE DATOS QUE NO ESTÁN EN EL EXCEL (" . count($missing_in_excel) . "):\n";
    $count = 0;
    foreach ($missing_in_excel as $code => $name) {
        if ($count >= 15) {
            echo "  - ... y " . (count($missing_in_excel) - 15) . " más.\n";
            break;
        }
        echo "  - Código: $code | Nombre: $name\n";
        $count++;
    }
}
echo "==================================================\n";
