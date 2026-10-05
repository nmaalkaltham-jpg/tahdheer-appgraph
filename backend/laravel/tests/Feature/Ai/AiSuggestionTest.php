<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Domain\Admin\AdminRole;
use App\Domain\Ai\AiResponse;
use App\Domain\Ai\AiSuggestionStore;
use App\Domain\Ai\AiTask;
use App\Domain\Ai\Models\AiSuggestion;
use App\Domain\Ai\Product\SuggestionApplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * الاقتراح والاعتماد — الحدّ بين ما يقترحه النموذج وما يصير بياناتٍ.
 *
 * ─── القاعدة المحروسة ───
 *
 * «لا تستبدل ما كتبه التاجر تلقائيًّا» و«لا كتابات مدمّرة تلقائيّة». فالخرج
 * يُخزَّن مرشَّحًا، ولا يُكتب في منتجٍ إلّا باعتماد مشرفٍ مسمًّى، ولا يطمس حقلًا
 * ممتلئًا حتّى بعد الاعتماد.
 */
class AiSuggestionTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function response(): AiResponse
    {
        return new AiResponse(
            task: AiTask::Classify,
            promptId: 'product.classify',
            promptVersion: 1,
            model: 'test-model',
            text: '{}',
            confidence: 0.8,
        );
    }

    private function curriculumIds(): array
    {
        $c = $this->curriculum();

        return [
            'stage' => $c['stage']->name_ar,
            'grade' => $c['grade']->name_ar,
            'subject' => $c['subject']->name_ar,
        ];
    }

    // ── المستودع ────────────────────────────────────────────────────────

    public function test_a_suggestion_is_stored_pending_and_never_canonical(): void
    {
        $product = $this->makeProduct('SUG-1');

        $stored = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => 'ابتدائي'], $this->response(), str_repeat('a', 64),
        );

        $this->assertNotNull($stored);
        $this->assertSame('PENDING', $stored->status);
        /*
         * `AI_SUGGESTED` خارج `AttributeValue::CANONICAL_SOURCES` بالتعريف —
         * فلا يصير اقتراحُ آلةٍ حقيقةً قانونيّة بلا مراجعة (§٥٩، §١٦٠).
         */
        $this->assertSame('AI_SUGGESTED', $stored->source);
        $this->assertSame(80, $stored->confidence_pct);
    }

    public function test_the_same_input_does_not_produce_a_second_row(): void
    {
        $product = $this->makeProduct('SUG-2');
        $store = app(AiSuggestionStore::class);
        $hash = str_repeat('b', 64);

        $store->put('product', (int) $product->id, 'classification', AiTask::Classify, ['stage' => 'ابتدائي'], $this->response(), $hash);
        $store->put('product', (int) $product->id, 'classification', AiTask::Classify, ['stage' => 'ابتدائي'], $this->response(), $hash);

        // إعادة التشغيل على محتوًى لم يتغيّر لا تُري المشرف الاقتراح مرّتين.
        $this->assertSame(1, AiSuggestion::query()->count());
    }

    public function test_changed_content_supersedes_the_older_pending_suggestion(): void
    {
        $product = $this->makeProduct('SUG-3');
        $store = app(AiSuggestionStore::class);

        $old = $store->put('product', (int) $product->id, 'classification', AiTask::Classify, ['stage' => 'ابتدائي'], $this->response(), str_repeat('c', 64));
        $store->put('product', (int) $product->id, 'classification', AiTask::Classify, ['stage' => 'متوسط'], $this->response(), str_repeat('d', 64));

        /*
         * القديم عن نصٍّ لم يعد موجودًا. تركُه معلّقًا يجعل المشرف يعتمد وصفًا
         * لمنتجٍ تغيّر.
         */
        $this->assertSame('SUPERSEDED', $old->fresh()->status);
        $this->assertSame(1, AiSuggestion::query()->where('status', 'PENDING')->count());
    }

    public function test_an_empty_value_is_not_stored(): void
    {
        $product = $this->makeProduct('SUG-4');

        // قيمةٌ فارغة تملأ صندوق المشرف بأسطرٍ لا فعل لها.
        $this->assertNull(app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify, [], $this->response(), str_repeat('e', 64),
        ));
    }

    // ── التطبيق ─────────────────────────────────────────────────────────

    public function test_an_unapproved_suggestion_is_never_applied(): void
    {
        $product = $this->makeProduct('SUG-5');
        $names = $this->curriculumIds();

        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify, $names, $this->response(), str_repeat('f', 64),
        );

        $this->expectException(RuntimeException::class);
        app(SuggestionApplier::class)->apply($suggestion);
    }

    public function test_approval_writes_only_into_empty_fields(): void
    {
        $c = $this->curriculum();
        // المادّة مكتوبةٌ سلفًا بيد التاجر — لا تُطمس.
        $product = $this->makeProduct('SUG-6', ['subject_id' => $c['subject']->id]);

        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => $c['stage']->name_ar, 'grade' => $c['grade']->name_ar, 'subject' => $c['subject']->name_ar],
            $this->response(), str_repeat('g', 64),
        );

        $suggestion->forceFill(['status' => 'APPROVED', 'approved_by_admin_user_id' => 1, 'approved_at' => now()])->save();

        $applied = app(SuggestionApplier::class)->apply($suggestion);

        $this->assertSame('written', $applied['stage']);
        $this->assertSame('written', $applied['grade']);
        // الممتلئ يُترك ويُقال «تُرك» — فيرى المشرف ما جرى فعلًا.
        $this->assertSame('kept', $applied['subject']);

        $product->refresh();
        $this->assertSame($c['stage']->id, $product->stage_id);
        $this->assertSame($c['subject']->id, $product->subject_id);
    }

    public function test_a_name_with_no_row_is_reported_unresolved_not_written_as_null(): void
    {
        $product = $this->makeProduct('SUG-7');

        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => 'مرحلةٌ لا وجود لها'], $this->response(), str_repeat('h', 64),
        );
        $suggestion->forceFill(['status' => 'APPROVED', 'approved_by_admin_user_id' => 1, 'approved_at' => now()])->save();

        $applied = app(SuggestionApplier::class)->apply($suggestion);

        $this->assertSame('unresolved', $applied['stage']);
        $this->assertNull($product->fresh()->stage_id);
    }

    public function test_approved_keywords_become_the_products_own_search_keywords(): void
    {
        $product = $this->makeProduct('SUG-8');

        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'keywords', AiTask::Extract,
            ['synonyms' => ['ورق عمل', 'تمارين'], 'english' => ['worksheet']],
            $this->response(), str_repeat('i', 64),
        );
        $suggestion->forceFill(['status' => 'APPROVED', 'approved_by_admin_user_id' => 1, 'approved_at' => now()])->save();

        app(SuggestionApplier::class)->apply($suggestion);

        /*
         * كانت تُكتب مرادفاتٍ عامّة معلّقة مصطلحُها عنوانُ المنتج كاملًا —
         * والتوسيع يطابق كلمةً كلمة، فلا يطابقها سؤالٌ أبدًا ولو فُعّلت.
         * الآن تُضاف إلى كلمات المنتج نفسه (`product_seo.synonyms`): أثرُها
         * على منتجها وحده، فلا تمسّ نتائج غيره — وهو ما كان «المعلّق» يحمي منه.
         */
        $keywords = (string) DB::table('product_seo')->where('product_id', $product->id)->value('synonyms');
        foreach (['ورق عمل', 'تمارين', 'worksheet'] as $term) {
            $this->assertStringContainsString($term, $keywords);
        }
        $this->assertSame(0, DB::table('search_synonyms')->where('source', 'ai_suggested')->count());
    }

    // ── نقاط النهاية ────────────────────────────────────────────────────

    public function test_approving_requires_products_update(): void
    {
        $product = $this->makeProduct('SUG-9');
        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => 'ابتدائي'], $this->response(), str_repeat('j', 64),
        );

        // دورٌ يقرأ الطلبات لا يعدّل المنتجات.
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->postJson("/api/v1/admin/ai/suggestions/{$suggestion->id}/approve")->assertStatus(403);
    }

    public function test_a_suggestion_cannot_be_decided_twice(): void
    {
        $c = $this->curriculum();
        $product = $this->makeProduct('SUG-10');
        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => $c['stage']->name_ar], $this->response(), str_repeat('k', 64),
        );

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson("/api/v1/admin/ai/suggestions/{$suggestion->id}/approve")->assertOk();
        // القرار الثاني يُرفض: اعتمادٌ مكرّر كان يكتب مرّتين.
        $this->postJson("/api/v1/admin/ai/suggestions/{$suggestion->id}/approve")->assertStatus(422);
    }

    public function test_rejection_demands_a_reason(): void
    {
        $product = $this->makeProduct('SUG-11');
        $suggestion = app(AiSuggestionStore::class)->put(
            'product', (int) $product->id, 'classification', AiTask::Classify,
            ['stage' => 'ابتدائي'], $this->response(), str_repeat('l', 64),
        );

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // رفضٌ بلا سبب لا يُحسّن الموجّه — والأسباب هي مجموعة التقييم التالية.
        $this->postJson("/api/v1/admin/ai/suggestions/{$suggestion->id}/reject")->assertStatus(422);
        $this->postJson("/api/v1/admin/ai/suggestions/{$suggestion->id}/reject", ['reason' => 'المرحلة خاطئة'])->assertOk();

        $this->assertSame('REJECTED', $suggestion->fresh()->status);
    }

    public function test_enrichment_is_refused_while_the_feature_is_off(): void
    {
        config(['ai.enabled' => false]);
        $product = $this->makeProduct('SUG-12');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        /*
         * الرفض عند الجدولة لا عند التنفيذ: جدولةُ عملٍ لميزةٍ مطفأة تملأ
         * الطابور بما سيُرفض وتُعطي المشرف ٢٠٢ كاذبة.
         */
        $this->postJson("/api/v1/admin/products/{$product->id}/ai/enrich")
            ->assertStatus(503)
            ->assertJsonPath('code', 'AI_DISABLED');
    }
}
