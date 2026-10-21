<?php
session_start();
require_once 'conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['id_usuario'])) {
    echo json_encode(['ok'=>false,'message'=>'Sesión expirada. Vuelve a iniciar sesión.']); exit;
}
if (($_SESSION['rol'] ?? '') !== 'jefe_agp') {
    echo json_encode(['ok'=>false,'message'=>'No autorizado.']); exit;
}

$id_usuario = (int)$_SESSION['id_usuario'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$id_version = (int)($_POST['id_version'] ?? $_GET['id_version'] ?? 0);

if ($action === 'get_especialista_asignaciones') {
    $id_usuario_esp = (int)($_POST['id_usuario'] ?? $_GET['id_usuario'] ?? 0);
    $rows = [];
    $st = $conn->prepare("
        SELECT ae.id, ae.sub_tipo, ae.fecha_inicio, i.nombre_ie, i.codigo_local, i.distrito
        FROM asignaciones_especialistas ae
        JOIN instituciones i ON i.id = ae.id_institucion
        WHERE ae.id_usuario = ? AND ae.estado = 'activo'
        ORDER BY i.nombre_ie ASC
    ");
    $st->bind_param('i', $id_usuario_esp); $st->execute(); $res = $st->get_result();
    while ($r = $res->fetch_assoc()) {
        $rows[] = $r;
    }
    $st->close();
    json_out($rows);
}

function json_out($arr){ echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function table_has_column($conn, $table, $column){
    $stmt=$conn->prepare("SELECT COUNT(*) c FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    $stmt->bind_param('ss',$table,$column); $stmt->execute(); $ok=((int)$stmt->get_result()->fetch_assoc()['c'])>0; $stmt->close(); return $ok;
}
function audit_fm_ajax($conn,$tabla,$id,$accion,$descripcion,$usuario,$antes=null,$nuevos=null){
    $ip=$_SERVER['REMOTE_ADDR']??''; $ua=$_SERVER['HTTP_USER_AGENT']??'';
    $a=$antes?json_encode($antes,JSON_UNESCAPED_UNICODE):null;
    $n=$nuevos?json_encode($nuevos,JSON_UNESCAPED_UNICODE):null;
    $stmt=$conn->prepare("INSERT INTO fm_auditoria (tabla_afectada,id_registro_afectado,accion,descripcion,datos_anteriores,datos_nuevos,id_usuario,ip_usuario,user_agent) VALUES (?,?,?,?,?,?,?,?,?)");
    if($stmt){ $stmt->bind_param('sissssiss',$tabla,$id,$accion,$descripcion,$a,$n,$usuario,$ip,$ua); $stmt->execute(); $stmt->close(); }
}
function get_context($conn,$id_version){
    $hasPlan = table_has_column($conn,'fm_tipos_ficha_versiones','total_items_planificados');
    $planFields = $hasPlan ? ", v.total_items_planificados, v.estructura_configurada" : ", NULL AS total_items_planificados, 0 AS estructura_configurada";
    $sql="SELECT v.* $planFields, tf.id AS id_tipo_ficha, tf.nombre, tf.anio, tf.estado AS estado_tipo,
        (SELECT COUNT(*) FROM fm_registros r WHERE r.id_version_ficha=v.id OR r.id_tipo_ficha=tf.id) AS total_respuestas,
        (SELECT COUNT(*) FROM fm_items i WHERE i.id_version_ficha=v.id AND i.estado='ACTIVO') AS total_items
        FROM fm_tipos_ficha_versiones v JOIN fm_tipos_ficha tf ON tf.id=v.id_tipo_ficha WHERE v.id=? LIMIT 1";
    $stmt=$conn->prepare($sql); $stmt->bind_param('i',$id_version); $stmt->execute(); $ctx=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $ctx;
}
function is_locked($ctx){ return !$ctx || (int)$ctx['total_respuestas']>0 || ($ctx['estado'] ?? '')==='PUBLICADA'; }
function next_item_number($conn,$id_version){
    $stmt=$conn->prepare("SELECT COALESCE(MAX(CAST(numero_item AS UNSIGNED)),0)+1 AS n FROM fm_items WHERE id_version_ficha=?");
    $stmt->bind_param('i',$id_version); $stmt->execute(); $n=(int)($stmt->get_result()->fetch_assoc()['n'] ?? 1); $stmt->close(); return max(1,$n);
}
function sections_flat($conn,$id_version){
    $rows=[];
    $stmt=$conn->prepare("SELECT s.*, p.titulo AS padre FROM fm_secciones s LEFT JOIN fm_secciones p ON p.id=s.id_seccion_padre WHERE s.id_version_ficha=? ORDER BY COALESCE(s.id_seccion_padre,0), s.orden, s.id");
    $stmt->bind_param('i',$id_version); $stmt->execute(); $res=$stmt->get_result(); while($r=$res->fetch_assoc()) $rows[]=$r; $stmt->close(); return $rows;
}
function render_editor_html($conn,$id_version){
    $ctx=get_context($conn,$id_version);
    $totalPlan=(int)($ctx['total_items_planificados'] ?? 0);
    $totalItems=(int)($ctx['total_items'] ?? 0);
    $sections=sections_flat($conn,$id_version);
    ob_start();
    ?>
    <div class="row g-3">
      <div class="col-lg-4">
        <div class="card-soft h-100">
          <div class="card-head"><i class="bi bi-diagram-3 me-2"></i>Estructura creada</div>
          <div class="p-3">
            <?php if(empty($sections)): ?>
              <div class="text-muted small">Aún no hay secciones. Puedes tener una ficha sin secciones, pero el modo guiado permite definirlas antes de crear ítems.</div>
            <?php else: ?>
              <?php foreach($sections as $s): ?>
                <div class="structure-line <?= $s['id_seccion_padre'] ? 'sub' : '' ?>">
                  <span class="badge <?= $s['id_seccion_padre'] ? 'bg-light text-success border' : 'bg-success' ?>"><?= $s['id_seccion_padre'] ? 'Subgrupo' : 'Sección' ?></span>
                  <strong><?= h($s['titulo']) ?></strong>
                  <?php if($s['padre']): ?><div class="small text-muted">Dentro de: <?= h($s['padre']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="card-soft">
          <div class="card-head d-flex justify-content-between align-items-center">
            <span><i class="bi bi-list-check me-2"></i>Ítems de la ficha</span>
            <span class="badge bg-light text-success">Creado: <?= $totalItems ?><?= $totalPlan ? ' / '.$totalPlan : '' ?></span>
          </div>
          <div class="p-3">
            <?php
            $sql="SELECT i.*, s.titulo AS seccion, sp.titulo AS seccion_padre
                  FROM fm_items i
                  LEFT JOIN fm_secciones s ON s.id=i.id_seccion
                  LEFT JOIN fm_secciones sp ON sp.id=s.id_seccion_padre
                  WHERE i.id_version_ficha=? AND i.estado='ACTIVO'
                  ORDER BY CAST(i.numero_item AS UNSIGNED), i.orden, i.id";
            $stmt=$conn->prepare($sql); $stmt->bind_param('i',$id_version); $stmt->execute(); $items=$stmt->get_result();
            if($items->num_rows===0): ?>
              <div class="empty-state"><i class="bi bi-clipboard-plus"></i><p class="mb-0">Aún no hay ítems. Usa el panel guiado para crear el ítem 1.</p></div>
            <?php else: while($it=$items->fetch_assoc()): ?>
              <div class="item-card">
                <div class="d-flex justify-content-between gap-2 flex-wrap">
                  <div>
                    <span class="item-num">Ítem <?= h($it['numero_item']) ?></span>
                    <span class="badge bg-success-subtle text-success border ms-1"><?= h($it['tipo_respuesta']) ?></span>
                    <?php if($it['id_seccion']): ?>
                      <span class="badge bg-light text-dark border ms-1"><?= h($it['seccion_padre'] ? $it['seccion_padre'].' / '.$it['seccion'] : $it['seccion']) ?></span>
                    <?php else: ?>
                      <span class="badge bg-light text-secondary border ms-1">Sin sección</span>
                    <?php endif; ?>
                  </div>
                  <div class="small text-muted">Puntaje máx.: <?= number_format((float)$it['puntaje_maximo'],0) ?></div>
                </div>
                <div class="mt-2 fw-semibold"><?= nl2br(h($it['pregunta'])) ?></div>
              </div>
            <?php endwhile; endif; $stmt->close(); ?>
          </div>
        </div>
      </div>
    </div>
    <?php
    return ob_get_clean();
}

if($id_version<=0) json_out(['ok'=>false,'message'=>'Versión no válida.']);
$ctx=get_context($conn,$id_version);
if(!$ctx) json_out(['ok'=>false,'message'=>'No se encontró la ficha.']);
if(is_locked($ctx) && !in_array($action,['render'],true)) json_out(['ok'=>false,'message'=>'Esta versión ya fue publicada o tiene respuestas. Crea una nueva versión para modificarla.']);
if(!table_has_column($conn,'fm_tipos_ficha_versiones','total_items_planificados') && !in_array($action,['render'],true)){
    json_out(['ok'=>false,'message'=>'Falta ejecutar el SQL de actualización del editor guiado. Importa primero actualizar_editor_guiado.sql.']);
}

try{
    if($action==='render'){
        json_out(['ok'=>true,'html'=>render_editor_html($conn,$id_version),'ctx'=>$ctx,'sections'=>sections_flat($conn,$id_version)]);
    }

    if($action==='create_structure'){
        $totalItems=(int)($_POST['total_items'] ?? 0);
        $sections=json_decode($_POST['sections_json'] ?? '[]', true);
        if($totalItems<=0) throw new Exception('Indica cuántos ítems tendrá la ficha.');
        if($totalItems>250) throw new Exception('El máximo permitido es 250 ítems por ficha.');
        if(!is_array($sections)) $sections=[];

        $stmt=$conn->prepare("SELECT COUNT(*) c FROM fm_items WHERE id_version_ficha=?");
        $stmt->bind_param('i',$id_version); $stmt->execute(); $itemsExist=(int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
        if($itemsExist>0) throw new Exception('Ya existen ítems creados. No se puede reiniciar la estructura desde aquí.');

        $conn->begin_transaction();
        $stmt=$conn->prepare("DELETE FROM fm_secciones WHERE id_version_ficha=?");
        $stmt->bind_param('i',$id_version); $stmt->execute(); $stmt->close();

        $ordenSec=1;
        foreach($sections as $sec){
            $titulo=trim($sec['titulo'] ?? '');
            if($titulo==='') continue;
            $desc=''; $nivel=1; $estado='ACTIVO'; $padre=null;
            $stmt=$conn->prepare("INSERT INTO fm_secciones (id_version_ficha,id_seccion_padre,titulo,descripcion,orden,nivel,estado) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('iisssis',$id_version,$padre,$titulo,$desc,$ordenSec,$nivel,$estado);
            $stmt->execute(); $idSec=$stmt->insert_id; $stmt->close();
            $ordenSub=1;
            foreach(($sec['subgrupos'] ?? []) as $sub){
                $tSub=trim($sub ?? ''); if($tSub==='') continue;
                $nivel=2; $stmt=$conn->prepare("INSERT INTO fm_secciones (id_version_ficha,id_seccion_padre,titulo,descripcion,orden,nivel,estado) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('iisssis',$id_version,$idSec,$tSub,$desc,$ordenSub,$nivel,$estado);
                $stmt->execute(); $stmt->close(); $ordenSub++;
            }
            $ordenSec++;
        }
        $stmt=$conn->prepare("UPDATE fm_tipos_ficha_versiones SET total_items_planificados=?, estructura_configurada=1 WHERE id=?");
        $stmt->bind_param('ii',$totalItems,$id_version); $stmt->execute(); $stmt->close();
        audit_fm_ajax($conn,'fm_tipos_ficha_versiones',$id_version,'CONFIGURAR','Configuró estructura guiada de ficha.',$id_usuario,null,['total_items'=>$totalItems,'secciones'=>$sections]);
        $conn->commit();
        json_out(['ok'=>true,'message'=>'Estructura inicial creada correctamente.','html'=>render_editor_html($conn,$id_version),'sections'=>sections_flat($conn,$id_version)]);
    }

    if($action==='add_item'){
        $ctx=get_context($conn,$id_version);
        if((int)($ctx['estructura_configurada'] ?? 0)!==1) throw new Exception('Primero configura la estructura guiada.');
        $totalPlan=(int)($ctx['total_items_planificados'] ?? 0);
        $stmt=$conn->prepare("SELECT COUNT(*) c FROM fm_items WHERE id_version_ficha=?");
        $stmt->bind_param('i',$id_version); $stmt->execute(); $totalActual=(int)$stmt->get_result()->fetch_assoc()['c']; $stmt->close();
        if($totalPlan>0 && $totalActual >= $totalPlan) throw new Exception('Ya se crearon todos los ítems planificados.');

        $pregunta=trim($_POST['pregunta'] ?? '');
        $tipo=$_POST['tipo_respuesta'] ?? 'ESCALA_1_4';
        $valid=['ESCALA_1_4','NUMERO','TEXTO','SI_NO','FECHA'];
        if(!in_array($tipo,$valid,true)) $tipo='ESCALA_1_4';
        if($pregunta==='') throw new Exception('Escribe el texto del ítem.');
        $id_seccion_raw=$_POST['id_seccion'] ?? '';
        $id_seccion=($id_seccion_raw==='' ? null : (int)$id_seccion_raw);
        $permite=isset($_POST['permite_no_aplica']) ? 1 : 0;
        $obligatorio=1;
        $puntaje=($tipo==='ESCALA_1_4') ? 4.00 : 0.00;
        $criterio=trim($_POST['criterio_instruccion'] ?? '');
        $numero=(string)next_item_number($conn,$id_version);
        $orden=(int)$numero;
        $titulo='Ítem '.$numero;
        $estado='ACTIVO';

        $conn->begin_transaction();
        $stmt=$conn->prepare("INSERT INTO fm_items (id_version_ficha,id_seccion,numero_item,orden,titulo,pregunta,tipo_respuesta,obligatorio,permite_no_aplica,puntaje_maximo,criterio_instruccion,estado) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('iisisssiidss',$id_version,$id_seccion,$numero,$orden,$titulo,$pregunta,$tipo,$obligatorio,$permite,$puntaje,$criterio,$estado);
        $stmt->execute(); $id_item=$stmt->insert_id; $stmt->close();
        if($tipo==='ESCALA_1_4'){
            for($v=1;$v<=4;$v++){
                $d=trim($_POST['rubrica_'.$v] ?? '');
                if($d==='') $d = ['1'=>'INICIO','2'=>'PROCESO','3'=>'LOGRADO','4'=>'DESTACADO'][(string)$v] ?? '';
                $sv=(string)$v;
                $stmt=$conn->prepare("INSERT INTO fm_rubricas (id_item,valor,descripcion) VALUES (?,?,?) ON DUPLICATE KEY UPDATE descripcion=VALUES(descripcion)");
                $stmt->bind_param('iss',$id_item,$sv,$d); $stmt->execute(); $stmt->close();
            }
        } else if($criterio!==''){
            $valor=null;
            $stmt=$conn->prepare("INSERT INTO fm_rubricas (id_item,valor,descripcion) VALUES (?,?,?)");
            $stmt->bind_param('iss',$id_item,$valor,$criterio); $stmt->execute(); $stmt->close();
        }
        audit_fm_ajax($conn,'fm_items',$id_item,'CREAR','Creó ítem desde editor guiado.',$id_usuario,null,['numero'=>$numero,'tipo'=>$tipo]);
        $conn->commit();
        json_out(['ok'=>true,'message'=>'Ítem '.$numero.' creado correctamente.','html'=>render_editor_html($conn,$id_version),'ctx'=>get_context($conn,$id_version),'sections'=>sections_flat($conn,$id_version)]);
    }

    json_out(['ok'=>false,'message'=>'Acción no reconocida.']);
} catch(Exception $e){
    if($conn->errno===0){ /* nada */ }
    @$conn->rollback();
    json_out(['ok'=>false,'message'=>$e->getMessage()]);
}
