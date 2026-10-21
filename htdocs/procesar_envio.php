<?php
session_start();
include 'conexion.php';

// 1. Validar sesión
if (!isset($_SESSION['id_usuario'])) {
    die("Error: Sesión no válida. Vuelva a iniciar sesión.");
}

// 2. Capturar datos comunes
$id_ie = $_SESSION['id_ie'] ?? 1;
$id_usuario_envio = $_SESSION['id_usuario'];

// OBTENER CODIGO LOCAL DESDE ASIGNACIONES E INSTITUCIONES
$codigo_local = '000000'; // Valor por defecto en caso de fallo
$sql_codigo = "SELECT i.codigo_local FROM asignaciones a INNER JOIN instituciones i ON a.id_ie = i.id WHERE a.id_usuario = ?";
$stmt_codigo = $conn->prepare($sql_codigo);
if ($stmt_codigo) {
    $stmt_codigo->bind_param("i", $id_usuario_envio);
    $stmt_codigo->execute();
    $res_codigo = $stmt_codigo->get_result();
    if ($row_codigo = $res_codigo->fetch_assoc()) {
        $codigo_local = $row_codigo['codigo_local'];
    }
    $stmt_codigo->close();
}

$tipo_documento = $_POST['tipo_documento'] ?? '';
$mes = !empty($_POST['mes']) ? $_POST['mes'] : null;
$anio = $_POST['anio'] ?? date('Y');

// Definir si es un documento de Gestión
$es_gestion = in_array($tipo_documento, ['RI', 'PAT', 'PSI', 'PI', 'RD_CONEI', 'DocGestion']);

// 3. Preparar carpeta de subidas 
$upload_dir = 'uploads/';
if ($tipo_documento == 'Mensual') {
    $upload_dir = 'uploads/' . $codigo_local . '/' . $anio . '/Asistencia mensual/';
} elseif ($es_gestion) {
    $upload_dir = 'uploads/' . $codigo_local . '/' . $anio . '/Documentos de Gestión/';
} elseif ($tipo_documento == 'Innovacion') {
    $upload_dir = 'uploads/' . $codigo_local . '/' . $anio . '/Proyecto de innovacion/';
} elseif (in_array($tipo_documento, ['ECRE INICIO', 'ECRE PROCESO', 'ECRE SALIDA'])) {
    // NUEVA ESTRUCTURA DE CARPETAS PARA ECRE
    $upload_dir = 'uploads/' . $codigo_local . '/' . $anio . '/Reportes ECRE/';
}

if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

$ruta_pdf = '';
$ruta_excel = null; 

// 4. Lógica de subida según el tipo de documento
if (in_array($tipo_documento, ['ECRE INICIO', 'ECRE PROCESO', 'ECRE SALIDA'])) {
    // LÓGICA DE SUBIDA PARA ECRE (HASTA 6 ARCHIVOS EXCEL)
    if (isset($_FILES['archivos_epla'])) {
        $rutas_epla = [];
        $total_archivos = count($_FILES['archivos_epla']['name']);
        $limite = min($total_archivos, 6); 
        
        for ($i = 0; $i < $limite; $i++) {
            if ($_FILES['archivos_epla']['error'][$i] == 0) {
                $ext = pathinfo($_FILES['archivos_epla']['name'][$i], PATHINFO_EXTENSION);
                // NUEVA NOMENCLATURA: codigo_local - Tipo de Documento - año - codigo_azar_indice
                $nombre = $codigo_local . " - " . $tipo_documento . " - " . $anio . " - " . rand(100,999) . "_" . $i . "." . $ext;
                if (move_uploaded_file($_FILES['archivos_epla']['tmp_name'][$i], $upload_dir . $nombre)) {
                    $rutas_epla[] = $upload_dir . $nombre;
                }
            }
        }
        $ruta_excel = implode(",", $rutas_epla); 
        $ruta_pdf = 'N/A'; 
    } else {
        die("Error: Faltan archivos ECRE.");
    }

} elseif ($tipo_documento == 'Innovacion') {
    if (isset($_FILES['archivo_innovacion']) && $_FILES['archivo_innovacion']['error'] == 0) {
        $ext = pathinfo($_FILES['archivo_innovacion']['name'], PATHINFO_EXTENSION);
        $nombre_archivo = $codigo_local . " - " . $tipo_documento . " - " . $anio . " - " . rand(100,999) . "." . $ext;
        $ruta_pdf = $upload_dir . $nombre_archivo;
        
        if (!move_uploaded_file($_FILES['archivo_innovacion']['tmp_name'], $ruta_pdf)) {
            die("Error al guardar el proyecto de Innovación.");
        }
    } else {
        die("Error: El documento de Innovación es obligatorio.");
    }

} elseif ($tipo_documento == 'Mensual' || $es_gestion) {
    
    $identificador = $es_gestion ? $tipo_documento : $mes;
    
    if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] == 0) {
        $ext_pdf = pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION);
        $nombre_pdf = $codigo_local . " - " . $identificador . " - " . $anio . " - " . rand(100,999) . "." . $ext_pdf;
        $ruta_pdf = $upload_dir . $nombre_pdf;
        if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $ruta_pdf)) {
            die("Error al guardar el PDF.");
        }
    } else {
        die("Error: El documento PDF es obligatorio.");
    }

    if ($tipo_documento == 'Mensual') {
        if (isset($_FILES['archivo_excel']) && $_FILES['archivo_excel']['error'] == 0) {
            $ext_excel = pathinfo($_FILES['archivo_excel']['name'], PATHINFO_EXTENSION);
            $nombre_excel = $codigo_local . " - " . $identificador . " - " . $anio . " - " . rand(100,999) . "." . $ext_excel;
            $ruta_excel = $upload_dir . $nombre_excel;
            move_uploaded_file($_FILES['archivo_excel']['tmp_name'], $ruta_excel);
        }
    }

} else {
    // Procesar lógica original de fallback por seguridad
    if (isset($_FILES['archivo_pdf']) && $_FILES['archivo_pdf']['error'] == 0) {
        $ext_pdf = pathinfo($_FILES['archivo_pdf']['name'], PATHINFO_EXTENSION);
        $nombre_pdf = $tipo_documento . "_PDF_" . time() . "_" . rand(100,999) . "." . $ext_pdf;
        $ruta_pdf = $upload_dir . $nombre_pdf;
        if (!move_uploaded_file($_FILES['archivo_pdf']['tmp_name'], $ruta_pdf)) {
            die("Error al guardar el PDF.");
        }
    } else {
        die("Error: El documento PDF es obligatorio.");
    }
}

// 5. Generar datos automáticos
$fecha_envio = date('Y-m-d H:i:s');
$codigo_constancia = "CONST-" . date('Y') . "-" . rand(1000,9999) . "-" . $codigo_local;
$estado = 'Pendiente';

// 6. Insertar en BD
$sql = "INSERT INTO envios (id_ie, id_usuario_envio, tipo_documento, mes, anio, ruta_archivo, ruta_excel, fecha_envio, codigo_constancia, estado) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("iisissssss", $id_ie, $id_usuario_envio, $tipo_documento, $mes, $anio, $ruta_pdf, $ruta_excel, $fecha_envio, $codigo_constancia, $estado);
    if ($stmt->execute()) {
        header("Location: ver_constancia.php?id=" . $stmt->insert_id);
        exit();
    } else {
        echo "Error en Base de datos: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Error de preparación SQL: " . $conn->error;
}
$conn->close();
?>