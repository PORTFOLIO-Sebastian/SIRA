<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

// CORRECCIÓN DE SEGURIDAD: 
if (!isset($_SESSION['id_ie']) || empty($_SESSION['id_ie'])) {
    echo "<script>
            alert('Error: No se pudo identificar su Institución Educativa. Por favor, inicie sesión nuevamente.'); 
            window.location.href='login.php';
          </script>";
    exit();
}

$tipo = isset($_GET['tipo']) ? $_GET['tipo'] : 'Mensual';
$anio_actual = date('Y');
$id_ie = $_SESSION['id_ie'];

// OBTENER DATOS DEL USUARIO Y SU IE
$id_usuario = $_SESSION['id_usuario'];

$query = "SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni, i.id AS id_ie, i.nombre_ie, i.codigo_local 
          FROM usuarios u
          LEFT JOIN asignaciones a ON u.id = a.id_usuario AND a.estado = 'activo'
          LEFT JOIN instituciones i ON a.id_ie = i.id
          WHERE u.id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();

$nombre_completo = $user_data['nombres'] . " " . $user_data['ape_pat'] . " " . $user_data['ape_mat'];
$nombre_ie = $user_data['nombre_ie'] ?? 'Sin Asignar'; 
$codigo_local = $user_data['codigo_local'] ?? '000000';

// OBTENER CODIGO LOCAL DESDE ASIGNACIONES E INSTITUCIONES
$sql_codigo = "SELECT i.codigo_local FROM asignaciones a INNER JOIN instituciones i ON a.id_ie = i.id WHERE a.id_usuario = ?";

$stmt_codigo = $conn->prepare($sql_codigo);
if ($stmt_codigo) {
    $stmt_codigo->bind_param("i", $id_usuario);
    $stmt_codigo->execute();
    $res_codigo = $stmt_codigo->get_result();

    if ($row_codigo = $res_codigo->fetch_assoc()) {
        $codigo_local = $row_codigo['codigo_local'];
    }
    $stmt_codigo->close();
}

$bloqueados = [];
$mensajes_observacion = [];
$estado_meses = []; // Usado para Mensual
$estado_gestion = []; // Usado para Gestión
$estado_innovacion = []; // Usado para Innovación
$estado_epla = []; // Usado para ECRE

if ($tipo == 'Mensual') {
    
    $meses_nombres = [1=>"Enero", 2=>"Febrero", 3=>"Marzo", 4=>"Abril", 5=>"Mayo", 6=>"Junio", 7=>"Julio", 8=>"Agosto", 9=>"Septiembre", 10=>"Octubre", 11=>"Noviembre", 12=>"Diciembre"];

    foreach($meses_nombres as $num => $nom) {
        $estado_meses[$num] = ['estado' => 'Pendiente', 'archivos' => [], 'constancia' => '', 'fecha_envio' => ''];
    }

    $sql_check = "SELECT mes, estado, observacion, ruta_archivo, ruta_excel, codigo_constancia, fecha_envio FROM envios WHERE id_ie = ? AND anio = ? AND tipo_documento = 'Mensual' ORDER BY id DESC";

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param("ii", $id_ie, $anio_actual);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        $procesados = [];
        while ($row = $res_check->fetch_assoc()) {
            $m = $row['mes'];

            if (!empty($row['ruta_archivo']) || !empty($row['ruta_excel'])) {
                $estado_meses[$m]['archivos'][] = [
                    'pdf' => $row['ruta_archivo'],
                    'excel' => $row['ruta_excel']
                ];
            }

            if (!in_array($m, $procesados)) {
                $procesados[] = $m;

                if ($row['estado'] == 'Pendiente' || $row['estado'] == 'Conforme') {
                    $bloqueados[] = $m;
                } elseif ($row['estado'] == 'Observado' || $row['estado'] == 'Rechazado') {
                    $mensajes_observacion[$m] = ['estado' => $row['estado'], 'obs' => $row['observacion'] ?? 'Subsanar envío.'];
                }

                $estado_mostrar = 'Pendiente';

                if ($row['estado'] == 'Pendiente') $estado_mostrar = 'Enviado';
                elseif ($row['estado'] == 'Conforme') $estado_mostrar = 'Aceptado';
                elseif ($row['estado'] == 'Observado') $estado_mostrar = 'Observado';
                elseif ($row['estado'] == 'Rechazado') $estado_mostrar = 'Rechazado';

                $estado_meses[$m]['estado'] = $estado_mostrar;
                $estado_meses[$m]['constancia'] = $row['codigo_constancia'] ?? '';
                $estado_meses[$m]['fecha_envio'] = $row['fecha_envio'] ?? '';
            }
        }
        $stmt_check->close();
    }
} elseif ($tipo == 'Gestion') {
    $opciones_gestion_multigrado = ["RI" => "Reglamento Interno (RI)", "PAT" => "Plan Anual de Trabajo (PAT)", "PCI" => "Proyecto Curricular Institucional (PCI)", "PEI" => "Proyecto Educativo Institucional (PEI)", "RD_CONEI" => "R.D. de CONEI"];
    $opciones_gestion_unidocente = ["DocGestion" => "Documentos de Gestión"];
    $todas_opciones_gestion = array_merge($opciones_gestion_multigrado, $opciones_gestion_unidocente);

    foreach($todas_opciones_gestion as $td => $nom) {
        $estado_gestion[$td] = ['estado' => 'Pendiente', 'archivos' => [], 'nombre' => $nom, 'constancia' => '', 'fecha_envio' => ''];
    }

    $sql_check = "SELECT tipo_documento, estado, observacion, ruta_archivo, codigo_constancia, fecha_envio FROM envios WHERE id_ie = ? AND anio = ? AND tipo_documento IN ('RI', 'PAT', 'PCI', 'PEI', 'RD_CONEI', 'DocGestion') ORDER BY id DESC";

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param("ii", $id_ie, $anio_actual);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        $procesados = [];
        while ($row = $res_check->fetch_assoc()) {
            $td = $row['tipo_documento'];

            if (!empty($row['ruta_archivo'])) {
                $estado_gestion[$td]['archivos'][] = [
                    'pdf' => $row['ruta_archivo']
                ];
            }

            if (!in_array($td, $procesados)) {
                $procesados[] = $td;

                if ($row['estado'] == 'Pendiente' || $row['estado'] == 'Conforme') {
                    $bloqueados[] = $td;
                } elseif ($row['estado'] == 'Observado' || $row['estado'] == 'Rechazado') {
                    $mensajes_observacion[$td] = ['estado' => $row['estado'], 'obs' => $row['observacion'] ?? 'Subsanar documento.'];
                }

                $estado_mostrar = 'Pendiente';

                if ($row['estado'] == 'Pendiente') $estado_mostrar = 'Enviado';
                elseif ($row['estado'] == 'Conforme') $estado_mostrar = 'Aceptado';
                elseif ($row['estado'] == 'Observado') $estado_mostrar = 'Observado';
                elseif ($row['estado'] == 'Rechazado') $estado_mostrar = 'Rechazado';

                $estado_gestion[$td]['estado'] = $estado_mostrar;
                $estado_gestion[$td]['constancia'] = $row['codigo_constancia'] ?? '';
                $estado_gestion[$td]['fecha_envio'] = $row['fecha_envio'] ?? '';
            }
        }
        $stmt_check->close();
    }
} elseif ($tipo == 'Innovacion') {
    $estado_innovacion = ['estado' => 'Pendiente', 'archivos' => [], 'nombre' => 'Proyecto de Innovación', 'constancia' => '', 'fecha_envio' => ''];

    $sql_check = "SELECT tipo_documento, estado, observacion, ruta_archivo, codigo_constancia, fecha_envio FROM envios WHERE id_ie = ? AND anio = ? AND tipo_documento = 'Innovacion' ORDER BY id DESC";

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param("ii", $id_ie, $anio_actual);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        $procesados = [];
        while ($row = $res_check->fetch_assoc()) {
            $td = $row['tipo_documento'];

            if (!empty($row['ruta_archivo'])) {
                $estado_innovacion['archivos'][] = [
                    'archivo' => $row['ruta_archivo']
                ];
            }

            if (!in_array($td, $procesados)) {
                $procesados[] = $td;

                if ($row['estado'] == 'Pendiente' || $row['estado'] == 'Conforme') {
                    $bloqueados[] = $td;
                } elseif ($row['estado'] == 'Observado' || $row['estado'] == 'Rechazado') {
                    $mensajes_observacion[$td] = ['estado' => $row['estado'], 'obs' => $row['observacion'] ?? 'Subsanar documento.'];
                }

                $estado_mostrar = 'Pendiente';

                if ($row['estado'] == 'Pendiente') $estado_mostrar = 'Enviado';
                elseif ($row['estado'] == 'Conforme') $estado_mostrar = 'Aceptado';
                elseif ($row['estado'] == 'Observado') $estado_mostrar = 'Observado';
                elseif ($row['estado'] == 'Rechazado') $estado_mostrar = 'Rechazado';

                $estado_innovacion['estado'] = $estado_mostrar;
                $estado_innovacion['constancia'] = $row['codigo_constancia'] ?? '';
                $estado_innovacion['fecha_envio'] = $row['fecha_envio'] ?? '';
            }
        }
        $stmt_check->close();
    }
} elseif ($tipo == 'EPLA') {
    $opciones_ecre = [
        'ECRE INICIO' => 'ECRE INICIO',
        'ECRE PROCESO' => 'ECRE PROCESO',
        'ECRE SALIDA' => 'ECRE SALIDA'
    ];

    foreach($opciones_ecre as $td => $nom) {
        $estado_epla[$td] = ['estado' => 'Pendiente', 'archivos' => [], 'nombre' => $nom, 'constancia' => '', 'fecha_envio' => ''];
    }

    $sql_check = "SELECT tipo_documento, estado, observacion, ruta_excel, codigo_constancia, fecha_envio FROM envios WHERE id_ie = ? AND anio = ? AND tipo_documento IN ('ECRE INICIO', 'ECRE PROCESO', 'ECRE SALIDA') ORDER BY id DESC";

    $stmt_check = $conn->prepare($sql_check);
    if ($stmt_check) {
        $stmt_check->bind_param("ii", $id_ie, $anio_actual);
        $stmt_check->execute();
        $res_check = $stmt_check->get_result();

        $procesados = [];
        while ($row = $res_check->fetch_assoc()) {
            $td = $row['tipo_documento'];

            if (!empty($row['ruta_excel'])) {
                // Separamos los archivos de excel para guardarlos agrupados
                $estado_epla[$td]['archivos'][] = [
                    'archivos_excel' => explode(",", $row['ruta_excel'])
                ];
            }

            if (!in_array($td, $procesados)) {
                $procesados[] = $td;

                if ($row['estado'] == 'Pendiente' || $row['estado'] == 'Conforme') {
                    $bloqueados[] = $td;
                } elseif ($row['estado'] == 'Observado' || $row['estado'] == 'Rechazado') {
                    $mensajes_observacion[$td] = ['estado' => $row['estado'], 'obs' => $row['observacion'] ?? 'Subsanar documento.'];
                }

                $estado_mostrar = 'Pendiente';

                if ($row['estado'] == 'Pendiente') $estado_mostrar = 'Enviado';
                elseif ($row['estado'] == 'Conforme') $estado_mostrar = 'Aceptado';
                elseif ($row['estado'] == 'Observado') $estado_mostrar = 'Observado';
                elseif ($row['estado'] == 'Rechazado') $estado_mostrar = 'Rechazado';

                $estado_epla[$td]['estado'] = $estado_mostrar;
                $estado_epla[$td]['constancia'] = $row['codigo_constancia'] ?? '';
                $estado_epla[$td]['fecha_envio'] = $row['fecha_envio'] ?? '';
            }
        }
        $stmt_check->close();
    }
}

// Configuración visual según tipo
$config = [
    'Mensual' => ['color' => '#e65c00', 'texto' => 'white', 'icono' => 'bi-calendar-check', 'titulo' => 'Subir Asistencia Mensual'],
    'Gestion' => ['color' => '#1a56db', 'texto' => 'white', 'icono' => 'bi-folder2-open', 'titulo' => 'Subir Documentos de Gestión'],
    'Innovacion' => ['color' => '#c62828', 'texto' => 'white', 'icono' => 'bi-lightbulb', 'titulo' => 'Subir Proyecto de Innovación'],
    'EPLA' => ['color' => '#b8860b', 'texto' => 'black', 'icono' => 'bi-bar-chart-steps', 'titulo' => 'Subir Reportes ECRE']
];

$cfg = $config[$tipo] ?? $config['Mensual'];
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $cfg['titulo']; ?> - UGEL</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/css/fluent.css">
    <!-- Librería para generar el PDF al vuelo -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { background-color: #f4f6f9; }
        .card-header-custom {
            background-color: <?php echo $cfg['color']; ?>;
            color: <?php echo $cfg['texto']; ?>;
            font-weight: bold; text-transform: uppercase;
        }

        /* Custom header table matching the current section theme */
        .table thead th {
            background-color: <?php echo $cfg['color']; ?> !important;
            border-color: <?php echo $cfg['color']; ?> !important;
            color: <?php echo $cfg['texto']; ?> !important;
            font-weight: 600;
        }
        
        /* Table stripe and hover colors matching current theme */
        <?php
        $light_colors = [
            'Mensual' => 'var(--ugel-orange-light)',
            'Gestion' => 'var(--ugel-blue-light)',
            'Innovacion' => 'var(--ugel-red-light)',
            'EPLA' => 'var(--ugel-yellow-light)'
        ];
        $light_color = $light_colors[$tipo] ?? 'var(--ugel-green-light)';
        ?>
        .table-striped > tbody > tr:nth-of-type(odd) > * {
            background-color: <?php echo $light_color; ?> !important;
        }
        .table-hover > tbody > tr:hover > * {
            background-color: <?php echo $light_color; ?> !important;
            filter: brightness(0.95);
        }

        /* Custom outline button for constancia matching the section theme */
        .btn-outline-custom {
            color: <?php echo $cfg['color']; ?> !important;
            border-color: <?php echo $cfg['color']; ?> !important;
            background-color: transparent !important;
        }
        .btn-outline-custom:hover {
            color: <?php echo $cfg['texto']; ?> !important;
            background-color: <?php echo $cfg['color']; ?> !important;
        }

        /* Custom input focus matching the section theme */
        .form-control:focus,
        .form-select:focus,
        select:focus {
            border-color: <?php echo $cfg['color']; ?> !important;
            box-shadow: 0 0 0 2px <?php echo $cfg['color']; ?>2b !important;
        }
        
        /* Ajustes responsivos extras */
        @media (max-width: 576px) {
            .navbar-brand span { font-size: 0.9rem !important; }
            .navbar-brand i { font-size: 1.5rem !important; }
            .btn-inicio-text { display: none; }
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand navbar-light navbar-custom mb-4 sticky-top shadow-sm">
        <div class="container-fluid px-3 px-md-4 flex-wrap">
            <!-- Hamburger (mobile) -->
            <button class="ugel-sidebar-toggle me-2" onclick="ugelOpenSidebar()" aria-label="Abrir menú">
                <i class="bi bi-list"></i>
            </button>
            <a class="navbar-brand d-flex align-items-center mb-2 mb-sm-0" href="dashboard.php">
                <i class="bi <?php echo $cfg['icono']; ?> ugel-brand-icon fs-2 me-2"></i>
                <div class="d-flex flex-column lh-1">
                    <span class="ugel-brand-title">
                        <?php 
                            if ($tipo == 'Mensual') echo 'Asistencia Mensual';
                            elseif ($tipo == 'Gestion') echo 'Documentos de Gestión';
                            elseif ($tipo == 'Innovacion') echo 'Proyectos de Innovación';
                            elseif ($tipo == 'EPLA') echo 'Reportes ECRE';
                        ?>
                    </span>
                    <small class="ugel-brand-subtitle">Sistema IE</small>
                </div>
            </a>
            
            <div class="ms-auto d-flex align-items-center">
                <a href="dashboard.php" class="btn ugel-profile-button fw-bold me-2 me-md-3 px-3 shadow-sm">
                    <i class="bi bi-house-door-fill me-1"></i> <span class="btn-inicio-text">Inicio</span>
                </a>
                <div class="dropdown">
                    <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                        <div class="text-end d-none d-sm-block">
                            <small class="d-block text-uppercase">Director</small>
                            <strong><?php echo htmlspecialchars($user_data['nombres']); ?></strong>
                        </div>
                        <i class="bi bi-person-circle ugel-profile-avatar fs-4"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-profile ugel-profile-menu p-4 mt-2 shadow">
                        <li class="text-center mb-4">
                            <div class="icon-box mb-2"><i class="bi bi-person-vcard fs-2"></i></div>
                            <h6 class="fw-bold mb-0 text-success"><?php echo htmlspecialchars($nombre_completo); ?></h6>
                            <small class="text-muted"><?php echo htmlspecialchars($user_data['correo']); ?></small>
                        </li>
                        <li class="px-2">
                            <p class="info-label">DNI</p><p class="info-value"><?php echo htmlspecialchars($user_data['dni']); ?></p>
                            <p class="info-label">CÓDIGO LOCAL</p><p class="info-value"><?php echo htmlspecialchars($codigo_local); ?></p>
                            <p class="info-label">IE</p><p class="info-value text-success fw-bold"><?php echo htmlspecialchars($nombre_ie); ?></p>
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
        $sidebar_role   = 'director';
        $sidebar_active = ($tipo === 'Mensual') ? 'asistencia' : (($tipo === 'Innovacion') ? 'proyectos' : 'dashboard');
        $sidebar_label  = $nombre_ie;
        include 'components/sidebar.php';
        ?>

        <main class="ugel-main-content">
        <div class="row g-4 justify-content-center">
            <div class="col-12 col-lg-5 col-xl-4">
                <div class="card shadow-sm border-0 h-100">
                    <div class="card-header card-header-custom py-3 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi <?php echo $cfg['icono']; ?>"></i> <?php echo $cfg['titulo']; ?></h5>
                    </div>
                    <div class="card-body p-4">
                        <form action="procesar_envio.php" method="POST" enctype="multipart/form-data">
                            
                            <?php if ($tipo == 'Mensual'): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Año de Gestión</label>
                                        <input type="number" class="form-control bg-light" name="anio" value="<?php echo $anio_actual; ?>" readonly>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <input type="hidden" name="tipo_documento" value="Mensual">
                                        <label class="form-label text-muted fw-bold">Mes a Reportar <span class="text-danger">*</span></label>
                                        <select class="form-select" style="border-color: <?php echo $cfg['color']; ?>;" name="mes" required onchange="mostrarObservacion(this)">
                                            <option value="" selected disabled>Seleccione...</option>
                                            <?php foreach($meses_nombres as $num => $nom): 
                                                $esta_bloqueado = in_array($num, $bloqueados);
                                                $requiere_subsanar = isset($mensajes_observacion[$num]); ?>
                                                <option value="<?php echo $num; ?>" <?php echo $esta_bloqueado ? 'disabled class="text-danger"' : ''; ?>>
                                                    <?php echo $nom; ?> 
                                                    <?php echo $requiere_subsanar ? '⚠️ (Subsanar)' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php elseif ($tipo == 'Gestion'): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-12">
                                        <label class="form-label text-muted fw-bold">Año de Gestión</label>
                                        <input type="number" class="form-control bg-light" name="anio" value="<?php echo $anio_actual; ?>" readonly>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Tipo de Institución <span class="text-danger">*</span></label>
                                        <select class="form-select" style="border-color: <?php echo $cfg['color']; ?>;" id="tipo_institucion" required onchange="actualizarTipoInstitucion()">
                                            <option value="" selected disabled>Seleccione...</option>
                                            <option value="Unidocente">UNIDOCENTE/MULTIGRADO/PROGRAMAS</option>
                                            <option value="Multigrado">POLIDOCENTE</option>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Tipo de Documento <span class="text-danger">*</span></label>
                                        <select class="form-select" style="border-color: <?php echo $cfg['color']; ?>;" name="tipo_documento" id="tipo_documento" required onchange="mostrarObservacion(this)">
                                            <option value="" selected disabled>Seleccione Inst...</option>
                                        </select>
                                    </div>
                                </div>
                            <?php elseif ($tipo == 'Innovacion'): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Año de Gestión</label>
                                        <input type="number" class="form-control bg-light" name="anio" value="<?php echo $anio_actual; ?>" readonly>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <input type="hidden" name="tipo_documento" value="Innovacion">
                                        <label class="form-label text-muted fw-bold">Tipo de Documento</label>
                                        <input type="text" class="form-control bg-light" value="Proyecto de Innovación" readonly>
                                    </div>
                                </div>
                            <?php elseif ($tipo == 'EPLA'): ?>
                                <div class="row g-3 mb-3">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Año de Gestión</label>
                                        <input type="number" class="form-control bg-light" name="anio" value="<?php echo $anio_actual; ?>" readonly>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <label class="form-label text-muted fw-bold">Tipo de Documento <span class="text-danger">*</span></label>
                                        <select class="form-select" style="border-color: <?php echo $cfg['color']; ?>;" name="tipo_documento" id="tipo_documento" required onchange="mostrarObservacion(this)">
                                            <option value="" selected disabled>Seleccione...</option>
                                            <?php 
                                            $opciones_ecre = ['ECRE INICIO' => 'ECRE INICIO', 'ECRE PROCESO' => 'ECRE PROCESO', 'ECRE SALIDA' => 'ECRE SALIDA'];
                                            foreach($opciones_ecre as $td => $nom): 
                                                $esta_bloqueado = in_array($td, $bloqueados);
                                                $requiere_subsanar = isset($mensajes_observacion[$td]); ?>
                                                <option value="<?php echo $td; ?>" <?php echo $esta_bloqueado ? 'disabled class="text-danger"' : ''; ?>>
                                                    <?php echo $nom; ?> 
                                                    <?php echo $requiere_subsanar ? '⚠️ (Subsanar)' : ''; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div id="alerta_observacion" style="display: none;"></div>
                            <hr class="text-muted mt-4">

                            <?php if ($tipo == 'Mensual'): ?>
                                <div class="alert alert-light border shadow-sm">
                                    <h6 class="fw-bold mb-2"><i class="bi bi-grid-3x3-gap-fill text-warning"></i> Modulo interno de asistencia</h6>
                                    <p class="mb-0 small text-muted">Registre personal, complete la grilla mensual, genere Anexo 03/04 y registre el envio sin subir archivos manualmente.</p>
                                </div>
                                <div class="d-grid gap-2 mt-4">
                                    <button type="button" class="btn btn-orange btn-lg shadow-sm" onclick="abrirModuloAsistencia()">
                                        <i class="bi bi-box-arrow-up-right"></i> ABRIR MODULO DE ASISTENCIA
                                    </button>
                                </div>
                            <?php elseif ($tipo == 'Gestion'): ?>
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-secondary"><i class="bi bi-file-earmark-pdf text-danger"></i> Archivo PDF (Obligatorio)</label>
                                    <input class="form-control border-danger" type="file" name="archivo_pdf" accept=".pdf" required>
                                </div>
                            <?php elseif ($tipo == 'Innovacion'): 
                                $esta_bloqueado_innov = in_array('Innovacion', $bloqueados);
                            ?>
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-secondary"><i class="bi bi-file-earmark-word text-primary"></i> Proyecto de Innovación</label>
                                    <input class="form-control border-success" type="file" name="archivo_innovacion" accept=".pdf,.doc,.docx" required <?php echo $esta_bloqueado_innov ? 'disabled' : ''; ?>>
                                    <div class="form-text">Puede subir formato PDF o Word (.doc, .docx).</div>
                                </div>
                            <?php elseif ($tipo == 'EPLA'): ?>
                                <div class="mb-4">
                                    <label class="form-label fw-bold text-secondary"><i class="bi bi-file-earmark-excel text-success"></i> Informes ECRE (De 1 a 6 archivos Excel)</label>
                                    <div class="input-group">
                                        <input class="form-control border-warning bg-light" type="file" name="archivos_epla[]" accept=".xls,.xlsx" multiple required onchange="validarEpla(this)">
                                    </div>
                                    <div class="form-text mt-2 text-dark">
                                        <i class="bi bi-info-circle-fill text-primary"></i> <strong>Nota:</strong> Puede seleccionar de 1 a 6 archivos Excel al mismo tiempo manteniendo presionada la tecla <strong>Ctrl</strong> al momento de elegir los archivos.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php 
                                $btn_class = 'btn-primary';
                                if ($tipo == 'Mensual') $btn_class = 'btn-orange';
                                elseif ($tipo == 'Gestion') $btn_class = 'btn-blue';
                                elseif ($tipo == 'Innovacion') $btn_class = 'btn-red';
                                elseif ($tipo == 'EPLA') $btn_class = 'btn-yellow';
                            ?>
                            <?php if ($tipo != 'Mensual'): ?>
                            <div class="d-grid gap-2 mt-4">
                                <button type="submit" class="btn <?php echo $btn_class; ?> btn-lg shadow-sm" <?php echo ($tipo == 'Innovacion' && isset($esta_bloqueado_innov) && $esta_bloqueado_innov) ? 'disabled' : ''; ?>>
                                    <i class="bi bi-cloud-arrow-up"></i> REGISTRAR ENVÍO
                                </button>
                            </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12 col-lg-7 col-xl-6">
                <div class="card shadow-sm border-0 h-100 mt-4 mt-lg-0">
                    <div class="card-header card-header-custom py-3">
                        <i class="bi bi-list-check me-2"></i> Estado de Envíos - <?php echo $anio_actual; ?>
                    </div>
                    <div class="card-body p-0 table-responsive">
                        <table class="table table-striped table-hover mb-0 align-middle text-center" style="min-width: 600px;">
                            <thead class="table-light">
                                <tr>
                                    <th><?php echo $tipo == 'Mensual' ? 'Mes' : 'Tipo de Documento'; ?></th>
                                    <th>Estado</th>
                                    <th>Archivo</th>
                                    <th>Constancia</th>
                                </tr>
                            </thead>
                            <tbody id="tabla_estados">
                                <?php if ($tipo == 'Mensual'): ?>
                                    <?php foreach($meses_nombres as $num => $nom): 
                                        $st = $estado_meses[$num];
                                        $badge_class = 'bg-secondary';
                                        if($st['estado'] == 'Enviado') $badge_class = 'is-blue';
                                        elseif($st['estado'] == 'Aceptado') $badge_class = 'is-green';
                                        elseif($st['estado'] == 'Observado') $badge_class = 'is-orange';
                                        elseif($st['estado'] == 'Rechazado') $badge_class = 'is-red';
                                    ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?php echo $nom; ?></td>
                                        <td><span class="ugel-badge <?php echo $badge_class; ?> fs-6"><?php echo $st['estado']; ?></span></td>
                                        <td>
                                            <?php if(!empty($st['archivos'])): ?>
                                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                    <?php foreach($st['archivos'] as $par): ?>
                                                        <div class="btn-group shadow-sm">
                                                            <?php if(!empty($par['pdf'])): ?>
                                                                <a href="<?php echo htmlspecialchars($par['pdf']); ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Ver PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                                            <?php endif; ?>
                                                            <?php if(!empty($par['excel'])): ?>
                                                                <a href="<?php echo htmlspecialchars($par['excel']); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Descargar Excel"><i class="bi bi-file-earmark-excel-fill"></i></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($st['constancia'])): ?>
                                                <div class="d-flex flex-column align-items-center gap-1">
                                                    <?php 
                                                        $fecha_formateada = !empty($st['fecha_envio']) ? date("d/m/Y h:i A", strtotime($st['fecha_envio'])) : ''; 
                                                        $periodo_mensual = $nom . " - " . $anio_actual;
                                                    ?>
                                                    <button type="button" onclick="generarPDFConstancia('<?php echo htmlspecialchars(addslashes($st['constancia'])); ?>', '<?php echo htmlspecialchars(addslashes($nombre_ie)); ?>', '<?php echo htmlspecialchars(addslashes($codigo_local)); ?>', 'Asistencia Mensual', '<?php echo htmlspecialchars(addslashes($periodo_mensual)); ?>', '<?php echo htmlspecialchars(addslashes($fecha_formateada)); ?>', 'Documento PDF y Archivo Excel')" class="btn btn-sm btn-outline-custom" title="Descargar Constancia PDF">
                                                        <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                
                                <?php elseif ($tipo == 'Gestion'): ?>
                                    <?php foreach($todas_opciones_gestion as $td => $nom): 
                                        $st = $estado_gestion[$td];
                                        $badge_class = 'bg-secondary';
                                        if($st['estado'] == 'Enviado') $badge_class = 'is-blue';
                                        elseif($st['estado'] == 'Aceptado') $badge_class = 'is-green';
                                        elseif($st['estado'] == 'Observado') $badge_class = 'is-orange';
                                        elseif($st['estado'] == 'Rechazado') $badge_class = 'is-red';
                                        
                                        $data_tipo = ($td == 'DocGestion') ? 'Unidocente' : 'Multigrado';
                                    ?>
                                    <tr class="row-gestion" data-tipo="<?php echo $data_tipo; ?>" style="display: none;">
                                        <td class="fw-bold text-secondary"><?php echo $nom; ?></td>
                                        <td><span class="ugel-badge <?php echo $badge_class; ?> fs-6"><?php echo $st['estado']; ?></span></td>
                                        <td>
                                            <?php if(!empty($st['archivos'])): ?>
                                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                    <?php foreach($st['archivos'] as $par): ?>
                                                        <div class="btn-group shadow-sm mt-1">
                                                            <?php if(!empty($par['pdf'])): ?>
                                                                <a href="<?php echo htmlspecialchars($par['pdf']); ?>" target="_blank" class="btn btn-sm btn-outline-danger" title="Ver PDF"><i class="bi bi-file-earmark-pdf-fill"></i></a>
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($st['constancia'])): ?>
                                                <div class="d-flex flex-column align-items-center gap-1">
                                                    <?php 
                                                        $fecha_formateada = !empty($st['fecha_envio']) ? date("d/m/Y h:i A", strtotime($st['fecha_envio'])) : ''; 
                                                    ?>
                                                    <button type="button" onclick="generarPDFConstancia('<?php echo htmlspecialchars(addslashes($st['constancia'])); ?>', '<?php echo htmlspecialchars(addslashes($nombre_ie)); ?>', '<?php echo htmlspecialchars(addslashes($codigo_local)); ?>', '<?php echo htmlspecialchars(addslashes($nom)); ?>', 'Año <?php echo $anio_actual; ?>', '<?php echo htmlspecialchars(addslashes($fecha_formateada)); ?>', 'Documento PDF')" class="btn btn-sm btn-outline-custom" title="Descargar Constancia PDF">
                                                        <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                
                                <?php elseif ($tipo == 'Innovacion'): 
                                    $st = $estado_innovacion;
                                    $badge_class = 'bg-secondary';
                                    if($st['estado'] == 'Enviado') $badge_class = 'is-blue';
                                    elseif($st['estado'] == 'Aceptado') $badge_class = 'is-green';
                                    elseif($st['estado'] == 'Observado') $badge_class = 'is-orange';
                                    elseif($st['estado'] == 'Rechazado') $badge_class = 'is-red';
                                ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?php echo $st['nombre']; ?></td>
                                        <td><span class="ugel-badge <?php echo $badge_class; ?> fs-6"><?php echo $st['estado']; ?></span></td>
                                        <td>
                                            <?php if(!empty($st['archivos'])): ?>
                                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                    <?php foreach($st['archivos'] as $par): 
                                                        $ext = strtolower(pathinfo($par['archivo'], PATHINFO_EXTENSION));
                                                        $es_word = in_array($ext, ['doc', 'docx']);
                                                    ?>
                                                        <div class="btn-group shadow-sm mt-1">
                                                            <a href="<?php echo htmlspecialchars($par['archivo']); ?>" target="_blank" class="btn btn-sm btn-outline-<?php echo $es_word ? 'primary' : 'danger'; ?>" title="Ver Archivo">
                                                                <i class="bi <?php echo $es_word ? 'bi-file-earmark-word-fill' : 'bi-file-earmark-pdf-fill'; ?>"></i>
                                                            </a>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($st['constancia'])): ?>
                                                <div class="d-flex flex-column align-items-center gap-1">
                                                    <?php 
                                                        $fecha_formateada = !empty($st['fecha_envio']) ? date("d/m/Y h:i A", strtotime($st['fecha_envio'])) : ''; 
                                                    ?>
                                                    <button type="button" onclick="generarPDFConstancia('<?php echo htmlspecialchars(addslashes($st['constancia'])); ?>', '<?php echo htmlspecialchars(addslashes($nombre_ie)); ?>', '<?php echo htmlspecialchars(addslashes($codigo_local)); ?>', 'Proyecto de Innovación', 'Año <?php echo $anio_actual; ?>', '<?php echo htmlspecialchars(addslashes($fecha_formateada)); ?>', 'Documento de Proyecto')" class="btn btn-sm btn-outline-custom" title="Descargar Constancia PDF">
                                                        <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>

                                <?php elseif ($tipo == 'EPLA'): 
                                    $opciones_ecre = ['ECRE INICIO' => 'ECRE INICIO', 'ECRE PROCESO' => 'ECRE PROCESO', 'ECRE SALIDA' => 'ECRE SALIDA'];
                                    foreach($opciones_ecre as $td => $nom): 
                                        $st = $estado_epla[$td];
                                        $badge_class = 'bg-secondary';
                                        if($st['estado'] == 'Enviado') $badge_class = 'is-blue';
                                        elseif($st['estado'] == 'Aceptado') $badge_class = 'is-green';
                                        elseif($st['estado'] == 'Observado') $badge_class = 'is-orange';
                                        elseif($st['estado'] == 'Rechazado') $badge_class = 'is-red';
                                ?>
                                    <tr>
                                        <td class="fw-bold text-secondary"><?php echo $st['nombre']; ?></td>
                                        <td><span class="ugel-badge <?php echo $badge_class; ?> fs-6"><?php echo $st['estado']; ?></span></td>
                                        <td>
                                            <?php if(!empty($st['archivos'])): ?>
                                                <div class="d-flex flex-wrap gap-2 justify-content-center">
                                                    <?php foreach($st['archivos'] as $grupo): ?>
                                                        <div class="btn-group shadow-sm mt-1 border border-warning rounded p-1 bg-white">
                                                            <?php foreach($grupo['archivos_excel'] as $archivo_ind): 
                                                                if(trim($archivo_ind) != ''):
                                                            ?>
                                                                <a href="<?php echo htmlspecialchars(trim($archivo_ind)); ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Descargar Excel"><i class="bi bi-file-earmark-excel-fill"></i></a>
                                                            <?php 
                                                                endif;
                                                            endforeach; ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!empty($st['constancia'])): ?>
                                                <div class="d-flex flex-column align-items-center gap-1">
                                                    <?php 
                                                        $fecha_formateada = !empty($st['fecha_envio']) ? date("d/m/Y h:i A", strtotime($st['fecha_envio'])) : ''; 
                                                    ?>
                                                    <button type="button" onclick="generarPDFConstancia('<?php echo htmlspecialchars(addslashes($st['constancia'])); ?>', '<?php echo htmlspecialchars(addslashes($nombre_ie)); ?>', '<?php echo htmlspecialchars(addslashes($codigo_local)); ?>', '<?php echo htmlspecialchars(addslashes($nom)); ?>', 'Año <?php echo $anio_actual; ?>', '<?php echo htmlspecialchars(addslashes($fecha_formateada)); ?>', 'Archivos Excel')" class="btn btn-sm btn-outline-custom" title="Descargar Constancia PDF">
                                                        <i class="bi bi-file-earmark-pdf-fill"></i> PDF
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
    </div><!-- /.ugel-app-shell -->

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
const observaciones = <?php echo json_encode($mensajes_observacion); ?>;
const bloqueadosGestion = <?php echo json_encode($bloqueados); ?>;

function abrirModuloAsistencia() {
    const selectMes = document.querySelector('select[name="mes"]');
    const anioInput = document.querySelector('input[name="anio"]');
    const mes = selectMes && selectMes.value ? selectMes.value : '<?php echo (int)date('n'); ?>';
    const anio = anioInput && anioInput.value ? anioInput.value : '<?php echo (int)$anio_actual; ?>';
    window.location.href = 'asistencia/index.php?mes=' + encodeURIComponent(mes) + '&anio=' + encodeURIComponent(anio);
}

function mostrarObservacion(selectElement) {
    const val = selectElement.value;
    const alertBox = document.getElementById('alerta_observacion');
    if (val && observaciones[val]) {
        const data = observaciones[val];
        alertBox.style.display = 'block';
        alertBox.className = `alert ${data.estado === 'Rechazado' ? 'alert-danger' : 'alert-warning'} mb-3 shadow-sm`;
        alertBox.innerHTML = `<strong>Anterior: ${data.estado}</strong><br><em>"${data.obs}"</em><br><small>Suba su archivo corregido abajo.</small>`;
    } else {
        alertBox.style.display = 'none';
    }
}

// Función para generar PDF al vuelo, aislado del CSS y viewport de la página
function generarPDFConstancia(codigo, ie_nombre, cod_local, tipo_doc, periodo, fecha, archivos_entregados) {
    
    const htmlContenido = `
        <div style="padding: 40px; font-family: Arial, sans-serif; color: #333; background-color: #fff;">
            <h3 style="text-align: center; margin-bottom: 30px; font-weight: bold; font-size: 24px; color: #000;">CONSTANCIA OFICIAL DE RECEPCIÓN DIGITAL</h3>
            <table style="width: 100%; border-collapse: collapse; font-size: 16px; margin-bottom: 25px;">
                <tbody>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; width: 35%; border: 1px solid #dee2e6; text-align: left; color: #000;">Código de Transacción:</th>
                        <td style="font-weight: bold; font-size: 1.2rem; padding: 15px; border: 1px solid #dee2e6; color: #000;">${codigo}</td>
                    </tr>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; text-align: left; color: #000;">Institución Educativa:</th>
                        <td style="padding: 15px; border: 1px solid #dee2e6; color: #000;">${ie_nombre} (Cód: ${cod_local})</td>
                    </tr>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; text-align: left; color: #000;">Tipo de Documento:</th>
                        <td style="padding: 15px; border: 1px solid #dee2e6; color: #000;">${tipo_doc}</td>
                    </tr>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; text-align: left; color: #000;">Periodo Correspondiente:</th>
                        <td style="padding: 15px; border: 1px solid #dee2e6; color: #000;">${periodo}</td>
                    </tr>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; text-align: left; color: #000;">Archivos Entregados:</th>
                        <td style="padding: 15px; border: 1px solid #dee2e6; color: #000;">${archivos_entregados}</td>
                    </tr>
                    <tr>
                        <th style="padding: 15px; background-color: #f8f9fa; border: 1px solid #dee2e6; text-align: left; color: #000;">Fecha y Hora de Recepción:</th>
                        <td style="padding: 15px; border: 1px solid #dee2e6; color: #000;">${fecha}</td>
                    </tr>
                </tbody>
            </table>
            <div style="text-align: center; margin-top: 40px; padding: 20px; background-color: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; border-radius: 8px; font-size: 15px;">
                Esta información ha sido guardada y registrada de manera segura en la base de datos de la UGEL.
            </div>
        </div>
    `;

    const opciones = {
        margin:       15,
        filename:     'Constancia_' + codigo + '.pdf',
        image:        { type: 'jpeg', quality: 0.98 },
        html2canvas:  { scale: 2, useCORS: true },
        jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
    };

    html2pdf().set(opciones).from(htmlContenido).save();
}

// Alerta automática para Innovación (ya que no usa selector <select>)
<?php if ($tipo == 'Innovacion'): ?>
document.addEventListener("DOMContentLoaded", function() {
    if (observaciones['Innovacion']) {
        const obsData = observaciones['Innovacion'];
        const alertBox = document.getElementById('alerta_observacion');
        alertBox.style.display = 'block';
        alertBox.className = `alert ${obsData.estado === 'Rechazado' ? 'alert-danger' : 'alert-warning'} mb-3 shadow-sm`;
        alertBox.innerHTML = `<strong>Anterior: ${obsData.estado}</strong><br><em>"${obsData.obs}"</em><br><small>Suba su archivo corregido abajo.</small>`;
    }
});
<?php endif; ?>

function validarEpla(input) {
    if (input.files.length > 6) {
        alert("⚠️ Solo puede seleccionar un máximo de 6 archivos Excel a la vez.");
        input.value = ""; 
    }
}

<?php if ($tipo == 'Gestion'): ?>
function actualizarTipoInstitucion() {
    var tipo = document.getElementById('tipo_institucion').value;
    var selectDoc = document.getElementById('tipo_documento');
    var tablaRows = document.querySelectorAll('.row-gestion');

    selectDoc.innerHTML = '<option value="" selected disabled>Seleccione...</option>';

    var opciones = [];
    if (tipo === 'Unidocente') {
        opciones = [['DocGestion', 'Documentos de Gestión']];
    } else if (tipo === 'Multigrado') {
        opciones = [
            ['RI', 'Reglamento Interno (RI)'],
            ['PAT', 'Plan Anual de Trabajo (PAT)'],
            ['PCI', 'Proyecto Curricular Institucional (PCI)'],
            ['PEI', 'Proyecto Educativo Institucional (PEI)'],
            ['RD_CONEI', 'R.D. de CONEI']
        ];
    }

    opciones.forEach(function(opc) {
        var estaBloqueado = bloqueadosGestion.includes(opc[0]);
        var subsanar = observaciones[opc[0]] !== undefined;
        var option = document.createElement('option');
        option.value = opc[0];
        
        option.text = opc[1] + (subsanar ? ' ⚠️ (Subsanar)' : '');
        
        if (estaBloqueado) {
            option.disabled = true;
            option.className = 'text-danger';
        }
        selectDoc.appendChild(option);
    });

    // Mostrar filas de tabla correspondientes
    tablaRows.forEach(function(row) {
        if (tipo === 'Unidocente' && row.dataset.tipo === 'Unidocente') {
            row.style.display = '';
        } else if (tipo === 'Multigrado' && row.dataset.tipo === 'Multigrado') {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}
<?php endif; ?>
</script>
</body>
</html>
