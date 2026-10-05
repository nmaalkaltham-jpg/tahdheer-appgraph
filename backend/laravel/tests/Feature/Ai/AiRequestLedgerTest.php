<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Admin\AdminRole;
use App\Domain\Ai\AiRequestLedger;
use App\Domain\Ai\Jobs\ProductAiEnrichmentJob;
use App\Domain\Ai\Models\AiRun;
use Database\Seeders\AiPromptSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * دفتر نداءات الذكاء — الدورة الكاملة من الجدولة إلى الإلغاء والإعادة.
 *
 * ─── العطل الذي وُلد منه هذا الملفّ ───
 *
 * الواجهة كانت تنادي `/admin/ai/requests` منذ أن كُتبت، ولها نوعٌ مكتوب
 * (`AiRequestRow`)، والمسار غير موجود — فيرى المشغّل ٤٠٤ في وجهه. وقد بُني
 * الخادم باسم `runs` فاختلف الطرفان بلا أن يكشفه اختبار.
 *
 * فالاختبار الأوّل هنا يحرس المسار المنشور نفسه: أيّ إعادة تسميةٍ تكسره.
 */
class AiRequestLedgerTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AiPromptSeeder::class);
        config(['ai.enabled' => true, 'ai.features.product_enrichment' => true]);
    }

    public function test_a_row_carries_every_field_the_typed_contract_declares(): void
    {
        $product = $this->makeProduct('REQ-1');

        AiRun::query()->create([
            'task' => 'classify', 'prompt_id' => 'product.classify', 'prompt_version' => 1,
            'model' => 'm', 'provider' => 'local', 'entity_type' => 'product', 'entity_id' => $product->id,
            'input_hash' => str_repeat('a', 64), 'status' => 'SUCCEEDED', 'latency_ms' => 900,
            'input_units' => 100, 'output_units' => 40,
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $row = $this->getJson('/api/v1/admin/ai/requests')->assertOk()->json('data.0');

        foreach ([
            'id', 'provider', 'model', 'task', 'field', 'productId', 'actorEmail', 'promptVersion',
            'status', 'errorCode', 'attempts', 'inputTokens', 'outputTokens', 'costMinor',
            'latencyMs', 'suggestions', 'createdAt',
        ] as $field) {
            $this->assertArrayHasKey($field, $row, "الحقل «{$field}» في عقد الواجهة وغائبٌ عن الخادم.");
        }

        $this->assertSame('ok', $row['status']);
        /*
         * الكلفة `null` لا صفر: النموذج محلّيّ بلا سعرٍ مُعلَن للرمز، وصفرٌ
         * هنا يُقرأ «مجّانيّ ومقيس» وهو ادّعاءٌ لا سند له.
         */
        $this->assertNull($row['costMinor']);
    }

    public function test_a_rejected_output_reads_as_failed_to_the_operator(): void
    {
        AiRun::query()->create([
            'task' => 'classify', 'prompt_id' => 'p', 'prompt_version' => 1, 'model' => 'm',
            'input_hash' => str_repeat('b', 64), 'status' => 'REJECTED', 'error_code' => 'AI_OUTPUT_INVALID',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // «رُفض خرجه» فشلٌ في عين المشغّل: نداءٌ لم يُنتج شيئًا صالحًا.
        $this->assertSame('failed', $this->getJson('/api/v1/admin/ai/requests?status=failed')->assertOk()->json('data.0.status'));
    }

    // ── الدورة ──────────────────────────────────────────────────────────

    public function test_queueing_creates_a_visible_row_before_anything_runs(): void
    {
        Queue::fake();
        $product = $this->makeProduct('REQ-2');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $row = $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'product', 'entityId' => $product->id])
            ->assertStatus(202)
            ->json('data');

        /*
         * الصفّ يُحجز عند الجدولة لا بعد التنفيذ: مهمّةٌ لا صفَّ لها لا تُرى
         * ولا تُلغى، والمشغّل الذي جدول مئة تحليلٍ ثمّ ندم لا يملك إلّا إطفاء
         * الذكاء كلّه.
         */
        $this->assertSame('QUEUED', $row['lifecycle']);
        $this->assertDatabaseHas('ai_runs', ['id' => $row['id'], 'status' => 'QUEUED']);
        Queue::assertPushed(ProductAiEnrichmentJob::class);
    }

    public function test_a_queued_request_can_be_cancelled_and_then_never_runs(): void
    {
        Queue::fake();
        $product = $this->makeProduct('REQ-3');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $id = $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'product', 'entityId' => $product->id])
            ->json('data.id');

        $this->postJson("/api/v1/admin/ai/requests/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.lifecycle', 'CANCELLED');

        /*
         * والمهمّة تحترم الإلغاء: `start()` تحديثٌ شرطيّ ذرّيّ يفشل على صفٍّ
         * ملغًى، فتخرج المهمّة بلا أن تحجز مقعد استدلال.
         */
        $this->assertFalse(app(AiRequestLedger::class)->start((int) $id));
    }

    public function test_a_running_request_is_not_cancellable(): void
    {
        $run = AiRun::query()->create([
            'task' => 'classify', 'prompt_id' => 'p', 'prompt_version' => 0, 'model' => 'm',
            'entity_type' => 'product', 'entity_id' => 1,
            'input_hash' => str_repeat('c', 64), 'status' => 'RUNNING',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // الحساب وقع فعلًا؛ قطعُه لا يوفّر شيئًا ويترك المقعد محجوزًا.
        $this->postJson("/api/v1/admin/ai/requests/{$run->id}/cancel")->assertStatus(422);
    }

    public function test_retry_reschedules_the_same_entity_and_counts_the_attempt(): void
    {
        Queue::fake();
        $product = $this->makeProduct('REQ-4');

        $failed = AiRun::query()->create([
            'task' => 'classify', 'prompt_id' => 'product.classify', 'prompt_version' => 1, 'model' => 'm',
            'entity_type' => 'product', 'entity_id' => $product->id,
            'input_hash' => str_repeat('d', 64), 'status' => 'FAILED', 'error_code' => 'AI_TIMEOUT',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $retry = $this->postJson("/api/v1/admin/ai/requests/{$failed->id}/retry")->assertStatus(202)->json('data');

        $this->assertSame(2, $retry['attempts']);
        $this->assertDatabaseHas('ai_runs', ['id' => $retry['id'], 'retry_of_id' => $failed->id]);
    }

    public function test_a_request_with_no_entity_cannot_be_retried_and_says_why(): void
    {
        $orphan = AiRun::query()->create([
            'task' => 'answer_with_context', 'prompt_id' => 'assistant.answer', 'prompt_version' => 2,
            'model' => 'm', 'input_hash' => str_repeat('e', 64), 'status' => 'FAILED',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * المدخل لا يُخزَّن نصًّا — بصمته وحدها، سياسةَ خصوصيّة. فنداءٌ بلا
         * كيانٍ لا يمكن إعادة بنائه، والسبب يُقال بدل زرٍّ يفشل بصمت.
         */
        $this->postJson("/api/v1/admin/ai/requests/{$orphan->id}/retry")
            ->assertStatus(422)
            ->assertJsonValidationErrors('request');

        $blocker = $this->getJson("/api/v1/admin/ai/requests/{$orphan->id}")->assertOk()->json('data.retryBlocker');
        $this->assertStringContainsString('خصوصيّة', $blocker);
    }

    public function test_a_successful_request_is_not_retryable(): void
    {
        $ok = AiRun::query()->create([
            'task' => 'classify', 'prompt_id' => 'p', 'prompt_version' => 1, 'model' => 'm',
            'entity_type' => 'product', 'entity_id' => 1,
            'input_hash' => str_repeat('f', 64), 'status' => 'SUCCEEDED',
        ]);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson("/api/v1/admin/ai/requests/{$ok->id}/retry")->assertStatus(422);
    }

    // ── الحراسة ─────────────────────────────────────────────────────────

    public function test_reading_the_ledger_requires_audit_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->getJson('/api/v1/admin/ai/requests')->assertStatus(403);
    }

    public function test_queueing_requires_products_update(): void
    {
        $product = $this->makeProduct('REQ-5');
        // دورٌ يقرأ الطلبات لا يجدول عملًا يستهلك معالجًا.
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'product', 'entityId' => $product->id])->assertStatus(403);
    }

    public function test_queueing_is_refused_while_the_feature_is_off(): void
    {
        config(['ai.enabled' => false]);
        $product = $this->makeProduct('REQ-6');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // لا جدولةَ عملٍ لميزةٍ مطفأة: يملأ الطابور بما سيُرفض.
        $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'product', 'entityId' => $product->id])
            ->assertStatus(503);
    }

    public function test_an_unschedulable_entity_type_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // القائمة مغلقة: ما لا مهمّةَ تنفّذه لا يُجدول.
        $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'order', 'entityId' => 1])
            ->assertStatus(422);
    }

    public function test_a_missing_product_is_404_not_a_queued_ghost(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->postJson('/api/v1/admin/ai/requests', ['entityType' => 'product', 'entityId' => 999999])->assertStatus(404);
    }
}
