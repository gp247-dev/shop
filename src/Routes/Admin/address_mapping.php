<?php
use GP247\Shop\Admin\Livewire\AddressMappingForm;
use Illuminate\Support\Facades\Route;

// Address settings (shop-address): also a tab of the Configuration hub; this page is the
// target of an address plugin's settings button (?country=VN). Same permission as shop_config.
Route::group(['prefix' => 'address_mapping'], function () {
    Route::get('/', gp247_namespace(AddressMappingForm::class))->name('admin_address_mapping.index');
});
