{{--
    Shared address fields of the storefront (registration, profile, address book, checkout).

    Prints the address fields the shop switched on, in the order of AddressForm, as plain inputs —
    so the form works without JavaScript — and lets window.gp247Address() turn the fields an address
    plugin feeds into dependent searchable lists (common/shop_address_combo, also used for the
    country) or a postcode lookup. Override THIS view in a template to change the layout; keep the
    input names, the <field>_code inputs and the Alpine bindings.

    @param \GP247\Shop\Address\View\AddressBlock $addressBlock  Fields, values and Alpine config.
    @param string $testidPrefix  data-testid prefix (e.g. "checkout-address").
    @param string $wrapperClass  Classes of the block root (it holds the Alpine state).
    @param string $fieldClass    Classes of each field wrapper.
    @param array  $wideFields    Fields that span the whole row (fieldWideClass added).
    @param string $fieldWideClass Classes added to a wide field.
    @param string $labelClass    Classes of each label.
    @param array  $countries     Country code => name (when the country field is shown).
    @param string $inputClass    Classes of inputs and selects (storefront: "input").
    @param string $inputErrorClass Classes added to an input with an error (storefront: "input-error").
    @param string $errorClass    Classes of an error message.
    @param string $hintClass     Classes of a hint line.
    @param string $buttonClass   Classes of the postcode lookup button.
    @param string $errorPrefix   Prefix of the error keys (admin Livewire: "form.").

    @aidlc-unit shop-address
    @aidlc-story US-TPL-address-block-contract
    @aidlc-story US-shop-address-dependent-select
    @aidlc-story US-shop-address-postcode-lookup
    @aidlc-adr shop-address_shared-form-block
--}}
@php
    $testidPrefix = $testidPrefix ?? 'shop-address';
    $wrapperClass = $wrapperClass ?? 'space-y-4';
    $fieldClass = $fieldClass ?? '';
    $wideFields = $wideFields ?? [];
    $fieldWideClass = $fieldWideClass ?? 'sm:col-span-2';
    $labelClass = $labelClass ?? 'label';
    $countries = $countries ?? [];
    $inputClass = $inputClass ?? 'input';
    $inputErrorClass = $inputErrorClass ?? 'input-error';
    $errorClass = $errorClass ?? 'text-xs text-red-600 mt-1';
    $hintClass = $hintClass ?? 'text-xs text-ink-500 mt-1';
    $buttonClass = $buttonClass ?? 'btn-outline btn-sm';
    $errorPrefix = $errorPrefix ?? '';
    $isLivewire = $addressBlock->livewire();
@endphp
@if ($isLivewire)
    @assets
        @include('gp247-shop-front::common.shop_address_script')
    @endassets
@else
    @once
        @include('gp247-shop-front::common.shop_address_script')
    @endonce
@endif
<div class="{{ $wrapperClass }}" x-data="gp247Address(@js($addressBlock->config()))" data-testid="{{ $testidPrefix }}-block">
    @foreach ($addressBlock->fields() as $name => $field)
        <div class="{{ $fieldClass }} {{ in_array($name, $wideFields, true) ? $fieldWideClass : '' }}">
            <div wire:ignore>
                <label class="{{ $labelClass }}" for="{{ $testidPrefix }}-{{ $name }}" id="{{ $testidPrefix }}-{{ $name }}-label">
                    <span @if ($name !== 'country') x-text="label('{{ $name }}')" @endif>{{ $field->label }}</span>
                    @if ($field->required)<span class="text-red-500">*</span>@endif
                </label>
                @if ($name === 'country')
                    {{-- WHY the native select stays: it is the field without JavaScript and the value a
                         plain form posts; with Alpine running it is hidden and follows the combobox. --}}
                    <select id="{{ $testidPrefix }}-country" name="country" class="{{ $inputClass }} {{ $errors->has($errorPrefix . 'country') ? $inputErrorClass : '' }}"
                        x-show="false"
                        x-on:change="changeCountry($event.target.value)" data-testid="{{ $testidPrefix }}-country">
                        <option value="">--</option>
                        @php $currentCountry = $isLivewire ? '' : $addressBlock->value('country'); @endphp
                        @foreach ($countries as $countryCode => $countryName)
                            <option value="{{ $countryCode }}" @selected($currentCountry === (string) $countryCode)
                                x-bind:selected="country === @js((string) $countryCode)">{{ $countryName }}</option>
                        @endforeach
                    </select>
                    @include('gp247-shop-front::common.shop_address_combo', [
                        'name' => 'country',
                        'show' => 'true',
                        'choices' => collect($countries)->map(fn ($countryName, $countryCode) => ['code' => (string) $countryCode, 'name' => (string) $countryName])->values()->all(),
                        'errorClasses' => $errors->has($errorPrefix . 'country') ? $inputErrorClass : '',
                    ])
                @else
                    <div class="flex gap-2">
                        <input type="text" id="{{ $testidPrefix }}-{{ $name }}" name="{{ $name }}"
                            class="{{ $inputClass }} w-full {{ $errors->has($errorPrefix . $name) ? $inputErrorClass : '' }}"
                            value="{{ $isLivewire ? '' : $addressBlock->value($name) }}"
                            x-bind:value="values['{{ $name }}']"
                            x-on:input="typed('{{ $name }}', $event.target.value)"
                            x-show="input('{{ $name }}') !== 'select'"
                            x-bind:readonly="!editable('{{ $name }}')"
                            @if ($name === 'postcode') x-on:keydown.enter="if (input('postcode') === 'lookup') { $event.preventDefault(); lookup('postcode'); }" @endif
                            data-testid="{{ $testidPrefix }}-{{ $name }}">
                        @if ($name === 'postcode')
                            <button type="button" class="{{ $buttonClass }} shrink-0" style="display: none"
                                x-show="input('postcode') === 'lookup'" x-on:click="lookup('postcode')"
                                data-testid="{{ $testidPrefix }}-postcode-lookup">{{ gp247_language_render('customer.address_lookup') }}</button>
                        @endif
                    </div>
                    @include('gp247-shop-front::common.shop_address_combo', [
                        'name' => $name,
                        'show' => "input('" . $name . "') === 'select'",
                        'choices' => null,
                        'errorClasses' => $errors->has($errorPrefix . $name) ? $inputErrorClass : '',
                    ])
                    @unless ($isLivewire)
                        <input type="hidden" name="{{ $name }}_code" x-bind:value="codes['{{ $name }}']" value="{{ $addressBlock->code($name) }}">
                    @endunless
                    @if ($name === 'postcode')
                        <p class="{{ $hintClass }}" style="display: none" x-show="lookupState === 'loading'">{{ gp247_language_render('customer.address_lookup_loading') }}</p>
                        <p class="{{ $hintClass }}" style="display: none" x-show="lookupState === 'none'" data-testid="{{ $testidPrefix }}-postcode-none">{{ gp247_language_render('customer.address_lookup_none') }}</p>
                        <p class="{{ $hintClass }}" style="display: none" x-show="lookupState === 'fallback'">{{ gp247_language_render('customer.address_lookup_fallback') }}</p>
                        <div class="mt-1 border rounded-lg bg-white divide-y overflow-y-auto" style="display: none; max-height: 12rem" x-show="suggestions.length > 1"
                            data-testid="{{ $testidPrefix }}-postcode-suggestions">
                            <p class="px-3 py-2 text-xs text-ink-500">{{ gp247_language_render('customer.address_lookup_choose') }}</p>
                            <template x-for="(suggestion, index) in suggestions" x-bind:key="index">
                                <button type="button" class="w-full text-left px-3 py-2 text-sm hover:bg-ink-50" x-on:click="apply(suggestion)" x-text="describe(suggestion)"></button>
                            </template>
                        </div>
                    @endif
                @endif
            </div>
            @if ($errors->has($errorPrefix . $name))<p class="{{ $errorClass }}">{{ $errors->first($errorPrefix . $name) }}</p>@endif
        </div>
    @endforeach
</div>
