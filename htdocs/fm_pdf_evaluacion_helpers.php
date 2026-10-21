<?php
require_once __DIR__ . '/fm_pdf_helpers.php';

function fm_pdf_eval_data(mysqli $conn, int $id_evaluacion): array {
    $sql = "SELECT e.*, r.codigo_envio, r.id AS id_registro, r.id_version_ficha, r.fecha_envio, r.fecha_borrador,
                   tf.nombre AS tipo_ficha_nombre, tf.anio,
                   i.nombre_ie, i.codigo_local, i.distrito,
                   u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni, u.correo AS dir_correo, u.telefono AS dir_telefono,
                   esp.nombres AS esp_nombres, esp.ape_pat AS esp_ape_pat, esp.ape_mat AS esp_ape_mat, esp.dni AS esp_dni
            FROM fm_evaluaciones e
            INNER JOIN fm_registros r ON r.id=e.id_registro
            INNER JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha
            INNER JOIN instituciones i ON i.id=r.id_institucion
            INNER JOIN usuarios u ON u.id=r.id_usuario
            LEFT JOIN usuarios esp ON esp.id=e.id_especialista
            WHERE e.id=? LIMIT 1";
    $st=$conn->prepare($sql); $st->bind_param('i',$id_evaluacion); $st->execute(); $eval=$st->get_result()->fetch_assoc(); $st->close();
    if(!$eval) throw new Exception('No se encontró la evaluación.');
    $vid=(int)$eval['id_version_ficha'];
    $secs=[]; $rs=$conn->query("SELECT * FROM fm_secciones WHERE id_version_ficha=$vid AND estado='ACTIVO' ORDER BY nivel,orden,id"); while($r=$rs->fetch_assoc()) $secs[(int)$r['id']]=$r;
    $items=[]; $rs=$conn->query("SELECT * FROM fm_items WHERE id_version_ficha=$vid AND estado='ACTIVO' ORDER BY orden,id"); while($r=$rs->fetch_assoc()) $items[(int)$r['id']]=$r;
    $respDir=[]; $rid=(int)$eval['id_registro']; $rs=$conn->query("SELECT * FROM fm_respuestas_director WHERE id_registro=$rid"); while($r=$rs->fetch_assoc()) $respDir[(int)$r['id_item']]=$r;
    $respEval=[]; $rs=$conn->query("SELECT * FROM fm_respuestas_especialista WHERE id_evaluacion=$id_evaluacion"); while($r=$rs->fetch_assoc()) $respEval[(int)$r['id_item']]=$r;
    return [$eval,$secs,$items,$respDir,$respEval];
}

function fm_pdf_save_doc_eval(mysqli $conn, int $id_registro, int $id_evaluacion, string $tipo, string $cod, string $ruta, int $user): void {
    $st=$conn->prepare("DELETE FROM fm_documentos WHERE id_evaluacion=? AND tipo_documento=?");
    $st->bind_param('is',$id_evaluacion,$tipo); $st->execute(); $st->close();
    $st=$conn->prepare("INSERT INTO fm_documentos(id_registro,id_evaluacion,tipo_documento,codigo_documento,ruta_archivo,generado_por,fecha_generacion) VALUES(?,?,?,?,?,?,NOW())");
    $st->bind_param('iisssi',$id_registro,$id_evaluacion,$tipo,$cod,$ruta,$user); $st->execute(); $st->close();
}

function fm_generar_pdf_constancia_evaluacion(mysqli $conn, int $id_evaluacion, int $user): string {
    [$e] = fm_pdf_eval_data($conn,$id_evaluacion);
    $autoload = __DIR__ . '/vendor/autoload.php';
    if(!file_exists($autoload)) throw new Exception('No se encontró vendor/autoload.php. Ejecuta composer require dompdf/dompdf.');
    require_once $autoload;
    $codigo = $e['codigo_evaluacion'];
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:20mm}body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937}.box{border:2px solid #0f5b2d;border-radius:16px;padding:40px;text-align:center;margin-top:90px}.title{font-size:22px;font-weight:800;color:#0f5b2d;margin-bottom:20px}.code{font-size:24px;font-weight:900;border:1px solid #9ccaa8;background:#eef8f1;padding:18px;border-radius:12px;display:inline-block}.small{margin-top:22px;color:#475569}</style></head><body><div class="box"><div class="title">CONSTANCIA DE EVALUACIÓN</div><div class="code">'.fm_h($codigo).'</div><div class="small">Ficha evaluada: '.fm_h($e['tipo_ficha_nombre']).'<br>Institución Educativa: '.fm_h($e['nombre_ie']).'</div></div></body></html>';
    $dompdf = new \Dompdf\Dompdf(); $dompdf->loadHtml($html,'UTF-8'); $dompdf->setPaper('A4','portrait'); $dompdf->render();
    [$d,$rel]=fm_pdf_dir(); $safe=preg_replace('/[^A-Za-z0-9_-]/','_',$codigo); $file="constancia_eval_$safe.pdf"; file_put_contents($d.$file,$dompdf->output());
    fm_pdf_save_doc_eval($conn,(int)$e['id_registro'],$id_evaluacion,'CONSTANCIA_EVALUACION','CONST-'.$codigo,$rel.$file,$user);
    return $rel.$file;
}

function fm_generar_pdf_ficha_evaluada(mysqli $conn, int $id_evaluacion, int $user): string {
    [$e,$secs,$items,$respDir,$respEval] = fm_pdf_eval_data($conn,$id_evaluacion);
    $por=(float)($e['porcentaje_eval']??0); $val=$e['escala_valorativa_eval'] ?: fm_pdf_valoracion($por);
    $niv=[1=>0,2=>0,3=>0,4=>0]; $asp=[];
    foreach($items as $iid=>$it){
        $re=$respEval[$iid]??null;
        if($re && !(int)$re['no_aplica'] && $it['tipo_respuesta']==='ESCALA_1_4'){$v=(int)$re['respuesta_escala']; if($v>=1&&$v<=4)$niv[$v]++;}
        $sid=(int)($it['id_seccion']??0); $root=null; while($sid&&isset($secs[$sid])){$root=$secs[$sid];$sid=(int)($secs[$sid]['id_seccion_padre']??0);} 
        if($root){$k=stripos($root['titulo'],'DESARROLLO')!==false?'aspecto_1':(stripos($root['titulo'],'CONDICIONES')!==false?'aspecto_2':'aspecto_'.$root['id']); if(!isset($asp[$k]))$asp[$k]=['puntaje'=>0,'maximo'=>0,'porcentaje'=>0]; if($re&&!(int)$re['no_aplica']){$asp[$k]['puntaje']+=(int)$re['respuesta_escala'];$asp[$k]['maximo']+=(float)$it['puntaje_maximo'];}}
    }
    foreach($asp as $k=>$a)$asp[$k]['porcentaje']=$a['maximo']>0?round($a['puntaje']/$a['maximo']*100,2):0;
    $ana=fm_pdf_observaciones($niv,$por,$asp);
    $autoload = __DIR__ . '/vendor/autoload.php'; if(!file_exists($autoload)) throw new Exception('No se encontró vendor/autoload.php.'); require_once $autoload;
    $chart = fm_svg_bar_chart($niv); $rowsHtml=''; $curRoot=null; $curSub=null;
    foreach($items as $iid=>$it){
        $sec=$secs[(int)($it['id_seccion']??0)]??null; $root=$sec; $sub=null;
        if($sec&&!empty($sec['id_seccion_padre'])&&isset($secs[(int)$sec['id_seccion_padre']])){$sub=$sec;$root=$secs[(int)$sec['id_seccion_padre']];}
        if($root && $curRoot!=(int)$root['id']){$curRoot=(int)$root['id']; $curSub=null; $rowsHtml.='<tr class="aspect-row"><td colspan="8">'.fm_h($root['titulo']).'</td></tr>';}
        if($sub && $curSub!=(int)$sub['id']){$curSub=(int)$sub['id']; $rowsHtml.='<tr class="sub-row"><td colspan="8">'.fm_h($sub['titulo']).'</td></tr>';}
        $rd=$respDir[$iid]??null; $re=$respEval[$iid]??null; $no=$re&&(int)$re['no_aplica']===1; $v=$re&&!$no?(int)($re['respuesta_escala']??0):0;
        $dirText=$rd?(((int)$rd['no_aplica']===1)?'NO APLICA':($rd['respuesta_escala']??'')):'-';
        $obs=$re?($re['observacion']??''):''; if($no)$obs=trim('NO APLICA. '.$obs);
        $rowsHtml.='<tr><td class="num">'.fm_h($it['numero_item']).'</td><td class="item-text">'.fm_h($it['pregunta']).'</td><td class="dir">'.fm_h($dirText).'</td>';
        foreach([1,2,3,4] as $n){$rowsHtml.='<td class="level '.($v===$n?'checked level-'.$n:'').'">'.($v===$n?'X':'').'</td>';}
        $rowsHtml.='<td class="obs">'.fm_h($obs).'</td></tr>';
    }
    $aspectRows=''; foreach($asp as $key=>$a){$nombre=$key==='aspecto_1'?'Aspecto 01: Desarrollo integral de las y los estudiantes':($key==='aspecto_2'?'Aspecto 02: Gestión de las condiciones operativas':strtoupper($key));$aspectRows.='<tr><td>'.fm_h($nombre).'</td><td>'.number_format((float)$a['maximo'],0).'</td><td>'.number_format((float)$a['puntaje'],0).'</td><td>'.number_format((float)$a['porcentaje'],2).'%</td></tr>';}
    $fechaEvaluacion = !empty($e['fecha_finalizacion']) ? fm_pdf_fecha($e['fecha_finalizacion']) : (!empty($e['fecha_inicio']) ? fm_pdf_fecha($e['fecha_inicio']) : fm_pdf_fecha($e['fecha_envio'] ?? null));
    $horaInicioEval = fm_pdf_hora($e['fecha_inicio'] ?? null);
    $horaFinEval = fm_pdf_hora($e['fecha_finalizacion'] ?? null);
    $html='<!doctype html><html><head><meta charset="utf-8"><style>@page{margin:18mm 12mm 16mm 12mm}body{font-family:DejaVu Sans,Arial,sans-serif;color:#1f2937;font-size:10px;line-height:1.33}.header{border:1px solid #c9ded0;background:#eef8f1;padding:10px 14px;border-radius:10px;text-align:center;margin-bottom:12px}.inst{font-size:10px;font-weight:700;color:#14532d}.title{font-size:13px;font-weight:800;text-align:center;color:#0f3f24;text-transform:uppercase;margin:8px 10px 4px;line-height:1.35}.code{display:inline-block;margin-top:7px;padding:5px 10px;border:1px solid #80b28d;border-radius:7px;background:#fff;font-weight:800;color:#14532d}.section-title{background:#0f5b2d;color:#fff;padding:6px 9px;font-weight:800;text-transform:uppercase;border-radius:6px 6px 0 0;margin-top:10px}table{width:100%;border-collapse:collapse;table-layout:fixed;page-break-inside:auto}tr{page-break-inside:avoid}th,td{border:1px solid #cbd5cf;padding:5px 6px;vertical-align:top;overflow-wrap:break-word;word-wrap:break-word}th{background:#eaf4ee;color:#123d25;font-weight:800;text-align:center}.info td:nth-child(odd){background:#f5faf7;font-weight:800;color:#17442a;width:24%}.items{font-size:7.6px}.items .num{width:4%;text-align:center;font-weight:800}.items .item-text{width:62%}.items .dir{width:7%;text-align:center}.items .level{width:3.5%;text-align:center;font-weight:900}.items .obs{width:13%;font-size:7.1px}.checked{color:#fff}.level-1{background:#dc3545}.level-2{background:#ffc107;color:#111827}.level-3{background:#198754}.level-4{background:#0d6efd}.aspect-row td{background:#0f5b2d;color:#fff;font-weight:900;font-size:8.5px;text-transform:uppercase}.sub-row td{background:#dff1e5;color:#17442a;font-weight:800;font-size:8px}.badge{display:inline-block;padding:7px 12px;border-radius:999px;background:#e7f5ec;border:1px solid #9ccaa8;color:#14532d;font-weight:900}.chart-box{text-align:center;margin:12px auto}.chart-box img{width:100%;max-width:690px}.obs-block{border:1px solid #cbd5cf;border-radius:8px;padding:8px 10px;margin:8px 0;background:#fbfdfc;page-break-inside:avoid}.obs-block h4{margin:0 0 4px 0;font-size:11px;color:#14532d}</style></head><body>
    <div class="header"><div class="inst">GOBIERNO REGIONAL ICA · DIRECCIÓN REGIONAL DE EDUCACIÓN ICA</div><div class="inst">UNIDAD DE GESTIÓN EDUCATIVA LOCAL – CHINCHA</div><div class="title">FICHA DE MONITOREO EVALUADA POR ESPECIALISTA</div><div>CONDICIONES OPERATIVAS ORIENTADAS AL SOSTENIMIENTO DEL SERVICIO EDUCATIVO</div><div class="code">'.fm_h($e['codigo_evaluacion']).'</div></div>
    <div class="section-title">I. Datos generales de la IE</div><table class="info"><tr><td>Institución Educativa</td><td>'.fm_h($e['nombre_ie']).'</td><td>Código Local/Modular</td><td>'.fm_h($e['codigo_local']).'</td></tr><tr><td>Distrito</td><td>'.fm_h($e['distrito']).'</td><td>UGEL</td><td>CHINCHA</td></tr><tr><td>Fecha de evaluación</td><td>'.fm_h($fechaEvaluacion).'</td><td>Hora de inicio</td><td>'.fm_h($horaInicioEval).'</td></tr><tr><td>Hora de fin</td><td>'.fm_h($horaFinEval).'</td><td>Año lectivo</td><td>'.fm_h($e['anio'] ?? '').'</td></tr><tr><td>Coordenadas de evaluación</td><td colspan="3">'.fm_pdf_coordenadas_html($e).'</td></tr></table>
    <div class="section-title">II. Datos del director/subdirector y especialista</div><table class="info"><tr><td>Director/Subdirector</td><td>'.fm_h(trim(($e['dir_nombres']??'').' '.($e['dir_ape_pat']??'').' '.($e['dir_ape_mat']??''))).'</td><td>DNI</td><td>'.fm_h($e['dir_dni']).'</td></tr><tr><td>Especialista</td><td>'.fm_h(trim(($e['esp_nombres']??'').' '.($e['esp_ape_pat']??'').' '.($e['esp_ape_mat']??''))).'</td><td>DNI</td><td>'.fm_h($e['esp_dni']).'</td></tr></table>
    <div class="section-title">III. Evaluación detallada</div><table class="items"><thead><tr><th style="width: 4%;">N°</th><th style="width: 50%;">Ítems</th><th style="width: 8%;">Resp. IE</th><th style="width: 3%;">1</th><th style="width: 3%;">2</th><th style="width: 3%;">3</th><th style="width: 3%;">4</th><th style="width: 26%;">Observación especialista</th></tr></thead><tbody>'.$rowsHtml.'</tbody></table>
    <div class="section-title">IV. Resultados evaluados</div><table><tr><th>Aspectos</th><th>Puntaje máximo</th><th>Puntaje alcanzado</th><th>Porcentaje</th></tr>'.$aspectRows.'<tr><td><strong>Total general</strong></td><td><strong>'.number_format((float)$e['puntaje_maximo_eval'],0).'</strong></td><td><strong>'.number_format((float)$e['puntaje_total_eval'],0).'</strong></td><td><strong>'.number_format($por,2).'%</strong></td></tr></table><p style="text-align:center"><span class="badge">Valoración final evaluada: '.fm_h($val).'</span></p><div class="chart-box"><img src="'.$chart.'"></div>
    <div class="section-title">V. Observaciones / Sugerencias</div><div class="obs-block"><h4>Observación general</h4>'.fm_h($e['observacion_general'] ?: $ana['observacion_general']).'</div><div class="obs-block"><h4>Fortalezas</h4>'.fm_h($ana['fortalezas']).'</div><div class="obs-block"><h4>Sugerencias priorizadas</h4>'.fm_h($ana['sugerencias']).'</div>
    <div class="section-title">VI. Compromisos</div><div class="obs-block"><h4>Compromiso del director/subdirector</h4>'.fm_h($e['compromiso_director']).'</div><div class="obs-block"><h4>Compromiso del especialista</h4>'.fm_h($e['compromiso_especialista']).'</div>
    </body></html>';
    $options = new \Dompdf\Options(); $options->set('isRemoteEnabled', true); $options->set('isHtml5ParserEnabled', true); $options->set('defaultFont','DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options); $dompdf->loadHtml($html,'UTF-8'); $dompdf->setPaper('A4','landscape'); $dompdf->render();
    [$d,$rel]=fm_pdf_dir(); $safe=preg_replace('/[^A-Za-z0-9_-]/','_',$e['codigo_evaluacion']); $file="ficha_evaluada_$safe.pdf"; file_put_contents($d.$file,$dompdf->output());
    fm_pdf_save_doc_eval($conn,(int)$e['id_registro'],$id_evaluacion,'PDF_FICHA_EVALUADA','PDF-'.$e['codigo_evaluacion'],$rel.$file,$user);
    return $rel.$file;
}

function fm_generar_documentos_evaluacion_especialista(mysqli $conn, int $id_evaluacion, int $user): array {
    $const = fm_generar_pdf_constancia_evaluacion($conn,$id_evaluacion,$user);
    $ficha = fm_generar_pdf_ficha_evaluada($conn,$id_evaluacion,$user);
    return ['ficha'=>$ficha,'constancia'=>$const];
}
