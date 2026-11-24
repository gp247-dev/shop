<?php

use GP247\Shop\Controllers\ShopPrivacyController;
use GP247\Shop\Controllers\ShopPrivacyRequestController;

// Personal data pages that work without a signed-in account (US-customer-privacy-self-delete,
// US-customer-privacy-guest-request). Every link that changes something is signed and checked
// by the controller; the public form answers the same for every address.
$privacyController = gp247_namespace(ShopPrivacyController::class);
$guestController = gp247_namespace(ShopPrivacyRequestController::class);
$prefixCustomer = config('gp247-config.shop.route.GP247_PREFIX_MEMBER') ?? 'customer';

// The "call off the erasure" link of the email: a page with a button (mail scanners open links).
Route::get($prefixCustomer . '/privacy/cancel/{requestId}', $privacyController . '@cancelLinkPage')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('customer.privacy_cancel_link');
Route::post($prefixCustomer . '/privacy/cancel/{requestId}', $privacyController . '@cancelLink')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('customer.privacy_cancel_link_post');

// Request form of shoppers without an account.
Route::get('privacy-request', $guestController . '@form')->name('shop.privacy_request');
Route::post('privacy-request', $guestController . '@submit')
    ->middleware('throttle:5,1')
    ->name('shop.privacy_request_post');
Route::get('privacy-request/verify/{requestId}', $guestController . '@verifyPage')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_verify');
Route::post('privacy-request/verify/{requestId}', $guestController . '@verify')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_verify_post');
Route::get('privacy-request/{requestId}/status', $guestController . '@status')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_status');
Route::post('privacy-request/{requestId}/download', $guestController . '@download')
    ->where('requestId', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_page_download');
Route::get('privacy-request/download/{export}', $guestController . '@downloadLinkPage')
    ->where('export', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_download');
Route::post('privacy-request/download/{export}', $guestController . '@downloadByLink')
    ->where('export', '[0-9A-Za-z]{26}')
    ->name('shop.privacy_request_download_post');
