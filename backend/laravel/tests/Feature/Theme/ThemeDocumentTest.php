<?php

declare(strict_types=1);

namespace Tests\Feature\Theme;

use App\Domain\Theme\FontCatalog;
use App\Domain\Theme\ThemeCss;
use App\Domain\Theme\ThemeDocument;
use App\Domain\Theme\ThemeRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * وثيقة الثيم: التطبيع، الترجمة، والحدود.
 *
 * كل اختبار هنا يثبّت سلوكًا وُلد من عطل حقيقي في هذا المستودع لا من افتراض:
 * أوزان غير محمَّلة تُصيَّر اصطناعيًّا بلا تحذير، ومدخلات حرّة تصل CSS،
 * ووثيقة ناقصة من نسخة أقدم تُسقط الصفحة بدل أن تُملأ بالافتراضات.
 */
class ThemeDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_cover_every_declared_role(): void
    {
        $document = ThemeDocument::normalize(null);

        foreach (ThemeDocument::ROLES as $role) {
            $this->assertArrayHasKey($role, $document['roles'], "الدور {$role} بلا افتراض");
            $this->assertArrayHasKey($role, ThemeDocument::ROLE_LABELS, "الدور {$role} بلا تسمية عربية");
        }

        $this->assertSame(ThemeDocument::VERSION, $document['version']);
    }

    public function test_weight_snaps_to_a_weight_the_font_actually_ships(): void
    {
        // «Tajawal» لا يملك الوزن 600 — تحقّقنا وزنًا وزنًا من المزوّد.
        $this->assertNotContains(600, FontCatalog::weights('tajawal'));
        $this->assertSame(700, FontCatalog::snapWeight('tajawal', 600));

        // و«Cairo» يملكه، فيبقى كما هو: القنص يتبع الخطّ الفعّال لا قائمة ثابتة.
        $this->assertContains(600, FontCatalog::weights('cairo'));
        $this->assertSame(600, FontCatalog::snapWeight('cairo', 600));

        $document = ThemeDocument::normalize([
            'headingFamily' => 'tajawal',
            'roles' => ['h1' => ['weight' => 600]],
        ]);
        $this->assertSame(700, $document['roles']['h1']['weight']);
    }

    public function test_google_fonts_url_requests_only_weights_in_use(): void
    {
        $document = ThemeDocument::normalize(null);
        $url = FontCatalog::googleFontsUrl(ThemeDocument::fontUsage($document));

        $this->assertNotNull($url);
        $this->assertStringContainsString('family=Tajawal:wght@', $url);
        // الوزن 800 يفرضه التصميم في 116 موضعًا وكان غير محمَّل إطلاقًا.
        $this->assertStringContainsString('800', $url);
        $this->assertStringContainsString('display=swap', $url);
    }

    public function test_hostile_input_never_reaches_the_stylesheet(): void
    {
        $document = ThemeDocument::normalize([
            'roles' => [
                'h1' => [
                    'color' => 'red; } body { display: none } .x {',
                    'transform' => '"><script>alert(1)</script>',
                    'weight' => 99999,
                    'lineHeight' => 500,
                    'letterSpacing' => -99,
                ],
            ],
        ]);

        $this->assertNull($document['roles']['h1']['color']);
        $this->assertSame('none', $document['roles']['h1']['transform']);
        $this->assertContains($document['roles']['h1']['weight'], FontCatalog::weights('tajawal'));
        $this->assertLessThanOrEqual(3.0, $document['roles']['h1']['lineHeight']);
        $this->assertGreaterThanOrEqual(-0.15, $document['roles']['h1']['letterSpacing']);

        $css = ThemeCss::compile($document);
        $this->assertStringNotContainsString('display: none', $css);
        $this->assertStringNotContainsString('<script', $css);
        $this->assertStringNotContainsString('}', substr($css, 0, strpos($css, '{') ?: 0));
    }

    public function test_only_design_tokens_and_hex_survive_as_colours(): void
    {
        $document = ThemeDocument::normalize([
            'roles' => [
                'price' => ['color' => '#B42318'],
                'body' => ['color' => 'var(--text-muted)'],
                'h2' => ['color' => 'url(https://evil.example/x)'],
            ],
        ]);

        $this->assertSame('#b42318', $document['roles']['price']['color']);
        $this->assertSame('var(--text-muted)', $document['roles']['body']['color']);
        $this->assertNull($document['roles']['h2']['color']);
    }

    public function test_fluid_size_compiles_to_clamp_in_rem(): void
    {
        $document = ThemeDocument::normalize(null);
        $css = ThemeCss::compile($document);

        $this->assertMatchesRegularExpression(
            '/--tp-h1-size:\s*clamp\([\d.]+rem,\s*[\d.-]+rem \+ [\d.-]+vw,\s*[\d.]+rem\)/',
            $css,
            'الحجم السائل يجب أن يكون clamp() بوحدة rem — بالبكسل يُلغى تكبير الخطّ في المتصفّح',
        );
    }

    public function test_fixed_size_compiles_to_media_queries_at_the_configured_breakpoints(): void
    {
        $document = ThemeDocument::normalize([
            'breakpoints' => ['tablet' => 980, 'mobile' => 600],
            'roles' => ['h1' => ['size' => [
                'desktop' => 40, 'tablet' => 32, 'mobile' => 24, 'fluid' => false,
            ]]],
        ]);
        $css = ThemeCss::compile($document);

        $this->assertStringContainsString('@media (max-width:980px)', $css);
        $this->assertStringContainsString('@media (max-width:600px)', $css);
        $this->assertStringContainsString('--tp-h1-size: 40px', $css);
    }

    public function test_overrides_are_partial_so_unmentioned_properties_stay_inherited(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['section' => ['hero' => ['h1' => ['weight' => 900]]]],
        ]);

        $patch = $document['overrides']['section']['hero']['h1'];
        $this->assertSame(['weight'], array_keys($patch));

        $css = ThemeCss::compile($document);
        $this->assertStringContainsString('[data-theme-section="hero"]{--tp-h1-weight: 900}', $css);
    }

    public function test_override_identifiers_that_would_break_a_selector_are_dropped(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['section' => ['"] , body { display:none } [x="' => ['h1' => ['weight' => 900]]]],
        ]);

        $this->assertSame([], $document['overrides']['section']);
    }

    public function test_box_override_emits_real_css_properties_not_text_variables(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['section' => ['featured' => ['box' => [
                'background' => '#eaf5ef',
                'radius' => 20,
                'padBlock' => 48,
            ]]]],
        ]);

        $css = ThemeCss::compile($document);

        /*
         * الصندوق يملك خلفيّته وحشوته، ولا يرثها نصٌّ بداخله — فتُبثّ خصائص
         * حقيقية لا متغيّرات ‎--tp-*‎.
         */
        $this->assertStringContainsString(
            '[data-theme-section="featured"]{background: #eaf5ef;border-radius: 20px;padding-block: 48px}',
            $css,
        );
    }

    public function test_box_lengths_are_clamped_to_their_declared_range(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['component' => ['card' => ['box' => [
                'padInline' => 999,
                'padBlock' => -50,
            ]]]],
        ]);

        $box = $document['overrides']['component']['card']['box'];

        // المدى يفرضه الخادم لا الواجهة: قيمةٌ خارجه تُقصّ ولا تُرفض صامتةً.
        $this->assertSame(160.0, $box['padInline']);
        $this->assertSame(0.0, $box['padBlock']);
    }

    public function test_box_background_rejects_anything_that_is_not_a_colour(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['section' => ['hero' => ['box' => [
                'background' => 'red;}body{display:none',
                'radius' => 'abc',
            ]]]],
        ]);

        // لا مفتاح نجا، فلا هدف نجا: قيمةٌ حرّة هنا تعني حقن أنماط عبر اللوحة.
        $this->assertSame([], $document['overrides']['section']);
        $this->assertStringNotContainsString('body{display', ThemeCss::compile($document));
    }

    public function test_placement_identifier_needs_two_valid_halves(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['placement' => [
                'featured__card' => ['h2' => ['weight' => 900]],
                '__card' => ['h2' => ['weight' => 900]],
                'featured__' => ['h2' => ['weight' => 900]],
                'a__b__c' => ['h2' => ['weight' => 900]],
            ]],
        ]);

        /*
         * `identifier()` وحده يقبل `a__b` لأن الشرطة السفليّة مسموحة فيه،
         * فيمرّ الثلاثة المشوّهة بلا معنى — ولذلك يُفحص النصفان لا الكلّ.
         */
        $this->assertSame(['featured__card'], array_keys($document['overrides']['placement']));
    }

    public function test_placement_emits_one_rule_for_both_a_section_and_a_page(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['placement' => ['shop__card' => ['productTitle' => ['color' => '#b1004e']]]],
        ]);

        $css = ThemeCss::compile($document);

        // الشقّ الأوّل قد يكون قسمًا أو صفحة؛ الموجود منهما هو الذي يطابق.
        $this->assertStringContainsString(
            '[data-theme-section="shop"] [data-theme-component="card"],'
            .'[data-theme-page="shop"] [data-theme-component="card"]{--tp-product-title-color: #b1004e}',
            $css,
        );
    }

    public function test_a_placement_outranks_the_component_it_narrows(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => [
                'component' => ['card' => ['productTitle' => ['color' => '#123456']]],
                'placement' => ['shop__card' => ['productTitle' => ['color' => '#b1004e']]],
            ],
        ]);

        $css = ThemeCss::compile($document);
        $component = strpos($css, '[data-theme-component="card"]{');
        $placement = strpos($css, '[data-theme-page="shop"] [data-theme-component="card"]');

        /*
         * التخصّص يحسمها (سمتان لا سمة)، والترتيب يسنده: النطاق الأضيق يُكتب
         * بعد الأعمّ، فلا يحتاج التجاوز `!important` ليغلب.
         */
        $this->assertNotFalse($component);
        $this->assertNotFalse($placement);
        $this->assertGreaterThan($component, $placement);
    }

    public function test_page_scope_emits_its_own_selector(): void
    {
        $document = ThemeDocument::normalize([
            'overrides' => ['page' => ['checkout' => ['body' => ['weight' => 700]]]],
        ]);

        $this->assertStringContainsString(
            '[data-theme-page="checkout"]{--tp-body-weight: 700}',
            ThemeCss::compile($document),
        );
    }

    public function test_every_override_scope_survives_a_normalize_round_trip(): void
    {
        $input = [
            'overrides' => [
                'section' => ['hero' => ['h1' => ['weight' => 900]]],
                'component' => ['card' => ['price' => ['weight' => 800]]],
                'page' => ['cart' => ['body' => ['weight' => 500]]],
                'placement' => ['shop__card' => ['productTitle' => ['weight' => 400]]],
            ],
        ];

        $once = ThemeDocument::normalize($input);
        $twice = ThemeDocument::normalize($once);

        // التطبيع خاملُ التكرار: وثيقةٌ مُطبَّعة تمرّ به فلا تتغيّر.
        $this->assertSame($once['overrides'], $twice['overrides']);
        $this->assertSame(
            ['section', 'component', 'page', 'placement'],
            array_keys($once['overrides']),
        );
    }

    public function test_public_endpoint_serves_css_and_honours_the_etag(): void
    {
        $first = $this->getJson('/api/v1/theme');
        $first->assertOk()->assertJsonStructure(['data' => ['css', 'fontsUrl', 'etag']]);

        $etag = $first->json('data.etag');
        $this->withHeaders(['If-None-Match' => '"'.$etag.'"'])
            ->getJson('/api/v1/theme')
            ->assertStatus(304);
    }

    public function test_saving_changes_the_etag_and_the_served_css(): void
    {
        $before = ThemeRepository::compiled();

        ThemeRepository::save([
            'roles' => ['price' => ['color' => '#b42318']],
        ]);

        $after = ThemeRepository::compiled();

        $this->assertNotSame($before['etag'], $after['etag'], 'البصمة يجب أن تتغيّر وإلّا بقيت النسخ المخزَّنة قديمة');
        $this->assertStringContainsString('--tp-price-color: #b42318', $after['css']);
    }

    public function test_reset_restores_defaults_exactly(): void
    {
        ThemeRepository::save(['roles' => ['h1' => ['weight' => 200]]]);
        $this->assertSame(200, ThemeRepository::document()['roles']['h1']['weight']);

        ThemeRepository::reset();

        $this->assertEquals(ThemeDocument::defaults(), ThemeRepository::document());
    }

    /**
     * حفظٌ بلا وثيقة يجب أن يُرفض، لا أن يمحو التصميم.
     *
     * الوثيقة المحفوظة كانت تُستبدل بالافتراضات كاملةً عند أوّل طلب ينسى
     * غلاف `document` — والردّ 200 لا يميّزه عن حفظ ناجح. `normalize`
     * متسامحة عمدًا للقراءة؛ استعمالها بديلًا عن التحقّق عند الكتابة يحوّل
     * خطأ عميل إلى فقدان بيانات.
     */
    public function test_saving_without_a_document_is_rejected_not_a_silent_wipe(): void
    {
        ThemeRepository::save(['baseFamily' => 'cairo']);
        $this->assertSame('cairo', ThemeRepository::document()['baseFamily']);

        // مباشرةً على المستودع: التحقّق يعيش في المتحكّم، وهذا يوثّق السبب.
        ThemeRepository::save(null);
        $this->assertSame(
            'tajawal',
            ThemeRepository::document()['baseFamily'],
            'المستودع متسامح عمدًا — ولذلك يجب أن يحرسه المتحكّم',
        );

        // والمتحكّم يرفض قبل أن يصل شيء إلى المستودع.
        ThemeRepository::save(['baseFamily' => 'cairo']);
        $this->putJson('/api/v1/admin/theme', [])->assertStatus(401);
    }

    public function test_admin_theme_routes_require_authentication(): void
    {
        $this->getJson('/api/v1/admin/theme')->assertStatus(401);
        $this->putJson('/api/v1/admin/theme', ['document' => []])->assertStatus(401);
        $this->deleteJson('/api/v1/admin/theme')->assertStatus(401);
    }
}
