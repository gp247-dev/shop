{{--
    Address settings tab (AddressMappingForm), laid out the way an owner thinks about it:
    pick the country (button per country an address plugin serves) → see what is still missing →
    (1) which shop field each component of the plugin fills → (2) what customers will see →
    (3) what happens to an address outside the plugin's list.
    Only classes already in the built admin.css of core (gp247.md §3a).

    @aidlc-unit shop-address
    @aidlc-story US-shop-address-field-mapping
    @aidlc-story US-shop-address-readiness-check
--}}
@php
    // WHY a block, not @php(...): mixing both forms in one view breaks the compiled template.
    $inputCls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100';
    $stepCls = 'mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100';
    $modeIcons = ['list' => 'fa-list', 'lookup' => 'fa-search', 'filled' => 'fa-magic', 'typed' => 'fa-keyboard'];
@endphp
<div class="space-y-4" data-testid="shop-admin-address-mapping">
    <x-gp247::card>
        <div class="space-y-6">
            <div>
                <h3 class="text-base font-semibold text-gray-800 dark:text-gray-100">{{ gp247_language_render('admin.address_mapping.heading') }}</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.address_mapping.lead') }}</p>
            </div>

            @if ($countryTabs === [])
                <p class="text-sm text-gray-600 dark:text-gray-300" data-testid="shop-admin-address-mapping-empty">{{ gp247_language_render('admin.address_mapping.no_plugin') }}</p>
            @else
                <div class="flex flex-wrap gap-2" data-testid="shop-admin-address-mapping-country">
                    @foreach ($countryTabs as $tab)
                        <button type="button" wire:click="selectCountry('{{ $tab['code'] }}')"
                            class="inline-flex items-center gap-2 rounded-lg border px-3 py-2 text-sm {{ $tab['code'] === $country ? 'border-blue-500 bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300' : 'border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-700' }}"
                            data-testid="shop-admin-address-mapping-country-{{ $tab['code'] }}">
                            <i class="fas fa-map-marker-alt"></i>
                            <span class="font-medium">{{ $tab['name'] }}</span>
                            @if ($tab['plugin'] !== '')
                                <span class="text-gray-400">·</span><span>{{ $tab['plugin'] }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                @if (!empty($readiness))
                    @php
                        $pending = array_values(array_filter($readiness, fn ($c) => !$c->passed()));
                        $hasError = collect($pending)->contains(fn ($c) => $c->level === 'error');
                        $box = $pending === []
                            ? 'border-green-200 bg-green-50 dark:border-green-900 dark:bg-green-900/20'
                            : ($hasError ? 'border-red-200 bg-red-50 dark:border-red-900 dark:bg-red-900/20' : 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-900/20');
                    @endphp
                    <div data-testid="shop-admin-address-mapping-readiness" class="rounded-xl border p-4 {{ $box }}">
                        @if ($pending === [])
                            <p class="mb-1 text-sm font-medium text-green-700 dark:text-green-300">{{ gp247_language_render('admin.address_mapping.ready') }}</p>
                        @endif
                        <ul class="space-y-1">
                            @foreach ($readiness as $check)
                                @php
                                    $tone = $check->passed() ? 'text-green-700 dark:text-green-300' : ($check->level === 'error' ? 'text-red-700 dark:text-red-300' : 'text-amber-700 dark:text-amber-300');
                                    $icon = $check->passed() ? 'fa-check-circle' : ($check->level === 'error' ? 'fa-times-circle' : 'fa-exclamation-triangle');
                                @endphp
                                <li data-testid="shop-admin-address-mapping-readiness-{{ $check->key }}" class="flex items-start gap-2 text-sm {{ $tone }}">
                                    <i class="fas {{ $icon }} mt-1 shrink-0"></i>
                                    <span>
                                        {{ $check->message }}
                                        @if (!$check->passed() && $check->fixUrl !== '')
                                            <a href="{{ $check->fixUrl }}" class="font-medium text-blue-600 underline hover:text-blue-700">→ {{ $check->fixLabel }}</a>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- (1) Component of the plugin → shop field --}}
                <div>
                    <p class="{{ $stepCls }}">1. {{ gp247_language_render('admin.address_mapping.step_targets') }}</p>
                    <p class="mb-3 text-sm text-gray-500 dark:text-gray-400">{{ gp247_language_render('admin.address_mapping.step_targets_help') }}</p>
                    <div class="divide-y divide-gray-200 rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                        <div class="grid grid-cols-1 gap-3 bg-gray-50 px-4 py-2 text-xs font-semibold uppercase text-gray-500 sm:grid-cols-2 dark:bg-gray-800 dark:text-gray-400">
                            <span>{{ gp247_language_render('admin.address_mapping.col_plugin_list') }}</span>
                            <span>{{ gp247_language_render('admin.address_mapping.col_shop_field') }}</span>
                        </div>
                        @foreach ($schemaComponents as $key => $component)
                            @php
                                $kindText = match (true) {
                                    $component['kind'] === 'lookup' => gp247_language_render('admin.address_mapping.kind_lookup'),
                                    $component['kind'] === 'derived' => gp247_language_render('admin.address_mapping.kind_derived'),
                                    $component['parent'] !== null => gp247_language_render('admin.address_mapping.kind_child', ['parent' => $component['parent']]),
                                    default => gp247_language_render('admin.address_mapping.kind_list'),
                                };
                                $usedByOthers = collect($targets)->except($key)->filter(fn ($f) => is_string($f) && $f !== '')->values()->all();
                            @endphp
                            <div class="grid grid-cols-1 items-center gap-3 px-4 py-3 sm:grid-cols-2">
                                <div>
                                    <p class="text-sm font-medium text-gray-800 dark:text-gray-100">{{ $component['label'] }}</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $kindText }}</p>
                                </div>
                                <div>
                                    <select class="{{ $inputCls }}" wire:model.live="targets.{{ $key }}" data-testid="shop-admin-address-mapping-{{ $key }}">
                                        <option value="">{{ gp247_language_render('admin.address_mapping.not_used') }}</option>
                                        @foreach ($fields as $field => $info)
                                            @php
                                                $optionText = $info['label']
                                                    . ($info['on'] ? '' : ' (' . gp247_language_render('admin.address_mapping.field_off') . ')')
                                                    . (in_array($field, $usedByOthers, true) ? ' (' . gp247_language_render('admin.address_mapping.in_use') . ')' : '');
                                            @endphp
                                            <option value="{{ $field }}" @disabled(in_array($field, $usedByOthers, true))>{{ $optionText }}</option>
                                        @endforeach
                                    </select>
                                    @if ($component['kind'] === 'derived' && ($targets[$key] ?? '') !== '')
                                        <label class="mt-2 flex items-center gap-2 text-xs text-gray-600 dark:text-gray-300">
                                            <input type="checkbox" class="rounded" wire:model="targetEditable.{{ $key }}" data-testid="shop-admin-address-mapping-{{ $key }}-editable">
                                            {{ gp247_language_render('admin.address_mapping.editable_hint') }}
                                        </label>
                                    @endif
                                    @error('targets.' . $key)<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- (2) What customers will see --}}
                <div>
                    <p class="{{ $stepCls }}">2. {{ gp247_language_render('admin.address_mapping.step_preview') }}</p>
                    <div class="grid grid-cols-1 gap-2 rounded-lg bg-gray-50 p-4 sm:grid-cols-2 md:grid-cols-3 dark:bg-gray-800" data-testid="shop-admin-address-mapping-preview">
                        @foreach ($preview as $field => $item)
                            <div class="flex items-center justify-between gap-2 text-sm" data-testid="shop-admin-address-mapping-preview-{{ $field }}">
                                <span class="text-gray-700 dark:text-gray-200">{{ $item['label'] }}</span>
                                <span class="inline-flex items-center gap-1 text-xs {{ $item['mode'] === 'typed' ? 'text-gray-500 dark:text-gray-400' : 'text-blue-600 dark:text-blue-300' }}">
                                    <i class="fas {{ $modeIcons[$item['mode']] }}"></i>{{ gp247_language_render('admin.address_mapping.mode_' . $item['mode']) }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>

                {{-- (3) An address outside the list --}}
                <div>
                    <p class="{{ $stepCls }}">3. {{ gp247_language_render('admin.address_mapping.step_strict') }}</p>
                    <div class="space-y-3">
                        <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" name="address_mapping_strict" class="mt-1" wire:click="$set('strict', true)" @checked($strict) data-testid="shop-admin-address-mapping-strict-refuse">
                            <span>{{ gp247_language_render('admin.address_mapping.strict_refuse') }}
                                <span class="text-xs text-gray-500 dark:text-gray-400">— {{ gp247_language_render('admin.address_mapping.strict_refuse_hint') }}</span></span>
                        </label>
                        <label class="flex items-start gap-2 text-sm text-gray-700 dark:text-gray-200">
                            <input type="radio" name="address_mapping_strict" class="mt-1" wire:click="$set('strict', false)" @checked(!$strict) data-testid="shop-admin-address-mapping-strict-accept">
                            <span>{{ gp247_language_render('admin.address_mapping.strict_accept') }}
                                <span class="text-xs text-gray-500 dark:text-gray-400">— {{ gp247_language_render('admin.address_mapping.strict_accept_hint') }}</span></span>
                        </label>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-gp247::button type="button" wire:click="save" wire:loading.attr="disabled" data-testid="shop-admin-address-mapping-save">
                        <i class="fas fa-save"></i> {{ gp247_language_render('action.save') }}
                    </x-gp247::button>
                    <x-gp247::button type="button" variant="secondary" wire:click="resetToDefault" data-testid="shop-admin-address-mapping-reset">
                        {{ gp247_language_render('admin.address_mapping.reset') }}
                    </x-gp247::button>
                </div>
            @endif
        </div>
    </x-gp247::card>
</div>
