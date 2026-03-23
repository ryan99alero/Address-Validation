<?php

namespace App\Models;

use Database\Factories\TransitTimeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransitTime extends Model
{
    /** @use HasFactory<TransitTimeFactory> */
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'transit_times';

    protected $fillable = [
        'address_id',
        'carrier_id',
        'origin_postal_code',
        'origin_country_code',
        'service_type',
        'service_name',
        'carrier_code',
        'transit_days_description',
        'minimum_transit_time',
        'maximum_transit_time',
        'delivery_date',
        'delivery_time',
        'delivery_day_of_week',
        'cutoff_time',
        'distance_value',
        'distance_units',
        'raw_response',
        'calculated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'delivery_time' => 'datetime:H:i:s',
            'cutoff_time' => 'datetime:H:i:s',
            'distance_value' => 'decimal:2',
            'raw_response' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    /**
     * Human-readable service type names.
     */
    public const SERVICE_TYPE_LABELS = [
        'FEDEX_GROUND' => 'FedEx Ground',
        'GROUND_HOME_DELIVERY' => 'FedEx Home Delivery',
        'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
        'FEDEX_2_DAY' => 'FedEx 2Day',
        'FEDEX_2_DAY_AM' => 'FedEx 2Day A.M.',
        'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
        'FIRST_OVERNIGHT' => 'FedEx First Overnight',
        'FEDEX_FREIGHT_ECONOMY' => 'FedEx Freight Economy',
        'FEDEX_FREIGHT_PRIORITY' => 'FedEx Freight Priority',
        'SMART_POST' => 'FedEx SmartPost',
    ];

    /**
     * Get the human-readable service name.
     */
    public function getServiceLabelAttribute(): string
    {
        return $this->service_name ?: (self::SERVICE_TYPE_LABELS[$this->service_type] ?? $this->service_type);
    }

    /**
     * Get formatted delivery date.
     */
    public function getFormattedDeliveryDateAttribute(): ?string
    {
        if (! $this->delivery_date) {
            return null;
        }

        $date = $this->delivery_date->format('M j, Y');
        $dayOfWeek = $this->delivery_day_of_week ?: $this->delivery_date->format('D');

        return "{$dayOfWeek}, {$date}";
    }

    /**
     * Get formatted delivery time.
     */
    public function getFormattedDeliveryTimeAttribute(): ?string
    {
        if (! $this->delivery_time) {
            return null;
        }

        return $this->delivery_time->format('g:i A');
    }

    /**
     * Get formatted transit time range.
     */
    public function getTransitRangeAttribute(): string
    {
        if ($this->transit_days_description) {
            return $this->transit_days_description;
        }

        if ($this->minimum_transit_time && $this->maximum_transit_time) {
            $min = $this->convertTransitTimeToNumber($this->minimum_transit_time);
            $max = $this->convertTransitTimeToNumber($this->maximum_transit_time);

            if ($min === $max) {
                return "{$min} ".($min === 1 ? 'Day' : 'Days');
            }

            return "{$min}-{$max} Days";
        }

        if ($this->minimum_transit_time) {
            $days = $this->convertTransitTimeToNumber($this->minimum_transit_time);

            return "{$days}+ Days";
        }

        // Calculate from delivery_date if available
        if ($this->delivery_date) {
            $days = $this->getCalculatedTransitDays();
            if ($days !== null) {
                return "{$days} ".($days === 1 ? 'Day' : 'Days');
            }
        }

        return 'N/A';
    }

    /**
     * Calculate transit days - returns a string like "3" or "2-3" if min/max differ.
     * Uses carrier-provided transit times, falls back to calculating from delivery_date.
     */
    public function getCalculatedTransitDays(): ?string
    {
        // First priority: Use carrier-provided transit times
        if ($this->minimum_transit_time || $this->maximum_transit_time) {
            $min = $this->minimum_transit_time ? $this->convertTransitTimeToNumber($this->minimum_transit_time) : null;
            $max = $this->maximum_transit_time ? $this->convertTransitTimeToNumber($this->maximum_transit_time) : null;

            // If both exist and are different, show range
            if ($min && $max && $min !== $max) {
                return "{$min}-{$max}";
            }

            // Otherwise return whichever we have
            $days = $max ?? $min;
            if ($days && $days > 0) {
                return (string) $days;
            }
        }

        // Fallback: Calculate from delivery_date
        if (! $this->delivery_date) {
            return null;
        }

        // Use address's requested_ship_date ONLY if already loaded (avoid lazy load)
        // Otherwise use calculated_at date or today
        $shipDate = null;
        if ($this->relationLoaded('address') && $this->address?->requested_ship_date) {
            $shipDate = $this->address->requested_ship_date;
        }
        $shipDate = $shipDate ?? $this->calculated_at?->startOfDay() ?? now()->startOfDay();

        // Calculate business days (excluding weekends)
        $days = 0;
        $current = $shipDate->copy();
        $deliveryDate = $this->delivery_date->startOfDay();

        // Safety limit to prevent infinite loops (max 365 days)
        $iterations = 0;
        $maxIterations = 365;

        while ($current->lt($deliveryDate) && $iterations < $maxIterations) {
            $current->addDay();
            $iterations++;
            // Only count weekdays
            if (! $current->isWeekend()) {
                $days++;
            }
        }

        return (string) max(1, $days);
    }

    /**
     * Get formatted distance.
     */
    public function getFormattedDistanceAttribute(): ?string
    {
        if (! $this->distance_value) {
            return null;
        }

        $units = $this->distance_units === 'KM' ? 'km' : 'mi';

        return number_format($this->distance_value, 1)." {$units}";
    }

    /**
     * Convert FedEx transit time enum to number.
     */
    protected function convertTransitTimeToNumber(string $transitTime): int
    {
        $map = [
            'ONE_DAY' => 1,
            'TWO_DAYS' => 2,
            'THREE_DAYS' => 3,
            'FOUR_DAYS' => 4,
            'FIVE_DAYS' => 5,
            'SIX_DAYS' => 6,
            'SEVEN_DAYS' => 7,
            'EIGHT_DAYS' => 8,
            'NINE_DAYS' => 9,
            'TEN_DAYS' => 10,
            'ELEVEN_DAYS' => 11,
            'TWELVE_DAYS' => 12,
            'THIRTEEN_DAYS' => 13,
            'FOURTEEN_DAYS' => 14,
            'FIFTEEN_DAYS' => 15,
            'SIXTEEN_DAYS' => 16,
            'SEVENTEEN_DAYS' => 17,
            'EIGHTEEN_DAYS' => 18,
            'NINETEEN_DAYS' => 19,
            'TWENTY_DAYS' => 20,
        ];

        return $map[$transitTime] ?? 0;
    }

    // Relationships

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    // Scopes

    public function scopeForAddress($query, int $addressId)
    {
        return $query->where('address_id', $addressId);
    }

    public function scopeByCarrier($query, int $carrierId)
    {
        return $query->where('carrier_id', $carrierId);
    }

    public function scopeGroundServices($query)
    {
        return $query->whereIn('service_type', ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY']);
    }

    public function scopeExpressServices($query)
    {
        return $query->whereNotIn('service_type', ['FEDEX_GROUND', 'GROUND_HOME_DELIVERY', 'SMART_POST']);
    }
}
