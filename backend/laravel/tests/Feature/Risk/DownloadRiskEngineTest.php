<?php

declare(strict_types=1);

namespace Tests\Feature\Risk;

use App\Domain\Risk\DownloadRiskEngine;
use App\Domain\Risk\Models\RiskAssessment;
use App\Models\Customer;
use App\Models\DownloadEvent;
use App\Models\DownloadGrant;
use App\Models\Entitlement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * تقييم خطر التنزيل (§١٠٣ §١٠٥).
 *
 * الاختبارات هنا عن **ضبط النفس** بقدر ما هي عن الكشف: نظامٌ يمنع بناءً على
 * تغيّر عنوان IP يمنع المسافرين وأصحاب الهواتف قبل أن يمنع لصًّا واحدًا،
 * فيدفع في الدعم والسمعة أكثر ممّا يوفّر.
 */
class DownloadRiskEngineTest extends TestCase
{
    use RefreshDatabase;

    private function engine(): DownloadRiskEngine
    {
        return $this->app->make(DownloadRiskEngine::class);
    }

    private function entitlement(array $overrides = []): Entitlement
    {
        $n = uniqid();
        $customer = Customer::create(['email' => "{$n}@test.local", 'name' => 'زبون']);
        $product = Product::create([
            'sku' => "S-{$n}", 'slug' => "p-{$n}", 'title' => 'ملفّ',
            'status' => 'published', 'fulfillment_type' => 'digital',
        ]);
        $order = Order::create([
            'number' => "TD-{$n}", 'customer_id' => $customer->id, 'email' => $customer->email,
            'status' => 'PAID', 'total_minor' => 5000,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id, 'product_id' => $product->id,
            'title_snapshot' => 'ملفّ', 'price_snapshot_minor' => 5000,
        ]);

        return Entitlement::create($overrides + [
            'customer_id' => $customer->id, 'order_id' => $order->id, 'order_item_id' => $item->id,
            'product_id' => $product->id, 'granted_at' => now(), 'status' => 'ACTIVE',
        ]);
    }

    private function completedDownloads(Entitlement $e, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DownloadEvent::create([
                'entitlement_id' => $e->id, 'event_type' => 'completed', 'created_at' => now()->subMinutes($i),
            ]);
        }
    }

    private function grantsFrom(Entitlement $e, int $distinctIps): void
    {
        for ($i = 0; $i < $distinctIps; $i++) {
            DownloadGrant::create([
                'entitlement_id' => $e->id,
                'token_hash' => hash('sha256', uniqid()),
                'expires_at' => now()->addDay(),
                'ip' => "10.0.0.{$i}",
            ]);
        }
    }

    public function test_ordinary_usage_is_allowed_with_no_signals(): void
    {
        $decision = $this->engine()->assess($this->entitlement(), '10.0.0.1', 'UA');

        $this->assertSame('ALLOW', $decision->decision);
        $this->assertSame([], $decision->signals);
        $this->assertSame(0, $decision->score);
    }

    public function test_an_exhausted_declared_limit_is_the_only_automatic_denial(): void
    {
        $entitlement = $this->entitlement(['download_limit' => 3, 'download_count' => 3]);

        $decision = $this->engine()->assess($entitlement, '10.0.0.1', 'UA');

        /*
         * منعٌ واحدٌ آليّ، وهو تنفيذُ عقدٍ لا اتّهامُ أحد: الزبون اشترى عددًا
         * محدّدًا ويعرفه.
         */
        $this->assertTrue($decision->blocks());
        $this->assertSame('limit_exhausted', $decision->signals[0]->code);
    }

    public function test_a_download_burst_raises_review_but_never_blocks(): void
    {
        $entitlement = $this->entitlement();
        $this->completedDownloads($entitlement, 70);

        $decision = $this->engine()->assess($entitlement, '10.0.0.1', 'UA');

        $this->assertFalse($decision->blocks());
        $this->assertContains($decision->decision, ['CHALLENGE', 'MANUAL_REVIEW']);
    }

    public function test_many_addresses_alone_never_blocks_a_paying_customer(): void
    {
        $entitlement = $this->entitlement();
        $this->grantsFrom($entitlement, 14);

        $decision = $this->engine()->assess($entitlement, '10.0.0.1', 'UA');

        /*
         * عائلةٌ على شبكاتٍ مختلفة وموظّفٌ ينتقل بين مكتبٍ وبيت يُنتجان العدد
         * نفسه. الإشارة ترفع الدرجة ولا تقطع الوصول.
         */
        $this->assertFalse($decision->blocks());
        $this->assertSame('many_addresses', $decision->signals[0]->code);
    }

    public function test_a_few_addresses_are_not_a_signal_at_all(): void
    {
        $entitlement = $this->entitlement();
        $this->grantsFrom($entitlement, 3);

        // ثلاثةُ عناوين سلوكٌ عاديّ تمامًا؛ عدُّها إشارةً يُغرق الطابور.
        $this->assertSame('ALLOW', $this->engine()->assess($entitlement, '10.0.0.1', 'UA')->decision);
    }

    public function test_the_assessment_stores_what_was_measured_not_just_a_score(): void
    {
        $entitlement = $this->entitlement();
        $this->completedDownloads($entitlement, 25);

        $this->engine()->assess($entitlement, '10.0.0.1', 'UA');
        $stored = RiskAssessment::query()->latest('id')->firstOrFail();

        // «لماذا مُنع هذا الزبون؟» سؤالٌ له جواب بعد شهر.
        $this->assertSame('download_burst', $stored->signals[0]['code']);
        $this->assertSame(25, $stored->signals[0]['measured']['count']);
    }

    public function test_the_raw_ip_is_never_stored(): void
    {
        $entitlement = $this->entitlement();
        $this->engine()->assess($entitlement, '203.0.113.42', 'Mozilla/5.0');

        $stored = RiskAssessment::query()->latest('id')->firstOrFail();

        /*
         * المقارنة المطلوبة «أهو العنوان نفسه؟» لا «ما هو العنوان؟»،
         * والتجزئة تكفي لها وتُسقط الاحتفاظ بمعرّفٍ شخصيّ (§١٠٦).
         */
        $this->assertNotSame('203.0.113.42', $stored->ip_hash);
        $this->assertSame(hash('sha256', '203.0.113.42'), $stored->ip_hash);
        $this->assertStringNotContainsString('203.0.113.42', (string) json_encode($stored->toArray()));
    }

    public function test_anything_raised_for_review_enters_the_review_queue(): void
    {
        $entitlement = $this->entitlement(['download_limit' => 1, 'download_count' => 1]);
        $this->engine()->assess($entitlement, '10.0.0.1', 'UA');

        // بلا ذلك تختفي الحالات المرفوعة ولا يعرف أحدٌ أنّ عليه النظر فيها.
        $this->assertSame(1, RiskAssessment::query()->awaitingReview()->count());
    }

    public function test_a_denial_without_a_written_reason_is_refused_by_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('risk_assessments')->insert([
            'subject_type' => 'download', 'decision' => 'DENY', 'score' => 100,
            'signals' => json_encode([]), 'review_outcome' => 'PENDING', 'created_at' => now(),
        ]);
    }
}
