<?php
/* Hotfix: NO usar htdocs/fpdf.php. Ese archivo del proyecto no es libreria FPDF,
   se requiere a si mismo y agota memoria. Este helper genera PDFs simples en PHP puro. */
function fm_pdf_txt($t){ $t=(string)($t??''); $t=str_replace(["\r\n","\r"],"\n",$t); $c=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$t); return $c!==false?$c:$t; }
function fm_pdf_fecha($f=null){ $ts=$f?strtotime($f):time(); $m=[1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre']; return date('d',$ts).' de '.$m[(int)date('n',$ts)].' de '.date('Y',$ts); }
function fm_pdf_hora($f=null){ return $f ? date('H:i', strtotime($f)) : ''; }
function fm_pdf_coordenadas(array $row): string {
    $lat = $row['latitud'] ?? null;
    $lng = $row['longitud'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return 'No registradas';
    }
    $out = 'Latitud: ' . number_format((float)$lat, 8, '.', '') . ' / Longitud: ' . number_format((float)$lng, 8, '.', '');
    if (isset($row['precision_metros']) && $row['precision_metros'] !== null && $row['precision_metros'] !== '') {
        $out .= ' / Precisión: ' . number_format((float)$row['precision_metros'], 1, '.', '') . ' m';
    }
    return $out;
}
function fm_pdf_coordenadas_html(array $row): string {
    $lat = $row['latitud'] ?? null;
    $lng = $row['longitud'] ?? null;
    if ($lat === null || $lng === null || $lat === '' || $lng === '') {
        return 'No registradas';
    }
    $lat_f = number_format((float)$lat, 8, '.', '');
    $lng_f = number_format((float)$lng, 8, '.', '');
    $url = 'https://www.google.com/maps/search/?api=1&query=' . $lat_f . ',' . $lng_f;
    $out = '<a href="' . $url . '" style="color:#0f5b2d; text-decoration:underline;">Latitud: ' . $lat_f . ' / Longitud: ' . $lng_f . '</a>';
    if (isset($row['precision_metros']) && $row['precision_metros'] !== null && $row['precision_metros'] !== '') {
        $out .= ' (Precisión: ' . number_format((float)$row['precision_metros'], 1, '.', '') . ' m)';
    }
    return $out;
}
function fm_pdf_valoracion($p){ $p=(float)$p; return $p<=25?'INICIO':($p<=50?'PROCESO':($p<=75?'LOGRADO':'DESTACADO')); }
function fm_pdf_observaciones($niv,$porc,$asp){
 $i=(int)($niv[1]??0); $p=(int)($niv[2]??0); $l=(int)($niv[3]??0); $d=(int)($niv[4]??0); $alt=$l+$d; $baj=$i+$p;
 $obs=$porc>=76?'La institución evidencia un nivel de desempeño destacado en la ficha evaluada, con prácticas consolidadas en la gestión del servicio educativo y condiciones favorables para la mejora continua.':($porc>=51?'La institución presenta un nivel logrado en la ficha evaluada. Se observan avances consistentes en la gestión institucional, aunque aún existen componentes que requieren fortalecimiento.':($porc>=26?'La institución se ubica en un nivel de proceso. Se identifican acciones en marcha, pero persisten brechas que limitan una implementación integral.':'La institución se encuentra en un nivel de inicio. Se evidencian debilidades significativas que requieren atención prioritaria.'));
 $fort=$alt>$baj?'Predominan los resultados en niveles de Logrado y Destacado, lo que refleja capacidades institucionales favorables.':(($l+$d)>0?'Se identifican avances parciales en la gestión institucional, con algunos componentes ubicados en niveles de logro.':'No se identifican fortalezas consolidadas en los resultados de la ficha.');
 $sug=[]; if($i>0)$sug[]='Priorizar los ítems ubicados en nivel Inicio mediante un plan de mejora con responsables, cronograma y evidencias.'; if($p>=$l)$sug[]='Consolidar los componentes que aún se encuentran en Proceso.'; if(($asp['aspecto_1']['porcentaje']??0)<51)$sug[]='Fortalecer el análisis de evidencias pedagógicas, metas de aprendizaje y seguimiento de estudiantes en riesgo.'; if(($asp['aspecto_2']['porcentaje']??0)<51)$sug[]='Reforzar la organización de las condiciones operativas, calendarización, asistencia, gestión del riesgo, recursos e infraestructura.'; if($d>0)$sug[]='Promover la socialización de las prácticas mejor valoradas.'; if(!$sug)$sug[]='Mantener el monitoreo interno y sostener acciones de mejora continua.';
 return ['observacion_general'=>$obs,'fortalezas'=>$fort,'sugerencias'=>implode(' ',$sug)];
}

class FMPlainPDF{
 public $pages=[],$cur=-1,$w=595.28,$h=841.89,$y=30,$ori='P';
 function pt($mm){return $mm*72/25.4;} function esc($s){$s=fm_pdf_txt($s);return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);} function raw($s){$this->pages[$this->cur]['c'].=$s."\n";}
 function add($ori='P',$title=''){ $this->ori=$ori; if($ori==='L'){$this->w=841.89;$this->h=595.28;}else{$this->w=595.28;$this->h=841.89;} $this->pages[]=['w'=>$this->w,'h'=>$this->h,'c'=>'']; $this->cur=count($this->pages)-1; $this->y=12; $this->header($title); }
 function header($title){ $this->fill(10,8,($this->w/$this->pt(1))-20,18,[235,245,239]); $this->text(0,13,'GOBIERNO REGIONAL ICA - DIRECCIÓN REGIONAL DE EDUCACIÓN ICA',8,true,'C',$this->w/$this->pt(1)); $this->text(0,18,'UNIDAD DE GESTIÓN EDUCATIVA LOCAL - CHINCHA',9,true,'C',$this->w/$this->pt(1)); $this->text(0,23,$title,8,true,'C',$this->w/$this->pt(1)); $this->y=32; }
 function text($x,$y,$txt,$sz=8,$bold=false,$al='L',$wmm=0){$f=$bold?'/F2':'/F1';$xp=$this->pt($x); if($al!='L'&&$wmm>0){$ap=strlen(fm_pdf_txt($txt))*$sz*.45; if($al=='C')$xp=$this->pt($x)+($this->pt($wmm)-$ap)/2; if($al=='R')$xp=$this->pt($x)+$this->pt($wmm)-$ap;} $yp=$this->h-$this->pt($y); $this->raw("BT $f $sz Tf 0 0 0 rg ".number_format($xp,2,'.','')." ".number_format($yp,2,'.','')." Td (".$this->esc($txt).") Tj ET");}
 function rect($x,$y,$w,$h){$this->raw('0 0 0 RG '.number_format($this->pt($x),2,'.','').' '.number_format($this->h-$this->pt($y+$h),2,'.','').' '.number_format($this->pt($w),2,'.','').' '.number_format($this->pt($h),2,'.','').' re S');}
 function fill($x,$y,$w,$h,$rgb){[$r,$g,$b]=$rgb; $this->raw(($r/255).' '.($g/255).' '.($b/255).' rg '.number_format($this->pt($x),2,'.','').' '.number_format($this->h-$this->pt($y+$h),2,'.','').' '.number_format($this->pt($w),2,'.','').' '.number_format($this->pt($h),2,'.','').' re f'); $this->rect($x,$y,$w,$h);}
 function wrap($txt,$max){$txt=trim((string)$txt);$out=[];foreach(explode("\n",$txt) as $p){$p=trim($p);while(strlen(fm_pdf_txt($p))>$max){$part=substr($p,0,$max);$cut=strrpos($part,' ');if($cut===false||$cut<10)$cut=$max;$out[]=trim(substr($p,0,$cut));$p=trim(substr($p,$cut));}$out[]=$p;}return $out?:[''];}
 function break($need=10,$title=''){ $lim=($this->h/$this->pt(1))-18; if($this->y+$need>$lim)$this->add($this->ori,$title); }
 function multi($x,$y,$w,$txt,$sz=7,$lh=4,$bold=false){$max=max(8,(int)floor($w*1.9/max(1,$sz/8)));$lines=$this->wrap($txt,$max);foreach($lines as $k=>$ln)$this->text($x+1,$y+3+$k*$lh,$ln,$sz,$bold);return max(5,count($lines)*$lh+2);}
 function section($t){$this->break(9,$t);$this->fill(10,$this->y,($this->w/$this->pt(1))-20,7,[0,102,51]);$this->text(11,$this->y+5,$t,8,true);$this->y+=8;}
 function row($cells,$widths,$sz=7,$lh=4,$shade=false){$hs=[];foreach($cells as $i=>$c){$txt=is_array($c)?$c[0]:$c;$hs[]=max(6,count($this->wrap($txt,max(8,(int)floor($widths[$i]*1.9))))*$lh+2);} $h=max($hs);$this->break($h+2);$x=10;foreach($cells as $i=>$c){$txt=is_array($c)?$c[0]:$c;$b=is_array($c)?(bool)$c[1]:false;if($shade||$b)$this->fill($x,$this->y,$widths[$i],$h,[242,242,242]);else $this->rect($x,$this->y,$widths[$i],$h);$this->multi($x,$this->y,$widths[$i],$txt,$sz,$lh,$b);$x+=$widths[$i];}$this->y+=$h;}
 function info($a,$b,$c,$d){$this->row([[$a,true],$b,[$c,true],$d],[46,49,46,49]);}
 function out($path){if(!$this->pages)$this->add('P','Documento');$objs=['<< /Type /Catalog /Pages 2 0 R >>'];$kids=[];$n=5;foreach($this->pages as $i=>$p){$kids[]=($n).' 0 R';$n+=2;}$objs[]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';$objs[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';$objs[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';$n=5;foreach($this->pages as $p){$cont=$n+1;$objs[]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.number_format($p['w'],2,'.','').' '.number_format($p['h'],2,'.','').'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$cont.' 0 R >>';$objs[]="<< /Length ".strlen($p['c'])." >>\nstream\n".$p['c']."endstream";$n+=2;} $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$ofs=[0];foreach($objs as $i=>$o){$ofs[]=strlen($pdf);$pdf.=($i+1)." 0 obj\n$o\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";for($i=1;$i<=count($objs);$i++)$pdf.=sprintf("%010d 00000 n \n",$ofs[$i]);$pdf.="trailer\n<< /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";file_put_contents($path,$pdf);} }

function fm_pdf_data(mysqli $conn,int $id){$sql="SELECT r.*,tf.nombre tipo_ficha_nombre,tf.anio,v.titulo version_titulo,i.nombre_ie,i.codigo_local,i.distrito,u.nombres dir_nombres,u.ape_pat dir_ape_pat,u.ape_mat dir_ape_mat,u.dni dir_dni,u.correo dir_correo,u.telefono dir_telefono,esp.nombres esp_nombres,esp.ape_pat esp_ape_pat,esp.ape_mat esp_ape_mat,esp.dni esp_dni FROM fm_registros r INNER JOIN fm_tipos_ficha tf ON tf.id=r.id_tipo_ficha INNER JOIN fm_tipos_ficha_versiones v ON v.id=r.id_version_ficha INNER JOIN instituciones i ON i.id=r.id_institucion INNER JOIN usuarios u ON u.id=r.id_usuario LEFT JOIN usuarios esp ON esp.id=r.id_especialista WHERE r.id=? LIMIT 1";$st=$conn->prepare($sql);$st->bind_param('i',$id);$st->execute();$reg=$st->get_result()->fetch_assoc();$st->close();if(!$reg)throw new Exception('No se encontró el registro de ficha.');$vid=(int)$reg['id_version_ficha'];$secs=[];$rs=$conn->query("SELECT * FROM fm_secciones WHERE id_version_ficha=$vid AND estado='ACTIVO' ORDER BY nivel,orden,id");while($r=$rs->fetch_assoc())$secs[(int)$r['id']]=$r;$items=[];$rs=$conn->query("SELECT * FROM fm_items WHERE id_version_ficha=$vid AND estado='ACTIVO' ORDER BY orden,id");while($r=$rs->fetch_assoc())$items[(int)$r['id']]=$r;$resp=[];$rs=$conn->query("SELECT * FROM fm_respuestas_director WHERE id_registro=$id");while($r=$rs->fetch_assoc())$resp[(int)$r['id_item']]=$r;return[$reg,$secs,$items,$resp];}
function fm_pdf_save_doc(mysqli $conn,$id,$tipo,$cod,$ruta,$user){$st=$conn->prepare("DELETE FROM fm_documentos WHERE id_registro=? AND tipo_documento=? AND id_evaluacion IS NULL");$st->bind_param('is',$id,$tipo);$st->execute();$st->close();$null=null;$st=$conn->prepare("INSERT INTO fm_documentos(id_registro,id_evaluacion,tipo_documento,codigo_documento,ruta_archivo,generado_por) VALUES(?,?,?,?,?,?)");$st->bind_param('iisssi',$id,$null,$tipo,$cod,$ruta,$user);$st->execute();$st->close();}
function fm_pdf_dir(){ $y=date('Y');$d=__DIR__."/documentos_fichas/$y/";if(!is_dir($d))mkdir($d,0775,true);return[$d,"documentos_fichas/$y/"];}
function fm_generar_pdf_constancia_envio(mysqli $conn,int $id,int $user){[$r]=fm_pdf_data($conn,$id);$pdf=new FMPlainPDF();$pdf->add('P','CONSTANCIA DE ENVÍO');$pdf->y=60;$pdf->text(0,$pdf->y,'CONSTANCIA DE ENVÍO DE FICHA',16,true,'C',210);$pdf->y+=20;$pdf->text(0,$pdf->y,'CÓDIGO DE CONSTANCIA',12,true,'C',210);$pdf->y+=8;$pdf->fill(35,$pdf->y,140,18,[235,245,239]);$pdf->text(35,$pdf->y+12,$r['codigo_envio'],16,true,'C',140);$pdf->y+=30;$pdf->text(25,$pdf->y,'Fecha de envío: '.fm_pdf_fecha($r['fecha_envio']??null),10);$pdf->y+=7;$pdf->text(25,$pdf->y,'Tipo de ficha: '.($r['tipo_ficha_nombre']??''),10);$pdf->y+=7;$pdf->text(25,$pdf->y,'Institución Educativa: '.($r['nombre_ie']??''),10);[$d,$rel]=fm_pdf_dir();$safe=preg_replace('/[^A-Za-z0-9_-]/','_',$r['codigo_envio']);$f="constancia_$safe.pdf";$pdf->out($d.$f);fm_pdf_save_doc($conn,$id,'CONSTANCIA_ENVIO_DIRECTOR','CONST-'.$r['codigo_envio'],$rel.$f,$user);return$rel.$f;}
function fm_siglas_ficha($nombre){
    $nombre = strtoupper(trim((string)$nombre));
    $stop = ['DE','DEL','LA','LAS','LOS','Y','A','AL','EL','EN','CON','PARA','POR'];
    $parts = preg_split('/\s+/', preg_replace('/[^A-ZÁÉÍÓÚÑÜ0-9\s]/u',' ', $nombre));
    $sig = '';
    foreach($parts as $w){ if($w === '' || in_array($w, $stop, true)) continue; $sig .= mb_substr($w,0,1,'UTF-8'); }
    return $sig ?: 'FM';
}
function fm_h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function fm_svg_bar_chart(array $niveles): string {
    $max = max(1, max($niveles));
    $labels = [1=>'INICIO',2=>'PROCESO',3=>'LOGRADO',4=>'DESTACADO'];
    $colors = [1=>'#dc3545',2=>'#ffc107',3=>'#198754',4=>'#0d6efd'];
    $svg = '<svg width="720" height="260" viewBox="0 0 720 260" xmlns="http://www.w3.org/2000/svg">';
    $svg .= '<rect x="0" y="0" width="720" height="260" rx="14" fill="#ffffff" stroke="#d9e2dc"/>';
    $svg .= '<text x="360" y="28" text-anchor="middle" font-family="DejaVu Sans, Arial" font-size="15" font-weight="700" fill="#14532d">Distribución de ítems por nivel</text>';
    for($i=0;$i<=4;$i++){ $y = 190 - ($i*35); $svg .= '<line x1="70" y1="'.$y.'" x2="660" y2="'.$y.'" stroke="#edf2ef" stroke-width="1"/>'; }
    $x0 = 110; $barW = 80; $gap = 65;
    foreach([1,2,3,4] as $idx=>$n){
        $val = (int)($niveles[$n] ?? 0); $h = ($val / $max) * 130; $x = $x0 + $idx*($barW+$gap); $y = 190 - $h;
        $svg .= '<rect x="'.$x.'" y="'.$y.'" width="'.$barW.'" height="'.$h.'" rx="8" fill="'.$colors[$n].'"/>';
        $svg .= '<text x="'.($x+$barW/2).'" y="'.($y-8).'" text-anchor="middle" font-family="DejaVu Sans, Arial" font-size="16" font-weight="700" fill="#111827">'.$val.'</text>';
        $svg .= '<text x="'.($x+$barW/2).'" y="220" text-anchor="middle" font-family="DejaVu Sans, Arial" font-size="11" font-weight="700" fill="#374151">'.$labels[$n].'</text>';
    }
    return 'data:image/svg+xml;base64,'.base64_encode($svg.'</svg>');
}
function fm_generar_pdf_ficha_director(mysqli $conn,int $id,int $user){
    [$r,$secs,$items,$resps]=fm_pdf_data($conn,$id);
    $por=(float)($r['porcentaje']??0); $val=$r['escala_valorativa']?:fm_pdf_valoracion($por);
    $niv=[1=>0,2=>0,3=>0,4=>0]; $asp=[];
    foreach($items as $iid=>$it){
        $resp=$resps[$iid]??null;
        if($resp && !(int)$resp['no_aplica'] && $it['tipo_respuesta']=='ESCALA_1_4'){$v=(int)$resp['respuesta_escala']; if($v>=1&&$v<=4)$niv[$v]++;}
        $sid=(int)($it['id_seccion']??0); $root=null;
        while($sid&&isset($secs[$sid])){$root=$secs[$sid];$sid=(int)($secs[$sid]['id_seccion_padre']??0);} 
        if($root){$k=stripos($root['titulo'],'DESARROLLO')!==false?'aspecto_1':(stripos($root['titulo'],'CONDICIONES')!==false?'aspecto_2':'aspecto_'.$root['id']); if(!isset($asp[$k]))$asp[$k]=['puntaje'=>0,'maximo'=>0,'porcentaje'=>0]; if($resp&&!(int)$resp['no_aplica']){$asp[$k]['puntaje']+=(int)$resp['respuesta_escala'];$asp[$k]['maximo']+=(float)$it['puntaje_maximo'];}}
    }
    foreach($asp as $k=>$a)$asp[$k]['porcentaje']=$a['maximo']>0?round($a['puntaje']/$a['maximo']*100,2):0;
    $ana=fm_pdf_observaciones($niv,$por,$asp);
    $autoload = __DIR__ . '/vendor/autoload.php';
    if(!file_exists($autoload)){ throw new Exception('No se encontró vendor/autoload.php. Ejecuta: composer require dompdf/dompdf dentro de htdocs.'); }
    require_once $autoload;
    $codigo = $r['codigo_envio'] ?? ''; $tituloFicha = $r['tipo_ficha_nombre'] ?? 'GESTIÓN DE CONDICIONES OPERATIVAS'; $chart = fm_svg_bar_chart($niv);
    $rowsHtml = ''; $curRoot = null; $curSub = null;
    foreach($items as $iid=>$it){
        $sec=$secs[(int)($it['id_seccion']??0)]??null; $root=$sec; $sub=null;
        if($sec&&!empty($sec['id_seccion_padre'])&&isset($secs[(int)$sec['id_seccion_padre']])){$sub=$sec;$root=$secs[(int)$sec['id_seccion_padre']];}
        if($root && $curRoot!=(int)$root['id']){$curRoot=(int)$root['id']; $curSub=null; $rowsHtml .= '<tr class="aspect-row"><td colspan="7">'.fm_h($root['titulo']).'</td></tr>';}
        if($sub && $curSub!=(int)$sub['id']){$curSub=(int)$sub['id']; $rowsHtml .= '<tr class="sub-row"><td colspan="7">'.fm_h($sub['titulo']).'</td></tr>';}
        $resp=$resps[$iid]??null; $noAplica=$resp&&(int)$resp['no_aplica']===1; $v=$resp&&!$noAplica?(int)($resp['respuesta_escala']??0):0;
        $obs=$resp?($resp['observacion']??''):''; if($noAplica)$obs=trim('NO APLICA. '.$obs);
        $rowsHtml .= '<tr><td class="num">'.fm_h($it['numero_item']).'</td><td class="item-text">'.fm_h($it['pregunta']).'</td>';
        foreach([1,2,3,4] as $n){ $rowsHtml .= '<td class="level '.($v===$n?'checked level-'.$n:'').'">'.($v===$n?'X':'').'</td>'; }
        $rowsHtml .= '<td class="obs">'.fm_h($obs).'</td></tr>';
    }
    $aspectRows = '';
    foreach($asp as $key=>$a){$nombre = $key==='aspecto_1' ? 'Aspecto 01: Desarrollo integral de las y los estudiantes' : ($key==='aspecto_2' ? 'Aspecto 02: Gestión de las condiciones operativas' : strtoupper($key)); $aspectRows .= '<tr><td>'.fm_h($nombre).'</td><td>'.number_format((float)$a['maximo'],0).'</td><td>'.number_format((float)$a['puntaje'],0).'</td><td>'.number_format((float)$a['porcentaje'],2).'%</td></tr>';}
    if($aspectRows===''){$aspectRows='<tr><td>'.count($items).' ÍTEMS</td><td>'.number_format((float)$r['puntaje_maximo'],0).'</td><td>'.number_format((float)$r['puntaje_total'],0).'</td><td>'.number_format($por,2).'%</td></tr>';}
    $fechaEnvio = fm_pdf_fecha($r['fecha_envio']??null);
    $horaInicio = !empty($r['fecha_borrador']) ? date('H:i', strtotime($r['fecha_borrador'])) : '';
    $horaFin    = !empty($r['fecha_envio'])    ? date('H:i', strtotime($r['fecha_envio']))    : '';
    $html = '<!doctype html><html><head><meta charset="utf-8"><style>
        @page { margin: 18mm 12mm 16mm 12mm; } body { font-family: DejaVu Sans, Arial, sans-serif; color:#1f2937; font-size: 10.5px; line-height:1.34; }
        .header { border:1px solid #c9ded0; background:#eef8f1; padding:10px 14px; border-radius:10px; text-align:center; margin-bottom:12px; }
        .header .inst { font-size:10px; font-weight:700; color:#14532d; letter-spacing:.2px; } .title { font-size:13px; font-weight:800; text-align:center; color:#0f3f24; text-transform:uppercase; margin:8px 10px 4px; line-height:1.35; }
        .subtitle { text-align:center; font-size:9.5px; color:#475569; } .code { display:inline-block; margin-top:7px; padding:5px 10px; border:1px solid #80b28d; border-radius:7px; background:#fff; font-weight:800; color:#14532d; }
        .section-title { background:#0f5b2d; color:#fff; padding:6px 9px; font-weight:800; text-transform:uppercase; border-radius:6px 6px 0 0; margin-top:10px; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; page-break-inside:auto; } tr { page-break-inside:avoid; page-break-after:auto; } th, td { border:1px solid #cbd5cf; padding:5px 6px; vertical-align:top; overflow-wrap:break-word; word-wrap:break-word; }
        th { background:#eaf4ee; color:#123d25; font-weight:800; text-align:center; } .info td:nth-child(odd) { background:#f5faf7; font-weight:800; color:#17442a; width:24%; } .info td:nth-child(even) { width:26%; }
        .items { font-size:8px; } .items th { padding:3px 2px; } .items td { padding:4px 4px; } .items .num { text-align:center; font-weight:800; } .items .item-text { font-size:7.9px; } .items .level { text-align:center; font-weight:900; color:#111827; } .items .obs { font-size:7.4px; }
        .checked { color:#fff; } .level-1 { background:#dc3545; } .level-2 { background:#ffc107; color:#111827; } .level-3 { background:#198754; } .level-4 { background:#0d6efd; }
        .aspect-row td { background:#0f5b2d; color:#fff; font-weight:900; font-size:9px; text-transform:uppercase; } .sub-row td { background:#dff1e5; color:#17442a; font-weight:800; font-size:8.5px; }
        .result-grid { width:100%; margin-top:8px; } .badge { display:inline-block; padding:7px 12px; border-radius:999px; background:#e7f5ec; border:1px solid #9ccaa8; color:#14532d; font-weight:900; }
        .chart-box { margin:12px auto 6px; text-align:center; } .chart-box img { width:100%; max-width:690px; } .obs-block { border:1px solid #cbd5cf; border-radius:8px; padding:8px 10px; margin:8px 0; background:#fbfdfc; page-break-inside:avoid; } .obs-block h4 { margin:0 0 4px 0; font-size:11px; color:#14532d; }
        .footer { margin-top:18px; text-align:center; color:#475569; font-size:9px; border-top:1px solid #d6e2da; padding-top:8px; }
    </style></head><body>
        <div class="header"><div class="inst">GOBIERNO REGIONAL ICA · DIRECCIÓN REGIONAL DE EDUCACIÓN ICA</div><div class="inst">UNIDAD DE GESTIÓN EDUCATIVA LOCAL – CHINCHA</div><div class="title">FICHA DE MONITOREO A LAS PRÁCTICAS DE GESTIÓN ASOCIADAS A LOS COMPROMISOS DE GESTIÓN ESCOLAR</div><div class="subtitle">CONDICIONES OPERATIVAS ORIENTADAS AL SOSTENIMIENTO DEL SERVICIO EDUCATIVO OFRECIDO POR LA IE</div><div class="code">'.fm_h($codigo).'</div></div>
        <div class="section-title">I. Datos generales de la IE</div><table class="info"><tr><td>Institución Educativa</td><td>'.fm_h($r['nombre_ie']??'').'</td><td>Código Local/Modular</td><td>'.fm_h($r['codigo_local']??'').'</td></tr><tr><td>Distrito</td><td>'.fm_h($r['distrito']??'').'</td><td>UGEL</td><td>CHINCHA</td></tr><tr><td>Fecha</td><td>'.fm_h($fechaEnvio).'</td><td>Hora de inicio</td><td>'.fm_h($horaInicio).'</td></tr><tr><td>Hora de fin</td><td>'.fm_h($horaFin).'</td><td>Año lectivo</td><td>'.fm_h($r['anio']??'').'</td></tr><tr><td>Coordenadas del registro</td><td colspan="3">'.fm_pdf_coordenadas_html($r).'</td></tr></table>
        <div class="section-title">II. Datos del director/subdirector</div><table class="info"><tr><td>Nombres completos</td><td>'.fm_h($r['dir_nombres']??'').'</td><td>Apellidos completos</td><td>'.fm_h(trim(($r['dir_ape_pat']??'').' '.($r['dir_ape_mat']??''))).'</td></tr><tr><td>Documento de Identidad</td><td>'.fm_h($r['dir_dni']??'').'</td><td>Teléfono</td><td>'.fm_h($r['dir_telefono']??'').'</td></tr><tr><td>Correo electrónico</td><td colspan="3">'.fm_h($r['dir_correo']??'').'</td></tr></table>
        <div class="section-title">III. Datos del especialista</div><table class="info"><tr><td>Nombres completos</td><td>'.fm_h($r['esp_nombres']??'').'</td><td>Apellidos completos</td><td>'.fm_h(trim(($r['esp_ape_pat']??'').' '.($r['esp_ape_mat']??''))).'</td></tr><tr><td>Documento de Identidad</td><td>'.fm_h($r['esp_dni']??'').'</td><td>Tipo de IE</td><td></td></tr></table>
        <div class="section-title">'.fm_h($tituloFicha).'</div><table class="items"><thead><tr><th style="width: 4%;">N°</th><th style="width: 58%;">Ítems</th><th style="width: 3%;">1</th><th style="width: 3%;">2</th><th style="width: 3%;">3</th><th style="width: 3%;">4</th><th style="width: 26%;">Observaciones</th></tr></thead><tbody>'.$rowsHtml.'</tbody></table>
        <div class="section-title">IV. Resultados</div><table class="result-grid"><tr><th>Escala valorativa</th><th>Valoración</th></tr><tr><td>0% - 25%</td><td>INICIO</td></tr><tr><td>26% - 50%</td><td>PROCESO</td></tr><tr><td>51% - 75%</td><td>LOGRADO</td></tr><tr><td>76% - 100%</td><td>DESTACADO</td></tr></table><table class="result-grid"><tr><th>Aspectos</th><th>Puntaje máximo</th><th>Puntaje alcanzado</th><th>Porcentaje</th></tr>'.$aspectRows.'<tr><td><strong>Total general</strong></td><td><strong>'.number_format((float)$r['puntaje_maximo'],0).'</strong></td><td><strong>'.number_format((float)$r['puntaje_total'],0).'</strong></td><td><strong>'.number_format($por,2).'%</strong></td></tr></table><p style="text-align:center;margin:10px 0 6px;"><span class="badge">Valoración final: '.fm_h($val).'</span></p><div class="chart-box"><img src="'.$chart.'" alt="Gráfico de resultados"></div>
        <div class="section-title">V. Observaciones / Sugerencias</div><div class="obs-block"><h4>Observación general</h4><div>'.fm_h($ana['observacion_general']).'</div></div><div class="obs-block"><h4>Fortalezas</h4><div>'.fm_h($ana['fortalezas']).'</div></div><div class="obs-block"><h4>Sugerencias priorizadas</h4><div>'.fm_h($ana['sugerencias']).'</div></div><div class="footer">UNIDAD DE GESTIÓN EDUCATIVA LOCAL – CHINCHA<br>AÑO DE LA ESPERANZA Y EL FORTALECIMIENTO DE LA DEMOCRACIA</div>
    </body></html>';
    $options = new \Dompdf\Options(); $options->set('isRemoteEnabled', true); $options->set('isHtml5ParserEnabled', true); $options->set('defaultFont', 'DejaVu Sans');
    $dompdf = new \Dompdf\Dompdf($options); $dompdf->loadHtml($html, 'UTF-8'); $dompdf->setPaper('A4', 'landscape'); $dompdf->render();
    [$d,$rel]=fm_pdf_dir(); $safe=preg_replace('/[^A-Za-z0-9_-]/','_',$codigo); $f="ficha_$safe.pdf"; file_put_contents($d.$f, $dompdf->output()); fm_pdf_save_doc($conn,$id,'PDF_FICHA_DIRECTOR','PDF-'.$codigo,$rel.$f,$user); return $rel.$f;
}
function fm_generar_documentos_envio_director(mysqli $conn,int $id,int $user):array{$const=fm_generar_pdf_constancia_envio($conn,$id,$user);$ficha=fm_generar_pdf_ficha_director($conn,$id,$user);return['ficha'=>$ficha,'constancia'=>$const];}
