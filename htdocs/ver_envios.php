<?php
session_start();
include 'conexion.php';

// Validar sesión
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = $_SESSION['id_usuario'];
$inicio_url = (strtolower($_SESSION['rol'] ?? '') === 'especialista') ? 'dashboard_especialista.php' : 'dashboard.php';

// Obtener datos del usuario especialista y su sub_tipo
$query_user = "SELECT u.nombres, u.ape_pat, u.ape_mat, u.correo, u.dni, ae.sub_tipo 
          FROM usuarios u
          LEFT JOIN asignaciones_especialistas ae ON u.id = ae.id_usuario AND ae.estado = 'activo'
          WHERE u.id = ? LIMIT 1";

$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("i", $id_usuario);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$nombre_completo = $user_data['nombres'] . " " . $user_data['ape_pat'] . " " . $user_data['ape_mat'];
$sub_tipo_usuario = $user_data['sub_tipo'] ?? 'Sin Asignar';

// Construir la consulta dinámica para los filtros de búsqueda
$where_clauses = ["1=1"]; 
// Condición base

// CUARTO: Limitar los archivos según la institución asignada al especialista
$where_clauses[] = "e.id_ie IN (SELECT id_institucion FROM asignaciones_especialistas WHERE id_usuario = '$id_usuario' AND estado = 'activo')";

if (!empty($_GET['nombre_ie'])) {
    $nombre_ie = $conn->real_escape_string($_GET['nombre_ie']);
    $where_clauses[] = "i.nombre_ie LIKE '%$nombre_ie%'";
}
if (!empty($_GET['codigo_local'])) {
    $codigo_local = $conn->real_escape_string($_GET['codigo_local']);
    $where_clauses[] = "i.codigo_local = '$codigo_local'";
}
if (!empty($_GET['mes'])) {
    $mes = $conn->real_escape_string($_GET['mes']);
    $where_clauses[] = "e.mes = '$mes'";
}
if (!empty($_GET['tipo'])) {
    $tipo = $conn->real_escape_string($_GET['tipo']);
    $where_clauses[] = "e.tipo_documento = '$tipo'";
}

$where_sql = implode(" AND ", $where_clauses);

// CONFIGURACIÓN DE PAGINACIÓN A 10 FILAS (TERCERO)
$registros_por_pagina = 10;
$pagina_actual = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if($pagina_actual < 1) $pagina_actual = 1;
$offset = ($pagina_actual - 1) * $registros_por_pagina;

// Contar total de registros para paginación
$sql_count = "SELECT COUNT(*) as total FROM envios e 
            INNER JOIN (
                SELECT MAX(id) as max_id 
                FROM envios 
                GROUP BY id_ie, anio, tipo_documento, IFNULL(mes, 0)
            ) as ultimos ON e.id = ultimos.max_id
            LEFT JOIN instituciones i ON e.id_ie = i.id 
            WHERE $where_sql";

$res_count = $conn->query($sql_count);
$total_registros = $res_count->fetch_assoc()['total'];
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Consulta optimizada: Obtiene SOLO el último envío de cada tipo y aplica los filtros con LIMIT
$sql = "SELECT e.*, i.codigo_local, i.nombre_ie 
        FROM envios e 
        INNER JOIN (
            SELECT MAX(id) as max_id 
            FROM envios 
            GROUP BY id_ie, anio, tipo_documento, IFNULL(mes, 0)
        ) 
        as ultimos ON e.id = ultimos.max_id
        LEFT JOIN instituciones i ON e.id_ie = i.id 
        WHERE $where_sql
        ORDER BY e.fecha_envio DESC
        LIMIT $offset, $registros_por_pagina";

$resultado = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Módulo del Especialista - UGEL</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        :root { --main-green: #198754; }
        body { 
            background-color: #f8fdf9;
            /* Fondo muy claro con un toque verde */
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        
        /* Cabecera del Filtro */
        .filter-box { 
            background-color: #198754;
            color: white; 
            border-radius: 8px 8px 0 0; 
            padding: 12px 20px; 
            font-weight: bold; 
            font-size: 1.1rem;
        }
        /* Cuerpo del Filtro */
        .filter-body { 
            background-color: white;
            padding: 25px; 
            border: 1px solid #d1e7dd; 
            border-top: none; 
            border-radius: 0 0 8px 8px; 
            margin-bottom: 25px;
        }
        /* Tabla */
        .table-custom-header th { 
            background-color: #198754 !important;
            color: white !important; 
            font-weight: 600;
            white-space: nowrap;
        }
        .table { border-color: #d1e7dd; }
        .table-hover tbody tr:hover { background-color: #eaf5ec; }
        
        /* Botones personalizados */
        .btn-green { 
            background-color: #198754;
            color: white; 
            border: none; 
        }
        .btn-green:hover { background-color: #146c43; color: white; }
        .btn-outline-green {
            color: #198754;
            border-color: #198754;
        }
        .btn-outline-green:hover {
            background-color: #198754;
            color: white;
        }

        /* Utilidades Responsive */
        @media (max-width: 768px) {
            .navbar-brand span { font-size: 1rem !important; }
            .navbar-brand small { font-size: 0.65rem !important; }
            .navbar-custom .ms-auto { width: 100%; justify-content: flex-end; margin-top: 10px; }
            .table td, .table th { font-size: 0.9rem; }
            .filter-body { padding: 15px; }
        }
        
        @media (max-width: 576px) {
            .navbar-custom .container-fluid { flex-direction: column; align-items: stretch !important; gap: 10px; }
            .navbar-custom .ms-auto { justify-content: center; flex-wrap: wrap; gap: 8px; }
            .profile-btn { width: 100%; justify-content: center; margin-top: 5px; }
            .d-flex.justify-content-between.align-items-center.mb-5 { flex-direction: column; gap: 15px; text-align: center; }
            .pagination-container { justify-content: center !important; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light ugel-topbar mb-4 sticky-top shadow-sm">
    <div class="container-fluid px-3 px-md-4">
        <!-- Hamburger (mobile) -->
        <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
            <i class="bi bi-list"></i>
        </button>
        <a class="navbar-brand d-flex align-items-center mb-2 mb-lg-0" href="dashboard_especialista.php">
            <i class="bi bi-shield-check ugel-brand-icon fs-2 me-2"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Especialista</small>
            </div>
        </a>
        
        <div class="ms-auto d-flex flex-wrap align-items-center gap-2">
            <a href="<?php echo htmlspecialchars($inicio_url); ?>" class="btn profile-btn d-flex align-items-center gap-1">
                <i class="bi bi-house-door-fill me-1"></i> <span>Inicio</span>
            </a>
            <a href="Publicacion_Comunicados.php" class="btn profile-btn d-flex align-items-center gap-1">
                <i class="bi bi-megaphone-fill me-1"></i> <span>Comunicados</span>
            </a>
            <div class="dropdown">
                <button class="btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase">Especialista</small>
                        <strong><?php echo htmlspecialchars($user_data['nombres']); ?></strong>
                    </div>
                    <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end ugel-profile-menu p-4 mt-2 shadow">
                    <li class="text-center mb-4">
                        <div class="mb-2"><i class="bi bi-person-vcard fs-2 text-success"></i></div>
                        <h6 class="fw-bold mb-0 text-success"><?php echo htmlspecialchars($nombre_completo); ?></h6>
                        <small class="text-muted"><?php echo htmlspecialchars($user_data['correo']); ?></small>
                    </li>
                    <li class="px-2">
                        <p class="text-uppercase fw-bold text-muted mb-0" style="font-size: 0.75rem;">DNI</p><p class="mb-2"><?php echo htmlspecialchars($user_data['dni']); ?></p>
                        <p class="text-uppercase fw-bold text-muted mb-0" style="font-size: 0.75rem;">SUB TIPO</p><p class="text-success fw-bold"><?php echo htmlspecialchars($sub_tipo_usuario); ?></p>
                    </li>
                    <li><hr class="dropdown-divider mt-4 mb-3"></li>
                    <li>
                        <a class="btn btn-outline-primary ugel-menu-action w-100 fw-bold mb-2" href="panel_usuario.php">
                            <i class="bi bi-person"></i>
                            <span>Perfil</span>
                        </a>
                    </li>
                    <li>
                        <a class="btn btn-outline-danger ugel-menu-action w-100 fw-bold" href="logout.php">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Cerrar Sesión</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- App Shell con Sidebar -->
<div class="ugel-app-shell">
    <?php
    $sidebar_role   = 'especialista';
    $sidebar_active = 'documentos';
    $sidebar_label  = 'Especialista UGEL';
    include 'components/sidebar.php';
    ?>

    <main class="ugel-main-content">
    
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'evaluado'): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm border-0" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i> Evaluación guardada correctamente.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="filter-box shadow-sm"><i class="bi bi-funnel me-2"></i>Búsqueda Avanzada de Documentos</div>
    <div class="filter-body shadow-sm">
        <form method="GET" action="ver_envios.php" class="row g-3 align-items-end">
            <div class="col-12 col-md-3">
                <label class="form-label text-success fw-bold">Institución Educativa (IE)</label>
                <input type="text" class="form-control border-success border-opacity-50" name="nombre_ie" placeholder="Nombre de la IE" value="<?= isset($_GET['nombre_ie']) ? htmlspecialchars($_GET['nombre_ie']) : '' ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label text-success fw-bold">Cód. Modular</label>
                <input type="text" class="form-control border-success border-opacity-50" name="codigo_local" placeholder="Ej: 123456" value="<?= isset($_GET['codigo_local']) ? htmlspecialchars($_GET['codigo_local']) : '' ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label text-success fw-bold">Filtrar por Mes</label>
                <select class="form-select border-success border-opacity-50" name="mes">
                    <option value="">Todos</option>
                    <?php 
                    $meses_arr = [1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];
                    foreach($meses_arr as $num => $nom) {
                        $selected = (isset($_GET['mes']) && $_GET['mes'] == $num) ? 'selected' : '';
                        echo "<option value='$num' $selected>$nom</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-success fw-bold">Tipo de Documento</label>
                <select class="form-select border-success border-opacity-50" name="tipo">
                    <option value="">Todos los tipos</option>
                    <option value="Mensual" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Mensual') ? 'selected' : '' ?>>Asistencia Mensual</option>
                    <option value="RI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'RI') ? 'selected' : '' ?>>Reglamento Interno (RI)</option>
                    <option value="PAT" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'PAT') ? 'selected' : '' ?>>Plan Anual de Trabajo (PAT)</option>
                    <option value="PCI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'PCI') ? 'selected' : '' ?>>Proyecto Curricular Institucional (PCI)</option>
                    <option value="Innovacion" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'Innovacion') ? 'selected' : '' ?>>Proyecto de Innovación (PI)</option>
                    <option value="RD_CONEI" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'RD_CONEI') ? 'selected' : '' ?>>R.D. de CONEI</option>
                    <option value="DocGestion" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'DocGestion') ? 'selected' : '' ?>>Documentos de Gestión</option>
                    <option value="ECRE INICIO" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'ECRE INICIO') ? 'selected' : '' ?>>ECRE INICIO</option>
                    <option value="ECRE PROCESO" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'ECRE PROCESO') ? 'selected' : '' ?>>ECRE PROCESO</option>
                    <option value="ECRE SALIDA" <?= (isset($_GET['tipo']) && $_GET['tipo'] == 'ECRE SALIDA') ? 'selected' : '' ?>>ECRE SALIDA</option>
                </select>
            </div>
            <div class="col-12 col-md-2 d-flex flex-wrap flex-md-nowrap gap-2 mt-3 mt-md-0">
                <button type="submit" class="btn btn-green w-100 fw-bold shadow-sm flex-grow-1"><i class="bi bi-search me-1"></i> Buscar</button>
                <a href="ver_envios.php" class="btn btn-outline-secondary shadow-sm flex-shrink-0" title="Limpiar filtros"><i class="bi bi-arrow-clockwise"></i></a>
            </div>
        </form>
    </div>

    <div class="table-responsive shadow-sm bg-white p-3 border border-success border-opacity-25 rounded mb-3">
        <table class="table table-hover table-bordered align-middle mb-0">
            <thead class="table-custom-header">
                <tr>
                    <th>Cód. Modular</th>
                    <th>Institución Educativa (IE)</th>
                    <th>Tipo Documento</th>
                    <th>Periodo</th>
                    <th>Fecha de Envío</th>
                    <th class="text-center">Estado / Revisión</th>
                    <th class="text-center" style="width: 200px;">Archivos</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($resultado && $resultado->num_rows > 0) {
                    while ($row = $resultado->fetch_assoc()) {
                        
                        // 1. Formatear el periodo
                        if ($row['tipo_documento'] === 'Mensual') {
                            $meses = [1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];
                            $nombre_mes = $meses[$row['mes']] ?? "Desconocido";
                            $periodo = $nombre_mes . " - " . $row['anio'];
                        } else {
                            $periodo = "Año " . $row['anio'];
                        }

                        // 2. Configurar colores del estado
                        $estado = $row['estado'] ?? 'Pendiente';
                        if ($estado === 'Conforme') { $color_badge = 'bg-success'; }
                        elseif ($estado === 'Observado') { $color_badge = 'bg-warning text-dark'; }
                        elseif ($estado === 'Rechazado') { $color_badge = 'bg-danger'; }
                        else { $color_badge = 'bg-secondary'; }

                        // 3. Etiqueta tipo documento (Verde para Gestión, Info para mensual)
                        $tipo_doc = $row['tipo_documento'];
                        $color_tipo = ($tipo_doc == 'Mensual') ? 'bg-info text-dark' : 'bg-success text-white';

                        // LÓGICA DE RESTRICCIÓN SEGÚN SUB_TIPO Y TIPO DOCUMENTO (QUINTO)
                        $es_agp_restringido = ($sub_tipo_usuario == 'AGP' && $tipo_doc == 'Mensual');
                        $es_ecre = in_array($tipo_doc, ['ECRE INICIO', 'ECRE PROCESO', 'ECRE SALIDA']);

                        // 4. Lógica de botones de descarga
                        if ($es_agp_restringido) {
                            $btn_pdf = "<button class='btn btn-outline-secondary btn-sm opacity-50 fw-bold' disabled title='No autorizado'><i class='bi bi-file-earmark-pdf-fill fs-6'></i></button>";
                            $btn_excel = "<button class='btn btn-outline-secondary btn-sm opacity-50 fw-bold' disabled title='No autorizado'><i class='bi bi-file-earmark-excel-fill fs-6'></i></button>";
                        } else {
                            $btn_pdf = "";
                            $btn_excel = "";

                            if ($es_ecre) {
                                // Lógica exclusiva para ECRE: Múltiples Excel, sin PDF
                                if (!empty($row['ruta_excel'])) {
                                    $archivos_excel = explode(',', $row['ruta_excel']);
                                    foreach ($archivos_excel as $indice => $archivo) {
                                        $archivo = trim($archivo);
                                        if (!empty($archivo)) {
                                            $num = $indice + 1;
                                            $btn_excel .= "<a href='{$archivo}' class='btn btn-outline-success btn-sm fw-bold mb-1' download title='Descargar Excel {$num}'><i class='bi bi-file-earmark-excel-fill fs-6'></i></a> ";
                                        }
                                    }
                                }
                            } else {
                                // Lógica para Mensual, Gestión, Innovación
                                if (!empty($row['ruta_archivo']) && $row['ruta_archivo'] !== 'N/A') {
                                    $btn_pdf = "<a href='{$row['ruta_archivo']}' class='btn btn-outline-danger btn-sm fw-bold mb-1' target='_blank' title='Ver PDF o Archivo'><i class='bi bi-file-earmark-pdf-fill fs-6'></i></a> ";
                                }
                                
                                if (!empty($row['ruta_excel'])) {
                                    $btn_excel = "<a href='{$row['ruta_excel']}' class='btn btn-outline-success btn-sm fw-bold mb-1' download title='Descargar Excel'><i class='bi bi-file-earmark-excel-fill fs-6'></i></a> ";
                                } elseif ($tipo_doc === 'Mensual') {
                                    $btn_excel = "<button class='btn btn-outline-secondary btn-sm opacity-50 mb-1' disabled title='Sin Excel'><i class='bi bi-file-earmark-excel-fill fs-6'></i></button> ";
                                }
                            }
                        }

                        // Botón Constancia
                        $btn_constancia = "<a href='ver_constancia.php?id={$row['id']}' class='btn btn-outline-dark btn-sm fw-bold mb-1' target='_blank' title='Ver Constancia'><i class='bi bi-receipt fs-6'></i></a>";

                        echo "<tr>";
                        echo "<td class='fw-bold text-success text-nowrap'>{$row['codigo_local']}</td>";
                        echo "<td><strong class='text-dark d-block text-wrap'>{$row['nombre_ie']}</strong></td>";
                        echo "<td><span class='badge {$color_tipo} px-2 py-1 shadow-sm text-wrap'>{$tipo_doc}</span></td>";
                        echo "<td><small class='fw-bold text-muted text-nowrap'>{$periodo}</small></td>";
                        echo "<td class='text-nowrap'><small class='text-muted'><i class='bi bi-clock me-1'></i>" . date("d/m/Y h:i A", strtotime($row['fecha_envio'])) . "</small></td>";

                        // Celda de Estado y botón Evaluar
                        $obs_js = htmlspecialchars($row['observacion'] ?? '', ENT_QUOTES);
                        echo "<td class='text-center bg-light'>
                                <span class='badge {$color_badge} mb-2 fs-6 shadow-sm w-75'>{$estado}</span><br>";
                        if ($es_agp_restringido) {
                            echo "<button type='button' class='btn btn-secondary btn-sm fw-bold shadow-sm opacity-50 w-100' disabled><i class='bi bi-pencil-square me-1'></i> Evaluar</button>";
                        } else {
                            echo "<button type='button' class='btn btn-green btn-sm fw-bold shadow-sm w-100' onclick=\"abrirModal({$row['id']}, '{$estado}', '{$obs_js}')\">
                                    <i class='bi bi-pencil-square me-1'></i> Evaluar
                                  </button>";
                        }
                        echo "</td>";

                        // Celda de Archivos
                        echo "<td class='text-center'>
                                <div class='d-flex flex-wrap justify-content-center gap-1'>
                                    {$btn_pdf}
                                    {$btn_excel}
                                    {$btn_constancia}
                                </div>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center py-5 text-muted'><i class='bi bi-folder-x fs-1 d-block mb-2'></i>No se encontraron envíos que coincidan con la búsqueda.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

    <?php if($total_paginas > 1): ?>
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-5 gap-3">
        <small class="text-muted fw-bold text-center text-md-start">Mostrando registros del <?php echo ($offset + 1); ?> al <?php echo min($offset + $registros_por_pagina, $total_registros); ?> de un total de <?php echo $total_registros; ?></small>
        <nav aria-label="Page navigation" class="pagination-container d-flex justify-content-center w-100 w-md-auto">
            <ul class="pagination pagination-sm m-0 shadow-sm flex-wrap justify-content-center">
                <li class="page-item <?php echo ($pagina_actual <= 1) ? 'disabled' : ''; ?>">
                    <a class="page-link text-success fw-bold" href="?page=<?php echo $pagina_actual - 1; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie']??'') ?>&codigo_local=<?= urlencode($_GET['codigo_local']??'') ?>&mes=<?= urlencode($_GET['mes']??'') ?>&tipo=<?= urlencode($_GET['tipo']??'') ?>"><i class="bi bi-chevron-left"></i> <span class="d-none d-sm-inline">Anterior</span></a>
                </li>
                <?php for($p=1; $p<=$total_paginas; $p++): ?>
                    <li class="page-item <?php echo ($p == $pagina_actual) ? 'active' : ''; ?>">
                        <a class="page-link <?php echo ($p == $pagina_actual) ? 'bg-success border-success text-white' : 'text-success'; ?>" href="?page=<?php echo $p; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie']??'') ?>&codigo_local=<?= urlencode($_GET['codigo_local']??'') ?>&mes=<?= urlencode($_GET['mes']??'') ?>&tipo=<?= urlencode($_GET['tipo']??'') ?>"><?php echo $p; ?></a>
                    </li>
                <?php endfor; ?>
                <li class="page-item <?php echo ($pagina_actual >= $total_paginas) ? 'disabled' : ''; ?>">
                    <a class="page-link text-success fw-bold" href="?page=<?php echo $pagina_actual + 1; ?>&nombre_ie=<?= urlencode($_GET['nombre_ie']??'') ?>&codigo_local=<?= urlencode($_GET['codigo_local']??'') ?>&mes=<?= urlencode($_GET['mes']??'') ?>&tipo=<?= urlencode($_GET['tipo']??'') ?>"><span class="d-none d-sm-inline">Siguiente</span> <i class="bi bi-chevron-right"></i></a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
    </main>
</div><!-- /.ugel-app-shell -->

<div class="modal fade" id="modalEvaluacion" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form action="procesar_evaluacion.php" method="POST">
        <div class="modal-header bg-success text-white border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-clipboard-check me-2"></i>Evaluar Documento</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <input type="hidden" name="id_envio" id="modal_id_envio">
          
          <div class="mb-3">
            <label class="form-label text-success fw-bold">Estado de la Revisión</label>
            <select class="form-select border-success shadow-sm" name="estado" id="modal_estado" required onchange="toggleObservacion()">
              <option value="Pendiente">Pendiente</option>
              <option value="Conforme">🟢 Conforme</option>
              <option value="Observado">🟡 Observado</option>
              <option value="Rechazado">🔴 Rechazado</option>
            </select>
          </div>
          
          <div class="mb-3" id="div_observacion" style="display: none;">
            <label class="form-label text-danger fw-bold">Mensaje / Observación para el Director</label>
            <textarea class="form-control border-danger shadow-sm" name="observacion" id="modal_observacion" rows="4" placeholder="Indique claramente qué debe corregir la IE..."></textarea>
          </div>
        </div>
        <div class="modal-footer bg-light border-0">
          <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-green fw-bold px-4">Guardar Evaluación</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
function abrirModal(id, estado, observacion) {
    document.getElementById('modal_id_envio').value = id;
    document.getElementById('modal_estado').value = estado;
    document.getElementById('modal_observacion').value = observacion;
    toggleObservacion();
    
    var myModal = new bootstrap.Modal(document.getElementById('modalEvaluacion'));
    myModal.show();
}

function toggleObservacion() {
    var estado = document.getElementById('modal_estado').value;
    var divObs = document.getElementById('div_observacion');
    var txtObs = document.getElementById('modal_observacion');
    
    if (estado === 'Observado' || estado === 'Rechazado') {
        divObs.style.display = 'block';
        txtObs.setAttribute('required', 'required');
    } else {
        divObs.style.display = 'none';
        txtObs.value = '';
        txtObs.removeAttribute('required');
    }
}
</script>

</body>
</html>