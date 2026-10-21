<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit();
}

$id_usuario = (int)$_SESSION['id_usuario'];
$mensaje = '';
$error = '';

if (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'panel_usuario.php') === false) {
    $_SESSION['url_previa'] = $_SERVER['HTTP_REFERER'];
}
$rol_actual = strtolower($_SESSION['rol'] ?? '');
$url_regreso = $_SESSION['url_previa'] ?? (
    ($rol_actual === 'especialista') ? 'dashboard_especialista.php' : (
        in_array($rol_actual, ['director', 'subdirector', 'sub-director'], true) ? 'ficha_monitoreo_2026.php' : 'dashboard.php'
    )
);

function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? 'contacto';

    if ($accion === 'contacto') {
        $correo = trim($_POST['correo'] ?? '');
        $telefono = trim($_POST['telefono'] ?? '');
        $stmt = $conn->prepare("UPDATE usuarios SET correo = ?, telefono = ? WHERE id = ?");
        $stmt->bind_param("ssi", $correo, $telefono, $id_usuario);
        if ($stmt->execute()) {
            $mensaje = 'Datos de contacto actualizados correctamente.';
        } else {
            $error = 'Error al actualizar los datos de contacto.';
        }
        $stmt->close();
    }

    if ($accion === 'password') {
        $password1 = trim($_POST['password_nueva'] ?? '');
        $password2 = trim($_POST['password_confirmar'] ?? '');
        if (strlen($password1) < 6) {
            $error = 'La contraseña debe tener al menos 6 caracteres.';
        } elseif ($password1 !== $password2) {
            $error = 'Las contraseñas no coinciden.';
        } else {
            $hash = password_hash($password1, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE usuarios SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hash, $id_usuario);
            if ($stmt->execute()) {
                $mensaje = 'Contraseña actualizada correctamente.';
            } else {
                $error = 'Error al actualizar la contraseña.';
            }
            $stmt->close();
        }
    }
}

$query = "SELECT dni, nombres, ape_pat, ape_mat, rol, encargatura, correo, telefono FROM usuarios WHERE id = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $id_usuario);
$stmt->execute();
$user_data = $stmt->get_result()->fetch_assoc();
$stmt->close();

$nombre_completo = trim(($user_data['nombres'] ?? '') . " " . ($user_data['ape_pat'] ?? '') . " " . ($user_data['ape_mat'] ?? ''));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil de Usuario - Sistema IE</title>
    <link href="assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
<link rel="stylesheet" href="assets/lib/tabler-icons/tabler-icons.min.css">
<link rel="stylesheet" href="assets/css/fluent.css">
    <style>
        :root { --main-green:#198754; --dark-green:#006633; --bg-color:#f8fdf9; }
        body { background-color: var(--bg-color); font-family:'Segoe UI',Tahoma,sans-serif; }
        .profile-card { background:#fff; border-radius:16px; box-shadow:0 8px 22px rgba(0,0,0,.06); border:1px solid #d1e7dd; padding:30px; }
        .form-label { font-weight:700; color:#495057; font-size:.9rem; }
        .readonly-field { background-color:#e9ecef; cursor:not-allowed; }
        .btn-green { background:#198754; color:#fff; border:none; }
        .btn-green:hover { background:#146c43; color:#fff; }
        .security-box { border:1px dashed #198754; background:#f3fbf6; border-radius:12px; padding:18px; }
    </style>
</head>
<body>
<nav class="navbar navbar-expand navbar-light ugel-topbar mb-4 mb-md-5 sticky-top shadow-sm">
    <div class="container-fluid px-3 px-md-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= h($url_regreso) ?>">
            <i class="bi bi-shield-check ugel-brand-icon"></i>
            <div class="d-flex flex-column lh-1">
                <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                <small class="ugel-brand-subtitle">Sistema IE · Perfil de Usuario</small>
            </div>
        </a>
    </div>
</nav>

<div class="container px-3 px-md-4 mb-5">
    <div class="row justify-content-center">
        <div class="col-12 col-lg-8">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="fw-bold text-success mb-0"><i class="bi bi-person-lines-fill me-2"></i>Perfil de Usuario</h4>
                <a href="<?= h($url_regreso) ?>" class="btn btn-outline-secondary fw-bold rounded-pill"><i class="bi bi-arrow-left me-2"></i>Regresar</a>
            </div>

            <?php if($mensaje): ?><div class="alert alert-success shadow-sm"><i class="bi bi-check-circle-fill me-2"></i><?= h($mensaje) ?></div><?php endif; ?>
            <?php if($error): ?><div class="alert alert-danger shadow-sm"><i class="bi bi-exclamation-triangle-fill me-2"></i><?= h($error) ?></div><?php endif; ?>

            <div class="profile-card">
                <form action="panel_usuario.php" method="POST">
                    <input type="hidden" name="accion" value="contacto">
                    <h5 class="mb-4 text-success border-bottom pb-2">Información Personal</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6"><label class="form-label">DNI</label><input type="text" class="form-control readonly-field" value="<?= h($user_data['dni']) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label">Nombres</label><input type="text" class="form-control readonly-field" value="<?= h($user_data['nombres']) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label">Apellido Paterno</label><input type="text" class="form-control readonly-field" value="<?= h($user_data['ape_pat']) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label">Apellido Materno</label><input type="text" class="form-control readonly-field" value="<?= h($user_data['ape_mat']) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label">Rol</label><input type="text" class="form-control readonly-field" value="<?= h(ucfirst($user_data['rol'])) ?>" readonly></div>
                        <div class="col-md-6"><label class="form-label">Encargatura</label><input type="text" class="form-control readonly-field" value="<?= h($user_data['encargatura'] ?: 'No registrada') ?>" readonly></div>
                    </div>

                    <h5 class="mb-4 text-primary border-bottom pb-2 mt-5">Datos de Contacto</h5>
                    <div class="row g-3">
                        <div class="col-md-8"><label class="form-label">Correo Electrónico</label><input type="email" name="correo" class="form-control" value="<?= h($user_data['correo']) ?>" required></div>
                        <div class="col-md-4"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?= h($user_data['telefono']) ?>" required></div>
                    </div>
                    <div class="text-end mt-4 pt-3 border-top"><button type="submit" class="btn btn-green fw-bold px-4 py-2 rounded-pill"><i class="bi bi-save me-2"></i>Guardar datos</button></div>
                </form>

                <div class="security-box mt-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                        <div><h6 class="fw-bold text-success mb-1"><i class="bi bi-shield-lock me-2"></i>Seguridad de la cuenta</h6><p class="text-muted mb-0 small">La contraseña no se muestra por seguridad. Puedes cambiarla desde una ventana segura.</p></div>
                        <button class="btn btn-outline-success rounded-pill fw-bold" data-bs-toggle="modal" data-bs-target="#modalPassword"><i class="bi bi-key me-1"></i>Cambiar contraseña</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPassword" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content rounded-4">
      <div class="modal-header bg-success text-white"><h5 class="modal-title"><i class="bi bi-key me-2"></i>Cambiar contraseña</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
      <form method="POST" action="panel_usuario.php">
        <input type="hidden" name="accion" value="password">
        <div class="modal-body">
          <label class="form-label">Nueva contraseña</label>
          <input type="password" name="password_nueva" class="form-control mb-3" minlength="6" required>
          <label class="form-label">Confirmar nueva contraseña</label>
          <input type="password" name="password_confirmar" class="form-control" minlength="6" required>
          <div class="form-text mt-2">Escribe la nueva contraseña dos veces para confirmar el cambio.</div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-success fw-bold"><i class="bi bi-check-circle me-1"></i>Confirmar cambio</button></div>
      </form>
    </div>
  </div>
</div>

<script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
