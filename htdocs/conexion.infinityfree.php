<?php
// ============================================================
//  CONFIGURACIÓN PARA InfinityFree
//  ⚠️  Reemplaza los valores PLACEHOLDER con los datos reales
//      que obtengas del panel de InfinityFree
// ============================================================

// 1. Servidor MySQL (lo verás en: Panel → MySQL Databases → "MySQL Server")
//    Ejemplo real: sql305.infinityfree.com  o  sql210.infinityfree.com
$servername = "sql3XX.infinityfree.com";   // ← REEMPLAZA con tu host MySQL

// 2. Usuario MySQL (lo ves al crear la BD en el panel)
//    Formato: epiz_XXXXXXXX_ugel
$username = "epiz_XXXXXXXX_ugel";          // ← REEMPLAZA con tu usuario

// 3. Contraseña que elegiste al crear la base de datos
$password = "TU_CONTRASEÑA_AQUI";          // ← REEMPLAZA con tu contraseña

// 4. Nombre de la base de datos
//    Formato: epiz_XXXXXXXX_ugel
$dbname = "epiz_XXXXXXXX_ugel";            // ← REEMPLAZA con tu nombre de BD

// ============================================================
//  NO MODIFICAR A PARTIR DE AQUÍ
// ============================================================

// Crear conexión
$conn = new mysqli($servername, $username, $password, $dbname);

// Comprobar conexión
if ($conn->connect_error) {
    die("Conexión fallida: " . $conn->connect_error);
}

// Charset UTF-8 completo (soporta emojis, caracteres especiales)
$conn->set_charset("utf8mb4");

// Zona horaria de Perú (UTC-5)
date_default_timezone_set('America/Lima');
$conn->query("SET time_zone = '-05:00'");
