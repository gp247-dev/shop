<?php

namespace GP247\Shop\Tests\Feature\Compat;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use GP247\Shop\Tests\TestCase;

/**
 * Contract of the gp247_shop_mail_view() helper (the shared view-resolution
 * wrapper used by every transactional shop mail after the DRY cleanup).
 *
 * CI tier: boots the app / DB, so it runs in CI rather than the sandbox.
 *
 * @aidlc-unit compat-foundation
 * @aidlc-story US-CMP-mail-delivery-hardening
 */
class ShopMailViewTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Helper must be defined once the shop package is loaded.
     */
    public function test_helper_is_defined(): void
    {
        $this->assertTrue(
            function_exists('gp247_shop_mail_view'),
            'gp247_shop_mail_view() must be defined by the shop package'
        );
    }

    /**
     * Resolving a known transactional mail sub-path returns a non-empty view name
     * that carries the sub-path (either the active-template override or the
     * gp247-shop-front:: package fallback).
     */
    public function test_resolves_known_mail_subpath_to_a_view_name(): void
    {
        if (!function_exists('gp247_shop_mail_view')) {
            $this->markTestSkipped('gp247_shop_mail_view not available');
        }

        $view = gp247_shop_mail_view('email.shop_welcome_customer');

        $this->assertIsString($view);
        $this->assertNotSame('', $view);
        $this->assertStringContainsString('email.shop_welcome_customer', $view);
    }
}
