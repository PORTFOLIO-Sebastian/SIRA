<?php
require 'conexion.php';
$res = $conn->query('SELECT COUNT(*) as total, SUM(CASE WHEN latitud IS NOT NULL THEN 1 ELSE 0 END) as with_coords FROM fm_registros');
print_r($res->fetch_assoc());
unlink(__FILE__);
?>
