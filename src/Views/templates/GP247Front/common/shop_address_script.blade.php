{{--
    Alpine factory of the shared address block (storefront and admin, Livewire and plain forms).

    window.gp247Address(cfg) drives the address fields printed by common/shop_address_fields
    (storefront and admin — the admin includes the package default of that view): a field fed by an address
    plugin becomes a select whose choices depend on its parent field, a postcode field can fill the
    others, and every field falls back to typing when the plugin cannot answer. Data comes from the
    read-only endpoints address/schema|options|lookup. The server checks everything again on save
    (AddressService), so nothing here is trusted.

    Livewire mode (cfg.mode = 'livewire'): values and codes are read from / written to the component
    through $wire (paths in cfg.wire / cfg.codesPath); the inputs sit in wire:ignore and server
    changes come back through $wire.$watch. Form mode: inputs carry name / <field>_code.

    Defined once per page; a second inclusion only redefines the same function.

    @aidlc-unit shop-address
    @aidlc-story US-shop-address-dependent-select
    @aidlc-story US-shop-address-postcode-lookup
    @aidlc-story US-shop-address-free-text-fallback
    A field fed by a list is a combobox (text input + listbox): typing filters the choices without
    accents or case ("ho chi minh" finds "Thành phố Hồ Chí Minh"); only a picked choice sets the value.
    Its look lives in the .gp247-addr-* rules below (no Tailwind class of its own, so no rebuild of
    a template's CSS); a template restyles them from its own stylesheet.

    @aidlc-adr shop-address_shared-form-block
--}}
<style>
    .gp247-addr-combo { position: relative; }
    .gp247-addr-combo .gp247-addr-search {
        padding-right: 2rem;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' fill='none'%3E%3Cpath d='M2 4l4 4 4-4' stroke='%2394a3b8' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right .65rem center; background-size: 12px 12px;
    }
    .gp247-addr-combo .gp247-addr-search:disabled { opacity: .6; cursor: not-allowed; }
    .gp247-addr-list {
        position: absolute; z-index: 60; left: 0; right: 0; top: 100%;
        margin: 4px 0 0; padding: .25rem 0; list-style: none;
        max-height: 16rem; overflow-y: auto;
        background: #fff; border: 1px solid rgb(226 232 240); border-radius: .5rem;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .09);
    }
    .gp247-addr-option { padding: .45rem .75rem; font-size: .875rem; line-height: 1.25rem; color: rgb(30 41 59); cursor: pointer; }
    .gp247-addr-option.is-active { background: rgb(241 245 249); }
    .gp247-addr-option[aria-selected="true"] { font-weight: 600; }
    .gp247-addr-empty { padding: .6rem .75rem; font-size: .8rem; color: rgb(148 163 184); text-align: center; }
    .dark .gp247-addr-list { background: rgb(31 41 55); border-color: rgb(55 65 81); box-shadow: 0 8px 24px rgba(0, 0, 0, .3); }
    .dark .gp247-addr-option { color: rgb(226 232 240); }
    .dark .gp247-addr-option.is-active { background: rgb(55 65 81); }
</style>
<script>
(function () {
window.gp247Address = function (cfg) {
    return {
        cfg: cfg,
        country: cfg.country || '',
        defs: {},
        values: {},
        codes: {},
        options: {},
        loading: {},
        fallback: {},
        suggestions: [],
        lookupState: '',
        // Combobox state: the field whose list is open, the typed filter and the highlighted row per field.
        openList: '',
        search: {},
        active: {},
        // Request counters: an answer is used only while it is the latest of its kind, so a
        // slow reply for a previous choice never overwrites the current one.
        seq: {},

        init() {
            (cfg.fields || []).forEach((f) => { this.defs[f.name] = f; });
            Object.keys(this.defs).forEach((name) => {
                this.values[name] = this.read(name);
                this.codes[name] = this.readCode(name);
                this.options[name] = [];
                this.search[name] = '';
                this.active[name] = 0;
            });
            if (cfg.countryField) {
                this.country = this.read('country') || this.country;
            }
            this.loadMapped();
            if (cfg.mode === 'alpine' && cfg.model) {
                // WHY: the page fills its own state (e.g. picking a customer on the create-order
                // screen); follow it like the Livewire mode follows the server.
                [...Object.keys(this.defs), 'country'].forEach((name) => {
                    this.$watch(cfg.model + '.' + name, (value) => {
                        value = value == null ? '' : String(value);
                        if (name === 'country') {
                            if (cfg.countryField && value !== this.country) { this.changeCountry(value, false); }
                            return;
                        }
                        if (value === this.values[name]) { return; }
                        this.values[name] = value;
                        this.codes[name] = '';
                        this.matchByName(name);
                    });
                });
            }
            if (cfg.mode === 'livewire' && this.$wire) {
                Object.keys(this.defs).forEach((name) => {
                    const path = (cfg.wire || {})[name];
                    if (!path) { return; }
                    this.$wire.$watch(path, (value) => {
                        value = value == null ? '' : String(value);
                        if (value === this.values[name]) { return; }
                        this.values[name] = value;
                        this.codes[name] = this.readCode(name);
                        this.matchByName(name);
                    });
                });
                if (cfg.countryField && (cfg.wire || {}).country) {
                    this.$wire.$watch(cfg.wire.country, (value) => {
                        value = value == null ? '' : String(value);
                        if (value !== this.country) { this.changeCountry(value, false); }
                    });
                }
            }
        },

        // --- reading / writing the bound values -------------------------------------------------
        read(name) {
            if (cfg.mode === 'alpine') {
                const value = ((this[cfg.model] || {})[name]);
                return value == null ? '' : String(value);
            }
            if (cfg.mode === 'livewire' && this.$wire) {
                const path = (cfg.wire || {})[name];
                const value = path ? this.$wire.$get(path) : '';
                return value == null ? '' : String(value);
            }
            return String((cfg.values || {})[name] ?? '');
        },
        readCode(name) {
            if (cfg.mode === 'alpine') {
                return String((cfg.codes || {})[name] ?? '');
            }
            if (cfg.mode === 'livewire' && this.$wire && cfg.codesPath) {
                const codes = this.$wire.$get(cfg.codesPath) || {};
                return String(codes[name] ?? '');
            }
            return String((cfg.codes || {})[name] ?? '');
        },
        write(name, value) {
            this.values[name] = value;
            if (cfg.mode === 'alpine' && this[cfg.model]) {
                this[cfg.model][name] = value;
            }
            if (cfg.mode === 'livewire' && this.$wire && (cfg.wire || {})[name]) {
                this.$wire.$set(cfg.wire[name], value, false);
            }
        },
        writeCode(name, code) {
            this.codes[name] = code;
            if (cfg.mode === 'livewire' && this.$wire && cfg.codesPath) {
                // WHY the whole object: an empty PHP array reaches JS as [], and a named key set
                // on an array is dropped when the component state is sent back to the server.
                const current = this.$wire.$get(cfg.codesPath);
                const codes = Object.assign({}, (current && typeof current === 'object') ? current : {});
                codes[name] = code;
                this.$wire.$set(cfg.codesPath, codes, false);
            }
        },

        // --- field state ---------------------------------------------------------------------------
        input(name) {
            const field = this.defs[name];
            if (!field || this.fallback[name]) { return 'text'; }
            // WHY: without a parent code a child list cannot load; when the parent is typed by
            // hand (its list failed), the child must be typed too, or a required child blocks the form.
            if (field.input === 'select' && field.parent_field && this.input(field.parent_field) !== 'select') {
                return 'text';
            }
            return field.input;
        },
        label(name) {
            return (this.defs[name] || {}).label || '';
        },
        editable(name) {
            const field = this.defs[name];
            return !field || !field.component || field.editable !== false || this.fallback[name];
        },
        children(name) {
            return Object.values(this.defs).filter((f) => f.parent_field === name).map((f) => f.name);
        },
        parentCode(name) {
            const parent = (this.defs[name] || {}).parent_field;
            return parent ? (this.codes[parent] || '') : null;
        },

        // --- data ----------------------------------------------------------------------------------
        async getJson(url, params) {
            const query = new URLSearchParams(params).toString();
            const response = await fetch(url + (url.includes('?') ? '&' : '?') + query, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) { throw new Error('HTTP ' + response.status); }
            return response.json();
        },
        next(key) {
            this.seq[key] = (this.seq[key] || 0) + 1;
            return this.seq[key];
        },
        loadMapped() {
            // Root lists load now; a child list loads once its parent has a code (matchByName / pick).
            Object.values(this.defs).forEach((f) => {
                if (f.input === 'select' && !f.parent_field) { this.loadOptions(f.name); }
            });
        },
        async loadOptions(name) {
            const field = this.defs[name];
            const ticket = this.next('options:' + name);
            if (!field || field.input !== 'select' || !this.country || !cfg.urls.options) {
                if (field && field.input === 'select' && !cfg.urls.options) { this.fallback[name] = true; }
                return;
            }
            const parent = this.parentCode(name);
            if (field.parent_field && !parent) { this.options[name] = []; return; }
            const country = this.country;
            this.loading[name] = true;
            let options = [];
            let fallback = false;
            try {
                const data = await this.getJson(cfg.urls.options, { country: country, component: field.component, parent: parent || '' });
                options = data.options || [];
                fallback = !!data.fallback;
            } catch (e) {
                fallback = true;
            }
            if (ticket !== this.seq['options:' + name] || country !== this.country) { return; }
            this.loading[name] = false;
            // The folded name is computed once per list, not on every keystroke.
            this.options[name] = options.map((o) => Object.assign({}, o, { folded: this.fold(o.name) }));
            this.fallback[name] = fallback;
            this.matchByName(name);
        },
        matchByName(name) {
            const field = this.defs[name];
            if (!field || field.input !== 'select') { return; }
            const list = this.options[name] || [];
            let code = this.codes[name];
            if (code && !list.some((o) => o.code === code)) { code = ''; }
            if (!code && this.values[name]) {
                const found = list.find((o) => o.name === this.values[name]);
                code = found ? found.code : '';
            }
            if (code !== this.codes[name]) { this.writeCode(name, code); }
            if (code) { this.children(name).forEach((child) => this.loadOptions(child)); }
        },

        // --- user actions --------------------------------------------------------------------------
        pick(name, code) {
            const option = (this.options[name] || []).find((o) => o.code === code);
            this.writeCode(name, option ? option.code : '');
            this.write(name, option ? option.name : '');
            this.clearChildren(name);
            this.children(name).forEach((child) => this.loadOptions(child));
        },
        typed(name, value) {
            this.write(name, value);
            if (this.codes[name]) { this.writeCode(name, ''); }
        },

        // --- searchable list (combobox) ------------------------------------------------------------
        /** Lower case, accents and đ removed, spaces collapsed: "Hồ Chí Minh" → "ho chi minh". */
        fold(text) {
            return String(text || '').normalize('NFD').replace(/[̀-ͯ]/g, '')
                .replace(/[đĐ]/g, 'd').toLowerCase().replace(/\s+/g, ' ').trim();
        },
        /** Fixed choices of a combobox (the country list printed by the page). */
        initList(name, list) {
            this.options[name] = (list || []).map((o) => ({ code: String(o.code), name: String(o.name), folded: this.fold(o.name) }));
            this.search[name] = '';
            this.active[name] = 0;
        },
        /** Code currently chosen in a combobox; the country lives in `country`, not in `codes`. */
        selectedCode(name) {
            return name === 'country' ? this.country : (this.codes[name] || '');
        },
        /** Text a closed combobox shows. */
        shown(name) {
            if (name === 'country') {
                const option = (this.options.country || []).find((o) => o.code === this.country);
                return option ? option.name : '';
            }
            return this.values[name] || '';
        },
        comboDisabled(name) {
            const field = this.defs[name];
            if (!field) { return false; }
            return !!(this.loading[name] || (field.parent_field && !this.codes[field.parent_field]));
        },
        filtered(name) {
            const list = this.options[name] || [];
            const query = this.fold(this.search[name]);
            return query ? list.filter((o) => (o.folded || this.fold(o.name)).includes(query)) : list;
        },
        showList(name) {
            if (this.openList === name) { return; }
            this.openList = name;
            this.search[name] = '';
            // Open on the current choice so the arrow keys continue from it.
            const index = (this.options[name] || []).findIndex((o) => o.code === this.selectedCode(name));
            this.active[name] = index < 0 ? 0 : index;
            this.scrollToActive(name);
        },
        closeList(name) {
            if (this.openList !== name) { return; }
            this.openList = '';
            this.search[name] = '';
        },
        searchList(name, value) {
            if (this.openList !== name) {
                // WHY: typing again after Esc / a pick (focus kept) edits the shown name, so the
                // input holds "<name><typed>" or a shortened name. Keep only what was typed; read
                // here rather than on keydown, because mobile keyboards often send no usable key.
                const shown = this.shown(name);
                if (shown && value.startsWith(shown)) {
                    value = value.slice(shown.length);
                } else if (shown && value.length === shown.length - 1 && shown.startsWith(value)) {
                    // Backspace on the shown name starts an empty search; a select-all + typed
                    // letter (shorter text) is kept as typed.
                    value = '';
                }
            }
            this.openList = name;
            this.search[name] = value;
            this.active[name] = 0;
            this.scrollToActive(name);
        },
        moveList(name, step) {
            if (this.openList !== name) { this.showList(name); return; }
            const count = this.filtered(name).length;
            if (!count) { return; }
            this.active[name] = ((this.active[name] || 0) + step + count) % count;
            this.scrollToActive(name);
        },
        chooseActive(name) {
            const option = this.filtered(name)[this.active[name] || 0];
            if (option) { this.chooseOption(name, option); }
        },
        chooseOption(name, option) {
            this.closeList(name);
            // WHY: picking the current choice again must not clear the fields below it.
            if (option.code === this.selectedCode(name)) { return; }
            if (name === 'country') {
                this.changeCountry(option.code);
                return;
            }
            this.pick(name, option.code);
        },
        scrollToActive(name) {
            this.$nextTick(() => {
                const list = this.$refs['list_' + name];
                const row = list ? list.querySelectorAll('[role="option"]')[this.active[name] || 0] : null;
                if (row) { row.scrollIntoView({ block: 'nearest' }); }
            });
        },
        clearChildren(name) {
            this.children(name).forEach((child) => {
                this.write(child, '');
                this.writeCode(child, '');
                this.options[child] = [];
                this.clearChildren(child);
            });
        },
        /**
         * A new country: fetch its fields, then reload the lists. When the shopper changed the
         * country, the values of the fields a list feeds are cleared (they belong to the old
         * country). When the page or the server changed it (an address was loaded), the values
         * are kept and matched against the new lists by name.
         */
        async changeCountry(country, fromUser = true) {
            const ticket = this.next('country');
            this.country = country || '';
            if (fromUser && cfg.countryField) { this.write('country', this.country); }
            let schema = null;
            if (this.country && cfg.urls.schema) {
                try {
                    schema = await this.getJson(cfg.urls.schema, { country: this.country, context: cfg.context || '' });
                } catch (e) {
                    schema = null;
                }
            }
            if (ticket !== this.seq['country']) { return; }
            const byName = {};
            ((schema && schema.fields) || []).forEach((f) => { byName[f.name] = f; });
            Object.keys(this.defs).forEach((name) => {
                const before = this.defs[name];
                const after = byName[name] || Object.assign({}, before, { input: 'text', component: null, parent_field: null, editable: true, label: before.default_label || before.label });
                const wasMapped = !!before.component;
                this.defs[name] = Object.assign({ default_label: before.default_label || before.label }, after);
                this.fallback[name] = false;
                this.options[name] = [];
                if (fromUser && (wasMapped || after.component)) {
                    this.write(name, '');
                    this.writeCode(name, '');
                } else if (!fromUser) {
                    this.values[name] = this.read(name);
                    this.codes[name] = '';
                }
            });
            this.suggestions = [];
            this.lookupState = '';
            this.loadMapped();
        },
        async lookup(name) {
            const value = (this.values[name] || '').trim();
            if (!value || !this.country || !cfg.urls.lookup) { return; }
            const ticket = this.next('lookup');
            this.lookupState = 'loading';
            this.suggestions = [];
            try {
                const data = await this.getJson(cfg.urls.lookup, { country: this.country, postcode: value });
                if (ticket !== this.seq['lookup']) { return; }
                if (data.fallback) {
                    this.lookupState = 'fallback';
                    return;
                }
                const list = data.suggestions || [];
                if (list.length === 1) {
                    await this.apply(list[0]);
                    this.lookupState = 'done';
                } else {
                    this.suggestions = list;
                    this.lookupState = list.length ? 'choose' : 'none';
                }
            } catch (e) {
                if (ticket === this.seq['lookup']) { this.lookupState = 'fallback'; }
            }
        },
        /**
         * Fills the fields from one postcode result, parents first. A field the result does not
         * cover is cleared, so no value of a previous address stays next to the new one.
         */
        async apply(suggestion) {
            this.suggestions = [];
            const components = suggestion.components || {};
            const depth = (f) => (f.parent_field ? 1 + depth(this.defs[f.parent_field] || {}) : 0);
            const ordered = Object.values(this.defs).filter((f) => f.component && f.input !== 'lookup')
                .sort((a, b) => depth(a) - depth(b));
            for (const field of ordered) {
                const part = components[field.component] || null;
                if (this.input(field.name) === 'select') {
                    await this.loadOptions(field.name);
                }
                this.writeCode(field.name, part ? (part.code || '') : '');
                this.write(field.name, part ? (part.name || '') : '');
            }
            this.lookupState = 'done';
        },
        describe(suggestion) {
            return Object.values(suggestion.components || {}).map((c) => c.name).filter(Boolean).join(' ');
        },
    };
};
// WHY both: x-data="gp247Address(...)" resolves a registered Alpine.data name first, and on a
// Livewire SPA visit this script may run after Alpine started (alpine:init already fired) —
// register right away when Alpine exists, otherwise on alpine:init (same as core's datepicker).
const define = () => window.Alpine.data('gp247Address', window.gp247Address);
if (window.Alpine) { define(); } else { document.addEventListener('alpine:init', define); }
})();
</script>
