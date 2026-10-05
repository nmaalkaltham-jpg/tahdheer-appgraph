<?php

declare(strict_types=1);

namespace App\Domain\Search\Evaluation;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\Extractors\OfficeOpenXmlExtractor;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * كتالوجٌ اصطناعيّ من التصنيف نفسه — القياسُ لا يعتمد على منتجاتٍ بعينها.
 *
 * منتجان لكلّ مادّة، بصفٍّ ونوعٍ وفصلٍ مختلفين، فيتنافسان في الترتيب:
 * سؤالٌ عن أحدهما يجب أن يضعه فوق الآخر. التوزيع حتميّ من المعرّفات.
 *
 * يُستعمل داخل معاملةٍ تُلغى (`search:eval --synthetic`) أو قاعدة اختبار
 * (`SearchQualityGateTest`). ويرفض الإنتاج: هذا كتابةٌ في جدول المنتجات.
 */
final class SyntheticCatalog
{
    public const SKU_PREFIX = 'SYN-';

    /**
     * درسٌ حقيقيّ لكلّ منتج — نصُّ «الملفّ» لا العنوان. يُكتب صفَّ استخراجٍ
     * `ok` كما يكتبه `OfficeOpenXmlExtractor`، فتقيس المجموعةُ الذهبيّة
     * (فئة content_only) البحثَ داخل الملفّات على كتالوجٍ بلا ملفّاتٍ مخزّنة.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LESSONS = [
        'لغتي' => ['الأسماء الموصولة', 'همزة القطع'],
        'اللغة العربية' => ['الممنوع من الصرف', 'أسلوب الشرط'],
        'الكفايات اللغوية' => ['التحرير الكتابي', 'علامات الترقيم'],
        'الرياضيات' => ['الكسور العشرية', 'المعادلات الخطية'],
        'العلوم' => ['البناء الضوئي', 'الدورة الدموية'],
        'الفيزياء' => ['قوانين نيوتن', 'الموجات الصوتية'],
        'الكيمياء' => ['الجدول الدوري', 'الروابط التساهمية'],
        'الأحياء' => ['الانقسام المتساوي', 'الجهاز العصبي'],
        'الدراسات الإسلامية' => ['أركان الإسلام', 'صلاة الجماعة'],
        'القرآن الكريم' => ['أحكام النون الساكنة', 'المد الطبيعي'],
        'الدراسات الاجتماعية' => ['توحيد المملكة', 'التضاريس والمناخ'],
        'اللغة الإنجليزية' => ['present simple', 'irregular verbs'],
        'المهارات الرقمية' => ['قواعد البيانات', 'البرمجة بلغة بايثون'],
        'المهارات الحياتية' => ['إدارة الوقت', 'التغذية الصحية'],
        'التفكير الناقد' => ['المغالطات المنطقية', 'الاستدلال الاستقرائي'],
        'التربية الفنية' => ['الألوان الأساسية', 'الزخرفة الإسلامية'],
        'التربية البدنية' => ['كرة الطائرة', 'اللياقة القلبية'],
        'علم البيئة' => ['التلوث البيئي', 'السلاسل الغذائية'],
    ];

    /**
     * @return int عدد المنتجات المُنشأة
     */
    public function build(): int
    {
        if (app()->environment('production')) {
            throw new RuntimeException('SyntheticCatalog writes products; it never runs in production.');
        }

        $subjects = Subject::query()->orderBy('id')->get(['id', 'name_ar']);
        $grades = Grade::query()->orderBy('id')->get(['id', 'stage_id', 'name_ar'])->values();
        $kinds = Kind::query()->orderBy('id')->pluck('slug')->values();
        $semesters = Semester::query()->orderBy('id')->get(['id', 'name_ar'])->values();

        if ($subjects->isEmpty() || $grades->isEmpty() || $kinds->isEmpty() || $semesters->isEmpty()) {
            throw new RuntimeException('SyntheticCatalog needs the curriculum taxonomy (CurriculumSeeder).');
        }

        $count = 0;
        foreach ($subjects as $i => $subject) {
            foreach ([[$i * 5, $i], [$i * 5 + 4, $i + 3]] as $n => [$g, $k]) {
                $grade = $grades[$g % $grades->count()];
                $kind = (string) $kinds[$k % $kinds->count()];
                $semester = $semesters[($i + $n) % $semesters->count()];
                $sku = self::SKU_PREFIX.$subject->id.'-'.$n;

                $product = Product::query()->create([
                    'sku' => $sku,
                    'slug' => mb_strtolower($sku),
                    'title' => "{$kind} {$subject->name_ar} — {$grade->name_ar} — {$semester->name_ar}",
                    'resource_type' => $kind,
                    'format' => str_contains($kind, 'بوربوينت') ? 'PPTX' : 'PDF',
                    'stage_id' => $grade->stage_id,
                    'grade_id' => $grade->id,
                    'subject_id' => $subject->id,
                    'semester_id' => $semester->id,
                    'status' => 'published',
                    'published_at' => now()->subDay(),
                ]);
                ProductPrice::query()->create(['product_id' => $product->id, 'regular_minor' => 1000 + $count, 'currency' => 'SAR']);
                if (($lesson = self::LESSONS[(string) $subject->name_ar][$n] ?? null) !== null) {
                    $this->attachLesson($product, $sku, $lesson);
                }
                $count++;
            }
        }

        return $count;
    }

    /** ملفٌّ بشريحةٍ واحدة: عنوانُها الدرس، وتحته سطرٌ عامّ لا يُسأل عنه. */
    private function attachLesson(Product $product, string $sku, string $lesson): void
    {
        $sha = hash('sha256', 'synthetic:'.$sku);
        $asset = Asset::query()->create([
            'provider' => 'local', 'provider_ref' => 'synthetic/'.$sha, 'original_name' => $sku.'.pptx', 'sha256' => $sha,
            'size_bytes' => 1, 'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'visibility' => 'paid_private', 'usage_count' => 1,
        ]);
        DB::table('product_files')->insert([
            'product_id' => $product->id, 'asset_id' => $asset->id, 'role' => 'main-0', 'position' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        AssetExtraction::query()->create([
            'asset_id' => $asset->id, 'asset_sha256' => $sha,
            'extractor' => OfficeOpenXmlExtractor::NAME, 'extractor_version' => OfficeOpenXmlExtractor::VERSION,
            'status' => ExtractionStatus::Ok->value, 'text' => $lesson.'
أهداف الدرس', 'units' => 1,
            'duration_ms' => 1, 'bytes_read' => 1,
        ]);
    }
}
