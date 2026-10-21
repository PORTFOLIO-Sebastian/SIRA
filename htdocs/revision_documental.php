<?php
session_start();
include 'conexion.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: login.php');
    exit();
}
if ($_SESSION['rol'] !== 'especialista') {
    header('Location: dashboard.php');
    exit();
}

$id_usuario = (int) $_SESSION['id_usuario'];
$id_registro = (int) ($_GET['id'] ?? 0);

if ($id_registro <= 0) {
    $_SESSION['error'] = 'ID de registro inválido.';
    header('Location: especialista_fichas.php');
    exit();
}

function h($v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Obtener datos del usuario logueado
$stmt = $conn->prepare("SELECT nombres, ape_pat, ape_mat, DNI FROM usuarios WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id_usuario);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
$nombre_corto = h($user['nombres'] ?? 'Usuario');

// Obtener datos de la ficha
$sql = "SELECT r.id, r.codigo_envio, r.estado, r.fecha_envio, r.porcentaje, r.escala_valorativa, r.observacion_general,
               tf.nombre AS tipo_ficha, tf.anio,
               i.nombre_ie, i.codigo_local, IFNULL(i.codigo_institucion,'') AS codigo_institucion, i.distrito,
               u.nombres AS dir_nombres, u.ape_pat AS dir_ape_pat, u.ape_mat AS dir_ape_mat, u.dni AS dir_dni,
               docf.ruta_archivo AS ruta_pdf, docc.ruta_archivo AS ruta_constancia
        FROM fm_registros r
        INNER JOIN asignaciones_especialistas ae ON ae.id_institucion = r.id_institucion AND ae.id_usuario = ? AND ae.estado = 'activo'
        INNER JOIN fm_tipos_ficha tf ON tf.id = r.id_tipo_ficha
        INNER JOIN instituciones i ON i.id = r.id_institucion
        INNER JOIN usuarios u ON u.id = r.id_usuario
        LEFT JOIN fm_documentos docf  ON docf.id_registro  = r.id AND docf.tipo_documento  = 'PDF_FICHA_DIRECTOR'
        LEFT JOIN fm_documentos docc  ON docc.id_registro  = r.id AND docc.tipo_documento  = 'CONSTANCIA_ENVIO_DIRECTOR'
        WHERE r.id = ? AND r.activo = 1 LIMIT 1";

$st = $conn->prepare($sql);
$st->bind_param('ii', $id_usuario, $id_registro);
$st->execute();
$f = $st->get_result()->fetch_assoc();
$st->close();

if (!$f) {
    $_SESSION['error'] = 'No se encontró la ficha o no tiene permisos para revisarla.';
    header('Location: especialista_fichas.php');
    exit();
}

$mensaje = '';
$error = '';

// POST: cambiar estado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'cambiar_estado') {
    $estado_nuevo = $_POST['estado_nuevo'] ?? '';
    $justificacion = trim($_POST['justificacion'] ?? '');
    $permitidos = ['OBSERVADO', 'RECHAZADO', 'CONFORME'];

    if (!in_array($estado_nuevo, $permitidos, true)) {
        $error = 'Estado no permitido.';
    } elseif (in_array($estado_nuevo, ['OBSERVADO', 'RECHAZADO'], true) && $justificacion === '') {
        $error = 'Debe escribir una justificación para marcar la ficha como ' . $estado_nuevo . '.';
    } else {
        if (!in_array($f['estado'], ['ENVIADO', 'EN_REVISION'], true)) {
            $error = 'El estado actual de la ficha no permite realizar esta acción.';
        } else {
            $estado_anterior = $f['estado'];
            $conn->begin_transaction();
            try {
                $obs = $justificacion !== '' ? $justificacion : 'Ficha marcada como CONFORME en revisión documental.';

                $st = $conn->prepare("UPDATE fm_registros SET estado = ?, observacion_general = ?, fecha_actualizacion = NOW() WHERE id = ?");
                $st->bind_param('ssi', $estado_nuevo, $obs, $id_registro);
                $st->execute();
                $st->close();

                $id_eval_null = null;
                $st = $conn->prepare("INSERT INTO fm_historial_estados(id_registro,id_evaluacion,estado_anterior,estado_nuevo,comentario,cambiado_por,fecha_cambio) VALUES(?,?,?,?,?,?,NOW())");
                $st->bind_param('iisssi', $id_registro, $id_eval_null, $estado_anterior, $estado_nuevo, $obs, $id_usuario);
                $st->execute();
                $st->close();

                $desc = 'Revisión documental: ' . $estado_anterior . ' -> ' . $estado_nuevo;
                $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
                $st = $conn->prepare("INSERT INTO fm_auditoria(tabla_afectada,id_registro_afectado,accion,descripcion,id_usuario,ip_usuario,user_agent,fecha_accion) VALUES('fm_registros',?,'CAMBIAR_ESTADO',?,?,?,?,NOW())");
                $st->bind_param('isiss', $id_registro, $desc, $id_usuario, $ip, $ua);
                $st->execute();
                $st->close();

                $conn->commit();
                $_SESSION['mensaje'] = 'Estado actualizado correctamente a ' . $estado_nuevo . '.';
                header('Location: especialista_fichas.php');
                exit();
            } catch (Throwable $ex) {
                $conn->rollback();
                $error = 'No se pudo actualizar el estado: ' . $ex->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <?php
    $page_title = 'Revisión Documental - ' . $f['tipo_ficha'];
    include 'components/head.php';
    ?>
    <style>
        .pdf-frame {
            width: 100%;
            height: 75vh;
            border: 1px solid var(--ugel-border);
            border-radius: var(--ugel-radius-card);
            background: var(--ugel-bg);
        }

        .review-form .form-label {
            font-size: 0.88rem;
            margin-bottom: 4px;
        }

        .review-form textarea {
            resize: vertical;
            min-height: 80px;
        }

        /* Bloqueo de formulario hasta ver PDF */
        .review-lock-wrapper {
            position: relative;
        }

        /* Pulso animado en el botón rojo de PDF */
        .btn-pdf-required {
            animation: pdf-pulse 1.8s ease-in-out infinite;
            box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.5);
            cursor: pointer !important;
        }

        @keyframes pdf-pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.55);
            }

            60% {
                box-shadow: 0 0 0 10px rgba(220, 53, 69, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        .btn-pdf-required.pdf-opened {
            animation: none;
            background-color: #198754 !important;
            border-color: #198754 !important;
        }

        .back-link {
            font-size: 0.9rem;
            color: var(--ugel-green-dark);
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 1.5rem;
        }

        .back-link:hover {
            color: var(--ugel-green);
        }

        .ie-info-header {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 1.5rem;
        }

        /* ── Tarjetas Estilo Fluent ── */
        .section-card {
            background: var(--ugel-surface, #fff);
            border: 1px solid var(--ugel-border, #e2e8f0);
            border-radius: var(--ugel-radius-card, 8px);
            overflow: hidden;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.02);
        }

        .section-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 0.92rem;
            color: #fff;
            background: var(--ugel-green-dark, #137e4b);
        }

        .mobile-header {
            display: none;
        }

        /* Estilos móviles premium (tipo Dialog) */
        @media (max-width: 991.98px) {
            body {
                background: radial-gradient(circle at top, #f8fafc, #e2e8f0) !important;
                min-height: 100vh;
                margin: 0;
                padding: 0 !important;
            }

            .navbar-custom {
                display: none !important;
            }

            main.container-fluid {
                padding: 0 !important;
                max-width: 100% !important;
            }

            .back-link {
                display: none !important;
            }

            .dialog-mobile-container {
                max-width: 600px;
                margin: 0 auto;
                background: #ffffff;
                min-height: 100vh;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
                display: flex;
                flex-direction: column;
            }

            .dialog-mobile-content {
                padding: 16px;
                flex: 1;
            }

            .section-card {
                border-radius: 12px !important;
                border: 1px solid #e2e8f0 !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.01) !important;
                background: #ffffff !important;
                margin-bottom: 1rem;
            }

            .section-card-header {
                font-size: 0.88rem !important;
                padding: 12px 16px !important;
                border-radius: 12px 12px 0 0 !important;
            }

            /* Estilo deshabilitado para evitar colores feos en iOS */
            .review-form select:disabled,
            .review-form textarea:disabled {
                background-color: #f8fafc !important;
                color: #94a3b8 !important;
                border-color: #e2e8f0 !important;
                opacity: 0.85;
                cursor: not-allowed;
                -webkit-text-fill-color: #94a3b8 !important;
            }

            .review-form select,
            .review-form textarea {
                font-size: 0.95rem !important;
                padding: 10px 12px !important;
            }

            .btn-guardar-revision {
                padding: 12px !important;
                font-size: 1rem !important;
                font-weight: 600 !important;
            }

            .mobile-header {
                display: flex;
                align-items: center;
                gap: 12px;
                background: var(--ugel-green-dark, #137e4b);
                color: #ffffff;
                padding: 14px 16px;
                position: sticky;
                top: 0;
                z-index: 1030;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            }

            .mobile-back-btn {
                font-size: 1.4rem;
                color: #ffffff;
                display: flex;
                align-items: center;
                justify-content: center;
                text-decoration: none;
                width: 36px;
                height: 36px;
                border-radius: 50%;
                transition: background 0.2s;
            }

            .mobile-back-btn:active {
                background: rgba(255, 255, 255, 0.15);
            }

            .mobile-header-title {
                font-weight: 700;
                font-size: 1.1rem;
                color: #ffffff;
                flex-grow: 1;
                margin: 0;
            }
        }

        @media (min-width: 992px) {
            .dialog-mobile-container {
                max-width: none;
                margin: 0;
                background: transparent;
                box-shadow: none;
                min-height: initial;
                display: block;
            }

            .dialog-mobile-content {
                padding: 0;
            }
        }
    </style>
</head>

<body>

    <div class="dialog-mobile-container">
        <!-- Header móvil (solo visible en pantallas < 992px) -->
        <div class="mobile-header">
            <a href="especialista_fichas.php" class="mobile-back-btn">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h1 class="mobile-header-title">Revisar Documento</h1>
        </div>

        <!-- ───────────────────── TOPBAR ───────────────────── -->
        <nav class="navbar navbar-expand navbar-light navbar-custom ugel-topbar sticky-top">
            <div class="container-fluid px-3 px-md-4">
                <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard_especialista.php">
                    <i class="bi bi-shield-check ugel-brand-icon"></i>
                    <div class="d-flex flex-column lh-1">
                        <span class="ugel-brand-title">GESTIÓN DE DOCUMENTOS</span>
                        <small class="ugel-brand-subtitle">Sistema IE · Especialista</small>
                    </div>
                </a>

                <div class="ms-auto d-flex align-items-center gap-2">
                    <a href="especialista_fichas.php" class="btn btn-sm d-flex align-items-center gap-1"
                        style="background:rgba(255,255,255,0.12);color:#fff;border:0;border-radius:5px;font-size:0.84rem;">
                        <i class="bi bi-arrow-left"></i> Fichas
                    </a>
                </div>
            </div>
        </nav>

        <div class="dialog-mobile-content">
            <!-- ───────────────────── CONTENIDO ───────────────────── -->
            <main class="container-fluid py-4 px-3 px-md-4" style="max-width: 1400px;">

                <!-- Enlace de regreso -->
                <a href="especialista_fichas.php" class="back-link">
                    <i class="bi bi-arrow-left"></i> Regresar a las tablas
                </a>

                <!-- Alertas -->
                <?php if ($mensaje): ?>
                    <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
                        <i class="bi bi-check-circle-fill"></i> <?= h($mensaje) ?>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger d-flex align-items-center gap-2 mb-4">
                        <i class="bi bi-exclamation-triangle-fill"></i> <?= h($error) ?>
                    </div>
                <?php endif; ?>

                <!-- Título e Información Básica -->
                <div class="mb-4">
                    <h3 class="fw-bold mb-2">Revisión Documental</h3>
                    <div class="ie-info-header">
                        <div class="row g-3">
                            <div class="col-12 col-md-6 col-lg-3">
                                <small class="text-muted d-block text-uppercase fw-bold"
                                    style="font-size: 0.7rem;">Institución Educativa</small>
                                <span class="fw-semibold text-dark"><?= h($f['nombre_ie']) ?></span>
                                <span class="text-muted d-block" style="font-size: 0.82rem;">Cód. Local:
                                    <?= h($f['codigo_local'] ?: $f['codigo_institucion']) ?> &bull;
                                    <?= h($f['distrito']) ?></span>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <small class="text-muted d-block text-uppercase fw-bold"
                                    style="font-size: 0.7rem;">Director / Responsable</small>
                                <span
                                    class="fw-semibold text-dark"><?= h(trim($f['dir_nombres'] . ' ' . $f['dir_ape_pat'] . ' ' . $f['dir_ape_mat'])) ?></span>
                                <span class="text-muted d-block" style="font-size: 0.82rem;">DNI:
                                    <?= h($f['dir_dni']) ?></span>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <small class="text-muted d-block text-uppercase fw-bold"
                                    style="font-size: 0.7rem;">Ficha y Año</small>
                                <span class="fw-semibold text-dark"><?= h($f['tipo_ficha']) ?></span>
                                <span class="text-muted d-block" style="font-size: 0.82rem;">Año: <?= h($f['anio']) ?>
                                    &bull; Cód. Envío: <?= h($f['codigo_envio']) ?></span>
                            </div>
                            <div class="col-12 col-md-6 col-lg-3">
                                <small class="text-muted d-block text-uppercase fw-bold"
                                    style="font-size: 0.7rem;">Estado Actual</small>
                                <span class="badge bg-secondary text-uppercase mt-1"><?= h($f['estado']) ?></span>
                                <span class="text-muted d-block" style="font-size: 0.82rem;">Enviado:
                                    <?= h($f['fecha_envio']) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Área de Trabajo Dividida -->
                <div class="row g-4">
                    <!-- Visualizador de PDF (Lado Izquierdo) -->
                    <div class="col-12 col-lg-8">
                        <div class="section-card mb-0">
                            <div class="section-card-header">
                                <i class="bi bi-file-earmark-pdf"></i> Documento de Ficha Enviada
                            </div>
                            <div class="p-3">
                                <?php if ($f['ruta_pdf']): ?>
                                    <!-- Botón rojo para móviles y tablets -->
                                    <a class="btn btn-danger w-100 d-flex d-lg-none align-items-center justify-content-center gap-2 py-3 mb-3 btn-abrir-pdf btn-pdf-required"
                                        href="<?= h($f['ruta_pdf']) ?>" target="_blank" id="btn-abrir-pdf-mobile">
                                        <i class="bi bi-file-earmark-pdf-fill fs-5"></i>
                                        Abrir PDF — Paso obligatorio antes de evaluar
                                    </a>
                                    <!-- iframe para pantallas de escritorio (>= lg) -->
                                    <div class="d-none d-lg-block">
                                        <iframe class="pdf-frame" src="<?= h($f['ruta_pdf']) ?>"
                                            id="pdf-iframe-desktop"></iframe>
                                    </div>
                                <?php else: ?>
                                    <div class="alert alert-warning text-center my-4">
                                        <i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>
                                        No se ha encontrado el archivo PDF de la ficha.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Formulario de Evaluación (Lado Derecho) -->
                    <div class="col-12 col-lg-4">
                        <div class="section-card">
                            <div class="section-card-header">
                                <i class="bi bi-pencil-square"></i> Resultado de Revisión Documental
                            </div>
                            <div class="p-4">
                                <!-- Wrapper con bloqueo mediante deshabilitado de campos -->
                                <div class="review-lock-wrapper" id="lock-wrap">
                                    <!-- Formulario de Evaluación -->
                                    <form method="POST" class="review-form" id="form-revision">
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id_registro" value="<?= (int) $f['id'] ?>">
                                        <input type="hidden" name="pdf_visto" id="pdf-visto-flag" value="0">

                                        <!-- Mensaje de advertencia para móviles/tablets si no han abierto el PDF -->
                                        <div class="alert alert-warning d-flex align-items-start gap-2 mb-3 d-lg-none"
                                            id="pdf-warning-alert" style="font-size:0.86rem; line-height: 1.4;">
                                            <i class="bi bi-exclamation-triangle-fill fs-5 text-warning mt-0.5"></i>
                                            <div>
                                                <strong>Revisión pendiente:</strong> Debe abrir y revisar el PDF de la
                                                ficha (botón rojo) para habilitar este formulario.
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">Estado a asignar</label>
                                            <select name="estado_nuevo" id="form-estado"
                                                class="form-select estado-select" required disabled>
                                                <option value="">Seleccione un resultado…</option>
                                                <option value="OBSERVADO">🟡 Observado</option>
                                                <option value="RECHAZADO">🔴 Rechazado</option>
                                                <option value="CONFORME">🟢 Conforme</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label fw-semibold">
                                                Justificación / comentario
                                                <span class="text-muted fw-normal" style="font-size:0.82rem;">
                                                    (obligatorio si es Observado o Rechazado)</span>
                                            </label>
                                            <textarea name="justificacion" id="form-justificacion"
                                                class="form-control justificacion" rows="4"
                                                placeholder="Escriba el motivo o comentario…" disabled></textarea>
                                        </div>

                                        <button type="submit" id="form-submit"
                                            class="btn btn-success btn-guardar-revision w-100 py-2" disabled>
                                            <i class="bi bi-save me-1"></i> Guardar evaluación
                                        </button>

                                        <p class="form-text mt-3 mb-0" style="font-size:0.82rem; line-height: 1.4;">
                                            <i class="bi bi-info-circle me-1"></i>
                                            <strong>CONFORME</strong> habilita la evaluación detallada de la ficha.<br>
                                            <strong>OBSERVADO / RECHAZADO</strong> notifica al director para que proceda
                                            a subsanar.
                                        </p>
                                    </form>
                                </div><!-- /review-lock-wrapper -->
                            </div>
                        </div>
                    </div>
                </div>
            </main>

            <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
            <script>
                function desbloquearFormulario() {
                    const flag = document.getElementById('pdf-visto-flag');
                    if (flag) flag.value = '1';

                    const warningAlert = document.getElementById('pdf-warning-alert');
                    if (warningAlert) warningAlert.classList.add('d-none');

                    const formEstado = document.getElementById('form-estado');
                    if (formEstado) formEstado.removeAttribute('disabled');

                    const formJustificacion = document.getElementById('form-justificacion');
                    if (formJustificacion) formJustificacion.removeAttribute('disabled');

                    const formSubmit = document.getElementById('form-submit');
                    if (formSubmit) formSubmit.removeAttribute('disabled');
                }

                // Botón móvil/tablet: desbloquear al hacer clic en "Abrir PDF"
                const btnAbrirPdfMobile = document.getElementById('btn-abrir-pdf-mobile');
                if (btnAbrirPdfMobile) {
                    btnAbrirPdfMobile.addEventListener('click', function () {
                        this.classList.remove('btn-danger');
                        this.classList.add('btn-success', 'pdf-opened');
                        this.innerHTML = '<i class="bi bi-check-circle-fill fs-5"></i> PDF abierto — Ahora puede evaluar';
                        desbloquearFormulario();
                    });
                }

                // Escritorio (>= 992px) o cuando no hay PDF cargado: desbloquear de forma automática
                if (window.innerWidth >= 992 || !btnAbrirPdfMobile) {
                    desbloquearFormulario();
                }

                // Validación antes de enviar: verificar que se haya visto el PDF y justificación
                const formRevision = document.getElementById('form-revision');
                if (formRevision) {
                    formRevision.addEventListener('submit', e => {
                        const flag = document.getElementById('pdf-visto-flag');
                        const estado = document.getElementById('form-estado').value;
                        const txt = document.getElementById('form-justificacion').value.trim();

                        if (btnAbrirPdfMobile && flag && flag.value !== '1') {
                            e.preventDefault();
                            alert('⚠️ Debe abrir y revisar el PDF de la ficha antes de registrar una evaluación.');
                            return;
                        }
                        if ((estado === 'OBSERVADO' || estado === 'RECHAZADO') && !txt) {
                            e.preventDefault();
                            alert('Debe escribir una justificación para marcar la ficha como ' + estado + '.');
                            document.getElementById('form-justificacion').focus();
                        }
                    });
                }
            </script>
        </div><!-- /.dialog-mobile-content -->
    </div><!-- /.dialog-mobile-container -->
</body>

</html>