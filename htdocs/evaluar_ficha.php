<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) { header('Location: login.php'); exit(); }
if (($_SESSION['rol'] ?? '') !== 'especialista') { header('Location: dashboard.php'); exit(); }

$id_usuario  = (int)$_SESSION['id_usuario'];
$id_registro = (int)($_GET['id'] ?? $_POST['id_registro'] ?? 0);
$mensaje     = '';
$error       = '';
$errores_validacion = [];

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function fm_eval_table_has_column(mysqli $conn, string $table, string $column): bool {
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

function fm_eval_post_coord(string $name): ?float {
    $raw = trim((string)($_POST[$name] ?? ''));
    return ($raw !== '' && is_numeric($raw)) ? (float)$raw : null;
}

function fm_eval_actualizar_coordenadas(mysqli $conn, int $idEvaluacion, ?float $latitud, ?float $longitud, ?float $precision): void {
    if ($idEvaluacion <= 0 || $latitud === null || $longitud === null) {
        return;
    }
    if (!fm_eval_table_has_column($conn, 'fm_evaluaciones', 'latitud') || !fm_eval_table_has_column($conn, 'fm_evaluaciones', 'longitud')) {
        return;
    }
    if ($precision !== null && fm_eval_table_has_column($conn, 'fm_evaluaciones', 'precision_metros')) {
        $stmt = $conn->prepare("UPDATE fm_evaluaciones SET latitud = ?, longitud = ?, precision_metros = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param('dddi', $latitud, $longitud, $precision, $idEvaluacion);
            $stmt->execute();
            $stmt->close();
        }
        return;
    }
    $stmt = $conn->prepare("UPDATE fm_evaluaciones SET latitud = ?, longitud = ? WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param('ddi', $latitud, $longitud, $idEvaluacion);
        $stmt->execute();
        $stmt->close();
    }
}

function badgeEstadoEval($e){
    return match($e){
        'ENVIADO'     => 'bg-primary',
        'EN_REVISION' => 'bg-secondary',
        'OBSERVADO'   => 'bg-warning text-dark',
        'RECHAZADO'   => 'bg-danger',
        'CONFORME'    => 'bg-success',
        'REVISADO'    => 'bg-success',
        default       => 'bg-light text-dark border',
    };
}

function valoracionEval($p){
    $p = (float)$p;
    if ($p <= 25) return 'INICIO';
    if ($p <= 50) return 'PROCESO';
    if ($p <= 75) return 'LOGRADO';
    return 'DESTACADO';
}

function codigoEvaluacion($nombreFicha){
    $txt   = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)$nombreFicha);
    $txt   = strtoupper($txt ?: (string)$nombreFicha);
    $parts = preg_split('/[^A-Z0-9]+/', $txt, -1, PREG_SPLIT_NO_EMPTY);
    $stop  = ['DE','DEL','LA','LAS','LOS','EL','Y','A','AL','EN','CON','PARA','POR'];
    $sig   = '';
    foreach ($parts as $p){ if (in_array($p, $stop, true)) continue; $sig .= substr($p, 0, 1); if (strlen($sig) >= 6) break; }
    return 'EVAL-'.($sig ?: 'FM').'-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
}

function auditarEval($conn, $tabla, $id, $accion, $desc, $user){
    $permitidas = ['CREAR','EDITAR','PUBLICAR','INACTIVAR','ACEPTAR_CONSENTIMIENTO','RECHAZAR_CONSENTIMIENTO','GUARDAR_BORRADOR','ENVIAR','EVALUAR','CAMBIAR_ESTADO','GENERAR_PDF','CREAR_PRORROGA'];
    if (!in_array($accion, $permitidas, true)){ $desc = '['.$accion.'] '.$desc; $accion = 'EVALUAR'; }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $st = $conn->prepare("INSERT INTO fm_auditoria(tabla_afectada,id_registro_afectado,accion,descripcion,id_usuario,ip_usuario,user_agent,fecha_accion) VALUES(?,?,?,?,?,?,?,NOW())");
    if ($st){ $st->bind_param('sississ', $tabla, $id, $accion, $desc, $user, $ip, $ua); $st->execute(); $st->close(); }
}

function calcularEvaluacion($items, $post){
    $total = 0.0; $maximo = 0.0; $niveles = [1=>0,2=>0,3=>0,4=>0];
    foreach ($items as $it){
        $id = (int)$it['id'];
        $no = isset($post['eval_no_aplica'][$id]) && (string)$post['eval_no_aplica'][$id] === '1';
        if ($no) continue;
        if (($it['tipo_respuesta'] ?? '') === 'ESCALA_1_4'){
            $v = isset($post['eval_respuesta'][$id]) ? (int)$post['eval_respuesta'][$id] : 0;
            if ($v >= 1 && $v <= 4){ $total += $v; $maximo += (float)$it['puntaje_maximo']; $niveles[$v]++; }
        }
    }
    $porcentaje = $maximo > 0 ? round(($total / $maximo) * 100, 2) : 0.0;
    return [$total, $maximo, $porcentaje, valoracionEval($porcentaje), $niveles];
}

function guardarRespuestasEvaluacion($conn, $id_evaluacion, $items, $validarCompleto = false){
    $errores = [];
    foreach ($items as $it){
        $id_item = (int)$it['id'];
        $tipo    = $it['tipo_respuesta'] ?? 'ESCALA_1_4';
        $no      = isset($_POST['eval_no_aplica'][$id_item]) && (string)$_POST['eval_no_aplica'][$id_item] === '1' ? 1 : 0;
        $escala = null; $numero = null; $texto = null; $sino = null; $fecha = null;
        $obs = trim($_POST['observacion_especialista'][$id_item] ?? '');

        if (!$no){
            if ($tipo === 'ESCALA_1_4'){
                $escala = isset($_POST['eval_respuesta'][$id_item]) ? (int)$_POST['eval_respuesta'][$id_item] : null;
                if ($validarCompleto && !in_array($escala, [1,2,3,4], true)) $errores[] = 'Falta evaluar el ítem '.($it['numero_item'] ?? $id_item).'.';
            } elseif ($tipo === 'NUMERO'){
                $raw = trim($_POST['eval_numero'][$id_item] ?? '');
                if ($raw !== '') $numero = (float)$raw;
                if ($validarCompleto && $raw === '') $errores[] = 'Falta evaluar el ítem '.($it['numero_item'] ?? $id_item).'.';
            } elseif ($tipo === 'TEXTO'){
                $texto = trim($_POST['eval_texto'][$id_item] ?? '');
                if ($validarCompleto && $texto === '') $errores[] = 'Falta evaluar el ítem '.($it['numero_item'] ?? $id_item).'.';
            } elseif ($tipo === 'SI_NO'){
                $sino = $_POST['eval_si_no'][$id_item] ?? null;
                if ($validarCompleto && !in_array($sino, ['SI','NO'], true)) $errores[] = 'Falta evaluar el ítem '.($it['numero_item'] ?? $id_item).'.';
            } elseif ($tipo === 'FECHA'){
                $fecha = trim($_POST['eval_fecha'][$id_item] ?? '');
                if ($fecha === '') $fecha = null;
                if ($validarCompleto && $fecha === null) $errores[] = 'Falta evaluar el ítem '.($it['numero_item'] ?? $id_item).'.';
            }
        }

        $sql = "INSERT INTO fm_respuestas_especialista
                (id_evaluacion,id_item,no_aplica,respuesta_escala,respuesta_numero,respuesta_texto,respuesta_si_no,respuesta_fecha,observacion,fecha_registro)
                VALUES(?,?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                    no_aplica=VALUES(no_aplica),
                    respuesta_escala=VALUES(respuesta_escala),
                    respuesta_numero=VALUES(respuesta_numero),
                    respuesta_texto=VALUES(respuesta_texto),
                    respuesta_si_no=VALUES(respuesta_si_no),
                    respuesta_fecha=VALUES(respuesta_fecha),
                    observacion=VALUES(observacion),
                    fecha_registro=NOW()";
        $st = $conn->prepare($sql);
        $st->bind_param('iiiidssss', $id_evaluacion, $id_item, $no, $escala, $numero, $texto, $sino, $fecha, $obs);
        $st->execute();
        $st->close();
    }
    return $errores;
}

if ($id_registro <= 0){ die('Ficha no especificada.'); }

// Usuario especialista — correo incluido para el topbar
$st = $conn->prepare("SELECT id,nombres,ape_pat,ape_mat,dni,correo FROM usuarios WHERE id=? LIMIT 1");
$st->bind_param('i', $id_usuario); $st->execute(); $user = $st->get_result()->fetch_assoc(); $st->close();
$nombre       = trim(($user['nombres']??'').' '.($user['ape_pat']??'').' '.($user['ape_mat']??''));
$nombre_corto = h($user['nombres'] ?? 'Usuario');

// Validar ficha asignada
$sql = "SELECT r.*, tf.nombre AS tipo_ficha, tf.anio, i.nombre_ie, i.codigo_local,
               IFNULL(i.codigo_institucion,'') AS codigo_institucion, i.distrito,
               u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni
        FROM fm_registros r
        INNER JOIN asignaciones_especialistas ae ON ae.id_institucion=r.id_institucion AND ae.id_usuario=? AND ae.estado='activo'
        INNER JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha
        INNER JOIN instituciones i ON i.id=r.id_institucion
        INNER JOIN usuarios u ON u.id=r.id_usuario
        WHERE r.id=? AND r.activo=1 LIMIT 1";
$st = $conn->prepare($sql); $st->bind_param('ii', $id_usuario, $id_registro); $st->execute();
$registro = $st->get_result()->fetch_assoc(); $st->close();
if (!$registro){ die('No se encontró la ficha o no tiene permiso para evaluarla.'); }

$estado_registro = $registro['estado'];
$bloqueado = in_array($estado_registro, ['OBSERVADO','RECHAZADO','REVISADO'], true);
if ($estado_registro === 'ENVIADO'){
    die('Antes de evaluar a detalle, debe revisar el PDF enviado y marcar la ficha como CONFORME desde Búsqueda/Revisión de Fichas.');
}

// Estructura de la ficha
$id_version = (int)$registro['id_version_ficha'];
$secciones  = [];
$rs = $conn->query("SELECT * FROM fm_secciones WHERE id_version_ficha=$id_version AND estado='ACTIVO' ORDER BY nivel, orden, id");
while ($r = $rs->fetch_assoc()) $secciones[(int)$r['id']] = $r;

$items = [];
$rs = $conn->query("SELECT * FROM fm_items WHERE id_version_ficha=$id_version AND estado='ACTIVO' ORDER BY COALESCE(id_seccion,0), orden, id");
while ($r = $rs->fetch_assoc()) $items[(int)$r['id']] = $r;

$rubricas = [];
if ($items){
    $ids = implode(',', array_map('intval', array_keys($items)));
    $rs  = $conn->query("SELECT * FROM fm_rubricas WHERE id_item IN ($ids) ORDER BY id_item, valor");
    while ($r = $rs->fetch_assoc()) $rubricas[(int)$r['id_item']][] = $r;
}

$respuestasDirector = [];
$rs = $conn->query("SELECT * FROM fm_respuestas_director WHERE id_registro=$id_registro");
while ($r = $rs->fetch_assoc()) $respuestasDirector[(int)$r['id_item']] = $r;

// Crear / obtener evaluación
$evaluacion = null;
if (!$bloqueado){
    $st = $conn->prepare("SELECT * FROM fm_evaluaciones WHERE id_registro=? AND id_especialista=? ORDER BY id DESC LIMIT 1");
    $st->bind_param('ii', $id_registro, $id_usuario); $st->execute(); $evaluacion = $st->get_result()->fetch_assoc(); $st->close();
    if (!$evaluacion){
        $cod = codigoEvaluacion($registro['tipo_ficha']);
        $st  = $conn->prepare("INSERT INTO fm_evaluaciones(codigo_evaluacion,id_registro,id_especialista,estado_evaluacion,fecha_inicio,fecha_creacion,fecha_actualizacion) VALUES(?,?,?,'EN_REVISION',NOW(),NOW(),NOW())");
        $st->bind_param('sii', $cod, $id_registro, $id_usuario); $st->execute(); $id_eval_new = $st->insert_id; $st->close();
        $st = $conn->prepare("SELECT * FROM fm_evaluaciones WHERE id=? LIMIT 1");
        $st->bind_param('i', $id_eval_new); $st->execute(); $evaluacion = $st->get_result()->fetch_assoc(); $st->close();
    }

    if ($estado_registro === 'CONFORME'){
        $conn->begin_transaction();
        try {
            $st = $conn->prepare("UPDATE fm_registros SET estado='EN_REVISION', fecha_actualizacion=NOW() WHERE id=?");
            $st->bind_param('i', $id_registro); $st->execute(); $st->close();
            $id_eval = (int)$evaluacion['id'];
            $coment  = 'El especialista inició la evaluación detallada de una ficha conforme.';
            $st = $conn->prepare("INSERT INTO fm_historial_estados(id_registro,id_evaluacion,estado_anterior,estado_nuevo,comentario,cambiado_por,fecha_cambio) VALUES(?,?,'CONFORME','EN_REVISION',?,?,NOW())");
            $st->bind_param('iisi', $id_registro, $id_eval, $coment, $id_usuario); $st->execute(); $st->close();
            auditarEval($conn, 'fm_registros', $id_registro, 'INICIAR_EVALUACION', $coment, $id_usuario);
            $conn->commit();
            $estado_registro     = 'EN_REVISION';
            $registro['estado']  = 'EN_REVISION';
        } catch (Throwable $e){ $conn->rollback(); $error = 'No se pudo iniciar la evaluación: '.$e->getMessage(); }
    }
}

$id_evaluacion = $evaluacion ? (int)$evaluacion['id'] : 0;

// Visitas
$visita = null; $max_visita = 0; $visitas_hist = [];
if ($id_evaluacion > 0){
    $rs = $conn->query("SELECT * FROM fm_evaluacion_visitas WHERE id_evaluacion=$id_evaluacion ORDER BY numero_visita ASC");
    while ($r = $rs->fetch_assoc()){
        $visitas_hist[] = $r;
        $max_visita = max($max_visita, (int)$r['numero_visita']);
        if ($r['estado_visita'] === 'EN_PROGRESO') $visita = $r;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'iniciar_visita' && !$bloqueado){
        if ($visita){ $mensaje = 'Ya existe una visita en progreso.'; }
        elseif ($max_visita >= 3){ $error = 'Ya se alcanzó el máximo de 3 visitas.'; }
        else {
            $num = $max_visita + 1;
            $st  = $conn->prepare("INSERT INTO fm_evaluacion_visitas(id_evaluacion,numero_visita,fecha_visita,estado_visita,iniciada_por,fecha_inicio) VALUES(?,?,CURDATE(),'EN_PROGRESO',?,NOW())");
            $st->bind_param('iii', $id_evaluacion, $num, $id_usuario); $st->execute(); $st->close();
            header('Location: evaluar_ficha.php?id='.$id_registro.'&msg=visita_iniciada'); exit();
        }
    }

    if (!$visita && $max_visita === 0 && !$bloqueado){
        $st = $conn->prepare("INSERT INTO fm_evaluacion_visitas(id_evaluacion,numero_visita,fecha_visita,estado_visita,iniciada_por,fecha_inicio) VALUES(?,1,CURDATE(),'EN_PROGRESO',?,NOW())");
        $st->bind_param('ii', $id_evaluacion, $id_usuario); $st->execute(); $st->close();
        header('Location: evaluar_ficha.php?id='.$id_registro); exit();
    }
}
if (($_GET['msg'] ?? '') === 'visita_iniciada') $mensaje = 'Visita iniciada correctamente.';

// Respuestas guardadas del especialista
$respuestasEval = [];
if ($id_evaluacion > 0){
    $rs = $conn->query("SELECT * FROM fm_respuestas_especialista WHERE id_evaluacion=$id_evaluacion");
    while ($r = $rs->fetch_assoc()) $respuestasEval[(int)$r['id_item']] = $r;
}

// Normaliza columnas de BD → claves _eval para el template.
// respuesta_escala es DECIMAL(10,2) en BD ("3.00"): se castea a int
// para que coincida con los values 1-4 de los radios.
function valorInicialEval($id_item, $tipo, $respuestasEval, $respuestasDirector){
    if (isset($respuestasEval[$id_item])){
        $r = $respuestasEval[$id_item];
        return [
            'no_aplica_eval'           => $r['no_aplica'] ?? 0,
            'respuesta_escala_eval'    => isset($r['respuesta_escala']) && $r['respuesta_escala'] !== null ? (int)$r['respuesta_escala'] : null,
            'respuesta_numero_eval'    => $r['respuesta_numero'] ?? null,
            'respuesta_texto_eval'     => $r['respuesta_texto']  ?? null,
            'respuesta_si_no_eval'     => $r['respuesta_si_no']  ?? null,
            'respuesta_fecha_eval'     => $r['respuesta_fecha']  ?? null,
            'observacion_especialista' => $r['observacion']      ?? '',
        ];
    }
    $d = $respuestasDirector[$id_item] ?? null;
    if (!$d) return null;
    return [
        'no_aplica_eval'           => $d['no_aplica'] ?? 0,
        'respuesta_escala_eval'    => isset($d['respuesta_escala']) && $d['respuesta_escala'] !== null ? (int)$d['respuesta_escala'] : null,
        'respuesta_numero_eval'    => $d['respuesta_numero'] ?? null,
        'respuesta_texto_eval'     => $d['respuesta_texto']  ?? null,
        'respuesta_si_no_eval'     => $d['respuesta_si_no']  ?? null,
        'respuesta_fecha_eval'     => $d['respuesta_fecha']  ?? null,
        'observacion_especialista' => '',
    ];
}

// Procesar guardado / finalización
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado && $id_evaluacion > 0
    && isset($_POST['accion']) && in_array($_POST['accion'], ['guardar_progreso','autoguardar','cerrar_visita','vista_previa','finalizar_evaluacion'], true)){

    $accion  = $_POST['accion'];
    $validar = in_array($accion, ['vista_previa','finalizar_evaluacion'], true);
    $latitud_eval = fm_eval_post_coord('latitud');
    $longitud_eval = fm_eval_post_coord('longitud');
    $precision_eval = fm_eval_post_coord('precision_metros');
    $errores_validacion = guardarRespuestasEvaluacion($conn, $id_evaluacion, $items, $validar);
    [$pt, $pm, $por, $escala] = calcularEvaluacion($items, $_POST);

    if (empty($errores_validacion)){
        $obs_general = trim($_POST['observacion_general'] ?? '');
        $st = $conn->prepare("UPDATE fm_evaluaciones SET estado_evaluacion='EN_REVISION', observacion_general=?, puntaje_total_eval=?, puntaje_maximo_eval=?, porcentaje_eval=?, escala_valorativa_eval=?, fecha_actualizacion=NOW() WHERE id=?");
        $st->bind_param('sdddsi', $obs_general, $pt, $pm, $por, $escala, $id_evaluacion); $st->execute(); $st->close();
        fm_eval_actualizar_coordenadas($conn, $id_evaluacion, $latitud_eval, $longitud_eval, $precision_eval);

        if ($visita){
            $id_visita = (int)$visita['id'];
            $st = $conn->prepare("UPDATE fm_evaluacion_visitas SET fecha_guardado=NOW() WHERE id=?");
            $st->bind_param('i', $id_visita); $st->execute(); $st->close();
        }

        // Autoguardado vía fetch: responde JSON sin redirigir ni auditar
        // (evita llenar fm_auditoria con un registro por cada cambio)
        if ($accion === 'autoguardar'){
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'hora' => date('H:i:s')]);
            exit();
        }

        if ($accion === 'guardar_progreso'){
            auditarEval($conn, 'fm_evaluaciones', $id_evaluacion, 'GUARDAR_PROGRESO_VISITA', 'El especialista guardó progreso de visita.', $id_usuario);
            header('Location: evaluar_ficha.php?id='.$id_registro.'&msg=guardado'); exit();
        }

        if ($accion === 'cerrar_visita' && $visita){
            $id_visita = (int)$visita['id'];
            $st = $conn->prepare("UPDATE fm_evaluacion_visitas SET estado_visita='CERRADA', fecha_guardado=NOW(), fecha_cierre=NOW() WHERE id=?");
            $st->bind_param('i', $id_visita); $st->execute(); $st->close();
            auditarEval($conn, 'fm_evaluacion_visitas', $id_visita, 'CERRAR_VISITA', 'El especialista cerró la visita de evaluación.', $id_usuario);
            header('Location: evaluar_ficha.php?id='.$id_registro.'&msg=visita_cerrada'); exit();
        }

        if ($accion === 'vista_previa'){
            header('Location: evaluar_ficha.php?id='.$id_registro.'&modo=finalizar'); exit();
        }

        if ($accion === 'finalizar_evaluacion'){
            $compromiso_director     = trim($_POST['compromiso_director'] ?? '');
            $compromiso_especialista = trim($_POST['compromiso_especialista'] ?? '');
            if ($compromiso_director === '')     $errores_validacion[] = 'Debe escribir el compromiso del director/subdirector.';
            if ($compromiso_especialista === '') $errores_validacion[] = 'Debe escribir el compromiso del especialista.';
            if (empty($errores_validacion)){
                $conn->begin_transaction();
                try {
                    $st = $conn->prepare("UPDATE fm_evaluaciones SET estado_evaluacion='FINALIZADA', resultado_estado='REVISADO', compromiso_director=?, compromiso_especialista=?, puntaje_total_eval=?, puntaje_maximo_eval=?, porcentaje_eval=?, escala_valorativa_eval=?, fecha_finalizacion=NOW(), fecha_actualizacion=NOW() WHERE id=?");
                    $st->bind_param('ssdddsi', $compromiso_director, $compromiso_especialista, $pt, $pm, $por, $escala, $id_evaluacion); $st->execute(); $st->close();

                    $estado_anterior = $registro['estado'];
                    $st = $conn->prepare("UPDATE fm_registros SET estado='REVISADO', fecha_actualizacion=NOW() WHERE id=?");
                    $st->bind_param('i', $id_registro); $st->execute(); $st->close();

                    if ($visita){
                        $id_visita = (int)$visita['id'];
                        $st = $conn->prepare("UPDATE fm_evaluacion_visitas SET estado_visita='CERRADA', fecha_guardado=NOW(), fecha_cierre=NOW() WHERE id=?");
                        $st->bind_param('i', $id_visita); $st->execute(); $st->close();
                    }

                    $coment = 'Evaluación detallada finalizada. La ficha queda como REVISADO.';
                    $nuevo  = 'REVISADO';
                    $st = $conn->prepare("INSERT INTO fm_historial_estados(id_registro,id_evaluacion,estado_anterior,estado_nuevo,comentario,cambiado_por,fecha_cambio) VALUES(?,?,?,?,?,?,NOW())");
                    $st->bind_param('iisssi', $id_registro, $id_evaluacion, $estado_anterior, $nuevo, $coment, $id_usuario); $st->execute(); $st->close();
                    auditarEval($conn, 'fm_evaluaciones', $id_evaluacion, 'FINALIZAR_EVALUACION', $coment, $id_usuario);
                    $conn->commit();

                    try {
                        require_once __DIR__ . '/fm_pdf_evaluacion_helpers.php';
                        fm_generar_documentos_evaluacion_especialista($conn, $id_evaluacion, $id_usuario);
                        header('Location: especialista_fichas.php?ok=evaluacion_finalizada'); exit();
                    } catch (Throwable $pdfEx){
                        header('Location: especialista_fichas.php?ok=evaluacion_finalizada&pdf_error=1'); exit();
                    }
                } catch (Throwable $ex){
                    $conn->rollback();
                    $error = 'No se pudo finalizar la evaluación: '.$ex->getMessage();
                }
            }
        }
    }
}
if (($_GET['msg'] ?? '') === 'guardado')       $mensaje = 'Progreso de visita guardado correctamente.';
if (($_GET['msg'] ?? '') === 'visita_cerrada') $mensaje = 'Visita cerrada correctamente. Podrá iniciar una nueva visita si aún no finaliza la evaluación.';

// Recargar estado tras procesar
if ($id_evaluacion > 0){
    $st = $conn->prepare("SELECT * FROM fm_evaluaciones WHERE id=? LIMIT 1");
    $st->bind_param('i', $id_evaluacion); $st->execute(); $evaluacion = $st->get_result()->fetch_assoc(); $st->close();

    $respuestasEval = [];
    $rs = $conn->query("SELECT * FROM fm_respuestas_especialista WHERE id_evaluacion=$id_evaluacion");
    while ($r = $rs->fetch_assoc()) $respuestasEval[(int)$r['id_item']] = $r;

    $visita = null; $visitas_hist = []; $max_visita = 0;
    $rs = $conn->query("SELECT * FROM fm_evaluacion_visitas WHERE id_evaluacion=$id_evaluacion ORDER BY numero_visita ASC");
    while ($r = $rs->fetch_assoc()){
        $visitas_hist[] = $r;
        $max_visita = max($max_visita, (int)$r['numero_visita']);
        if ($r['estado_visita'] === 'EN_PROGRESO') $visita = $r;
    }
}

$puede_editar = !$bloqueado && $evaluacion && (($evaluacion['estado_evaluacion'] ?? '') !== 'FINALIZADA') && $visita;

// Calcular resultado actual desde BD para previsualización
$postCalc = ['eval_no_aplica' => [], 'eval_respuesta' => []];
foreach ($items as $iid => $it){
    $e = valorInicialEval($iid, $it['tipo_respuesta'], $respuestasEval, $respuestasDirector);
    if ($e){
        if ((int)($e['no_aplica_eval'] ?? 0) === 1)  $postCalc['eval_no_aplica'][$iid] = '1';
        if (isset($e['respuesta_escala_eval']))        $postCalc['eval_respuesta'][$iid] = $e['respuesta_escala_eval'];
    }
}
[$ptPrev, $pmPrev, $porPrev, $escPrev, $nivPrev] = calcularEvaluacion($items, $postCalc);

$ruLabels = [1 => 'INICIO', 2 => 'PROCESO', 3 => 'LOGRADO', 4 => 'DESTACADO'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php
    $page_title = 'Evaluar Ficha — '.h($registro['tipo_ficha'] ?? 'Evaluación');
    include 'components/head.php';
    ?>
    <style>
    /* ── section headers ─────────────────────────────────── */
    .aspect-title {
        background: var(--ugel-green-dark);
        color: #fff;
        border-radius: var(--ugel-radius-card);
        padding: 12px 16px;
        font-weight: 700;
        margin-top: 22px;
        margin-bottom: 8px;
    }
    .sub-title {
        background: var(--ugel-green-light);
        color: var(--ugel-green-dark);
        border-left: 4px solid var(--ugel-green-mid);
        border-radius: 0 var(--ugel-radius-card) var(--ugel-radius-card) 0;
        padding: 10px 14px;
        font-weight: 700;
        margin-top: 12px;
        margin-bottom: 8px;
    }
    /* ── eval panels ─────────────────────────────────────── */
    .eval-panel {
        background: var(--ugel-surface);
        border: 0.5px solid var(--ugel-border);
        border-radius: var(--ugel-radius-card);
        overflow: hidden;
        margin-bottom: 1.25rem;
    }
    .eval-panel-head {
        background: var(--ugel-green-dark);
        color: #fff;
        font-weight: 600;
        padding: 12px 18px;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: .95rem;
    }
    .eval-panel-body { padding: 16px 18px; }
    .ugel-topbar .ugel-profile-button,
    .ugel-topbar .ugel-profile-button:hover,
    .ugel-topbar .ugel-profile-button:focus,
    .ugel-topbar .ugel-profile-button:active,
    .ugel-topbar .ugel-profile-button.show {
        background: rgba(255, 255, 255, 0.12) !important;
        border: 0 !important;
        color: #fff !important;
        box-shadow: none !important;
    }
    .ugel-topbar .ugel-profile-button small { color: #bde4cc !important; }
    .ugel-topbar .ugel-profile-button strong,
    .ugel-topbar .ugel-profile-button .bi { color: #fff !important; }
    /* ── item card ───────────────────────────────────────── */
    .item-card {
        background: var(--ugel-surface);
        border: 0.5px solid var(--ugel-border);
        border-radius: var(--ugel-radius-card);
        overflow: hidden;
        margin-bottom: 1rem;
    }
    .item-head {
        background: var(--ugel-green-light);
        border-bottom: 1px solid var(--ugel-green-border);
        padding: 12px 16px;
    }
    /* ── response boxes: director (azul) vs especialista (verde) ── */
    .director-box {
        background: var(--ugel-blue-light);
        border: 1px solid #c3d4f5;
        border-left: 4px solid var(--ugel-blue);
        border-radius: var(--ugel-radius-card);
        padding: 14px;
        height: 100%;
    }
    .director-box h6 { color: var(--ugel-blue) !important; }
    .eval-box {
        background: var(--ugel-surface);
        border: 1px solid var(--ugel-green-border);
        border-left: 4px solid var(--ugel-green-mid);
        border-radius: var(--ugel-radius-card);
        padding: 14px;
        height: 100%;
    }
    /* Chip grande del nivel marcado por el director, con el color de su escala */
    .dir-level {
        display: inline-flex;
        align-items: center;
        gap: 9px;
        border-radius: var(--ugel-radius-card);
        padding: 9px 16px;
        font-weight: 700;
        font-size: 0.95rem;
        color: #fff;
        letter-spacing: .3px;
        box-shadow: var(--ugel-shadow-soft);
    }
    .dir-level-num {
        width: 26px; height: 26px;
        border-radius: 50%;
        background: rgba(255,255,255,.28);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: .82rem;
        flex-shrink: 0;
    }
    .dir-level-1 { background: var(--ugel-red); }
    .dir-level-2 { background: var(--ugel-orange); }
    .dir-level-3 { background: var(--ugel-green-mid); }
    .dir-level-4 { background: var(--ugel-blue); }
    .dir-na   { display:inline-flex; align-items:center; gap:7px; background:#6c757d; color:#fff; border-radius:var(--ugel-radius-card); padding:9px 16px; font-weight:700; font-size:.9rem; }
    .dir-vacio{ display:inline-flex; align-items:center; gap:7px; background:var(--ugel-surface); color:var(--ugel-text-muted); border:1px dashed var(--ugel-border); border-radius:var(--ugel-radius-card); padding:9px 16px; font-weight:600; font-size:.88rem; font-style:italic; }
    .dir-valor{ display:inline-flex; align-items:center; gap:7px; background:var(--ugel-surface); color:var(--ugel-text); border:1px solid #c3d4f5; border-radius:var(--ugel-radius-card); padding:9px 16px; font-weight:700; font-size:.92rem; }
    .item-error { border: 2px solid var(--ugel-red) !important; box-shadow: 0 0 0 3px rgba(198,40,40,.1) !important; }
    /* ── scale grid ──────────────────────────────────────── */
    .scale-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 6px; margin-bottom: 8px; }
    /* Input visually hidden but still in DOM so :checked CSS works */
    .scale-input { position: absolute; opacity: 0; pointer-events: none; }
    .scale-option {
        border: 1px solid var(--ugel-border);
        border-radius: var(--ugel-radius-card);
        padding: 8px 10px;
        font-weight: 600;
        font-size: 0.82rem;
        cursor: pointer;
        background: var(--ugel-surface);
        color: var(--ugel-text);
        transition: all 0.15s ease;
        min-height: 44px;
        display: flex;
        flex-direction: row;
        justify-content: center;
        align-items: center;
        gap: 8px;
        margin: 0;
        user-select: none;
    }
    .scale-option:hover { border-color: var(--ugel-green-border); background: var(--ugel-green-light); }
    .scale-number {
        width: 22px; height: 22px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background: #f3f2f1;
        color: #605e5c;
        font-weight: 700;
        font-size: 0.72rem;
        flex-shrink: 0;
        transition: background .15s, color .15s;
    }
    .scale-input:disabled + .scale-option { opacity: .5; cursor: not-allowed; pointer-events: none; }
    .scale-input:checked + .scale-1 { background: var(--ugel-red)       !important; border-color: var(--ugel-red)       !important; color: #fff !important; box-shadow: 0 2px 6px rgba(198,40,40,.25); }
    .scale-input:checked + .scale-2 { background: var(--ugel-orange)    !important; border-color: var(--ugel-orange)    !important; color: #fff !important; box-shadow: 0 2px 6px rgba(230,92,0,.25); }
    .scale-input:checked + .scale-3 { background: var(--ugel-green-mid) !important; border-color: var(--ugel-green-mid) !important; color: #fff !important; box-shadow: 0 2px 6px rgba(46,125,79,.25); }
    .scale-input:checked + .scale-4 { background: var(--ugel-blue)      !important; border-color: var(--ugel-blue)      !important; color: #fff !important; box-shadow: 0 2px 6px rgba(26,86,219,.25); }
    .scale-input:checked + .scale-option .scale-number { background: rgba(255,255,255,.25); color: inherit; }
    /* ── visit pill ──────────────────────────────────────── */
    .visit-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        border: 1px solid var(--ugel-green-border);
        background: var(--ugel-green-light);
        color: var(--ugel-green-dark);
        border-radius: 999px;
        padding: 7px 16px;
        font-weight: 700;
        font-size: .88rem;
        white-space: nowrap;
    }
    /* ── result cards ────────────────────────────────────── */
    .result-card {
        border: 0.5px solid var(--ugel-border);
        border-radius: var(--ugel-radius-card);
        background: var(--ugel-surface);
        padding: 14px;
    }
    .commit-risk-summary {
        border: 1px solid #f0d8a8;
        border-left: 4px solid var(--ugel-orange);
        border-radius: var(--ugel-radius-card);
        background: #fff9ec;
        padding: 12px 14px;
        margin: 0 0 14px;
    }
    .commit-risk-summary h6 {
        color: #7a4a00;
        font-weight: 700;
        margin: 0 0 8px;
        font-size: .92rem;
    }
    .commit-risk-list {
        display: grid;
        gap: 7px;
        margin: 0;
        padding: 0;
        list-style: none;
    }
    .commit-risk-item {
        display: grid;
        grid-template-columns: auto 1fr;
        gap: 8px;
        align-items: start;
        color: #3f3a2f;
        font-size: .88rem;
        line-height: 1.35;
    }
    .commit-risk-badge {
        border-radius: 999px;
        color: #fff;
        font-size: .72rem;
        font-weight: 700;
        padding: 3px 8px;
        white-space: nowrap;
    }
    .commit-risk-badge.level-1 { background: var(--ugel-red); }
    .commit-risk-badge.level-2 { background: var(--ugel-orange); }
    /* ── bar chart ───────────────────────────────────────── */
    .live-chart {
        border: 0.5px solid var(--ugel-border);
        border-radius: var(--ugel-radius-card);
        background: var(--ugel-surface);
        padding: 16px;
    }
    /* Las etiquetas viven en una fila aparte (.bar-labels) para que
       número + barra nunca desborden el área del gráfico */
    .bar-wrap { display: flex; align-items: flex-end; justify-content: center; gap: 18px; height: 145px; padding: 8px 12px 0; border-bottom: 1px solid var(--ugel-border); }
    .bar-col { width: 72px; display: flex; flex-direction: column; justify-content: flex-end; align-items: stretch; height: 100%; text-align: center; }
    .bar { width: 100%; min-height: 6px; border-radius: var(--ugel-radius-card) var(--ugel-radius-card) 0 0; transition: height .25s ease; }
    .bar-count { font-size: .9rem; font-weight: 700; line-height: 1.2; margin-bottom: 4px; }
    .bar-labels { display: flex; justify-content: center; gap: 18px; padding: 6px 12px 0; }
    .bar-label { width: 72px; text-align: center; font-size: .82rem; font-weight: 700; line-height: 1.25; }
    .bar-1 { background: var(--ugel-red); }
    .bar-2 { background: var(--ugel-yellow); }
    .bar-3 { background: var(--ugel-green-mid); }
    .bar-4 { background: var(--ugel-blue); }
    /* ── sticky footer ───────────────────────────────────── */
    .sticky-actions {
        position: sticky;
        bottom: 0;
        background: rgba(255,255,255,.97);
        backdrop-filter: blur(8px);
        border-top: 1px solid var(--ugel-border);
        padding: 12px 0;
        z-index: 50;
    }
    /* ── autosave indicator ──────────────────────────────── */
    .autosave-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: .82rem;
        font-weight: 600;
        color: var(--ugel-text-muted);
        white-space: nowrap;
    }
    .autosave-badge.saving { color: var(--ugel-orange); }
    .autosave-badge.ok     { color: var(--ugel-green-mid); }
    .autosave-badge.err    { color: var(--ugel-red); }
    /* ── locked overlay ──────────────────────────────────── */
    .locked-overlay {
        background: var(--ugel-yellow-light);
        border: 1px solid #ffe082;
        border-radius: var(--ugel-radius-card);
        padding: 14px;
    }
    /* ── commit box ──────────────────────────────────────── */
    .commit-box { display: none; }
    .commit-box.open { display: block; animation: fadeInBox .2s ease; }
    @keyframes fadeInBox { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } }
    /* ── rubrica modal ───────────────────────────────────── */
    .modal-header-green { background: var(--ugel-green-dark); color: #fff; }
    .rubrica-list li { margin-bottom: 10px; }
    /* ── back link ───────────────────────────────────────── */
    .ugel-back-link { display:inline-flex;align-items:center;gap:4px;font-size:.88rem;font-weight:600;color:var(--ugel-green-dark);text-decoration:none;padding:4px 10px 4px 6px;border-radius:6px;transition:background .15s; }
    .ugel-back-link:hover { background:var(--ugel-green-light);color:var(--ugel-green-dark); }
    @media (max-width: 768px) {
        .scale-grid { grid-template-columns: 1fr 1fr; }
        .sticky-actions .btn { width:100%; margin-bottom:6px; }
        .bar-wrap, .bar-labels { gap:8px; }
        .bar-col, .bar-label { width:54px; }
        .bar-label { font-size:.72rem; }
    }
    </style>
</head>
<body>

<!-- ─── TOPBAR ───────────────────────────────────────────────────── -->
<nav class="navbar navbar-expand navbar-light ugel-topbar sticky-top">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_especialista.php">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Evaluación de Ficha</small>
            </div>
        </a>

        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Especialista</small>
                        <strong><?= $nombre_corto ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end ugel-profile-menu p-4 mt-2">
                    <li class="text-center mb-4">
                        <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                        <h6 class="fw-bold mb-1"><?= h($nombre) ?></h6>
                        <small><?= h($user['correo'] ?? '') ?></small>
                    </li>
                    <li class="px-2">
                        <p class="info-label">DNI</p>
                        <p class="info-value"><?= h($user['dni'] ?? '') ?></p>
                        <p class="info-label">Rol</p>
                        <p class="info-value fw-bold" style="color:var(--ugel-green-dark);">Especialista</p>
                    </li>
                    <li><hr class="dropdown-divider my-3"></li>
                    <li>
                        <a class="btn btn-outline-primary ugel-menu-action w-100 fw-bold mb-2" href="panel_usuario.php">
                            <i class="bi bi-person"></i> Perfil
                        </a>
                    </li>
                    <li>
                        <a class="btn btn-outline-danger ugel-menu-action w-100 fw-bold" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i> Cerrar Sesión
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ─── MAIN ─────────────────────────────────────────────────────── -->
<main class="container-fluid px-3 px-md-4 py-4">

    <div class="mb-3">
        <a href="especialista_fichas.php" class="ugel-back-link">
            <i class="bi bi-arrow-left me-1"></i>Volver a Búsqueda/Revisión
        </a>
    </div>

    <!-- Encabezado de página -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1" style="color:#0f172a;">
                <i class="bi bi-clipboard2-check me-2" style="color:var(--ugel-green-dark);"></i>Evaluación detallada de ficha
            </h4>
            <p class="text-muted mb-0 small">La respuesta original del director/subdirector se conserva. Su evaluación queda registrada por separado.</p>
        </div>
        <div class="visit-pill">
            <i class="bi bi-geo-alt-fill"></i>
            <?php if ($visita): ?>
                Visita <?= (int)$visita['numero_visita'] ?> de 3
            <?php else: ?>
                Sin visita activa
            <?php endif; ?>
        </div>
    </div>

    <!-- Alertas -->
    <?php if ($mensaje): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle me-2"></i><?= h($mensaje) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($errores_validacion): ?>
    <div id="alertaValidacion" class="alert alert-danger">
        <strong>Revisa la ficha:</strong>
        <ul class="mb-0">
            <?php foreach ($errores_validacion as $ev): ?><li><?= h($ev) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Datos de la ficha -->
    <div class="eval-panel mb-4">
        <div class="eval-panel-head"><i class="bi bi-info-circle"></i>Datos de la ficha</div>
        <div class="eval-panel-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <strong>I.E.:</strong><br>
                    <?= h($registro['nombre_ie']) ?>
                    <br><small class="text-muted">Código: <?= h($registro['codigo_local'] ?: $registro['codigo_institucion']) ?> | <?= h($registro['distrito']) ?></small>
                </div>
                <div class="col-md-4">
                    <strong>Director/Subdirector:</strong><br>
                    <?= h(trim($registro['dir_nombres'].' '.$registro['dir_ape_pat'].' '.$registro['dir_ape_mat'])) ?>
                    <br><small class="text-muted">DNI: <?= h($registro['dir_dni']) ?></small>
                </div>
                <div class="col-md-4">
                    <strong>Tipo de ficha:</strong><br>
                    <?= h($registro['tipo_ficha']) ?><br>
                    <span class="badge <?= badgeEstadoEval($registro['estado']) ?>"><?= h($registro['estado']) ?></span>
                    <small class="text-muted ms-1">Código: <?= h($registro['codigo_envio']) ?></small>
                </div>
            </div>
        </div>
    </div>

    <!-- Overlay bloqueado / finalizado -->
    <?php if ($bloqueado || (($evaluacion['estado_evaluacion'] ?? '') === 'FINALIZADA')): ?>
    <div class="locked-overlay mb-4">
        <i class="bi bi-lock-fill me-2" style="color:var(--ugel-yellow);"></i>
        Esta ficha ya no puede editarse desde el perfil especialista. Si se requiere una modificación excepcional, deberá intervenir el administrador.
    </div>
    <?php endif; ?>

    <!-- Gestión de visitas (sin visita activa) -->
    <?php if (!$bloqueado && !$visita && (($evaluacion['estado_evaluacion'] ?? '') !== 'FINALIZADA')): ?>
    <div class="eval-panel mb-4">
        <div class="eval-panel-head"><i class="bi bi-calendar-plus"></i>Gestión de visitas</div>
        <div class="eval-panel-body">
            <p class="mb-3 text-muted">No hay una visita activa. Puede iniciar la siguiente visita para continuar la evaluación.</p>
            <div class="mb-3 d-flex flex-wrap gap-2">
                <?php foreach ($visitas_hist as $vh): ?>
                <span class="badge <?= $vh['estado_visita'] === 'CERRADA' ? 'bg-secondary' : 'bg-success' ?>">
                    Visita <?= (int)$vh['numero_visita'] ?>: <?= h($vh['estado_visita']) ?>
                </span>
                <?php endforeach; ?>
            </div>
            <?php if ($max_visita < 3): ?>
            <form method="POST">
                <input type="hidden" name="id_registro" value="<?= (int)$id_registro ?>">
                <input type="hidden" name="accion" value="iniciar_visita">
                <button class="btn btn-success fw-bold">
                    <i class="bi bi-plus-circle me-1"></i>Iniciar visita <?= $max_visita + 1 ?>
                </button>
            </form>
            <?php else: ?>
            <div class="alert alert-warning mb-0">Ya se registraron 3 visitas. Debe finalizar la evaluación o solicitar apoyo del administrador.</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ─── Formulario de evaluación ─────────────────────────────── -->
    <form method="POST" id="formEvaluacion">
    <input type="hidden" name="id_registro" value="<?= (int)$id_registro ?>">
    <input type="hidden" name="accion" id="accionForm" value="guardar_progreso">
    <input type="hidden" name="latitud" id="latitudEvaluacion" value="">
    <input type="hidden" name="longitud" id="longitudEvaluacion" value="">
    <input type="hidden" name="precision_metros" id="precisionEvaluacion" value="">

    <?php
    $curRoot = null; $curSub = null;
    foreach ($items as $iid => $it):
        $sec  = $secciones[(int)($it['id_seccion'] ?? 0)] ?? null;
        $root = $sec; $sub = null;
        if ($sec && !empty($sec['id_seccion_padre']) && isset($secciones[(int)$sec['id_seccion_padre']])){
            $sub  = $sec;
            $root = $secciones[(int)$sec['id_seccion_padre']];
        }
        if ($root && $curRoot !== (int)$root['id']){
            $curRoot = (int)$root['id']; $curSub = null;
            echo '<div class="aspect-title">'.h($root['titulo']).'</div>';
        }
        if ($sub && $curSub !== (int)$sub['id']){
            $curSub = (int)$sub['id'];
            echo '<div class="sub-title">'.h($sub['titulo']).'</div>';
        }
        $d       = $respuestasDirector[$iid] ?? null;
        $e       = valorInicialEval($iid, $it['tipo_respuesta'], $respuestasEval, $respuestasDirector);
        $noEval  = (int)($e['no_aplica_eval'] ?? 0) === 1;
        $valEval = $e['respuesta_escala_eval'] ?? '';
        $obsEval = $e['observacion_especialista'] ?? '';
        $noDir   = $d && (int)($d['no_aplica'] ?? 0) === 1;
        $tipo    = $it['tipo_respuesta'] ?? 'ESCALA_1_4';
        $modalId = 'rubrica'.(int)$iid;

        // Respuesta del director según tipo, con chips coloreados
        $sinResp = '<span class="dir-vacio"><i class="bi bi-dash-circle"></i>Sin respuesta</span>';
        if ($noDir){
            $dirDisplay = '<span class="dir-na"><i class="bi bi-slash-circle"></i>NO APLICA</span>';
        } elseif ($tipo === 'ESCALA_1_4'){
            $v = ($d && $d['respuesta_escala'] !== null) ? (int)$d['respuesta_escala'] : 0;
            $lbl = $ruLabels[$v] ?? '';
            $dirDisplay = ($v >= 1 && $v <= 4)
                ? '<span class="dir-level dir-level-'.$v.'"><span class="dir-level-num">'.$v.'</span>'.h($lbl).'</span>'
                : $sinResp;
        } elseif ($tipo === 'NUMERO'){
            $num = ($d && $d['respuesta_numero'] !== null) ? rtrim(rtrim(number_format((float)$d['respuesta_numero'], 2, '.', ''), '0'), '.') : null;
            $dirDisplay = $num !== null ? '<span class="dir-valor"><i class="bi bi-123"></i>'.h($num).'</span>' : $sinResp;
        } elseif ($tipo === 'TEXTO'){
            $dirDisplay = ($d && $d['respuesta_texto']) ? '<div class="dir-valor" style="white-space:normal;text-align:left;">'.h($d['respuesta_texto']).'</div>' : $sinResp;
        } elseif ($tipo === 'SI_NO'){
            $v = $d['respuesta_si_no'] ?? '';
            if ($v === 'SI')      $dirDisplay = '<span class="dir-level dir-level-3"><i class="bi bi-check-circle"></i>SÍ</span>';
            elseif ($v === 'NO')  $dirDisplay = '<span class="dir-level dir-level-1"><i class="bi bi-x-circle"></i>NO</span>';
            else                  $dirDisplay = $sinResp;
        } elseif ($tipo === 'FECHA'){
            $dirDisplay = ($d && $d['respuesta_fecha'])
                ? '<span class="dir-valor"><i class="bi bi-calendar-event"></i>'.h(date('d/m/Y', strtotime($d['respuesta_fecha']))).'</span>'
                : $sinResp;
        } else {
            $dirDisplay = $sinResp;
        }
    ?>
    <div class="item-card item-evaluable" data-item="<?= h($it['numero_item']) ?>" data-id="<?= (int)$iid ?>" data-puntaje="<?= h($it['puntaje_maximo'] ?? 4) ?>">

        <div class="item-head d-flex justify-content-between align-items-start gap-3 flex-wrap">
            <div>
                <strong>Ítem <?= h($it['numero_item']) ?></strong>
                <div class="mt-1 small text-muted"><?= h($it['pregunta']) ?></div>
            </div>
            <?php if (!empty($rubricas[$iid])): ?>
            <button type="button" class="btn btn-sm btn-outline-success flex-shrink-0"
                    data-bs-toggle="modal" data-bs-target="#<?= $modalId ?>">
                <i class="bi bi-info-circle me-1"></i>Rúbrica
            </button>
            <?php endif; ?>
        </div>

        <div class="p-3">
            <div class="row g-3">

                <!-- Respuesta del director -->
                <div class="col-lg-5">
                    <div class="director-box">
                        <h6 class="fw-bold mb-3">
                            <i class="bi bi-person-badge me-1"></i>Respuesta del director/subdirector
                        </h6>
                        <div class="mb-2"><?= $dirDisplay ?></div>
                        <?php if ($d && !$noDir && ($d['observacion'] ?? '')): ?>
                        <div class="small text-muted border-top pt-2 mt-2">
                            <strong>Observación:</strong><br><?= h($d['observacion']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Evaluación del especialista -->
                <div class="col-lg-7">
                    <div class="eval-box">
                        <h6 class="fw-bold mb-2" style="color:var(--ugel-green-dark);">
                            <i class="bi bi-pencil-square me-1"></i>Evaluación del especialista
                        </h6>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input no-aplica-eval" type="checkbox"
                                   name="eval_no_aplica[<?= (int)$iid ?>]" value="1"
                                   <?= $noEval ? 'checked' : '' ?>
                                   <?= !$puede_editar ? 'disabled' : '' ?>>
                            <label class="form-check-label small fw-bold">NO APLICA</label>
                        </div>

                        <?php if ($tipo === 'ESCALA_1_4'): ?>
                        <div class="scale-grid respuesta-eval mb-3">
                            <?php foreach ([1=>'INICIO',2=>'PROCESO',3=>'LOGRADO',4=>'DESTACADO'] as $n=>$lab): ?>
                            <input class="scale-input" type="radio"
                                   id="ei_<?= (int)$iid ?>_<?= $n ?>"
                                   name="eval_respuesta[<?= (int)$iid ?>]"
                                   value="<?= $n ?>"
                                   <?= ((string)$valEval === (string)$n && !$noEval) ? 'checked' : '' ?>
                                   <?= (!$puede_editar || $noEval) ? 'disabled' : '' ?>>
                            <label class="scale-option scale-<?= $n ?>" for="ei_<?= (int)$iid ?>_<?= $n ?>">
                                <span class="scale-number"><?= $n ?></span>
                                <span><?= $lab ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <?php elseif ($tipo === 'NUMERO'): ?>
                        <input type="number" step="0.01" class="form-control mb-3"
                               name="eval_numero[<?= (int)$iid ?>]"
                               value="<?= h($e['respuesta_numero_eval'] ?? '') ?>"
                               <?= (!$puede_editar || $noEval) ? 'disabled' : '' ?>>

                        <?php elseif ($tipo === 'TEXTO'): ?>
                        <textarea class="form-control mb-3" name="eval_texto[<?= (int)$iid ?>]" rows="2"
                                  <?= (!$puede_editar || $noEval) ? 'disabled' : '' ?>><?= h($e['respuesta_texto_eval'] ?? '') ?></textarea>

                        <?php elseif ($tipo === 'SI_NO'):
                            $valSiNo = $e['respuesta_si_no_eval'] ?? '';
                        ?>
                        <div class="d-flex gap-4 mb-3">
                            <?php foreach (['SI' => 'Sí', 'NO' => 'No'] as $val => $lab): ?>
                            <label class="d-flex align-items-center gap-2" style="cursor:pointer;">
                                <input type="radio" name="eval_si_no[<?= (int)$iid ?>]" value="<?= $val ?>"
                                       <?= ((string)$valSiNo === $val && !$noEval) ? 'checked' : '' ?>
                                       <?= (!$puede_editar || $noEval) ? 'disabled' : '' ?>>
                                <span class="fw-bold"><?= $lab ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <?php elseif ($tipo === 'FECHA'):
                            $valFecha = $e['respuesta_fecha_eval'] ?? '';
                        ?>
                        <input type="date" class="form-control mb-3"
                               name="eval_fecha[<?= (int)$iid ?>]"
                               value="<?= h($valFecha) ?>"
                               <?= (!$puede_editar || $noEval) ? 'disabled' : '' ?>>
                        <?php endif; ?>

                        <label class="form-label small fw-bold">Observación por ítem</label>
                        <textarea class="form-control" name="observacion_especialista[<?= (int)$iid ?>]"
                                  rows="2" <?= !$puede_editar ? 'disabled' : '' ?>><?= h($obsEval) ?></textarea>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal rúbrica -->
    <?php if (!empty($rubricas[$iid])): ?>
    <div class="modal fade" id="<?= $modalId ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-green">
                    <h5 class="modal-title"><i class="bi bi-list-check me-2"></i>Rúbrica — Ítem <?= h($it['numero_item']) ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <ul class="rubrica-list list-unstyled">
                        <?php foreach ($rubricas[$iid] as $ru):
                            $ruLbl = $ruLabels[(int)$ru['valor']] ?? '';
                        ?>
                        <li class="pb-3 mb-3 border-bottom">
                            <strong class="d-block mb-1" style="color:var(--ugel-green-dark);"><?= (int)$ru['valor'] ?> — <?= h($ruLbl) ?></strong>
                            <span class="text-muted small"><?= h($ru['descripcion']) ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>

    <!-- Resultado preliminar -->
    <div class="eval-panel my-4">
        <div class="eval-panel-head"><i class="bi bi-bar-chart"></i>Resultado evaluado preliminar</div>
        <div class="eval-panel-body">
            <div class="row g-3 mb-4">
                <div class="col-6 col-md-3">
                    <div class="result-card">
                        <small class="text-muted d-block">Puntaje</small>
                        <h4 class="mb-0"><span id="livePuntaje"><?= number_format($ptPrev, 0) ?></span> <span class="text-muted fw-normal fs-6">/ <span id="liveMaximo"><?= number_format($pmPrev, 0) ?></span></span></h4>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="result-card">
                        <small class="text-muted d-block">Porcentaje</small>
                        <h4 class="mb-0"><span id="livePorcentaje"><?= number_format($porPrev, 2) ?></span>%</h4>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="result-card">
                        <small class="text-muted d-block">Nivel alcanzado</small>
                        <h5 class="mb-0 fw-bold" id="liveNivel" style="color:var(--ugel-green-dark);"><?= h($escPrev) ?></h5>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="result-card">
                        <small class="text-muted d-block">Código evaluación</small>
                        <h6 class="mb-0"><?= h($evaluacion['codigo_evaluacion'] ?? 'Pendiente') ?></h6>
                    </div>
                </div>
            </div>

            <div class="live-chart">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-1">
                    <h6 class="fw-bold mb-0" style="color:var(--ugel-green-dark);">
                        <i class="bi bi-bar-chart-fill me-1"></i>Distribución por nivel
                    </h6>
                    <small class="text-muted">Se actualiza automáticamente con sus respuestas</small>
                </div>
                <div class="bar-wrap">
                    <div class="bar-col">
                        <div class="bar-count" id="countNivel1"><?= (int)($nivPrev[1] ?? 0) ?></div>
                        <div class="bar bar-1" id="barNivel1"></div>
                    </div>
                    <div class="bar-col">
                        <div class="bar-count" id="countNivel2"><?= (int)($nivPrev[2] ?? 0) ?></div>
                        <div class="bar bar-2" id="barNivel2"></div>
                    </div>
                    <div class="bar-col">
                        <div class="bar-count" id="countNivel3"><?= (int)($nivPrev[3] ?? 0) ?></div>
                        <div class="bar bar-3" id="barNivel3"></div>
                    </div>
                    <div class="bar-col">
                        <div class="bar-count" id="countNivel4"><?= (int)($nivPrev[4] ?? 0) ?></div>
                        <div class="bar bar-4" id="barNivel4"></div>
                    </div>
                </div>
                <div class="bar-labels">
                    <div class="bar-label" style="color:var(--ugel-red);">1<br>INICIO</div>
                    <div class="bar-label" style="color:var(--ugel-yellow);">2<br>PROCESO</div>
                    <div class="bar-label" style="color:var(--ugel-green-mid);">3<br>LOGRADO</div>
                    <div class="bar-label" style="color:var(--ugel-blue);">4<br>DESTACADO</div>
                </div>
            </div>

            <div class="mt-4">
                <label class="form-label fw-bold" style="color:var(--ugel-green-dark);">Observación general de la evaluación</label>
                <textarea class="form-control" name="observacion_general" rows="2"
                          <?= !$puede_editar ? 'disabled' : '' ?>><?= h($evaluacion['observacion_general'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Finalización y compromisos -->
    <?php if (!$bloqueado && (($evaluacion['estado_evaluacion'] ?? '') !== 'FINALIZADA')): ?>
    <div class="eval-panel my-4 commit-box <?= (!empty($errores_validacion) && (($_POST['accion'] ?? '') === 'finalizar_evaluacion')) ? 'open' : '' ?>" id="finalizarBox">
        <div class="eval-panel-head"><i class="bi bi-check2-square"></i>Finalización de evaluación y compromisos</div>
        <div class="eval-panel-body">
            <div class="alert alert-warning">
                <strong>Antes de terminar:</strong> verifique las respuestas evaluadas, el puntaje y el nivel alcanzado.
                Al dar por terminada la ficha, el especialista ya no podrá editarla.
            </div>
            <div class="commit-risk-summary" id="commitRiskSummary" hidden>
                <h6><i class="bi bi-exclamation-triangle me-1"></i>Items que requieren compromisos de mejora</h6>
                <ul class="commit-risk-list" id="commitRiskList"></ul>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-bold" style="color:var(--ugel-green-dark);">Compromiso del director/subdirector</label>
                    <textarea class="form-control" name="compromiso_director" rows="5"><?= h($_POST['compromiso_director'] ?? ($evaluacion['compromiso_director'] ?? '')) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-bold" style="color:var(--ugel-green-dark);">Compromiso del especialista</label>
                    <textarea class="form-control" name="compromiso_especialista" rows="5"><?= h($_POST['compromiso_especialista'] ?? ($evaluacion['compromiso_especialista'] ?? '')) ?></textarea>
                </div>
            </div>
            <div class="text-end mt-4">
                <button type="button" class="btn btn-success btn-lg fw-bold" onclick="confirmarFinalizar()">
                    <i class="bi bi-check-circle me-1"></i>Dar por terminada la ficha
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Acciones fijas -->
    <?php if ($puede_editar): ?>
    <div class="sticky-actions d-flex justify-content-end align-items-center gap-2 flex-wrap">
        <span class="autosave-badge me-auto" id="autosaveBadge">
            <i class="bi bi-cloud-check"></i>Autoguardado activado
        </span>
        <button type="button" class="btn btn-outline-success fw-bold" onclick="enviarAccion('guardar_progreso')">
            <i class="bi bi-save me-1"></i>Guardar progreso de visita
        </button>
        <button type="button" class="btn btn-outline-secondary fw-bold" onclick="enviarAccion('cerrar_visita')">
            <i class="bi bi-door-closed me-1"></i>Cerrar visita
        </button>
        <button type="button" class="btn btn-success fw-bold" onclick="mostrarCompromisos()">
            <i class="bi bi-pencil-square me-1"></i>Llenado de compromisos
        </button>
    </div>
    <?php endif; ?>

    </form>
</main>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
function calcularNivel(p){p=parseFloat(p)||0;if(p<=25)return 'INICIO';if(p<=50)return 'PROCESO';if(p<=75)return 'LOGRADO';return 'DESTACADO';}
function actualizarResultado(){let total=0,maximo=0,counts={1:0,2:0,3:0,4:0};document.querySelectorAll('.item-evaluable').forEach(card=>{const no=card.querySelector('.no-aplica-eval');if(no && no.checked)return;const checked=card.querySelector('input[type="radio"]:checked');if(checked){const v=parseInt(checked.value,10);if(v>=1&&v<=4){total+=v;maximo+=parseFloat(card.dataset.puntaje||4);counts[v]++;}}});const porcentaje=maximo>0?(total/maximo*100):0;const liveP=document.getElementById('livePuntaje');if(liveP)liveP.textContent=String(Math.round(total));const liveM=document.getElementById('liveMaximo');if(liveM)liveM.textContent=String(Math.round(maximo));const livePor=document.getElementById('livePorcentaje');if(livePor)livePor.textContent=porcentaje.toFixed(2);const liveNivel=document.getElementById('liveNivel');if(liveNivel)liveNivel.textContent=calcularNivel(porcentaje);let maxCount=Math.max(1,counts[1],counts[2],counts[3],counts[4]);[1,2,3,4].forEach(n=>{const c=document.getElementById('countNivel'+n);const b=document.getElementById('barNivel'+n);if(c)c.textContent=counts[n];if(b)b.style.height=Math.max(6,(counts[n]/maxCount)*110)+'px';});}
function validarCompleto(){let faltantes=[];document.querySelectorAll('.item-evaluable').forEach(card=>{card.classList.remove('item-error');const no=card.querySelector('.no-aplica-eval');const checkedNo=no && no.checked;const radios=card.querySelectorAll('input[type="radio"]');if(!checkedNo && radios.length){let ok=false;radios.forEach(r=>{if(r.checked) ok=true;});if(!ok){faltantes.push(card.dataset.item);card.classList.add('item-error');}}});if(faltantes.length){alert('Falta evaluar el ítem '+faltantes[0]+'.');document.querySelector('.item-error')?.scrollIntoView({behavior:'smooth',block:'center'});return false;}return true;}
function enviarAccion(accion,validar=false){if(validar && !validarCompleto()) return;if(accion==='cerrar_visita' && !confirm('¿Cerrar esta visita? Podrá iniciar una nueva visita después, pero esta visita quedará registrada como cerrada.')) return;document.getElementById('accionForm').value=accion;document.getElementById('formEvaluacion').submit();}
function actualizarDetalleCompromisos(){
    const box = document.getElementById('commitRiskSummary');
    const list = document.getElementById('commitRiskList');
    if (!box || !list) return;
    const bajos = [];
    document.querySelectorAll('.item-evaluable').forEach(card => {
        const no = card.querySelector('.no-aplica-eval');
        if (no && no.checked) return;
        const checked = card.querySelector('input[type="radio"]:checked');
        if (!checked || !['1','2'].includes(checked.value)) return;
        const nivel = checked.value === '1' ? 'INICIO' : 'PROCESO';
        const pregunta = card.querySelector('.item-head .small')?.textContent?.trim() || '';
        bajos.push({ item: card.dataset.item || '', nivel, valor: checked.value, pregunta });
    });
    list.innerHTML = '';
    bajos.forEach(row => {
        const li = document.createElement('li');
        li.className = 'commit-risk-item';
        const badge = document.createElement('span');
        badge.className = 'commit-risk-badge level-' + row.valor;
        badge.textContent = row.valor + ' ' + row.nivel;
        const text = document.createElement('span');
        const strong = document.createElement('strong');
        strong.textContent = 'Ítem ' + row.item + ': ';
        text.appendChild(strong);
        text.appendChild(document.createTextNode(row.pregunta));
        li.appendChild(badge);
        li.appendChild(text);
        list.appendChild(li);
    });
    box.hidden = bajos.length === 0;
}
function mostrarCompromisos(){if(!validarCompleto()) return;actualizarDetalleCompromisos();const box=document.getElementById('finalizarBox');if(box){box.classList.add('open');box.scrollIntoView({behavior:'smooth',block:'start'});}}
function confirmarFinalizar(){if(!validarCompleto()) return;const cd=document.querySelector('textarea[name="compromiso_director"]');const ce=document.querySelector('textarea[name="compromiso_especialista"]');if(!cd||!cd.value.trim()){alert('Debe escribir el compromiso del director/subdirector.');cd?.focus();return;}if(!ce||!ce.value.trim()){alert('Debe escribir el compromiso del especialista.');ce?.focus();return;}if(!confirm('¿Dar por terminada la ficha? Esta acción no podrá deshacerse desde el perfil especialista.')) return;document.getElementById('accionForm').value='finalizar_evaluacion';document.getElementById('formEvaluacion').submit();}
const latEvalInput = document.getElementById('latitudEvaluacion');
const lngEvalInput = document.getElementById('longitudEvaluacion');
const accEvalInput = document.getElementById('precisionEvaluacion');

const obtenerIPEval = () => {
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
            if (lat && lng && latEvalInput && lngEvalInput) {
                latEvalInput.value = parseFloat(lat).toFixed(8);
                lngEvalInput.value = parseFloat(lng).toFixed(8);
                if (accEvalInput) accEvalInput.value = '50000.00';
            }
        }).catch(() => {});
};

if (latEvalInput && lngEvalInput) {
    if (window.isSecureContext && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => {
            latEvalInput.value = pos.coords.latitude.toFixed(8);
            lngEvalInput.value = pos.coords.longitude.toFixed(8);
            if (accEvalInput && Number.isFinite(pos.coords.accuracy)) {
                accEvalInput.value = pos.coords.accuracy.toFixed(2);
            }
        }, () => {
            obtenerIPEval();
        }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 300000 });
    } else {
        obtenerIPEval();
    }
}
document.querySelectorAll('.no-aplica-eval').forEach(chk=>{chk.addEventListener('change',()=>{const card=chk.closest('.item-evaluable');card.querySelectorAll('.scale-input,input[type="number"],textarea[name^="eval_texto"],input[type="date"],input[name^="eval_si_no"]').forEach(el=>{el.disabled=chk.checked;if(chk.checked && el.type==='radio') el.checked=false;});actualizarResultado();actualizarDetalleCompromisos();});});
document.querySelectorAll('.item-evaluable .scale-input').forEach(r=>r.addEventListener('change',()=>{actualizarResultado();actualizarDetalleCompromisos();}));
document.addEventListener('DOMContentLoaded',actualizarResultado);

<?php if ($puede_editar): ?>
/* ── Autoguardado ─────────────────────────────────────────────
   Tras 3 s sin cambios envía el formulario vía fetch con
   accion=autoguardar; el servidor guarda y responde JSON. */
(function(){
    let autoTimer = null;
    let enviando  = false;
    const badge = document.getElementById('autosaveBadge');
    const form  = document.getElementById('formEvaluacion');

    function setBadge(texto, icono, clase){
        if (!badge) return;
        badge.className = 'autosave-badge me-auto ' + (clase || '');
        badge.innerHTML = '<i class="bi ' + icono + '"></i>' + texto;
    }

    function autoGuardar(){
        if (enviando) { programar(); return; }
        enviando = true;
        setBadge('Guardando…', 'bi-arrow-repeat', 'saving');
        const fd = new FormData(form);
        fd.set('accion', 'autoguardar');
        fetch('evaluar_ficha.php?id=<?= (int)$id_registro ?>', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(j => {
                if (j && j.ok) setBadge('Guardado automáticamente a las ' + j.hora, 'bi-cloud-check', 'ok');
                else           setBadge('No se pudo autoguardar', 'bi-cloud-slash', 'err');
            })
            .catch(() => setBadge('Sin conexión — cambios sin guardar', 'bi-cloud-slash', 'err'))
            .finally(() => { enviando = false; });
    }

    function programar(){
        clearTimeout(autoTimer);
        autoTimer = setTimeout(() => { autoTimer = null; autoGuardar(); }, 3000);
    }

    form.addEventListener('change', programar);
    form.addEventListener('input', e => {
        if (e.target.matches('textarea, input[type="number"], input[type="date"]')) programar();
    });

    // Si el usuario sale con cambios pendientes, intenta un último guardado
    window.addEventListener('pagehide', () => {
        if (!autoTimer) return;
        clearTimeout(autoTimer);
        autoTimer = null;
        const fd = new FormData(form);
        fd.set('accion', 'autoguardar');
        navigator.sendBeacon('evaluar_ficha.php?id=<?= (int)$id_registro ?>', fd);
    });

    // Al enviar el formulario manualmente, cancela el autoguardado pendiente
    form.addEventListener('submit', () => { clearTimeout(autoTimer); autoTimer = null; });
})();
<?php endif; ?>
</script>
</body>
</html>
