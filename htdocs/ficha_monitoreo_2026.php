<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'conexion.php';
require_once 'components/ui.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit();
}


$rol = trim(strtolower($_SESSION['rol'] ?? ''));
$roles_permitidos = ['director', 'sub-director', 'subdirector'];
if (!in_array($rol, $roles_permitidos, true)) {
    if ($rol === 'especialista') { header('Location: ver_envios.php'); exit(); }
    if ($rol === 'administrador') { header('Location: administrador.php'); exit(); }
    if ($rol === 'docente') { header('Location: dashboard.php'); exit(); }
    header('Location: dashboard.php'); exit();
}

$id_usuario = (int)$_SESSION['id_usuario'];
$mensaje = '';
$error = '';
$errores_validacion = [];
$items_con_error = [];
$mensajes_error_item = [];

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function fm_table_has_column_local(mysqli $conn, string $table, string $column): bool {
    static $cache = [];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $escaped_column = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$escaped_column'");
    if (!$result) {
        return $cache[$key] = false;
    }
    $exists = $result->num_rows > 0;
    $result->free();
    return $cache[$key] = $exists;
}

function fm_post_coord(string $name): ?float {
    $raw = trim((string)($_POST[$name] ?? ''));
    return ($raw !== '' && is_numeric($raw)) ? (float)$raw : null;
}

function fm_actualizar_coordenadas_registro(mysqli $conn, int $idRegistro, ?float $latitud, ?float $longitud, ?float $precision): void {
    if ($idRegistro <= 0 || $latitud === null || $longitud === null) {
        return;
    }
    if (!fm_table_has_column_local($conn, 'fm_registros', 'latitud') || !fm_table_has_column_local($conn, 'fm_registros', 'longitud')) {
        return;
    }
    if ($precision !== null && fm_table_has_column_local($conn, 'fm_registros', 'precision_metros')) {
        $stmt = $conn->prepare("UPDATE fm_registros SET latitud = ?, longitud = ?, precision_metros = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('dddi', $latitud, $longitud, $precision, $idRegistro);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $stmt = $conn->prepare("UPDATE fm_registros SET latitud = ?, longitud = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('ddi', $latitud, $longitud, $idRegistro);
        $stmt->execute();
        $stmt->close();
    }
}

function generarCodigoFicha($nombreFicha) {
    $txt = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$nombreFicha);
    $txt = strtoupper($txt ?: (string)$nombreFicha);
    $palabras = preg_split('/[^A-Z0-9]+/', $txt, -1, PREG_SPLIT_NO_EMPTY);
    $ignorar = ['DE','DEL','LA','LAS','LOS','EL','Y','A','EN','PARA','POR','CON'];
    $siglas = '';
    foreach ($palabras as $p) {
        if (in_array($p, $ignorar, true)) { continue; }
        $siglas .= substr($p, 0, 1);
        if (strlen($siglas) >= 6) { break; }
    }
    $siglas = $siglas ?: 'FM';
    return $siglas . '-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function auditar($conn, $tabla, $idAfectado, $accion, $descripcion, $idUsuario) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt = $conn->prepare("INSERT INTO fm_auditoria (tabla_afectada, id_registro_afectado, accion, descripcion, id_usuario, ip_usuario, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    if ($stmt) {
        $stmt->bind_param('sississ', $tabla, $idAfectado, $accion, $descripcion, $idUsuario, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }
}

function fechaLimiteEfectiva($conn, $idTipoFicha, $idInstitucion, $fechaBase) {
    $fecha = $fechaBase;
    $stmt = $conn->prepare("SELECT nueva_fecha_limite FROM fm_prorrogas WHERE id_tipo_ficha = ? AND estado = 'ACTIVA' AND (id_institucion IS NULL OR id_institucion = ?) ORDER BY id_institucion DESC, nueva_fecha_limite DESC LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $idTipoFicha, $idInstitucion);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) { $fecha = $row['nueva_fecha_limite']; }
        $stmt->close();
    }
    return $fecha;
}

function obtenerRegistroActivoFicha($conn, $idTipoFicha, $idInstitucion) {
    $stmt = $conn->prepare("SELECT * FROM fm_registros WHERE id_tipo_ficha = ? AND id_institucion = ? AND activo = 1 ORDER BY version_envio DESC, id DESC LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('ii', $idTipoFicha, $idInstitucion);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function tieneConsentimientoAceptado($conn, $idTipoFicha, $idUsuario, $idInstitucion) {
    $stmt = $conn->prepare("SELECT aceptado FROM fm_consentimientos WHERE id_tipo_ficha = ? AND id_usuario = ? AND id_institucion = ? ORDER BY fecha_registro DESC LIMIT 1");
    if (!$stmt) { return false; }
    $stmt->bind_param('iii', $idTipoFicha, $idUsuario, $idInstitucion);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && (int)$row['aceptado'] === 1;
}

function motivoFicha($f) {
    $motivo = trim((string)($f['observacion_general'] ?? ''));
    if ($motivo === '') { $motivo = trim((string)($f['motivo_historial'] ?? '')); }
    return $motivo;
}

function calcularPuntaje($items, $post) {
    $total = 0.0;
    $maximo = 0.0;
    foreach ($items as $it) {
        $id = (int)$it['id'];
        $noAplica = isset($post['no_aplica'][$id]) && $post['no_aplica'][$id] == '1';
        if ($noAplica) { continue; }
        if ($it['tipo_respuesta'] === 'ESCALA_1_4') {
            $valor = isset($post['respuesta'][$id]) ? (int)$post['respuesta'][$id] : 0;
            if ($valor >= 1 && $valor <= 4) {
                $total += $valor;
                $maximo += (float)$it['puntaje_maximo'];
            }
        }
    }
    $porcentaje = $maximo > 0 ? round(($total / $maximo) * 100, 2) : null;
    $escala = null;
    if ($porcentaje !== null) {
        if ($porcentaje < 50) $escala = 'INICIO';
        elseif ($porcentaje < 75) $escala = 'PROCESO';
        elseif ($porcentaje < 90) $escala = 'LOGRADO';
        else $escala = 'DESTACADO';
    }
    return [$total, $maximo, $porcentaje, $escala];
}

function respuestaValor($resp, $tipo) {
    if (!$resp) return '';
    if ((int)$resp['no_aplica'] === 1) return '';
    switch ($tipo) {
        case 'ESCALA_1_4': return $resp['respuesta_escala'] === null ? '' : (string)(int)$resp['respuesta_escala'];
        case 'NUMERO': return $resp['respuesta_numero'];
        case 'TEXTO': return $resp['respuesta_texto'];
        case 'SI_NO': return $resp['respuesta_si_no'];
        case 'FECHA': return $resp['respuesta_fecha'];
        default: return '';
    }
}

function renderEstadoBadge($estado) {
    switch ($estado) {
        case 'ENVIADO':
            return '<span class="badge" style="background: var(--ugel-blue-light); color: var(--ugel-blue); border: 1px solid rgba(26, 86, 219, 0.25); font-size: 0.75rem;"><i class="bi bi-send-fill me-1"></i> Enviado</span>';
        case 'BORRADOR':
            return '<span class="badge" style="background: var(--ugel-yellow-light); color: var(--ugel-yellow); border: 1px solid rgba(184, 134, 11, 0.25); font-size: 0.75rem;"><i class="bi bi-pencil-fill me-1"></i> Borrador</span>';
        case 'OBSERVADO':
            return '<span class="badge" style="background: var(--ugel-orange-light); color: var(--ugel-orange); border: 1px solid rgba(230, 92, 0, 0.25); font-size: 0.75rem;"><i class="bi bi-exclamation-triangle-fill me-1"></i> Observado</span>';
        case 'RECHAZADO':
            return '<span class="badge" style="background: var(--ugel-red-light); color: var(--ugel-red); border: 1px solid rgba(198, 40, 40, 0.25); font-size: 0.75rem;"><i class="bi bi-x-circle-fill me-1"></i> Rechazado</span>';
        case 'CONFORME':
        case 'REVISADO':
            return '<span class="badge" style="background: var(--ugel-green-light); color: var(--ugel-green-dark); border: 1px solid var(--ugel-green-border); font-size: 0.75rem;"><i class="bi bi-check-circle-fill me-1"></i> Conforme</span>';
        case 'EN_REVISION':
            return '<span class="badge bg-light text-muted border" style="font-size: 0.75rem;"><i class="bi bi-hourglass-split me-1"></i> En revisión</span>';
        default:
            return '<span class="badge bg-light text-muted border" style="font-size: 0.75rem;">' . htmlspecialchars($estado) . '</span>';
    }
}

// Datos del usuario + IE
$stmt = $conn->prepare("SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni, i.id AS id_ie, i.nombre_ie, i.codigo_local, i.distrito
                       FROM usuarios u
                       LEFT JOIN asignaciones a ON u.id = a.id_usuario AND a.estado = 'activo'
                       LEFT JOIN instituciones i ON a.id_ie = i.id
                       WHERE u.id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user_data || empty($user_data['id_ie'])) {
    $error = 'No se encontró una institución educativa activa asignada a tu usuario.';
}
$id_ie = (int)($user_data['id_ie'] ?? 0);
$nombre_completo = trim(($user_data['nombres'] ?? '') . ' ' . ($user_data['ape_pat'] ?? '') . ' ' . ($user_data['ape_mat'] ?? ''));
$nombre_ie = $user_data['nombre_ie'] ?? 'Sin Asignar';
$codigo_local = $user_data['codigo_local'] ?? 'N/A';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'seleccionar_ficha') {
    $idSel = (int)($_POST['id_tipo_ficha'] ?? 0);
    if ($idSel > 0 && $id_ie > 0) {
        $regSel = obtenerRegistroActivoFicha($conn, $idSel, $id_ie);
        $estadoSel = $regSel['estado'] ?? null;

        if ($estadoSel === 'BORRADOR') {
            header('Location: ficha_monitoreo_2026.php?modo=formulario&id_tipo=' . $idSel);
            exit();
        }

        if (in_array($estadoSel, ['OBSERVADO','RECHAZADO'], true)) {
            header('Location: ficha_monitoreo_2026.php?modo=consentimiento&id_tipo=' . $idSel . '&subsanar=1');
            exit();
        }

        if (tieneConsentimientoAceptado($conn, $idSel, $id_usuario, $id_ie)) {
            header('Location: ficha_monitoreo_2026.php?modo=formulario&id_tipo=' . $idSel);
            exit();
        }

        header('Location: ficha_monitoreo_2026.php?modo=consentimiento&id_tipo=' . $idSel);
        exit();
    }
    $error = 'Selecciona una ficha para responder.';
}

$modo = $_GET['modo'] ?? 'inicio';
$id_tipo = (int)($_GET['id_tipo'] ?? 0);
$es_subsanacion = isset($_GET['subsanar']) && $_GET['subsanar'] == '1';
$tipo_ficha = null;
$fecha_limite_efectiva = null;
$consentimiento_ok = false;

if ($id_tipo > 0 && !$error) {
    $stmt = $conn->prepare("SELECT * FROM fm_tipos_ficha WHERE id = ? AND estado = 'ACTIVO' LIMIT 1");
    $stmt->bind_param('i', $id_tipo);
    $stmt->execute();
    $tipo_ficha = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$tipo_ficha) {
        $error = 'La ficha seleccionada no existe o no está activa.';
        $modo = 'inicio';
        $id_tipo = 0;
    } else {
        $fecha_limite_efectiva = fechaLimiteEfectiva($conn, $id_tipo, $id_ie, $tipo_ficha['fecha_limite']);
    }
}

// Consentimiento aceptado vigente para esta ficha.
// En subsanación se fuerza un nuevo consentimiento, salvo que ya exista borrador nuevo.
if ($tipo_ficha && $id_ie) {
    $registroActivoConsent = obtenerRegistroActivoFicha($conn, $id_tipo, $id_ie);
    $estadoActivoConsent = $registroActivoConsent['estado'] ?? null;
    $sessionKeySubsanacion = 'fm_consent_subsanar_' . $id_tipo . '_' . $id_ie;

    if ($estadoActivoConsent === 'BORRADOR') {
        $consentimiento_ok = true;
    } elseif (in_array($estadoActivoConsent, ['OBSERVADO','RECHAZADO'], true) && $es_subsanacion) {
        $consentimiento_ok = !empty($_SESSION[$sessionKeySubsanacion]);
    } else {
        $consentimiento_ok = tieneConsentimientoAceptado($conn, $id_tipo, $id_usuario, $id_ie);
    }
}

// Procesar consentimiento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'consentimiento' && $tipo_ficha && $id_ie) {
    $aceptado = ($_POST['decision'] ?? '') === 'aceptar' ? 1 : 0;
    $texto = $tipo_ficha['texto_consentimiento'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $stmt = $conn->prepare("INSERT INTO fm_consentimientos (id_tipo_ficha, id_usuario, id_institucion, aceptado, texto_consentimiento, ip_usuario, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiiisss', $id_tipo, $id_usuario, $id_ie, $aceptado, $texto, $ip, $ua);
    $stmt->execute();
    $id_consent = $stmt->insert_id;
    $stmt->close();
    auditar($conn, 'fm_consentimientos', $id_consent, $aceptado ? 'ACEPTAR_CONSENTIMIENTO' : 'RECHAZAR_CONSENTIMIENTO', $aceptado ? 'Usuario aceptó consentimiento.' : 'Usuario rechazó consentimiento.', $id_usuario);
    if (!$aceptado) {
        header('Location: ficha_monitoreo_2026.php?info=consentimiento_rechazado');
        exit();
    }
    if ($es_subsanacion) {
        $_SESSION['fm_consent_subsanar_' . $id_tipo . '_' . $id_ie] = true;
    }
    header('Location: ficha_monitoreo_2026.php?modo=formulario&id_tipo=' . $id_tipo);
    exit();
}

if ($modo === 'formulario' && $tipo_ficha && !$consentimiento_ok) {
    $regTmp = $id_ie ? obtenerRegistroActivoFicha($conn, $id_tipo, $id_ie) : null;
    $redirSub = ($regTmp && in_array($regTmp['estado'], ['OBSERVADO','RECHAZADO'], true)) ? '&subsanar=1' : '';
    header('Location: ficha_monitoreo_2026.php?modo=consentimiento&id_tipo=' . $id_tipo . $redirSub);
    exit();
}

// Cargar versión, secciones, items, rubricas y registro activo si se entrará al formulario
$version = null; $secciones = []; $items = []; $rubricasPorItem = []; $respuestas_actuales = []; $registro = null;
if ($tipo_ficha && $consentimiento_ok && $modo === 'formulario') {
    $stmt = $conn->prepare("SELECT * FROM fm_tipos_ficha_versiones WHERE id_tipo_ficha = ? AND estado = 'PUBLICADA' ORDER BY version DESC LIMIT 1");
    $stmt->bind_param('i', $id_tipo);
    $stmt->execute();
    $version = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($version) {
        $id_version = (int)$version['id'];
        $res = $conn->query("SELECT * FROM fm_secciones WHERE id_version_ficha = {$id_version} AND estado = 'ACTIVO' ORDER BY nivel, orden, id");
        while ($row = $res->fetch_assoc()) { $secciones[(int)$row['id']] = $row; }
        $res = $conn->query("SELECT * FROM fm_items WHERE id_version_ficha = {$id_version} AND estado = 'ACTIVO' ORDER BY COALESCE(id_seccion,0), orden, id");
        while ($row = $res->fetch_assoc()) { $items[] = $row; }
        if ($items) {
            $ids = implode(',', array_map('intval', array_column($items, 'id')));
            $res = $conn->query("SELECT valor, descripcion, id_item FROM fm_rubricas WHERE id_item IN ({$ids}) ORDER BY id_item, valor");
            while ($row = $res->fetch_assoc()) { $rubricasPorItem[(int)$row['id_item']][] = $row; }
        }
    }

    $stmt = $conn->prepare("SELECT * FROM fm_registros WHERE id_tipo_ficha = ? AND id_institucion = ? AND activo = 1 ORDER BY version_envio DESC, id DESC LIMIT 1");
    $stmt->bind_param('ii', $id_tipo, $id_ie);
    $stmt->execute();
    $registro = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($registro) {
        $id_reg = (int)$registro['id'];
        $res = $conn->query("SELECT * FROM fm_respuestas_director WHERE id_registro = {$id_reg}");
        while ($row = $res->fetch_assoc()) { $respuestas_actuales[(int)$row['id_item']] = $row; }
    }
}

// Procesar guardado / envío sin salir del formulario ante errores de validación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['accion'] ?? '', ['guardar_borrador','enviar_ficha'], true) && $tipo_ficha && $consentimiento_ok && $version) {
    $accion = $_POST['accion'];
    $nuevo_estado = $accion === 'enviar_ficha' ? 'ENVIADO' : 'BORRADOR';
    $es_ajax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
    $es_autoguardado = $es_ajax && $accion === 'guardar_borrador';
    $latitud_ficha = fm_post_coord('latitud');
    $longitud_ficha = fm_post_coord('longitud');
    $precision_ficha = fm_post_coord('precision_metros');

    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if ($is_https && $accion === 'enviar_ficha' && ($latitud_ficha === null || $longitud_ficha === null)) {
        $errores_validacion[] = 'Debe permitir la ubicacion del navegador antes de enviar la ficha.';
    }

    foreach ($items as $it) {
        $id_item = (int)$it['id'];
        $noAplica = isset($_POST['no_aplica'][$id_item]) && $_POST['no_aplica'][$id_item] == '1';
        if ($noAplica) { continue; }
        $valor = trim($_POST['respuesta'][$id_item] ?? '');
        if ($valor === '') {
            $items_con_error[$id_item] = true;
            $mensajes_error_item[$id_item] = 'Falta responder este item o marcar NO APLICA.';
            $errores_validacion[] = 'Falta responder el item ' . ($it['numero_item'] ?: $id_item) . '.';
            continue;
        }
        if ($it['tipo_respuesta'] === 'ESCALA_1_4' && !in_array($valor, ['1','2','3','4'], true)) {
            $items_con_error[$id_item] = true;
            $mensajes_error_item[$id_item] = 'Selecciona una escala valida del 1 al 4.';
            $errores_validacion[] = 'El item ' . ($it['numero_item'] ?: $id_item) . ' debe tener escala 1 a 4.';
        }
        if ($it['tipo_respuesta'] === 'NUMERO' && !is_numeric($valor)) {
            $items_con_error[$id_item] = true;
            $mensajes_error_item[$id_item] = 'Ingresa un valor numerico valido.';
            $errores_validacion[] = 'El item ' . ($it['numero_item'] ?: $id_item) . ' debe ser numerico.';
        }
    }

    if ($accion === 'enviar_ficha' && $errores_validacion) {
        $errores_generales = array_filter($errores_validacion, function($msg) {
            return stripos($msg, 'item') === false;
        });
        $error = $errores_generales ? implode('<br>', array_map('h', $errores_generales)) : '';
        if ($es_ajax) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => strip_tags($error), 'errors' => $errores_validacion]);
            exit();
        }
        foreach ($items as $it) {
            $id_item = (int)$it['id'];
            $respuestas_actuales[$id_item] = [
                'id_item' => $id_item,
                'no_aplica' => (isset($_POST['no_aplica'][$id_item]) && $_POST['no_aplica'][$id_item] == '1') ? 1 : 0,
                'respuesta_escala' => $it['tipo_respuesta'] === 'ESCALA_1_4' ? ($_POST['respuesta'][$id_item] ?? null) : null,
                'respuesta_numero' => $it['tipo_respuesta'] === 'NUMERO' ? ($_POST['respuesta'][$id_item] ?? null) : null,
                'respuesta_texto' => $it['tipo_respuesta'] === 'TEXTO' ? ($_POST['respuesta'][$id_item] ?? null) : null,
                'respuesta_si_no' => $it['tipo_respuesta'] === 'SI_NO' ? ($_POST['respuesta'][$id_item] ?? null) : null,
                'respuesta_fecha' => $it['tipo_respuesta'] === 'FECHA' ? ($_POST['respuesta'][$id_item] ?? null) : null,
                'observacion' => $_POST['observacion'][$id_item] ?? '',
            ];
        }
    } else {
        $conn->begin_transaction();
        try {
            $id_version = (int)$version['id'];
            $id_especialista = null;
            $stmt = $conn->prepare("SELECT id_usuario FROM asignaciones_especialistas WHERE id_institucion = ? AND estado = 'activo' LIMIT 1");
            $stmt->bind_param('i', $id_ie);
            $stmt->execute();
            if ($ae = $stmt->get_result()->fetch_assoc()) { $id_especialista = (int)$ae['id_usuario']; }
            $stmt->close();

            $editable = $registro && in_array($registro['estado'], ['NO_ENVIADO','BORRADOR'], true);
            $requiere_nueva_version = $registro && in_array($registro['estado'], ['OBSERVADO','RECHAZADO'], true);
            if ($registro && in_array($registro['estado'], ['ENVIADO','EN_REVISION','CONFORME'], true)) {
                throw new Exception('La ficha ya fue enviada o está en revisión. No puede modificarse desde este panel.');
            }

            [$puntaje_total, $puntaje_maximo, $porcentaje, $escala] = calcularPuntaje($items, $_POST);
            $fuera_plazo = 0;
            if ($accion === 'enviar_ficha' && $fecha_limite_efectiva && strtotime('now') > strtotime($fecha_limite_efectiva)) { $fuera_plazo = 1; }

            if ($requiere_nueva_version) {
                $stmt = $conn->prepare("UPDATE fm_registros SET activo = 0 WHERE id = ?");
                $rid = (int)$registro['id'];
                $stmt->bind_param('i', $rid);
                $stmt->execute(); $stmt->close();
                $registro = null; $editable = false;
            }

            if (!$editable) {
                $codigo = generarCodigoFicha($tipo_ficha['nombre']);
                $stmt = $conn->prepare("SELECT COALESCE(MAX(version_envio),0)+1 AS siguiente FROM fm_registros WHERE id_tipo_ficha = ? AND id_institucion = ?");
                $stmt->bind_param('ii', $id_tipo, $id_ie);
                $stmt->execute();
                $version_envio = (int)$stmt->get_result()->fetch_assoc()['siguiente'];
                $stmt->close();
                $fecha_borrador = date('Y-m-d H:i:s');
                $fecha_envio = $accion === 'enviar_ficha' ? date('Y-m-d H:i:s') : null;
                $stmt = $conn->prepare("INSERT INTO fm_registros (codigo_envio, id_tipo_ficha, id_version_ficha, id_usuario, id_institucion, id_especialista, version_envio, estado, fuera_plazo, fecha_borrador, fecha_envio, puntaje_total, puntaje_maximo, porcentaje, escala_valorativa, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param('siiiiiisissddds', $codigo, $id_tipo, $id_version, $id_usuario, $id_ie, $id_especialista, $version_envio, $nuevo_estado, $fuera_plazo, $fecha_borrador, $fecha_envio, $puntaje_total, $puntaje_maximo, $porcentaje, $escala);
                $stmt->execute();
                $id_registro = $stmt->insert_id;
                $stmt->close();
                $estado_anterior = null;
            } else {
                $id_registro = (int)$registro['id'];
                $estado_anterior = $registro['estado'];
                $fecha_borrador = $accion === 'guardar_borrador' ? date('Y-m-d H:i:s') : $registro['fecha_borrador'];
                $fecha_envio = $accion === 'enviar_ficha' ? date('Y-m-d H:i:s') : $registro['fecha_envio'];
                $stmt = $conn->prepare("UPDATE fm_registros SET estado = ?, fuera_plazo = ?, fecha_borrador = ?, fecha_envio = ?, puntaje_total = ?, puntaje_maximo = ?, porcentaje = ?, escala_valorativa = ? WHERE id = ?");
                $stmt->bind_param('sissdddsi', $nuevo_estado, $fuera_plazo, $fecha_borrador, $fecha_envio, $puntaje_total, $puntaje_maximo, $porcentaje, $escala, $id_registro);
                $stmt->execute(); $stmt->close();
            }

            fm_actualizar_coordenadas_registro($conn, $id_registro, $latitud_ficha, $longitud_ficha, $precision_ficha);

            foreach ($items as $it) {
                $id_item = (int)$it['id'];
                $noAplica = isset($_POST['no_aplica'][$id_item]) && $_POST['no_aplica'][$id_item] == '1' ? 1 : 0;
                $valor = trim($_POST['respuesta'][$id_item] ?? '');
                $obs = trim($_POST['observacion'][$id_item] ?? '');
                $esc = null; $num = null; $txt = null; $sino = null; $fecha = null;
                if (!$noAplica && $valor !== '') {
                    switch ($it['tipo_respuesta']) {
                        case 'ESCALA_1_4': $esc = (int)$valor; break;
                        case 'NUMERO': $num = (float)$valor; break;
                        case 'TEXTO': $txt = $valor; break;
                        case 'SI_NO': $sino = $valor === 'SI' ? 'SI' : 'NO'; break;
                        case 'FECHA': $fecha = $valor; break;
                    }
                }
                $stmt = $conn->prepare("INSERT INTO fm_respuestas_director (id_registro, id_item, no_aplica, respuesta_escala, respuesta_numero, respuesta_texto, respuesta_si_no, respuesta_fecha, observacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE no_aplica=VALUES(no_aplica), respuesta_escala=VALUES(respuesta_escala), respuesta_numero=VALUES(respuesta_numero), respuesta_texto=VALUES(respuesta_texto), respuesta_si_no=VALUES(respuesta_si_no), respuesta_fecha=VALUES(respuesta_fecha), observacion=VALUES(observacion)");
                $stmt->bind_param('iiiidssss', $id_registro, $id_item, $noAplica, $esc, $num, $txt, $sino, $fecha, $obs);
                $stmt->execute(); $stmt->close();
            }

            if (!$es_autoguardado || $estado_anterior !== $nuevo_estado) {
                $stmt = $conn->prepare("INSERT INTO fm_historial_estados (id_registro, estado_anterior, estado_nuevo, comentario, cambiado_por) VALUES (?, ?, ?, ?, ?)");
                $comentario = $accion === 'enviar_ficha' ? 'Ficha enviada por director/subdirector.' : 'Ficha guardada como borrador.';
                $stmt->bind_param('isssi', $id_registro, $estado_anterior, $nuevo_estado, $comentario, $id_usuario);
                $stmt->execute(); $stmt->close();
                auditar($conn, 'fm_registros', $id_registro, $accion === 'enviar_ficha' ? 'ENVIAR' : 'GUARDAR_BORRADOR', $comentario, $id_usuario);
            }

            if ($accion === 'enviar_ficha') {
                // Genera y registra automaticamente el PDF formal de la ficha llenada
                // y la constancia de envio con el codigo de la ficha.
                require_once __DIR__ . '/fm_pdf_helpers.php';
                fm_generar_documentos_envio_director($conn, $id_registro, $id_usuario);
                auditar($conn, 'fm_documentos', $id_registro, 'GENERAR_PDF', 'Se genero PDF formal de ficha llenada y constancia de envio.', $id_usuario);
            }

            $conn->commit();
            if ($es_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'message' => $accion === 'enviar_ficha' ? 'Ficha enviada correctamente.' : 'Borrador guardado correctamente.',
                    'id_registro' => $id_registro,
                    'estado' => $nuevo_estado,
                    'saved_at' => date('Y-m-d H:i:s'),
                ]);
                exit();
            }
            if ($accion === 'enviar_ficha') {
                header('Location: ficha_monitoreo_2026.php?ok=enviado');
            } else {
                header('Location: ficha_monitoreo_2026.php?ok=borrador');
            }
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            if ($es_ajax) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
                exit();
            }
            $error = h($e->getMessage());
        }
    }
}

if (isset($_GET['ok'])) {
    $mensaje = $_GET['ok'] === 'enviado'
        ? 'Ficha enviada correctamente. Ya puedes revisar el resumen en la tabla de fichas.'
        : 'Borrador guardado correctamente.';
}
if (isset($_GET['info']) && $_GET['info'] === 'consentimiento_rechazado') {
    $mensaje = 'No se aceptó el consentimiento. Puedes seleccionar la ficha nuevamente cuando corresponda.';
}

// Resumen de fichas disponibles para pantalla inicial
$fichas_disponibles = [];
if (!$error && $id_ie) {
    $sql = "SELECT tf.id, tf.nombre, tf.anio, tf.fecha_inicio, tf.fecha_limite,
                   r.id AS id_registro, r.estado, r.codigo_envio, r.fecha_envio, r.fuera_plazo,
                   r.observacion_general,
                   (
                       SELECT he.comentario
                       FROM fm_historial_estados he
                       WHERE he.id_registro = r.id
                         AND he.estado_nuevo IN ('OBSERVADO','RECHAZADO')
                       ORDER BY he.fecha_cambio DESC, he.id DESC
                       LIMIT 1
                   ) AS motivo_historial,
                   docf.ruta_archivo AS ruta_ficha_pdf,
                   docc.ruta_archivo AS ruta_constancia_pdf
            FROM fm_tipos_ficha tf
            LEFT JOIN fm_registros r ON r.id_tipo_ficha = tf.id AND r.id_institucion = ? AND r.activo = 1
            LEFT JOIN fm_documentos docf ON docf.id_registro = r.id AND docf.tipo_documento = 'PDF_FICHA_DIRECTOR'
            LEFT JOIN fm_documentos docc ON docc.id_registro = r.id AND docc.tipo_documento = 'CONSTANCIA_ENVIO_DIRECTOR'
            WHERE tf.estado = 'ACTIVO'
            ORDER BY tf.anio DESC, tf.nombre ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id_ie);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        if (empty($row['observacion_general']) && !empty($row['motivo_historial'])) {
            $row['observacion_general'] = $row['motivo_historial'];
        }
        $fichas_disponibles[] = $row;
    }
    $stmt->close();
    usort($fichas_disponibles, function($a, $b) {
        $pa = in_array($a['estado'] ?? '', ['OBSERVADO','RECHAZADO'], true) ? 0 : 1;
        $pb = in_array($b['estado'] ?? '', ['OBSERVADO','RECHAZADO'], true) ? 0 : 1;
        if ($pa !== $pb) { return $pa <=> $pb; }
        return strcmp((string)$a['nombre'], (string)$b['nombre']);
    });
}

$estado_actual = $registro['estado'] ?? 'NO_ENVIADO';
$puede_editar = in_array($estado_actual, ['NO_ENVIADO','BORRADOR','OBSERVADO','RECHAZADO'], true);

function renderCampoRespuesta($it, $rid, $valor, $noAplica) {
    $disabled = $noAplica ? 'disabled' : '';
    if ($it['tipo_respuesta'] === 'ESCALA_1_4') {
        $opciones = [1 => 'INICIO', 2 => 'PROCESO', 3 => 'LOGRADO', 4 => 'DESTACADO'];
        echo '<div class="scale-grid">';
        foreach ($opciones as $num => $label) {
            $checked = ((string)$valor === (string)$num) ? 'checked' : '';
            echo '<input class="scale-input" type="radio" name="respuesta['.$rid.']" id="resp'.$rid.'_'.$num.'" value="'.$num.'" '.$checked.' '.$disabled.'>';
            echo '<label class="scale-option scale-'.$num.'" for="resp'.$rid.'_'.$num.'"><span class="scale-number">'.$num.'</span><span>'.$label.'</span></label>';
        }
        echo '</div>';
    } elseif ($it['tipo_respuesta'] === 'NUMERO') {
        echo '<input type="number" step="0.01" name="respuesta['.$rid.']" value="'.h($valor).'" class="form-control" '.$disabled.'>';
    } elseif ($it['tipo_respuesta'] === 'TEXTO') {
        echo '<textarea name="respuesta['.$rid.']" class="form-control" rows="2" '.$disabled.'>'.h($valor).'</textarea>';
    } elseif ($it['tipo_respuesta'] === 'SI_NO') {
        echo '<select name="respuesta['.$rid.']" class="form-select" '.$disabled.'><option value="">Seleccionar</option><option value="SI" '.($valor==='SI'?'selected':'').'>Sí</option><option value="NO" '.($valor==='NO'?'selected':'').'>No</option></select>';
    } else {
        echo '<input type="date" name="respuesta['.$rid.']" value="'.h($valor).'" class="form-control" '.$disabled.'>';
    }
}

function renderItem($it, $respuestas_actuales, $rubricasPorItem, $items_con_error = [], $mensajes_error_item = []) {
    $rid = (int)$it['id'];
    $resp = $respuestas_actuales[$rid] ?? null;
    $valor = respuestaValor($resp, $it['tipo_respuesta']);
    $noAplica = $resp && (int)$resp['no_aplica'] === 1;
    $rubricas = $rubricasPorItem[$rid] ?? [];
    $tieneError = isset($items_con_error[$rid]);
    $mensajeError = $mensajes_error_item[$rid] ?? 'Falta responder este item o marcar NO APLICA.';
    ?>
    <div class="item-box <?= $tieneError ? 'item-error' : '' ?>" id="item-<?= h($it['numero_item'] ?: $rid) ?>" data-item-id="<?= $rid ?>" data-item-numero="<?= h($it['numero_item'] ?: $rid) ?>" data-tipo-respuesta="<?= h($it['tipo_respuesta']) ?>">
        <div class="d-flex justify-content-between gap-2 align-items-start mb-2">
            <div>
                <div class="fw-bold text-dark mb-1"><?= h($it['numero_item']) ?>. <?= h($it['titulo'] ?: 'Ítem') ?></div>
                <div class="text-secondary"><?= h($it['pregunta']) ?></div>
            </div>
            <button type="button" class="btn btn-outline-success btn-sm rounded-pill flex-shrink-0" data-bs-toggle="modal" data-bs-target="#rubricaModal<?= $rid ?>">
                <i class="bi bi-journal-text"></i> Rúbrica
            </button>
        </div>
        <div class="row g-2 align-items-stretch">
            <div class="col-lg-7 d-flex flex-column justify-content-between">
                <div>
                    <?php renderCampoRespuesta($it, $rid, $valor, $noAplica); ?>
                    <div class="item-error-label" aria-live="polite"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= h($mensajeError) ?></div>
                </div>
                <?php if ((int)$it['permite_no_aplica'] === 1): ?>
                    <div class="form-check mt-2 mb-0">
                        <input class="form-check-input no-aplica" type="checkbox" value="1" name="no_aplica[<?= $rid ?>]" id="na<?= $rid ?>" <?= $noAplica?'checked':'' ?>>
                        <label class="form-check-label small text-muted" for="na<?= $rid ?>">Este ítem NO APLICA para mi I.E.</label>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-5">
                <textarea name="observacion[<?= $rid ?>]" class="form-control" rows="2" placeholder="Observación (opcional)..." style="font-size: 0.85rem;"><?= h($resp['observacion'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
    <div class="modal fade" id="rubricaModal<?= $rid ?>" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content rounded-4 overflow-hidden">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title fw-bold">Rúbrica del ítem <?= h($it['numero_item'] ?: $rid) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body">
                    <?php if ($rubricas): ?>
                        <div class="list-group list-group-flush">
                        <?php foreach ($rubricas as $rub): ?>
                            <div class="list-group-item px-0">
                                <strong><?= h($rub['valor'] ?: 'Criterio') ?>:</strong> <?= h($rub['descripcion']) ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-light border mb-0">Este ítem todavía no tiene rúbrica registrada.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php
}

function renderSeccion($seccion, $hijos, $itemsPorSeccion, $respuestas_actuales, $rubricasPorItem, $items_con_error = [], $mensajes_error_item = [], $nivel = 1) {
    $idSec = (int)$seccion['id'];
    $claseTitulo = $nivel === 1 ? 'section-title' : 'subsection-title';
    ?>
    <div class="<?= $claseTitulo ?>">
        <i class="bi <?= $nivel === 1 ? 'bi-folder2-open' : 'bi-diagram-2' ?> me-2"></i><?= h($seccion['titulo']) ?>
    </div>
    <?php if (!empty($seccion['descripcion'])): ?><div class="px-3 pt-3 text-muted small"><?= h($seccion['descripcion']) ?></div><?php endif; ?>
    <?php foreach ($itemsPorSeccion[$idSec] ?? [] as $it) { renderItem($it, $respuestas_actuales, $rubricasPorItem, $items_con_error, $mensajes_error_item); } ?>
    <?php foreach ($hijos[$idSec] ?? [] as $sub) { renderSeccion($sub, $hijos, $itemsPorSeccion, $respuestas_actuales, $rubricasPorItem, $items_con_error, $mensajes_error_item, $nivel + 1); } ?>
<?php } ?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha de Monitoreo 2026</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        /* Hide scrollbars globally */
        * {
            scrollbar-width: none !important; /* Firefox */
            -ms-overflow-style: none !important;  /* IE and Edge */
        }
        *::-webkit-scrollbar {
            display: none !important; /* Chrome, Safari, Opera */
        }

        /* General Body & Container layout */
        body {
            background-color: #f3f2f1; /* Fluent neutral background */
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, sans-serif;
            color: #323130; /* Fluent dark neutral text */
        }
        .ugel-shell {
            width: min(100% - 40px, 1320px);
        }
        .monitor-page-title {
            color: #201f1e;
            font-size: clamp(1.2rem, 1.8vw, 1.5rem);
            font-weight: 600;
        }
        .monitor-context-badge {
            min-height: 32px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #ffffff !important;
            border: 1px solid #edebe9;
            color: var(--ugel-green-dark) !important;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 4px !important;
        }

        /* Fluent Header Card */
        .monitor-header-card {
            background: #ffffff;
            border: 1px solid #edebe9 !important;
            border-radius: 6px !important;
            box-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,0.132), 0 0.3px 0.9px 0 rgba(0,0,0,0.108) !important;
        }
        .monitor-header-icon {
            width: 40px;
            height: 40px;
            border-radius: 4px;
            background: var(--ugel-green-light);
            color: var(--ugel-green-dark);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .bg-light-green {
            background-color: var(--ugel-green-light) !important;
        }

        /* Fluent Section Cards */
        .section-card {
            background: #ffffff;
            border: 1px solid #edebe9 !important;
            border-radius: 6px !important;
            box-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,0.132), 0 0.3px 0.9px 0 rgba(0,0,0,0.108) !important;
            overflow: hidden;
            padding: 0 !important;
            margin-bottom: 24px;
        }
        .section-title {
            background: #ffffff !important;
            color: var(--ugel-green-dark) !important;
            padding: 14px 20px;
            font-weight: 600;
            font-size: 0.95rem;
            border-bottom: 1px solid #edebe9;
            letter-spacing: 0.02em;
            display: flex;
            align-items: center;
        }
        .subsection-title {
            background-color: #faf9f8 !important;
            color: #323130 !important;
            padding: 10px 20px;
            font-weight: 600;
            font-size: 0.88rem;
            border-left: 4px solid var(--ugel-green-mid);
            border-bottom: 1px solid #edebe9;
        }

        /* Item Box (Questions) - Clean and Compact */
        .item-box {
            border-bottom: 1px solid #edebe9;
            padding: 16px 20px;
            background: #ffffff;
            transition: background-color 0.15s ease;
        }
        .item-box:hover {
            background-color: #fcfcfc;
        }
        .item-box:last-child {
            border-bottom: 0;
        }
        .item-box .fw-bold.text-dark {
            font-size: 0.9rem;
            color: #323130 !important;
        }
        .item-box .text-secondary {
            color: #605e5c !important;
            font-size: 0.82rem !important;
            line-height: 1.4;
        }
        .item-box .btn-outline-success {
            font-size: 0.72rem !important;
            padding: 3px 10px !important;
            border-radius: 4px !important;
            border-color: var(--ugel-green-border);
            color: var(--ugel-green-dark);
        }
        .item-box .btn-outline-success:hover {
            background-color: var(--ugel-green-light);
            border-color: var(--ugel-green-mid);
            color: var(--ugel-green-dark);
        }

        /* Fluent Form Controls */
        .form-control, .form-select {
            border: 1px solid #8a8886 !important;
            border-radius: 4px !important;
            font-size: 0.85rem;
            color: #323130;
            padding: 6px 10px;
            background-color: #ffffff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--ugel-green-mid) !important;
            box-shadow: 0 0 0 1px var(--ugel-green-mid) !important;
            outline: none;
        }
        .form-control::placeholder {
            color: #a19f9d;
        }

        /* Scale Grid & Buttons */
        .scale-grid {
            display: grid;
            grid-template-columns: repeat(4, 120px);
            gap: 6px;
            margin-bottom: 8px;
        }
        .scale-input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        .scale-option {
            border: 1px solid #d2d0ce;
            border-radius: 6px;
            padding: 6px 10px;
            font-weight: 600;
            font-size: 0.8rem;
            cursor: pointer;
            background: #ffffff;
            color: #323130;
            transition: all 0.15s ease;
            min-height: 38px;
            display: flex;
            flex-direction: row;
            justify-content: center;
            align-items: center;
            gap: 8px;
        }
        .scale-option:hover {
            border-color: #8a8886;
            background-color: #f3f2f1;
        }
        .scale-number {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: #f3f2f1;
            color: #605e5c;
            font-weight: 700;
            font-size: 0.75rem;
            margin-bottom: 0;
            flex-shrink: 0;
        }
        .scale-input:disabled + .scale-option {
            opacity: .45;
            cursor: not-allowed;
        }
        .scale-input:checked + .scale-1 {
            background: #d13438 !important;
            border-color: #d13438 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(209, 52, 56, 0.2);
        }
        .scale-input:checked + .scale-2 {
            background: #f7630c !important;
            border-color: #f7630c !important;
            color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(247, 99, 12, 0.2);
        }
        .scale-input:checked + .scale-3 {
            background: #107c41 !important;
            border-color: #107c41 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(16, 124, 65, 0.2);
        }
        .scale-input:checked + .scale-4 {
            background: #0078d4 !important;
            border-color: #0078d4 !important;
            color: #ffffff !important;
            box-shadow: 0 2px 4px rgba(0, 120, 212, 0.2);
        }
        .scale-input:checked + .scale-option .scale-number {
            background: rgba(255, 255, 255, 0.25);
            color: inherit;
        }

        /* Error state */
        .item-box.item-error {
            background: var(--ugel-red-light);
            box-shadow: inset 4px 0 0 var(--ugel-red);
        }
        .item-box.item-error .scale-option,
        .item-box.item-error .form-control,
        .item-box.item-error .form-select {
            border-color: var(--ugel-red) !important;
        }
        .item-box.item-error .fw-bold.text-dark {
            color: var(--ugel-red) !important;
        }
        .item-error-label {
            display: none;
            color: var(--ugel-red);
            font-weight: 600;
            margin-top: 6px;
            font-size: 0.78rem;
        }
        .item-box.item-error .item-error-label {
            display: block;
        }

        /* Floating Validation Alert */
        .floating-validation {
            position: sticky;
            top: 76px;
            z-index: 1030;
            display: none;
            border-left: 4px solid var(--ugel-red);
            font-size: 0.85rem;
            padding: 10px 14px;
        }
        .floating-validation.show {
            display: block;
        }

        /* Sticky Action Footer - Always Floating at bottom */
        .form-action-sticky {
            position: sticky;
            bottom: 0;
            z-index: 1025;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid #edebe9 !important;
            border-radius: 8px !important;
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.05) !important;
            padding: 14px 20px;
            margin-top: 24px;
            margin-bottom: 10px;
        }
        .action-title {
            color: var(--ugel-green-dark);
            font-size: 0.88rem;
            font-weight: 700;
        }
        .action-copy {
            color: #605e5c;
            font-size: 0.78rem;
        }
        .autosave-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            color: var(--ugel-text-muted);
            font-size: 0.76rem;
            font-weight: 600;
            min-height: 18px;
        }
        .autosave-status[data-state="saving"] { color: var(--ugel-blue); }
        .autosave-status[data-state="saved"] { color: var(--ugel-green-dark); }
        .autosave-status[data-state="error"] { color: var(--ugel-red); }

        .monitor-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        .monitor-actions .btn {
            min-height: 36px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border-radius: 4px !important;
            padding: 6px 14px !important;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
            transition: all 0.15s ease;
        }

        /* Motivo Subsanacion - Fluent MessageBar style */
        .motivo-subsanacion {
            display: none;
            border-left: 3px solid #ffb900;
            background-color: #fff4ce;
            color: #323130;
            padding: 12px;
            margin-bottom: 16px;
            border-radius: 4px;
            font-size: 0.82rem;
        }
        .motivo-subsanacion.rechazado {
            border-left-color: #d13438;
            background-color: #fde7e9;
        }
        select option.opt-observado { color: var(--ugel-yellow); font-weight: 700; }
        select option.opt-rechazado { color: var(--ugel-red); font-weight: 700; }

        /* Tables & Historial - Fluent Clean style */
        .table-custom-header th {
            background-color: #faf9f8 !important;
            color: #323130 !important;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.78rem;
            letter-spacing: 0.05em;
            border-bottom: 2px solid #edebe9;
            padding: 12px 14px;
            white-space: nowrap;
        }
        .table th, .table td {
            padding: 12px 14px;
            font-size: 0.85rem;
            border-bottom: 1px solid #edebe9;
        }
        .table {
            border-color: #edebe9;
        }
        .table-responsive {
            border: 1px solid #edebe9;
            border-radius: 6px;
            background: var(--ugel-surface);
        }
        .btn-outline-danger {
            background: #ffffff !important;
            border: 1px solid #d13438 !important;
            color: #d13438 !important;
            border-radius: 4px !important;
        }
        .btn-outline-danger:hover {
            background: #fde7e9 !important;
            color: #d13438 !important;
        }
        .btn-outline-primary {
            background: #ffffff !important;
            border: 1px solid #0078d4 !important;
            color: #0078d4 !important;
            border-radius: 4px !important;
        }
        .btn-outline-primary:hover {
            background: #eff6fc !important;
            color: #0078d4 !important;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .monitor-actions {
                justify-content: stretch;
                width: 100%;
            }
            .monitor-actions .btn {
                flex: 1 1 auto;
                font-size: 0.75rem;
                padding: 6px 10px !important;
            }
        }
        @media (max-width: 768px) {
            .ugel-shell {
                width: min(100% - 20px, 1320px);
            }
            .item-box {
                padding: 12px 14px;
            }
            .scale-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 6px;
            }
            .scale-option {
                min-height: 46px;
                font-size: 0.74rem;
            }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar mb-4 sticky-top shadow-sm">
    <div class="container-fluid px-3 px-md-4">
        <!-- Hamburger (mobile) -->
        <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <i class="bi bi-clipboard2-check ugel-brand-icon fs-2 me-2 text-success"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">FICHA DE MONITOREO 2026</span>
                <small class="ugel-brand-subtitle">Módulo interno</small>
            </div>
        </a>
        
        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase"><?php echo htmlspecialchars($user_data['rol'] ?? 'Director'); ?></small>
                        <strong><?php echo htmlspecialchars($user_data['nombres']); ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2 shadow">
                    <li class="text-center mb-4">
                        <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                        <h6 class="fw-bold mb-0 text-success"><?php echo htmlspecialchars($nombre_completo); ?></h6>
                        <small class="text-muted"><?php echo htmlspecialchars($user_data['correo']); ?></small>
                    </li>
                    <li class="px-2">
                        <div class="mb-3">
                            <small class="d-block text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">DNI</small>
                            <strong class="d-block"><?php echo htmlspecialchars($user_data['dni']); ?></strong>
                        </div>
                        <div>
                            <small class="d-block text-muted text-uppercase fw-bold" style="font-size: 0.7rem;">IE</small>
                            <strong class="d-block text-success"><?php echo htmlspecialchars($nombre_ie); ?></strong>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider mt-4 mb-3"></li>
                    <li>
                        <a class="btn btn-outline-primary ugel-menu-action w-100 fw-bold mb-2" href="panel_usuario.php">
                            <i class="bi bi-person"></i>
                            <span>Perfil</span>
                        </a>
                    </li>
                    <li>
                        <a class="btn btn-outline-danger ugel-menu-action w-100 fw-bold" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Cerrar Sesi&oacute;n</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- App Shell con Sidebar -->
<div class="ugel-app-shell">
    <?php
    $sidebar_role   = 'director';
    $sidebar_active = 'fichas';
    $sidebar_label  = $nombre_ie;
    include 'components/sidebar.php';
    ?>

    <main class="ugel-main-content">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <h4 class="fw-bold ugel-section-title monitor-page-title mb-0">
            <i class="bi bi-clipboard2-check-fill me-2 text-success"></i>Fichas de Monitoreo 2026
        </h4>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge ugel-context-badge monitor-context-badge"><i class="bi bi-building"></i>IE: <?php echo htmlspecialchars($nombre_ie); ?></span>
            <a href="dashboard.php" class="btn btn-outline-primary ugel-menu-action fw-bold px-4 py-2">
                <i class="bi bi-house-door"></i>
                <span>Volver al Inicio</span>
            </a>
        </div>
    </div>

    <?php if ($mensaje): ?><div class="alert alert-success shadow-sm mb-4"><i class="bi bi-check-circle-fill me-2"></i><?= h($mensaje) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger shadow-sm mb-4"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= $error ?></div><?php endif; ?>

    <?php if (!$error && (!$tipo_ficha || $modo === 'inicio')): ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-header-green p-3 d-flex align-items-center">
                        <i class="bi bi-ui-checks-grid fs-5 me-2"></i>
                        <h5 class="mb-0 fw-bold">Responder Ficha</h5>
                    </div>
                    <div class="card-body p-4">
                        <form method="POST">
                            <input type="hidden" name="accion" value="seleccionar_ficha">
                            <div class="mb-3">
                                <label class="form-label fw-bold text-success">Año de gestión</label>
                                <input type="text" class="form-control bg-light" value="<?= h(date('Y')) ?>" readonly>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold text-success">Ficha a responder</label>
                                <select name="id_tipo_ficha" id="selectorFicha" class="form-select" required>
                                    <option value="">Seleccionar ficha</option>
                                    <?php foreach ($fichas_disponibles as $f):
                                        $estadoOpt = $f['estado'] ?: 'NO_ENVIADO';
                                        $motivoOpt = motivoFicha($f);
                                        $labelExtra = '';
                                        $claseOpt = '';
                                        if ($estadoOpt === 'OBSERVADO') { $labelExtra = ' ⚠ (Subsanar)'; $claseOpt = 'opt-observado'; }
                                        if ($estadoOpt === 'RECHAZADO') { $labelExtra = ' ⛔ (Subsanar)'; $claseOpt = 'opt-rechazado'; }
                                    ?>
                                        <option class="<?= h($claseOpt) ?>" value="<?= (int)$f['id'] ?>" data-estado="<?= h($estadoOpt) ?>" data-motivo="<?= h($motivoOpt) ?>"><?= h($f['nombre']) ?><?= h($labelExtra) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div id="bloqueMotivoSubsanacion" class="motivo-subsanacion rounded-3 p-3 mb-4">
                                <div class="fw-bold mb-1" id="tituloMotivoSubsanacion">Motivo de subsanación</div>
                                <div class="small fst-italic mb-2" id="textoMotivoSubsanacion"></div>
                                <div class="small text-muted"><i class="bi bi-info-circle me-1"></i>Completa la ficha corregida desde este formulario.</div>
                            </div>
                            <button class="btn btn-green w-100 fw-bold py-2 shadow-sm">
                                <i class="bi bi-pencil-square me-1"></i> Responder Ficha
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-header-green p-3 d-flex align-items-center">
                        <i class="bi bi-table fs-5 me-2"></i>
                        <h5 class="mb-0 fw-bold">Historial de Fichas de Monitoreo</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-custom-header">
                                    <tr>
                                        <th>Tipo de Ficha</th>
                                        <th style="width: 150px;">Estado</th>
                                        <th style="width: 130px;" class="text-center">Archivo</th>
                                        <th style="width: 130px;" class="text-center">Constancia</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (count($fichas_disponibles) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            <i class="bi bi-info-circle fs-3 d-block mb-2 text-success opacity-50"></i>
                                            No hay fichas activas disponibles.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($fichas_disponibles as $f): $estado = $f['estado'] ?: 'NO_ENVIADO'; ?>
                                    <tr>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="d-flex align-items-center justify-content-center rounded bg-light" style="width: 36px; height: 36px; flex-shrink: 0;">
                                                    <i class="bi bi-file-earmark-ruled text-success fs-5"></i>
                                                </div>
                                                <div>
                                                    <strong class="text-dark d-block" style="font-size: 0.88rem;"><?= h($f['nombre']) ?></strong>
                                                    <small class="text-muted">Gestión <?= h($f['anio']) ?></small>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?= renderEstadoBadge($estado) ?></td>
                                        <td class="text-center">
                                            <?php if (!empty($f['ruta_ficha_pdf'])): ?>
                                                <a class="btn btn-outline-danger btn-sm fw-bold px-3 py-1 shadow-sm" href="<?= h($f['ruta_ficha_pdf']) ?>" target="_blank">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="bi bi-clock me-1"></i> Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($f['ruta_constancia_pdf'])): ?>
                                                <a class="btn btn-outline-primary btn-sm fw-bold px-3 py-1 shadow-sm" href="<?= h($f['ruta_constancia_pdf']) ?>" target="_blank">
                                                    <i class="bi bi-file-earmark-pdf me-1"></i> PDF
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted small"><i class="bi bi-clock me-1"></i> Pendiente</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif (!$error && $tipo_ficha && $modo === 'consentimiento'): ?>
        <div class="card mb-4">
            <div class="card-header-green p-4 d-flex align-items-center">
                <i class="bi bi-shield-check-fill fs-4 me-2"></i>
                <h4 class="mb-0 fw-bold">Consentimiento Informado</h4>
            </div>
            <div class="card-body p-4 p-md-5">
                <h5 class="fw-bold text-success mb-3"><?= h($tipo_ficha['nombre']) ?></h5>
                <?php if ($es_subsanacion): ?>
                    <div class="alert alert-warning border-0 d-flex align-items-center gap-2 rounded-3 mb-4">
                        <i class="bi bi-arrow-repeat fs-4 text-warning"></i>
                        <div>
                            <strong>Modo Subsanación:</strong> Esta ficha será subsanada por haber sido observada o rechazada. Se registrará un nuevo consentimiento y, al enviar, se generará una nueva versión.
                        </div>
                    </div>
                <?php endif; ?>
                <div class="bg-light border rounded-3 p-4 mb-4 fs-6 text-dark" style="max-height: 300px; overflow-y: auto; line-height: 1.6;">
                    <?= nl2br(h($tipo_ficha['texto_consentimiento'])) ?>
                </div>
                <p class="text-muted mb-4">
                    <i class="bi bi-info-circle me-1"></i> Para responder esta ficha, debe aceptar los términos de tratamiento de datos de la I.E. Esta decisión quedará registrada para fines de auditoría (usuario, fecha, DNI, IP).
                </p>
                <form method="POST" class="d-flex flex-wrap gap-2">
                    <input type="hidden" name="accion" value="consentimiento">
                    <button name="decision" value="aceptar" class="btn btn-green fw-bold px-4 py-2 shadow-sm">
                        <i class="bi bi-check2-circle me-1"></i> Acepto y continuar
                    </button>
                    <button name="decision" value="rechazar" class="btn btn-outline-danger fw-bold px-4 py-2 shadow-sm">
                        <i class="bi bi-x-circle me-1"></i> No acepto
                    </button>
                    <a href="ficha_monitoreo_2026.php" class="btn btn-outline-secondary fw-bold px-4 py-2">
                        Volver
                    </a>
                </form>
            </div>
        </div>

    <?php elseif ((!$error || $errores_validacion) && $tipo_ficha && $consentimiento_ok && $modo === 'formulario'): ?>
        <div class="card monitor-header-card border-0 rounded-3 p-3 mb-4 shadow-sm">
            <div class="row align-items-center g-3">
                <div class="col-lg-7">
                    <div class="d-flex align-items-center gap-3">
                        <div class="monitor-header-icon bg-light-green text-success rounded-3 p-2">
                            <i class="bi bi-file-earmark-text-fill fs-3"></i>
                        </div>
                        <div>
                            <small class="text-uppercase text-muted fw-bold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Ficha Activa</small>
                            <h5 class="fw-bold text-success mb-1"><?= h($tipo_ficha['nombre']) ?></h5>
                            <div class="small text-secondary">
                                <span><i class="bi bi-calendar3 me-1"></i>Gestión: <strong><?= h($tipo_ficha['anio']) ?></strong></span>
                                <span class="mx-2">•</span>
                                <span><i class="bi bi-person me-1"></i>Usuario: <strong><?= h($nombre_completo) ?></strong></span>
                                <span class="mx-2">•</span>
                                <span><i class="bi bi-building me-1"></i>Código local: <strong><?= h($user_data['codigo_local'] ?? 'N/A') ?></strong></span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="d-flex justify-content-lg-end justify-content-between align-items-center gap-4">
                        <div class="text-lg-end">
                            <div class="small text-muted mb-1">Estado Actual</div>
                            <div><?= renderEstadoBadge($estado_actual) ?></div>
                        </div>
                        <div class="vr d-none d-lg-block" style="height: 40px; opacity: 0.15;"></div>
                        <div class="text-lg-end">
                            <div class="small text-muted mb-1">Fecha Límite</div>
                            <span class="badge bg-light text-dark border fw-semibold"><i class="bi bi-calendar-event text-success me-1"></i><?= h($fecha_limite_efectiva ?: 'No definida') ?></span>
                        </div>
                        <?php if ($registro): ?>
                            <div class="vr d-none d-lg-block" style="height: 40px; opacity: 0.15;"></div>
                            <div class="text-lg-end">
                                <div class="small text-muted">Cód: <strong><?= h($registro['codigo_envio']) ?></strong></div>
                                <div class="small text-muted">Ver: <strong><?= h($registro['version_envio']) ?></strong></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!$version): ?>
            <div class="alert alert-warning">No hay una versión publicada de esta ficha.</div>
        <?php else: ?>
            <?php if (!$puede_editar): ?>
                <div class="alert alert-info shadow-sm"><i class="bi bi-info-circle me-2"></i>Esta ficha ya fue enviada o está en revisión. Regresa al resumen para descargar documentos cuando estén disponibles.</div>
                <a href="ficha_monitoreo_2026.php" class="btn btn-success fw-bold"><i class="bi bi-arrow-left me-1"></i>Volver al resumen</a>
            <?php else: ?>
                <div id="alertaValidacionCliente" class="alert alert-danger floating-validation shadow-sm rounded-4 mb-3" role="alert"></div>
                <form method="POST" id="formFichaMonitoreo" novalidate>
                    <input type="hidden" name="latitud" id="latitudFicha" value="">
                    <input type="hidden" name="longitud" id="longitudFicha" value="">
                    <input type="hidden" name="precision_metros" id="precisionFicha" value="">
                    <?php
                    $itemsPorSeccion = [];
                    foreach ($items as $it) { $itemsPorSeccion[(int)($it['id_seccion'] ?? 0)][] = $it; }
                    $hijos = [];
                    foreach ($secciones as $s) { $hijos[(int)($s['id_seccion_padre'] ?? 0)][] = $s; }
                    $seccionesRaiz = $hijos[0] ?? [];
                    ?>

                    <?php if (!$items): ?>
                        <div class="alert alert-warning">Esta ficha no tiene ítems activos registrados.</div>
                    <?php endif; ?>

                    <?php foreach ($itemsPorSeccion[0] ?? [] as $it): ?>
                        <div class="section-card bg-white mb-4"><?php renderItem($it, $respuestas_actuales, $rubricasPorItem, $items_con_error, $mensajes_error_item); ?></div>
                    <?php endforeach; ?>

                    <?php foreach ($seccionesRaiz as $sec): ?>
                        <div class="section-card bg-white mb-4">
                            <?php renderSeccion($sec, $hijos, $itemsPorSeccion, $respuestas_actuales, $rubricasPorItem, $items_con_error, $mensajes_error_item, 1); ?>
                        </div>
                    <?php endforeach; ?>

                    <div class="bg-white border rounded-4 p-3 shadow-sm text-center text-muted small mb-3">
                        Fin de la ficha. Usa la barra de acciones inferior para guardar, enviar o volver al resumen.
                    </div>

                    <div class="form-action-sticky d-flex flex-column flex-md-row gap-3 justify-content-between align-items-md-center">
                        <div class="d-flex flex-row flex-md-column align-items-center align-items-md-start justify-content-between w-100 w-md-auto">
                            <div class="d-none d-md-block">
                                <div class="action-title"><i class="bi bi-pin-angle-fill me-1"></i>Acciones de la ficha</div>
                                <small class="action-copy d-block">Guarda tu avance o envía la ficha cuando esté completa.</small>
                            </div>
                            <div id="autosaveStatus" class="autosave-status" data-state="idle" aria-live="polite">
                                <i class="bi bi-cloud-check-fill text-success"></i> <span class="fw-semibold text-success">Autoguardado activo</span>
                            </div>
                        </div>
                        <div class="monitor-actions w-100 w-md-auto d-flex justify-content-end gap-2">
                            <a href="ficha_monitoreo_2026.php" class="btn btn-outline-secondary fw-bold px-3 py-2">
                                <i class="bi bi-arrow-left me-1"></i> Volver
                            </a>
                            <button type="submit" name="accion" value="guardar_borrador" class="btn btn-outline-success fw-bold px-3 py-2">
                                <i class="bi bi-save me-1"></i> Guardar Borrador
                            </button>
                            <button type="submit" name="accion" value="enviar_ficha" id="btnEnviarFicha" class="btn btn-green fw-bold px-3 py-2 shadow-sm">
                                <i class="bi bi-send-check me-1"></i> Enviar Ficha
                            </button>
                        </div>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    </main>
</div><!-- /.ugel-app-shell -->
<script>
const selectorFicha = document.getElementById('selectorFicha');
const bloqueMotivo = document.getElementById('bloqueMotivoSubsanacion');
const tituloMotivo = document.getElementById('tituloMotivoSubsanacion');
const textoMotivo = document.getElementById('textoMotivoSubsanacion');
if (selectorFicha && bloqueMotivo) {
    const actualizarMotivo = () => {
        const opt = selectorFicha.options[selectorFicha.selectedIndex];
        const estado = opt?.dataset?.estado || '';
        const motivo = opt?.dataset?.motivo || '';
        if ((estado === 'OBSERVADO' || estado === 'RECHAZADO') && motivo.trim() !== '') {
            bloqueMotivo.classList.toggle('rechazado', estado === 'RECHAZADO');
            tituloMotivo.textContent = estado === 'RECHAZADO' ? 'Anterior: Rechazado' : 'Anterior: Observado';
            textoMotivo.textContent = '“' + motivo + '”';
            bloqueMotivo.style.display = 'block';
        } else {
            bloqueMotivo.style.display = 'none';
        }
    };
    selectorFicha.addEventListener('change', actualizarMotivo);
    actualizarMotivo();
}

function toggleNoAplica(chk) {
    const box = chk.closest('.item-box');
    const controls = box ? box.querySelectorAll('select[name^="respuesta"], input[name^="respuesta"], textarea[name^="respuesta"]') : [];
    controls.forEach(c => {
        if (c.classList.contains('no-aplica')) return;
        c.disabled = chk.checked;
        if (chk.checked) {
            if (c.type === 'radio') c.checked = false;
            else c.value = '';
        }
    });
    if (chk.checked && box) box.classList.remove('item-error');
}

document.querySelectorAll('.no-aplica').forEach(chk => {
    chk.addEventListener('change', () => toggleNoAplica(chk));
    toggleNoAplica(chk);
});

function itemTieneRespuesta(box) {
    if (!box) return true;
    const noAplica = box.querySelector('.no-aplica');
    if (noAplica && noAplica.checked) return true;

    const tipo = box.dataset.tipoRespuesta || '';
    if (tipo === 'ESCALA_1_4') {
        return !!box.querySelector('input[type="radio"][name^="respuesta"]:checked');
    }

    const control = box.querySelector('select[name^="respuesta"], input[name^="respuesta"]:not([type="radio"]), textarea[name^="respuesta"]');
    return !!control && control.value.trim() !== '';
}

function actualizarEstadoItem(box, forzarMarcado = false) {
    if (!box) return true;
    const completo = itemTieneRespuesta(box);
    if (completo) {
        box.classList.remove('item-error');
        return true;
    }
    if (forzarMarcado || box.classList.contains('item-error')) {
        box.classList.add('item-error');
    }
    return false;
}

function enfocarPrimerItemPendiente(item) {
    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    window.setTimeout(() => {
        const control = item.querySelector('input[name^="respuesta"]:not([disabled]), select[name^="respuesta"]:not([disabled]), textarea[name^="respuesta"]:not([disabled]), .no-aplica');
        if (control) control.focus({ preventScroll: true });
    }, 450);
}

function validarItemsFicha() {
    const pendientes = [];
    formFicha.querySelectorAll('.item-box').forEach(box => {
        if (!actualizarEstadoItem(box, true)) pendientes.push(box);
    });
    if (pendientes.length > 0) {
        const primero = pendientes[0];
        const numero = primero.dataset.itemNumero || '';
        const alerta = document.getElementById('alertaValidacionCliente');
        if (alerta) {
            alerta.classList.add('show');
            alerta.innerHTML = '<i class="bi bi-exclamation-triangle-fill me-2"></i>Completa el primer item pendiente' + (numero ? ' (' + numero + ')' : '') + '. Esta marcado en rojo dentro del formulario.';
        }
        enfocarPrimerItemPendiente(primero);
        return false;
    }
    document.getElementById('alertaValidacionCliente')?.classList.remove('show');
    return true;
}

document.querySelectorAll('.item-box input, .item-box select, .item-box textarea').forEach(ctrl => {
    const actualizar = () => {
        const box = ctrl.closest('.item-box');
        actualizarEstadoItem(box, false);
        if (!document.querySelector('.item-box.item-error')) {
            document.getElementById('alertaValidacionCliente')?.classList.remove('show');
        }
    };
    ctrl.addEventListener('input', actualizar);
    ctrl.addEventListener('change', actualizar);
});

const formFicha = document.getElementById('formFichaMonitoreo');
if (formFicha) {
    const latInput = document.getElementById('latitudFicha');
    const lngInput = document.getElementById('longitudFicha');
    const accInput = document.getElementById('precisionFicha');
    let envioFichaConfirmado = false;
    let ubicacionListaParaEnvio = false;

    const guardarUbicacionFicha = pos => {
        if (latInput && lngInput) {
            latInput.value = pos.coords.latitude.toFixed(8);
            lngInput.value = pos.coords.longitude.toFixed(8);
        }
        if (accInput && Number.isFinite(pos.coords.accuracy)) {
            accInput.value = pos.coords.accuracy.toFixed(2);
        }
    };

    const pedirUbicacionFicha = () => new Promise((resolve, reject) => {
        if (!latInput || !lngInput) {
            reject(new Error('No se encontraron los campos de ubicacion.'));
            return;
        }

        const obtenerUbicacionPorIP = () => {
            const endpoint = window.isSecureContext ? 'https://freeipapi.com/api/json' : 'http://ip-api.com/json';
            fetch(endpoint)
                .then(res => res.json())
                .then(data => {
                    let lat = null, lng = null;
                    if (data.lat !== undefined && data.lon !== undefined) {
                        lat = data.lat; lng = data.lon;
                    } else if (data.latitude !== undefined && data.longitude !== undefined) {
                        lat = data.latitude; lng = data.longitude;
                    }
                    if (lat && lng) {
                        latInput.value = parseFloat(lat).toFixed(8);
                        lngInput.value = parseFloat(lng).toFixed(8);
                        if (accInput) accInput.value = '50000.00';
                        resolve({ coords: { latitude: lat, longitude: lng } });
                    } else {
                        resolve(null);
                    }
                })
                .catch(() => {
                    resolve(null);
                });
        };

        if (!window.isSecureContext || !navigator.geolocation) {
            obtenerUbicacionPorIP();
            return;
        }
        navigator.geolocation.getCurrentPosition(pos => {
            guardarUbicacionFicha(pos);
            resolve(pos);
        }, err => {
            obtenerUbicacionPorIP();
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 });
    });

    if (window.isSecureContext && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(guardarUbicacionFicha, () => {
            const endpoint = 'https://freeipapi.com/api/json';
            fetch(endpoint)
                .then(res => res.json())
                .then(data => {
                    if (data.latitude && data.longitude) {
                        latInput.value = parseFloat(data.latitude).toFixed(8);
                        lngInput.value = parseFloat(data.longitude).toFixed(8);
                        if (accInput) accInput.value = '50000.00';
                    }
                }).catch(() => {});
        }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 300000 });
    } else {
        const endpoint = 'http://ip-api.com/json';
        fetch(endpoint)
            .then(res => res.json())
            .then(data => {
                if (data.lat && data.lon) {
                    latInput.value = parseFloat(data.lat).toFixed(8);
                    lngInput.value = parseFloat(data.lon).toFixed(8);
                    if (accInput) accInput.value = '50000.00';
                }
            }).catch(() => {});
    }

    const autosaveStatus = document.getElementById('autosaveStatus');
    let autosaveTimer = null;
    let autosaveController = null;
    let autosaveSeq = 0;
    let autosaveDirty = false;

    const setAutosaveStatus = (state, text, icon) => {
        if (!autosaveStatus) return;
        autosaveStatus.dataset.state = state;
        autosaveStatus.innerHTML = '<i class="bi ' + icon + '"></i><span>' + text + '</span>';
    };

    const buildAutosaveData = () => {
        const data = new FormData(formFicha);
        data.set('accion', 'guardar_borrador');
        return data;
    };

    const runAutosave = async () => {
        if (!autosaveDirty) return;
        autosaveDirty = false;
        const seq = ++autosaveSeq;

        if (autosaveController) autosaveController.abort();
        autosaveController = new AbortController();
        setAutosaveStatus('saving', 'Guardando borrador...', 'bi-cloud-arrow-up');

        try {
            const response = await fetch(window.location.href, {
                method: 'POST',
                body: buildAutosaveData(),
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                },
                signal: autosaveController.signal
            });
            const payload = await response.json().catch(() => null);
            if (!response.ok || !payload || !payload.ok) {
                throw new Error(payload?.message || 'No se pudo guardar el borrador.');
            }
            if (seq === autosaveSeq) {
                setAutosaveStatus('saved', 'Borrador guardado ' + new Date().toLocaleTimeString('es-PE', {hour: '2-digit', minute: '2-digit'}), 'bi-cloud-check');
            }
        } catch (err) {
            if (err.name === 'AbortError') return;
            autosaveDirty = true;
            setAutosaveStatus('error', 'Error al autoguardar. Usa Guardar borrador.', 'bi-cloud-slash');
        }
    };

    const scheduleAutosave = () => {
        autosaveDirty = true;
        setAutosaveStatus('idle', 'Cambios pendientes...', 'bi-cloud');
        clearTimeout(autosaveTimer);
        autosaveTimer = setTimeout(runAutosave, 1800);
    };

    formFicha.querySelectorAll('input[name^="respuesta"], select[name^="respuesta"], textarea[name^="respuesta"], input[name^="no_aplica"], textarea[name^="observacion"]').forEach(ctrl => {
        ctrl.addEventListener('input', scheduleAutosave);
        ctrl.addEventListener('change', scheduleAutosave);
    });

    formFicha.addEventListener('submit', function() {
        clearTimeout(autosaveTimer);
        if (autosaveController) autosaveController.abort();
    });

    let clickSubmitter = null;
    formFicha.querySelectorAll('button[type="submit"]').forEach(btn => {
        btn.addEventListener('click', function() {
            clickSubmitter = this;
        });
    });

    formFicha.addEventListener('submit', function(e) {
        const submitter = clickSubmitter || e.submitter || document.activeElement;
        clickSubmitter = null;
        if (!submitter || (submitter.value !== 'enviar_ficha' && submitter.getAttribute('value') !== 'enviar_ficha')) return;
        e.stopImmediatePropagation();
        if (!validarItemsFicha()) {
            e.preventDefault();
            envioFichaConfirmado = false;
            return;
        }
        if (!envioFichaConfirmado) {
            if (!confirm('Confirma el envio de la ficha. Luego quedara registrada para revision.')) {
                e.preventDefault();
                return;
            }
            envioFichaConfirmado = true;
        }
        if (ubicacionListaParaEnvio || (latInput && lngInput && latInput.value && lngInput.value)) {
            ubicacionListaParaEnvio = true;
            return;
        }
        e.preventDefault();
        submitter.disabled = true;
        const originalText = submitter.innerHTML;
        submitter.innerHTML = '<i class="bi bi-geo-alt me-1"></i> Obteniendo ubicacion...';
        
        clearTimeout(autosaveTimer);
        if (autosaveController) autosaveController.abort();

        pedirUbicacionFicha()
            .then(() => {
                ubicacionListaParaEnvio = true;
                submitter.disabled = false;
                
                let actionInput = formFicha.querySelector('input[name="accion"]');
                if (!actionInput) {
                    actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'accion';
                    formFicha.appendChild(actionInput);
                }
                actionInput.value = 'enviar_ficha';
                formFicha.submit();
            })
            .catch(err => {
                envioFichaConfirmado = false;
                submitter.disabled = false;
                submitter.innerHTML = originalText;
                alert((err && err.message ? err.message : 'No se pudo obtener la ubicacion.') + ' Active el permiso de ubicacion y vuelva a enviar la ficha.');
            });
    }, true);
}
</script>
<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
