<?php

namespace GP247\Shop\Tests\Unit\ShopAdmin\Catalog;

use GP247\Shop\Services\Catalog\CatalogWriter;
use GP247\Shop\Services\Catalog\CustomerWriter;
use GP247\Shop\Services\Catalog\ProductWriter;
use GP247\Shop\Services\Catalog\WriteContext;
use GP247\Shop\Tests\TestCase;

/**
 * Pure rules of the catalog write services: alias derivation, accepted password hashes,
 * the reference-store resolver registry and the write context guard.
 *
 * @aidlc-unit shop-admin
 */
class CatalogWriterRulesTest extends TestCase
{
    public function test_alias_keeps_a_given_alias_and_otherwise_slugs_the_fallback(): void
    {
        $this->assertSame('my-alias', CatalogWriter::alias('my-alias', 'Ignored Name'));
        $this->assertSame('green-tea-set', CatalogWriter::alias('', 'Green Tea Set'));
        $this->assertLessThanOrEqual(100, strlen(CatalogWriter::alias('', str_repeat('long name ', 30))));
    }

    public function test_only_bcrypt_and_argon2_hashes_are_accepted(): void
    {
        $this->assertTrue(CustomerWriter::acceptsHash(password_hash('x', PASSWORD_BCRYPT)));
        if (defined('PASSWORD_ARGON2ID')) {
            $this->assertTrue(CustomerWriter::acceptsHash(password_hash('x', PASSWORD_ARGON2ID)));
        }
        $this->assertFalse(CustomerWriter::acceptsHash(md5('x')));
        $this->assertFalse(CustomerWriter::acceptsHash('$P$BabcdefghijklmnopqrstuvwxyzABCD'));
        $this->assertFalse(CustomerWriter::acceptsHash('plain-password'));
    }

    public function test_the_reference_store_follows_the_first_resolver_that_answers(): void
    {
        config(['gp247-config.shop.product_reference_store_resolvers' => []]);
        $this->assertSame('7', ProductWriter::referenceStoreFor('7'));

        config(['gp247-config.shop.product_reference_store_resolvers' => [
            ['key' => 'broken', 'callback' => static function (): string { throw new \RuntimeException('down'); }],
            ['key' => 'silent', 'callback' => static fn (): ?string => null],
            ['key' => 'market', 'callback' => static fn (string $owner): string => 'root-of-' . $owner],
        ]]);
        $this->assertSame('root-of-7', ProductWriter::referenceStoreFor('7'));
    }

    public function test_a_write_needs_a_store_and_a_known_actor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new WriteContext(' ');
    }
}
