<?php
// Script temporal para corregir el rol del usuario especialista
// ELIMINAR ESTE ARCHIVO DESPUÉS DE USARLO
session_start();
include 'conexion.php';

$dni = '33333333';

// Primero, verificar qué rol tiene actualmente
$stmt = $conn->prepare("SELECT id, nombres, ape_pat, rol, estado FROM usuarios WHERE dni = ? LIMIT 1");
$stmt->bind_param('s', $dni);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo "<p style='color:red'>❌ No se encontró ningún usuario con DNI <b>$dni</b></p>";
    exit;
}

echo "<h3>Usuario encontrado:</h3>";
echo "<pre>";
print_r($user);
echo "</pre>";

// Si se pasa ?fix=1, actualizar el rol a especialista
if (isset($_GET['fix']) && $_GET['fix'] === '1') {
    $stmt2 = $conn->prepare("UPDATE usuarios SET rol = 'especialista' WHERE dni = ? AND id = ?");
    $stmt2->bind_param('si', $dni, $user['id']);
    if ($stmt2->execute()) {
        echo "<p style='color:green'>✅ Rol actualizado a <b>especialista</b> exitosamente.</p>";
    } else {
        echo "<p style='color:red'>❌ Error al actualizar: " . $conn->error . "</p>";
    }
    $stmt2->close();
} else {
    echo "<p><a href='?fix=1' style='color:blue;font-weight:bold'>➡ Clic aquí para cambiar el rol a ESPECIALISTA</a></p>";
    echo "<p style='color:gray;font-size:0.9em'>El rol actual es: <b>" . htmlspecialchars($user['rol']) . "</b></p>";
}
?>
