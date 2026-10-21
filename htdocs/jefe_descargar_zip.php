<?php
session_start(); require_once 'conexion.php'; require_once 'jefe_agp_helpers.php'; jefe_require_role();
$categoria=$_GET['categoria'] ?? ''; $doc=$_GET['doc'] ?? '';
$iesSel=jefe_arr('ies'); $tiposSel=jefe_arr('tipos'); $distSel=jefe_arr('distritos'); $espSel=jefe_arr('especialistas'); $estadoSel=jefe_arr('estados'); $desde=trim($_GET['desde']??''); $hasta=trim($_GET['hasta']??'');
$where=[]; $params=[]; $types=''; $files=[];
if($categoria==='autoevaluacion'){
    $where=["r.fecha_envio IS NOT NULL", "r.estado IN ('ENVIADO','CONFORME','EN_REVISION','OBSERVADO','RECHAZADO','REVISADO')"];
    jefe_add_in($where,$params,$types,'i.id',$iesSel,'i'); jefe_add_in($where,$params,$types,'tf.id',$tiposSel,'i'); jefe_add_in($where,$params,$types,'i.distrito',$distSel,'s'); jefe_add_in($where,$params,$types,'r.id_especialista',$espSel,'i'); jefe_add_in($where,$params,$types,'r.estado',$estadoSel,'s');
    if($desde!==''){ $where[]="DATE(r.fecha_envio)>=?"; $params[]=$desde; $types.='s'; }
    if($hasta!==''){ $where[]="DATE(r.fecha_envio)<=?"; $params[]=$hasta; $types.='s'; }
    $tipoDoc = $doc==='constancia' ? 'CONSTANCIA_ENVIO_DIRECTOR' : 'PDF_FICHA_DIRECTOR';
    $sql="SELECT d.ruta_archivo ruta, CONCAT(REPLACE(IFNULL(i.codigo_local,'IE'),' ','_'),'_',REPLACE(r.codigo_envio,'/','-'),'_',d.tipo_documento,'.pdf') nombre
          FROM fm_registros r JOIN instituciones i ON i.id=r.id_institucion JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha
          JOIN fm_documentos d ON d.id_registro=r.id AND d.tipo_documento=?
          WHERE ".implode(' AND ',$where)." ORDER BY r.fecha_envio DESC";
    array_unshift($params,$tipoDoc); $types='s'.$types;
    $st=$conn->prepare($sql); $st->bind_param($types,...$params); $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()) $files[]=$r;
    jefe_zip_download($files, ($doc==='constancia'?'constancias_autoevaluacion_':'autoevaluaciones_directores_').date('Ymd_His').'.zip');
}
if($categoria==='evaluacion'){
    $where=["e.estado_evaluacion='FINALIZADA'"];
    jefe_add_in($where,$params,$types,'i.id',$iesSel,'i'); jefe_add_in($where,$params,$types,'tf.id',$tiposSel,'i'); jefe_add_in($where,$params,$types,'i.distrito',$distSel,'s'); jefe_add_in($where,$params,$types,'e.id_especialista',$espSel,'i'); jefe_add_in($where,$params,$types,'r.estado',$estadoSel,'s');
    if($desde!==''){ $where[]="DATE(e.fecha_finalizacion)>=?"; $params[]=$desde; $types.='s'; }
    if($hasta!==''){ $where[]="DATE(e.fecha_finalizacion)<=?"; $params[]=$hasta; $types.='s'; }
    $tipoDoc = $doc==='constancia' ? 'CONSTANCIA_EVALUACION' : 'PDF_FICHA_EVALUADA';
    $sql="SELECT d.ruta_archivo ruta, CONCAT(REPLACE(IFNULL(i.codigo_local,'IE'),' ','_'),'_',REPLACE(IFNULL(e.codigo_evaluacion,r.codigo_envio),'/','-'),'_',d.tipo_documento,'.pdf') nombre
          FROM fm_evaluaciones e JOIN fm_registros r ON r.id=e.id_registro JOIN instituciones i ON i.id=r.id_institucion JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha
          JOIN fm_documentos d ON d.id_evaluacion=e.id AND d.tipo_documento=?
          WHERE ".implode(' AND ',$where)." ORDER BY e.fecha_finalizacion DESC";
    array_unshift($params,$tipoDoc); $types='s'.$types;
    $st=$conn->prepare($sql); $st->bind_param($types,...$params); $st->execute(); $res=$st->get_result(); while($r=$res->fetch_assoc()) $files[]=$r;
    jefe_zip_download($files, ($doc==='constancia'?'constancias_evaluacion_':'evaluaciones_especialistas_').date('Ymd_His').'.zip');
}
http_response_code(400); echo 'Parámetros de descarga no válidos.';
