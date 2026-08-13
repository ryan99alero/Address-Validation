<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $this->heatHeading() }}</x-slot>

        @php($meta = $this->heatMeta())

        @if (($meta['matched'] ?? 0) === 0)
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No mappable destinations for this period.
            </div>
        @else
            <div
                wire:key="heat-{{ $this->heatMapId() }}-{{ $this->heatPeriodKey() }}"
                x-data="{
                    cfg: @js($this->heatMapConfig()),
                    map: null,
                    isFull: false,
                    zoomCtl: null,
                    init() {
                        this.ensureLeaflet(() => this.render());
                    },
                    ensureLeaflet(cb) {
                        if (! document.getElementById('leaflet-css')) {
                            const l = document.createElement('link');
                            l.id = 'leaflet-css'; l.rel = 'stylesheet';
                            l.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                            document.head.appendChild(l);
                        }
                        if (! document.getElementById('leaflet-js') && ! window.L) {
                            const s = document.createElement('script');
                            s.id = 'leaflet-js';
                            s.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                            s.onload = () => {
                                const h = document.createElement('script');
                                h.id = 'leaflet-heat-js';
                                h.src = 'https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js';
                                document.head.appendChild(h);
                            };
                            document.head.appendChild(s);
                        }
                        let tries = 0;
                        const timer = setInterval(() => {
                            if (window.L && window.L.heatLayer) { clearInterval(timer); cb(); }
                            else if (++tries > 200) { clearInterval(timer); }
                        }, 50);
                    },
                    render() {
                        // Tile = a live but NON-interactive preview (so a click anywhere expands it,
                        // instead of panning/zooming). Interaction is turned on only in fullscreen.
                        this.map = L.map(this.$refs.map, {
                            zoomControl: false, dragging: false, scrollWheelZoom: false,
                            doubleClickZoom: false, boxZoom: false, keyboard: false,
                            touchZoom: false, worldCopyJump: true,
                        }).setView(this.cfg.center, this.cfg.zoom);
                        L.tileLayer(this.cfg.tiles.url, { attribution: this.cfg.tiles.attribution, subdomains: 'abcd', maxZoom: 12 }).addTo(this.map);
                        const overlays = {};
                        this.cfg.layers.forEach((layer) => {
                            if (! layer.points.length) return;
                            const max = layer.max || 1;
                            const heat = L.heatLayer(
                                layer.points.map((p) => [p[0], p[1], p[2] / max]),
                                { radius: 22, blur: 18, maxZoom: 9, minOpacity: 0.3, gradient: layer.gradient }
                            );
                            heat.addTo(this.map);
                            overlays[layer.name] = heat;
                        });
                        if (this.cfg.layers.length > 1) {
                            L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(this.map);
                        }
                        const el = this.$refs.map;
                        document.addEventListener('fullscreenchange', () => {
                            this.isFull = (document.fullscreenElement === el);
                            el.style.height = this.isFull ? '100vh' : '18rem';
                            this.setInteractive(this.isFull);
                            setTimeout(() => this.map && this.map.invalidateSize(), 120);
                        });
                        setTimeout(() => this.map && this.map.invalidateSize(), 250);
                    },
                    setInteractive(on) {
                        const m = this.map; if (! m) return;
                        ['dragging','scrollWheelZoom','doubleClickZoom','boxZoom','keyboard','touchZoom'].forEach((h) => {
                            if (m[h]) { on ? m[h].enable() : m[h].disable(); }
                        });
                        if (on && ! this.zoomCtl) { this.zoomCtl = L.control.zoom({ position: 'topleft' }).addTo(m); }
                        else if (! on && this.zoomCtl) { m.removeControl(this.zoomCtl); this.zoomCtl = null; }
                    },
                    openFull() {
                        const el = this.$refs.map;
                        (el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen)?.call(el);
                    },
                }"
            >
                <div class="relative" wire:ignore>
                    <div x-ref="map" style="height: 18rem; width: 100%; border-radius: 0.5rem; overflow: hidden; z-index: 0; background: #e5e7eb;"></div>

                    {{-- The whole preview is the click target; hidden while fullscreen so the map is interactive there. --}}
                    <div
                        x-show="! isFull"
                        @click="openFull()"
                        title="Click to expand"
                        class="absolute inset-0 cursor-pointer rounded-lg ring-1 ring-transparent transition hover:ring-2 hover:ring-primary-500"
                    >
                        <div class="absolute left-2 top-2 rounded-md bg-gray-100 p-1 text-gray-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                            <x-filament::icon icon="heroicon-m-arrows-pointing-out" class="h-4 w-4" />
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
