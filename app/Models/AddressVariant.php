<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class AddressVariant extends Model
{
    protected $fillable = [
        'corrected_address_id',
        'input_address_1',
        'input_address_2',
        'input_city',
        'input_state',
        'input_postal',
        'input_country',
        'input_hash',
        'times_seen',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'times_seen' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    // Relationships

    public function correctedAddress(): BelongsTo
    {
        return $this->belongsTo(CorrectedAddress::class);
    }

    // Static Methods

    /**
     * Compute hash for an input address (for lookup).
     */
    public static function computeHash(
        string $address1,
        ?string $city,
        ?string $state,
        ?string $postal,
        ?string $country = 'us'
    ): string {
        $normalized = implode('|', [
            CorrectedAddress::normalize($address1),
            CorrectedAddress::normalize($city),
            CorrectedAddress::normalize($state),
            CorrectedAddress::normalizePostal($postal),
            CorrectedAddress::normalize($country ?? 'us'),
        ]);

        return hash('sha256', $normalized);
    }

    /**
     * Look up a single address in the local cache.
     */
    public static function lookup(
        string $address1,
        ?string $city,
        ?string $state,
        string $postal,
        ?string $country = 'us'
    ): ?CorrectedAddress {
        $hash = self::computeHash($address1, $city, $state, $postal, $country);
        $normalizedPostal = CorrectedAddress::normalizePostal($postal);

        $variant = self::query()
            ->where('input_postal', $normalizedPostal)
            ->where('input_hash', $hash)
            ->with('correctedAddress')
            ->first();

        if ($variant) {
            // Update usage stats
            $variant->increment('times_seen');
            $variant->update(['last_seen_at' => now()]);
            $variant->correctedAddress?->increment('usage_count');
            $variant->correctedAddress?->update(['last_used_at' => now()]);

            return $variant->correctedAddress;
        }

        return null;
    }

    /**
     * Batch lookup multiple addresses - ONE database query for any batch size.
     *
     * @param  Collection<int, array{address_1: string, city: ?string, state: ?string, postal: string, country: ?string}>  $addresses
     * @return array{hits: array<string, CorrectedAddress>, misses: array<string, array>}
     */
    public static function lookupBatch(Collection $addresses): array
    {
        if ($addresses->isEmpty()) {
            return ['hits' => [], 'misses' => []];
        }

        // Compute hashes and collect postal codes
        $lookups = [];
        $postals = [];
        $hashes = [];

        foreach ($addresses as $key => $addr) {
            $hash = self::computeHash(
                $addr['address_1'],
                $addr['city'] ?? null,
                $addr['state'] ?? null,
                $addr['postal'],
                $addr['country'] ?? 'us'
            );
            $postal = CorrectedAddress::normalizePostal($addr['postal']);

            $lookups[$key] = [
                'hash' => $hash,
                'postal' => $postal,
                'original' => $addr,
            ];

            $postals[] = $postal;
            $hashes[] = $hash;
        }

        // Single query: postal narrows to ~0.003% of records, then hash for exact match
        $matches = self::query()
            ->whereIn('input_postal', array_unique($postals))
            ->whereIn('input_hash', array_unique($hashes))
            ->with('correctedAddress')
            ->get()
            ->keyBy('input_hash');

        // Separate hits from misses
        $hits = [];
        $misses = [];

        foreach ($lookups as $key => $lookup) {
            if ($match = $matches->get($lookup['hash'])) {
                $hits[$key] = $match->correctedAddress;
            } else {
                $misses[$key] = $lookup['original'];
            }
        }

        // Batch update usage stats for hits
        if (! empty($hits)) {
            $hitHashes = array_map(fn ($key) => $lookups[$key]['hash'], array_keys($hits));
            self::whereIn('input_hash', $hitHashes)->increment('times_seen');
            self::whereIn('input_hash', $hitHashes)->update(['last_seen_at' => now()]);
        }

        return ['hits' => $hits, 'misses' => $misses];
    }

    /**
     * Create or update an address variant mapping.
     *
     * @return array{variant: self, created: bool}
     */
    public static function createOrUpdateVariant(
        int $correctedAddressId,
        string $address1,
        ?string $address2,
        ?string $city,
        ?string $state,
        string $postal,
        ?string $country = 'us'
    ): array {
        $hash = self::computeHash($address1, $city, $state, $postal, $country);
        $normalizedPostal = CorrectedAddress::normalizePostal($postal);

        $variant = self::query()
            ->where('input_postal', $normalizedPostal)
            ->where('input_hash', $hash)
            ->first();

        if ($variant) {
            $variant->increment('times_seen');
            $variant->update(['last_seen_at' => now()]);

            return ['variant' => $variant, 'created' => false];
        }

        $variant = self::create([
            'corrected_address_id' => $correctedAddressId,
            'input_address_1' => CorrectedAddress::normalize($address1),
            'input_address_2' => $address2 ? CorrectedAddress::normalize($address2) : null,
            'input_city' => $city ? CorrectedAddress::normalize($city) : null,
            'input_state' => $state ? CorrectedAddress::normalize($state) : null,
            'input_postal' => $normalizedPostal,
            'input_country' => CorrectedAddress::normalize($country ?? 'us'),
            'input_hash' => $hash,
            'times_seen' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Increment variant count on corrected address
        CorrectedAddress::where('id', $correctedAddressId)->increment('variant_count');

        return ['variant' => $variant, 'created' => true];
    }
}
