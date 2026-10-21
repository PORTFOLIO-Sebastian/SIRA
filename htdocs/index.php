<?php
// 1. Forzamos a que se muestren errores por si algo falla
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// 2. Intentamos la redirección por PHP
// IMPORTANTE: Asegúrate de que no haya ni un espacio antes de <?php
header("Location: login.php");

// 3. Si el servidor bloquea el header, usamos HTML/JS como respaldo
echo '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="refresh" content="0;url=login.php">
    <title>Redireccionando...</title>

<link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/css/fluent.css">
</head>
<body>
    <script type="text/javascript">
        window.location.href = "login.php";
    </script>
    <p>Si no eres redirigido, <a href="login.php">haz clic aquí</a>.</p>
</body>
</html>';
exit;
?>
