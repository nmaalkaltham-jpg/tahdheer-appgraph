<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Assets\Models\Asset;
use App\Domain\ContentIntelligence\ExtractionStatus;
use App\Domain\ContentIntelligence\ExtractorRegistry;
use App\Domain\ContentIntelligence\Extractors\CapabilityGapExtractor;
use App\Domain\ContentIntelligence\Models\AssetExtraction;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

/**
 * فاحص ذكاء المنتج (§١١٣ §١١٤ §١١٥ §١٨٢) — «ماذا استخرج النظام، وأين توقّف؟».
 *
 * ─── لماذا سطحٌ أصلًا ───
 *
 * خطّ الاستخراج يكتب صفوفًا في `asset_extractions` ولا أحد يقرؤها: النجاح
 * صامت والفشل صامت مثله. ومن يُشغّل `content:extract` يرى عدّادًا في الطرفيّة
 * ثمّ لا شيء — فلا يعرف أيّ أصلٍ سقط ولا لماذا، ولا أنّ صيغةً كاملة لا
 * محوّلَ لها أصلًا. هذا هو نمط العطب الذي يعنينا: فجوةٌ يسكت عنها النظام.
 *
 * ─── لماذا لا أعيد تشغيل شيء من هنا ───
 *
 * قراءةٌ محضة: لا يكتب هذا المسار صفًّا ولا يشغّل مستخرِجًا. البديل المرفوض
 * زرُّ «أعد الاستخراج» في الشاشة — يقرأ الملفّ من داخل طلب HTTP، ويُطيل الطلب
 * بقدر حجم الأصل، ويُنشئ طريقًا ثانيًا للتشغيل بجوار `content:extract`.
 * الإعادة أمرٌ مجدول، والشاشة تقول ما حدث لا تُحدثه.
 *
 * ─── مفردات المرحلة (§١٨٣) ───
 *
 * `COMPLETE · PARTIAL · FAILED · STALE · NOT_REQUIRED` — وما عداها `UNKNOWN`.
 * المرحلة **مشتقّة من الصفوف** لا محفوظة في عمود: عمودُ حالةٍ ثانٍ بجوار
 * `asset_extractions.status` يتباعد عنه أوّلَ مرّةٍ تُرفع فيها نسخة مستخرِج.
 * وحيثما عجزت الصفوف عن الجواب تُقال `UNKNOWN` صراحةً ولا تُملأ بتخمين.
 *
 * (الشرطة السفليّة في `NOT_REQUIRED` لأنّها مفتاحٌ آليّ لا نصّ معروض؛
 * الترجمة العربيّة في الواجهة وحدها.)
 */
final class ProductIntelligenceController
{
    /** سقفُ العرض — مُعلَنٌ في الحمولة، لا صامت. */
    private const CATALOG_CAP = 300;

    /**
     * سقف النصّ المعاد في الاستجابة.
     *
     * النصّ داخليّ ويُعرض للمشرف كاملًا من حيث المبدأ، لكنّ ملفًّا كبيرًا
     * يُنتج نصًّا بالميغابايتات فيُثقل صفحةً تُفتح لتشخيص عطل. القطع **معلَن**
     * بـ`textTruncated` مع الطول الأصليّ في `textChars` — قطعٌ صامت كان
     * سيجعل المشغّل يظنّ أنّ المستخرِج توقّف حيث توقّف العرض.
     */
    private const TEXT_LIMIT = 20_000;

    /**
     * ترتيب الإلحاح عند طيّ مراحل الأصول في مرحلة منتج واحدة.
     *
     * الأصغر أسوأ. و`UNKNOWN` يسبق `STALE` عمدًا: أصلٌ بلا محاولةٍ البتّة
     * أخطر من أصلٍ استُخرج بنسخةٍ قديمة — الثاني له جوابٌ مكتوب، والأوّل
     * صمتٌ كامل، وهو ما تُبنى هذه الشاشة لكشفه.
     */
    private const SEVERITY = [
        'FAILED' => 0,
        'UNKNOWN' => 1,
        'STALE' => 2,
        'PARTIAL' => 3,
        'NOT_REQUIRED' => 4,
        'COMPLETE' => 5,
    ];

    /**
     * ما يفعله المشغّل عند كلّ رمز عطل — لا نقطةٌ حمراء.
     *
     * المفاتيح هي ما تكتبه `AssetExtractionService` فعلًا
     * (`app/Domain/ContentIntelligence/AssetExtractionService.php:81,96`).
     * ورمزٌ لا يُعرف هنا لا يُترجَم إلى نصيحةٍ عامّة: يُعاد كما هو مع
     * `meta.note_ar` الذي كتبه المستخرِج — نصيحةٌ مخترعة أسوأ من لا نصيحة.
     */
    private const REMEDIES = [
        'storage_missing_object' => 'التخزين لا يُعيد كائن هذا الأصل تحت مفتاحه المسجَّل. '
            .'افحص مزوّد التخزين ووجود الكائن، ثمّ أعِد `php artisan content:extract` — '
            .'حالة `failed` غير نهائيّة فتُعاد تلقائيًّا في التشغيل التالي.',
        'storage_disk_unavailable' => 'التخزين لم يُجب. عطلٌ عابر بطبعه: تحقّق من اتّصال المزوّد ثمّ أعِد التشغيل.',
        'sha256_mismatch' => 'بايتات التخزين لا تطابق بصمة الأصل المسجَّلة. لا يُصلَح بإعادة التشغيل: '
            .'وفِّق الأصل مع التخزين أوّلًا (البصمة المقيسة في `meta.measured_sha256`).',
        'asset_too_large' => 'الأصل أكبر من سقف القراءة في الذاكرة. لا يُصلَح بإعادة التشغيل: '
            .'يحتاج محوّلًا يعمل على تدفّق.',
    ];

    public function __construct(private readonly ExtractorRegistry $registry) {}

    /**
     * قائمة الكتالوج — أين يقف الخطّ على كلّ منتج، وما الذي لا يُستخرج أصلًا.
     */
    public function index(): JsonResponse
    {
        /*
         * السقف يُعلَن ولا يُسكت عنه.
         *
         * هذه الشاشة دعواها أنّ الفجوة لا تُخفى — فسقفٌ صامت عند ٣٠٠ منتج
         * يجعلها ترتكب ما وُجدت لتمنعه: كتالوجٌ أكبر يُعرض ناقصًا ويُقرأ
         * كاملًا. فيُقاس العدد الكلّيّ ويُعاد مع الحمولة، ويعرف القارئ أنّه
         * ينظر إلى جزء.
         */
        $total = Product::query()->count();

        $products = Product::query()
            ->with(['files.asset'])
            ->orderBy('id')
            ->limit(self::CATALOG_CAP)
            ->get();

        $rows = $this->rowsForAssets($products->flatMap(fn (Product $p) => $p->files->pluck('asset'))->filter());

        $stageCounts = ['COMPLETE' => 0, 'PARTIAL' => 0, 'FAILED' => 0, 'STALE' => 0, 'NOT_REQUIRED' => 0, 'UNKNOWN' => 0];
        $assetsWithoutAttempt = 0;
        $cards = [];

        foreach ($products as $product) {
            $assets = $product->files->pluck('asset')->filter()->values();
            $stages = [];

            foreach ($assets as $asset) {
                $verdict = $this->assetVerdict($asset, $rows);
                $stages[] = $verdict['stage'];

                if ($verdict['attempts'] === 0) {
                    $assetsWithoutAttempt++;
                }
            }

            $stage = $this->foldStages($stages, $assets->count());
            $stageCounts[$stage['stage']]++;

            $cards[] = [
                'id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'title' => (string) $product->title,
                'status' => (string) $product->status,
                'assetCount' => $assets->count(),
                'stage' => $stage['stage'],
                'stageReason' => $stage['reason'],
                'assetStages' => $stages,
            ];
        }

        /* الأسوأ أوّلًا: الشاشة تُفتح لتُصلح لا لتُتصفّح. */
        usort($cards, fn (array $a, array $b) => [self::SEVERITY[$a['stage']], $a['id']] <=> [self::SEVERITY[$b['stage']], $b['id']]);

        return response()->json([
            /* حدُّ العرض مُعلَنٌ مع الحمولة — قارئٌ يرى ٣٠٠ من ٤٠٠ يعرف ذلك. */
            'catalogTotal' => $total,
            'shown' => $products->count(),
            'truncated' => $total > $products->count(),

            'data' => $cards,
            'stages' => $stageCounts,
            'registry' => $this->registryShape(),
            'coverage' => $this->coverage(),
            'totals' => [
                'products' => $products->count(),
                'assets' => Asset::query()->count(),
                'assetsWithoutAttempt' => $assetsWithoutAttempt,
                'extractionRows' => AssetExtraction::query()->count(),
            ],
        ]);
    }

    /**
     * منتجٌ واحد — بياناته المرجعيّة، وأصوله، ومحاولة كلّ أصل بنصّها.
     */
    public function show(int $product): JsonResponse
    {
        $model = Product::query()
            ->with(['files.asset', 'stage', 'grade', 'subject', 'semester'])
            ->find($product);

        if ($model === null) {
            /* ٤٠٤ مفقود — دلالة المصدر تُحفظ حرفيًّا (قاعدة ١١). */
            return response()->json([
                'message' => 'لا منتج بهذا المعرّف.',
                'code' => 'PRODUCT_NOT_FOUND',
            ], 404);
        }

        $files = $model->files->filter(fn ($file) => $file->asset !== null)->values();
        $rows = $this->rowsForAssets($files->pluck('asset'));

        $assets = $files->map(function ($file) use ($rows) {
            $asset = $file->asset;
            $verdict = $this->assetVerdict($asset, $rows);

            return [
                'assetId' => (int) $asset->id,
                'role' => (string) $file->role,
                'position' => (int) $file->position,
                /* لا `provider_ref` هنا ولا في أيّ حقلٍ آخر: مفتاح الكائن سرٌّ داخليّ. */
                'provider' => (string) $asset->provider,
                'originalName' => (string) $asset->original_name,
                'customerLabel' => $asset->customer_label,
                'mime' => (string) $asset->mime,
                'sizeBytes' => (int) $asset->size_bytes,
                'visibility' => (string) $asset->visibility,
                'sha256Prefix' => mb_substr((string) $asset->sha256, 0, 12),
                'expected' => $verdict['expected'],
                'storage' => $verdict['storage'],
                'stage' => $verdict['stage'],
                'stageReason' => $verdict['reason'],
                'remedy' => $verdict['remedy'],
                'gap' => $verdict['gap'],
                'current' => $verdict['current'],
                'superseded' => $verdict['superseded'],
            ];
        })->values();

        $stage = $this->foldStages($assets->pluck('stage')->all(), $assets->count());

        return response()->json([
            'product' => [
                'id' => (int) $model->id,
                'sku' => (string) $model->sku,
                'slug' => (string) $model->slug,
                'title' => (string) $model->title,
                'subtitle' => $model->subtitle,
                'status' => (string) $model->status,
                'publishedAt' => $model->published_at?->toIso8601String(),
                'resourceType' => $model->resource_type,
                'format' => $model->format,
                'editable' => (string) $model->editable,
                'fulfillmentType' => (string) $model->fulfillment_type,
                'contentHash' => $model->content_hash,
                /* البيانات المرجعيّة للمنهج: ما يقوله الكتالوج، لا ما استنتجه مستخرِج. */
                'stage' => $model->stage?->name_ar,
                'grade' => $model->grade?->name_ar,
                'subject' => $model->subject?->name_ar,
                'semester' => $model->semester?->name_ar,
            ],
            'pipeline' => [
                'stage' => $stage['stage'],
                'reason' => $stage['reason'],
            ],
            'assets' => $assets,
            'registry' => $this->registryShape(),
        ]);
    }

    /**
     * كلّ صفوف الاستخراج لبصمات هذه الأصول، مجمّعةً بالبصمة.
     *
     * المفتاح `asset_sha256` لا `asset_id`: الخدمة تكتب بالبصمة
     * (`AssetExtractionService.php:141`)، فأصلان بمحتوًى واحد يتقاسمان الصفّ
     * نفسه. الاستعلام بـ`asset_id` كان سيُظهر أحدهما «بلا محاولة» وهو مستخرَج.
     *
     * @param  Collection<int, Asset>  $assets
     * @return Collection<string, Collection<int, AssetExtraction>>
     */
    private function rowsForAssets(Collection $assets): Collection
    {
        $shas = $assets->pluck('sha256')->filter()->unique()->values()->all();

        if ($shas === []) {
            return collect();
        }

        return AssetExtraction::query()
            ->whereIn('asset_sha256', $shas)
            ->orderBy('id')
            ->get()
            ->groupBy('asset_sha256');
    }

    /**
     * حكمُ أصلٍ واحد: المرحلة وسببها، والصفّ الحاليّ، وما سبقه.
     *
     * @param  Collection<string, Collection<int, AssetExtraction>>  $rows
     * @return array<string, mixed>
     */
    private function assetVerdict(Asset $asset, Collection $rows): array
    {
        $mime = (string) $asset->mime;
        $extractor = $this->registry->for($mime);
        $claimed = ! $extractor instanceof CapabilityGapExtractor;

        $all = $rows->get((string) $asset->sha256, collect());
        $current = $all->first(
            fn (AssetExtraction $r) => (string) $r->extractor === $extractor->name()
                && (string) $r->extractor_version === $extractor->version(),
        );
        $superseded = $all->filter(fn (AssetExtraction $r) => $r !== $current)->values();

        [$stage, $reason, $gap] = $this->stageFor($current, $superseded, $claimed, $mime);

        $errorCode = $current === null ? null : ($current->error_code === null ? null : (string) $current->error_code);

        return [
            'stage' => $stage,
            'reason' => $reason,
            'gap' => $gap,
            'attempts' => $all->count(),
            'remedy' => $errorCode === null ? null : (self::REMEDIES[$errorCode] ?? null),
            'expected' => [
                'extractor' => $extractor->name(),
                'version' => $extractor->version(),
                /* `false` تعني: لا محوّل يدّعي هذه الصيغة — الشامل وحده تولّاها. */
                'claimed' => $claimed,
            ],
            'storage' => $this->storageFor($current, $all),
            'current' => $current === null ? null : $this->shapeRow($current, true),
            'superseded' => $superseded->map(fn (AssetExtraction $r) => $this->shapeRow($r, false))->all(),
        ];
    }

    /**
     * اشتقاق المرحلة من الصفوف وحدها.
     *
     * @param  Collection<int, AssetExtraction>  $superseded
     * @return array{0: string, 1: string, 2: array<string, mixed>|null}
     */
    private function stageFor(?AssetExtraction $current, Collection $superseded, bool $claimed, string $mime): array
    {
        if ($current === null) {
            if ($superseded->isNotEmpty()) {
                $last = $superseded->last();

                return [
                    'STALE',
                    sprintf(
                        'آخر ما على السجلّ من «%s» نسخة %s؛ والمنتظَر اليوم نسخةٌ أحدث — لم يُعَد الاستخراج بعد.',
                        (string) $last->extractor,
                        (string) $last->extractor_version,
                    ),
                    null,
                ];
            }

            /*
             * لا صفّ البتّة. والصفوف لا تُفرّق بين «لم يُجدوَل قطّ» و«جُدوِل
             * وضاع» — فتُقال `UNKNOWN` ولا تُزوَّر إلى «لم يُطلب».
             */
            return ['UNKNOWN', 'لا محاولة استخراجٍ مسجّلة لهذا الأصل البتّة. شغّل `php artisan content:extract`.', null];
        }

        $status = ExtractionStatus::tryFrom((string) $current->status);
        $meta = is_array($current->meta) ? $current->meta : [];

        return match ($status) {
            ExtractionStatus::Ok => ['COMPLETE', sprintf('استُخرج نصًّا أصليًّا: %d وحدة.', (int) $current->units), null],
            ExtractionStatus::Partial => [
                'PARTIAL',
                (string) ($meta['note_ar'] ?? 'استُخرج بعضُ المحتوى لا كلّه.'),
                $this->gapShape($meta),
            ],
            ExtractionStatus::CapabilityGap => [
                /*
                 * `NOT_REQUIRED` لا `FAILED`: لا عطبَ هنا ولا إعادةَ تُصلحه —
                 * لا قدرة على الصيغة أصلًا. وعدُّها فشلًا كان يجعل كلّ تشغيلٍ
                 * يقرأ هذه الملفّات ليكتب الجواب نفسه. والقدرة الناقصة مسمّاة
                 * في `gap` فلا يُقرأ هذا «لا شيء ناقص».
                 */
                'NOT_REQUIRED',
                (string) ($meta['note_ar'] ?? sprintf('لا محوّل لصيغة %s اليوم.', $mime)),
                $this->gapShape($meta),
            ],
            ExtractionStatus::Failed => [
                'FAILED',
                (string) ($meta['note_ar'] ?? 'عطلٌ أثناء الاستخراج.'),
                null,
            ],
            /*
             * حالةٌ لا تعرفها القائمة — مثل `ocr_unavailable` التي كتبتها شيفرةٌ
             * محذوفة. تُقال `UNKNOWN` باسم الحالة الخام كما هي.
             */
            null => ['UNKNOWN', sprintf('حالةٌ خارج المفردات المعروفة: «%s».', (string) $current->status), null],
        };
    }

    /** @param array<string, mixed> $meta */
    private function gapShape(array $meta): ?array
    {
        $capability = $meta['capability'] ?? null;
        $requires = $meta['requires'] ?? null;

        if ($capability === null && $requires === null) {
            return null;
        }

        return [
            'capability' => $capability === null ? null : (string) $capability,
            'requires' => is_array($requires) ? array_values(array_map('strval', $requires)) : [],
        ];
    }

    /**
     * حالة التخزين مشتقّةٌ من الصفوف لا من فحصٍ حيّ.
     *
     * البديل المرفوض: سؤال المزوّد عن كلّ أصلٍ داخل الطلب. نداءٌ شبكيّ لكلّ
     * صفٍّ يجعل زمن الشاشة تابعًا لزمن التخزين، ويحوّل سطحَ تشخيصٍ إلى حِملٍ
     * على المزوّد. فما يُقال هنا مقيسٌ بزمنه: «قُرئت بايتاتُه في ذلك الوقت»
     * أو «التخزين لم يُعِده حينها» — لا ادّعاء عن اللحظة الحاضرة.
     *
     * @param  Collection<int, AssetExtraction>  $all
     * @return array<string, mixed>
     */
    private function storageFor(?AssetExtraction $current, Collection $all): array
    {
        if ($current !== null && str_starts_with((string) $current->error_code, 'storage_')) {
            return [
                'state' => 'missing',
                'observedAt' => $current->updated_at?->toIso8601String(),
                'bytesRead' => null,
                'note' => 'التخزين لم يُعِد بايتات هذا الأصل وقت المحاولة.',
            ];
        }

        $read = $all->last(fn (AssetExtraction $r) => (int) $r->bytes_read > 0);

        if ($read !== null) {
            return [
                'state' => 'readable',
                'observedAt' => $read->updated_at?->toIso8601String(),
                'bytesRead' => (int) $read->bytes_read,
                'note' => 'قُرئت بايتاتُه كاملةً وطابقت بصمته وقت المحاولة.',
            ];
        }

        return [
            'state' => 'unverified',
            'observedAt' => null,
            'bytesRead' => null,
            'note' => 'لا محاولةَ قرأت بايتات هذا الأصل، فلا شهادة على تخزينه من هذا السجلّ.',
        ];
    }

    /** @return array<string, mixed> */
    private function shapeRow(AssetExtraction $row, bool $withText): array
    {
        $text = (string) ($row->text ?? '');
        $chars = mb_strlen($text);

        $shape = [
            'id' => (int) $row->id,
            'extractor' => (string) $row->extractor,
            'version' => (string) $row->extractor_version,
            'status' => (string) $row->status,
            'errorCode' => $row->error_code,
            'units' => $row->units === null ? null : (int) $row->units,
            'durationMs' => $row->duration_ms === null ? null : (int) $row->duration_ms,
            'bytesRead' => $row->bytes_read === null ? null : (int) $row->bytes_read,
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];

        if (! $withText) {
            return $shape;
        }

        return $shape + [
            'meta' => is_array($row->meta) ? $row->meta : null,
            'textChars' => $row->text === null ? null : $chars,
            'textTruncated' => $chars > self::TEXT_LIMIT,
            'text' => $row->text === null ? null : mb_substr($text, 0, self::TEXT_LIMIT),
        ];
    }

    /**
     * طيُّ مراحل الأصول في مرحلة منتج واحدة.
     *
     * @param  list<string>  $stages
     * @return array{stage: string, reason: string}
     */
    private function foldStages(array $stages, int $assetCount): array
    {
        if ($assetCount === 0) {
            return [
                'stage' => 'NOT_REQUIRED',
                'reason' => 'لا أصل مرتبط بهذا المنتج، فلا شيء يُستخرج.',
            ];
        }

        $worst = 'COMPLETE';

        foreach ($stages as $stage) {
            if (self::SEVERITY[$stage] < self::SEVERITY[$worst]) {
                $worst = $stage;
            }
        }

        $count = count(array_filter($stages, fn (string $s) => $s === $worst));

        return [
            'stage' => $worst,
            'reason' => sprintf('%d من %d أصلٍ في هذه المرحلة، وهي الأشدّ في هذا المنتج.', $count, $assetCount),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function registryShape(): array
    {
        return array_map(fn ($extractor) => [
            'extractor' => $extractor->name(),
            'version' => $extractor->version(),
            'catchAll' => $extractor instanceof CapabilityGapExtractor,
        ], $this->registry->all());
    }

    /**
     * تغطية الصيغ في الكتالوج — أيّ نوعٍ يتولّاه محوّل، وأيّها يسقط على الشامل.
     *
     * يُقاس من `assets.mime` الفعليّة لا من قائمةٍ مكتوبة: صيغةٌ تدخل الكتالوج
     * غدًا بلا محوّلٍ يجب أن تظهر هنا بلا تعديل شيفرة.
     *
     * @return list<array<string, mixed>>
     */
    private function coverage(): array
    {
        $mimes = Asset::query()
            ->selectRaw('mime, count(*) as assets')
            ->groupBy('mime')
            ->orderByRaw('count(*) desc')
            ->get();

        return $mimes->map(function ($row) {
            $extractor = $this->registry->for((string) $row->mime);

            return [
                'mime' => (string) $row->mime,
                'assets' => (int) $row->assets,
                'extractor' => $extractor->name(),
                'version' => $extractor->version(),
                'claimed' => ! $extractor instanceof CapabilityGapExtractor,
            ];
        })->all();
    }
}
