<?php

namespace GP247\Shop\Tests\Unit\ApiCommerce;

use GP247\Shop\Api\Support\Commerce\OrderAccessToken;
use GP247\Shop\Payment\Support\ReturnUrlPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use GP247\Shop\Tests\TestCase;

/**
 * Return-address allowlist (exact scheme + host + port, path prefix on a segment boundary)
 * and the order access token (HMAC of the order id, compared in constant time).
 */
class ReturnUrlPolicyAndOrderAccessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['gp247-config.payment.return_urls' => "zzshop://checkout\nhttps://app.zz-shop.example/return, https://pay.zz-shop.example:8443"]);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function addresses(): array
    {
        return [
            'deep link on the list'          => ['zzshop://checkout/done', true],
            'deep link host only'            => ['zzshop://checkout', true],
            'path under the prefix'          => ['https://app.zz-shop.example/return/ok?x=1', true],
            'host case differs'              => ['https://APP.zz-shop.example/return', true],
            'explicit port on the list'      => ['https://pay.zz-shop.example:8443/anything', true],
            'path only sharing letters'      => ['https://app.zz-shop.example/returned', false],
            'look-alike host'                => ['https://app.zz-shop.example.attacker/return', false],
            'sub-domain of a listed host'    => ['https://evil.app.zz-shop.example/return', false],
            'other scheme'                   => ['http://app.zz-shop.example/return', false],
            'protocol-relative'              => ['//app.zz-shop.example/return', false],
            'user info before the host'      => ['https://app.zz-shop.example@evil.example/return', false],
            'listed host as user info'       => ['https://evil.example\\@app.zz-shop.example/return', false],
            'port not on the list'           => ['https://pay.zz-shop.example/anything', false],
            'javascript'                     => ['javascript:alert(1)', false],
            'empty'                          => ['', false],
        ];
    }

    #[DataProvider('addresses')]
    public function test_only_an_address_on_the_list_is_accepted(string $url, bool $accepted): void
    {
        $this->assertSame($accepted, ReturnUrlPolicy::accepts($url));
    }

    public function test_an_empty_list_accepts_nothing(): void
    {
        config(['gp247-config.payment.return_urls' => '']);

        $this->assertFalse(ReturnUrlPolicy::accepts('zzshop://checkout'));
    }

    public function test_the_order_access_token_opens_only_its_own_order(): void
    {
        $token = OrderAccessToken::issue('101');

        $this->assertTrue(OrderAccessToken::verify('101', $token));
        $this->assertFalse(OrderAccessToken::verify('102', $token));
        $this->assertFalse(OrderAccessToken::verify('101', $token . 'x'));
        $this->assertFalse(OrderAccessToken::verify('101', null));
        $this->assertSame(43, strlen($token), '256-bit, base64url without padding');
    }
}
