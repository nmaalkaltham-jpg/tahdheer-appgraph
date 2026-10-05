<?php

declare(strict_types=1);

namespace Tests\Feature\ContentIntelligence;

use App\Domain\ContentIntelligence\Entities\EntityAgreement;
use App\Domain\ContentIntelligence\Entities\EntityCandidate;
use App\Domain\ContentIntelligence\Entities\EntityDerivationService;
use App\Domain\ContentIntelligence\Entities\EntityDimension;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Subject;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * الاشتقاق يقترح ويشهد ويقارن — ولا يكتب في الفهرس أبدًا.
 *
 * النصّ المستعمل هنا منسوخٌ حرفيًّا من `asset_extractions#3` في قاعدة
 * التطوير (`svg_native_text`, `status=ok`, قياس ٢٠٢٦-٠٩-٢٣). ليس نصًّا
 * مخترعًا يوافق الشيفرة: هو ما يخرج فعلًا من غلاف معاينةٍ حقيقيّ.
 */
class EntityDerivationTest extends TestCase
{
    use RefreshDatabase;

    /** نصّ استخراجٍ حقيقيّ — `asset_extractions#3`، تطوير. */
    private const REAL_SVG_TEXT = "تحضير الرياضيات — الصف السادس الابتدائي — الفصل الدراسي الأول — غلاف معاينة للتصميم\n"
        ."تحضير\n"
        ."الفصل الدراسي الأول\n"
        ."الرياضيات\n"
        ."الصف السادس الابتدائي\n"
        ."DOCX · قابل للتعديل\n"
        ."86 صفحة\n"
        ."DOCX\n"
        ."قابل للتعديل\n"
        ."التحاضير الحديثة\n"
        .'غلاف معاينة للتصميم';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurriculumSeeder::class);
    }

    public function test_derives_the_five_dimensions_from_a_real_extraction(): void
    {
        $extraction = $this->extraction(self::REAL_SVG_TEXT);

        $proposals = (new EntityDerivationService)->propose($extraction);

        $this->assertSame(
            ['subject', 'grade', 'semester', 'kind', 'format'],
            array_values(array_intersect(EntityDimension::values(), array_keys($proposals))),
            'كلّ بُعدٍ من الخمسة له مرشَّح',
        );

        $this->assertSame(Subject::query()->where('slug', 'الرياضيات')->value('id'), $proposals['subject']['entity_id']);
        $this->assertSame(Grade::query()->where('slug', 'سادس-ابتدائي')->value('id'), $proposals['grade']['entity_id']);
        $this->assertSame(Semester::query()->where('slug', 'الفصل-الأول')->value('id'), $proposals['semester']['entity_id']);
        $this->assertSame(Kind::query()->where('slug', 'تحضير')->value('id'), $proposals['kind']['entity_id']);

        // الصيغة وحدها بلا معرّف — لا جدولَ لها، والقيد في القاعدة يفرض ذلك.
        $this->assertNull($proposals['format']['entity_id']);
        $this->assertSame('DOCX', $proposals['format']['entity_value']);
    }

    public function test_evidence_is_a_substring_at_the_byte_offset_it_claims(): void
    {
        $extraction = $this->extraction(self::REAL_SVG_TEXT);

        foreach ((new EntityDerivationService)->propose($extraction) as $dimension => $proposal) {
            $this->assertStringStartsWith(
                $proposal['evidence_text'],
                substr(self::REAL_SVG_TEXT, $proposal['evidence_offset']),
                "الشاهد لا يقع عند الموضع الذي يدّعيه: {$dimension}",
            );
        }
    }

    /**
     * سطران متطابقان في ملفٍّ واحد: الموضع يجب أن يكون موضع الأوّل بعينه.
     *
     * لو حُسبت المواضع بـ`strpos` لأعطت الجواب نفسه هنا بالصدفة — لذلك
     * يُفحص السطر **الثاني** أيضًا: المرشَّح يُنسب إلى الأوّل، والتأييد
     * اثنان لا واحد.
     */
    public function test_repeated_lines_are_counted_once_each_and_anchored_to_the_first(): void
    {
        $text = "حشو\nالرياضيات\nحشو آخر\nالرياضيات";
        $extraction = $this->extraction($text);

        $subject = (new EntityDerivationService)->propose($extraction)['subject'];

        $this->assertSame(strpos($text, 'الرياضيات'), $subject['evidence_offset']);
        $this->assertSame(2, $subject['support'], 'السطران يُحسبان تأييدين');
        $this->assertSame(0, $subject['conflicts']);
    }

    public function test_never_writes_to_the_product(): void
    {
        $product = $this->product(['subject_id' => Subject::query()->where('slug', 'العلوم')->value('id')]);
        $extraction = $this->extraction(self::REAL_SVG_TEXT, $this->linkedAsset($product));

        $before = $product->fresh()->getAttributes();
        (new EntityDerivationService)->run(true);
        $after = $product->fresh()->getAttributes();

        $this->assertSame($before, $after, 'المنتج لم يُمسّ ولو بحقلٍ واحد');
        $this->assertGreaterThan(0, EntityCandidate::query()->count(), 'ومع ذلك كُتب مرشَّحون');
    }

    public function test_records_disagreement_without_correcting_the_catalog(): void
    {
        $wrong = Subject::query()->where('slug', 'العلوم')->value('id');
        $product = $this->product(['subject_id' => $wrong]);
        $this->extraction(self::REAL_SVG_TEXT, $this->linkedAsset($product));

        (new EntityDerivationService)->run(true);

        $candidate = EntityCandidate::query()->where('dimension', 'subject')->sole();
        $this->assertSame(EntityAgreement::Disagree->value, $candidate->agreement);
        $this->assertSame($wrong, $candidate->catalog_entity_id);
        $this->assertSame(Subject::query()->where('slug', 'الرياضيات')->value('id'), $candidate->entity_id);
        $this->assertSame($wrong, $product->fresh()->subject_id, 'الفهرس بقي على خطئه — يقرّره إنسان');
    }

    public function test_agrees_when_the_catalog_says_the_same_thing(): void
    {
        $product = $this->product(['subject_id' => Subject::query()->where('slug', 'الرياضيات')->value('id')]);
        $this->extraction(self::REAL_SVG_TEXT, $this->linkedAsset($product));

        (new EntityDerivationService)->run(true);

        $this->assertSame(
            EntityAgreement::Agree->value,
            EntityCandidate::query()->where('dimension', 'subject')->sole()->agreement,
        );
    }

    public function test_separates_an_empty_catalog_field_from_a_wrong_one(): void
    {
        $product = $this->product(['subject_id' => null, 'resource_type' => null]);
        $this->extraction(self::REAL_SVG_TEXT, $this->linkedAsset($product));

        (new EntityDerivationService)->run(true);

        $this->assertSame(
            EntityAgreement::CatalogMissing->value,
            EntityCandidate::query()->where('dimension', 'subject')->sole()->agreement,
        );
    }

    public function test_marks_an_orphan_asset_as_having_no_catalog_at_all(): void
    {
        $this->extraction(self::REAL_SVG_TEXT, $this->asset());

        (new EntityDerivationService)->run(true);

        $this->assertSame(
            EntityAgreement::NoProduct->value,
            EntityCandidate::query()->where('dimension', 'subject')->sole()->agreement,
        );
        $this->assertNull(EntityCandidate::query()->where('dimension', 'subject')->sole()->product_id);
    }

    public function test_conflicting_lines_lower_confidence_and_keep_the_loser(): void
    {
        // «الرياضيات» مرّتان و«العلوم» مرّة: الأغلبيّة تفوز، والخاسر يبقى
        // مسجَّلًا كي يُفسَّر رقمُ الثقة.
        $extraction = $this->extraction("الرياضيات\nالعلوم\nالرياضيات");

        $subject = (new EntityDerivationService)->propose($extraction)['subject'];

        $this->assertSame(Subject::query()->where('slug', 'الرياضيات')->value('id'), $subject['entity_id']);
        $this->assertSame(2, $subject['support']);
        $this->assertSame(1, $subject['conflicts']);
        $this->assertSame(0.667, $subject['confidence']);
        $this->assertSame(
            [Subject::query()->where('slug', 'العلوم')->value('id')],
            array_column($subject['alternatives'], 'entity_id'),
        );
    }

    public function test_rerunning_updates_in_place_instead_of_piling_up(): void
    {
        $this->extraction(self::REAL_SVG_TEXT, $this->asset());
        $service = new EntityDerivationService;

        $service->run(true);
        $first = EntityCandidate::query()->orderBy('id')->pluck('id')->all();
        $service->run(true);

        $this->assertSame($first, EntityCandidate::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_ignores_extractions_that_did_not_succeed(): void
    {
        $this->extraction(self::REAL_SVG_TEXT, $this->asset(), ExtractionStatus::CapabilityGap);

        $report = (new EntityDerivationService)->run(true);

        $this->assertSame(0, $report['extractions']);
        $this->assertSame(0, EntityCandidate::query()->count());
    }

    public function test_database_refuses_a_format_candidate_that_carries_an_entity_id(): void
    {
        // العيب مبنيٌّ عمدًا: لو سقط القيد لمرّ هذا الصفّ صامتًا.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/entity_candidates_format_has_no_id_check/');

        DB::table('entity_candidates')->insert([
            'asset_sha256' => str_repeat('a', 64), 'extractor' => 'probe',
            'dimension' => 'format', 'entity_id' => 7, 'entity_value' => 'PDF',
            'evidence_text' => 'PDF', 'evidence_offset' => 0,
            'support' => 1, 'conflicts' => 0, 'confidence' => 1,
            'agreement' => 'agree', 'derivation_version' => 'probe', 'derived_at' => now(),
        ]);
    }

    // ── مساعدات ──────────────────────────────────────────────────────────

    private function extraction(string $text, ?int $assetId = null, ?ExtractionStatus $status = null): AssetExtraction
    {
        return AssetExtraction::query()->create([
            'asset_id' => $assetId,
            'asset_sha256' => hash('sha256', $text),
            'extractor' => 'svg_native_text',
            'extractor_version' => '1',
            'status' => ($status ?? ExtractionStatus::Ok)->value,
            'text' => $text,
            'units' => substr_count($text, "\n") + 1,
        ]);
    }

    private function asset(): int
    {
        return (int) DB::table('assets')->insertGetId([
            'provider' => 'local', 'provider_ref' => 'test/'.uniqid(),
            'original_name' => 'preview.svg', 'sha256' => hash('sha256', uniqid()),
            'size_bytes' => 1024, 'mime' => 'image/svg+xml', 'visibility' => 'public_sample',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function linkedAsset(Product $product): int
    {
        $assetId = $this->asset();
        DB::table('product_files')->insert([
            'product_id' => $product->id, 'asset_id' => $assetId,
            'role' => 'sample', 'position' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $assetId;
    }

    /** @param  array<string, mixed>  $attributes */
    private function product(array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'TEST-'.strtoupper(uniqid()),
            'slug' => 'test-'.uniqid(),
            'title' => 'منتج اختبار',
            'status' => 'draft',
        ], $attributes));
    }
}
