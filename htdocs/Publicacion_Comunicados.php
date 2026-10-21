<?php
session_start();
include 'conexion.php';

// Validar sesión
if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = $_SESSION['id_usuario'];

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

// -------------------------------------------------------------------
// PROCESAMIENTO DEL FORMULARIO: SUBIR COMUNICADO (SEGUNDO REQUISITO)
// -------------------------------------------------------------------
if (isset($_POST['btn_publicar'])) {
    $titulo = trim($_POST['titulo']);
    $tipo = $_POST['tipo']; // 'Comunicado', 'Oficio', 'Norma', 'Plantilla'
    $fecha_publicacion = date('Y-m-d H:i:s');

    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] == 0) {
        $file_tmp = $_FILES['archivo']['tmp_name'];
        $file_name = $_FILES['archivo']['name'];
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

        // Determinar subcarpeta según el tipo
        $carpetas_tipo = [
            'Comunicado' => 'Comunicados',
            'Oficio' => 'Oficios',
            'Norma' => 'Normas',
            'Plantilla' => 'Plantillas'
        ];
        $sub_carpeta = $carpetas_tipo[$tipo] ?? 'Otros';

        // Verificar y crear estructura de directorios
        $base_dir = 'uploads/';
        if (!file_exists($base_dir)) mkdir($base_dir, 0777, true);
        
        $pub_dir = $base_dir . 'Publicaciones/';
        if (!file_exists($pub_dir)) mkdir($pub_dir, 0777, true);
        
        $target_dir = $pub_dir . $sub_carpeta . '/';
        if (!file_exists($target_dir)) mkdir($target_dir, 0777, true);

        // Primero insertamos el registro para obtener el ID AUTO_INCREMENT
        $stmt_insert = $conn->prepare("INSERT INTO comunicados (titulo, tipo, id_usuario, ruta_archivo, extension, fecha_publicacion) VALUES (?, ?, ?, '', ?, ?)");
        $stmt_insert->bind_param("ssiss", $titulo, $tipo, $id_usuario, $extension, $fecha_publicacion);
        
        if ($stmt_insert->execute()) {
            $nuevo_id = $conn->insert_id;
            // Estructura de nombre solicitada: 'tipo'-'0000''id'-'id_usuario'
            $nuevo_nombre_archivo = $tipo . "-0000" . $nuevo_id . "-" . $id_usuario . "." . $extension;
            $ruta_final = $target_dir . $nuevo_nombre_archivo;

            // Mover archivo
            if (move_uploaded_file($file_tmp, $ruta_final)) {
                // Actualizar el registro con la ruta correcta
                $stmt_update = $conn->prepare("UPDATE comunicados SET ruta_archivo = ? WHERE id = ?");
                $stmt_update->bind_param("si", $ruta_final, $nuevo_id);
                $stmt_update->execute();
                
                header("Location: Publicacion_Comunicados.php?msg=success");
                exit();
            } else {
                // Si falla la subida física, borramos el registro huérfano
                $conn->query("DELETE FROM comunicados WHERE id = $nuevo_id");
                header("Location: Publicacion_Comunicados.php?msg=error_upload");
                exit();
            }
        } else {
            header("Location: Publicacion_Comunicados.php?msg=error_db");
            exit();
        }
    } else {
        header("Location: Publicacion_Comunicados.php?msg=error_file");
        exit();
    }
}

// -------------------------------------------------------------------
// PROCESAMIENTO: ELIMINAR COMUNICADO
// -------------------------------------------------------------------
if (isset($_GET['delete_id'])) {
    $del_id = intval($_GET['delete_id']);
    // Obtener la ruta para eliminar el archivo físico
    $stmt_del = $conn->prepare("SELECT ruta_archivo FROM comunicados WHERE id = ?");
    $stmt_del->bind_param("i", $del_id);
    $stmt_del->execute();
    $res_del = $stmt_del->get_result();
    
    if ($row_del = $res_del->fetch_assoc()) {
        if (file_exists($row_del['ruta_archivo']) && !empty($row_del['ruta_archivo'])) {
            unlink($row_del['ruta_archivo']); // Elimina el archivo del servidor
        }
        // Eliminar registro de BD
        $conn->query("DELETE FROM comunicados WHERE id = $del_id");
        header("Location: Publicacion_Comunicados.php?msg=deleted");
        exit();
    }
}

// -------------------------------------------------------------------
// PROCESAMIENTO: EDITAR COMUNICADO (Sólo datos, no archivo)
// -------------------------------------------------------------------
if (isset($_POST['btn_editar'])) {
    $id_edit = intval($_POST['id_edit']);
    $titulo_edit = trim($_POST['titulo']);
    $tipo_edit = $_POST['tipo'];

    $stmt_update_info = $conn->prepare("UPDATE comunicados SET titulo = ?, tipo = ? WHERE id = ?");
    $stmt_update_info->bind_param("ssi", $titulo_edit, $tipo_edit, $id_edit);
    
    if ($stmt_update_info->execute()) {
        header("Location: Publicacion_Comunicados.php?msg=edited");
    } else {
        header("Location: Publicacion_Comunicados.php?msg=error_db");
    }
    exit();
}

$sql_list = "SELECT c.*, u.nombres, u.ape_pat, u.rol, ae.sub_tipo FROM comunicados c LEFT JOIN usuarios u ON c.id_usuario = u.id LEFT JOIN asignaciones_especialistas ae ON u.id = ae.id_usuario AND ae.estado = 'activo' ORDER BY c.fecha_publicacion DESC";
$resultado_comunicados = $conn->query($sql_list);

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Publicación de Comunicados - Sistema IE</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
    <link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        :root { 
            --main-green: #198754; 
            --main-blue: #0d6efd;
        }
        body { 
            background-color: #f8fdf9; 
            font-family: 'Segoe UI', Tahoma, sans-serif;
        }
        /* Cajas de Formulario */
        .form-box { background-color: #0d6efd; color: white; border-radius: 8px 8px 0 0; padding: 12px 20px; font-weight: bold; font-size: 1.1rem; }
        .form-body { background-color: white; padding: 25px; border: 1px solid #cfe2ff; border-top: none; border-radius: 0 0 8px 8px; margin-bottom: 25px; }
        
        /* Tabla */
        .table-custom-header th { background-color: #198754 !important; color: white !important; font-weight: 600; white-space: nowrap; }
        .table { border-color: #d1e7dd; }
        .table-hover tbody tr:hover { background-color: #eaf5ec; }
        
        .btn-green { background-color: #198754; color: white; border: none; }
        .btn-green:hover { background-color: #146c43; color: white; }

        @media (max-width: 768px) {
            .navbar-brand span { font-size: 1rem !important; }
            .navbar-brand small { font-size: 0.65rem !important; }
            .navbar-custom .ms-auto { width: 100%; justify-content: flex-end; margin-top: 10px; }
            .action-buttons { flex-direction: column; gap: 5px; }
            .table td, .table th { font-size: 0.9rem; }
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light ugel-topbar mb-4 sticky-top shadow-sm">
    <div class="container-fluid px-3 px-md-4">
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
 
            <a href="ver_envios.php" class="btn profile-btn d-flex align-items-center gap-1">
                <i class="bi bi-folder-check me-1"></i> <span>Revisiones</span>
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

<div class="container-fluid mt-4 px-3 px-md-4">
    
    <?php if (isset($_GET['msg'])): ?>
        <?php if ($_GET['msg'] == 'success'): ?>
            <div class="alert alert-success alert-dismissible fade show shadow-sm border-0"><i class="bi bi-check-circle-fill me-2"></i> Documento publicado correctamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($_GET['msg'] == 'deleted'): ?>
            <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0"><i class="bi bi-trash-fill me-2"></i> Documento eliminado exitosamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php elseif ($_GET['msg'] == 'edited'): ?>
            <div class="alert alert-info alert-dismissible fade show shadow-sm border-0"><i class="bi bi-pencil-fill me-2"></i> Documento actualizado correctamente. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php else: ?>
            <div class="alert alert-warning alert-dismissible fade show shadow-sm border-0"><i class="bi bi-exclamation-triangle-fill me-2"></i> Ocurrió un error en la operación. <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="form-box shadow-sm"><i class="bi bi-cloud-arrow-up-fill me-2"></i>Publicar Nuevo Documento / Comunicado</div>
    <div class="form-body shadow-sm">
        <form method="POST" action="" enctype="multipart/form-data" class="row g-3 align-items-end">
            <div class="col-12 col-md-5">
                <label class="form-label text-primary fw-bold">Título del Documento</label>
                <input type="text" class="form-control border-primary border-opacity-50" name="titulo" required placeholder="Ej. Directiva N° 001-2026 UGEL CHINCHA">
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-primary fw-bold">Tipo de Documento</label>
                <select class="form-select border-primary border-opacity-50" name="tipo" required>
                    <option value="" selected disabled>Seleccione...</option>
                    <option value="Comunicado">Comunicado</option>
                    <option value="Oficio">Oficio</option>
                    <option value="Norma">Norma</option>
                    <option value="Plantilla">Plantilla</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label text-primary fw-bold">Archivo Adjunto</label>
                <input type="file" class="form-control border-primary border-opacity-50" name="archivo" required>
            </div>
            <div class="col-12 col-md-1 d-flex gap-2 mt-3 mt-md-0">
                <button type="submit" name="btn_publicar" class="btn btn-primary w-100 fw-bold shadow-sm" title="Subir y Publicar">
                    <i class="bi bi-upload"></i> <span class="d-inline d-md-none ms-2">Subir Documento</span>
                </button>
            </div>
        </form>
    </div>

    <h5 class="fw-bold text-success mb-3"><i class="bi bi-table me-2"></i>Documentos Publicados Recientemente</h5>
    <div class="table-responsive shadow-sm bg-white p-3 border border-success border-opacity-25 rounded mb-5">
        <table class="table table-hover table-bordered align-middle mb-0">
            <thead class="table-custom-header">
                <tr>
                    <th class="text-center" style="width: 50px;">ID</th>
                    <th>Título</th>
                    <th class="text-center">Tipo</th>
                    <th class="text-center d-none d-md-table-cell">Extensión</th>
                    <th class="d-none d-sm-table-cell">Fecha Publicación</th>
                    <th class="d-none d-md-table-cell">Publicado Por</th>
                    <th class="text-center" style="width: 150px;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($resultado_comunicados && $resultado_comunicados->num_rows > 0) {
                    while ($row = $resultado_comunicados->fetch_assoc()) {
                        
                        // Icono dinámico según el tipo
                        $icono_tipo = 'bi-file-earmark-text';
                        $color_tipo = 'bg-secondary';
                        if($row['tipo'] == 'Comunicado') { $icono_tipo = 'bi-megaphone'; $color_tipo = 'bg-info text-dark'; }
                        if($row['tipo'] == 'Oficio') { $icono_tipo = 'bi-envelope-paper'; $color_tipo = 'bg-primary'; }
                        if($row['tipo'] == 'Norma') { $icono_tipo = 'bi-book'; $color_tipo = 'bg-danger'; }
                        if($row['tipo'] == 'Plantilla') { $icono_tipo = 'bi-file-earmark-ruled'; $color_tipo = 'bg-warning text-dark'; }

                        echo "<tr>";
                        echo "<td class='text-center fw-bold text-muted'>{$row['id']}</td>";
                        echo "<td>
                                <a href='{$row['ruta_archivo']}' target='_blank' class='text-decoration-none text-dark fw-bold d-block text-wrap'>
                                    <i class='bi {$icono_tipo} text-primary me-2'></i>{$row['titulo']}
                                </a>
                                <small class='d-block d-sm-none text-muted mt-1'><i class='bi bi-clock me-1'></i>" . date("d/m/Y", strtotime($row['fecha_publicacion'])) . "</small>
                              </td>";
                        echo "<td class='text-center'><span class='badge {$color_tipo} px-2 py-1 shadow-sm'>{$row['tipo']}</span></td>";
                        echo "<td class='text-center d-none d-md-table-cell'><span class='badge bg-light text-dark border'>." . strtoupper($row['extension']) . "</span></td>";
                        echo "<td class='d-none d-sm-table-cell'><small class='text-muted'><i class='bi bi-clock me-1'></i>" . date("d/m/Y", strtotime($row['fecha_publicacion'])) . "</small></td>";

                        // AQUI ESTÁ LA MODIFICACIÓN: Mostrar Publicado por, Nombre Completo y el Rol del usuario - sub_tipo
                        $rol_usuario = isset($row['rol']) ? strtoupper($row['rol']) : 'DESCONOCIDO';
                        $sub_tipo_usuario_lista = isset($row['sub_tipo']) && !empty($row['sub_tipo']) ? strtoupper($row['sub_tipo']) : '';
                        $rol_mostrar = $sub_tipo_usuario_lista ? $rol_usuario . ' - ' . $sub_tipo_usuario_lista : $rol_usuario;
                        
                        echo "<td class='d-none d-md-table-cell'>
                                <small class='text-muted d-block mb-1' style='font-size: 0.7rem;'>Publicado por:</small>
                                <small class='text-dark fw-bold d-block'>{$row['nombres']} {$row['ape_pat']}</small>
                                <span class='badge bg-secondary bg-opacity-10 text-secondary border mt-1' style='font-size: 0.65rem;'><i class='bi bi-person-badge me-1'></i>Rol: {$rol_mostrar}</span>
                              </td>";
                        
                        // Acciones: Editar y Eliminar
                        $titulo_js = htmlspecialchars($row['titulo'], ENT_QUOTES);
                        echo "<td class='text-center'>
                                <div class='d-flex flex-row justify-content-center gap-2 action-buttons'>
                                    <button class='btn btn-sm btn-outline-primary shadow-sm flex-fill' onclick=\"abrirModalEdit({$row['id']}, '{$titulo_js}', '{$row['tipo']}')\" title='Editar Detalles'><i class='bi bi-pencil-square'></i></button>
                                    <a href='Publicacion_Comunicados.php?delete_id={$row['id']}' class='btn btn-sm btn-outline-danger shadow-sm flex-fill' onclick=\"return confirm('¿Está seguro de eliminar este documento de forma permanente? Esta acción no se puede deshacer.');\" title='Eliminar Documento'><i class='bi bi-trash-fill'></i></a>
                                </div>
                              </td>";
                        echo "</tr>";
                    }
                } else {
                    echo "<tr><td colspan='7' class='text-center py-5 text-muted'><i class='bi bi-folder-x fs-1 d-block mb-2'></i>No hay comunicados publicados.</td></tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

</div>

<div class="modal fade" id="modalEditar" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <form action="Publicacion_Comunicados.php" method="POST">
        <div class="modal-header bg-primary text-white border-0">
          <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Editar Documento</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body p-4">
          <input type="hidden" name="id_edit" id="modal_id_edit">
          
          <div class="mb-3">
            <label class="form-label text-primary fw-bold">Título del Documento</label>
            <input type="text" class="form-control border-primary shadow-sm" name="titulo" id="modal_titulo" required>
          </div>
          
          <div class="mb-3">
            <label class="form-label text-primary fw-bold">Tipo de Documento</label>
            <select class="form-select border-primary shadow-sm" name="tipo" id="modal_tipo" required>
              <option value="Comunicado">Comunicado</option>
              <option value="Oficio">Oficio</option>
              <option value="Norma">Norma</option>
              <option value="Plantilla">Plantilla</option>
            </select>
          </div>
          <div class="alert alert-info py-2" style="font-size: 0.85rem;">
             Nota: Para cambiar el archivo adjunto, es necesario eliminar este registro y crear uno nuevo para no romper la estructura de carpetas.
          </div>
        </div>
        <div class="modal-footer bg-light border-0">
          <button type="button" class="btn btn-secondary fw-bold" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" name="btn_editar" class="btn btn-primary fw-bold px-4">Guardar Cambios</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>
// Función para pasar datos al modal de edición
function abrirModalEdit(id, titulo, tipo) {
    document.getElementById('modal_id_edit').value = id;
    document.getElementById('modal_titulo').value = titulo;
    document.getElementById('modal_tipo').value = tipo;
    
    var myModal = new bootstrap.Modal(document.getElementById('modalEditar'));
    myModal.show();
}
</script>

</body>
</html>