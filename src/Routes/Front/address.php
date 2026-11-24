<?php

use GP247\Shop\Address\Http\AddressDataController;
use Illuminate\Support\Facades\Route;

/*
 * Read-only address data (fields of a country, choices of a field, addresses of a
 * postcode) for the address forms that are not Livewire components.
 *
 * WHY no {lang?} prefix / suffix: the URLs are called by script, never shown or indexed;
 * the language comes from the storefront session like every other storefront request.
 * Each route is throttled per IP — a provider may sit on a metered external service.
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-data-endpoints
 */
$addressController = gp247_namespace(AddressDataController::class);
$addressPrefix = trim((string) config('gp247-config.shop.address.route_prefix', 'address'), '/');
$throttleOptions = 'throttle:' . config('gp247-config.shop.address.throttle_options', '60,1');
$throttleLookup = 'throttle:' . config('gp247-config.shop.address.throttle_lookup', '20,1');

Route::get($addressPrefix . '/schema', $addressController . '@schema')
    ->middleware($throttleOptions)
    ->name('address.schema');

Route::get($addressPrefix . '/options', $addressController . '@options')
    ->middleware($throttleOptions)
    ->name('address.options');

Route::get($addressPrefix . '/lookup', $addressController . '@lookup')
    ->middleware($throttleLookup)
    ->name('address.lookup');
