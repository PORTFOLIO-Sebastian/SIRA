<?php
session_start();

if (!isset($_SESSION['id_usuario'])) {
    echo "No hay sesión activa. <a href='login.php'>Ir al login</a>";
    exit;
}

echo "<h2>Verificación de Rol del Usuario</h2>";
echo "<p><strong>ID Usuario:</strong> " . $_SESSION['id_usuario'] . "</p>";
echo "<p><strong>Rol en sesión:</strong> '" . $_SESSION['rol'] . "'</p>";
echo "<p><strong>Rol en minúsculas:</strong> '" . strtolower($_SESSION['rol'] ?? '') . "'</p>";
echo "<p><strong>Rol con trim:</strong> '" . trim(strtolower($_SESSION['rol'] ?? '')) . "'</p>";

include 'conexion.php';

$id_usuario = (int)$_SESSION['id_usuario'];
$stmt = $conn->prepare("SELECT id, rol, nombres, ape_pat FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo "<h3>Datos en la base de datos:</h3>";
if ($result) {
    echo "<p><strong>ID:</strong> " . $result['id'] . "</p>";
    echo "<p><strong>Rol en BD:</strong> '" . $result['rol'] . "'</p>";
    echo "<p><strong>Nombres:</strong> " . $result['nombres'] . " " . $result['ape_pat'] . "</p>";
} else {
    echo "<p>No se encontró el usuario en la BD</p>";
}

echo "<hr>";
echo "<p><a href='dashboard.php'>Volver al Dashboard</a></p>";
echo "<p><a href='logout.php'>Cerrar Sesión</a></p>";
?>
