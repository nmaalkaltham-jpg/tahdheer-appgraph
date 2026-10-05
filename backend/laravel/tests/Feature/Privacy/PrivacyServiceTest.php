<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Domain\Privacy\Models\AnonymizationRecord;
use App\Domain\Privacy\Models\LegalHold;
use App\Domain\Privacy\PrivacyService;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * حقوق صاحب البيانات (§١٠٦).
 *
 * الاختبار المركزيّ هنا هو التوتّر نفسه: «احذف بياناتي» و«احتفظ بالفواتير»
 * أمران قانونيّان متعارضان، والحلّ إخفاءُ الهويّة لا الحذف. ما يُثبت ذلك
 * اختبارٌ واحد: بعد المحو يبقى المبلغ والضريبة ولا يبقى اسمٌ ولا بريد.
 */
class PrivacyServiceTest extends TestCase
{
    use RefreshDatabase;

    private function privacy(): PrivacyService
    {
        return $this->app->make(PrivacyService::class);
    }

    private function customerWithOrder(): array
    {
        $n = uniqid();
        $customer = Customer::create([
            'email' => "{$n}@test.local", 'name' => 'هشام', 'phone' => '0500000000',
        ]);
        $product = Product::create([
            'sku' => "S-{$n}", 'slug' => "p-{$n}", 'title' => 'ملفّ',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $order = Order::create([
            'number' => "TD-{$n}", 'customer_id' => $customer->id, 'email' => $customer->email,
            'phone' => '0500000000', 'status' => 'PAID',
            'total_minor' => 11500, 'vat_minor' => 1500,
            'tax_org_name' => 'مؤسّسة', 'tax_vat_number' => '300000000000003',
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => 'ملفّ', 'price_snapshot_minor' => 11500,
        ]);

        return [$customer, $order];
    }

    public function test_erasure_removes_the_person_and_keeps_the_financial_record(): void
    {
        [$customer, $order] = $this->customerWithOrder();

        $result = $this->privacy()->erase($customer);

        $this->assertTrue($result['anonymized']);

        $fresh = DB::table('orders')->where('id', $order->id)->first();

        /*
         * هذا هو بيت القصيد: سجلٌّ ماليٌّ كامل بلا شخص. الحذف الكامل كان
         * يخالف نظام الفوترة، ورفضُ الحذف يخالف حقّ المحو.
         */
        $this->assertSame(11500, (int) $fresh->total_minor);
        $this->assertSame(1500, (int) $fresh->vat_minor);
        $this->assertSame(PrivacyService::REDACTED, $fresh->email);
        $this->assertNull($fresh->phone);
        $this->assertNull($fresh->tax_vat_number);
    }

    public function test_the_customer_row_survives_so_the_order_keeps_its_link(): void
    {
        [$customer, $order] = $this->customerWithOrder();

        $this->privacy()->erase($customer);

        // حذفُ الصفّ كان سيقطع الطلب عن صاحبه فيفقد السجلّ الماليّ تماسكه.
        $fresh = DB::table('customers')->where('id', $customer->id)->first();
        $this->assertNotNull($fresh);
        $this->assertSame(PrivacyService::REDACTED, $fresh->name);
        $this->assertStringContainsString('@invalid.local', $fresh->email);
        $this->assertSame($customer->id, (int) DB::table('orders')->where('id', $order->id)->value('customer_id'));
    }

    public function test_a_legal_hold_blocks_erasure_and_says_why(): void
    {
        [$customer] = $this->customerWithOrder();

        LegalHold::create([
            'subject_type' => 'customer', 'subject_id' => $customer->id,
            'reason' => 'نزاعٌ قائم حول طلبٍ مستردّ', 'reference' => 'CASE-7',
        ]);

        $result = $this->privacy()->erase($customer);

        /*
         * الامتثال لطلب محوٍ أثناء نزاعٍ جارٍ إتلافُ دليل. والرفض يُعلَّل
         * لصاحب البيانات ولا يُصمَت عنه.
         */
        $this->assertFalse($result['anonymized']);
        $this->assertStringContainsString('نزاعٌ قائم', (string) $result['reason']);
        $this->assertSame('هشام', DB::table('customers')->where('id', $customer->id)->value('name'));
    }

    public function test_a_released_hold_no_longer_blocks(): void
    {
        [$customer] = $this->customerWithOrder();
        $hold = LegalHold::create([
            'subject_type' => 'customer', 'subject_id' => $customer->id, 'reason' => 'تحقّق',
        ]);
        $hold->update(['released_at' => now(), 'release_note' => 'أُغلق التحقيق']);

        $this->assertTrue($this->privacy()->erase($customer)['anonymized']);
    }

    public function test_the_erasure_itself_is_recorded(): void
    {
        [$customer] = $this->customerWithOrder();

        $this->privacy()->erase($customer);

        /*
         * يبقى **أنّ المحو وقع**: بغيره يستحيل إثبات الامتثال، ويبدو الطلب
         * المجهول الاسم عطلًا في البيانات لا قرارًا نُفِّذ.
         */
        $log = AnonymizationRecord::query()->firstOrFail();
        $this->assertSame('customer', $log->subject_type);
        $this->assertContains('email', $log->fields_cleared);
        $this->assertStringContainsString('نظام الفوترة', (string) $log->retained_for);
    }

    public function test_an_export_contains_the_order_history_and_consents(): void
    {
        [$customer] = $this->customerWithOrder();
        $this->privacy()->recordConsent($customer, 'MARKETING_EMAIL', true, 'v3', 'CHECKOUT');

        $export = $this->privacy()->export($customer);

        $this->assertSame($customer->email, $export['subject']['email']);
        $this->assertCount(1, $export['orders']);
        $this->assertCount(1, $export['consents']);
    }

    public function test_withdrawing_consent_adds_a_record_and_keeps_the_old_one(): void
    {
        [$customer] = $this->customerWithOrder();
        $privacy = $this->privacy();

        $privacy->recordConsent($customer, 'MARKETING_EMAIL', true, 'v3', 'CHECKOUT');
        $this->assertTrue($privacy->hasConsent($customer, 'MARKETING_EMAIL'));

        $privacy->recordConsent($customer, 'MARKETING_EMAIL', false, 'v3', 'ACCOUNT');

        /*
         * السحب حدثٌ جديد لا حذفٌ للقديم: السجلّ دليلُ امتثال، ومحوُه يُفقد
         * إثبات أنّ الإذن كان قائمًا يوم أُرسلت الرسالة.
         */
        $this->assertFalse($privacy->hasConsent($customer, 'MARKETING_EMAIL'));
        $this->assertSame(2, DB::table('consent_records')->where('customer_id', $customer->id)->count());
    }

    public function test_absence_of_consent_is_not_consent(): void
    {
        [$customer] = $this->customerWithOrder();

        // ما لم يُؤذَن به صراحةً ممنوع — والغياب ليس إذنًا.
        $this->assertFalse($this->privacy()->hasConsent($customer, 'MARKETING_SMS'));
    }

    public function test_a_request_carries_the_deadline_it_was_given(): void
    {
        [$customer] = $this->customerWithOrder();

        $request = $this->privacy()->openRequest($customer->email, 'DELETION', $customer);

        $this->assertSame('IDENTITY_PENDING', $request->status);
        $this->assertSame(
            PrivacyService::RESPONSE_DAYS,
            (int) round($request->received_at->diffInDays($request->due_at)),
        );
        $this->assertFalse($request->isOverdue());
    }

    public function test_a_request_cannot_be_completed_without_verifying_identity(): void
    {
        [$customer] = $this->customerWithOrder();
        $request = $this->privacy()->openRequest($customer->email, 'EXPORT', $customer);

        /*
         * تسليمُ بيانات شخصٍ لمن ادّعى أنّه هو خرقٌ أسوأ من التأخّر في الردّ.
         * القيد في المحرّك لا في الإرشادات.
         */
        $this->expectException(QueryException::class);
        $request->update(['status' => 'COMPLETED', 'completed_at' => now()]);
    }

    public function test_a_refusal_must_state_its_reason(): void
    {
        [$customer] = $this->customerWithOrder();
        $request = $this->privacy()->openRequest($customer->email, 'DELETION', $customer);

        // النظام يمنح صاحب البيانات حقّ معرفة لماذا رُفض طلبه.
        $this->expectException(QueryException::class);
        $request->update(['status' => 'REFUSED']);
    }

    public function test_reviews_stay_published_without_their_author(): void
    {
        [$customer] = $this->customerWithOrder();
        $product = Product::query()->firstOrFail();

        DB::table('reviews')->insert([
            'product_id' => $product->id, 'customer_id' => $customer->id,
            'rating' => 5, 'body' => 'ممتاز',
            'status' => 'approved', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->privacy()->erase($customer);

        /*
         * المراجعة لا تحمل اسمَ كاتبٍ أصلًا بل تُنسب عبر `customer_id`،
         * فطمسُ صفّ الزبون يُفقدها كاتبَها تلقائيًّا. والمحتوى يبقى منشورًا:
         * رأيٌ عن منتجٍ لا بياناتُ شخص.
         */
        $review = DB::table('reviews')->where('customer_id', $customer->id)->first();
        $this->assertSame('ممتاز', $review->body);
        $this->assertSame(PrivacyService::REDACTED, DB::table('customers')->where('id', $customer->id)->value('name'));
    }
}
