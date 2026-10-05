<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\ProductReadiness;
use App\Domain\Catalog\ProductCapabilities;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Domain\Search\Indexing\ProductIndexSync;
use App\Domain\UrlPlatform\ProductUrlSync;
use App\Domain\UrlPlatform\UrlPlatformError;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductCardResource;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\ProductPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Product Studio backend (MIP §8.1). Money is always integer minor units
 * (halalas) — the console converts riyals on input, never the server.
 *
 * The list row reuses App\Http\Resources\ProductCardResource verbatim and
 * merges the operator-only fields (status + readiness) on top; the resource
 * is never forked.
 */
class ProductController extends Controller
{
    private const STATUSES = ['draft', 'published', 'retired'];

    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly ProductUrlSync $urls,
        private readonly ProductIndexSync $index,
        private readonly ProductGroupAuthoring $groups,
    ) {}

    /**
     * أعمدة الفرز المسموحة — قائمةٌ بيضاء لا اسمٌ يأتي من العميل.
     *
     * `price` ليس عمودًا في `products` بل في جدولٍ مرتبط، ويُفرَز باستعلامٍ
     * مترابط لا بوصلة: الوصلة تُضاعف الصفّ إن وُجد له أكثر من سعر، فيظهر
     * المنتج مرّتين في الصفحة — عطلٌ يبدو خطأ بيانات.
     */
    private const SORTABLE = [
        'title' => 'title',
        'sku' => 'sku',
        'status' => 'status',
        'createdAt' => 'created_at',
    ];

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['sometimes', Rule::in(self::STATUSES)],
            'stageId' => 'sometimes|integer',
            'subjectId' => 'sometimes|integer',
            'q' => 'sometimes|string|max:200',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
            'sort' => ['sometimes', Rule::in([...array_keys(self::SORTABLE), 'price'])],
            'dir' => ['sometimes', Rule::in(['asc', 'desc'])],
        ], AdminValidation::MESSAGES);

        $query = Product::query()->with(['price', 'stage', 'subject', 'group']);

        $applyFilters = function ($builder) use ($filters) {
            if (isset($filters['status'])) {
                $builder->where('status', $filters['status']);
            }
            if (isset($filters['stageId'])) {
                $builder->where('stage_id', (int) $filters['stageId']);
            }
            if (isset($filters['subjectId'])) {
                $builder->where('subject_id', (int) $filters['subjectId']);
            }
            if (isset($filters['q']) && trim($filters['q']) !== '') {
                $needle = '%'.trim($filters['q']).'%';
                $builder->where(fn ($q) => $q->where('title', 'like', $needle)->orWhere('sku', 'like', $needle)->orWhere('slug', 'like', $needle));
            }

            return $builder;
        };

        $applyFilters($query);

        /*
         * عدّ الحالات يُحسب على البحث **دون** مرشّح الحالة.
         *
         * وإلّا لَأظهر تبويبُ «منشور» رقمه وحده وأصفارًا في البقيّة، فيبدو
         * الكتالوج بلا مسوّداتٍ بينما هي مُخفاةٌ بالمرشّح.
         */
        $countQuery = Product::query();
        if (isset($filters['q']) && trim($filters['q']) !== '') {
            $needle = '%'.trim($filters['q']).'%';
            $countQuery->where(fn ($q) => $q->where('title', 'like', $needle)->orWhere('sku', 'like', $needle)->orWhere('slug', 'like', $needle));
        }
        if (isset($filters['stageId'])) {
            $countQuery->where('stage_id', (int) $filters['stageId']);
        }
        if (isset($filters['subjectId'])) {
            $countQuery->where('subject_id', (int) $filters['subjectId']);
        }
        $statusCounts = array_fill_keys(self::STATUSES, 0);
        foreach ($countQuery->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status') as $status => $total) {
            $statusCounts[(string) $status] = (int) $total;
        }

        /* `id` قاطعٌ ثانويّ دائمًا — بدونه الترتيب غير محدَّد عبر الصفحات. */
        $direction = ($filters['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = $filters['sort'] ?? null;

        if ($sort === 'price') {
            $query->orderBy(
                ProductPrice::query()
                    ->select('regular_minor')
                    ->whereColumn('product_prices.product_id', 'products.id')
                    ->limit(1),
                $direction,
            )->orderByDesc('id');
        } elseif ($sort !== null && isset(self::SORTABLE[$sort])) {
            $query->orderBy(self::SORTABLE[$sort], $direction)->orderByDesc('id');
        } else {
            $query->orderByDesc('id');
        }

        $paginator = $query->paginate(
            perPage: (int) ($filters['perPage'] ?? 20),
            page: (int) ($filters['page'] ?? 1),
        );

        $readiness = ProductReadiness::forMany($paginator->getCollection());

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Product $p) => $this->row($p, $request, $readiness[$p->id]))
                ->values(),
            'meta' => AdminValidation::meta($paginator),
            'filters' => ['statuses' => self::STATUSES],
            'statusCounts' => $statusCounts,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $product = Product::query()->with(['price', 'facts', 'stage', 'grade', 'subject', 'semester'])->findOrFail($id);

        return response()->json(['data' => $this->detail($product, $request)]);
    }

    public function store(Request $request, AdminUser $actor): JsonResponse
    {
        $data = $request->validate([
            /*
             * القواعد مكتوبةٌ حرفيًّا في موضع `validate` لا مبنيّةً في تابع
             * مشترك: مولّد العقد يقرأ المصفوفة **نصًّا** عبر AST، و`$this->rules()`
             * تُنتج جسمَ طلبٍ فارغًا في `openapi.json` و`body: null as never`
             * في العميل المولَّد. فالتكرار هنا ثمنُ عقدٍ صادق، وهو الثمن
             * الذي يدفعه هذا المستودع في كلّ متحكّمٍ آخر للسبب نفسه.
             */
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products', 'sku')],
            'slug' => [
                'required', 'string', 'max:255',
                'regex:/^[^\\s\\/#?%&+\\\\]+$/u',
                Rule::unique('products', 'slug'),
            ],
            'slugReason' => 'sometimes|nullable|string|max:500',
            'slugForce' => 'sometimes|boolean',
            'title' => 'required|string|max:255',
            /*
             * §٦ — طبيعة المنتج، إلزاميّةٌ عند الإنشاء.
             *
             * كانت `product_nature` بلا كاتبٍ في التطبيق كلّه، فيولد كلّ منتجٍ
             * جديد بلا طبيعة — أي بلا ما يقرّر أيّ الحقول تلزمه وكيف يُسلَّم.
             * والافتراض إلى «رقميّ» كان يُنتج منتجًا ماديًّا يمرّ من بوّابة
             * النشر ثمّ يُرفض عند الشحن لأنّه بلا وزن.
             */
            'productNature' => 'required|in:DIGITAL,PHYSICAL,SERVICE,HYBRID,ACCESS_BASED,COMPOSITE',
            /*
             * §٨ — استراتيجيّات التسليم. فارغةٌ تعني: خذ اقتراح الطبيعة.
             * والهجين وحده لا اقتراح له، فيُطلب منه اختيارٌ صريح.
             */
            'fulfillmentStrategies' => 'sometimes|array|max:19',
            'subtitle' => 'sometimes|nullable|string|max:255',
            'shortDesc' => 'sometimes|nullable|string|max:1000',
            'description' => 'sometimes|nullable|string|max:50000',
            'resourceType' => 'sometimes|nullable|string|max:64',
            'format' => 'sometimes|nullable|string|max:32',
            'stageId' => 'sometimes|nullable|integer|exists:stages,id',
            'gradeId' => 'sometimes|nullable|integer|exists:grades,id',
            'subjectId' => 'sometimes|nullable|integer|exists:subjects,id',
            'semesterId' => 'sometimes|nullable|integer|exists:semesters,id',
            'editable' => ['sometimes', Rule::in(['yes', 'no', 'unset'])],
            'regularMinor' => 'sometimes|integer|min:0|max:100000000',
            'saleMinor' => 'sometimes|nullable|integer|min:0',
            'saleFrom' => 'sometimes|nullable|date',
            'saleTo' => 'sometimes|nullable|date',
            'facts' => 'sometimes|array|max:40',
            'facts.*.key' => 'required|string|max:64',
            'facts.*.intValue' => 'sometimes|nullable|integer|min:0',
            'facts.*.textValue' => 'sometimes|nullable|string|max:255',
            /*
             * الطريق النجميّ (`.*`) لا يقرؤه مولّد العقد أصلًا — يُسقطه
             * `ValidationRuleReader` قبل قراءة قواعده. فتسعَ عشرةَ قيمةً
             * مكتوبةً هنا نسخةٌ بلا مستهلك: لا تُنتج عقدًا ولا تفعل شيئًا
             * سوى أن تنحرف. والقاعدة تُبنى من سجلّ القدرات.
             */
            'fulfillmentStrategies.*' => ['string', Rule::in(ProductCapabilities::FULFILLMENT_STRATEGIES)],
        ], AdminValidation::MESSAGES);
        $this->assertPricing($data, null);

        try {
            $product = DB::transaction(function () use ($data, $actor) {
                $columns = $this->columns($data);
                if (($columns['sku'] ?? null) === null || $columns['sku'] === '') {
                    $columns['sku'] = $this->nextSku();
                }

                // New products always start as drafts: publishing goes through the gate.
                $product = Product::query()->create($columns + ['status' => 'draft']);
                $this->writePrice($product, $data);
                $this->writeFacts($product, $data);
                // الهويّة في سجلّ العناوين تُولد مع المنتج في المعاملة نفسها.
                $this->urls->created($product, $actor->id);

                /*
                 * §٦ — والمجموعة تُولد معه، في المعاملة نفسها.
                 *
                 * خارجها كانت ستبقى لحظةٌ يوجد فيها منتجٌ بلا طبيعةٍ ولا عرضٍ
                 * ولا استراتيجيّة تسليم — وهي بعينها الحالة التي جعلت
                 * `product_nature` مجمَّدةً على ما كتبه ترحيلُ الردم: يقرؤها
                 * البحث والسلّة والدفع، ولا يكتبها أحد.
                 */
                $this->groups->create(
                    $product,
                    $data['productNature'],
                    $data['fulfillmentStrategies'] ?? [],
                );

                return $product;
            });
        } catch (UrlPlatformError $e) {
            return $this->refuseUrl($e);
        }

        $this->audit->log($actor, 'product.created', 'product', $product->id, null, $this->auditSnapshot($product->fresh()), null, $request);

        return response()->json([
            'data' => $this->detail($product->fresh(['price', 'facts', 'stage', 'grade', 'subject', 'semester', 'group']), $request),
        ], 201);
    }

    public function update(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $product = Product::query()->with('price')->findOrFail($id);
        $data = $request->validate([
            /*
             * القواعد مكتوبةٌ حرفيًّا في موضع `validate` لا مبنيّةً في تابع
             * مشترك: مولّد العقد يقرأ المصفوفة **نصًّا** عبر AST، و`$this->rules()`
             * تُنتج جسمَ طلبٍ فارغًا في `openapi.json` و`body: null as never`
             * في العميل المولَّد. فالتكرار هنا ثمنُ عقدٍ صادق، وهو الثمن
             * الذي يدفعه هذا المستودع في كلّ متحكّمٍ آخر للسبب نفسه.
             */
            'sku' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('products', 'sku')->ignore($product->id)],
            'slug' => [
                'sometimes', 'string', 'max:255',
                'regex:/^[^\\s\\/#?%&+\\\\]+$/u',
                Rule::unique('products', 'slug')->ignore($product->id),
            ],
            'slugReason' => 'sometimes|nullable|string|max:500',
            'slugForce' => 'sometimes|boolean',
            'title' => 'sometimes|required|string|max:255',
            /*
             * §٦ — طبيعة المنتج. اختياريّةٌ في التعديل: من لم يُرسلها لم
             * يطلب تبديلًا، وتبديلُها يُعيد كتابة استراتيجيّات التسليم كلّها
             * فلا يقع بالصدفة.
             */
            'productNature' => 'sometimes|in:DIGITAL,PHYSICAL,SERVICE,HYBRID,ACCESS_BASED,COMPOSITE',
            /*
             * §٨ — استراتيجيّات التسليم. فارغةٌ تعني: خذ اقتراح الطبيعة.
             * والهجين وحده لا اقتراح له، فيُطلب منه اختيارٌ صريح.
             */
            'fulfillmentStrategies' => 'sometimes|array|max:19',
            'subtitle' => 'sometimes|nullable|string|max:255',
            'shortDesc' => 'sometimes|nullable|string|max:1000',
            'description' => 'sometimes|nullable|string|max:50000',
            'resourceType' => 'sometimes|nullable|string|max:64',
            'format' => 'sometimes|nullable|string|max:32',
            'stageId' => 'sometimes|nullable|integer|exists:stages,id',
            'gradeId' => 'sometimes|nullable|integer|exists:grades,id',
            'subjectId' => 'sometimes|nullable|integer|exists:subjects,id',
            'semesterId' => 'sometimes|nullable|integer|exists:semesters,id',
            'editable' => ['sometimes', Rule::in(['yes', 'no', 'unset'])],
            'regularMinor' => 'sometimes|integer|min:0|max:100000000',
            'saleMinor' => 'sometimes|nullable|integer|min:0',
            'saleFrom' => 'sometimes|nullable|date',
            'saleTo' => 'sometimes|nullable|date',
            'facts' => 'sometimes|array|max:40',
            'facts.*.key' => 'required|string|max:64',
            'facts.*.intValue' => 'sometimes|nullable|integer|min:0',
            'facts.*.textValue' => 'sometimes|nullable|string|max:255',
            /*
             * الطريق النجميّ (`.*`) لا يقرؤه مولّد العقد أصلًا — يُسقطه
             * `ValidationRuleReader` قبل قراءة قواعده. فتسعَ عشرةَ قيمةً
             * مكتوبةً هنا نسخةٌ بلا مستهلك: لا تُنتج عقدًا ولا تفعل شيئًا
             * سوى أن تنحرف. والقاعدة تُبنى من سجلّ القدرات.
             */
            'fulfillmentStrategies.*' => ['string', Rule::in(ProductCapabilities::FULFILLMENT_STRATEGIES)],
        ], AdminValidation::MESSAGES);
        $this->assertPricing($data, $product->price);
        $before = $this->auditSnapshot($product);

        try {
            DB::transaction(function () use ($product, $data, $actor) {
                $columns = $this->columns($data);
                /*
                 * الرابط لا يتغيّر صامتًا: السجلّ يكتب تاريخه وتحويله في
                 * المعاملة نفسها، ويرفض المقفل ما لم يُطلب التجاوز بصلاحيّته.
                 */
                if (isset($columns['slug']) && $columns['slug'] !== $product->slug) {
                    $this->urls->slugChanging(
                        $product, (string) $columns['slug'], $actor->id, $data['slugReason'] ?? null,
                        (bool) ($data['slugForce'] ?? false) && $actor->allows('slug.override'),
                    );
                }
                if ($columns !== []) {
                    $product->update($columns);
                }
                $this->writePrice($product, $data);
                $this->writeFacts($product, $data);

                /*
                 * §٦ — تبديل الطبيعة يمرّ بالنطاق لا بعمودٍ يُكتب هنا: هو
                 * يُعيد كتابة استراتيجيّات التسليم واستراتيجيّة المخزون معًا،
                 * وتركُها على حالها كان يُنتج منتجًا ماديًّا تسليمُه تنزيلٌ رقميّ.
                 */
                $group = $this->groups->groupFor($product);

                if (array_key_exists('productNature', $data)) {
                    if ($group !== null) {
                        $this->groups->changeNature(
                            $group,
                            $data['productNature'],
                            $data['fulfillmentStrategies'] ?? [],
                        );
                    } else {
                        /*
                         * منتجٌ سبق النموذج الكونيّ: النوع يُنشئ له مجموعةً
                         * بدل أن يُكتب نصفَ كتابة.
                         *
                         * الحارس السابق كان يتخطّى التبديل بصمت، بينما
                         * `columns()` تكتب `products.fulfillment_type` على
                         * كلّ حال. فيخرج الردّ ٢٠٠ ويظنّ المشغّل أنّ النوع
                         * تبدّل، ولا طبيعةَ كُتبت ولا عرضَ ولا استراتيجيّة
                         * تسليم — ولا بوّابةَ نشرٍ ترى النقص.
                         */
                        $this->groups->create(
                            $product->refresh(),
                            $data['productNature'],
                            $data['fulfillmentStrategies'] ?? [],
                        );
                    }
                }

                /*
                 * ثمّ تُنسَخ حقولُ العرض إلى المجموعة. النموذجان يُقرآن معًا —
                 * وثيقةُ البحث تأخذ عنوانها من المجموعة والمتجرُ من المنتج —
                 * فتحريرُ العنوان بلا مزامنةٍ كان يترك البحث يعرض عنوانًا
                 * قديمًا إلى الأبد، بلا خطأٍ يشكو.
                 */
                $this->groups->syncFromLegacy($product->refresh());

                $this->index->updated($product);
            });
        } catch (UrlPlatformError $e) {
            return $this->refuseUrl($e);
        }

        $fresh = $product->fresh(['price', 'facts', 'stage', 'grade', 'subject', 'semester', 'group']);
        $this->audit->log($actor, 'product.updated', 'product', $product->id, $before, $this->auditSnapshot($fresh), null, $request);

        return response()->json(['data' => $this->detail($fresh, $request)]);
    }

    /**
     * Publish gate: 422 listing the REAL missing readiness items; publishing
     * only happens at 100%. The checklist is recomputed from rows on every
     * call — there is no stored score to drift.
     */
    public function publish(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $readiness = ProductReadiness::for($product);

        if (! $readiness->isReady()) {
            return response()->json([
                'message' => 'لا يمكن نشر المنتج قبل اكتمال متطلبات الجاهزية: '.implode('، ', $readiness->toArray()['missingLabels']).'.',
                'code' => 'READINESS_INCOMPLETE',
                'readiness' => $readiness->toArray(),
            ], 422);
        }

        $before = ['status' => $product->status, 'publishedAt' => $product->published_at?->toIso8601String()];
        try {
            DB::transaction(function () use ($product, $actor) {
                $product->update([
                    'status' => 'published',
                    'published_at' => $product->published_at ?? now(),
                ]);
                $this->urls->published($product, $actor->id);
                /* المجموعة تُنشَر مع المنتج — وإلّا بقيت مسوّدةً يُخفيها المتجر. */
                $this->groups->syncFromLegacy($product->refresh());
                $this->index->published($product);
            });
        } catch (UrlPlatformError $e) {
            return $this->refuseUrl($e);
        }
        $fresh = $product->fresh(['price', 'facts', 'stage', 'grade', 'subject', 'semester', 'group']);

        $this->audit->log($actor, 'product.published', 'product', $product->id, $before, [
            'status' => $fresh->status, 'publishedAt' => $fresh->published_at?->toIso8601String(),
        ], null, $request);

        return response()->json([
            'data' => $this->detail($fresh, $request),
            'readiness' => $readiness->toArray(),
        ]);
    }

    /**
     * سحب منتج منشور — الاتجاه المعاكس للنشر، وكان مفقودًا.
     *
     * `retired` حالةٌ مصمَّمة بالكامل: القائمة تُرشِّح بها، و`IndexabilityPolicy`
     * لها حكمٌ خاصّ بها («منتج متقاعد — يُوجَّه إلى البديل ولا يدخل خريطة
     * الموقع»)، ولم يكن في المنتج مسارٌ واحد يبلغها. أي أن ملفًا نُشر بالخطأ أو
     * بطل مع تغيّر المنهج لا يمكن إنزاله إلا بتعديل قاعدة البيانات يدويًّا.
     *
     * الوجهتان المسموحتان هنا `retired` و`draft` وحدهما — والنشر يبقى على
     * `publish` خلف بوابة الجاهزية. لو قَبِل هذا المسار `published` لصار بابًا
     * خلفيًّا يتخطّى البوابة.
     *
     * `published_at` لا يُمحى: هو تاريخ النشر الأوّل، وسجلٌّ لا لافتة حالة.
     */
    public function withdraw(Request $request, AdminUser $actor, int $id): JsonResponse
    {
        $validated = $request->validate([
            'to' => 'required|string|in:retired,draft',
        ], AdminValidation::MESSAGES);

        $product = Product::query()->findOrFail($id);
        $before = ['status' => $product->status];

        if ($product->status === $validated['to']) {
            return response()->json([
                'data' => $this->detail(
                    $product->fresh(['price', 'facts', 'stage', 'grade', 'subject', 'semester', 'group']),
                    $request,
                ),
            ]);
        }

        try {
            DB::transaction(function () use ($product, $validated, $actor) {
                $product->update(['status' => $validated['to']]);
                /* والسحب كذلك: مجموعةٌ تبقى منشورةً بعد سحب منتجها تُباع بعد سحبه. */
                $this->groups->syncFromLegacy($product->refresh());
                $this->urls->withdrawn($product, $actor->id, $validated['to']);
                $this->index->withdrawn($product);
            });
        } catch (UrlPlatformError $e) {
            return $this->refuseUrl($e);
        }
        $fresh = $product->fresh(['price', 'facts', 'stage', 'grade', 'subject', 'semester', 'group']);

        $this->audit->log(
            $actor,
            $validated['to'] === 'retired' ? 'product.retired' : 'product.unpublished',
            'product', $product->id, $before, ['status' => $fresh->status], null, $request,
        );

        return response()->json(['data' => $this->detail($fresh, $request)]);
    }

    /**
     * أوّل رمز حرّ بصيغة `TD-####`.
     *
     * الحلقة تسأل قاعدة البيانات لا الذاكرة، ومحاطة بمعاملة الإنشاء نفسها،
     * فإنشاءان متزامنان لا يتّفقان على رمز واحد. والسقف يمنع حلقة لا تنتهي لو
     * امتلأ المدى — وعندها يُرفع الخطأ بدل أن يدور الطلب إلى الأبد.
     */
    private function nextSku(): string
    {
        $start = (int) Product::query()->max('id') + 1;

        for ($n = $start; $n < $start + 1000; $n++) {
            $candidate = 'TD-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            if (! Product::query()->where('sku', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('تعذّر اشتقاق رمز منتج حرّ — اكتب الرمز يدويًّا.');
    }

    /** @return array<string, mixed> */

    /** camelCase request payload → snake_case columns (the API boundary). */
    /** رفضٌ مسمّى من منصّة العناوين — ٤٠٩ للتصادم والقفل، ٤٢٢ لما سواه. */
    private function refuseUrl(UrlPlatformError $e): JsonResponse
    {
        $conflict = in_array($e->errorCode, ['COLLISION', 'LOCKED', 'PROTECTED_HISTORY', 'STALE_VERSION'], true);

        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode, 'context' => $e->context], $conflict ? 409 : 422);
    }

    private function columns(array $data): array
    {
        $map = [
            'sku' => 'sku', 'slug' => 'slug', 'title' => 'title', 'subtitle' => 'subtitle',
            'shortDesc' => 'short_desc', 'description' => 'description',
            'resourceType' => 'resource_type', 'format' => 'format', 'editable' => 'editable',
            'stageId' => 'stage_id', 'gradeId' => 'grade_id',
            'subjectId' => 'subject_id', 'semesterId' => 'semester_id',
        ];

        $columns = [];
        foreach ($map as $input => $column) {
            if (array_key_exists($input, $data)) {
                $columns[$column] = $data[$input];
            }
        }

        /*
         * العمود القديم يتبع الطبيعة الجديدة ولا يستقلّ عنها.
         *
         * `products.fulfillment_type` يُقرأ في المتجر والبحث والسلّة، وقيمته
         * على كلّ صفٍّ اليوم `digital` لأنّه افتراضُ المخطّط ولا كاتبَ له.
         * وتركُه بلا كتابةٍ بعد إتاحة الطبيعة كان سيُنتج منتجًا ماديًّا يقول
         * عمودُه إنّه رقميّ — ونسختان من الحقيقة إحداهما كاذبة أسوأ من واحدة.
         */
        if (array_key_exists('productNature', $data)) {
            $legacy = ProductCapabilities::legacyOfNature($data['productNature']);

            if ($legacy !== null) {
                $columns['fulfillment_type'] = $legacy;
            }
        }

        return $columns;
    }

    /**
     * السعر يُفحص على الحالة الناتجة لا على الحمولة وحدها.
     *
     * `PATCH` جزئيّ: قد تُرسل نهاية النافذة وحدها والبداية محفوظة، وقد يُرسل
     * السعر الأساسي وحده وسعر التخفيض محفوظ. لذلك تُدمج القيم المُرسَلة مع
     * المخزَّنة قبل المقارنة.
     *
     * هذا يحلّ عيبين حقيقيّين في `lt:regularMinor` وحده:
     *  · إرسال `saleMinor` دون `regularMinor` كان يرمي استثناء مقارنة أنواع
     *    (خطأ 500) لأنّ الحقل المرجعي غائب — طلبٌ لا ينجح أبدًا.
     *  · إنزال `regularMinor` وحده تحت سعر تخفيضٍ محفوظ كان يمرّ، فيبيع
     *    المتجر بسعر «تخفيض» أعلى من السعر الأساسي و`discountPct` صفر.
     */
    private function assertPricing(array $data, ?ProductPrice $existing): void
    {
        $regular = array_key_exists('regularMinor', $data)
            ? (int) $data['regularMinor']
            : $existing?->regular_minor;
        $sale = array_key_exists('saleMinor', $data)
            ? ($data['saleMinor'] === null ? null : (int) $data['saleMinor'])
            : $existing?->sale_minor;

        if ($sale !== null && $regular !== null && $sale >= (int) $regular) {
            throw ValidationException::withMessages([
                'saleMinor' => 'سعر التخفيض يجب أن يكون أقل من السعر الأساسي.',
            ]);
        }

        $from = array_key_exists('saleFrom', $data) ? $data['saleFrom'] : $existing?->sale_from;
        $to = array_key_exists('saleTo', $data) ? $data['saleTo'] : $existing?->sale_to;

        // الغياب (`null`) حدٌّ مفتوح يقبله محرّك التسعير — لا يُفحص إلا المُحدَّدان.
        if ($from !== null && $to !== null && Carbon::parse($from) >= Carbon::parse($to)) {
            throw ValidationException::withMessages([
                'saleTo' => 'تاريخ انتهاء التخفيض يجب أن يكون بعد تاريخ بدايته.',
            ]);
        }
    }

    private function writePrice(Product $product, array $data): void
    {
        $values = [];

        if (array_key_exists('regularMinor', $data)) {
            $values['regular_minor'] = (int) $data['regularMinor'];
            $values['currency'] = 'SAR';
        }
        if (array_key_exists('saleMinor', $data)) {
            $values['sale_minor'] = $data['saleMinor'] === null ? null : (int) $data['saleMinor'];
        }
        /*
         * `setTimezone` ليست تجميلًا: العمودان بلا منطقة زمنية، والقراءة
         * تَسِمهما بـ`app.timezone` (Eloquent::asDateTime). فحين يصل من المحرّر
         * وقتٌ بإزاحته — وهو ما يرسله حقل datetime-local بعد تحويله — كان
         * `Carbon::parse` يبقيه على إزاحته فيُخزَّن جدارُ ساعته هو، ثم يُقرأ
         * كأنّه بتوقيت التطبيق: انزياحٌ صامت بمقدار الفرق.
         *
         * قِسته: إرسال منتصف ليل ١ سبتمبر بالرياض (21:00Z) خُزِّن
         * '2026-08-31 21:00:00' وعاد '2026-08-31T21:00:00+03:00' — أبكر بثلاث
         * ساعات. وليس عرضًا خاطئًا وحسب: `PricingService` تقارن الحدّين
         * بـ`now()` المَوسومة صحيحًا، فيبدأ التخفيض وينتهي قبل موعده.
         */
        foreach (['saleFrom' => 'sale_from', 'saleTo' => 'sale_to'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $values[$column] = $data[$input] === null
                    ? null
                    : Carbon::parse($data[$input])->setTimezone(config('app.timezone'));
            }
        }

        if ($values === []) {
            return;
        }

        $existing = ProductPrice::query()->where('product_id', $product->id)->first();

        /*
         * `regular_minor` عمودٌ إلزامي، فصفّ سعرٍ جديد لا يُنشأ بنافذة تخفيض
         * وحدها: يُقال ذلك صراحةً بدل أن ينفجر الطلب بخطأ قاعدة بيانات.
         */
        if ($existing === null) {
            if (! array_key_exists('regular_minor', $values)) {
                throw ValidationException::withMessages([
                    'regularMinor' => 'اضبط السعر الأساسي قبل جدولة تخفيض.',
                ]);
            }

            ProductPrice::query()->create($values + ['product_id' => $product->id]);

            return;
        }

        $existing->update($values);
    }

    private function writeFacts(Product $product, array $data): void
    {
        if (! array_key_exists('facts', $data)) {
            return;
        }

        $keys = [];
        foreach ($data['facts'] as $fact) {
            $keys[] = $fact['key'];
            ProductFact::query()->updateOrCreate(
                ['product_id' => $product->id, 'key' => $fact['key']],
                ['int_value' => $fact['intValue'] ?? null, 'text_value' => $fact['textValue'] ?? null],
            );
        }

        ProductFact::query()->where('product_id', $product->id)->whereNotIn('key', $keys ?: [''])->delete();
    }

    private function row(Product $product, Request $request, ProductReadiness $readiness): array
    {
        return (new ProductCardResource($product))->toArray($request) + [
            'status' => $product->status,
            'readiness' => $readiness->toArray(),
        ];
    }

    private function detail(Product $product, Request $request): array
    {
        $readiness = ProductReadiness::for($product);

        return $this->row($product, $request, $readiness) + [
            'subtitle' => $product->subtitle,
            'shortDesc' => $product->short_desc,
            'description' => $product->description,
            'editableState' => $product->editable,
            'stageId' => $product->stage_id,
            'gradeId' => $product->grade_id,
            'subjectId' => $product->subject_id,
            'semesterId' => $product->semester_id,
            'saleMinor' => $product->price?->sale_minor,
            /*
             * النافذة تُعاد خامًا كما هي مخزَّنة — لا كما يراها المتجر. بطاقة
             * المنتج تُظهر `saleTo` حين يكون التخفيض فعّالًا فقط، والمحرّر
             * يحتاج التاريخين دائمًا كي يعرضهما ويعدّلهما ولو كانت النافذة
             * مستقبليّة أو منتهية. `onSale` من البطاقة يبقى مرجع الفعليّة.
             */
            'saleFrom' => $product->price?->sale_from?->toIso8601String(),
            'saleTo' => $product->price?->sale_to?->toIso8601String(),
            'facts' => $product->facts->map(fn (ProductFact $f) => [
                'key' => $f->key,
                'intValue' => $f->int_value,
                'textValue' => $f->text_value,
            ])->values(),
            'fileCount' => DB::table('product_files')->where('product_id', $product->id)->count(),
            'imageCount' => DB::table('product_media')->where('product_id', $product->id)->count(),
        ];
    }

    private function auditSnapshot(Product $product): array
    {
        return [
            'sku' => $product->sku,
            'slug' => $product->slug,
            'title' => $product->title,
            'status' => $product->status,
            'stageId' => $product->stage_id,
            'gradeId' => $product->grade_id,
            'subjectId' => $product->subject_id,
            'semesterId' => $product->semester_id,
            'regularMinor' => $product->price?->regular_minor,
            'saleMinor' => $product->price?->sale_minor,
            // نافذة التخفيض تغيّر السعر المعروض فعليًّا، فهي تدخل السجلّ كالسعر.
            'saleFrom' => $product->price?->sale_from?->toIso8601String(),
            'saleTo' => $product->price?->sale_to?->toIso8601String(),
        ];
    }
}
