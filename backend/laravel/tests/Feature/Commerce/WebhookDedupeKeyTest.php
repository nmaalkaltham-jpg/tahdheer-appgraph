<?php

namespace Tests\Feature\Commerce;

use App\Domain\Payments\WebhookEvent;
use App\Domain\Payments\WebhookIntake;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * هويّة التسليم عند المزوّد.
 *
 * Tap يضع معرّف **الرسم** في `id`، لا معرّف الحدث. فقراءته هويّةً للحدث
 * كانت تجعل «مصرَّح» ثمّ «مقبوض» لرسمٍ واحد تسليمًا واحدًا — ويُهمَل الثاني
 * بوصفه مكرّرًا، فيُقبض المال ولا يُموَّل الطلب.
 */
class WebhookDedupeKeyTest extends TestCase
{
    use RefreshDatabase;

    private function tapEvent(string $status): WebhookEvent
    {
        return new WebhookEvent(
            provider: 'tap', chargeId: 'chg_tap_1', orderNumber: 'TD-X',
            succeeded: $status === 'CAPTURED',
            payload: ['id' => 'chg_tap_1', 'status' => $status, 'reference' => ['order' => 'TD-X']],
        );
    }

    public function test_two_statuses_of_one_charge_are_two_deliveries(): void
    {
        $intake = app(WebhookIntake::class);

        $first = $intake->record($this->tapEvent('AUTHORIZED'), true);
        $second = $intake->record($this->tapEvent('CAPTURED'), true);

        $this->assertFalse($first['duplicate']);
        $this->assertFalse($second['duplicate']);
        $this->assertNotSame($first['delivery']->id, $second['delivery']->id);
    }

    public function test_the_same_status_of_one_charge_is_one_delivery(): void
    {
        $intake = app(WebhookIntake::class);

        $intake->record($this->tapEvent('CAPTURED'), true);
        $again = $intake->record($this->tapEvent('CAPTURED'), true);

        $this->assertTrue($again['duplicate']);
    }

    public function test_a_real_event_id_still_dedupes(): void
    {
        $intake = app(WebhookIntake::class);
        $event = new WebhookEvent('tap', 'chg_2', 'TD-Y', true, ['event_id' => 'evt_9', 'status' => 'CAPTURED']);

        $intake->record($event, true);
        $this->assertTrue($intake->record($event, true)['duplicate']);
    }
}
