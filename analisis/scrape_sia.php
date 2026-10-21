<?php
/**
 * Extractor de datos de sia.ugelchincha.gob.pe (panel administrador).
 * Hace login, recorre TODAS las páginas de cada vista y exporta a CSV + JSON.
 *
 * Uso:  php scrape_sia.php
 * Salida: analisis/data/*.csv y *.json
 *
 * NOTA: las credenciales se leen de variables de entorno si existen,
 * si no, usa las definidas abajo.
 */

date_default_timezone_set('America/Lima');

$BASE  = 'https://sia.ugelchincha.gob.pe/htdocs';
$DNI   = getenv('SIA_DNI') ?: '11111111';
$PASS  = getenv('SIA_PASS') ?: 'Ugel26';
$COOKIE = __DIR__ . '/cookies.txt';
$OUTDIR = __DIR__ . '/data';
if (!is_dir($OUTDIR)) mkdir($OUTDIR, 0777, true);

/* ---------- helpers HTTP ---------- */
function http_get($url) {
    global $COOKIE;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'sia-extractor/1.0',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $loc  = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return [$body, $code, $loc];
}

function login() {
    global $BASE, $DNI, $PASS, $COOKIE;
    @unlink($COOKIE);
    $ch = curl_init("$BASE/login.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['dni'=>$DNI, 'password'=>$PASS]),
        CURLOPT_COOKIEJAR      => $COOKIE,
        CURLOPT_COOKIEFILE     => $COOKIE,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    curl_exec($ch);
    $loc = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    if (strpos((string)$loc, 'administrador.php') === false) {
        fwrite(STDERR, "ERROR: login falló (redirect a: $loc)\n");
        exit(1);
    }
    echo "Login OK\n";
}

/* ---------- helpers parsing ---------- */
function txt($node){ return $node ? trim(preg_replace('/\s+/', ' ', $node->textContent)) : ''; }

/** Devuelve [filas, total] de una vista ya descargada en $html */
function parse_total($html){
    if (preg_match('/de un total de\s*([0-9]+)/i', $html, $m)) return (int)$m[1];
    return 0;
}

/** Extrae el id numérico de un href tipo ...edit_x=ID... */
function id_from_links($tr, $xp, $pattern){
    foreach ($xp->query('.//a', $tr) as $a) {
        if (preg_match($pattern, $a->getAttribute('href'), $m)) return $m[1];
    }
    return '';
}

/* Cada vista: cómo recorrer páginas y parsear filas */
function scrape_view($view, $pageParam, $parser){
    global $BASE;
    [$html] = http_get("$BASE/administrador.php?view=$view");
    $total = parse_total($html);
    $pages = max(1, (int)ceil($total / 10));
    echo "  $view: total=$total, páginas=$pages\n";
    $rows = [];
    for ($p = 1; $p <= $pages; $p++) {
        [$h, $code] = http_get("$BASE/administrador.php?view=$view&$pageParam=$p");
        if ($code != 200 || stripos($h, 'login.php') !== false && stripos($h,'Listado')===false && stripos($h,'Registros')===false) {
            // sesión caída: re-login una vez
            login();
            [$h] = http_get("$BASE/administrador.php?view=$view&$pageParam=$p");
        }
        $d = new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML($h);
        $xp = new DOMXPath($d);
        $trs = $xp->query('//table//tbody/tr');
        if ($trs->length === 0) $trs = $xp->query('//table//tr'); // fallback
        foreach ($trs as $tr) {
            $tds = $xp->query('./td', $tr);
            if ($tds->length < 3) continue; // saltar cabeceras / filas vacías
            $row = $parser($tr, $tds, $xp);
            if ($row) $rows[] = $row;
        }
        usleep(150000); // 0.15s, cortesía con el servidor
        echo "\r    página $p/$pages   ";
    }
    echo "\n";
    return $rows;
}

/* ---------- parsers por vista ---------- */
$P_usuarios = function($tr,$tds,$xp){
    $contacto = $tds->item(3);
    $divs = $xp->query('.//div', $contacto);
    $correo = $divs->length>0 ? txt($divs->item(0)) : '';
    $tel    = $divs->length>1 ? txt($divs->item(1)) : '';
    $rolEst = $tds->item(4);
    $spans = $xp->query('.//span', $rolEst);
    $rol    = $spans->length>0 ? txt($spans->item(0)) : '';
    $estado = $spans->length>1 ? txt($spans->item(1)) : '';
    return [
        'id'              => txt($tds->item(0)),
        'dni'             => txt($tds->item(1)),
        'nombre_completo' => txt($tds->item(2)),
        'correo'          => ($correo === '-' ? '' : $correo),
        'telefono'        => ($tel === '-' ? '' : $tel),
        'rol'             => strtolower($rol),
        'estado'          => strtolower($estado),
    ];
};

$P_instituciones = function($tr,$tds,$xp){
    $nom = $tds->item(1);
    $nivelGes = $tds->item(3);
    $ubic = $tds->item(4);
    $dn = $xp->query('.//div', $nivelGes);
    $du = $xp->query('.//div', $ubic);
    $smallDir = $xp->query('.//small', $nom)->item(0);
    $smallCp  = $xp->query('.//small', $ubic)->item(0);
    return [
        'id'                 => id_from_links($tr,$xp,'/edit_ie=([0-9]+)/'),
        'codigo_local'       => txt($tds->item(0)),
        'nombre_ie'          => txt($xp->query('.//div',$nom)->item(0)),
        'direccion'          => $smallDir ? txt($smallDir) : '',
        'codigo_institucion' => txt($tds->item(2)),
        'nivel_modalidad'    => $dn->length>0 ? txt($dn->item(0)) : '',
        'tipo_gestion'       => $dn->length>1 ? txt($dn->item(1)) : '',
        'distrito'           => $du->length>0 ? txt($du->item(0)) : '',
        'centro_poblado'     => $smallCp ? txt($smallCp) : '',
    ];
};

$P_asig_ie = function($tr,$tds,$xp){
    $usu = $tds->item(1);
    $ie  = $tds->item(2);
    $badgeDni = $xp->query('.//span', $usu)->item(0);
    $nomUsu   = $xp->query('.//small', $usu)->item(0);
    $nomIe    = $xp->query('.//div', $ie)->item(0);
    $codIe    = $xp->query('.//span', $ie)->item(0);
    $detIe    = $xp->query('.//small', $ie)->item(0);
    $cod = $codIe ? txt($codIe) : '';
    $cod = preg_replace('/^\s*C[óo]d\.?:\s*/iu', '', $cod);
    return [
        'id'            => txt($tds->item(0)),
        'dni'           => $badgeDni ? txt($badgeDni) : '',
        'usuario'       => $nomUsu ? txt($nomUsu) : '',
        'nombre_ie'     => $nomIe ? txt($nomIe) : '',
        'codigo_local'  => $cod,
        'detalle_ie'    => $detIe ? txt($detIe) : '',
        'fecha_inicio'  => txt($tds->item(3)),
        'estado'        => txt($tds->item(4)),
    ];
};

$P_asig_esp = function($tr,$tds,$xp){
    $usu = $tds->item(1);
    $ie  = $tds->item(3);
    $badgeDni = $xp->query('.//span', $usu)->item(0);
    $nomUsu   = $xp->query('.//small', $usu)->item(0);
    $nomIe    = $xp->query('.//div', $ie)->item(0);
    $codIe    = $xp->query('.//span', $ie)->item(0);
    $detIe    = $xp->query('.//small', $ie)->item(0);
    $cod = $codIe ? txt($codIe) : '';
    $cod = preg_replace('/^\s*C[óo]d\.?:\s*/iu', '', $cod);
    return [
        'id'            => txt($tds->item(0)),
        'dni'           => $badgeDni ? txt($badgeDni) : '',
        'especialista'  => $nomUsu ? txt($nomUsu) : '',
        'categoria'     => txt($tds->item(2)),
        'nombre_ie'     => $nomIe ? txt($nomIe) : '',
        'codigo_local'  => $cod,
        'detalle_ie'    => $detIe ? txt($detIe) : '',
        'fecha_inicio'  => txt($tds->item(4)),
        'estado'        => txt($tds->item(5)),
    ];
};

/* ---------- salida ---------- */
function save($name, $rows){
    global $OUTDIR;
    file_put_contents("$OUTDIR/$name.json", json_encode($rows, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    $fp = fopen("$OUTDIR/$name.csv", 'w');
    fprintf($fp, "\xEF\xBB\xBF"); // BOM para Excel
    if ($rows) {
        fputcsv($fp, array_keys($rows[0]));
        foreach ($rows as $r) fputcsv($fp, $r);
    }
    fclose($fp);
    echo "  -> $name: ".count($rows)." filas guardadas (CSV + JSON)\n";
}

/* ---------- main ---------- */
login();
echo "Extrayendo...\n";
save('usuarios',                  scrape_view('usuarios',                 'page_user', $P_usuarios));
save('instituciones',             scrape_view('instituciones',            'page',      $P_instituciones));
save('asignaciones_ie',           scrape_view('Asignacion_ie',            'page_asig', $P_asig_ie));
save('asignaciones_especialistas',scrape_view('asignacion_especialistas', 'page_esp',  $P_asig_esp));
echo "Listo. Archivos en: $OUTDIR\n";
