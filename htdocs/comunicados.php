<?php
session_start();
include 'conexion.php';
require_once 'components/ui.php';


// 1. Validar sesión y proteger ruta
if (!isset($_SESSION['id_usuario']) || empty($_SESSION['id_ie'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = $_SESSION['id_usuario'];
$id_ie = $_SESSION['id_ie'];

// 2. Obtener datos del usuario y su IE para el navbar
$query_user = "SELECT u.nombres, u.ape_pat, u.ape_mat, u.rol, u.correo, u.dni, i.id AS id_ie, i.nombre_ie, i.codigo_local 
               FROM usuarios u
               LEFT JOIN asignaciones a ON u.id = a.id_usuario AND a.estado = 'activo'
               LEFT JOIN instituciones i ON a.id_ie = i.id
               WHERE u.id = ?";
$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("i", $id_usuario);
$stmt_user->execute();
$user_data = $stmt_user->get_result()->fetch_assoc();

$nombre_completo = $user_data['nombres'] . " " . $user_data['ape_pat'] . " " . $user_data['ape_mat'];
$nombre_ie = $user_data['nombre_ie'] ?? 'Sin Asignar'; 
$codigo_local = $user_data['codigo_local'] ?? 'N/A';

// 3. Obtener comunicados y verificar si la IE actual ya los vio, incluyendo datos de quien publicó
$sql = "SELECT c.*, cv.fecha_visto, u.nombres, u.ape_pat, u.rol, ae.sub_tipo 
        FROM comunicados c 
        LEFT JOIN comunicados_vistos cv ON c.id = cv.id_comunicado AND cv.id_ie = ?
        LEFT JOIN usuarios u ON c.id_usuario = u.id 
        LEFT JOIN asignaciones_especialistas ae ON u.id = ae.id_usuario AND ae.estado = 'activo'
        ORDER BY c.fecha_publicacion DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id_ie);
$stmt->execute();
$resultado = $stmt->get_result();

$comunicados_list = [];
while ($row = $resultado->fetch_assoc()) {
    $comunicados_list[] = $row;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comunicados y Normas - UGEL</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        /* Ajustes de responsividad */
        @media (max-width: 768px) {
            h4.ugel-section-title {
                font-size: 1.15rem;
            }
        }
        /* Estilos personalizados para la tabla de comunicados */
        .table-custom-header th { 
            background-color: var(--ugel-green-dark) !important;
            color: white !important; 
            font-weight: 600;
            white-space: nowrap;
            padding: 12px 16px;
        }
        .table th, .table td {
            padding: 12px 16px;
        }
        .table {
            border-color: rgba(0,0,0,0.05);
        }
        .table-responsive {
            border: 1px solid var(--ugel-border);
            border-radius: var(--ugel-radius-card);
            background: var(--ugel-surface);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar mb-4 mb-md-5 sticky-top shadow-sm">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center" href="dashboard.php">
            <i class="bi bi-shield-check ugel-brand-icon fs-2 me-2 text-success"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTI&Oacute;N DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE</small>
            </div>
        </a>
        
        <div class="ms-auto">
            <div class="dropdown">
                <button class="btn profile-btn ugel-profile-button dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                    <div class="text-end d-none d-sm-block">
                        <small class="d-block text-uppercase"><?php echo htmlspecialchars($user_data['rol'] ?? 'Director'); ?></small>
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
                            <span>Cerrar Sesi&oacute;n</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<div class="container px-3 px-md-4 ugel-shell dashboard-shell">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <h4 class="fw-bold ugel-section-title mb-0">
            <i class="bi bi-megaphone-fill me-2 text-success"></i>Comunicados, Oficios y Normas
        </h4>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge ugel-context-badge">IE: <?php echo htmlspecialchars($nombre_ie); ?></span>
            <a href="dashboard.php" class="btn btn-outline-primary ugel-menu-action fw-bold rounded-pill px-4 py-2">
                <i class="bi bi-house-door"></i>
                <span>Volver al Inicio</span>
            </a>
        </div>
    </div>

    <!-- Panel de Filtros Moderno -->
    <div class="card shadow-sm border-0 rounded-4 p-3 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-12 col-md-5">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0 text-muted"><i class="bi bi-search"></i></span>
                    <input type="text" id="inputBuscar" class="form-control border-start-0 ps-0" placeholder="Buscar por título o palabra clave...">
                </div>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <select id="selectTipo" class="form-select">
                    <option value="">Todos los tipos</option>
                    <option value="oficio">Oficios</option>
                    <option value="norma">Normas</option>
                    <option value="comunicado">Comunicados</option>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-3">
                <select id="selectEstado" class="form-select">
                    <option value="">Todos los estados</option>
                    <option value="visto">Visto</option>
                    <option value="novisto">No visto</option>
                </select>
            </div>
            <div class="col-12 col-md-1 text-md-end text-center d-none" id="btnLimpiarCol">
                <button type="button" id="btnLimpiar" class="btn btn-outline-secondary w-100 p-2 rounded-3" title="Limpiar Filtros">
                    <i class="bi bi-x-circle-fill fs-5"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Tabla de Documentos (Oficios, Normas, Comunicados) -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-5 p-0 <?php echo count($comunicados_list) > 0 ? '' : 'd-none'; ?>" id="tabla-documentos-wrapper">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-custom-header">
                    <tr>
                        <th style="width: 140px;">Tipo</th>
                        <th>Documento</th>
                        <th>Publicador</th>
                        <th style="width: 150px;">Fecha</th>
                        <th style="width: 140px;">Estado</th>
                        <th style="width: 160px;" class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody id="documentos-tabla-body">
                    <?php 
                    if (count($comunicados_list) > 0) {
                        foreach ($comunicados_list as $row) {
                            echo ui_document_row($row);
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Mensaje cuando no hay resultados (Filtros o vacío) -->
    <div id="no-docs-card" class="card shadow-sm border-0 rounded-4 p-5 text-center text-muted <?php echo count($comunicados_list) > 0 ? 'd-none' : ''; ?> mb-5">
        <div class="mb-3">
            <i class="bi bi-info-circle fs-1 text-success opacity-50"></i>
        </div>
        <h5>No se encontraron documentos</h5>
        <p class="small mb-0">Intenta cambiar los filtros de búsqueda o regresa más tarde.</p>
    </div>
</div>

<script>
function obtenerFechaFormateada() {
    const ahora = new Date();
    const dia = String(ahora.getDate()).padStart(2, '0');
    const mes = String(ahora.getMonth() + 1).padStart(2, '0');
    const anio = ahora.getFullYear();
    let horas = ahora.getHours();
    const minutos = String(ahora.getMinutes()).padStart(2, '0');
    const ampm = horas >= 12 ? 'PM' : 'AM';
    horas = horas % 12;
    horas = horas ? horas : 12; // 0 horas -> 12
    const horasStr = String(horas).padStart(2, '0');
    return `${dia}/${mes}/${anio} ${horasStr}:${minutos} ${ampm}`;
}

function verDocumento(idComunicado, rutaArchivo) {
    // 1. Enviamos petición al servidor para marcar como visto (notificado)
    fetch('marcar_visto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id_comunicado=' + idComunicado
    })
    .then(response => response.text())
    .then(data => {
        if(data.trim() === 'ok') {
            // 2. Abrimos el documento en una nueva pestaña
            window.open(rutaArchivo, '_blank');
            
            // 3. Actualizamos la vista sin recargar la página
            const fechaFormateada = obtenerFechaFormateada();
            
            // Obtener la fila del documento
            const rowElement = document.querySelector(`.document-item-row[data-id="${idComunicado}"]`);
            
            // Determinar la clase del botón basada en el tema de la fila
            let btnClass = 'btn-green'; // valor por defecto
            if (rowElement) {
                const theme = rowElement.getAttribute('data-theme');
                if (theme) {
                    btnClass = theme.replace('is-', 'btn-');
                }
                
                // Cambiar el data-attribute de estado a visto
                rowElement.setAttribute('data-estado', 'visto');
            }
            
            // Actualizar badge de estado
            const estadoContainer = document.querySelector(`.estado-container[data-id="${idComunicado}"]`);
            if (estadoContainer) {
                estadoContainer.innerHTML = `
                    <span class="badge-soft" style="font-size: 0.75rem;"><i class="bi bi-check-circle-fill me-1"></i> Visto</span>
                `;
            }
            
            // Actualizar botón de acción
            const actionBtnContainer = document.querySelector(`.action-btn-container[data-id="${idComunicado}"]`);
            if (actionBtnContainer) {
                actionBtnContainer.innerHTML = `
                    <a href="${rutaArchivo}" download class="btn ${btnClass} btn-sm px-3 shadow-sm">
                        <i class="bi bi-download me-1"></i> Descargar
                    </a>
                `;
            }
            
            // Actualizar timestamp de visualización
            const timestampContainer = document.querySelector(`.visto-timestamp-container[data-id="${idComunicado}"]`);
            if (timestampContainer) {
                timestampContainer.innerHTML = `
                    <div class="text-muted mt-1" style="font-size: 0.68rem;">
                        ${fechaFormateada}
                    </div>
                `;
            }
            
            // Volver a aplicar filtros por si el estado actual oculta el elemento (ejm. si estaba filtrado por "No visto")
            aplicarFiltros();
        } else {
            alert('Hubo un error al registrar la visualización.');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Hubo un problema con la conexión de red.');
    });
}

// Lógica de Filtros
function aplicarFiltros() {
    const query = document.getElementById('inputBuscar').value.toLowerCase().trim();
    const tipo = document.getElementById('selectTipo').value;
    const estado = document.getElementById('selectEstado').value;
    
    // Mostrar/Ocultar botón de limpiar filtros
    const btnLimpiarCol = document.getElementById('btnLimpiarCol');
    if (query || tipo || estado) {
        if (btnLimpiarCol) btnLimpiarCol.classList.remove('d-none');
    } else {
        if (btnLimpiarCol) btnLimpiarCol.classList.add('d-none');
    }
    
    // Filtrar Filas de la Tabla
    const rows = document.querySelectorAll('.document-item-row');
    let visibleRows = 0;
    
    rows.forEach(row => {
        const rowTipo = row.getAttribute('data-tipo') || '';
        const rowEstado = row.getAttribute('data-estado') || '';
        const rowTitulo = row.getAttribute('data-titulo') || '';
        
        const matchQuery = !query || rowTitulo.includes(query);
        const matchTipo = !tipo || rowTipo.includes(tipo);
        const matchEstado = !estado || rowEstado === estado;
        
        if (matchQuery && matchTipo && matchEstado) {
            row.classList.remove('d-none');
            visibleRows++;
        } else {
            row.classList.add('d-none');
        }
    });
    
    const tablaWrapper = document.getElementById('tabla-documentos-wrapper');
    const noDocsCard = document.getElementById('no-docs-card');
    
    if (tablaWrapper) {
        if (visibleRows === 0 && rows.length > 0) {
            tablaWrapper.classList.add('d-none');
        } else {
            tablaWrapper.classList.remove('d-none');
        }
    }
    
    if (noDocsCard) {
        if (visibleRows === 0 && rows.length > 0) {
            noDocsCard.classList.remove('d-none');
        } else {
            noDocsCard.classList.add('d-none');
        }
    }
}

// Configurar event listeners al cargar el DOM
document.addEventListener('DOMContentLoaded', () => {
    const inputBuscar = document.getElementById('inputBuscar');
    const selectTipo = document.getElementById('selectTipo');
    const selectEstado = document.getElementById('selectEstado');
    const btnLimpiar = document.getElementById('btnLimpiar');
    
    if (inputBuscar) inputBuscar.addEventListener('input', aplicarFiltros);
    if (selectTipo) selectTipo.addEventListener('change', aplicarFiltros);
    if (selectEstado) selectEstado.addEventListener('change', aplicarFiltros);
    
    if (btnLimpiar) {
        btnLimpiar.addEventListener('click', () => {
            if (inputBuscar) inputBuscar.value = '';
            if (selectTipo) selectTipo.value = '';
            if (selectEstado) selectEstado.value = '';
            aplicarFiltros();
        });
    }
});
</script>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>