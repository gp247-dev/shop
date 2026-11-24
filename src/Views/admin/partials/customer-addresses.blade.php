{{--
    Customer addresses sub-panel (1:N) — shown on the customer edit screen. List
    of addresses (default badge, set-default, edit, delete) + an add/edit form,
    config-driven fields mirroring the legacy address screen. UI text via
    gp247_language_render.

    @aidlc-unit shop-admin
    @aidlc-story US-SADM-004
    @aidlc-adr ADR-005, ADR-006, ADR-007

    Variables: $addresses, $addressForm, $editingAddressId, $defaultAddressId (component state).
--}}
@php($inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100')
{{-- WHY: no top divider/margin — this panel now sits in its own "Addresses" tab
     (see customer-manager.blade.php), so the separator that framed it as an inline
     sub-section below the form is no longer needed. --}}
<div>
    <p class="mb-3 text-sm font-semibold text-gray-700 dark:text-gray-200">{{ gp247_language_render('customer.address_list') }}</p>

    {{-- Address list --}}
    <div class="mb-4 space-y-2">
        @forelse ($addresses as $addr)
            <div class="flex items-start justify-between rounded-lg border border-gray-200 p-3 text-sm dark:border-gray-700 {{ (string) $addr['id'] === (string) $defaultAddressId ? 'bg-blue-50 dark:bg-blue-900/30' : '' }}" wire:key="address-{{ $addr['id'] }}">
                <div class="text-gray-700 dark:text-gray-200">
                    <span class="font-medium">{{ trim(($addr['first_name'] ?? '') . ' ' . ($addr['last_name'] ?? '')) }}</span>
                    @if ((string) $addr['id'] === (string) $defaultAddressId)
                        <x-gp247::badge color="blue">{{ gp247_language_render('customer.default') }}</x-gp247::badge>
                    @endif
                    <div class="text-gray-500 dark:text-gray-400">{{ $addr['address1'] ?? '' }} {{ $addr['phone'] ?? '' }}</div>
                </div>
                <div class="flex items-center gap-1">
                    @if ((string) $addr['id'] !== (string) $defaultAddressId)
                        <x-gp247::button size="sm" variant="ghost" wire:click="setDefaultAddress('{{ $addr['id'] }}')" title="{{ gp247_language_render('customer.set_default') }}"><i class="fas fa-star text-amber-500"></i></x-gp247::button>
                    @endif
                    <x-gp247::button size="sm" variant="ghost" wire:click="editAddress('{{ $addr['id'] }}')"><i class="fas fa-edit"></i></x-gp247::button>
                    <x-gp247::button size="sm" variant="ghost" wire:click="deleteAddress('{{ $addr['id'] }}')" wire:confirm="{{ gp247_language_render('action.delete_confirm') }}"><i class="fas fa-trash-alt text-red-600"></i></x-gp247::button>
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-400">{{ gp247_language_render('admin.no_records') }}</p>
        @endforelse
    </div>

    {{-- Address add / edit form --}}
    <div class="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
            {{ gp247_language_render($editingAddressId ? 'action.edit' : 'customer.address_add') }}
        </p>
        <div class="space-y-3">
            <x-gp247::input :label="gp247_language_render('customer.first_name')" name="addr_first_name"
                wire:model="addressForm.first_name" :error="$errors->first('addressForm.first_name')" />
            @if (gp247_config_admin('customer_lastname'))
                <x-gp247::input :label="gp247_language_render('customer.last_name')" name="addr_last_name"
                    wire:model="addressForm.last_name" :error="$errors->first('addressForm.last_name')" />
            @endif
            {{-- City / district precede address1 to match the canonical address order
                 (city -> district -> address1/2/3). Both default OFF. --}}
            @if (gp247_config_admin('customer_phone'))
                <x-gp247::input :label="gp247_language_render('customer.phone')" name="addr_phone"
                    wire:model="addressForm.phone" :error="$errors->first('addressForm.phone')" />
            @endif
            {{-- Address fields: the shared block in Livewire mode (addressForm.*, codes in addressFormCodes). --}}
            @include('gp247-shop-front::common.shop_address_fields', [
                'addressBlock' => \GP247\Shop\Address\View\AddressBlock::for(
                    ($addressForm['country'] ?? '') !== '' ? $addressForm['country'] : null,
                    \GP247\Shop\Address\AddressForm::CONTEXT_ADDRESS_BOOK,
                    mode: \GP247\Shop\Address\View\AddressBlock::MODE_LIVEWIRE,
                    wire: collect(\GP247\Shop\Address\AddressForm::FIELD_ORDER)->mapWithKeys(fn ($f) => [$f => 'addressForm.' . $f])->all(),
                    codesPath: 'addressFormCodes',
                    storeId: session('adminStoreId') === null ? null : (string) session('adminStoreId'),
                ),
                'testidPrefix' => 'shop-admin-customer-address',
                'wrapperClass' => 'space-y-3',
                'errorPrefix' => 'addressForm.',
                'countries' => $this->countryOptions(),
                'inputClass' => $inputCls,
                'inputErrorClass' => 'border-red-400 dark:border-red-500',
                'labelClass' => 'mb-2 block text-sm font-medium text-gray-700 dark:text-gray-200',
                'errorClass' => 'mt-1 text-sm text-red-600',
                'hintClass' => 'mt-1 text-xs text-gray-500 dark:text-gray-400',
                'buttonClass' => 'rounded-lg border border-blue-600 bg-white px-3 py-2 text-sm text-blue-700 hover:bg-blue-50 dark:bg-gray-800 dark:text-blue-300',
            ])
            <div class="flex items-center gap-2">
                <x-gp247::button type="button" wire:click="saveAddress" wire:loading.attr="disabled">
                    <i class="fas fa-save"></i> {{ gp247_language_render($editingAddressId ? 'admin.update' : 'customer.address_add') }}
                </x-gp247::button>
                @if ($editingAddressId)
                    <x-gp247::button type="button" variant="secondary" wire:click="newAddress">{{ gp247_language_render('admin.cancel') }}</x-gp247::button>
                @endif
            </div>
        </div>
    </div>
</div>
