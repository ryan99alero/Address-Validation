<?php

use App\Http\Controllers\Api\PaceWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Pace Connect punch-out endpoint. Pace POSTs a JobShipment address payload here;
 * a GET returns a health/status JSON for browser sanity checks. The {token} is the
 * connection's webhook_token.
 */
Route::match(['get', 'post'], '/integrations/pace/{token}', PaceWebhookController::class)
    ->name('integrations.pace.webhook');
