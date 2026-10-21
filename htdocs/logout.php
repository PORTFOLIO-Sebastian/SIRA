<?php
ob_start(); // Activa el almacenamiento en búfer para evitar el error de cabeceras
session_start();

// 1. Limpieza total de variables
$_SESSION = array();

// 2. Destruir la cookie de sesión
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 86400, '/');
}

// 3. Destruir sesión y cerrar escritura
session_destroy();
session_write_close();

// 4. Forzar limpieza de caché
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// 5. Redirección con timestamp para que el navegador no use la memoria
$url = "login.php?status=logged_out&v=" . time();
header("Location: " . $url);

// 6. Respaldo por si el servidor falla
echo "<script>window.location.replace('$url');</script>";
exit();