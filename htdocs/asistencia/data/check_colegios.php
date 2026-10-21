<?php
require_once __DIR__ . '/../helpers.php';
asistencia_require_login();

$json_file = __DIR__ . '/nexus_data.json';
if (!file_exists($json_file)) {
    die('No se encontró el archivo de datos JSON. Por favor ejecuta "py main.py" en tu consola local para generarlo.');
}

$json_content = file_get_contents($json_file);
$data = json_decode($json_content, true);
if (!$data || !isset($data['colegios'])) {
    die('Error al leer o decodificar el archivo JSON de colegios.');
}

$excel_codes = $data['colegios'];

$import_message = '';
if (isset($_GET['importar'])) {
    $ie_map = [];
    $ie_base_to_full = [];
    $ie_res = $conn->query("SELECT id, codigo_local, nivel_modalidad FROM instituciones");
    if ($ie_res) {
        while ($ie_row = $ie_res->fetch_assoc()) {
            $full_code = trim((string)$ie_row['codigo_local']);
            $ie_map[$full_code] = [
                'id' => (int)$ie_row['id'],
                'nivel_modalidad' => trim((string)$ie_row['nivel_modalidad'])
            ];
            
            $parts = explode('_', $full_code);
            $base_code = $parts[0];
            $ie_base_to_full[$base_code][] = $full_code;
        }
    }
    
    $guess_nivel = function($tipo_trabajador, $nombre_ie, $db_nivel) {
        if ($tipo_trabajador === 'ADMINISTRATIVO') {
            return 'Administrativo';
        }
        if (!empty($db_nivel)) {
            $db_nivel_clean = mb_convert_case(trim($db_nivel), MB_CASE_TITLE, "UTF-8");
            if (in_array($db_nivel_clean, ['Inicial', 'Primaria', 'Secundaria', 'CEBA', 'CETPRO', 'EBE', 'Administrativo'])) {
                return $db_nivel_clean;
            }
        }
        $nombre = strtoupper($nombre_ie);
        if (strpos($nombre, 'INICIAL') !== false || strpos($nombre, 'I.E.I') !== false || strpos($nombre, 'IEI') !== false) {
            return 'Inicial';
        }
        if (strpos($nombre, 'CEBA') !== false) {
            return 'CEBA';
        }
        if (strpos($nombre, 'CETPRO') !== false) {
            return 'CETPRO';
        }
        if (strpos($nombre, 'EBE') !== false || strpos($nombre, 'PRITE') !== false || strpos($nombre, 'ESPECIAL') !== false) {
            return 'EBE';
        }
        if (strpos($nombre, 'SECUNDARIA') !== false || strpos($nombre, 'C.N.') !== false || strpos($nombre, 'C.M.') !== false) {
            return 'Secundaria';
        }
        if (strpos($nombre, 'PRIMARIA') !== false || preg_match('/[0-9]/', $nombre)) {
            return 'Primaria';
        }
        return 'Secundaria';
    };

    $personal = $data['personal'];
    $conn->begin_transaction();
    
    $stmt = $conn->prepare("INSERT INTO asistencia_personal (id_ie, dni, apellidos_nombres, cargo, condicion_laboral, jornada_laboral, nivel_educativo, estado)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'activo')
        ON DUPLICATE KEY UPDATE 
            apellidos_nombres = VALUES(apellidos_nombres),
            cargo = VALUES(cargo),
            condicion_laboral = VALUES(condicion_laboral),
            jornada_laboral = VALUES(jornada_laboral),
            nivel_educativo = VALUES(nivel_educativo),
            estado = 'activo'");
            
    if (!$stmt) {
        die("Error al preparar la consulta: " . $conn->error);
    }
    
    $inserted_or_updated = 0;
    $skipped = 0;
    
    foreach ($personal as $p) {
        $code = $p['codigo_local'];
        
        $matched_full_code = null;
        if (isset($ie_map[$code])) {
            $matched_full_code = $code;
        } elseif (isset($ie_base_to_full[$code])) {
            $matched_full_code = $ie_base_to_full[$code][0];
        }
        
        if ($matched_full_code === null) {
            $skipped++;
            continue;
        }
        
        $id_ie = $ie_map[$matched_full_code]['id'];
        $db_nivel = $ie_map[$matched_full_code]['nivel_modalidad'];
        
        $dni = $p['dni'];
        $apellidos_nombres = $p['apellidos_nombres'];
        $cargo = $p['cargo'];
        $condicion_laboral = $p['condicion_laboral'];
        $jornada_laboral = $p['jornada_laboral'];
        $nivel_educativo = $guess_nivel($p['tipo_trabajador'], $p['nombre_ie_excel'], $db_nivel);
        
        $stmt->bind_param("issssss", $id_ie, $dni, $apellidos_nombres, $cargo, $condicion_laboral, $jornada_laboral, $nivel_educativo);
        $stmt->execute();
        $inserted_or_updated++;
    }
    
    $conn->commit();
    $stmt->close();
    
    $import_message = "
    <div class='alert alert-success d-flex align-items-center mb-4' role='alert'>
        <i class='bi bi-check-circle-fill me-2 fs-4'></i>
        <div>
            <strong>¡Carga de Personal completada con éxito!</strong><br>
            Se importaron/actualizaron <strong>{$inserted_or_updated}</strong> trabajadores en la base de datos.<br>
            Se omitieron <strong>{$skipped}</strong> registros (pertenecientes a colegios que no están registrados en el sistema).
        </div>
    </div>";
}

// Get all codes from DB
$db_codes = [];
$db_id_map = [];
$db_base_to_full = [];

$res = $conn->query("SELECT id, codigo_local, nombre_ie FROM instituciones");
if ($res) {
    while ($db_row = $res->fetch_assoc()) {
        $full_code = trim((string)$db_row['codigo_local']);
        $db_codes[$full_code] = trim((string)$db_row['nombre_ie']);
        $db_id_map[$full_code] = (int)$db_row['id'];
        
        $parts = explode('_', $full_code);
        $base_code = $parts[0];
        $db_base_to_full[$base_code][] = $full_code;
    }
}

// Function to check if excel code matches any DB code (exact or base)
$find_matching_db_code = function($excel_code) use ($db_codes, $db_base_to_full) {
    if (isset($db_codes[$excel_code])) {
        return $excel_code;
    }
    if (isset($db_base_to_full[$excel_code])) {
        return $db_base_to_full[$excel_code][0];
    }
    return null;
};

// Compares
$missing_in_db = [];
foreach ($excel_codes as $code => $name) {
    if ($find_matching_db_code($code) === null) {
        $missing_in_db[$code] = $name;
    }
}

$missing_in_excel = [];
foreach ($db_codes as $full_code => $name) {
    $parts = explode('_', $full_code);
    $base_code = $parts[0];
    if (!isset($excel_codes[$full_code]) && !isset($excel_codes[$base_code])) {
        $missing_in_excel[$full_code] = $name;
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Comparación de Instituciones Educativas</title>
    <link href="../../assets/lib/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="../../assets/lib/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f3f2f1; padding: 24px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .card { border: 1px solid #e0e0e0; border-radius: 6px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); margin-bottom: 24px; }
        .card-header { background: #1a5c38; color: #fff; font-weight: 600; padding: 12px 16px; border-bottom: none; }
        .badge-counter { font-size: 0.9rem; padding: 4px 8px; border-radius: 99px; }
        .summary-box { background: #fff; border: 1px solid #e0e0e0; border-radius: 6px; padding: 16px; text-align: center; height: 100%; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .summary-box h3 { font-size: 2.2rem; font-weight: 700; margin: 0; color: #1a5c38; }
        .summary-box p { margin: 4px 0 0; color: #666; font-size: 0.9rem; }
    </style>
</head>
<body>
<div class="container-fluid" style="max-width: 1200px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-dark m-0">Comparación de Instituciones Educativas</h2>
            <p class="text-muted m-0">Verificación de coincidencia entre códigos locales de Excel Nexus y la Base de Datos</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-primary fw-bold" href="?importar=1" onclick="return confirm('¿Estás seguro de importar todo el personal de Nexus? Esta acción insertará nuevos trabajadores y actualizará los existentes.');"><i class="bi bi-cloud-upload-fill"></i> Importar Personal</a>
            <a class="btn btn-outline-success" href="../index.php"><i class="bi bi-arrow-left"></i> Volver al módulo</a>
        </div>
    </div>

    <?php echo $import_message; ?>

    <div class="row g-4 mb-4">
        <div class="col-6 col-md-3">
            <div class="summary-box">
                <h3><?php echo count($excel_codes); ?></h3>
                <p>Colegios en Excel Nexus</p>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="summary-box">
                <h3><?php echo count($db_codes); ?></h3>
                <p>Colegios en Base de Datos</p>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="summary-box">
                <h3 class="<?php echo count($missing_in_db) > 0 ? 'text-danger' : 'text-success'; ?>"><?php echo count($missing_in_db); ?></h3>
                <p>No encontrados en BD</p>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="summary-box">
                <h3><?php echo count($missing_in_excel); ?></h3>
                <p>No reportados en Excel</p>
            </div>
        </div>
    </div>

    <div class="accordion mb-4" id="diagAccordion">
        <div class="accordion-item">
            <h2 class="accordion-header" id="headingDiag">
                <button class="accordion-button collapsed fw-bold text-dark" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDiag" aria-expanded="false" aria-controls="collapseDiag">
                    <i class="bi bi-bug-fill text-danger me-2"></i> Ver Diagnóstico de Mismatch de Códigos (Para Administradores)
                </button>
            </h2>
            <div id="collapseDiag" class="accordion-collapse collapse" aria-labelledby="headingDiag" data-bs-parent="#diagAccordion">
                <div class="accordion-body bg-light">
                    <div class="row">
                        <div class="col-md-6 border-end">
                            <p class="fw-bold mb-1 text-muted">Ejemplos de Códigos en Excel Nexus (Código Local):</p>
                            <ul class="mb-0">
                                <?php 
                                $count = 0;
                                foreach ($excel_codes as $c => $n) {
                                    if ($count >= 5) break;
                                    echo "<li><code>" . htmlspecialchars($c) . "</code> - " . htmlspecialchars($n) . "</li>";
                                    $count++;
                                }
                                ?>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <p class="fw-bold mb-1 text-muted">Ejemplos de Códigos en tu Base de Datos (codigo_local):</p>
                            <ul class="mb-0">
                                <?php 
                                $count = 0;
                                foreach ($db_codes as $c => $n) {
                                    if ($count >= 5) break;
                                    echo "<li><code>" . htmlspecialchars($c) . "</code> - " . htmlspecialchars($n) . "</li>";
                                    $count++;
                                }
                                ?>
                            </ul>
                        </div>
                    </div>
                    <div class="mt-3 small text-muted">
                        <strong>Nota de comparación:</strong> Si los códigos de arriba tienen formatos distintos (por ejemplo, longitud de dígitos, guiones o si en la base de datos se guardó el "Código Modular" en lugar del "Código Local"), esa es la razón por la cual no coinciden.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-danger d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle-fill"></i> Códigos en Excel Nexus que NO existen en la Base de Datos</span>
            <span class="badge bg-white text-danger badge-counter fw-bold"><?php echo count($missing_in_db); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (count($missing_in_db) === 0): ?>
                <div class="p-4 text-success fw-bold text-center"><i class="bi bi-check-circle-fill"></i> ¡Excelente! Todos los colegios de la planilla Excel existen en tu base de datos actual. No habrá problemas de mapeo.</div>
            <?php else: ?>
                <div class="p-3 bg-light border-bottom text-muted" style="font-size: 0.85rem;">
                    <strong>Importante:</strong> Si cargas personal de estos colegios, el script los omitirá a menos que primero agregues estas I.E. en la tabla <code>instituciones</code>.
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>Código Local</th><th>Nombre de la IE (según planilla Excel)</th></tr></thead>
                        <tbody>
                            <?php foreach ($missing_in_db as $code => $name): ?>
                                <tr><td><code class="text-danger fw-bold"><?php echo htmlspecialchars($code); ?></code></td><td><?php echo htmlspecialchars($name); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-secondary d-flex justify-content-between align-items-center">
            <span><i class="bi bi-info-circle-fill"></i> Colegios en la Base de Datos que NO figuran en este Excel Nexus</span>
            <span class="badge bg-white text-secondary badge-counter fw-bold"><?php echo count($missing_in_excel); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (count($missing_in_excel) === 0): ?>
                <div class="p-4 text-success fw-bold text-center"><i class="bi bi-check-circle-fill"></i> Todos los colegios registrados en la base de datos están incluidos en la planilla Excel.</div>
            <?php else: ?>
                <div class="p-3 bg-light border-bottom text-muted" style="font-size: 0.85rem;">
                    Estos colegios están registrados en el sistema, pero no tienen personal reportado en esta planilla de Nexus.
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead class="table-light"><tr><th>Código Local</th><th>Nombre de la IE (según Base de Datos)</th></tr></thead>
                        <tbody>
                            <?php foreach ($missing_in_excel as $code => $name): ?>
                                <tr><td><code><?php echo htmlspecialchars($code); ?></code></td><td><?php echo htmlspecialchars($name); ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="../../assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>
