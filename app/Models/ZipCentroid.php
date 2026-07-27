<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * US ZIP → lat/lng centroid (GeoNames). One row per 5-digit ZIP; keyed by the ZIP string.
 */
class ZipCentroid extends Model
{
    public $timestamps = false;

    protected $fillable = ['zip', 'lat', 'lng', 'city', 'state'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
        ];
    }
}
