<?php
session_start();
include 'conexion.php';

// Validar que se reciban los datos por POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_envio'], $_POST['estado'])) {
    
    $id_envio = intval($_POST['id_envio']);
    $estado = $_POST['estado'];
    $observacion = $_POST['observacion'] ?? NULL;

    // Si el estado es Conforme o Pendiente, limpiamos la observación por si acaso
    if ($estado === 'Conforme' || $estado === 'Pendiente') {
        $observacion = NULL;
    }

    $sql = "UPDATE envios SET estado = ?, observacion = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    
    if ($stmt) {
        $stmt->bind_param("ssi", $estado, $observacion, $id_envio);
        if ($stmt->execute()) {
            // Redirigir de vuelta al panel del especialista con éxito
            header("Location: ver_envios.php?msg=evaluado");
            exit();
        } else {
            die("Error al actualizar: " . $stmt->error);
        }
    } else {
        die("Error en la preparación de la consulta: " . $conn->error);
    }
} else {
    die("Acceso no autorizado.");
}
?>