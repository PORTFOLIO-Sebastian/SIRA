<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'administrador') {
    header("Location: login.php");
    exit();
}

$id = $_POST['id_usuario'] ?? '';
$dni = $_POST['dni'];
$pass = $_POST['password'];
$nombres = $_POST['nombres'];
$ape_pat = $_POST['ape_pat'];
$ape_mat = $_POST['ape_mat'];
$correo = $_POST['correo'];
$telefono = $_POST['telefono']; 
$rol = $_POST['rol'];
$estado = $_POST['estado'];

// --- VALIDACIÓN DE DNI DUPLICADO ---
if (!empty($id)) {
    // Si editamos: Permite el registro si el DNI es del mismo ID, bloquea si pertenece a OTRO.
    $check_dni = $conn->prepare("SELECT id FROM usuarios WHERE dni = ? AND id != ?");
    $check_dni->bind_param("si", $dni, $id);
} else {
    // Si es nuevo: Bloquea si el DNI ya existe.
    $check_dni = $conn->prepare("SELECT id FROM usuarios WHERE dni = ?");
    $check_dni->bind_param("s", $dni);
}

$check_dni->execute();
if ($check_dni->get_result()->num_rows > 0) {
    header("Location: administrador.php?view=usuarios&status=duplicate");
    exit();
}

// --- PROCESO DE GUARDADO O ACTUALIZACIÓN ---
if (!empty($id)) {
    // Actualización de campos
    $stmt = $conn->prepare("UPDATE usuarios SET dni=?, password=?, nombres=?, ape_pat=?, ape_mat=?, correo=?, telefono=?, rol=?, estado=? WHERE id=?");
    $stmt->bind_param("sssssssssi", $dni, $pass, $nombres, $ape_pat, $ape_mat, $correo, $telefono, $rol, $estado, $id);
    $status = "updated";
} else {
    // Registro nuevo
    $stmt = $conn->prepare("INSERT INTO usuarios (dni, password, nombres, ape_pat, ape_mat, correo, telefono, rol, estado) VALUES (?,?,?,?,?,?,?,?,?)");
    $stmt->bind_param("sssssssss", $dni, $pass, $nombres, $ape_pat, $ape_mat, $correo, $telefono, $rol, $estado);
    $status = "saved";
}

if ($stmt->execute()) {
    header("Location: administrador.php?view=usuarios&status=$status");
} else {
    header("Location: administrador.php?view=usuarios&status=error");
}
exit();