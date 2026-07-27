<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $this->heatHeading() }}</x-slot>
        <x-slot name="description">{{ $this->heatDescription() }}</x-slot>

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
                        this.map = L.map(this.$refs.map, { scrollWheelZoom: false, worldCopyJump: true }).setView(this.cfg.center, this.cfg.zoom);
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
                        setTimeout(() => this.map && this.map.invalidateSize(), 250);
                    },
                }"
            >
                <div wire:ignore x-ref="map" style="height: 30rem; width: 100%; border-radius: 0.5rem; overflow: hidden; z-index: 0; background: #e5e7eb;"></div>

                <div class="mt-3 space-y-1.5 text-xs">
                    @foreach ($this->heatLegends() as $legend)
                        <div class="flex items-center gap-3">
                            <span class="w-16 shrink-0 font-semibold text-gray-700 dark:text-gray-200">{{ $legend['label'] }}</span>
                            <span class="inline-block h-2.5 w-24 shrink-0 rounded-full" style="background: linear-gradient(to right, {{ implode(',', $legend['stops']) }});"></span>
                            <span class="text-gray-600 dark:text-gray-300">
                                <span class="font-medium">{{ number_format($legend['plotted']) }}</span> {{ $this->heatUnit() }}
                                across <span class="font-medium">{{ number_format($legend['zips']) }}</span> ZIPs
                                · busiest ZIP {{ number_format($legend['max']) }}
                                @if ($legend['unmapped'] > 0)
                                    · {{ number_format($legend['unmapped']) }} unmapped
                                @endif
                            </span>
                        </div>
                    @endforeach
                    <div class="pt-0.5 text-gray-400 dark:text-gray-500">
                        Color shows {{ $this->heatUnit() }} density per ZIP — light = fewer, dark = the busiest ZIP. Toggle a carrier top-right. "Unmapped" = non-US or bad ZIP.
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
