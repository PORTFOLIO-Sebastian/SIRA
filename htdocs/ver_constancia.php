<?php
session_start();
include 'conexion.php';

// Validar si se recibe el ID de la constancia
if (!isset($_GET['id'])) {
    die("Error: No se especificó el ID de la constancia.");
}

$id_envio = intval($_GET['id']);

// Consultar los datos del envío uniendo con la tabla de instituciones
$sql = "SELECT e.*, i.codigo_local, i.nombre_ie 
        FROM envios e 
        LEFT JOIN instituciones i ON e.id_ie = i.id 
        WHERE e.id = ?";
        
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $id_envio);
    $stmt->execute();
    $resultado = $stmt->get_result();
    
    if ($resultado->num_rows === 0) {
        die("Error: Constancia no encontrada.");
    }
    
    $datos = $resultado->fetch_assoc();
    $stmt->close();
} else {
    die("Error en la consulta: " . $conn->error);
}

// Formatear el periodo dependiendo de si es Mensual o de Gestión
$periodo = "";
if ($datos['tipo_documento'] === 'Mensual') {
    $meses = [1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];
    $nombre_mes = isset($meses[$datos['mes']]) ? $meses[$datos['mes']] : "Desconocido";
    $periodo = $nombre_mes . " - " . $datos['anio'];
} else {
    $periodo = "Año " . $datos['anio'];
}

// Formatear los archivos entregados
$archivos_entregados = "Documento PDF";
if ($datos['tipo_documento'] === 'Mensual') {
    $archivos_entregados .= " y Archivo Excel";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de Envío - UGEL</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        body { 
            background-color: #f8f9fa; 
            padding: 20px; 
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .constancia-container {
            max-width: 800px;
            width: 100%;
            background: white;
            padding: 40px;
            border: 2px dashed #6c757d;
            border-radius: 10px;
        }
        .table th { 
            background-color: #f8f9fa; 
            width: 35%; 
        }
        /* Esta regla oculta los botones a la hora de Imprimir/Guardar PDF */
        @media print {
            .no-print { display: none !important; }
            .constancia-container { border: none; padding: 0; box-shadow: none !important; }
            body { background-color: white; align-items: flex-start; padding: 0;}
        }
    </style>
</head>
<body>

<div class="constancia-container shadow">
    <h3 class="text-center mb-4 fw-bold">CONSTANCIA OFICIAL DE RECEPCIÓN DIGITAL</h3>
    
    <table class="table table-bordered align-middle">
        <tbody>
            <tr>
                <th class="py-3">Código de Transacción:</th>
                <td class="fw-bold fs-5 py-3"><?php echo htmlspecialchars($datos['codigo_constancia']); ?></td>
            </tr>
            <tr>
                <th class="py-3">Institución Educativa:</th>
                <td class="py-3"><?php echo htmlspecialchars($datos['nombre_ie'] . " (Cód: " . $datos['codigo_local'] . ")"); ?></td>
            </tr>
            <tr>
                <th class="py-3">Tipo de Documento:</th>
                <td class="py-3"><?php echo htmlspecialchars($datos['tipo_documento']); ?></td>
            </tr>
            <tr>
                <th class="py-3">Periodo Correspondiente:</th>
                <td class="py-3"><?php echo htmlspecialchars($periodo); ?></td>
            </tr>
            <tr>
                <th class="py-3">Archivos Entregados:</th>
                <td class="py-3"><?php echo $archivos_entregados; ?></td>
            </tr>
            <tr>
                <th class="py-3">Fecha y Hora de Recepción:</th>
                <td class="py-3"><?php echo date("d/m/Y h:i A", strtotime($datos['fecha_envio'])); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="alert alert-success text-center mt-4" style="background-color: #d1e7dd; color: #0f5132; border-color: #badbcc;">
        Esta información ha sido guardada y registrada de manera segura en la base de datos de la UGEL.
    </div>

    <div class="text-center mt-4 no-print d-flex justify-content-center gap-3">
        <button onclick="window.print()" class="btn btn-primary btn-lg" style="background-color: #0d6efd;">
            Guardar como PDF / Imprimir
        </button>
        
        <a href="dashboard.php" class="btn btn-secondary btn-lg" style="background-color: #6c757d; border: none;">
            Volver al Menú
        </a>
    </div>
</div>

</body>
</html>
