<?php
/**
 * Sidebar reutilizable para todo el sistema.
 *
 * Variables opcionales:
 *   $sidebar_role    — 'director' | 'especialista' | 'jefe_agp'  (default: 'director')
 *   $sidebar_active  — clave del ítem activo, ej: 'dashboard', 'fichas', 'proyectos'
 *   $sidebar_label   — texto de la institución/rol mostrado en la cabecera del sidebar
 */
$sidebar_role   = $sidebar_role   ?? 'director';
$sidebar_active = $sidebar_active ?? 'dashboard';
$sidebar_label  = $sidebar_label  ?? '';

/* ── Definición de menús por rol ──────────────────────────── */
$menus = [
  'director' => [
    'label'   => 'Institución',
    'home'    => 'dashboard.php',
    'sections' => [
      'PRINCIPAL' => [
        ['key' => 'dashboard',  'label' => 'Inicio',                 'icon' => 'bi-house',                  'href' => 'dashboard.php'],
      ],
      'MÓDULOS' => [
        ['key' => 'asistencia', 'label' => 'Asistencia Mensual',      'icon' => 'bi-calendar-check',          'href' => 'enviar_documento.php?tipo=Mensual'],
        ['key' => 'proyectos',  'label' => 'Proyectos Innovación',    'icon' => 'bi-lightbulb',               'href' => 'director_proyectos.php'],
        ['key' => 'fichas',     'label' => 'Ficha de Monitoreo',      'icon' => 'bi-clipboard2-check',        'href' => 'ficha_monitoreo_2026.php'],
      ],
      'COMUNICACIÓN' => [
        ['key' => 'comunicados','label' => 'Normas y Comunicados',    'icon' => 'bi-megaphone',               'href' => 'comunicados.php'],
      ],
      'CUENTA' => [
        ['key' => 'perfil',     'label' => 'Mi Perfil',               'icon' => 'bi-person-circle',           'href' => 'panel_usuario.php'],
      ],
    ],
  ],
  'especialista' => [
    'label'   => 'Especialista',
    'home'    => 'dashboard_especialista.php',
    'sections' => [
      'PRINCIPAL' => [
        ['key' => 'dashboard',  'label' => 'Inicio',                  'icon' => 'bi-house',                  'href' => 'dashboard_especialista.php'],
      ],
      'MÓDULOS' => [
        ['key' => 'documentos', 'label' => 'Documentos',              'icon' => 'bi-folder-check',            'href' => 'ver_envios.php'],
        ['key' => 'fichas',     'label' => 'Fichas de Monitoreo',     'icon' => 'bi-clipboard2-data',         'href' => 'especialista_fichas.php'],
        ['key' => 'proyectos',  'label' => 'Proyectos Innovación',    'icon' => 'bi-lightbulb',               'href' => 'especialista_proyectos.php'],
      ],
      'CUENTA' => [
        ['key' => 'perfil',     'label' => 'Mi Perfil',               'icon' => 'bi-person-circle',           'href' => 'panel_usuario.php'],
      ],
    ],
  ],
  'jefe_agp' => [
    'label'   => 'Jefe de AGP',
    'home'    => 'dashboard_jefe_agp.php',
    'sections' => [
      'PRINCIPAL' => [
        ['key' => 'dashboard',  'label' => 'Inicio',                  'icon' => 'bi-house',                  'href' => 'dashboard_jefe_agp.php'],
      ],
      'GESTIÓN' => [
        ['key' => 'fichas',     'label' => 'Fichas de Monitoreo',     'icon' => 'bi-clipboard2-data',         'href' => 'jefe_ficha_menu.php'],
        ['key' => 'especialistas','label'=>'Especialistas',           'icon' => 'bi-person-badge',            'href' => 'jefe_gestionar_especialistas.php'],
        ['key' => 'instituciones','label'=>'Instituciones I.E.',      'icon' => 'bi-building',                'href' => 'jefe_gestionar_ie.php'],
        ['key' => 'proyectos',  'label' => 'Proyectos Innovación',    'icon' => 'bi-lightbulb',               'href' => 'jefe_proyectos.php'],
      ],
      'REPORTES' => [
        ['key' => 'reportes',   'label' => 'Vista de Reportes',       'icon' => 'bi-bar-chart-line',          'href' => 'vista_reportes.php'],
        ['key' => 'autoevaluacion','label'=>'Autoevaluación Dir.',    'icon' => 'bi-file-earmark-check',      'href' => 'jefe_autoevaluacion_directores.php'],
      ],
      'CUENTA' => [
        ['key' => 'perfil',     'label' => 'Mi Perfil',               'icon' => 'bi-person-circle',           'href' => 'panel_usuario.php'],
      ],
    ],
  ],
];

$menu = $menus[$sidebar_role] ?? $menus['director'];
?>
<!-- ── Sidebar backdrop (mobile) ──────────────────────── -->
<div class="ugel-sidebar-backdrop" id="ugelSidebarBackdrop" onclick="ugelCloseSidebar()"></div>

<!-- ── Sidebar panel ──────────────────────────────────── -->
<aside class="ugel-sidebar" id="ugelSidebar" role="navigation" aria-label="Navegación principal">

  <!-- Sidebar: cabecera con institución/rol -->
  <div class="ugel-sidebar__header">
    <div class="ugel-sidebar__school-label">
      <?php echo htmlspecialchars($menu['label'], ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php if ($sidebar_label): ?>
    <div class="ugel-sidebar__school-name">
      <?php echo htmlspecialchars($sidebar_label, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar: navegación -->
  <nav class="ugel-sidebar__nav">
    <?php foreach ($menu['sections'] as $section_name => $items): ?>
      <div class="ugel-sidebar__section-label"><?php echo $section_name; ?></div>
      <?php foreach ($items as $item): ?>
        <?php $is_active = ($sidebar_active === $item['key']); ?>
        <a href="<?php echo htmlspecialchars($item['href'], ENT_QUOTES, 'UTF-8'); ?>"
           class="ugel-sidebar__item<?php echo $is_active ? ' is-active' : ''; ?>"
           <?php if ($is_active): ?>aria-current="page"<?php endif; ?>>
          <i class="bi <?php echo htmlspecialchars($item['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
          <span class="ugel-sidebar__item-text"><?php echo htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>

  <!-- Sidebar: pie con cerrar sesión -->
  <div class="ugel-sidebar__footer">
    <a href="logout.php" class="ugel-sidebar__item" style="color: #c62828;">
      <i class="bi bi-box-arrow-right" style="color: #c62828;"></i>
      <span class="ugel-sidebar__item-text">Cerrar Sesión</span>
    </a>
  </div>

</aside>

<script>
function ugelOpenSidebar() {
  var sb = document.getElementById('ugelSidebar');
  var bd = document.getElementById('ugelSidebarBackdrop');
  if (sb && bd) {
    sb.classList.add('is-open');
    bd.classList.add('is-open');
    document.body.style.overflow = 'hidden';
  }
}
function ugelCloseSidebar() {
  var sb = document.getElementById('ugelSidebar');
  var bd = document.getElementById('ugelSidebarBackdrop');
  if (sb && bd) {
    sb.classList.remove('is-open');
    bd.classList.remove('is-open');
    document.body.style.overflow = '';
  }
}
</script>
