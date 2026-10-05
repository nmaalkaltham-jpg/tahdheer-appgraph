<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Entities;

use App\Domain\Assets\Models\ProductFile;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use App\Domain\Search\ArabicNormalizer;
use App\Domain\Search\EntityExtractor;
use App\Models\Grade;
use App\Models\Kind;
use App\Models\Product;
use App\Models\Semester;
use App\Models\Subject;
use Illuminate\Support\Carbon;

/**
 * اشتقاق الكيانات التعليميّة من النصّ المستخرَج — اقتراحات، لا حقيقة.
 *
 * ─── لماذا `EntityExtractor` مُعادُ استعمالُه حرفيًّا ───
 *
 * `app/Domain/Search/EntityExtractor.php:56` يأخذ نصًّا مطبَّعًا ويعيد
 * معرّفات التصنيف بمطابقةِ الأطول-أوّلًا على كلمةٍ كاملة من ٢٦١ اسمًا
 * مستعارًا في `curriculum_aliases`، ويعيد النوع والصيغة معهما. وهذه هي
 * المسألة نفسها بلا فرق: نصٌّ عربيّ ← معرّفات تصنيف. قِيس على أسطرٍ حقيقيّة
 * من الاستخراج (٢٠٢٦-٠٩-٢٣): «الصف السادس الابتدائي»→`grade_id=6`،
 * «الفصل الدراسي الأول»→`semester_id=1`، «الرياضيات»→`subject_id=4`،
 * «تحضير»→النوع، «DOCX · قابل للتعديل»→`DOCX`.
 *
 * فكتابةُ مُطابِقٍ ثانٍ هنا كانت ستُنتج مفرداتٍ تنحرف عن مفردات البحث،
 * فيرى المشتري تصنيفًا ويرى المحرّر تصنيفًا آخر لنفس النصّ. مُطابِقٌ واحد،
 * كما أنّ المطبِّع واحد (`ArabicNormalizer`).
 *
 * ─── لماذا السطر هو وحدة الشاهد ───
 *
 * `EntityExtractor` يبتلع الاسم المستعار من النصّ ولا يقول أيَّ مقطعٍ ابتلع
 * — يعيد البقيّة فقط. ومرشّحٌ بلا مقطعٍ يشير إليه لا يُراجَع. فبدل تعديل
 * `EntityExtractor` ليعيد المواضع (تغييرٌ في مسار البحث الحيّ لأجل مستهلكٍ
 * خارجه — وهو ما رُفض)، يُستدعى **مرّةً لكلّ سطر**: فالسطر الذي أنتج
 * المعرّف هو الشاهد، وموضعُه بالبايت في `text` يثبته. وأسطر هذه
 * الاستخراجات قصيرة أصلًا (أطولها ٩٩ حرفًا، مقيس) فالشاهد دقيقٌ لا فضفاض.
 *
 * والثمن: ~٢٧٦ نداءً لكلّ تشغيلٍ كامل بدل ٢٦. مقبولٌ لدُفعةٍ تُشغَّل يدويًّا،
 * والمفردات تُحمَّل مرّةً واحدة لأنّ النسخة الواحدة تُخزّنها
 * (`EntityExtractor::vocabulary`).
 *
 * ─── ما لا تفعله هذه الخدمة ───
 *
 * لا تكتب في `products` ولا في أيّ جدول تصنيف. لا سطرَ هنا ينادي `save`
 * على منتج. البند ١٧٨ يريد تجاوزًا بشريًّا يبقى بعد إعادة التوليد، ولا
 * يبقى تجاوزٌ دُهس. المرشَّح يُقترح ويُقارَن ويُسجَّل خلافُه — ثمّ يقرّر
 * إنسان.
 */
final class EntityDerivationService
{
    /** تُرفع حين يتغيّر منطق الاشتقاق، فتُميَّز صفوفُ اليوم عن صفوف الغد. */
    public const VERSION = '1';

    /** سقفُ الشاهد المخزَّن. المخزَّن بادئةُ السطر، فالموضع يبقى صادقًا. */
    private const EVIDENCE_MAX_CHARS = 200;

    /** @var array<string, array<int|string, string>>|null */
    private ?array $slugs = null;

    public function __construct(
        private readonly EntityExtractor $extractor = new EntityExtractor,
    ) {}

    /**
     * يمرّ على كلّ استخراجٍ ناجح، يقترح، ويكتب المرشَّحين إن طُلب.
     *
     * `$persist=false` = تشغيلٌ جافّ يقيس من دون أثر — وهو ما يُشغَّل على
     * قاعدة التطوير قبل أن تُقبل الهجرة.
     *
     * @return array{extractions: int, candidates: int, by_dimension: array<string, array<string, int>>}
     */
    public function run(bool $persist = true): array
    {
        $byDimension = [];
        foreach (EntityDimension::cases() as $dimension) {
            $byDimension[$dimension->value] = [
                'proposed' => 0, 'absent' => 0,
                'agree' => 0, 'disagree' => 0, 'catalog_missing' => 0, 'no_product' => 0,
            ];
        }

        $extractions = 0;
        $candidates = 0;

        AssetExtraction::query()
            ->where('status', ExtractionStatus::Ok->value)
            ->orderBy('id')
            ->each(function (AssetExtraction $extraction) use (&$extractions, &$candidates, &$byDimension, $persist) {
                $extractions++;
                $proposals = $this->propose($extraction);

                foreach (EntityDimension::cases() as $dimension) {
                    $proposal = $proposals[$dimension->value] ?? null;
                    if ($proposal === null) {
                        $byDimension[$dimension->value]['absent']++;

                        continue;
                    }

                    $byDimension[$dimension->value]['proposed']++;
                    $byDimension[$dimension->value][$proposal['agreement']]++;
                    $candidates++;

                    if ($persist) {
                        $this->store($proposal);
                    }
                }
            });

        return ['extractions' => $extractions, 'candidates' => $candidates, 'by_dimension' => $byDimension];
    }

    /**
     * اقتراحاتُ استخراجٍ واحد — بلا أيّ كتابة.
     *
     * @return array<string, array<string, mixed>> البُعد ← المرشَّح
     */
    public function propose(AssetExtraction $extraction): array
    {
        $text = (string) $extraction->text;
        if ($extraction->status !== ExtractionStatus::Ok->value || trim($text) === '') {
            return [];
        }

        $product = $this->productOf($extraction);
        $tally = $this->tally($text);
        $now = Carbon::now();

        $proposals = [];
        foreach ($tally as $dimensionValue => $values) {
            $dimension = EntityDimension::from($dimensionValue);

            // الفائز: أكثرُ الأسطر تأييدًا؛ وعند التعادل الأسبقُ موضعًا —
            // ترتيبٌ قاطع، فلا يتغيّر المرشَّح بين تشغيلين على نفس النصّ.
            uasort($values, fn (array $a, array $b) => [$b['support'], $a['offset']] <=> [$a['support'], $b['offset']]);
            $winner = reset($values);
            $rest = array_slice($values, 1);

            $conflicts = array_sum(array_column($rest, 'support'));
            $support = (int) $winner['support'];

            $catalog = $this->compare($dimension, $winner, $product);

            $proposals[$dimensionValue] = [
                'asset_extraction_id' => $extraction->id,
                'asset_id' => $extraction->asset_id,
                'asset_sha256' => $extraction->asset_sha256,
                'extractor' => $extraction->extractor,
                'product_id' => $product?->id,
                'dimension' => $dimensionValue,
                'entity_id' => $winner['entity_id'],
                'entity_value' => $winner['entity_value'],
                'evidence_text' => $winner['evidence'],
                'evidence_offset' => $winner['offset'],
                'support' => $support,
                'conflicts' => $conflicts,
                // البدائل تُحفظ كي يرى المراجع ما الذي خسر ولماذا؛ حذفُها
                // يجعل مرشّحًا بثقة 0.5 لا يُفسَّر.
                'alternatives' => array_values(array_map(fn (array $v) => [
                    'entity_id' => $v['entity_id'],
                    'entity_value' => $v['entity_value'],
                    'support' => $v['support'],
                    'evidence_offset' => $v['offset'],
                ], $rest)) ?: null,
                // اتّساقٌ داخليّ بين أسطر هذا الملفّ — لا احتمالُ صواب.
                // سطرٌ واحدٌ بلا معارض = 1.000 ولا يعني «مؤكَّد».
                'confidence' => round($support / max(1, $support + $conflicts), 3),
                'catalog_entity_id' => $catalog['catalog_entity_id'],
                'catalog_value' => $catalog['catalog_value'],
                'agreement' => $catalog['agreement'],
                'derivation_version' => self::VERSION,
                'derived_at' => $now,
            ];
        }

        return $proposals;
    }

    /** الكتابة الوحيدة في هذه الخدمة — وهي في `entity_candidates` وحدها. */
    public function store(array $proposal): EntityCandidate
    {
        return EntityCandidate::query()->updateOrCreate(
            [
                'asset_extraction_id' => $proposal['asset_extraction_id'],
                'dimension' => $proposal['dimension'],
            ],
            $proposal,
        );
    }

    /**
     * إحصاءُ كلّ قيمةٍ اقترحها أيُّ سطر، مع أوّل موضعٍ أيّدها.
     *
     * @return array<string, array<string, array{entity_id: ?int, entity_value: ?string, support: int, offset: int, evidence: string}>>
     */
    private function tally(string $text): array
    {
        $tally = [];

        foreach ($this->segments($text) as $segment) {
            $normalized = ArabicNormalizer::normalize($segment['text']);
            if ($normalized === '') {
                continue;
            }

            foreach ($this->hits($this->extractor->extract($normalized)) as $dimension => $hit) {
                $key = $dimension.'#'.($hit['entity_id'] ?? $hit['entity_value']);
                if (isset($tally[$dimension][$key])) {
                    $tally[$dimension][$key]['support']++;

                    continue;
                }

                $tally[$dimension][$key] = $hit + [
                    'support' => 1,
                    'offset' => $segment['offset'],
                    'evidence' => mb_substr($segment['text'], 0, self::EVIDENCE_MAX_CHARS),
                ];
            }
        }

        return $tally;
    }

    /**
     * ما التقطه `EntityExtractor` من سطرٍ واحد، بُعدًا بُعدًا.
     *
     * `stage` مُسقَطة هنا لا سهوًا: انظر `EntityDimension`.
     *
     * @param  array<string, mixed>  $extracted
     * @return array<string, array{entity_id: ?int, entity_value: ?string}>
     */
    private function hits(array $extracted): array
    {
        $hits = [];

        foreach ([EntityDimension::Subject, EntityDimension::Grade, EntityDimension::Semester] as $dimension) {
            $id = $extracted['ids'][$dimension->value.'_id'] ?? null;
            if ($id !== null) {
                $hits[$dimension->value] = [
                    'entity_id' => (int) $id,
                    'entity_value' => $this->slugFor($dimension, (int) $id),
                ];
            }
        }

        // النوع يعود لافتةً من `EntityExtractor` لا معرّفًا؛ يُحلّ إلى معرّف
        // لأنّ البند ١٥٤ يمنع الربط بلافتةٍ قابلةٍ للتغيير.
        $kindSlug = $extracted['filters']['kind'] ?? null;
        if (is_string($kindSlug) && $kindSlug !== '') {
            $kindId = array_search($kindSlug, $this->slugMap('kind'), true);
            if ($kindId !== false) {
                $hits[EntityDimension::Kind->value] = ['entity_id' => (int) $kindId, 'entity_value' => $kindSlug];
            }
        }

        $format = $extracted['filters']['format'] ?? null;
        if (is_string($format) && $format !== '') {
            $hits[EntityDimension::Format->value] = ['entity_id' => null, 'entity_value' => $format];
        }

        return $hits;
    }

    /**
     * تقسيمُ النصّ أسطرًا مع موضع كلّ سطرٍ بالبايت في النصّ الأصل.
     *
     * الموضع يُحسب بمؤشّرٍ متحرّك لا بـ`strpos`: سطران متطابقان في نفس
     * الملفّ (والعنوان يتكرّر فعلًا في هذه الـSVG) كانا سيُنسبان معًا إلى
     * الموضع الأوّل، فيكذب الشاهد على أحدهما.
     *
     * @return array<int, array{offset: int, text: string}>
     */
    private function segments(string $text): array
    {
        $segments = [];
        $cursor = 0;

        foreach (explode("\n", $text) as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $segments[] = ['offset' => $cursor + (strlen($line) - strlen(ltrim($line))), 'text' => $trimmed];
            }
            $cursor += strlen($line) + 1; // +1 للفاصل الذي ابتلعه `explode`
        }

        return $segments;
    }

    /**
     * موقف المرشَّح من قيمة الفهرس — من دون أن يمسّها.
     *
     * @param  array{entity_id: ?int, entity_value: ?string}  $winner
     * @return array{catalog_entity_id: ?int, catalog_value: ?string, agreement: string}
     */
    private function compare(EntityDimension $dimension, array $winner, ?Product $product): array
    {
        if ($product === null) {
            return ['catalog_entity_id' => null, 'catalog_value' => null, 'agreement' => EntityAgreement::NoProduct->value];
        }

        [$catalogId, $catalogValue] = match ($dimension) {
            EntityDimension::Subject => [$product->subject_id, $this->slugFor($dimension, $product->subject_id)],
            EntityDimension::Grade => [$product->grade_id, $this->slugFor($dimension, $product->grade_id)],
            EntityDimension::Semester => [$product->semester_id, $this->slugFor($dimension, $product->semester_id)],
            // `products.resource_type` لافتةٌ نصّيّة لا مفتاح؛ تُحلّ إلى
            // معرّف نوعٍ حين تطابق لافتةً قائمة، وإلّا بقيت نصًّا يُقارَن
            // مطبَّعًا — ولا تُخترَع لها هويّة.
            EntityDimension::Kind => [
                ($id = array_search((string) $product->resource_type, $this->slugMap('kind'), true)) === false ? null : (int) $id,
                $product->resource_type,
            ],
            EntityDimension::Format => [null, $product->format],
        };

        $catalogId = $catalogId === null ? null : (int) $catalogId;
        $catalogValue = is_string($catalogValue) && trim($catalogValue) !== '' ? $catalogValue : null;

        if ($catalogId === null && $catalogValue === null) {
            return ['catalog_entity_id' => null, 'catalog_value' => null, 'agreement' => EntityAgreement::CatalogMissing->value];
        }

        $same = $catalogId !== null && $winner['entity_id'] !== null
            ? $catalogId === $winner['entity_id']
            : ArabicNormalizer::normalize((string) $catalogValue) === ArabicNormalizer::normalize((string) $winner['entity_value']);

        return [
            'catalog_entity_id' => $catalogId,
            'catalog_value' => $catalogValue,
            'agreement' => ($same ? EntityAgreement::Agree : EntityAgreement::Disagree)->value,
        ];
    }

    /** المنتج المربوط بالأصل عبر `product_files` — وقد لا يوجد. */
    private function productOf(AssetExtraction $extraction): ?Product
    {
        if ($extraction->asset_id === null) {
            return null;
        }

        $productId = ProductFile::query()
            ->where('asset_id', $extraction->asset_id)
            ->orderBy('position')
            ->orderBy('id')
            ->value('product_id');

        return $productId === null ? null : Product::query()->find($productId);
    }

    private function slugFor(EntityDimension $dimension, int|string|null $id): ?string
    {
        return $id === null ? null : ($this->slugMap($dimension->value)[(int) $id] ?? null);
    }

    /**
     * لافتاتُ جداول التصنيف مرّةً واحدة لكلّ نسخة — جداولُ عشراتٍ لا آلاف.
     *
     * @return array<int, string>
     */
    private function slugMap(string $dimension): array
    {
        $this->slugs ??= [
            'subject' => Subject::query()->pluck('slug', 'id')->all(),
            'grade' => Grade::query()->pluck('slug', 'id')->all(),
            'semester' => Semester::query()->pluck('slug', 'id')->all(),
            'kind' => Kind::query()->pluck('slug', 'id')->all(),
        ];

        return $this->slugs[$dimension] ?? [];
    }
}
