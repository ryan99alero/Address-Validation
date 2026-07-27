<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Static US ZIP → lat/lng lookup (GeoNames, public domain) so shipment/correction destinations can
 * be plotted on a heatmap without any per-address geocoding or external API calls. Populated by
 * `php artisan zipcentroids:import`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zip_centroids', function (Blueprint $table) {
            $table->id();
            $table->char('zip', 5)->unique();
            $table->decimal('lat', 8, 5);
            $table->decimal('lng', 8, 5);
            $table->string('city', 100)->nullable();
            $table->char('state', 2)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zip_centroids');
    }
};
