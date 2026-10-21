<?php
include 'conexion.php';

// Asegurarnos de tener la librería FPDF en la misma carpeta
require('fpdf.php'); 

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] != 'director') {
    die("Acceso denegado.");
}

$id_ie = $_SESSION['id_usuario'];
$nombre_ie = $_SESSION['nombre_ie'];
$tipo_documento = $_POST['tipo_documento'];
$mes = isset($_POST['mes']) ? $_POST['mes'] : null;
$anio = date("Y"); // Año actual 2026

// 1. Manejo del Archivo Subido
$directorio_subida = "uploads/";
// Crear directorio si no existe
if (!file_exists($directorio_subida)) {
    mkdir($directorio_subida, 0777, true);
}

$nombre_archivo_original = basename($_FILES["archivo"]["name"]);
// Renombrar archivo para evitar duplicados (Ej: PAT_15_167890.pdf)
$nuevo_nombre_archivo = $tipo_documento . "_" . $id_ie . "_" . time() . "_" . $nombre_archivo_original;
$ruta_final = $directorio_subida . $nuevo_nombre_archivo;

if (move_uploaded_file($_FILES["archivo"]["tmp_name"], $ruta_final)) {
    
    // 2. Generar Código de Constancia Único
    $codigo_constancia = "CONST-" . $anio . "-" . rand(1000, 9999) . "-" . $id_ie;

    // 3. Guardar en Base de Datos
    $stmt = $conn->prepare("INSERT INTO envios (id_ie, tipo_documento, mes, anio, ruta_archivo, codigo_constancia) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("isiiss", $id_ie, $tipo_documento, $mes, $anio, $ruta_final, $codigo_constancia);
    $stmt->execute();
    $stmt->close();
    $conn->close();

    // 4. Generar Constancia en PDF usando FPDF
    $pdf = new FPDF();
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 16);
    
    // Título
    $pdf->Cell(190, 10, utf8_decode('CONSTANCIA DE ENVÍO DE DOCUMENTOS'), 0, 1, 'C');
    $pdf->Ln(10);
    
    // Detalles
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 10, utf8_decode('Código Constancia:'), 0, 0);
    $pdf->SetFont('Arial', 'B', 12);
    $pdf->Cell(140, 10, $codigo_constancia, 0, 1);
    
    $pdf->SetFont('Arial', '', 12);
    $pdf->Cell(50, 10, utf8_decode('Institución Educativa:'), 0, 0);
    $pdf->Cell(140, 10, utf8_decode($nombre_ie), 0, 1);
    
    $pdf->Cell(50, 10, utf8_decode('Documento:'), 0, 0);
    $pdf->Cell(140, 10, utf8_decode($tipo_documento), 0, 1);
    
    if($mes != null){
        $pdf->Cell(50, 10, utf8_decode('Mes / Año:'), 0, 0);
        $pdf->Cell(140, 10, $mes . ' / ' . $anio, 0, 1);
    }
    
    $pdf->Cell(50, 10, utf8_decode('Fecha de Recepción:'), 0, 0);
    $pdf->Cell(140, 10, date('d/m/Y H:i:s'), 0, 1);
    
    $pdf->Ln(20);
    $pdf->SetFont('Arial', 'I', 10);
    $pdf->MultiCell(190, 10, utf8_decode('Este documento es un comprobante oficial de que la IE ha realizado el envío digital del archivo a través de la plataforma de la UGEL. El archivo está sujeto a revisión por los especialistas.'), 0, 'C');

    // Forzar la descarga del PDF en el navegador
    $pdf->Output('D', 'Constancia_'.$codigo_constancia.'.pdf');
    
} else {
    echo "Hubo un error al subir el archivo.";
}
?>