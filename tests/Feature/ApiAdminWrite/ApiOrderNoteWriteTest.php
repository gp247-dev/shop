<?php

namespace GP247\Shop\Tests\Feature\ApiAdminWrite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Group;
use GP247\Shop\Tests\Feature\ApiAdminWrite\Support\OrderWriteFixtures;
use GP247\Shop\Tests\Feature\Compat\Support\ApiFixtures;
use GP247\Shop\Tests\TestCase;

/**
 * An ERP adds a note to an order through the admin API: it lands in the order history
 * with the author and the API as source, shown safely (no markup injection).
 *
 * @aidlc-unit api-admin-write
 * @aidlc-story US-api-admin-write-order-note
 * @aidlc-adr api-admin-write_write-authorization-as-admin-action
 */
#[Group('api-admin-write')]
class ApiOrderNoteWriteTest extends TestCase
{
    use DatabaseTransactions;
    use ApiFixtures;
    use OrderWriteFixtures;

    public function test_a_note_lands_in_the_order_history_with_its_author_and_the_api_as_source(): void
    {
        [$order] = $this->orderWithStock();
        $admin = $this->makeAdmin([], ['administrator']);

        $this->write($this->tokenFor($admin, ['admin']), 'order/' . $order->id . '/notes', ['content' => 'Called the customer, ships tomorrow'])
            ->assertOk()->assertJsonPath('data.id', $order->id);

        $last = $this->historyOf($order)->last();
        $this->assertStringContainsString('Called the customer, ships tomorrow', (string) $last->content);
        $this->assertStringContainsString('API', (string) $last->content);
        $this->assertSame((string) $admin->id, (string) $last->admin_id);
    }

    public function test_markup_in_a_note_is_escaped(): void
    {
        [$order] = $this->orderWithStock();

        $this->write($this->writer(), 'order/' . $order->id . '/notes', ['content' => '<script>alert(1)</script>'])->assertOk();

        $content = (string) $this->historyOf($order)->last()->content;
        $this->assertStringNotContainsString('<script>', $content);
        $this->assertStringContainsString('&lt;script&gt;', $content);
    }

    public function test_an_empty_or_too_long_note_is_refused(): void
    {
        [$order] = $this->orderWithStock();

        $this->write($this->writer(), 'order/' . $order->id . '/notes', ['content' => '   '])->assertStatus(422);
        $this->write($this->writer(), 'order/' . $order->id . '/notes', ['content' => str_repeat('x', 1001)])->assertStatus(422);
    }

    /**
     * @return string
     */
    private function writer(): string
    {
        return $this->tokenFor($this->makeAdmin([], ['administrator']), ['admin']);
    }
}
