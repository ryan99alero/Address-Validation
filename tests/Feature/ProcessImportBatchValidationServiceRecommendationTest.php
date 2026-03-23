<?php

use App\Jobs\ProcessImportBatchValidation;
use App\Models\Address;
use App\Models\Carrier;
use App\Models\ImportBatch;
use App\Models\TransitTime;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->carrier = Carrier::factory()->create([
        'slug' => 'fedex',
        'name' => 'FedEx',
        'is_active' => true,
    ]);
});

it('recommends the most economical service that meets the required on-site date', function () {
    // Create a batch with an address that has a required on-site date
    $batch = ImportBatch::factory()->create([
        'carrier_id' => $this->carrier->id,
        'status' => ImportBatch::STATUS_PROCESSING,
        'include_transit_times' => true,
        'origin_postal_code' => '90210',
    ]);

    $requiredDate = now()->addDays(5);

    $address = Address::factory()->create([
        'import_batch_id' => $batch->id,
        'required_on_site_date' => $requiredDate,
        'validation_status' => 'valid',
        'validated_by_carrier_id' => $this->carrier->id,
        'validated_at' => now(),
    ]);

    // Create transit times - some can meet the deadline, some cannot
    // Ground: delivers in 7 days (CANNOT meet 5-day deadline)
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_GROUND',
        'service_name' => 'FedEx Ground',
        'delivery_date' => now()->addDays(7),
    ]);

    // Express Saver: delivers in 4 days (CAN meet 5-day deadline)
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_EXPRESS_SAVER',
        'service_name' => 'FedEx Express Saver',
        'delivery_date' => now()->addDays(4),
    ]);

    // 2Day: delivers in 2 days (CAN meet 5-day deadline)
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_2_DAY',
        'service_name' => 'FedEx 2Day',
        'delivery_date' => now()->addDays(2),
    ]);

    // Overnight: delivers tomorrow (CAN meet 5-day deadline)
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'PRIORITY_OVERNIGHT',
        'service_name' => 'FedEx Priority Overnight',
        'delivery_date' => now()->addDay(),
    ]);

    // Run the recommendation logic directly
    $job = new ProcessImportBatchValidation($batch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateServiceRecommendations');
    $method->setAccessible(true);
    $method->invoke($job);

    // Refresh the address
    $address->refresh();

    // Should recommend Express Saver (cheapest that meets the deadline)
    expect($address->recommended_service)->toBe('FedEx Express Saver');
    expect($address->can_meet_required_date)->toBeTrue();
    expect($address->estimated_delivery_date->format('Y-m-d'))->toBe(now()->addDays(4)->format('Y-m-d'));
});

it('recommends the fastest service when no service can meet the deadline', function () {
    $batch = ImportBatch::factory()->create([
        'carrier_id' => $this->carrier->id,
        'status' => ImportBatch::STATUS_PROCESSING,
        'include_transit_times' => true,
        'origin_postal_code' => '90210',
    ]);

    // Required on-site in 1 day - impossible to meet
    $requiredDate = now()->addDay();

    $address = Address::factory()->create([
        'import_batch_id' => $batch->id,
        'required_on_site_date' => $requiredDate,
        'validation_status' => 'valid',
        'validated_by_carrier_id' => $this->carrier->id,
        'validated_at' => now(),
    ]);

    // All services deliver after the required date
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_GROUND',
        'service_name' => 'FedEx Ground',
        'delivery_date' => now()->addDays(7),
    ]);

    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_2_DAY',
        'service_name' => 'FedEx 2Day',
        'delivery_date' => now()->addDays(2),
    ]);

    $job = new ProcessImportBatchValidation($batch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateServiceRecommendations');
    $method->setAccessible(true);
    $method->invoke($job);

    $address->refresh();

    // Should recommend 2Day (fastest available) but mark as cannot meet
    expect($address->recommended_service)->toBe('FedEx 2Day');
    expect($address->can_meet_required_date)->toBeFalse();
});

it('skips addresses without required on-site dates', function () {
    $batch = ImportBatch::factory()->create([
        'carrier_id' => $this->carrier->id,
        'status' => ImportBatch::STATUS_PROCESSING,
        'include_transit_times' => true,
        'origin_postal_code' => '90210',
    ]);

    // Address without required on-site date
    $address = Address::factory()->create([
        'import_batch_id' => $batch->id,
        'required_on_site_date' => null,
        'validation_status' => 'valid',
        'validated_by_carrier_id' => $this->carrier->id,
        'validated_at' => now(),
    ]);

    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_GROUND',
        'delivery_date' => now()->addDays(5),
    ]);

    $job = new ProcessImportBatchValidation($batch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateServiceRecommendations');
    $method->setAccessible(true);
    $method->invoke($job);

    $address->refresh();

    // Should not set any recommendation
    expect($address->recommended_service)->toBeNull();
    expect($address->can_meet_required_date)->toBeNull();
});

it('prefers ground over express when both meet the deadline', function () {
    $batch = ImportBatch::factory()->create([
        'carrier_id' => $this->carrier->id,
        'status' => ImportBatch::STATUS_PROCESSING,
        'include_transit_times' => true,
        'origin_postal_code' => '90210',
    ]);

    // Required on-site in 10 days - both ground and express can meet
    $requiredDate = now()->addDays(10);

    $address = Address::factory()->create([
        'import_batch_id' => $batch->id,
        'required_on_site_date' => $requiredDate,
        'validation_status' => 'valid',
        'validated_by_carrier_id' => $this->carrier->id,
        'validated_at' => now(),
    ]);

    // Ground: delivers in 5 days
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_GROUND',
        'service_name' => 'FedEx Ground',
        'delivery_date' => now()->addDays(5),
    ]);

    // Express Saver: delivers in 3 days
    TransitTime::factory()->create([
        'address_id' => $address->id,
        'carrier_id' => $this->carrier->id,
        'service_type' => 'FEDEX_EXPRESS_SAVER',
        'service_name' => 'FedEx Express Saver',
        'delivery_date' => now()->addDays(3),
    ]);

    $job = new ProcessImportBatchValidation($batch);
    $reflection = new ReflectionClass($job);
    $method = $reflection->getMethod('updateServiceRecommendations');
    $method->setAccessible(true);
    $method->invoke($job);

    $address->refresh();

    // Should recommend Ground (cheaper than Express Saver)
    expect($address->recommended_service)->toBe('FedEx Ground');
    expect($address->can_meet_required_date)->toBeTrue();
});
