<?php

namespace GP247\Shop\Address;

use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Contracts\ListsAddressOptions;
use GP247\Shop\Address\Contracts\ResolvesPostcode;
use GP247\Shop\Address\Data\AddressComponent;
use GP247\Shop\Address\Data\AddressField;
use GP247\Shop\Address\Data\AddressFieldSet;
use GP247\Shop\Address\Data\AddressMeta;
use GP247\Shop\Address\Data\AddressOption;
use GP247\Shop\Address\Data\AddressResolution;
use GP247\Shop\Address\Data\AddressSchema;
use GP247\Shop\Address\Data\AddressSuggestion;
use GP247\Shop\Address\Data\NormalizedAddress;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Talks to the address provider of a country on behalf of every form: lists the
 * choices of a field, resolves a postcode, and — on save — checks the submitted
 * address against the provider's data, replacing names by the provider's own and
 * building the codes stored in `address_meta`.
 *
 * Every provider call is isolated: a failure is reported once and the caller gets a
 * "fall back to typing" answer, never an exception. A provider failure while saving
 * lets the address through as free text (a broken plugin must not stop an order).
 *
 * @aidlc-unit shop-address
 * @aidlc-story US-shop-address-server-normalize
 * @aidlc-story US-shop-address-structured-meta
 * @aidlc-story US-shop-address-free-text-fallback
 * @aidlc-adr shop-address_names-plus-meta-storage
 */
class AddressService
{
    /** Seconds a list of choices stays cached when the config gives no value. */
    private const DEFAULT_CACHE_TTL = 86400;

    /** Characters a component code may be made of. */
    private const CODE_PATTERN = '/^[A-Za-z0-9_.\-]+$/';

    /** Longest component name passed to a provider (size of the address columns). */
    private const NAME_MAX = 100;

    /** @var bool|null Memo of storesMeta(). */
    private ?bool $storesMeta = null;

    /**
     * @return AddressService The container-bound service.
     */
    public static function make(): self
    {
        return app(self::class);
    }

    /**
     * Choices of a list component, limited to one parent when given.
     *
     * @param string|null $storeId    Store; null = the current store.
     * @param string      $country    ISO alpha-2 code.
     * @param string      $component  Component key.
     * @param string|null $parentCode Code of the selected parent.
     * @param string|null $locale     Language; null = the current one.
     * @return array{options: array<int, array{code: string, name: string}>, fallback: bool}
     *
     * @aidlc-story US-shop-address-dependent-select
     */
    public function options(?string $storeId, string $country, string $component, ?string $parentCode, ?string $locale = null): array
    {
        [$key, $schema, $provider] = $this->provider($storeId, $country);
        $definition = $schema?->component($component);
        if (
            !$provider instanceof ListsAddressOptions
            || $definition === null
            || $definition->kind !== AddressComponent::KIND_LIST
            || !AddressProviderRegistry::instance()->supports((string) $key, AddressProvider::CAP_OPTIONS)
        ) {
            return ['options' => [], 'fallback' => false];
        }
        $locale = $locale ?? (string) gp247_get_locale();
        $parentCode = ($parentCode === null || $parentCode === '') ? null : $parentCode;
        $cacheKey = 'gp247_address_options:' . sha1(json_encode([$key, $schema->country, $schema->dataVersion, $component, $parentCode, $locale]));

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return ['options' => $cached, 'fallback' => false];
        }
        try {
            $options = [];
            foreach ($provider->options($schema->country, $component, $parentCode, $locale) as $option) {
                if ($option instanceof AddressOption) {
                    $options[] = $option->toArray();
                }
            }
        } catch (\Throwable $e) {
            $this->report((string) $key, 'options', $e);

            return ['options' => [], 'fallback' => true];
        }
        // WHY not when empty: the parent code comes from the request, so caching "nothing"
        // would let anyone fill the cache with one entry per made-up parent.
        if ($options !== []) {
            Cache::put($cacheKey, $options, (int) config('gp247-config.shop.address.cache_ttl', self::DEFAULT_CACHE_TTL));
        }

        return ['options' => $options, 'fallback' => false];
    }

    /**
     * Addresses matching a postcode.
     *
     * @param string|null $storeId  Store; null = the current store.
     * @param string      $country  ISO alpha-2 code.
     * @param string      $postcode Postcode as typed.
     * @param string|null $locale   Language; null = the current one.
     * @return array{suggestions: array<int, array{components: array<string, array{code: ?string, name: string}>}>, fallback: bool}
     *
     * @aidlc-story US-shop-address-postcode-lookup
     */
    public function lookup(?string $storeId, string $country, string $postcode, ?string $locale = null): array
    {
        [$key, $schema, $provider] = $this->provider($storeId, $country);
        $postcode = trim($postcode);
        if (
            !$provider instanceof ResolvesPostcode
            || $postcode === ''
            || !AddressProviderRegistry::instance()->supports((string) $key, AddressProvider::CAP_POSTCODE)
        ) {
            return ['suggestions' => [], 'fallback' => false];
        }
        try {
            $suggestions = [];
            foreach ($provider->lookup($schema->country, $postcode, $locale ?? (string) gp247_get_locale()) as $suggestion) {
                if ($suggestion instanceof AddressSuggestion) {
                    $suggestions[] = $suggestion->toArray();
                }
            }
        } catch (\Throwable $e) {
            $this->report((string) $key, 'lookup', $e);

            return ['suggestions' => [], 'fallback' => true];
        }

        return ['suggestions' => $suggestions, 'fallback' => false];
    }

    /**
     * Checks a submitted address against the provider of its country.
     *
     * For each field fed by a component the form may send `<field>` (name) and
     * `<field>_code`. Matched components get the provider's canonical name and enter
     * `address_meta`. An unmatched list value is refused when the country's mapping is
     * strict, otherwise kept as typed without a code.
     *
     * @param string|null          $storeId Store; null = the current store.
     * @param string|null          $country ISO alpha-2 code of the address.
     * @param array<string, mixed> $input   Submitted address data.
     * @param string               $context AddressForm::CONTEXT_*.
     * @return AddressResolution
     */
    public function normalize(?string $storeId, ?string $country, array $input, string $context): AddressResolution
    {
        return $this->normalizeSet(AddressForm::make()->resolve($storeId, $country, $context), $input);
    }

    /**
     * Same as normalize(), for a field set the caller already resolved.
     *
     * @param AddressFieldSet      $set   Fields of the form.
     * @param array<string, mixed> $input Submitted address data.
     * @return AddressResolution
     */
    public function normalizeSet(AddressFieldSet $set, array $input): AddressResolution
    {
        if (!$set->hasProvider()) {
            return new AddressResolution();
        }
        [$key, $schema, $provider] = $this->provider($set->storeId, (string) $set->country);
        $submitted = $this->submittedComponents($set, $input);
        if ($provider === null || $submitted === []) {
            return new AddressResolution();
        }

        try {
            $normalized = $provider->normalize($schema->country, $submitted, (string) gp247_get_locale());
        } catch (\Throwable $e) {
            $this->report((string) $key, 'normalize', $e);

            return new AddressResolution();
        }

        return $this->resolution($set, $schema, $input, $submitted, $normalized);
    }

    /**
     * Applies the provider's answer to a customer mapping (the `validate` rules and the
     * data array a gp247_customer_*_mapping() helper is about to return): canonical
     * names replace the submitted ones, `address_meta` is added, and a refused field
     * gets a rule that fails with the reason — so every caller of the mapping reports
     * it like any other validation error.
     *
     * Nothing is touched when no plugin serves the country, when the form sent none of
     * the mapped fields (a partial update must not erase the codes), or when the
     * `address_meta` column is not there yet.
     *
     * @param AddressFieldSet           $set      Fields of the form.
     * @param array<string, mixed>      $input    Raw submitted data.
     * @param array<string, mixed>      $validate Rules built so far.
     * @param array<string, mixed>      $data     Data to store built so far.
     * @param array<string, mixed>|null $current  Stored address of the record being edited: when no
     *                                            address field differs and no code was sent, the address
     *                                            is left as it is (an old free-text address stays editable).
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} Rules and data.
     */
    public function applyToMapping(AddressFieldSet $set, array $input, array $validate, array $data, ?array $current = null): array
    {
        if ($current !== null && !$this->addressChanged($input, $current)) {
            return [$validate, $data];
        }
        if (!$set->hasProvider()) {
            // WHY: on a shop that uses address plugins, an address moved to a country no
            // plugin serves must not keep the codes of its previous values.
            if ($this->sentAddressField($input) && AddressProviderRegistry::instance()->countries($set->storeId) !== [] && $this->storesMeta()) {
                $data['address_meta'] = null;
            }

            return [$validate, $data];
        }
        if (!$this->sentMappedField($set, $input) || !$this->storesMeta()) {
            return [$validate, $data];
        }
        $resolution = $this->normalizeSet($set, $input);
        if (!$resolution->passes()) {
            foreach ($resolution->errors as $field => $message) {
                $rules = is_array($validate[$field] ?? null) ? $validate[$field] : array_filter(explode('|', (string) ($validate[$field] ?? '')));
                $rules[] = static function (string $attribute, mixed $value, \Closure $fail) use ($message): void {
                    $fail($message);
                };
                $validate[$field] = $rules;
            }

            return [$validate, $data];
        }

        return [$validate, $resolution->applyTo($data)];
    }

    /**
     * Whether the `address_meta` column exists. A site updated by composer but not yet
     * by gp247:update lacks it, and writing it would break every address save.
     *
     * @return bool
     */
    public function storesMeta(): bool
    {
        if ($this->storesMeta === null) {
            try {
                $connection = Schema::connection(GP247_DB_CONNECTION)->getConnection();
                // WHY scoped + one day: the answer belongs to one database (a restored or
                // trial database sharing this cache may not have the column), and only a
                // positive answer is kept — a missing column is asked again.
                $key = 'gp247_address_meta_column:' . sha1($connection->getName() . '|' . $connection->getDatabaseName() . '|' . GP247_DB_PREFIX);
                $this->storesMeta = (bool) Cache::remember($key, 86400, static function (): ?bool {
                    return Schema::connection(GP247_DB_CONNECTION)->hasColumn(GP247_DB_PREFIX . 'shop_customer_address', 'address_meta') ?: null;
                });
            } catch (\Throwable $e) {
                $this->storesMeta = false;
            }
        }

        return $this->storesMeta;
    }

    /**
     * @param array<string, mixed> $input   Submitted data.
     * @param array<string, mixed> $current Stored address.
     * @return bool True when a code was sent or an address field (or the country) differs from the stored one.
     */
    private function addressChanged(array $input, array $current): bool
    {
        foreach (array_merge(AddressMapping::FIELDS, ['country']) as $field) {
            if ($this->scalar($input[$field . '_code'] ?? null) !== '') {
                return true;
            }
            if (array_key_exists($field, $input) && $this->scalar($input[$field]) !== trim((string) ($current[$field] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $input Submitted data.
     * @return bool True when the form sent at least one address field a component could feed.
     */
    private function sentAddressField(array $input): bool
    {
        return array_intersect(AddressMapping::FIELDS, array_keys($input)) !== [];
    }

    /**
     * @param AddressFieldSet      $set   Fields of the form.
     * @param array<string, mixed> $input Submitted data.
     * @return bool True when the form sent at least one field fed by a component.
     */
    private function sentMappedField(AddressFieldSet $set, array $input): bool
    {
        foreach ($set->mapped() as $field) {
            if (array_key_exists($field->name, $input) || array_key_exists($field->name . '_code', $input)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param AddressFieldSet      $set   Fields of the form.
     * @param array<string, mixed> $input Submitted data.
     * @return array<string, array{code: ?string, name: ?string}> Component key => submitted value (empty fields left out).
     */
    private function submittedComponents(AddressFieldSet $set, array $input): array
    {
        $out = [];
        foreach ($set->mapped() as $field) {
            $name = $this->scalar($input[$field->name] ?? null);
            $code = $this->scalar($input[$field->name . '_code'] ?? null);
            // WHY: both reach the provider straight from the request, sometimes before the
            // form's own validation ran. Something that cannot be a code is dropped (the name
            // decides); an over-long name is cut to the column size.
            if (strlen($code) > AddressFieldSet::CODE_MAX || preg_match(self::CODE_PATTERN, $code) !== 1) {
                $code = '';
            }
            $name = mb_substr($name, 0, self::NAME_MAX);
            if ($name === '' && $code === '') {
                continue;
            }
            $out[(string) $field->component] = ['code' => $code === '' ? null : $code, 'name' => $name === '' ? null : $name];
        }

        return $out;
    }

    /**
     * @param AddressFieldSet                                    $set        Fields of the form.
     * @param AddressSchema                                      $schema     Provider schema.
     * @param array<string, mixed>                               $input      Submitted data.
     * @param array<string, array{code: ?string, name: ?string}> $submitted  Components sent to the provider.
     * @param NormalizedAddress                                  $normalized Provider answer.
     * @return AddressResolution
     */
    private function resolution(AddressFieldSet $set, AddressSchema $schema, array $input, array $submitted, NormalizedAddress $normalized): AddressResolution
    {
        $values = [];
        $components = [];
        $errors = [];
        foreach ($set->mapped() as $field) {
            $key = (string) $field->component;
            if (!isset($submitted[$key])) {
                continue;
            }
            $kind = $schema->component($key)?->kind;
            if (!$normalized->has($key)) {
                // WHY select only: without a list to pick from (provider cannot list, or
                // it fell back to typing) the shopper could only guess the exact name.
                if ($set->strict && $kind === AddressComponent::KIND_LIST && $field->input === AddressField::INPUT_SELECT) {
                    $errors[$field->name] = (string) gp247_language_render('customer.address_not_in_list', ['attribute' => $field->label]);
                }
                continue;
            }
            $match = [
                'code' => $this->plain($normalized->components[$key]['code']),
                'name' => $this->plain($normalized->components[$key]['name']),
            ];
            if ($kind === AddressComponent::KIND_DERIVED && $field->editable) {
                // WHY: the shopper may extend a prefilled line ("Chiyoda" → "Chiyoda 1-1");
                // the code stays only while the line still starts with the matched name.
                if ($match['name'] !== '' && str_starts_with($this->scalar($input[$field->name] ?? null), $match['name'])) {
                    $components[$key] = $match;
                }
                continue;
            }
            $values[$field->name] = $match['name'];
            $components[$key] = $match;
        }
        if ($errors !== []) {
            return new AddressResolution([], null, $errors);
        }
        $meta = $components === []
            ? null
            : (new AddressMeta((string) $set->providerKey, (string) $set->country, $set->dataVersion, $components))->toArray();

        return new AddressResolution($values, $meta);
    }

    /**
     * @param string|null $storeId Store; null = the current store.
     * @param string      $country ISO alpha-2 code.
     * @return array{0: string|null, 1: AddressSchema|null, 2: AddressProvider|null}
     */
    private function provider(?string $storeId, string $country): array
    {
        [$key, $schema] = AddressForm::make()->providerFor($storeId, $country);
        if ($key === null || $schema === null) {
            return [null, null, null];
        }

        return [$key, $schema, AddressProviderRegistry::instance()->provider($key)];
    }

    /**
     * @param mixed $value Code or name returned by a provider.
     * @return string The value as plain text, tags removed — it is stored and printed like any address text.
     */
    private function plain(mixed $value): string
    {
        return (string) gp247_text_plain(data: is_scalar($value) ? (string) $value : '', stripTags: true);
    }

    /**
     * @param mixed $value Submitted value.
     * @return string Trimmed string ('' for non-scalars).
     */
    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param string     $key    Plugin key.
     * @param string     $method Provider method that failed.
     * @param \Throwable $e      Failure.
     * @return void
     */
    private function report(string $key, string $method, \Throwable $e): void
    {
        AddressProviderRegistry::instance()->reportOnce(
            $key . ':' . $method,
            '[GP247 address] provider "' . $key . '" failed in ' . $method . '() (' . $e->getMessage() . ') — falling back to free text.'
        );
    }
}
