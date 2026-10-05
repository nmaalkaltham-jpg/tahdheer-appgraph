<?php

namespace Tests\Feature\Commerce;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Operations\Jobs\ReplayWebhookDeliveryJob;
use App\Domain\Operations\Models\OperationRecord;
use App\Domain\Operations\OperationLedger;
use App\Domain\Payments\FakeProvider;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\WebhookDelivery;
use App\Domain\Payments\WebhookIntake;
use App\Http\Middleware\AdminAuthenticate;
use App\Models\Entitlement;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * الخطّاف يُحفظ قبل أن يُعالَج، ويُعاد تشغيله بأمان.
 *
 * كلّ اختبارٍ هنا يقابل خسارةً صامتة كانت ممكنة حين كان الحدث يُعالَج ولا
 * يُحفظ: دفعةٌ تقع ولا يعلم بها المتجر، وحمولةٌ تضيع فلا يبقى ما يُصلَح به.
 */
class WebhookDurabilityTest extends TestCase
{
    /*
     * `AdminFixtures` غير مستعمل هنا: يتصادم مع `CommerceFixtures` في
     * `setUp` و`makeProduct` معًا، وحلُّ التصادم بالاستبعاد كان سيخفي أيّ
     * تصادمٍ ثالث يُضاف لاحقًا. الحاجة هنا دالّتان فقط، فتُكتبان صراحةً.
     */
    use CommerceFixtures;
    use RefreshDatabase;

    private const ADMIN_PASSWORD = 'كلمةُ مرورٍ طويلةٌ للاختبار ١٢٣٤';

    private function admin(AdminRole $role): AdminUser
    {
        return AdminUser::query()->create([
            'email' => str_replace('_', '-', $role->value).'@localhost',
            'name' => 'مشغّل الاختبار',
            'password_hash' => Hash::make(self::ADMIN_PASSWORD),
            'role' => $role->value,
        ]);
    }

    /** تسجيل دخولٍ حقيقيّ: كوكي الجلسة ورمز الحماية كما يصدرهما الخادم. */
    private function asAdmin(AdminRole $role): static
    {
        $admin = $this->admin($role);
        unset($this->defaultCookies[config('session.cookie')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::ADMIN_PASSWORD,
        ])->assertOk();

        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withHeader(AdminAuthenticate::CSRF_HEADER, (string) $response->json('csrfToken'));

        return $this;
    }

    /** @return array{order: Order, token: string} */
    private function awaitingOrder(string $sku): array
    {
        $cookie = $this->cartWith([$this->makeProduct($sku, 40000)]);

        return $this->checkoutOrder($cookie);
    }

    private function deliver(Order $order, string $chargeId, string $status = 'CAPTURED', array $extra = []): TestResponse
    {
        // الاتّحاد يُبقي اليسار: الإضافات على اليسار وإلّا أُهملت بصمت.
        $body = json_encode($extra + [
            'charge_id' => $chargeId,
            'order_number' => $order->number,
            'status' => $status,
        ], JSON_THROW_ON_ERROR);

        return $this->call(
            'POST', '/api/v1/payments/webhook/fake',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign($body)],
            content: $body,
        );
    }

    // ── الحفظ ────────────────────────────────────────────────────────────

    public function test_a_delivery_is_stored_with_its_payload(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-001');

        $this->deliver($order, 'chg_d1')->assertOk()->assertJsonPath('status', 'PROCESSED');

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('fake', $delivery->provider);
        $this->assertTrue($delivery->signature_verified);
        $this->assertTrue($delivery->succeeded);
        $this->assertSame($order->number, $delivery->order_number);
        // الحمولة كما وصلت: هي ما يُعاد تشغيله لاحقًا.
        $this->assertSame('chg_d1', $delivery->payload['charge_id']);
        $this->assertNotNull($delivery->processed_at);
    }

    public function test_a_forged_signature_stores_nothing(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-002');

        $body = json_encode(['charge_id' => 'x', 'order_number' => $order->number, 'status' => 'CAPTURED']);
        $this->call('POST', '/api/v1/payments/webhook/fake',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => 'forged'],
            content: (string) $body,
        )->assertStatus(401);

        /*
         * حفظُ الموقَّع الفاسد يجعل الجدول مسربًا لأيّ مجهولٍ يعرف العنوان،
         * ويُتيح «إعادة تشغيل» حدثٍ لم يُصدره أحد.
         */
        $this->assertSame(0, WebhookDelivery::query()->count());
    }

    public function test_the_same_event_twice_is_stored_once(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-003');

        $this->deliver($order, 'chg_d3')->assertOk()->assertJsonPath('duplicate', false);
        $this->deliver($order, 'chg_d3')->assertOk()->assertJsonPath('duplicate', true);

        // المزوّدون يعيدون الإرسال بالتصميم — عشر مرّاتٍ أمرٌ عاديّ.
        $this->assertSame(1, WebhookDelivery::query()->count());
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
    }

    public function test_a_declined_event_is_stored_as_ignored(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-004');

        $this->deliver($order, 'chg_d4', status: 'DECLINED')->assertOk()->assertJsonPath('ignored', true);

        // «لماذا لم يُحسب هذا الحدث؟» سؤالٌ يجب أن يبقى له جواب.
        $this->assertSame('IGNORED', WebhookDelivery::query()->firstOrFail()->status);
    }

    public function test_an_event_for_an_unknown_order_is_stored_as_unmatched(): void
    {
        $body = json_encode(['charge_id' => 'chg_x', 'order_number' => 'TD-NOPE', 'status' => 'CAPTURED']);

        $this->call('POST', '/api/v1/payments/webhook/fake',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign((string) $body)],
            content: (string) $body,
        )->assertOk()->assertJsonPath('status', 'UNMATCHED');

        /*
         * دفعةٌ بلا طلبٍ مطابق ليست فشلًا: الطلب قد يكون في الطريق. تُحفظ
         * ليُعاد تشغيلها حين يظهر — وكانت تُهمل بلا أثرٍ إطلاقًا.
         */
        $this->assertSame('UNMATCHED', WebhookDelivery::query()->firstOrFail()->status);
    }

    public function test_two_charges_on_one_order_are_two_deliveries(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-006');

        $this->deliver($order, 'chg_a')->assertOk();
        $this->deliver($order, 'chg_b')->assertOk();

        $this->assertSame(2, WebhookDelivery::query()->count());
    }

    // ── إعادة التشغيل ────────────────────────────────────────────────────

    public function test_replay_requires_the_payments_permission(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-007');
        $this->deliver($order, 'chg_d7');
        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->asAdmin(AdminRole::Analytics);
        $this->postJson("/api/v1/admin/payments/webhooks/{$delivery->id}/replay")->assertStatus(403);
    }

    public function test_replay_enqueues_once_per_generation(): void
    {
        Queue::fake();
        ['order' => $order] = $this->awaitingOrder('TD-D-008');
        $this->deliver($order, 'chg_d8');
        $delivery = WebhookDelivery::query()->firstOrFail();

        $this->asAdmin(AdminRole::Owner);

        $this->postJson("/api/v1/admin/payments/webhooks/{$delivery->id}/replay")
            ->assertStatus(202)->assertJsonPath('duplicate', false);

        // النقرة المكرّرة لا تُرسل مهمّةً ثانية.
        $this->postJson("/api/v1/admin/payments/webhooks/{$delivery->id}/replay")
            ->assertOk()->assertJsonPath('duplicate', true);

        Queue::assertPushed(ReplayWebhookDeliveryJob::class, 1);
    }

    public function test_replay_of_a_processed_event_creates_no_second_payment(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-009');
        $this->deliver($order, 'chg_d9');
        $delivery = WebhookDelivery::query()->firstOrFail();

        $record = OperationRecord::create([
            'idempotency_key' => 'webhook_replay:'.$delivery->id.':0',
            'kind' => 'webhook_replay', 'subject_type' => 'webhook_delivery',
            'subject_id' => $delivery->id, 'status' => 'RESERVED',
        ]);

        (new ReplayWebhookDeliveryJob($record->id, $delivery->id))->handle(
            $this->app->make(OperationLedger::class),
            $this->app->make(WebhookIntake::class),
        );

        /*
         * الأمان من `OrderFinalizer` لا من المهمّة: مفتاحه فريدٌ في المحرّك،
         * فإعادةُ حدثٍ عولج سلفًا تُعيد «مكرّر» ولا تُنشئ دفعةً ثانية.
         */
        $this->assertSame('SUCCEEDED', $record->fresh()->status);
        $this->assertFalse($record->fresh()->result['applied']);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, Entitlement::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, (int) $delivery->fresh()->replay_count);
    }

    public function test_replay_settles_an_unmatched_event_once_the_order_exists(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-010');

        // الحدث يصل باسم طلبٍ لا يُطابق — كما لو سبق الحدثُ الطلب.
        $body = json_encode(['charge_id' => 'chg_d10', 'order_number' => 'TD-LATE', 'status' => 'CAPTURED']);
        $this->call('POST', '/api/v1/payments/webhook/fake',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => FakeProvider::sign((string) $body)],
            content: (string) $body,
        )->assertOk();

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('UNMATCHED', $delivery->status);

        // يظهر الطلب لاحقًا بالرقم نفسه.
        $order->update(['number' => 'TD-LATE']);

        $record = OperationRecord::create([
            'idempotency_key' => 'webhook_replay:'.$delivery->id.':0',
            'kind' => 'webhook_replay', 'subject_type' => 'webhook_delivery',
            'subject_id' => $delivery->id, 'status' => 'RESERVED',
        ]);

        (new ReplayWebhookDeliveryJob($record->id, $delivery->id))->handle(
            $this->app->make(OperationLedger::class),
            $this->app->make(WebhookIntake::class),
        );

        $this->assertSame('PROCESSED', $delivery->fresh()->status);
        /* رقميٌّ بحت ⇒ يكتمل في المعاملة نفسها. `paid_at` محفوظ. */
        $this->assertSame('COMPLETED', $order->fresh()->status);
    }

    public function test_the_admin_list_reports_failures_and_unmatched(): void
    {
        ['order' => $order] = $this->awaitingOrder('TD-D-011');
        $this->deliver($order, 'chg_d11');

        $this->asAdmin(AdminRole::Owner);

        $this->getJson('/api/v1/admin/payments/webhooks')
            ->assertOk()
            ->assertJsonPath('data.0.provider', 'fake')
            ->assertJsonPath('data.0.status', 'PROCESSED')
            ->assertJsonPath('failed', 0)
            ->assertJsonPath('unmatched', 0);
    }

    public function test_replay_of_a_missing_delivery_is_404(): void
    {
        $this->asAdmin(AdminRole::Owner);
        $this->postJson('/api/v1/admin/payments/webhooks/999999/replay')->assertStatus(404);
    }
}
