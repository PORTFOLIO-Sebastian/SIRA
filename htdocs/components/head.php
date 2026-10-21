<?php
/**
 * <head> compartido para todo el sistema.
 * Carga Bootstrap, los iconos y el sistema de diseño fluent.css en un solo lugar.
 *
 * Uso:
 *   $page_title = 'Mi página';            // opcional
 *   $extra_head = '<link ...>';           // opcional (CSS/JS extra)
 *   include 'components/head.php';
 */
$page_title = $page_title ?? 'Sistema Documental - UGEL Chincha';
$extra_head = $extra_head ?? '';
?>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/css/fluent.css">
<?php echo $extra_head; ?>
