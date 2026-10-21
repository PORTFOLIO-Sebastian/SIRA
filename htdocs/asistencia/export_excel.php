<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$personal = asistencia_personal_activo($conn, $idIe);
$calendario = asistencia_calendario($mes, $anio);

try {
    $ruta = asistencia_crear_excel($conn, $mensual, $personal, $calendario);
    header('Location: ../' . $ruta);
} catch (Throwable $e) {
    die($e->getMessage());
}
exit();
?>
