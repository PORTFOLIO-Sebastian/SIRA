<?php
/**
 * Vista de Mapa Libre (OpenStreetMap + Leaflet.js)
 * Permite visualizar coordenadas del sistema UGEL sin depender de APIs de pago.
 */
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Coordenadas por defecto (Centro Histórico de Lima, Perú)
$lat_default = -12.04600000;
$lng_default = -77.03050000;

// Obtener parámetros de la URL
$lat = isset($_GET['lat']) ? (float) $_GET['lat'] : $lat_default;
$lng = isset($_GET['lng']) ? (float) $_GET['lng'] : $lng_default;
$precision = isset($_GET['precision']) && $_GET['precision'] !== '' ? (float) $_GET['precision'] : null;
$title = isset($_GET['title']) ? htmlspecialchars($_GET['title'], ENT_QUOTES, 'UTF-8') : 'Ubicación de Referencia';

// Cabecera del sistema
$page_title = 'Visualizador de Mapa - UGEL Chincha';
$extra_head = '
<!-- Optimización de carga DNS para Leaflet y OpenStreetMap -->
<link rel="preconnect" href="https://unpkg.com">
<!-- Leaflet.js para mapas interactivos libres -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin=""/>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<style>
    .map-container {
        height: 420px;
        border-radius: 12px;
        border: 1px solid var(--ugel-green-border);
        box-shadow: 0 8px 30px rgba(0,0,0,0.08);
        overflow: hidden;
        position: relative;
        transition: height 0.3s ease;
    }
    #map {
        height: 100%;
        width: 100%;
        z-index: 1;
    }
    .info-overlay {
        position: absolute;
        top: 15px;
        right: 15px;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(26, 92, 56, 0.15);
        border-radius: 10px;
        padding: 15px;
        width: 320px;
        z-index: 1000;
        box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1), opacity 0.3s ease;
    }
    .info-overlay.minimized {
        transform: translateY(-10px) scale(0.95);
        opacity: 0;
        pointer-events: none;
    }
    .toggle-info-btn {
        position: absolute;
        top: 15px;
        right: 15px;
        z-index: 999;
        display: none;
    }
    .toggle-info-btn.visible {
        display: block;
    }
    .coordinate-badge {
        font-family: monospace;
        background: var(--ugel-green-light);
        color: var(--ugel-green-dark);
        border: 1px solid var(--ugel-green-border);
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 0.9rem;
    }
    .geocoding-spinner {
        display: inline-block;
        width: 1rem;
        height: 1rem;
        border: 2px solid currentColor;
        border-right-color: transparent;
        border-radius: 50%;
        animation: spinner .75s linear infinite;
    }
    .ugel-back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--ugel-green-dark);
        text-decoration: none;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 8px 12px;
        border-radius: 6px;
        background: rgba(26, 92, 56, 0.05);
        transition: background 0.2s, color 0.2s;
    }
    .ugel-back-link:hover {
        background: rgba(26, 92, 56, 0.1);
        color: var(--ugel-green-mid);
    }
    
    @keyframes spinner {
        to { transform: rotate(360deg); }
    }

    /* Ajustes de diseño responsive */
    @media (max-width: 991.98px) {
        .map-container {
            height: 360px;
        }
    }
    
    @media (max-width: 767.98px) {
        .map-container {
            height: 300px;
            border-radius: 12px 12px 0 0;
            border-bottom: 0;
        }
        .info-overlay {
            position: static;
            width: 100%;
            border-radius: 0 0 12px 12px;
            border: 1px solid var(--ugel-green-border);
            border-top: 1px solid #e2e8f0;
            background: #fff;
            backdrop-filter: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            padding: 18px;
            transform: none !important;
            opacity: 1 !important;
            pointer-events: auto !important;
        }
        #closeOverlayBtn {
            display: none !important;
        }
        #showInfoBtn {
            display: none !important;
        }
    }
    
    @media (max-width: 575.98px) {
        .ugel-shell {
            width: 100% !important;
            padding-left: 12px !important;
            padding-right: 12px !important;
        }
        .map-container {
            height: 250px;
        }
    }
</style>
';

include 'components/head.php';
?>

<body class="ugel-page py-4">
    <div class="container-fluid ugel-shell">
        <!-- Botón de retorno / Volver -->
        <div class="mb-3">
            <a href="javascript:window.history.back();" class="ugel-back-link">
                <i class="bi bi-arrow-left"></i> Volver a la lista
            </a>
        </div>

        <!-- Encabezado Principal -->
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
            <div>
                <h4 class="ugel-section-title mb-1">
                    <i class="bi bi-map-fill"></i> Visualizador de Geolocalización
                </h4>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">
                    Ubicación exacta registrada en el momento del envío de la ficha de monitoreo.
                </p>
            </div>
            <div class="ugel-context-badge">
                <i class="bi bi-geo-alt-fill text-success"></i> Coordenadas de Referencia
            </div>
        </div>

        <div class="row g-4">
            <!-- Sección del Mapa -->
            <div class="col-12">
                <div class="map-container">
                    <!-- Botón para volver a mostrar el panel de información si se minimizó (sólo Escritorio) -->
                    <button id="showInfoBtn" class="btn btn-green toggle-info-btn shadow">
                        <i class="bi bi-info-square-fill me-1"></i> Ver Datos
                    </button>

                    <!-- Panel de información flotante en el mapa -->
                    <div id="infoOverlay" class="info-overlay">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h6 class="fw-bold text-success m-0" style="font-size: 1.05rem;">
                                <i class="bi bi-geo-alt-fill text-success"></i> Información Geográfica
                            </h6>
                            <button type="button" class="btn-close btn-close-sm" id="closeOverlayBtn" aria-label="Close"
                                style="font-size: 0.75rem;"></button>
                        </div>
                        <hr class="my-2 text-muted">

                        <div class="mb-3">
                            <span class="d-block text-uppercase text-muted font-semibold"
                                style="font-size: 0.72rem; letter-spacing: 0.5px;">Institución Educativa</span>
                            <strong class="d-block text-dark"
                                style="font-size: 0.95rem;"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>

                        <div class="mb-3">
                            <span class="d-block text-uppercase text-muted font-semibold"
                                style="font-size: 0.72rem; letter-spacing: 0.5px;">Dirección aproximada (OSM Nominatim)</span>
                            <div id="addressText" class="text-dark" style="font-size: 0.88rem;">
                                <span class="geocoding-spinner me-1 text-success"></span> Buscando dirección...
                            </div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <span class="d-block text-uppercase text-muted font-semibold"
                                    style="font-size: 0.72rem; letter-spacing: 0.5px;">Latitud</span>
                                <span class="font-monospace text-dark d-block"
                                    style="font-size: 0.88rem;"><?= number_format($lat, 8, '.', ''); ?></span>
                            </div>
                            <div class="col-6">
                                <span class="d-block text-uppercase text-muted font-semibold"
                                    style="font-size: 0.72rem; letter-spacing: 0.5px;">Longitud</span>
                                <span class="font-monospace text-dark d-block"
                                    style="font-size: 0.88rem;"><?= number_format($lng, 8, '.', ''); ?></span>
                            </div>
                        </div>

                        <?php if ($precision !== null && $precision > 0): ?>
                            <div class="mb-0">
                                <span class="d-block text-uppercase text-muted font-semibold mb-1"
                                    style="font-size: 0.72rem; letter-spacing: 0.5px;">Precisión del GPS</span>
                                <span class="badge bg-success"
                                    style="font-size: 0.8rem; border-radius: 4px; padding: 4px 8px;">
                                    <i class="bi bi-bullseye me-1"></i> ±
                                    <?= number_format($precision, 1, '.', ''); ?> metros
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Div de Leaflet -->
                    <div id="map"></div>
                </div>
            </div>

            <!-- Botones de Acción de Integración / Mapas Externos -->
            <div class="col-12">
                <div class="card shadow-sm p-3">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                        <div>
                            <h6 class="fw-bold mb-1 text-success" style="font-size: 0.95rem;">
                                <i class="bi bi-box-arrow-up-right"></i> Abrir en Aplicaciones Externas
                            </h6>
                            <p class="text-muted mb-0" style="font-size: 0.82rem;">
                                Navega hacia este punto en tiempo real utilizando navegadores GPS especializados.
                            </p>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="https://www.google.com/maps/search/?api=1&query=<?= $lat; ?>,<?= $lng; ?>"
                                target="_blank"
                                class="btn btn-outline-success px-4 py-2 d-flex align-items-center justify-content-center gap-2"
                                style="font-size: 0.88rem; font-weight: 500; border-radius: 6px;">
                                <i class="bi bi-google"></i> Google Maps
                            </a>
                            <a href="https://waze.com/ul?ll=<?= $lat; ?>,<?= $lng; ?>&navigate=yes"
                                target="_blank"
                                class="btn btn-outline-warning px-4 py-2 d-flex align-items-center justify-content-center gap-2 text-dark"
                                style="font-size: 0.88rem; font-weight: 500; border-radius: 6px;">
                                <i class="bi bi-car-front-fill"></i> Waze GPS
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Inicializar el mapa de Leaflet
        const lat = <?= $lat; ?>;
        const lng = <?= $lng; ?>;
        const precision = <?= $precision !== null ? $precision : 'null'; ?>;
        const title = "<?= addslashes($title); ?>";

        // Crear mapa y centrar en las coordenadas
        const map = L.map('map').setView([lat, lng], 16);

        // Cargar capas de OpenStreetMap (libre)
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        // Añadir un marcador con un globo informativo
        const marker = L.marker([lat, lng]).addTo(map);
        marker.bindPopup(`<b>${title}</b><br>Lat: ${lat}<br>Lng: ${lng}`).openPopup();

        // Si hay una precisión del GPS, dibujar un círculo de precisión
        if (precision && precision > 0) {
            L.circle([lat, lng], {
                color: '#1a5c38',
                fillColor: '#2e7d4f',
                fillOpacity: 0.15,
                radius: precision
            }).addTo(map);
        }

        // Lógica para cerrar y abrir la tarjeta de información flotante (sólo aplicable en Escritorio)
        const infoOverlay = document.getElementById('infoOverlay');
        const showInfoBtn = document.getElementById('showInfoBtn');
        const closeOverlayBtn = document.getElementById('closeOverlayBtn');

        if (closeOverlayBtn) {
            closeOverlayBtn.addEventListener('click', () => {
                infoOverlay.classList.add('minimized');
                showInfoBtn.classList.add('visible');
            });
        }

        if (showInfoBtn) {
            showInfoBtn.addEventListener('click', () => {
                infoOverlay.classList.remove('minimized');
                showInfoBtn.classList.remove('visible');
            });
        }

        // Realizar geocodificación inversa con Nominatim (servidor libre de OpenStreetMap)
        const addressText = document.getElementById('addressText');
        const reverseGeocodeUrl = `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`;

        fetch(reverseGeocodeUrl, {
            headers: {
                'User-Agent': 'UGEL-Chincha-System-Geolocalizacion'
            }
        })
            .then(response => response.json())
            .then(data => {
                if (data && data.display_name) {
                    addressText.innerHTML = data.display_name;
                } else {
                    addressText.innerHTML = 'Ubicación no identificada en el mapa.';
                }
            })
            .catch(error => {
                console.error('Error fetching address:', error);
                addressText.innerHTML = 'No se pudo obtener la dirección aproximada.';
            });
    </script>
    <script src="assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
</body>
</html>