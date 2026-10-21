<?php
/**
 * Componentes de interfaz reutilizables (UI kit).
 *
 * Todas las funciones DEVUELVEN el HTML como string para poder usarlas con <?= ... ?>.
 * Se apoyan en las clases del sistema de diseño definido en assets/css/fluent.css.
 *
 * Uso:  require_once 'components/ui.php';
 */

if (!function_exists('ui_e')) {
    /** Escapa texto para imprimir de forma segura en HTML. */
    function ui_e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('ui_attrs')) {
    /** Convierte ['data-x' => 'y', 'required' => true] en una cadena de atributos HTML. */
    function ui_attrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $val) {
            if ($val === false || $val === null) {
                continue;
            }
            if ($val === true) {
                $out .= ' ' . $key;
            } else {
                $out .= ' ' . $key . '="' . ui_e((string) $val) . '"';
            }
        }
        return $out;
    }
}

if (!function_exists('ui_alert')) {
    /**
     * Mensaje de alerta (Bootstrap + fluent).
     *
     * @param string $message Texto a mostrar.
     * @param string $type    danger | success | warning | info
     * @param string $icon    Nombre de icono Bootstrap (sin el prefijo "bi bi-").
     */
    function ui_alert(string $message, string $type = 'danger', string $icon = 'exclamation-triangle-fill'): string
    {
        if ($message === '') {
            return '';
        }
        $iconHtml = $icon !== '' ? '<i class="bi bi-' . ui_e($icon) . ' me-2"></i>' : '';
        return '<div class="alert alert-' . ui_e($type) . ' text-center small fw-bold">'
            . $iconHtml . ui_e($message) . '</div>';
    }
}

if (!function_exists('ui_button')) {
    /**
     * Botón o enlace estilizado.
     *
     * @param string $label Texto del botón.
     * @param array  $opts  Opciones:
     *   - href    string  Si se define, renderiza <a> en vez de <button>.
     *   - type    string  Tipo del <button> (submit por defecto).
     *   - variant string  primary | outline | danger | outline-danger (default: primary).
     *   - icon    string  Icono Bootstrap a la derecha (sin prefijo).
     *   - block   bool    Ocupar todo el ancho (default: false).
     *   - class   string  Clases extra.
     *   - attrs   array   Atributos HTML extra.
     */
    function ui_button(string $label, array $opts = []): string
    {
        $variants = [
            'primary' => 'ugel-btn-primary',
            'outline' => 'btn-outline-success',
            'danger' => 'btn-danger',
            'outline-danger' => 'btn-outline-danger',
        ];
        $variant = $variants[$opts['variant'] ?? 'primary'] ?? $variants['primary'];

        $classes = ['btn', $variant];
        if (!empty($opts['block'])) {
            $classes[] = 'ugel-btn-block';
        }
        if (!empty($opts['class'])) {
            $classes[] = $opts['class'];
        }
        $classAttr = implode(' ', $classes);

        $iconHtml = !empty($opts['icon']) ? ' <i class="bi bi-' . ui_e($opts['icon']) . '"></i>' : '';
        $inner = ui_e($label) . $iconHtml;
        $extra = ui_attrs($opts['attrs'] ?? []);

        if (!empty($opts['href'])) {
            return '<a href="' . ui_e($opts['href']) . '" class="' . $classAttr . '"' . $extra . '>' . $inner . '</a>';
        }

        $type = $opts['type'] ?? 'submit';
        return '<button type="' . ui_e($type) . '" class="' . $classAttr . '"' . $extra . '>' . $inner . '</button>';
    }
}

if (!function_exists('ui_field')) {
    /**
     * Campo de formulario con etiqueta e icono (input-group).
     *
     * @param array $opts
     *   - label  string  Texto de la etiqueta.
     *   - name   string  Atributo name del input.
     *   - id     string  Atributo id del input (default: field-<name>).
     *   - type   string  text | password | email ... (default: text).
     *   - icon   string  Icono Bootstrap a la izquierda (sin prefijo).
     *   - toggle bool    Muestra el botón ojo para revelar/ocultar (ideal en password).
     *   - attrs  array   Atributos extra del input (placeholder, required, maxlength, ...).
     */
    function ui_field(array $opts): string
    {
        $label = $opts['label'] ?? '';
        $name = $opts['name'] ?? '';
        $type = $opts['type'] ?? 'text';
        $icon = $opts['icon'] ?? '';
        $toggle = !empty($opts['toggle']);
        $id = $opts['id'] ?? ($name !== '' ? 'field-' . $name : '');
        $extra = ui_attrs($opts['attrs'] ?? []);

        $iconHtml = $icon !== ''
            ? '<span class="input-group-text"><i class="bi bi-' . ui_e($icon) . '"></i></span>'
            : '';

        $idAttr = $id !== '' ? ' id="' . ui_e($id) . '"' : '';
        $forAttr = $id !== '' ? ' for="' . ui_e($id) . '"' : '';

        $toggleHtml = $toggle
            ? '<button type="button" class="ugel-pw-toggle" data-ugel-toggle="' . ui_e($id) . '" aria-label="Mostrar u ocultar contraseña" tabindex="-1">'
                . '<i class="bi bi-eye-slash"></i></button>'
            : '';

        return '<div class="ugel-field">'
            . ($label !== '' ? '<label class="ugel-field__label"' . $forAttr . '>' . ui_e($label) . '</label>' : '')
            . '<div class="input-group' . ($toggle ? ' ugel-field--toggle' : '') . '">'
            . $iconHtml
            . '<input type="' . ui_e($type) . '" name="' . ui_e($name) . '"' . $idAttr . ' class="form-control form-control-lg"' . $extra . '>'
            . $toggleHtml
            . '</div>'
            . '</div>';
    }
}

if (!function_exists('ui_auth_card_open')) {
    /**
     * Abre una tarjeta de autenticación (login / recuperar contraseña, etc.).
     * Cerrar siempre con ui_auth_card_close().
     *
     * @param array $opts
     *   - title    string  Título dentro del encabezado verde.
     *   - subtitle string  Texto secundario bajo el título (opcional).
     *   - logo     string  URL del logo (opcional).
     *   - logo_alt string  Texto alternativo del logo.
     */
    function ui_auth_card_open(array $opts = []): string
    {
        $title = $opts['title'] ?? '';
        $subtitle = $opts['subtitle'] ?? '';
        $logo = $opts['logo'] ?? '';
        $logoAlt = $opts['logo_alt'] ?? 'Logo';

        $logoHtml = $logo !== ''
            ? '<img src="' . ui_e($logo) . '" alt="' . ui_e($logoAlt) . '" height="55" class="ugel-auth-card__logo">'
            : '';

        return '<div class="ugel-auth-card">'
            . '<div class="ugel-auth-card__header">'
            . $logoHtml
            . ($title !== '' ? '<h1 class="ugel-auth-card__title">' . ui_e($title) . '</h1>' : '')
            . ($subtitle !== '' ? '<p class="ugel-auth-card__subtitle">' . ui_e($subtitle) . '</p>' : '')
            . '</div>'
            . '<div class="ugel-auth-card__body">';
    }
}

if (!function_exists('ui_auth_card_close')) {
    function ui_auth_card_close(): string
    {
        return '</div></div>';
    }
}

if (!function_exists('ui_document_card')) {
    /**
     * Renderiza una tarjeta de documento inspirada en el Fluent Design del Dashboard.
     *
     * @param array $row Fila de datos del comunicado/documento.
     * @return string HTML renderizado.
     */
    function ui_document_card(array $row): string
    {
        $id = (int) $row['id'];
        $titulo = ui_e($row['titulo']);
        $tipo = ui_e($row['tipo']);
        $tipo_lower = strtolower($row['tipo'] ?? '');
        $extension = ui_e(strtoupper($row['extension'] ?? ''));
        $ext_lower = strtolower($row['extension'] ?? '');
        $ruta_archivo = ui_e($row['ruta_archivo']);
        $fecha_pub = date("d-m-Y", strtotime($row['fecha_publicacion']));
        $fecha_visto = $row['fecha_visto'] ?? null;
        $estado = $fecha_visto ? 'visto' : 'novisto';

        // Mapear extensión a icono
        $icono_clase = "bi-file-earmark-text text-secondary";
        if (in_array($ext_lower, ['pdf'])) {
            $icono_clase = "bi-file-earmark-pdf-fill text-danger";
        } elseif (in_array($ext_lower, ['xls', 'xlsx'])) {
            $icono_clase = "bi-file-earmark-excel-fill text-success";
        } elseif (in_array($ext_lower, ['doc', 'docx'])) {
            $icono_clase = "bi-file-earmark-word-fill text-primary";
        }

        // Mapear tipo de documento a tema del dashboard (is-orange, is-blue, is-red, is-green)
        $badge_tipo_theme = 'is-green';
        if (strpos($tipo_lower, 'oficio') !== false) {
            $badge_tipo_theme = 'is-orange';
        } elseif (strpos($tipo_lower, 'norma') !== false) {
            $badge_tipo_theme = 'is-blue';
        } elseif (strpos($tipo_lower, 'comunicado') !== false) {
            $badge_tipo_theme = 'is-red';
        }

        // Editor / Publicador info
        $nombres = ui_e($row['nombres'] ?? '');
        $ape_pat = ui_e($row['ape_pat'] ?? '');
        $rol_usuario = isset($row['rol']) ? strtoupper($row['rol']) : 'DESCONOCIDO';
        $sub_tipo_usuario_lista = isset($row['sub_tipo']) && !empty($row['sub_tipo']) ? strtoupper($row['sub_tipo']) : '';
        $rol_mostrar = $sub_tipo_usuario_lista ? $rol_usuario . ' - ' . $sub_tipo_usuario_lista : $rol_usuario;

        // Construir el contenedor del botón de acción
        $action_html = '';
        if ($fecha_visto) {
            $action_html = '
                <a href="' . $ruta_archivo . '" download class="card-ref-btn ugel-module-card__action btn-outline-success btn-sm w-100 m-0">
                    <i class="bi bi-download"></i> Descargar
                </a>';
        } else {
            $action_html = '
                <button class="card-ref-btn ugel-module-card__action btn-green btn-sm w-100 m-0" onclick="verDocumento(' . $id . ', \'' . $ruta_archivo . '\')">
                    <i class="bi bi-eye"></i> Ver para Descargar
                </button>';
        }

        // Timestamp de visto
        $visto_timestamp_html = '';
        if ($fecha_visto) {
            $visto_timestamp_html = '
                <div class="text-center text-muted mt-2 pt-1 border-top" style="font-size: 0.68rem;">
                    <i class="bi bi-check-circle-fill text-success"></i> Visto el ' . date("d/m/Y h:i A", strtotime($fecha_visto)) . '
                </div>';
        }

        return '
        <div class="col-12 col-md-6 col-lg-4 d-flex">
            <div class="card-ref ugel-module-card ' . $badge_tipo_theme . ' w-100 d-flex flex-column justify-content-between p-0 shadow-sm border rounded-4 overflow-hidden document-item-card"
                 data-id="' . $id . '"
                 data-tipo="' . $tipo_lower . '"
                 data-estado="' . $estado . '"
                 data-titulo="' . strtolower($titulo) . '">
                <div>
                    <!-- Encabezado de la Tarjeta (Tipo, Fecha) -->
                    <div class="card-ref-header ugel-module-card__header border-bottom py-3 px-4 d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center gap-2">
                            <div class="header-icon-box ugel-module-card__icon p-0 m-0 d-flex align-items-center justify-content-center" style="width: 36px; height: 36px; border-radius: 8px;">
                                <i class="bi ' . $icono_clase . '" style="font-size: 1.1rem;"></i>
                            </div>
                            <span class="badge ugel-badge ' . $badge_tipo_theme . '" style="font-size: 0.72rem; padding: 4px 8px;">' . $tipo . '</span>
                        </div>
                        <span class="badge bg-light text-muted border px-2 py-1" style="font-size: 0.72rem;">
                            <i class="bi bi-calendar-event me-1"></i>' . $fecha_pub . '
                        </span>
                    </div>

                    <!-- Cuerpo de la Tarjeta (Título, Editor) -->
                    <div class="card-ref-body ugel-module-card__body py-3 px-4" style="padding-left: 1.5rem !important;">
                        <h6 class="fw-bold text-dark mb-3" style="font-size: 0.95rem; line-height: 1.4; min-height: 2.8rem; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">' . $titulo . '</h6>
                        
                        <div class="d-flex align-items-center mt-2 p-2 bg-light rounded-3">
                            <div class="avatar-circle-sm rounded-circle d-flex align-items-center justify-content-center me-2" style="background: var(--ugel-green-light); color: var(--ugel-green-dark); width: 32px; height: 32px; flex-shrink: 0; font-size: 0.85rem;">
                                <i class="bi bi-person-fill"></i>
                            </div>
                            <div style="font-size: 0.78rem; line-height: 1.25;">
                                <div class="fw-bold text-dark">' . $nombres . ' ' . $ape_pat . '</div>
                                <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
                                    <i class="bi bi-person-badge me-1 text-muted" style="font-size: 0.75rem;"></i>' . $rol_mostrar . '
                                </small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pie de la Tarjeta (Botón de Acción, Visto) -->
                <div class="card-footer bg-white border-0 pt-0 pb-3 px-4">
                    <div class="flex-grow-1 action-btn-container" data-id="' . $id . '">
                        ' . $action_html . '
                    </div>
                    <div class="visto-timestamp-container" data-id="' . $id . '">
                        ' . $visto_timestamp_html . '
                    </div>
                </div>
            </div>
        </div>';
    }
}

if (!function_exists('ui_document_row')) {
    /**
     * Renderiza una fila de tabla para un comunicado/documento en el Fluent Design.
     *
     * @param array $row Fila de datos del comunicado/documento.
     * @return string HTML de la fila <tr>.
     */
    function ui_document_row(array $row): string
    {
        $id = (int) $row['id'];
        $titulo = ui_e($row['titulo']);
        $tipo = ui_e($row['tipo']);
        $tipo_lower = strtolower($row['tipo'] ?? '');
        $extension = ui_e(strtoupper($row['extension'] ?? ''));
        $ext_lower = strtolower($row['extension'] ?? '');
        $ruta_archivo = ui_e($row['ruta_archivo']);
        $fecha_pub = date("d-m-Y", strtotime($row['fecha_publicacion']));
        $fecha_visto = $row['fecha_visto'] ?? null;
        $estado = $fecha_visto ? 'visto' : 'novisto';

        // Mapear extensión a icono
        $icono_clase = "bi-file-earmark-text text-secondary";
        if (in_array($ext_lower, ['pdf'])) {
            $icono_clase = "bi-file-earmark-pdf-fill text-danger";
        } elseif (in_array($ext_lower, ['xls', 'xlsx'])) {
            $icono_clase = "bi-file-earmark-excel-fill text-success";
        } elseif (in_array($ext_lower, ['doc', 'docx'])) {
            $icono_clase = "bi-file-earmark-word-fill text-primary";
        }

        // Mapear tipo de documento a tema del dashboard (is-orange, is-blue, is-red, is-green)
        $badge_tipo_theme = 'is-green';
        if (strpos($tipo_lower, 'oficio') !== false) {
            $badge_tipo_theme = 'is-orange';
        } elseif (strpos($tipo_lower, 'norma') !== false) {
            $badge_tipo_theme = 'is-blue';
        } elseif (strpos($tipo_lower, 'comunicado') !== false) {
            $badge_tipo_theme = 'is-red';
        }

        // Editor / Publicador info
        $nombres = ui_e($row['nombres'] ?? '');
        $ape_pat = ui_e($row['ape_pat'] ?? '');
        $rol_usuario = isset($row['rol']) ? strtoupper($row['rol']) : 'DESCONOCIDO';
        $sub_tipo_usuario_lista = isset($row['sub_tipo']) && !empty($row['sub_tipo']) ? strtoupper($row['sub_tipo']) : '';
        $rol_mostrar = $sub_tipo_usuario_lista ? $rol_usuario . ' - ' . $sub_tipo_usuario_lista : $rol_usuario;

        // Construir el botón de acción
        if ($fecha_visto) {
            $action_html = '
                <a href="' . $ruta_archivo . '" download class="btn btn-outline-success btn-sm px-3 shadow-sm">
                    <i class="bi bi-download me-1"></i> Descargar
                </a>';
        } else {
            $action_html = '
                <button class="btn btn-green btn-sm px-3 shadow-sm" onclick="verDocumento(' . $id . ', \'' . $ruta_archivo . '\')">
                    <i class="bi bi-eye me-1"></i> Ver y Descargar
                </button>';
        }

        // Badge de estado visto / no visto
        $estado_badge = '';
        $visto_timestamp_html = '';
        if ($fecha_visto) {
            $estado_badge = '<span class="badge" style="background: var(--ugel-green-light); color: var(--ugel-green-dark); border: 1px solid var(--ugel-green-border); font-size: 0.75rem;"><i class="bi bi-check-circle-fill me-1"></i> Visto</span>';
            $visto_timestamp_html = '
                <div class="text-muted mt-1" style="font-size: 0.68rem;">
                    ' . date("d/m/Y h:i A", strtotime($fecha_visto)) . '
                </div>';
        } else {
            $estado_badge = '<span class="badge" style="background: var(--ugel-yellow-light); color: var(--ugel-yellow); border: 1px solid rgba(184, 134, 11, 0.25); font-size: 0.75rem;"><i class="bi bi-clock-history me-1"></i> Pendiente</span>';
        }

        return '
        <tr class="document-item-row align-middle"
            data-id="' . $id . '"
            data-tipo="' . $tipo_lower . '"
            data-estado="' . $estado . '"
            data-theme="' . $badge_tipo_theme . '"
            data-titulo="' . strtolower($titulo) . '">
            <!-- Tipo de Documento -->
            <td>
                <span class="badge ugel-badge ' . $badge_tipo_theme . ' text-uppercase" style="font-size: 0.72rem; padding: 5px 10px;">
                    ' . $tipo . '
                </span>
            </td>
            <!-- Título -->
            <td>
                <div class="d-flex align-items-center gap-2">
                    <div class="d-flex align-items-center justify-content-center rounded" style="width: 32px; height: 32px; flex-shrink: 0; background: var(--ugel-bg);">
                        <i class="bi ' . $icono_clase . '" style="font-size: 1.2rem;"></i>
                    </div>
                    <div>
                        <strong class="text-dark d-block text-wrap" style="font-size: 0.88rem; max-width: 500px;">' . $titulo . '</strong>
                    </div>
                </div>
            </td>
            <!-- Publicado por -->
            <td>
                <div class="d-flex align-items-center">
                    <div class="avatar-circle-sm rounded-circle d-flex align-items-center justify-content-center me-2" style="background: var(--ugel-green-light); color: var(--ugel-green-dark); width: 28px; height: 28px; flex-shrink: 0; font-size: 0.78rem;">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div style="font-size: 0.78rem; line-height: 1.2;">
                        <div class="fw-bold text-dark">' . $nombres . ' ' . $ape_pat . '</div>
                        <small class="text-muted d-block mt-1" style="font-size: 0.7rem;">
                            <i class="bi bi-person-badge me-1 text-muted" style="font-size: 0.75rem;"></i>' . $rol_mostrar . '
                        </small>
                    </div>
                </div>
            </td>
            <!-- Fecha de publicación -->
            <td class="text-nowrap">
                <span class="text-secondary" style="font-size: 0.82rem;">
                    <i class="bi bi-calendar-event me-1" style="font-size: 0.85rem;"></i>' . $fecha_pub . '
                </span>
            </td>
            <!-- Estado / Visto -->
            <td>
                <div class="estado-container" data-id="' . $id . '">
                    ' . $estado_badge . '
                </div>
                <div class="visto-timestamp-container" data-id="' . $id . '">
                    ' . $visto_timestamp_html . '
                </div>
            </td>
            <!-- Acciones -->
            <td>
                <div class="action-btn-container text-center" data-id="' . $id . '">
                    ' . $action_html . '
                </div>
            </td>
        </tr>';
    }
}

