<?php
session_start();
if (!isset($_SESSION['id_usuario'])) { header('Location: login.php'); exit(); }
if (($_SESSION['rol'] ?? '') === 'jefe_agp') { header('Location: jefe_crear_fichas.php'); exit(); }
header('Location: especialista_menu_fichas.php'); exit();
?>
