<?php

namespace GP247\Shop\Tests\Feature\CustomerPrivacy;

use GP247\Shop\Admin\Livewire\CustomerManager;
use GP247\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Guard: a customer account is removed only by the personal data erasure of the core. Code
 * of gp247/core, front, shop or mcp that deletes a customer row directly would leave the
 * customer's orders, addresses and sign-ins behind, so this test fails on it.
 *
 * @aidlc-unit customer-privacy
 * @aidlc-story US-customer-privacy-admin-delete-complete
 * @aidlc-adr customer-privacy_admin-delete-flow
 */
#[Group('customer-privacy')]
class SingleErasurePathTest extends TestCase
{
    /** Ways of deleting customer rows directly. */
    private const PATTERNS = [
        '/(ShopCustomer|AdminCustomer)::[^;]*->(delete|forceDelete)\(/s',
        '/(ShopCustomer|AdminCustomer)::destroy\(/',
        '/table\([^)]*shop_customer[\'"]\s*\)[^;]*->(delete|truncate)\(/s',
        '/\$customer->(delete|forceDelete)\(\)/',
    ];

    public function test_no_package_deletes_a_customer_row_outside_the_erasure(): void
    {
        $hits = [];
        foreach (['core', 'front', 'shop', 'mcp'] as $package) {
            $root = base_path('vendor/gp247/' . $package . '/src');
            if (!is_dir($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                foreach (self::PATTERNS as $pattern) {
                    if (preg_match($pattern, $code, $m, PREG_OFFSET_CAPTURE) === 1) {
                        $hits[] = $package . '/' . substr($file->getPathname(), strlen($root) + 1) . ':' . (substr_count(substr($code, 0, $m[0][1]), "\n") + 1);
                    }
                }
            }
        }

        $this->assertSame([], $hits, 'Erase customers through GP247\Core\PersonalData\Application\ErasureService.');
    }

    public function test_the_customers_screen_cannot_fall_back_to_deleting_the_row(): void
    {
        $method = new \ReflectionMethod(CustomerManager::class, 'deleteModel');

        $this->expectException(\LogicException::class);

        $method->invoke((new \ReflectionClass(CustomerManager::class))->newInstanceWithoutConstructor(), 'any-id');
    }
}
