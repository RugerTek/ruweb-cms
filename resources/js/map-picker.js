/**
 * Map picker for `map_point` settings (docs/admin/layout.md).
 *
 * Leaflet with OpenStreetMap tiles, no API key. Loaded lazily by admin.js when a
 * [data-map-picker] element is on the page. The two coordinate inputs stay the
 * authoritative form fields: the pin writes into them and typing moves the pin,
 * so validation and dirty-state behave like any other input.
 */
import L from 'leaflet';
import leafletCss from 'leaflet/dist/leaflet.css?inline';

let cssInjected = false;

function injectCss() {
    if (cssInjected) return;
    const style = document.createElement('style');
    style.dataset.leaflet = 'true';
    style.textContent = leafletCss + `
        .leaflet-container { background: #1a1a1a; font: inherit; }
        .leaflet-control-attribution { background: rgba(20,20,20,.8); color: #9ca3af; }
        .leaflet-control-attribution a { color: #38bdf8; }
        .leaflet-bar a { background: #141414; color: #e5e7eb; border-color: rgba(255,255,255,.1); }
        .leaflet-bar a:hover { background: #1f1f1f; }
        .ruweb-map-pin { color: #0ea5e9; filter: drop-shadow(0 2px 3px rgba(0,0,0,.6)); }
    `;
    document.head.appendChild(style);
    cssInjected = true;
}

// Inline SVG pin: avoids shipping Leaflet's PNG marker images through the bundler.
const pinIcon = L.divIcon({
    className: 'ruweb-map-pin',
    iconSize: [28, 40],
    iconAnchor: [14, 40],
    html: '<svg viewBox="0 0 28 40" width="28" height="40" xmlns="http://www.w3.org/2000/svg">'
        + '<path fill="currentColor" d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 40 14 40s14-15.7 14-26.1C28 6.2 21.7 0 14 0z"/>'
        + '<circle cx="14" cy="14" r="5.5" fill="#fff"/></svg>',
});

function parsePoint(latInput, lngInput) {
    const lat = parseFloat(latInput.value);
    const lng = parseFloat(lngInput.value);
    if (Number.isNaN(lat) || Number.isNaN(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return null;
    return [lat, lng];
}

function initPicker(root) {
    const canvas = root.querySelector('[data-map-canvas]');
    const latInput = root.querySelector('[data-map-lat]');
    const lngInput = root.querySelector('[data-map-lng]');
    const clearButton = root.querySelector('[data-map-clear]');
    if (!canvas || !latInput || !lngInput) return;

    const defaultCenter = (root.dataset.defaultCenter || '0,0').split(',').map(Number);
    const defaultZoom = Number(root.dataset.defaultZoom || 2);
    const pinnedZoom = Number(root.dataset.pinnedZoom || 16);

    const initial = parsePoint(latInput, lngInput);
    const map = L.map(canvas, { scrollWheelZoom: false }).setView(initial || defaultCenter, initial ? pinnedZoom : defaultZoom);

    L.tileLayer(root.dataset.tilesUrl, {
        attribution: root.dataset.attribution || '',
        maxZoom: 19,
    }).addTo(map);

    let marker = null;

    const notify = () => {
        latInput.dispatchEvent(new Event('input', { bubbles: true }));
        latInput.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const writeInputs = (latlng) => {
        latInput.value = latlng.lat.toFixed(7);
        lngInput.value = latlng.lng.toFixed(7);
    };

    const placeMarker = (latlng, { pan = false } = {}) => {
        if (marker) {
            marker.setLatLng(latlng);
        } else {
            marker = L.marker(latlng, { icon: pinIcon, draggable: true }).addTo(map);
            marker.on('dragend', () => {
                writeInputs(marker.getLatLng());
                notify();
            });
        }
        if (pan) map.setView(latlng, Math.max(map.getZoom(), pinnedZoom));
    };

    const removeMarker = () => {
        if (marker) {
            marker.remove();
            marker = null;
        }
    };

    if (initial) placeMarker(L.latLng(initial[0], initial[1]));

    map.on('click', (event) => {
        placeMarker(event.latlng);
        writeInputs(event.latlng);
        notify();
    });

    const syncFromInputs = () => {
        const point = parsePoint(latInput, lngInput);
        if (point) {
            placeMarker(L.latLng(point[0], point[1]), { pan: true });
        } else {
            removeMarker();
        }
    };
    latInput.addEventListener('change', syncFromInputs);
    lngInput.addEventListener('change', syncFromInputs);

    clearButton?.addEventListener('click', () => {
        latInput.value = '';
        lngInput.value = '';
        removeMarker();
        map.setView(defaultCenter, defaultZoom);
        notify();
    });

    // The settings tab panel may be display:none when Leaflet measures the
    // container; re-measure whenever the picker becomes visible.
    const observer = new IntersectionObserver((entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
            map.invalidateSize();
            if (marker) map.setView(marker.getLatLng(), map.getZoom());
        }
    });
    observer.observe(canvas);
}

export function initMapPickers(root = document) {
    injectCss();
    root.querySelectorAll('[data-map-picker]').forEach(initPicker);
}
