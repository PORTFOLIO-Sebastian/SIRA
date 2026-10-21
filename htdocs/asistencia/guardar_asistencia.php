<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$calendario = asistencia_calendario($mes, $anio);
$laborables = [];
foreach ($calendario as $dia) {
    if ($dia['laborable']) {
        $laborables[(int)$dia['dia']] = $dia['fecha'];
    }
}
$asistencia = $_POST['asistencia'] ?? [];
$observaciones = $_POST['observaciones'] ?? [];
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

// Guardar observaciones del personal
if (!empty($observaciones)) {
    $stmtObs = $conn->prepare("UPDATE asistencia_personal SET observaciones = ? WHERE id = ? AND id_ie = ?");
    foreach ($observaciones as $idPersonal => $obsVal) {
        $idPersonal = (int)$idPersonal;
        $obsVal = trim((string)$obsVal);
        if ($obsVal === '') {
            $obsVal = null;
        }
        $stmtObs->bind_param('sii', $obsVal, $idPersonal, $idIe);
        $stmtObs->execute();
    }
    $stmtObs->close();
}

$stmt = $conn->prepare("INSERT INTO asistencia_detalle (id_asistencia_mensual, id_personal, fecha, dia_mes, codigo_asistencia, registrado_por, ip_registro) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE codigo_asistencia = VALUES(codigo_asistencia), registrado_por = VALUES(registrado_por), ip_registro = VALUES(ip_registro), updated_at = CURRENT_TIMESTAMP");
$idMensual = (int)$mensual['id'];
$stmtDelete = $conn->prepare("DELETE FROM asistencia_detalle WHERE id_asistencia_mensual = ? AND id_personal = ? AND dia_mes = ?");

foreach ($asistencia as $idPersonal => $dias) {
    $idPersonal = (int)$idPersonal;
    foreach ($dias as $diaMes => $codigo) {
        $diaMes = (int)$diaMes;
        $codigo = strtoupper(trim((string)$codigo));
        if (!isset($laborables[$diaMes])) {
            continue;
        }
        if ($codigo === '') {
            $stmtDelete->bind_param('iii', $idMensual, $idPersonal, $diaMes);
            $stmtDelete->execute();
            continue;
        }
        if (!in_array($codigo, ASISTENCIA_CODIGOS, true)) {
            die('Codigo de asistencia invalido.');
        }
        $fecha = $laborables[$diaMes];
        $stmt->bind_param('iisisss', $idMensual, $idPersonal, $fecha, $diaMes, $codigo, $idUsuario, $ip);
        $stmt->execute();
    }
}
$stmt->close();
$stmtDelete->close();

asistencia_auditar($conn, 'asistencia_detalle', (int)$mensual['id'], 'GUARDAR_GRILLA', null, 'Periodo ' . $mes . '/' . $anio, $idUsuario);
header('Location: ' . asistencia_url('registro.php', $mes, $anio) . '&ok=1');
exit();
?>
