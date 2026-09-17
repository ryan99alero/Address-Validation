<?php

use App\Models\ChargebackPush;

it('labels the test-mode hold as "Outside Test Parameters" and friendly-labels the rest', function () {
    expect(ChargebackPush::statusLabel('skipped_test_mode'))->toBe('Outside Test Parameters')
        ->and(ChargebackPush::statusLabel('pushed'))->toBe('Pushed')
        ->and(ChargebackPush::statusLabel('recorded'))->toBe('Recorded (Audit only)')
        ->and(ChargebackPush::statusLabel('skipped_job_closed'))->toBe('Skipped — Job Closed')
        ->and(ChargebackPush::statusLabel('quarantined'))->toBe('Needs Review')
        ->and(ChargebackPush::statusLabel(null))->toBe('—')
        ->and(ChargebackPush::statusLabel('some_new_status'))->toBe('Some New Status'); // headline fallback
});
