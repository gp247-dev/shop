{{--
    Searchable list (combobox) of the shared address block: a text input that filters the choices
    without accents, and a listbox. The value is taken only from a picked choice. Used for the
    country and for every field an address plugin feeds a list to; state and actions live in
    window.gp247Address() (shop_address_script), styling in its .gp247-addr-* rules.

    @param string $name         Field name ("country", "city", "ward"…).
    @param string $testidPrefix data-testid prefix of the block.
    @param string $inputClass   Classes of the text input.
    @param string $errorClasses Classes added when the field has an error ('' otherwise).
    @param string $show         Alpine expression deciding when the combobox replaces the plain field.
    @param array|null $choices  Fixed choices [['code' => …, 'name' => …]] (the country); null = loaded by the block.

    @aidlc-unit shop-address
    @aidlc-story US-shop-address-dependent-select
    @aidlc-story US-TPL-address-block-contract
    @aidlc-adr shop-address_shared-form-block
--}}
<div class="gp247-addr-combo" style="display: none"
    x-show="{{ $show }}"
    @if ($choices !== null) x-init="initList('{{ $name }}', @js($choices))" @endif
    x-on:click.outside="closeList('{{ $name }}')"
    data-testid="{{ $testidPrefix }}-{{ $name }}-select">
    <input type="text" class="{{ $inputClass }} w-full gp247-addr-search {{ $errorClasses }}"
        role="combobox" aria-autocomplete="list" autocomplete="off"
        aria-controls="{{ $testidPrefix }}-{{ $name }}-list"
        aria-labelledby="{{ $testidPrefix }}-{{ $name }}-label"
        x-bind:aria-expanded="openList === '{{ $name }}' ? 'true' : 'false'"
        x-bind:aria-activedescendant="openList === '{{ $name }}' && filtered('{{ $name }}').length ? '{{ $testidPrefix }}-{{ $name }}-opt-' + (active['{{ $name }}'] || 0) : null"
        placeholder="{{ gp247_language_render('customer.address_search') }}"
        x-bind:value="openList === '{{ $name }}' ? search['{{ $name }}'] : shown('{{ $name }}')"
        x-bind:disabled="comboDisabled('{{ $name }}')"
        x-on:focus="showList('{{ $name }}')"
        x-on:click="showList('{{ $name }}')"
        x-on:input="searchList('{{ $name }}', $event.target.value)"
        x-on:keydown.down.prevent="moveList('{{ $name }}', 1)"
        x-on:keydown.up.prevent="moveList('{{ $name }}', -1)"
        x-on:keydown.enter="if (openList === '{{ $name }}') { $event.preventDefault(); chooseActive('{{ $name }}'); }"
        x-on:keydown.escape="closeList('{{ $name }}')"
        x-on:keydown.tab="closeList('{{ $name }}')"
        x-on:blur="closeList('{{ $name }}')"
        data-testid="{{ $testidPrefix }}-{{ $name }}-search">
    <ul class="gp247-addr-list" role="listbox" id="{{ $testidPrefix }}-{{ $name }}-list" style="display: none"
        x-ref="list_{{ $name }}" x-show="openList === '{{ $name }}'" x-on:mousedown.prevent>
        <template x-for="(option, index) in filtered('{{ $name }}')" x-bind:key="option.code">
            <li role="option" class="gp247-addr-option"
                x-bind:id="'{{ $testidPrefix }}-{{ $name }}-opt-' + index"
                x-bind:aria-selected="option.code === selectedCode('{{ $name }}') ? 'true' : 'false'"
                x-bind:class="{ 'is-active': index === (active['{{ $name }}'] || 0) }"
                x-on:mousedown.prevent="chooseOption('{{ $name }}', option)"
                x-on:mousemove="active['{{ $name }}'] = index"
                x-text="option.name"
                data-testid="{{ $testidPrefix }}-{{ $name }}-option"></li>
        </template>
        <li class="gp247-addr-empty" x-show="filtered('{{ $name }}').length === 0">{{ gp247_language_render('customer.address_no_match') }}</li>
    </ul>
</div>
