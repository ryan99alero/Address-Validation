<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ $this->heatHeading() }}</x-slot>
        <x-slot name="description">{{ $this->heatDescription() }}</x-slot>

        @assets
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
            <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
        @endassets

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
                        this.map = L.map(this.$refs.map, { scrollWheelZoom: false, worldCopyJump: true }).setView(this.cfg.center, this.cfg.zoom);
                        L.tileLayer(this.cfg.tiles.url, { attribution: this.cfg.tiles.attribution, subdomains: 'abcd', maxZoom: 12 }).addTo(this.map);
                        const overlays = {};
                        this.cfg.layers.forEach((layer) => {
                            if (! layer.points.length) return;
                            const max = layer.max || 1;
                            const heat = L.heatLayer(
                                layer.points.map((p) => [p[0], p[1], p[2] / max]),
                                { radius: 22, blur: 18, maxZoom: 9, minOpacity: 0.28, gradient: layer.gradient }
                            );
                            heat.addTo(this.map);
                            overlays[layer.name] = heat;
                        });
                        if (this.cfg.layers.length > 1) {
                            L.control.layers(null, overlays, { collapsed: false, position: 'topright' }).addTo(this.map);
                        }
                        setTimeout(() => this.map && this.map.invalidateSize(), 200);
                    },
                }"
            >
                <div wire:ignore x-ref="map" style="height: 30rem; width: 100%; border-radius: 0.5rem; overflow: hidden; z-index: 0;"></div>

                <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2 text-xs">
                    @foreach ($this->heatLegends() as $legend)
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-gray-700 dark:text-gray-200">{{ $legend['label'] }}</span>
                            <span class="text-gray-400">fewer</span>
                            <span class="inline-block h-2.5 w-28 rounded-full" style="background: linear-gradient(to right, {{ implode(',', $legend['stops']) }});"></span>
                            <span class="text-gray-400">more</span>
                            <span class="text-gray-500 dark:text-gray-400">(max {{ number_format($legend['max']) }})</span>
                        </div>
                    @endforeach
                </div>

                <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ number_format($meta['matched']) }} plotted
                    @if (($meta['unmatched'] ?? 0) > 0)
                        · {{ number_format($meta['unmatched']) }} unmapped (non-US / bad ZIP)
                    @endif
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
