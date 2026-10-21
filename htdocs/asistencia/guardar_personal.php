<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$id = (int)($_POST['id'] ?? 0);
$accion = $_POST['accion'] ?? 'guardar';

if ($accion === 'baja' && $id > 0) {
    $stmt = $conn->prepare("UPDATE asistencia_personal SET estado = 'inactivo' WHERE id = ? AND id_ie = ?");
    $stmt->bind_param('ii', $id, $idIe);
    $stmt->execute();
    $stmt->close();
    asistencia_auditar($conn, 'asistencia_personal', $id, 'BAJA', null, 'inactivo', $idUsuario);
    header('Location: ' . asistencia_url('personal.php', $mes, $anio) . '&ok=1');
    exit();
}

$dni = trim($_POST['dni'] ?? '');
$nombres = trim($_POST['apellidos_nombres'] ?? '');
$cargo = trim($_POST['cargo'] ?? '');
$condicion = trim($_POST['condicion_laboral'] ?? '');
$jornada = trim($_POST['jornada_laboral'] ?? '');
$nivel = trim($_POST['nivel_educativo'] ?? '');
$observaciones = trim($_POST['observaciones'] ?? '');

if ($dni === '' || $nombres === '' || $cargo === '' || $condicion === '' || $jornada === '' || $nivel === '') {
    die('Faltan datos obligatorios del trabajador.');
}

$stmtCheck = $conn->prepare("SELECT id FROM asistencia_personal WHERE id_ie = ? AND dni = ? AND id != ?");
$stmtCheck->bind_param('isi', $idIe, $dni, $id);
$stmtCheck->execute();
$resCheck = $stmtCheck->get_result();
if ($resCheck->num_rows > 0) {
    $stmtCheck->close();
    header('Location: ' . asistencia_url('personal.php', $mes, $anio) . '&error=' . urlencode('El DNI ingresado ya se encuentra registrado para otro trabajador en esta Institución Educativa.'));
    exit();
}
$stmtCheck->close();


if ($id > 0) {
    $stmt = $conn->prepare("UPDATE asistencia_personal SET dni=?, apellidos_nombres=?, cargo=?, condicion_laboral=?, jornada_laboral=?, nivel_educativo=?, observaciones=?, estado='activo' WHERE id=? AND id_ie=?");
    $stmt->bind_param('sssssssii', $dni, $nombres, $cargo, $condicion, $jornada, $nivel, $observaciones, $id, $idIe);
    $stmt->execute();
    $stmt->close();
    asistencia_auditar($conn, 'asistencia_personal', $id, 'ACTUALIZAR', null, json_encode($_POST, JSON_UNESCAPED_UNICODE), $idUsuario);
} else {
    $stmt = $conn->prepare("INSERT INTO asistencia_personal (id_ie, dni, apellidos_nombres, cargo, condicion_laboral, jornada_laboral, nivel_educativo, observaciones) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('isssssss', $idIe, $dni, $nombres, $cargo, $condicion, $jornada, $nivel, $observaciones);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    asistencia_auditar($conn, 'asistencia_personal', $id, 'CREAR', null, json_encode($_POST, JSON_UNESCAPED_UNICODE), $idUsuario);
}

header('Location: ' . asistencia_url('personal.php', $mes, $anio) . '&ok=1');
exit();
?>
