<?php
/**
 * Genera el SQL de importación a la BD destino (esquema 4582023_ugel).
 * - usuarios:     desde usuarios_full.json (campos separados + password real)
 * - instituciones:desde instituciones.json
 * - asignaciones / asignaciones_especialistas: resuelven FK por JOIN (dni / codigo_local)
 *
 * Es ADITIVO e IDEMPOTENTE: usa INSERT IGNORE / NOT EXISTS, se puede re-ejecutar.
 * Salida: import_sia_a_destino.sql
 */
$OUTDIR = __DIR__ . '/data';
$SQL    = __DIR__ . '/import_sia_a_destino.sql';

function fixmoji($s){ // repara doble-codificación UTF-8 (Ñ->Ã‘, í->Ã­) de forma segura
    if ($s === null || $s === '') return $s;
    if (!preg_match('/Ã.|Â.|â€/u', $s)) return $s;            // sin señal de mojibake: no tocar
    $conv = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
    if ($conv !== false && $conv !== '' && mb_check_encoding($conv, 'UTF-8')) return $conv;
    return $s;                                                 // si no queda UTF-8 válido, dejar original
}
function q($v){ // escapa y entrecomilla; '' o '-' -> NULL
    if ($v === null || trim((string)$v) === '' || trim((string)$v) === '-') return 'NULL';
    $v = fixmoji($v);
    return "'" . str_replace(["\\","'"], ["\\\\","''"], $v) . "'";
}
function qstr($v){ $v = fixmoji((string)$v); return "'" . str_replace(["\\","'"], ["\\\\","''"], $v) . "'"; }
function fecha($v){ $v=trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v) ? "'$v'" : 'NULL'; }
function estado_asig($v){ return (stripos((string)$v,'vigente')!==false) ? "'activo'" : "'inactivo'"; }

$usuarios = json_decode(file_get_contents("$OUTDIR/usuarios_full.json"), true);
$insts    = json_decode(file_get_contents("$OUTDIR/instituciones.json"), true);
$aIe      = json_decode(file_get_contents("$OUTDIR/asignaciones_ie.json"), true);
$aEsp     = json_decode(file_get_contents("$OUTDIR/asignaciones_especialistas.json"), true);

$out  = [];
$out[] = "-- ============================================================";
$out[] = "-- Importación de datos reales de sia.ugelchincha.gob.pe";
$out[] = "-- a la base destino (esquema 4582023_ugel).";
$out[] = "-- Generado: " . date('Y-m-d H:i');
$out[] = "-- Aditivo e idempotente (se puede re-ejecutar sin duplicar).";
$out[] = "-- ============================================================";
$out[] = "SET NAMES utf8mb4;";
$out[] = "SET sql_mode='';";
$out[] = "-- Selecciona la base destino (evita el error #1046). Cambia el nombre si tu BD es otra.";
$out[] = "USE `4582023_ugel`;";
$out[] = "START TRANSACTION;";
$out[] = "";

/* ---- SIN LIMPIEZA: import 100% aditivo ----
   No se borra la semilla porque algunos usuarios de prueba están referenciados
   por fm_registros (FK ON DELETE RESTRICT) y MySQL no permite eliminarlos.
   Los 3 DNI de prueba que ya existen (11111111, 22222222, 33333333) se omiten
   por INSERT IGNORE; el resto de datos reales entra sin problemas. */
$out[] = "-- Import aditivo (no borra datos existentes).";
$out[] = "";

/* ---------- 1) USUARIOS ---------- */
$out[] = "-- ---------- USUARIOS (".count($usuarios).") | clave natural: dni | password = DNI ----------";
$rows = [];
foreach ($usuarios as $u) {
    $pass = $u['dni'];   // contraseña = DNI (solicitado por el usuario)
    $rows[] = "(" . implode(",", [
        qstr($u['dni']), qstr($pass),
        qstr($u['nombres']), qstr($u['ape_pat']), q($u['ape_mat']),
        q($u['correo']), q($u['telefono']),
        qstr($u['rol']), qstr($u['estado'])
    ]) . ")";
}
$out[] = "INSERT IGNORE INTO usuarios (dni,password,nombres,ape_pat,ape_mat,correo,telefono,rol,estado) VALUES";
$out[] = implode(",\n", $rows) . ";";
$out[] = "";

/* ---------- 2) INSTITUCIONES ---------- */
$out[] = "-- ---------- INSTITUCIONES (".count($insts).") | clave natural: codigo_local | NOT EXISTS evita duplicar ----------";
foreach ($insts as $i) {
    $cols = [
        qstr($i['codigo_local']), qstr($i['nombre_ie']), q($i['codigo_institucion']),
        q($i['nivel_modalidad']), q($i['tipo_gestion']), q($i['direccion']),
        q($i['centro_poblado']), q($i['distrito'])
    ];
    $out[] = "INSERT INTO instituciones (codigo_local,nombre_ie,codigo_institucion,nivel_modalidad,tipo_gestion,direccion,centro_poblado,distrito) "
           . "SELECT " . implode(",", $cols) . " FROM DUAL "
           . "WHERE NOT EXISTS (SELECT 1 FROM instituciones WHERE codigo_local=" . qstr($i['codigo_local']) . ");";
}
$out[] = "";

/* ---------- 3) ASIGNACIONES (IE) ---------- */
$out[] = "-- ---------- ASIGNACIONES I.E. (".count($aIe).") | FK por JOIN dni + codigo_local ----------";
foreach ($aIe as $a) {
    $out[] = "INSERT INTO asignaciones (id_usuario,id_ie,fecha_inicio,fecha_fin,estado) "
        . "SELECT u.id,i.id," . fecha($a['fecha_inicio']) . ",NULL," . estado_asig($a['estado']) . " "
        . "FROM usuarios u JOIN instituciones i ON i.codigo_local=" . qstr($a['codigo_local']) . " "
        . "WHERE u.dni=" . qstr($a['dni']) . " "
        . "AND NOT EXISTS (SELECT 1 FROM asignaciones x WHERE x.id_usuario=u.id AND x.id_ie=i.id);";
}
$out[] = "";

/* ---------- 4) ASIGNACIONES ESPECIALISTAS ---------- */
$out[] = "-- ---------- ASIGNACIONES ESPECIALISTAS (".count($aEsp).") | sub_tipo = categoría ----------";
foreach ($aEsp as $a) {
    $out[] = "INSERT INTO asignaciones_especialistas (id_usuario,id_institucion,sub_tipo,fecha_inicio,fecha_fin,estado) "
        . "SELECT u.id,i.id," . q($a['categoria']) . "," . fecha($a['fecha_inicio']) . ",NULL," . estado_asig($a['estado']) . " "
        . "FROM usuarios u JOIN instituciones i ON i.codigo_local=" . qstr($a['codigo_local']) . " "
        . "WHERE u.dni=" . qstr($a['dni']) . " "
        . "AND NOT EXISTS (SELECT 1 FROM asignaciones_especialistas x WHERE x.id_usuario=u.id AND x.id_institucion=i.id);";
}
$out[] = "";
$out[] = "COMMIT;";
$out[] = "";
$out[] = "-- Verificación rápida:";
$out[] = "-- SELECT COUNT(*) FROM usuarios; SELECT COUNT(*) FROM instituciones;";
$out[] = "-- SELECT COUNT(*) FROM asignaciones; SELECT COUNT(*) FROM asignaciones_especialistas;";

file_put_contents($SQL, implode("\n", $out));
$bytes = filesize($SQL);
echo "Generado: import_sia_a_destino.sql ($bytes bytes)\n";
echo "  usuarios: ".count($usuarios)." | instituciones: ".count($insts)
   . " | asig_ie: ".count($aIe)." | asig_esp: ".count($aEsp)."\n";
