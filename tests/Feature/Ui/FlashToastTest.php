<?php

namespace Tests\Feature\Ui;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The bridge that carries a controller's flashed message to the screen.
 *
 * Twenty-three places in the app redirect with `with('toast', ...)` and, until
 * the layout grew a reader, not one of them reached the reader: the session was
 * written and never looked at. These tests exist so that gap cannot reopen
 * quietly -- a message that silently stops arriving fails here, not in a
 * support ticket.
 *
 * The payload crosses into JavaScript as JSON, so most of what is pinned below
 * is about the crossing: that it is present, that it round-trips, and that a
 * message carrying markup cannot close the script tag it sits in.
 */
class FlashToastTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->owner()->create();
    }

    /**
     * The JSON the layout handed to the client, decoded.
     *
     * @return array<mixed>
     */
    private function payloadIn(string $html): array
    {
        $this->assertSame(1, preg_match(
            '#<script type="application/json" id="flash-toast">(.*?)</script>#s',
            $html,
            $matches
        ), 'the layout must hand the flashed message to the client as JSON');

        return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function a_flashed_message_reaches_the_page(): void
    {
        $response = $this->actingAs($this->owner())
            ->withSession(['toast' => ['type' => 'success', 'message' => 'Rak A-01 dihapus.']])
            ->get(route('master.lokasi-rak'));

        $response->assertOk();
        $this->assertSame(
            ['type' => 'success', 'message' => 'Rak A-01 dihapus.'],
            $this->payloadIn($response->getContent())
        );
    }

    #[Test]
    public function every_type_the_controllers_use_arrives_unchanged(): void
    {
        foreach (['success', 'error', 'warning', 'info'] as $type) {
            $response = $this->actingAs($this->owner())
                ->withSession(['toast' => ['type' => $type, 'message' => 'Pesan '.$type]])
                ->get(route('master.lokasi-rak'));

            $this->assertSame($type, $this->payloadIn($response->getContent())['type']);
        }
    }

    #[Test]
    public function a_message_carrying_markup_is_neutralised_on_the_way_out(): void
    {
        $response = $this->actingAs($this->owner())
            ->withSession(['toast' => [
                'type' => 'error',
                'message' => '</script><script>alert(1)</script>',
            ]])
            ->get(route('master.lokasi-rak'));

        $html = $response->getContent();

        // The raw text must not contain a tag the parser could act on, and the
        // `</script>` that would have ended the block early is escaped.
        $this->assertStringNotContainsString('</script><script>alert(1)', $html);
        $this->assertStringContainsString('<script type="application/json" id="flash-toast">', $html);

        // What the client reads is still the message the controller wrote.
        $this->assertSame('</script><script>alert(1)</script>', $this->payloadIn($html)['message']);
    }

    #[Test]
    public function a_page_with_nothing_flashed_carries_no_block_at_all(): void
    {
        $response = $this->actingAs($this->owner())->get(route('master.lokasi-rak'));

        $response->assertOk();
        $this->assertStringNotContainsString('id="flash-toast"', $response->getContent());
    }

    #[Test]
    public function the_toast_stack_the_layout_renders_is_still_where_it_was(): void
    {
        $response = $this->actingAs($this->owner())->get(route('master.lokasi-rak'));

        $response->assertOk();
        // The reader is new; the container the rows render into is not, and the
        // 43 call sites that push to `$store.toast` still resolve against it.
        $this->assertStringContainsString('$store.toast.items', $response->getContent());
        $this->assertStringContainsString('aria-live="polite"', $response->getContent());
    }
}
