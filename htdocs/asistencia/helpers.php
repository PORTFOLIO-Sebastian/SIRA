<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../conexion.php';

const ASISTENCIA_CODIGOS = ['A', 'I', 'J', 'L', 'P', 'T', 'H'];

function asistencia_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function asistencia_require_login(): void {
    if (empty($_SESSION['id_usuario'])) {
        header('Location: ../login.php');
        exit();
    }
    if (empty($_SESSION['id_ie'])) {
        echo "<script>alert('No se pudo identificar su Institucion Educativa. Inicie sesion nuevamente.'); window.location.href='../login.php';</script>";
        exit();
    }
}

function asistencia_db_ready(mysqli $conn): bool {
    $res = $conn->query("SHOW TABLES LIKE 'asistencia_mensual'");
    return $res && $res->num_rows > 0;
}

function asistencia_mes_nombres(): array {
    return [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
}

function asistencia_periodo(): array {
    $mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('n');
    $anio = isset($_GET['anio']) ? (int)$_GET['anio'] : (int)date('Y');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $mes = isset($_POST['mes']) ? (int)$_POST['mes'] : $mes;
        $anio = isset($_POST['anio']) ? (int)$_POST['anio'] : $anio;
    }
    if ($mes < 1 || $mes > 12) {
        $mes = (int)date('n');
    }
    if ($anio < 2020 || $anio > 2100) {
        $anio = (int)date('Y');
    }
    return [$mes, $anio];
}

function asistencia_url(string $page, int $mes, int $anio): string {
    return $page . '?mes=' . $mes . '&anio=' . $anio;
}

function asistencia_ie(mysqli $conn, int $idIe): array {
    $stmt = $conn->prepare("SELECT id, nombre_ie, codigo_local, nivel_modalidad, distrito FROM instituciones WHERE id = ?");
    $stmt->bind_param('i', $idIe);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('No se encontro la institucion educativa asignada.');
    }
    return $row;
}

function asistencia_get_or_create_mensual(mysqli $conn, int $idIe, int $idUsuario, int $mes, int $anio): array {
    $stmt = $conn->prepare("SELECT * FROM asistencia_mensual WHERE id_ie = ? AND mes = ? AND anio = ?");
    $stmt->bind_param('iii', $idIe, $mes, $anio);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return $row;
    }

    $ie = asistencia_ie($conn, $idIe);
    $ugel = 'CHINCHA';
    $region = 'ICA';
    $provincia = 'CHINCHA';
    $distrito = $ie['distrito'] ?? '';
    $nombre = $ie['nombre_ie'];
    $codigo = $ie['codigo_local'];
    $nivel = $ie['nivel_modalidad'] ?? '';
    $stmt = $conn->prepare("INSERT INTO asistencia_mensual (id_ie, mes, anio, ugel, region, provincia, distrito, nombre_ie, codigo_local, nivel_modalidad, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('iiisssssssi', $idIe, $mes, $anio, $ugel, $region, $provincia, $distrito, $nombre, $codigo, $nivel, $idUsuario);
    $stmt->execute();
    $id = $stmt->insert_id;
    $stmt->close();
    asistencia_auditar($conn, 'asistencia_mensual', $id, 'CREAR', null, json_encode(['mes' => $mes, 'anio' => $anio], JSON_UNESCAPED_UNICODE), $idUsuario);
    return asistencia_get_mensual_por_id($conn, $id);
}

function asistencia_get_mensual_por_id(mysqli $conn, int $id): array {
    $stmt = $conn->prepare("SELECT * FROM asistencia_mensual WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new RuntimeException('No se encontro el periodo de asistencia.');
    }
    return $row;
}

function asistencia_personal_activo(mysqli $conn, int $idIe): array {
    $stmt = $conn->prepare("SELECT * FROM asistencia_personal WHERE id_ie = ? AND estado = 'activo' ORDER BY apellidos_nombres");
    $stmt->bind_param('i', $idIe);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function asistencia_personal_todos(mysqli $conn, int $idIe): array {
    $stmt = $conn->prepare("SELECT * FROM asistencia_personal WHERE id_ie = ? ORDER BY estado, apellidos_nombres");
    $stmt->bind_param('i', $idIe);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function asistencia_calendario(int $mes, int $anio): array {
    $dias = cal_days_in_month(CAL_GREGORIAN, $mes, $anio);
    $abbr = [1 => 'lu.', 2 => 'ma.', 3 => 'mi.', 4 => 'ju.', 5 => 'vi.', 6 => 'sa.', 7 => 'do.'];
    $out = [];
    for ($dia = 1; $dia <= $dias; $dia++) {
        $fecha = sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
        $n = (int)date('N', strtotime($fecha));
        $out[] = ['dia' => $dia, 'fecha' => $fecha, 'semana' => $abbr[$n], 'laborable' => $n <= 5];
    }
    return $out;
}

function asistencia_detalle_map(mysqli $conn, int $idMensual): array {
    $stmt = $conn->prepare("SELECT id_personal, dia_mes, codigo_asistencia FROM asistencia_detalle WHERE id_asistencia_mensual = ?");
    $stmt->bind_param('i', $idMensual);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    while ($row = $res->fetch_assoc()) {
        $map[(int)$row['id_personal']][(int)$row['dia_mes']] = $row['codigo_asistencia'];
    }
    $stmt->close();
    return $map;
}

function asistencia_resumen(mysqli $conn, int $idMensual): array {
    $stmt = $conn->prepare("SELECT id_personal, codigo_asistencia, COUNT(*) total FROM asistencia_detalle WHERE id_asistencia_mensual = ? GROUP BY id_personal, codigo_asistencia");
    $stmt->bind_param('i', $idMensual);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $idPersonal = (int)$row['id_personal'];
        if (!isset($out[$idPersonal])) {
            $out[$idPersonal] = ['I' => 0, 'T' => 0, 'P' => 0, 'H' => 0];
        }
        if (isset($out[$idPersonal][$row['codigo_asistencia']])) {
            $out[$idPersonal][$row['codigo_asistencia']] = (int)$row['total'];
        }
    }
    $stmt->close();
    return $out;
}

function asistencia_validar_completo(mysqli $conn, array $mensual, array $personal, array $calendario): array {
    if (!$personal) {
        return [false, 'Debe registrar al menos un trabajador activo.'];
    }
    $detalle = asistencia_detalle_map($conn, (int)$mensual['id']);
    foreach ($personal as $trabajador) {
        foreach ($calendario as $dia) {
            if (!$dia['laborable']) {
                continue;
            }
            $valor = $detalle[(int)$trabajador['id']][(int)$dia['dia']] ?? '';
            if (!in_array($valor, ASISTENCIA_CODIGOS, true)) {
                return [false, 'Faltan codigos de asistencia en dias laborables.'];
            }
        }
    }
    return [true, 'OK'];
}

function asistencia_auditar(mysqli $conn, string $tabla, int $idRegistro, string $accion, ?string $anterior, ?string $nuevo, int $idUsuario): void {
    if (!asistencia_db_ready($conn)) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $stmt = $conn->prepare("INSERT INTO asistencia_auditoria (tabla_afectada, id_registro_afectado, accion, valor_anterior, valor_nuevo, id_usuario, ip_usuario, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('sisssiss', $tabla, $idRegistro, $accion, $anterior, $nuevo, $idUsuario, $ip, $agent);
    $stmt->execute();
    $stmt->close();
}

function asistencia_upload_dir(array $mensual): array {
    $baseRel = 'uploads/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $mensual['codigo_local']) . '/' . (int)$mensual['anio'] . '/Asistencia mensual/';
    $baseAbs = __DIR__ . '/../uploads/' . preg_replace('/[^A-Za-z0-9_-]/', '_', $mensual['codigo_local']) . '/' . (int)$mensual['anio'] . '/Asistencia mensual/';
    if (!is_dir($baseAbs)) {
        mkdir($baseAbs, 0777, true);
    }
    return [$baseAbs, $baseRel];
}

function asistencia_pdf_txt($t){ $t=(string)($t??''); $t=str_replace(["\r\n","\r"],"\n",$t); $c=@iconv('UTF-8','Windows-1252//TRANSLIT//IGNORE',$t); return $c!==false?$c:$t; }

class AsistenciaPlainPDF {
    public $pages=[],$cur=-1,$w=595.28,$h=841.89,$y=30,$ori='P';
    function pt($mm){return $mm*72/25.4;}
    function esc($s){$s=asistencia_pdf_txt($s);return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$s);}
    function raw($s){$this->pages[$this->cur]['c'].=$s."\n";}
    function add($ori='P',$title=''){
        $this->ori=$ori;
        if($ori==='L'){$this->w=841.89;$this->h=595.28;}else{$this->w=595.28;$this->h=841.89;}
        $this->pages[]=['w'=>$this->w,'h'=>$this->h,'c'=>''];
        $this->cur=count($this->pages)-1;
        $this->y=12;
        $this->header($title);
    }
    function header($title){
        $this->fill(10,8,($this->w/$this->pt(1))-20,18,[235,245,239]);
        $this->text(0,13,'GOBIERNO REGIONAL ICA - DIRECCIÓN REGIONAL DE EDUCACIÓN ICA',8,true,'C',$this->w/$this->pt(1));
        $this->text(0,18,'UNIDAD DE GESTIÓN EDUCATIVA LOCAL - CHINCHA',9,true,'C',$this->w/$this->pt(1));
        $this->text(0,23,$title,8,true,'C',$this->w/$this->pt(1));
        $this->y=32;
    }
    function text($x,$y,$txt,$sz=8,$bold=false,$al='L',$wmm=0){
        $f=$bold?'/F2':'/F1';
        $xp=$this->pt($x);
        if($al!='L'&&$wmm>0){
            $ap=strlen(asistencia_pdf_txt($txt))*$sz*.45;
            if($al=='C')$xp=$this->pt($x)+($this->pt($wmm)-$ap)/2;
            if($al=='R')$xp=$this->pt($x)+$this->pt($wmm)-$ap;
        }
        $yp=$this->h-$this->pt($y);
        $this->raw("BT $f $sz Tf 0 0 0 rg ".number_format($xp,2,'.','')." ".number_format($yp,2,'.','')." Td (".$this->esc($txt).") Tj ET");
    }
    function rect($x,$y,$w,$h){
        $this->raw('0 0 0 RG '.number_format($this->pt($x),2,'.','').' '.number_format($this->h-$this->pt($y+$h),2,'.','').' '.number_format($this->pt($w),2,'.','').' '.number_format($this->pt($h),2,'.','').' re S');
    }
    function fill($x,$y,$w,$h,$rgb){
        [$r,$g,$b]=$rgb;
        $this->raw(($r/255).' '.($g/255).' '.($b/255).' rg '.number_format($this->pt($x),2,'.','').' '.number_format($this->h-$this->pt($y+$h),2,'.','').' '.number_format($this->pt($w),2,'.','').' '.number_format($this->pt($h),2,'.','').' re f');
        $this->rect($x,$y,$w,$h);
    }
    function wrap($txt,$max){
        $txt=trim((string)$txt);
        $out=[];
        foreach(explode("\n",$txt) as $p){
            $p=trim($p);
            while(strlen(asistencia_pdf_txt($p))>$max){
                $part=substr($p,0,$max);
                $cut=strrpos($part,' ');
                if($cut===false||$cut<10)$cut=$max;
                $out[]=trim(substr($p,0,$cut));
                $p=trim(substr($p,$cut));
            }
            $out[]=$p;
        }
        return $out?:[''];
    }
    function break($need=10,$title=''){
        $lim=($this->h/$this->pt(1))-18;
        if($this->y+$need>$lim)$this->add($this->ori,$title);
    }
    function multi($x,$y,$w,$txt,$sz=7,$lh=4,$bold=false){
        $max=max(8,(int)floor($w*1.9/max(1,$sz/8)));
        $lines=$this->wrap($txt,$max);
        foreach($lines as $k=>$ln)$this->text($x+1,$y+3+$k*$lh,$ln,$sz,$bold);
        return max(5,count($lines)*$lh+2);
    }
    function row($cells,$widths,$sz=7,$lh=4,$shade=false){
        $hs=[];
        foreach($cells as $i=>$c){
            $txt=is_array($c)?$c[0]:$c;
            $hs[]=max(6,count($this->wrap($txt,max(8,(int)floor($widths[$i]*1.9))))*$lh+2);
        }
        $h=max($hs);
        $this->break($h+2);
        $x=10;
        foreach($cells as $i=>$c){
            $txt=is_array($c)?$c[0]:$c;
            $b=is_array($c)?(bool)$c[1]:false;
            if($shade||$b)$this->fill($x,$this->y,$widths[$i],$h,[242,242,242]);
            else $this->rect($x,$this->y,$widths[$i],$h);
            $this->multi($x,$this->y,$widths[$i],$txt,$sz,$lh,$b);
            $x+=$widths[$i];
        }
        $this->y+=$h;
    }
    function out($path){
        if(!$this->pages)$this->add('P','Documento');
        $objs=['<< /Type /Catalog /Pages 2 0 R >>'];
        $kids=[];
        $n=5;
        foreach($this->pages as $i=>$p){$kids[]=($n).' 0 R';$n+=2;}
        $objs[]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
        $objs[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $n=5;
        foreach($this->pages as $p){
            $cont=$n+1;
            $objs[]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.number_format($p['w'],2,'.','').' '.number_format($p['h'],2,'.','').'] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.$cont.' 0 R >>';
            $objs[]="<< /Length ".strlen($p['c'])." >>\nstream\n".$p['c']."endstream";
            $n+=2;
        }
        $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $ofs=[0];
        foreach($objs as $i=>$o){
            $ofs[]=strlen($pdf);
            $pdf.=($i+1)." 0 obj\n$o\nendobj\n";
        }
        $xref=strlen($pdf);
        $pdf.="xref\n0 ".(count($objs)+1)."\n0000000000 65535 f \n";
        for($i=1;$i<=count($objs);$i++)$pdf.=sprintf("%010d 00000 n \n",$ofs[$i]);
        $pdf.="trailer\n<< /Size ".(count($objs)+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
        file_put_contents($path,$pdf);
    }
}

function asistencia_crear_excel(mysqli $conn, array $mensual, array $personal, array $calendario): string {
    [$abs, $rel] = asistencia_upload_dir($mensual);
    $filename = 'Anexos_Asistencia_' . $mensual['codigo_local'] . '_' . $mensual['mes'] . '_' . $mensual['anio'] . '_' . time() . '.xls';
    
    $detalle = asistencia_detalle_map($conn, (int)$mensual['id']);
    $meses = asistencia_mes_nombres();
    $nombreMes = $meses[(int)$mensual['mes']];
    
    // Build HTML content for Excel
    $html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    $html .= '<head>';
    $html .= '<meta http-equiv="Content-type" content="text/html;charset=utf-8" />';
    $html .= '<style>';
    $html .= 'body { font-family: "Segoe UI", Arial, sans-serif; font-size: 11px; }';
    $html .= 'table { border-collapse: collapse; margin-top: 10px; }';
    $html .= 'td, th { border: 0.5pt solid #cbd5cf; padding: 6px; text-align: center; }';
    $html .= '.title-container { background-color: #23b26c; color: white; font-weight: bold; font-size: 14px; text-align: center; height: 35px; vertical-align: middle; }';
    $html .= '.info-header { text-align: left; font-weight: bold; background-color: #f2f7f4; border: none; font-size: 11px; }';
    $html .= '.header-cell { background-color: #198754; color: white; font-weight: bold; font-size: 11px; height: 26px; }';
    $html .= '.weekend { background-color: #f2f2f2; color: #888888; }';
    $html .= '.legend { text-align: left; font-size: 10px; color: #555555; background-color: #fafafa; border: none; padding: 10px; }';
    
    // Status colors matching our Fluent design
    $html .= '.bg-a { background-color: #d1e7dd; color: #0f5132; font-weight: bold; }'; // Asistencia
    $html .= '.bg-i { background-color: #f8d7da; color: #842029; font-weight: bold; }'; // Inasistencia
    $html .= '.bg-j { background-color: #fff3cd; color: #664d03; font-weight: bold; }'; // Justificada
    $html .= '.bg-l { background-color: #cfe2ff; color: #084298; font-weight: bold; }'; // Licencia
    $html .= '.bg-p { background-color: #e2d9f3; color: #563d7c; font-weight: bold; }'; // Permiso
    $html .= '.bg-t { background-color: #e0f7fa; color: #006064; font-weight: bold; }'; // Tardanza
    $html .= '.bg-h { background-color: #f8bbd0; color: #880e4f; font-weight: bold; }'; // Huelga
    
    $html .= '</style>';
    $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Asistencia</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->';
    $html .= '</head>';
    $html .= '<body>';
    
    $totalColumns = 7 + count($calendario) + 1; // N, DNI, Nombres, Cargo, Condicion, Jornada, Nivel + Days + Obs
    
    $html .= '<table>';
    
    // Title Banner
    $html .= '<tr><td colspan="' . $totalColumns . '" class="title-container">ANEXO 03 - REPORTE DE ASISTENCIA DETALLADO</td></tr>';
    
    // Info Rows
    $html .= '<tr><td colspan="' . $totalColumns . '" class="info-header">UGEL: ' . htmlspecialchars($mensual['ugel']) . ' &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; IE: ' . htmlspecialchars($mensual['nombre_ie']) . ' &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; Codigo local: ' . htmlspecialchars($mensual['codigo_local']) . '</td></tr>';
    $html .= '<tr><td colspan="' . $totalColumns . '" class="info-header">Periodo: ' . htmlspecialchars($nombreMes) . ' ' . htmlspecialchars($mensual['anio']) . '</td></tr>';
    $html .= '<tr><td colspan="' . $totalColumns . '" style="border:none; height: 10px;"></td></tr>';
    
    // Headers
    $html .= '<tr>';
    $html .= '<th class="header-cell" style="width: 35px;">N</th>';
    $html .= '<th class="header-cell" style="width: 80px;">DNI</th>';
    $html .= '<th class="header-cell" style="width: 250px; text-align: left;">Apellidos y nombres</th>';
    $html .= '<th class="header-cell" style="width: 150px; text-align: left;">Cargo</th>';
    $html .= '<th class="header-cell" style="width: 100px;">Condicion</th>';
    $html .= '<th class="header-cell" style="width: 60px;">Jornada</th>';
    $html .= '<th class="header-cell" style="width: 100px;">Nivel</th>';
    
    foreach ($calendario as $dia) {
        $class = $dia['laborable'] ? 'header-cell' : 'header-cell weekend';
        $html .= '<th class="' . $class . '" style="width: 30px;">' . $dia['dia'] . '</th>';
    }
    
    $html .= '<th class="header-cell" style="width: 150px; text-align: left;">Observaciones</th>';
    $html .= '</tr>';
    
    // Data Rows
    foreach ($personal as $idx => $trabajador) {
        $html .= '<tr>';
        $html .= '<td>' . ($idx + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($trabajador['dni']) . '</td>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($trabajador['apellidos_nombres']) . '</td>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($trabajador['cargo']) . '</td>';
        $html .= '<td>' . htmlspecialchars($trabajador['condicion_laboral']) . '</td>';
        $html .= '<td>' . htmlspecialchars($trabajador['jornada_laboral']) . '</td>';
        $html .= '<td>' . htmlspecialchars($trabajador['nivel_educativo']) . '</td>';
        
        foreach ($calendario as $dia) {
            if (!$dia['laborable']) {
                $html .= '<td class="weekend">-</td>';
            } else {
                $val = $detalle[(int)$trabajador['id']][(int)$dia['dia']] ?? '';
                $valClass = '';
                if ($val !== '') {
                    $lowerVal = strtolower($val);
                    $valClass = ' bg-' . $lowerVal;
                }
                $html .= '<td class="' . $valClass . '">' . htmlspecialchars($val) . '</td>';
            }
        }
        
        $html .= '<td style="text-align: left;">' . htmlspecialchars($trabajador['observaciones']) . '</td>';
        $html .= '</tr>';
    }
    
    // Blank divider row
    $html .= '<tr><td colspan="' . $totalColumns . '" style="border:none; height: 15px;"></td></tr>';
    
    // Legend Row
    $html .= '<tr><td colspan="' . $totalColumns . '" class="legend"><strong>Leyenda:</strong> A = Día laborado, I = Inasistencia injustificada, J = Inasistencia justificada, L = Licencia sin goce, P = Permiso sin goce, T = Tardanza, H = Huelga o paro.</td></tr>';
    
    // Anexo 04 Section
    $html .= '<tr><td colspan="' . $totalColumns . '" style="border:none; height: 30px;"></td></tr>';
    $html .= '<tr><td colspan="' . $totalColumns . '" class="title-container" style="background-color: #0f62fe;">ANEXO 04 - CONSOLIDADO DE ASISTENCIA MENSUAL</td></tr>';
    $html .= '<tr><td colspan="' . $totalColumns . '" style="border:none; height: 10px;"></td></tr>';
    
    $html .= '<tr>';
    $html .= '<th class="header-cell" style="width: 35px; background-color: #0f62fe;">N</th>';
    $html .= '<th class="header-cell" style="width: 80px; background-color: #0f62fe;">DNI</th>';
    $html .= '<th class="header-cell" style="width: 250px; text-align: left; background-color: #0f62fe;">Apellidos y nombres</th>';
    $html .= '<th class="header-cell" style="width: 80px; background-color: #0f62fe;">Inasistencias (I)</th>';
    $html .= '<th class="header-cell" style="width: 80px; background-color: #0f62fe;">Tardanzas (T)</th>';
    $html .= '<th class="header-cell" style="width: 80px; background-color: #0f62fe;">Permisos (P)</th>';
    $html .= '<th class="header-cell" style="width: 80px; background-color: #0f62fe;">Huelgas (H)</th>';
    $html .= '<th class="header-cell" colspan="' . ($totalColumns - 7) . '" style="text-align: left; background-color: #0f62fe;">Observaciones</th>';
    $html .= '</tr>';
    
    $resumen = asistencia_resumen($conn, (int)$mensual['id']);
    foreach ($personal as $idx => $trabajador) {
        $tot = $resumen[(int)$trabajador['id']] ?? ['I' => 0, 'T' => 0, 'P' => 0, 'H' => 0];
        $html .= '<tr>';
        $html .= '<td>' . ($idx + 1) . '</td>';
        $html .= '<td>' . htmlspecialchars($trabajador['dni']) . '</td>';
        $html .= '<td style="text-align: left;">' . htmlspecialchars($trabajador['apellidos_nombres']) . '</td>';
        $html .= '<td>' . (int)$tot['I'] . '</td>';
        $html .= '<td>' . (int)$tot['T'] . '</td>';
        $html .= '<td>' . (int)$tot['P'] . '</td>';
        $html .= '<td>' . (int)$tot['H'] . '</td>';
        $html .= '<td colspan="' . ($totalColumns - 7) . '" style="text-align: left;">' . htmlspecialchars($trabajador['observaciones'] ?? '') . '</td>';
        $html .= '</tr>';
    }
    
    // Signatures
    $html .= '<tr><td colspan="' . $totalColumns . '" style="border:none; height: 40px;"></td></tr>';
    
    $sigColSpan = (int)floor($totalColumns / 3);
    $remColSpan = $totalColumns - ($sigColSpan * 3);
    
    $html .= '<tr>';
    $html .= '<td colspan="' . $sigColSpan . '" style="border:none; text-align:center;">________________________<br><strong>Director de IE</strong></td>';
    $html .= '<td colspan="' . $sigColSpan . '" style="border:none; text-align:center;">________________________<br><strong>Miembro de APAFA</strong></td>';
    $html .= '<td colspan="' . ($sigColSpan + $remColSpan) . '" style="border:none; text-align:center;">________________________<br><strong>Representante CONEI</strong></td>';
    $html .= '</tr>';
    
    $html .= '</table>';
    $html .= '</body>';
    $html .= '</html>';
    
    file_put_contents($abs . $filename, $html);
    return $rel . $filename;
}

function asistencia_crear_pdf(mysqli $conn, array $mensual, array $personal, array $calendario): string {
    $detalle = asistencia_detalle_map($conn, (int)$mensual['id']);
    $meses = asistencia_mes_nombres();
    
    $pdf = new AsistenciaPlainPDF();
    $pdf->add('L', 'ANEXO 03 - REPORTE DE ASISTENCIA DETALLADO');
    
    // Page dimensions: 841.89 points wide = 297 mm. Printable: 277 mm.
    // Margin: 10 mm left, 10 mm right.
    
    // Info block
    $pdf->text(10, 36, 'UGEL: ' . $mensual['ugel'] . ' | I.E: ' . $mensual['nombre_ie'] . ' | Codigo local: ' . $mensual['codigo_local'], 9, true);
    $pdf->text(10, 41, 'Periodo: ' . $meses[(int)$mensual['mes']] . ' ' . $mensual['anio'], 9, true);
    
    $pdf->y = 46;
    
    // Widths mapping: A4 landscape is 297mm.
    // Let's divide:
    // N: 6
    // DNI: 16
    // Nombres: 45
    // Cargo: 25
    // Days: if we have 31 days, 31 * 5 = 155.
    // Obs: 30.
    // Total = 6 + 16 + 45 + 25 + 155 + 30 = 277 mm. Perfect!
    
    $headers = [['N', true], ['DNI', true], ['Apellidos y nombres', true], ['Cargo', true]];
    $widths = [6, 16, 45, 25];
    
    foreach ($calendario as $dia) {
        $headers[] = [(string)$dia['dia'], true];
        $widths[] = 5;
    }
    
    $headers[] = ['Obs.', true];
    $widths[] = 30;
    
    // Draw header row
    $pdf->row($headers, $widths, 6, 3.5, true);
    
    // Draw workers
    foreach ($personal as $idx => $trabajador) {
        $rowCells = [
            (string)($idx + 1),
            $trabajador['dni'],
            $trabajador['apellidos_nombres'],
            $trabajador['cargo']
        ];
        
        foreach ($calendario as $dia) {
            $rowCells[] = $dia['laborable'] ? ($detalle[(int)$trabajador['id']][(int)$dia['dia']] ?? '') : '-';
        }
        
        $rowCells[] = $trabajador['observaciones'];
        
        $pdf->row($rowCells, $widths, 6, 3.5);
    }
    
    $pdf->y += 5;
    $pdf->text(10, $pdf->y, 'Leyenda: A=Dia laborado, I=Inasistencia injustificada, J=Justificada, L=Licencia sin goce, P=Permiso sin goce, T=Tardanza, H=Huelga/paro.', 7);
    
    $pdf->y += 18;
    // Signatures Anexo 03
    $pdf->break(15);
    $pdf->text(20, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(20, $pdf->y + 4, 'Director de IE', 8, true, 'C', 60);
    
    $pdf->text(100, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(100, $pdf->y + 4, 'Miembro de APAFA', 8, true, 'C', 60);
    
    $pdf->text(180, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(180, $pdf->y + 4, 'Representante CONEI', 8, true, 'C', 60);
    
    // --- PAGE 2: ANEXO 04 ---
    $pdf->add('L', 'ANEXO 04 - CONSOLIDADO DE ASISTENCIA MENSUAL');
    
    // Info block
    $pdf->text(10, 36, 'UGEL: ' . $mensual['ugel'] . ' | I.E: ' . $mensual['nombre_ie'] . ' | Codigo local: ' . $mensual['codigo_local'], 9, true);
    $pdf->text(10, 41, 'Periodo: ' . $meses[(int)$mensual['mes']] . ' ' . $mensual['anio'], 9, true);
    
    $pdf->y = 46;
    
    // Table headers for Anexo 04
    $headers04 = [
        ['N', true],
        ['DNI', true],
        ['Apellidos y nombres', true],
        ['I', true],
        ['T', true],
        ['P', true],
        ['H', true],
        ['Observaciones', true]
    ];
    $widths04 = [10, 25, 95, 12, 12, 12, 12, 99];
    
    $pdf->row($headers04, $widths04, 7, 3.5, true);
    
    $resumen = asistencia_resumen($conn, (int)$mensual['id']);
    
    foreach ($personal as $idx => $trabajador) {
        $tot = $resumen[(int)$trabajador['id']] ?? ['I' => 0, 'T' => 0, 'P' => 0, 'H' => 0];
        $rowCells04 = [
            (string)($idx + 1),
            $trabajador['dni'],
            $trabajador['apellidos_nombres'],
            (string)$tot['I'],
            (string)$tot['T'],
            (string)$tot['P'],
            (string)$tot['H'],
            $trabajador['observaciones'] ?? ''
        ];
        
        $pdf->row($rowCells04, $widths04, 7, 3.5);
    }
    
    $pdf->y += 5;
    $pdf->text(10, $pdf->y, 'Leyenda: I = Inasistencia, T = Tardanza, P = Permiso, H = Huelga/paro.', 7);
    
    $pdf->y += 18;
    // Signatures Anexo 04
    $pdf->break(15);
    $pdf->text(20, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(20, $pdf->y + 4, 'Director de IE', 8, true, 'C', 60);
    
    $pdf->text(100, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(100, $pdf->y + 4, 'Miembro de APAFA', 8, true, 'C', 60);
    
    $pdf->text(180, $pdf->y, '________________________', 8, false, 'C', 60);
    $pdf->text(180, $pdf->y + 4, 'Representante CONEI', 8, true, 'C', 60);
    
    [$abs, $rel] = asistencia_upload_dir($mensual);
    $filename = 'Anexos_Asistencia_' . $mensual['codigo_local'] . '_' . $mensual['mes'] . '_' . $mensual['anio'] . '_' . time() . '.pdf';
    
    $pdf->out($abs . $filename);
    return $rel . $filename;
}

function asistencia_layout_header(string $titulo, array $mensual, int $mes, int $anio): void {
    $meses = asistencia_mes_nombres();
    $currentPage = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');
    $navItems = [
        'index.php' => ['label' => 'Resumen', 'icon' => 'bi-speedometer2'],
        'personal.php' => ['label' => 'Personal', 'icon' => 'bi-people'],
        'registro.php' => ['label' => 'Registro', 'icon' => 'bi-calendar3'],
        'reportes.php' => ['label' => 'Reportes', 'icon' => 'bi-file-earmark-bar-graph'],
    ];
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>' . asistencia_h($titulo) . '</title>';
    echo '<link href="../assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">';
    echo '<link href="../assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">';
    echo '<link rel="stylesheet" href="../assets/css/fluent.css">';
    echo '<style>
body{background:#f3f2f1;color:#1a1a1a;font-size:.94rem}
.asistencia-topbar{background:#1a5c38!important;border-bottom:0!important;box-shadow:0 1px 3px rgba(0,0,0,.08)!important}
.asistencia-topbar .container-fluid{width:min(100% - 48px,1440px);padding-inline:0!important}
.asistencia-brand{min-width:0;gap:10px}
.asistencia-brand__icon{width:36px;height:36px;border-radius:6px;background:rgba(255,255,255,.12);display:inline-flex;align-items:center;justify-content:center;color:#fff!important}
.asistencia-brand__icon .bi{font-size:1.05rem!important}
.asistencia-brand__title{display:block;color:#ff7a1a;font-size:1rem;font-weight:600;line-height:1.15}
.asistencia-brand__subtitle{display:block;color:#d7eadf!important;font-size:.82rem;margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:min(56vw,760px)}
.asistencia-actions{display:flex;gap:8px;align-items:center}
.asistencia-actions .btn{min-height:34px;display:inline-flex;align-items:center;gap:6px;padding-inline:11px;font-weight:500}
.asistencia-shell{width:min(100% - 48px,1440px);margin:0 auto 36px}
.asistencia-nav-wrap{position:sticky;top:62px;z-index:10;background:rgba(243,242,241,.96);backdrop-filter:blur(10px);padding:11px 0 12px}
.asistencia-nav{gap:6px;border-bottom:1px solid #e0e0e0;padding-bottom:8px}
.asistencia-nav .nav-link{border-radius:4px!important;color:#425b4d;font-weight:500;padding:7px 11px;display:flex;align-items:center;gap:6px}
.asistencia-nav .nav-link:hover{background:#e8f5ee;color:#1a5c38}
.asistencia-nav .nav-link.active{background:#1a5c38;color:#fff!important;box-shadow:none}
.asistencia-card{border:1px solid #e0e0e0!important;border-radius:6px!important;box-shadow:0 1px 3px rgba(0,0,0,.08)!important;padding:0!important;overflow:hidden}
.asistencia-card:hover{box-shadow:0 4px 12px rgba(0,0,0,.1)!important}
.asistencia-card .card-header{background:#fff!important;border-bottom:1px solid #e6e6e6!important;padding:13px 16px!important}
.asistencia-card .card-body{padding:16px!important}
.asistencia-title{font-size:1rem;font-weight:600;color:#111827;display:flex;align-items:center;gap:8px;margin:0}
.asistencia-title .bi{font-size:1rem!important;color:#e65c00}
.asistencia-muted{color:#666}
.asistencia-form-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.035em;color:#52635b;font-weight:600;margin-bottom:5px}
.form-control,.form-select{min-height:36px;border-color:#d9dfdc!important;font-size:.92rem}
.btn-orange{background:#e65c00!important;border-color:#e65c00!important;color:#fff!important}
.btn-orange:hover{background:#c94f00!important;border-color:#c94f00!important}
.asistencia-info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px 12px}
.asistencia-info-item{background:#fafafa;border:1px solid #e4e8e6;border-radius:5px;padding:9px 11px}
.asistencia-info-label{display:block;color:#5f6f67;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.035em;margin-bottom:3px}
.asistencia-info-value{font-weight:500;color:#1a1a1a}
.asistencia-status{display:inline-flex;align-items:center;border-radius:999px;padding:4px 9px;font-weight:500;font-size:.72rem;text-transform:uppercase;letter-spacing:.02em}
.asistencia-progress-row{display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #e6e6e6;padding:9px 0;font-size:.9rem}
.asistencia-progress-row strong{font-size:.96rem;font-weight:600;color:#1a1a1a}
.asistencia-alert{border-radius:5px;border-width:1px;padding:10px 12px}
.asistencia-action-card{position:relative;display:block;border:1px solid #e0e0e0!important;border-radius:6px!important;box-shadow:0 1px 3px rgba(0,0,0,.08)!important;padding:0!important;overflow:hidden;transition:box-shadow .16s ease,border-color .16s ease}
.asistencia-action-card:hover{border-color:#a8d5b5!important;box-shadow:0 4px 12px rgba(0,0,0,.1)!important}
.asistencia-action-card .card-body{padding:18px!important}
.asistencia-action-icon{width:38px;height:38px;border-radius:5px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px}
.asistencia-action-icon .bi{font-size:1.2rem!important}
.asistencia-action-title{font-size:1rem;font-weight:600;color:#1a1a1a;margin-bottom:5px}
.asistencia-table-card .table{margin-bottom:0}
.asistencia-table-card .table thead th{vertical-align:middle;white-space:nowrap}
.asistencia-grid{font-size:.82rem}
.asistencia-day{min-width:42px}
.weekend{background:#eef2f0!important;color:#819088!important}
.code-select{min-width:46px;padding:2px 4px;min-height:30px;font-size:.8rem}
.table-sticky th{position:sticky;top:0;z-index:2}
.worker-cell{min-width:220px;position:sticky;left:0;background:#fff;z-index:1;box-shadow:1px 0 0 #e5ece8}
.asistencia-legend{display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:#fafafa;border:1px solid #e6e6e6;border-radius:6px;padding:9px 11px}
.asistencia-legend .badge{min-height:22px;display:inline-flex;align-items:center;font-weight:500}
@media (max-width:768px){
  .asistencia-topbar .container-fluid,.asistencia-shell{width:min(100% - 24px,1440px)}
  .asistencia-topbar .container-fluid{align-items:flex-start!important;gap:12px}
  .asistencia-actions{width:100%;justify-content:flex-start;flex-wrap:wrap}
  .asistencia-brand__subtitle{max-width:calc(100vw - 96px);white-space:normal}
  .asistencia-nav-wrap{top:0;position:relative;padding-top:12px}
  .asistencia-nav{overflow-x:auto;flex-wrap:nowrap;padding-bottom:8px}
  .asistencia-nav .nav-link{white-space:nowrap}
  .asistencia-info-grid{grid-template-columns:1fr}
  .asistencia-card .card-body,.asistencia-action-card .card-body{padding:14px!important}
}
</style>';
    echo '</head><body><nav class="navbar navbar-expand navbar-light navbar-custom asistencia-topbar sticky-top"><div class="container-fluid d-flex flex-wrap align-items-center">';
    echo '<a class="navbar-brand asistencia-brand d-flex align-items-center me-auto" href="../enviar_documento.php?tipo=Mensual"><span class="asistencia-brand__icon"><i class="bi bi-calendar-check"></i></span><span class="min-w-0"><span class="asistencia-brand__title">Modulo de Asistencia</span><small class="asistencia-brand__subtitle">' . asistencia_h($mensual['nombre_ie']) . ' - ' . asistencia_h($meses[$mes] . ' ' . $anio) . '</small></span></a>';
    echo '<div class="asistencia-actions"><a class="btn btn-outline-light" href="../dashboard.php"><i class="bi bi-house-door"></i><span>Inicio</span></a><a class="btn btn-orange" href="../enviar_documento.php?tipo=Mensual"><i class="bi bi-arrow-left"></i><span>Envios</span></a></div>';
    echo '</div></nav><main class="asistencia-shell">';
    echo '<div class="asistencia-nav-wrap"><ul class="nav nav-pills asistencia-nav">';
    foreach ($navItems as $page => $item) {
        $active = $currentPage === $page ? ' active' : '';
        echo '<li class="nav-item"><a class="nav-link' . $active . '" href="' . asistencia_url($page, $mes, $anio) . '"><i class="bi ' . $item['icon'] . '"></i>' . $item['label'] . '</a></li>';
    }
    echo '</ul></div>';
}

function asistencia_layout_footer(): void {
    echo '</main><script src="../assets/lib/bootstrap/bootstrap.bundle.min.js"></script></body></html>';
}
?>
