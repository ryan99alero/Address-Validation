<?php

use App\Services\Carriers\FedExCarrier;
use App\Services\Carriers\SmartyCarrier;
use App\Services\Carriers\UpsCarrier;

return [

    /*
    |--------------------------------------------------------------------------
    | Address-validation drivers
    |--------------------------------------------------------------------------
    |
    | Maps a carrier slug to the class that validates an address against that
    | carrier's API. The engine resolves the driver from here instead of a
    | hard-coded match, so a NEW carrier (USPS, DHL, …) is added by:
    |   1. writing a driver class that implements
    |      App\Services\Carriers\CarrierInterface, and
    |   2. registering its slug here.
    | No change to AddressValidationService is required. The slug must match the
    | Carrier Account's `carriers.slug`.
    |
    */

    'drivers' => [
        'ups' => UpsCarrier::class,
        'fedex' => FedExCarrier::class,
        'smarty' => SmartyCarrier::class,
    ],

];
