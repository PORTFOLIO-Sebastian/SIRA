<?php
// Script temporal de consulta - ELIMINAR DESPUÉS DE USAR
session_start();
include 'conexion.php';

$roles = ['especialista', 'jefe_agp'];
foreach ($roles as $rol) {
    $stmt = $conn->prepare("SELECT id, nombres, ape_pat, dni, rol, estado FROM usuarios WHERE rol = ? ORDER BY id LIMIT 10");
    $stmt->bind_param('s', $rol);
    $stmt->execute();
    $res = $stmt->get_result();
    echo "<h3 style='font-family:sans-serif;color:#1a1a2e'>Rol: <span style='color:#6d28d9'>$rol</span></h3>";
    echo "<table border='1' cellpadding='6' style='border-collapse:collapse;font-family:sans-serif;font-size:0.9rem'>";
    echo "<tr style='background:#f0ebff'><th>ID</th><th>Nombres</th><th>DNI</th><th>Rol</th><th>Estado</th></tr>";
    while ($u = $res->fetch_assoc()) {
        $color = $u['estado'] === 'activo' ? '#d1fae5' : '#fee2e2';
        echo "<tr style='background:$color'><td>{$u['id']}</td><td>{$u['nombres']} {$u['ape_pat']}</td><td><b>{$u['dni']}</b></td><td>{$u['rol']}</td><td>{$u['estado']}</td></tr>";
    }
    echo "</table><br>";
    $stmt->close();
}
echo "<p style='font-family:sans-serif;color:gray;font-size:0.8rem'>⚠️ Eliminar este archivo después de consultarlo.</p>";
?>
