<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Editable FedEx commitment configuration (single row): the six numeric targets, the optional
 * membership toggles, and the day-count mode. A blank target column falls back to
 * config('fedex_commitments.targets'); the bucket allowlists themselves stay in config (dev domain).
 */
class FedExCommitmentSetting extends Model
{
    protected $table = 'fedex_commitment_settings';

    protected $fillable = [
        'express_avg_daily_packages',
        'express_avg_daily_revenue',
        'express_avg_charge_per_package',
        'ground_avg_daily_packages',
        'ground_avg_daily_revenue',
        'ground_avg_charge_per_package',
        'include_home_delivery',
        'include_first_overnight',
        'include_sameday',
        'day_count_mode',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'express_avg_daily_packages' => 'decimal:2',
            'express_avg_daily_revenue' => 'decimal:2',
            'express_avg_charge_per_package' => 'decimal:2',
            'ground_avg_daily_packages' => 'decimal:2',
            'ground_avg_daily_revenue' => 'decimal:2',
            'ground_avg_charge_per_package' => 'decimal:2',
            'include_home_delivery' => 'boolean',
            'include_first_overnight' => 'boolean',
            'include_sameday' => 'boolean',
        ];
    }

    public static function instance(): self
    {
        return self::first() ?? self::create([]);
    }

    /**
     * Effective targets, per bucket, with UI values overriding the config defaults.
     *
     * @return array{express: array{avg_daily_packages: float, avg_daily_revenue: float, avg_charge_per_package: float}, ground: array{avg_daily_packages: float, avg_daily_revenue: float, avg_charge_per_package: float}}
     */
    public function targets(): array
    {
        $defaults = config('fedex_commitments.targets');

        $out = [];
        foreach (['express', 'ground'] as $bucket) {
            foreach (['avg_daily_packages', 'avg_daily_revenue', 'avg_charge_per_package'] as $metric) {
                $override = $this->{$bucket.'_'.$metric};
                $out[$bucket][$metric] = $override !== null
                    ? (float) $override
                    : (float) $defaults[$bucket][$metric];
            }
        }

        return $out;
    }

    /**
     * The effective exact-match service allowlist per bucket, folding in the toggled optionals.
     *
     * @return array{express: array<int, string>, ground: array<int, string>}
     */
    public function bucketServices(): array
    {
        $buckets = config('fedex_commitments.buckets');
        $optional = config('fedex_commitments.optional');

        $toggle = [
            'ground_home_delivery' => (bool) $this->include_home_delivery,
            'express_first_overnight' => (bool) $this->include_first_overnight,
            'express_sameday' => (bool) $this->include_sameday,
        ];

        foreach ($optional as $key => $opt) {
            if (! empty($toggle[$key])) {
                $buckets[$opt['bucket']] = array_merge($buckets[$opt['bucket']], $opt['services']);
            }
        }

        return [
            'express' => array_values(array_unique($buckets['express'])),
            'ground' => array_values(array_unique($buckets['ground'])),
        ];
    }

    /**
     * Human-readable optional-toggle state, for showing in the widget so the number is never
     * ambiguous (e.g. "Home Delivery: included").
     *
     * @return array<int, string>
     */
    public function optionalStatusLabels(): array
    {
        $optional = config('fedex_commitments.optional');
        $toggle = [
            'ground_home_delivery' => (bool) $this->include_home_delivery,
            'express_first_overnight' => (bool) $this->include_first_overnight,
            'express_sameday' => (bool) $this->include_sameday,
        ];

        $labels = [];
        foreach ($optional as $key => $opt) {
            $labels[] = $opt['label'].': '.(($toggle[$key] ?? false) ? 'included' : 'excluded');
        }

        return $labels;
    }

    public function dayCountMode(): string
    {
        return in_array($this->day_count_mode, ['business', 'calendar', 'active'], true)
            ? $this->day_count_mode
            : (string) config('fedex_commitments.day_count_mode', 'business');
    }
}
