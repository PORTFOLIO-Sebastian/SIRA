<?php
/**
 * Segundo pase: enriquece usuarios leyendo el formulario de edición de sia
 * (?view=usuarios&edit=ID), que trae los campos YA separados y la contraseña real.
 *
 * Requiere cookies.txt (sesión activa). Si expiró, corre antes scrape_sia.php.
 * Salida: data/usuarios_full.{json,csv}
 */
date_default_timezone_set('America/Lima');
$BASE   = 'https://sia.ugelchincha.gob.pe/htdocs';
$COOKIE = __DIR__ . '/cookies.txt';
$OUTDIR = __DIR__ . '/data';
$DNI    = getenv('SIA_DNI') ?: '11111111';
$PASS   = getenv('SIA_PASS') ?: 'Ugel26';

function http_get($url){
    global $COOKIE;
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_COOKIEFILE=>$COOKIE,CURLOPT_COOKIEJAR=>$COOKIE,CURLOPT_TIMEOUT=>40,
        CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'sia-extractor/1.0']);
    $b=curl_exec($ch); curl_close($ch); return $b;
}
function login(){
    global $BASE,$DNI,$PASS,$COOKIE;
    $ch=curl_init("$BASE/login.php");
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['dni'=>$DNI,'password'=>$PASS]),
        CURLOPT_COOKIEJAR=>$COOKIE,CURLOPT_COOKIEFILE=>$COOKIE,CURLOPT_TIMEOUT=>40,CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch); curl_close($ch);
}

function field($x,$name){
    $n=$x->query("//*[@name='".$name."']")->item(0);
    if(!$n) return '';
    if($n->nodeName==='select'){
        $sel=$x->query(".//option[@selected]",$n)->item(0);
        return $sel ? $sel->getAttribute('value') : '';
    }
    return $n->getAttribute('value');
}

$users = json_decode(file_get_contents("$OUTDIR/usuarios.json"), true);
$total = count($users);
echo "Enriqueciendo $total usuarios...\n";
$out = [];
$i = 0;
foreach($users as $u){
    $id = $u['id'];
    $h = http_get("$BASE/administrador.php?view=usuarios&edit=$id");
    if (stripos($h,'name="nombres"')===false){ login(); $h=http_get("$BASE/administrador.php?view=usuarios&edit=$id"); }
    $d=new DOMDocument(); libxml_use_internal_errors(true); $d->loadHTML($h);
    $x=new DOMXPath($d);
    $out[]=[
        'id'       => $id,
        'dni'      => $u['dni'],                       // dni viene del listado (el form lo tiene como buscador)
        'nombres'  => trim(field($x,'nombres')),
        'ape_pat'  => trim(field($x,'ape_pat')),
        'ape_mat'  => trim(field($x,'ape_mat')),
        'correo'   => trim(field($x,'correo')),
        'telefono' => trim(field($x,'telefono')),
        'password' => field($x,'password'),
        'rol'      => field($x,'rol') ?: $u['rol'],
        'estado'   => field($x,'estado') ?: $u['estado'],
    ];
    $i++;
    if($i%10==0 || $i==$total){ echo "\r  $i/$total   "; }
    usleep(120000);
}
echo "\n";

file_put_contents("$OUTDIR/usuarios_full.json", json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
$fp=fopen("$OUTDIR/usuarios_full.csv",'w'); fprintf($fp,"\xEF\xBB\xBF");
fputcsv($fp,array_keys($out[0]));
foreach($out as $r) fputcsv($fp,$r);
fclose($fp);

// pequeño reporte de calidad
$sinNom=count(array_filter($out,fn($r)=>$r['nombres']===''));
$sinApe=count(array_filter($out,fn($r)=>$r['ape_pat']===''));
$sinPass=count(array_filter($out,fn($r)=>$r['password']===''));
echo "Guardado usuarios_full ($total filas). Sin nombres: $sinNom | sin ape_pat: $sinApe | sin password: $sinPass\n";
