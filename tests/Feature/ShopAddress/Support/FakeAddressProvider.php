<?php

namespace GP247\Shop\Tests\Feature\ShopAddress\Support;

use GP247\Shop\Address\Contracts\AddressProvider;
use GP247\Shop\Address\Contracts\ListsAddressOptions;
use GP247\Shop\Address\Contracts\ResolvesPostcode;
use GP247\Shop\Address\Data\AddressComponent;
use GP247\Shop\Address\Data\AddressOption;
use GP247\Shop\Address\Data\AddressSchema;
use GP247\Shop\Address\Data\AddressSuggestion;
use GP247\Shop\Address\Data\NormalizedAddress;

/**
 * Address provider with a tiny built-in data set, so the address contract can be
 * exercised without a country plugin: "VN" offers province → ward selects, "JP" a
 * postcode lookup that fills prefecture, municipality and town.
 */
class FakeAddressProvider implements AddressProvider, ListsAddressOptions, ResolvesPostcode
{
    public const PROVINCES = ['01' => 'Hà Nội', '79' => 'Hồ Chí Minh'];

    public const WARDS = [
        '01' => ['00004' => 'Phường Ba Đình', '00008' => 'Phường Ngọc Hà'],
        '79' => ['26734' => 'Phường Sài Gòn'],
    ];

    public const PREFECTURES = ['13' => '東京都', '27' => '大阪府'];

    public const POSTCODES = [
        '100-0001' => [['prefecture' => '13', 'municipality' => ['13101', '千代田区'], 'town' => ['0001', '千代田']]],
        '530-0001' => [
            ['prefecture' => '27', 'municipality' => ['27127', '大阪市北区'], 'town' => ['0001', '梅田']],
            ['prefecture' => '27', 'municipality' => ['27127', '大阪市北区'], 'town' => ['0002', '大深町']],
        ],
    ];

    /** @var array<string, int> Method name => number of calls. */
    public array $calls = [];

    /** @var array<int, string> Methods that throw. */
    public array $failing = [];

    /**
     * {@inheritDoc}
     */
    public function schema(string $country): AddressSchema
    {
        $this->touch('schema');
        if ($country === 'JP') {
            return new AddressSchema('JP', '2026-01', [
                new AddressComponent('postcode', 'fake_address.postcode', AddressComponent::KIND_LOOKUP),
                new AddressComponent('prefecture', 'fake_address.prefecture', AddressComponent::KIND_LIST),
                new AddressComponent('municipality', 'fake_address.municipality', AddressComponent::KIND_DERIVED, 'prefecture'),
                new AddressComponent('town', 'fake_address.town', AddressComponent::KIND_DERIVED, 'municipality'),
            ], ['postcode' => 'postcode', 'city' => 'prefecture', 'district' => 'municipality', 'address1' => 'town'], ['address1']);
        }

        return new AddressSchema('VN', '2025-07', [
            new AddressComponent('province', 'fake_address.province', AddressComponent::KIND_LIST),
            new AddressComponent('ward', 'fake_address.ward', AddressComponent::KIND_LIST, 'province'),
        ], ['city' => 'province', 'ward' => 'ward']);
    }

    /**
     * {@inheritDoc}
     */
    public function options(string $country, string $component, ?string $parentCode, string $locale): array
    {
        $this->touch('options');
        $rows = match ($component) {
            'province' => self::PROVINCES,
            'prefecture' => self::PREFECTURES,
            'ward' => self::WARDS[(string) $parentCode] ?? [],
            default => [],
        };
        $out = [];
        foreach ($rows as $code => $name) {
            $out[] = new AddressOption((string) $code, $name);
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function lookup(string $country, string $postcode, string $locale): array
    {
        $this->touch('lookup');
        $out = [];
        foreach (self::POSTCODES[$postcode] ?? [] as $row) {
            $out[] = new AddressSuggestion([
                'postcode' => ['code' => $postcode, 'name' => $postcode],
                'prefecture' => ['code' => $row['prefecture'], 'name' => self::PREFECTURES[$row['prefecture']]],
                'municipality' => ['code' => $row['municipality'][0], 'name' => $row['municipality'][1]],
                'town' => ['code' => $row['town'][0], 'name' => $row['town'][1]],
            ]);
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function normalize(string $country, array $components, string $locale): NormalizedAddress
    {
        $this->touch('normalize');
        $matched = [];
        $errors = [];

        $province = $this->match(self::PROVINCES, $components['province'] ?? null);
        if ($province !== null) {
            $matched['province'] = $province;
        } elseif (isset($components['province'])) {
            $errors['province'] = 'fake_address.unknown';
        }
        if (isset($components['ward'])) {
            $ward = $province === null ? null : $this->match(self::WARDS[$province['code']] ?? [], $components['ward']);
            if ($ward !== null) {
                $matched['ward'] = $ward;
            } else {
                $errors['ward'] = 'fake_address.unknown';
            }
        }

        $prefecture = $this->match(self::PREFECTURES, $components['prefecture'] ?? null);
        if ($prefecture !== null) {
            $matched['prefecture'] = $prefecture;
        }
        if (isset($components['postcode']['name']) && isset(self::POSTCODES[$components['postcode']['name']])) {
            $matched['postcode'] = ['code' => $components['postcode']['name'], 'name' => $components['postcode']['name']];
        }
        foreach (['municipality', 'town'] as $key) {
            if (!empty($components[$key]['code'])) {
                $matched[$key] = ['code' => (string) $components[$key]['code'], 'name' => (string) ($components[$key]['name'] ?? '')];
            }
        }

        return new NormalizedAddress($matched, $errors);
    }

    /**
     * @param array<string|int, string>                 $rows  code => name.
     * @param array{code?: ?string, name?: ?string}|null $value Submitted value.
     * @return array{code: string, name: string}|null
     */
    private function match(array $rows, ?array $value): ?array
    {
        if ($value === null) {
            return null;
        }
        $code = (string) ($value['code'] ?? '');
        if ($code !== '') {
            return isset($rows[$code]) ? ['code' => $code, 'name' => $rows[$code]] : null;
        }
        $found = array_search((string) ($value['name'] ?? ''), $rows, true);

        return $found === false ? null : ['code' => (string) $found, 'name' => $rows[$found]];
    }

    /**
     * @param string $method Method being called.
     * @return void
     */
    private function touch(string $method): void
    {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
        if (in_array($method, $this->failing, true)) {
            throw new \RuntimeException('fake address provider: ' . $method . ' is down');
        }
    }
}
