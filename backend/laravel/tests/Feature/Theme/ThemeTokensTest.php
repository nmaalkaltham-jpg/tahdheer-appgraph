<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Admin\AdminRole;
use App\Domain\Theme\ThemeCss;
use App\Domain\Theme\ThemeDocument;
use App\Domain\Theme\ThemeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * رموز الاستوديو عبر خطّ الثيم القائم (STUDIO-PHASE2-CONTRACT §2.8، C27):
 * كتالوج مُغلق بقواعد نوع لكل قيمة، بثّ داخل ‎:root:root‎، بصمة تعارض
 * baseEtag ⇒ 409، لقطات theme_versions باسترجاع حقيقي، وVERSION 2 يقصف
 * الكاش ذاتيًّا — فئة العطل الموثَّقة: CSS قديم يُقدَّم ساعة بلا خطأ.
 */
class ThemeTokensTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const THEME = '/api/v1/admin/theme';

    public function test_tokens_persist_compile_into_double_root_and_reach_the_public_css(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $document = ThemeDocument::defaults();
        $document['tokens'] = ['green-700' => '#0B6B4F', 'radius-card' => 20];

        $this->putJson(self::THEME, ['document' => $document])->assertOk();

        $css = ThemeRepository::css();
        $this->assertStringStartsWith(':root:root{', $css);
        $this->assertStringContainsString('--green-700: #0b6b4f', $css);   // hex يُخفَّض قانونيًّا
        $this->assertStringContainsString('--radius-card: 20px', $css);

        // نفس CSS يبلغ المسار العام — لا مُترجِم ثانيًا.
        $public = $this->getJson('/api/v1/theme')->assertOk();
        $this->assertStringContainsString('--green-700: #0b6b4f', (string) $public->json('data.css'));
    }

    public function test_values_are_clamped_and_hostile_input_never_reaches_css(): void
    {
        $document = ThemeDocument::normalize(['tokens' => [
            'radius-card' => 999,                                  // يُقنص إلى 48
            'radius-sm' => -5,                                     // يُقنص إلى 0
            'green-700' => 'javascript:alert(1)',                  // ليس hex — يسقط
            'ink' => 'red; } body { display:none } .x {',          // حقن — يسقط
            'shadow-card' => '0 2px 8px rgba(0, 0, 0, 0.08)',      // نحو ظلّ مشروع
            'shadow-float' => 'url(https://evil.example) 0 0 #fff', // url — يسقط
            'not-a-token' => '#ffffff',                            // خارج الكتالوج — يسقط
        ]]);

        $tokens = $document['tokens'];
        $this->assertSame(48.0, $tokens['radius-card']);
        $this->assertSame(0.0, $tokens['radius-sm']);
        $this->assertSame('0 2px 8px rgba(0, 0, 0, 0.08)', $tokens['shadow-card']);
        $this->assertArrayNotHasKey('green-700', $tokens);
        $this->assertArrayNotHasKey('ink', $tokens);
        $this->assertArrayNotHasKey('shadow-float', $tokens);
        $this->assertArrayNotHasKey('not-a-token', $tokens);

        $css = ThemeCss::compile($document);
        $this->assertStringNotContainsString('javascript:', $css);
        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('url(', $css);
    }

    public function test_absent_token_means_inherit_deleting_the_entry_restores_defaults(): void
    {
        ThemeRepository::save(['tokens' => ['green-700' => '#012345']]);
        $this->assertStringContainsString('--green-700: #012345', ThemeRepository::css());

        // «إعادة للموروث» = حذف المدخل — لا كتابة قيمة مضادّة.
        ThemeRepository::save(['tokens' => []]);
        $this->assertStringNotContainsString('--green-700', ThemeRepository::css());
    }

    /**
     * الرموز والطباعة تسكنان وثيقة واحدة، ولوحان في الاستوديو يحرّرانهما معًا.
     *
     * الاختبار يثبّت القانون الذي يبني عليه العميل: الحفظ يستبدل الوثيقة
     * **كاملةً**، فمن يرسل نصفها يمحو نصفها الآخر. ولهذا يبسط
     * `mergedDocument` الوثيقةَ الخادمية قبل التعديل — والسطر الأخير هنا هو
     * بالضبط العطب الذي كان سيقع لولا ذلك.
     */
    public function test_tokens_and_typography_share_one_document_and_erase_each_other_if_sent_alone(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $document = ThemeDocument::defaults();
        $roleId = (string) array_key_first($document['roles']);

        // وثيقة تحمل النصفين معًا — وهي ما يرسله الاستوديو.
        $document['tokens'] = ['green-700' => '#123456'];
        $document['roles'][$roleId]['weight'] = 800;

        $this->putJson(self::THEME, ['document' => $document])->assertOk();

        $this->getJson(self::THEME)
            ->assertOk()
            ->assertJsonPath('data.document.tokens.green-700', '#123456')
            ->assertJsonPath("data.document.roles.{$roleId}.weight", 800);

        // النصف وحده يمحو الآخر: حفظٌ بلا مفتاح tokens يُسقط الرمز المطبَّق.
        $partial = ThemeDocument::defaults();
        $partial['roles'][$roleId]['weight'] = 300;
        $this->putJson(self::THEME, ['document' => $partial])->assertOk();

        $this->getJson(self::THEME)
            ->assertOk()
            ->assertJsonPath("data.document.roles.{$roleId}.weight", 300)
            ->assertJsonMissingPath('data.document.tokens.green-700');
    }

    public function test_a_stale_base_etag_yields_409_with_the_current_state(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $etag = $this->getJson(self::THEME)->assertOk()->json('data.etag');

        // زميل يحفظ بعد قراءتنا — بصمتنا تشيخ.
        ThemeRepository::save(['tokens' => ['green-700' => '#0b6b4f']]);

        $document = ThemeDocument::defaults();
        $document['tokens'] = ['green-700' => '#123456'];

        $this->putJson(self::THEME, ['document' => $document, 'baseEtag' => $etag])
            ->assertStatus(409)
            ->assertJsonPath('code', 'THEME_CONFLICT')
            ->assertJsonStructure(['data' => ['document', 'css', 'etag']]);

        // عمل الزميل لم يُسحق.
        $this->assertStringContainsString('--green-700: #0b6b4f', ThemeRepository::css());

        // وبالبصمة الجارية يمرّ الحفظ نفسه.
        $current = ThemeRepository::compiled()['etag'];
        $this->putJson(self::THEME, ['document' => $document, 'baseEtag' => $current])->assertOk();
        $this->assertStringContainsString('--green-700: #123456', ThemeRepository::css());
    }

    public function test_versions_snapshot_with_baseline_first_and_restore_round_trips(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // أول حفظ بعد الترحيل: لقطة baseline لما قبل الحفظ ثم لقطة الحفظ.
        $docA = ThemeDocument::defaults();
        $docA['tokens'] = ['green-700' => '#0b6b4f'];
        $this->putJson(self::THEME, ['document' => $docA])->assertOk();

        $this->assertSame(
            ['baseline', 'publish'],
            DB::table('theme_versions')->orderBy('id')->pluck('kind')->all(),
        );

        // حفظ ثانٍ يغيّر القيمة.
        $docB = ThemeDocument::defaults();
        $docB['tokens'] = ['green-700' => '#222222'];
        $this->putJson(self::THEME, ['document' => $docB])->assertOk();
        $this->assertStringContainsString('--green-700: #222222', ThemeRepository::css());

        $versions = $this->getJson(self::THEME.'/versions')->assertOk()->json('data');
        $this->assertCount(3, $versions);

        // استرجاع لقطة الحفظ الأول — المتجر يعود إلى قيمتها فعلًا.
        $snapshotA = collect($versions)->firstWhere('kind', 'publish');
        // الأحدث أولًا: أول publish في القائمة هو docB؛ نأخذ الأقدم.
        $publishRows = array_values(array_filter($versions, static fn (array $v): bool => $v['kind'] === 'publish'));
        $oldest = end($publishRows);

        $this->postJson(self::THEME.'/restore', ['id' => $oldest['id']])->assertOk();
        $this->assertStringContainsString('--green-700: #0b6b4f', ThemeRepository::css());

        // الاسترجاع نفسه لقطة kind='restore' — يمكن التراجع عن التراجع.
        $this->assertSame(1, DB::table('theme_versions')->where('kind', 'restore')->count());
        $this->assertNotNull($snapshotA);
    }

    public function test_restore_of_a_pruned_version_is_a_422_not_a_wipe(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->postJson(self::THEME.'/restore', ['id' => 12345])
            ->assertStatus(422);
    }

    public function test_snapshots_prune_to_the_newest_fifty(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            ThemeRepository::save(['tokens' => ['radius-sm' => $i % 20]]);
        }

        $this->assertSame(ThemeRepository::VERSIONS_KEEP, DB::table('theme_versions')->count());
    }

    public function test_typography_studio_requests_without_base_etag_stay_valid(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // طلب استوديو التيبوغرافيا القائم — بلا baseEtag وبلا tokens: يمرّ كما كان.
        $this->putJson(self::THEME, ['document' => ['roles' => ['price' => ['color' => '#b42318']]]])
            ->assertOk();
        $this->assertStringContainsString('--tp-price-color: #b42318', ThemeRepository::css());
    }
}
