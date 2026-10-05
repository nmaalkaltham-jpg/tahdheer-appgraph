<?php

namespace Tests\Feature\Seo;

use App\Domain\Redirects\NotFoundHit;
use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectRuleError;
use App\Domain\Redirects\RedirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** MIP §7.7 — single-hop guarantee and typed refusal of broken rules. */
class RedirectServiceTest extends TestCase
{
    use RefreshDatabase;

    private RedirectService $redirects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redirects = app(RedirectService::class);
    }

    public function test_an_exact_rule_matches_and_reports_its_status(): void
    {
        $this->redirects->save(['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301]);

        $match = $this->redirects->match('/old');

        $this->assertNotNull($match);
        $this->assertSame('/new', $match->target);
        $this->assertSame(301, $match->statusCode);
        $this->assertNull($this->redirects->match('/unrelated'));
    }

    public function test_a_trailing_wildcard_captures_and_substitutes_the_remainder(): void
    {
        $this->redirects->save(['from_path' => '/old-shop/*', 'to_path' => '/products/*']);

        $this->assertSame('/products/math-1', $this->redirects->match('/old-shop/math-1')->target);
        $this->assertSame('/products/a/b/c', $this->redirects->match('/old-shop/a/b/c')->target);
        // A wildcard must not leak across a sibling prefix.
        $this->assertNull($this->redirects->match('/old-shopping/x'));
    }

    public function test_a_wildcard_source_with_a_static_target_drops_the_capture(): void
    {
        $this->redirects->save(['from_path' => '/legacy/*', 'to_path' => '/stages']);

        $this->assertSame('/stages', $this->redirects->match('/legacy/anything/deep')->target);
    }

    public function test_the_longest_matching_wildcard_prefix_wins(): void
    {
        $this->redirects->save(['from_path' => '/old/*', 'to_path' => '/broad/*']);
        $this->redirects->save(['from_path' => '/old/deep/*', 'to_path' => '/narrow/*']);

        $this->assertSame('/narrow/x', $this->redirects->match('/old/deep/x')->target);
        $this->assertSame('/broad/other', $this->redirects->match('/old/other')->target);
    }

    public function test_a_self_redirect_is_refused_with_a_typed_error(): void
    {
        try {
            $this->redirects->save(['from_path' => '/same', 'to_path' => '/same']);
            $this->fail('كان يجب رفض التحويل إلى النفس');
        } catch (RedirectRuleError $e) {
            $this->assertSame(RedirectRuleError::SELF_REDIRECT, $e->errorCode);
        }

        $this->assertSame(0, RedirectRule::query()->count(), 'القاعدة المرفوضة لا تُحفَظ');
    }

    public function test_a_closed_loop_is_refused_with_a_typed_error(): void
    {
        $this->redirects->save(['from_path' => '/a', 'to_path' => '/b']);

        try {
            // /b → /a would close the loop /a → /b → /a.
            $this->redirects->save(['from_path' => '/b', 'to_path' => '/a']);
            $this->fail('كان يجب رفض الحلقة المغلقة');
        } catch (RedirectRuleError $e) {
            $this->assertSame(RedirectRuleError::LOOP, $e->errorCode);
            $this->assertStringContainsString('حلقة تحويل مغلقة', $e->getMessage());
        }

        $this->assertSame(1, RedirectRule::query()->count());
    }

    public function test_a_longer_loop_is_refused(): void
    {
        $this->redirects->save(['from_path' => '/a', 'to_path' => '/b']);
        $this->redirects->save(['from_path' => '/b', 'to_path' => '/c']);

        $this->expectException(RedirectRuleError::class);
        $this->redirects->save(['from_path' => '/c', 'to_path' => '/a']);
    }

    /**
     * SINGLE HOP: saving `/a → /b` when `/b → /c` already exists must store
     * `/a → /c`, so a request for /a returns exactly one 301.
     */
    public function test_a_chain_is_resolved_to_its_final_target_at_save_time(): void
    {
        $this->redirects->save(['from_path' => '/b', 'to_path' => '/c']);
        $rule = $this->redirects->save(['from_path' => '/a', 'to_path' => '/b']);

        $this->assertSame('/c', $rule->to_path, 'يُخزَّن الهدف النهائي لا الوسيط');
        $this->assertSame('/c', $this->redirects->match('/a')->target);
    }

    /**
     * The reverse direction: an existing rule pointing at the NEW source would
     * become a 2-hop chain, so it is collapsed onto the final target.
     */
    public function test_inbound_chains_are_collapsed_when_a_new_rule_lands(): void
    {
        $this->redirects->save(['from_path' => '/x', 'to_path' => '/y']);
        $this->redirects->save(['from_path' => '/y', 'to_path' => '/z']);

        $this->assertSame('/z', RedirectRule::query()->where('from_path', '/x')->value('to_path'));
        $this->assertSame('/z', $this->redirects->match('/x')->target);
    }

    public function test_collapsing_into_a_self_redirect_disables_the_rule_instead(): void
    {
        // /x → /y, then /y → /x would collapse /x onto itself.
        $this->redirects->save(['from_path' => '/x', 'to_path' => '/y']);

        $this->expectException(RedirectRuleError::class);
        $this->redirects->save(['from_path' => '/y', 'to_path' => '/x']);
    }

    public function test_a_target_known_to_be_a_404_is_refused(): void
    {
        $this->redirects->recordNotFound('/dead-end');

        try {
            $this->redirects->save(['from_path' => '/old', 'to_path' => '/dead-end']);
            $this->fail('كان يجب رفض التحويل إلى 404');
        } catch (RedirectRuleError $e) {
            $this->assertSame(RedirectRuleError::TARGET_NOT_FOUND, $e->errorCode);
        }
    }

    public function test_only_301_and_302_are_accepted(): void
    {
        $this->expectException(RedirectRuleError::class);
        $this->redirects->save(['from_path' => '/a', 'to_path' => '/b', 'status_code' => 307]);
    }

    public function test_a_wildcard_is_only_allowed_at_the_end_of_the_source(): void
    {
        $this->expectException(RedirectRuleError::class);
        $this->redirects->save(['from_path' => '/a/*/b', 'to_path' => '/c']);
    }

    public function test_a_duplicate_source_is_refused(): void
    {
        $this->redirects->save(['from_path' => '/a', 'to_path' => '/b']);

        $this->expectException(RedirectRuleError::class);
        $this->redirects->save(['from_path' => '/a', 'to_path' => '/c']);
    }

    public function test_a_disabled_rule_never_matches(): void
    {
        $rule = $this->redirects->save(['from_path' => '/a', 'to_path' => '/b']);
        $rule->update(['enabled' => false]);

        $this->assertNull($this->redirects->match('/a'));
    }

    public function test_not_found_hits_aggregate_instead_of_logging_a_row_per_request(): void
    {
        $this->redirects->recordNotFound('/missing', 'https://ref.test/a');
        $this->redirects->recordNotFound('/missing', 'https://ref.test/b');
        $this->redirects->recordNotFound('/missing');

        $this->assertDatabaseCount('not_found_hits', 1);

        $hit = NotFoundHit::query()->first();
        $this->assertSame(3, $hit->hits);
        $this->assertSame('https://ref.test/a', $hit->referrer, 'يُحفظ أول مُحيل لا الأحدث');
        $this->assertNotNull($hit->first_seen_at);
        $this->assertNotNull($hit->last_seen_at);
    }

    public function test_query_strings_and_trailing_slashes_normalise_to_one_aggregate_row(): void
    {
        $this->redirects->recordNotFound('/missing/');
        $this->redirects->recordNotFound('/missing?utm_source=x');

        $this->assertDatabaseCount('not_found_hits', 1);
        $this->assertSame(2, NotFoundHit::query()->value('hits'));
    }
}
