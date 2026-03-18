<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    protected $fillable = [
        'company_name',
        'contact_name',
        'phone',
        'email',
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'ups_account_number',
        'fedex_account_number',
    ];

    /**
     * Get the singleton company settings instance.
     * Creates one if it doesn't exist.
     */
    public static function instance(): self
    {
        $settings = self::first();

        if (! $settings) {
            $settings = self::create(['country_code' => 'US']);
        }

        return $settings;
    }

    /**
     * Check if company address is configured.
     */
    public function hasAddress(): bool
    {
        return ! empty($this->postal_code) && ! empty($this->city);
    }

    /**
     * Get the formatted full address.
     */
    public function getFormattedAddressAttribute(): string
    {
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->city,
            $this->state,
            $this->postal_code,
            $this->country_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Get address as array for API requests (FedEx format).
     *
     * @return array<string, mixed>
     */
    public function toFedExAddress(): array
    {
        return array_filter([
            'postalCode' => $this->postal_code,
            'city' => $this->city,
            'stateOrProvinceCode' => $this->state,
            'countryCode' => $this->country_code ?? 'US',
        ]);
    }

    /**
     * Get address as array for API requests (UPS format).
     *
     * @return array<string, mixed>
     */
    public function toUpsAddress(): array
    {
        $address = [
            'City' => $this->city,
            'StateProvinceCode' => $this->state,
            'PostalCode' => $this->postal_code,
            'CountryCode' => $this->country_code ?? 'US',
        ];

        if ($this->address_line_1) {
            $address['AddressLine'] = array_filter([
                $this->address_line_1,
                $this->address_line_2,
            ]);
        }

        return array_filter($address);
    }
}
