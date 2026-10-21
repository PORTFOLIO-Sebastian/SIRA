<?php
require_once __DIR__ . '/helpers.php';
asistencia_require_login();

$idIe = (int)$_SESSION['id_ie'];
$idUsuario = (int)$_SESSION['id_usuario'];
[$mes, $anio] = asistencia_periodo();
$mensual = asistencia_get_or_create_mensual($conn, $idIe, $idUsuario, $mes, $anio);
$personal = asistencia_personal_activo($conn, $idIe);
$calendario = asistencia_calendario($mes, $anio);
[$completo, $mensaje] = asistencia_validar_completo($conn, $mensual, $personal, $calendario);

if (!$completo) {
    header('Location: ' . asistencia_url('reportes.php', $mes, $anio) . '&error=' . urlencode($mensaje));
    exit();
}

$stmtCheck = $conn->prepare("SELECT id, estado FROM envios WHERE id_ie = ? AND anio = ? AND mes = ? AND tipo_documento = 'Mensual' ORDER BY id DESC LIMIT 1");
$stmtCheck->bind_param('iii', $idIe, $anio, $mes);
$stmtCheck->execute();
$ultimo = $stmtCheck->get_result()->fetch_assoc();
$stmtCheck->close();
if ($ultimo && in_array($ultimo['estado'], ['Pendiente', 'Aprobado', 'Conforme'], true)) {
    header('Location: ' . asistencia_url('reportes.php', $mes, $anio) . '&error=' . urlencode('Este mes ya tiene un envio pendiente o aceptado.'));
    exit();
}

try {
    $rutaExcel = asistencia_crear_excel($conn, $mensual, $personal, $calendario);
    $rutaPdf = asistencia_crear_pdf($conn, $mensual, $personal, $calendario);
} catch (Throwable $e) {
    header('Location: ' . asistencia_url('reportes.php', $mes, $anio) . '&error=' . urlencode($e->getMessage()));
    exit();
}

$codigoConstancia = 'CONST-' . date('Y') . '-' . rand(1000, 9999) . '-' . $mensual['codigo_local'];
$estado = 'Pendiente';
$tipo = 'Mensual';
$stmt = $conn->prepare("INSERT INTO envios (id_ie, id_usuario_envio, tipo_documento, mes, anio, ruta_archivo, ruta_excel, fecha_envio, codigo_constancia, estado) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?)");
$stmt->bind_param('iisisssss', $idIe, $idUsuario, $tipo, $mes, $anio, $rutaPdf, $rutaExcel, $codigoConstancia, $estado);
$stmt->execute();
$idEnvio = $stmt->insert_id;
$stmt->close();

$estadoMensual = 'enviado';
$stmt = $conn->prepare("UPDATE asistencia_mensual SET estado = ?, id_envio = ? WHERE id = ?");
$stmt->bind_param('sii', $estadoMensual, $idEnvio, $mensual['id']);
$stmt->execute();
$stmt->close();

asistencia_auditar($conn, 'envios', $idEnvio, 'GENERAR_ENVIO_MENSUAL', null, json_encode(['pdf' => $rutaPdf, 'excel' => $rutaExcel], JSON_UNESCAPED_UNICODE), $idUsuario);
header('Location: ../ver_constancia.php?id=' . $idEnvio);
exit();
?>
