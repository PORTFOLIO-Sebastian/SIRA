<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_ie']) || !isset($_POST['id_comunicado'])) {
    echo "error";
    exit();
}

$id_ie = $_SESSION['id_ie'];
$id_comunicado = intval($_POST['id_comunicado']);

// Verificar que no se haya registrado antes (por seguridad)
$check = $conn->prepare("SELECT id FROM comunicados_vistos WHERE id_comunicado = ? AND id_ie = ?");
$check->bind_param("ii", $id_comunicado, $id_ie);
$check->execute();
$res = $check->get_result();

if ($res->num_rows == 0) {
    // Si no existe, lo insertamos con la fecha y hora actual
    $insert = $conn->prepare("INSERT INTO comunicados_vistos (id_comunicado, id_ie) VALUES (?, ?)");
    $insert->bind_param("ii", $id_comunicado, $id_ie);
    if ($insert->execute()) {
        echo "ok";
    } else {
        echo "error db";
    }
    $insert->close();
} else {
    // Si por algún motivo ya estaba, devolvemos ok para que no se bloquee
    echo "ok";
}

$check->close();
$conn->close();
?>