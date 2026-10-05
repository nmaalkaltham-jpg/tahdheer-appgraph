<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Navigation\LinkChecker;
use App\Domain\Navigation\MenuTree;
use App\Domain\Navigation\Models\NavigationMenu;
use App\Domain\Navigation\Models\NavigationPublishOperation;
use App\Domain\Navigation\Models\NavigationRevision;
use App\Domain\Navigation\NavigationPublishContract;
use App\Domain\Navigation\NavigationPublishFailed;
use App\Domain\Navigation\NavigationPublishService;
use App\Domain\Outbox\OutboxWriter;
use App\Domain\SEO\SiteIdentity;
use App\Domain\UrlPlatform\UrlRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * قوائم التنقّل: الحفظ والنشر وفحص الوجهات وجرد الصفحات.
 *
 * ورشة التنقّل كانت كاملةً في الواجهة وبلا خادم إطلاقًا — تُبنى الشجرة ثم
 * تزول بإعادة التحميل، والمتجر يعرض قائمة مكتوبة في ملفّ. هذا هو الخادم.
 *
 * المنشور وحده يصل المتجر. المسوّدة تخصّ المحرّر: تسريبها يجعل كل سحب عنصر
 * تغييرًا فوريًّا في ترويسة كل زائر.
 */
final class NavigationController
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly OutboxWriter $outbox,
        private readonly SiteIdentity $site,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => NavigationMenu::query()->orderBy('location')->get()
                ->map(fn (NavigationMenu $m): array => $this->payload($m))->all(),
            'meta' => [
                'locations' => MenuTree::LOCATIONS,
                'maxDepth' => MenuTree::MAX_DEPTH,
                'maxNodes' => MenuTree::MAX_NODES,
                'types' => MenuTree::TYPES,
                // عقد النشر من الخادم: الحوار يعرض ما يُنفَّذ فعلًا لا قائمةً مكتوبة فيه.
                'publishContract' => NavigationPublishContract::describe(),
            ],
        ]);
    }

    public function show(string $location): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->findOrCreate($location))]);
    }

    public function update(Request $request, string $location): JsonResponse
    {
        $validated = $request->validate([
            'nodes' => 'required|array|max:'.MenuTree::MAX_NODES,
            // النسخة إلزامية: بدونها آخر حافظ يفوز ولو سحق عمل زميله.
            'version' => 'required|integer|min:1',
            'name' => 'sometimes|string|max:160',
        ]);

        $menu = $this->findOrCreate($location);
        $normalized = MenuTree::normalize($validated['nodes']);

        $saved = DB::transaction(function () use ($menu, $normalized, $validated) {
            /** @var NavigationMenu $fresh */
            $fresh = NavigationMenu::query()->lockForUpdate()->findOrFail($menu->id);

            if ((int) $fresh->version !== (int) $validated['version']) {
                return null;
            }

            $next = (int) $fresh->version + 1;

            $fresh->forceFill(array_filter([
                'name' => $validated['name'] ?? null,
            ], static fn ($v): bool => $v !== null) + [
                'draft_nodes' => $normalized,
                'version' => $next,
                'updated_by' => $this->actor()?->getKey(),
            ])->save();

            /*
             * كل مسوّدة محفوظة إصدار. هذا هو مخزن اللقطات: التراجع يستعيد
             * شجرةً كُتبت فعلًا، لا إعادةَ بناءٍ من ذاكرة أو من فرقٍ محسوب.
             */
            NavigationRevision::query()->create([
                'menu_id' => $fresh->id,
                'revision' => $next,
                'nodes' => $normalized,
                'fingerprint' => MenuTree::fingerprint($normalized),
                'node_count' => count($normalized),
                'created_by' => $this->actor()?->getKey(),
                'created_at' => now(),
            ]);

            return $fresh;
        });

        if ($saved === null) {
            return response()->json([
                'message' => 'عدّل مشرف آخر هذه القائمة بينما كنت تحرّرها. راجع التغييرات قبل الحفظ.',
                'code' => 'VERSION_CONFLICT',
                'data' => $this->payload($menu->refresh()),
            ], 409);
        }

        $this->audit->log(
            $this->actor(), 'navigation.updated', 'navigation_menu', $saved->id,
            null, ['location' => $saved->location, 'nodes' => count($normalized)], null, $request,
        );

        return response()->json([
            'data' => $this->payload($saved),
            // ما أُسقط في التطبيع يُقال صراحةً: عنصر يختفي بلا سبب يبدو عطلًا.
            'dropped' => count($validated['nodes']) - count($normalized),
            // فرقٌ عن المنشور الحالي — يُعرض قبل النشر لا بعده.
            'diff' => MenuTree::diff((array) ($menu->published_nodes ?? []), $normalized),
        ]);
    }

    /**
     * ينشر عبر المنسّق: قفل موزّع، فحص مسبق، لقطة، التزام، إبطال، تحقّق.
     *
     * المتصفّح لا ينسّق هذه الخطوات: إغلاق تبويب في منتصفها يترك النظام في
     * حالة لا يعرفها أحد ولا يستطيع أحد إتمامها.
     */
    public function publish(Request $request, string $location, NavigationPublishService $publisher): JsonResponse
    {
        $validated = $request->validate([
            // مفتاح التكرار: إعادة المحاولة بعد انقطاع تقرأ العملية القائمة.
            'idempotencyKey' => 'required|string|max:100',
            'baseRevision' => 'sometimes|nullable|integer|min:1',
        ]);

        $menu = $this->findOrCreate($location);

        try {
            $operation = $publisher->publish(
                $menu,
                $validated['idempotencyKey'],
                $validated['baseRevision'] ?? null,
                $this->actor(),
            );
        } catch (NavigationPublishFailed $e) {
            // مفتاحٌ مستعمَل لقائمة أخرى: خطأ مدخلٍ يُقال، لا عطلُ خادم.
            return response()->json(['message' => $e->getMessage(), 'code' => 'IDEMPOTENCY_KEY_REUSED'], 422);
        }

        /*
         * الفعل يتبع النتيجة.
         *
         * كان كلّ نداء يُسجَّل `navigation.published` ولو رفضه الفحص المسبق،
         * فيقرأ المدقّق عشر عمليّات نشرٍ لموقعٍ لم يتغيّر فيه شيء.
         */
        $succeeded = $operation->status === $operation::COMPLETED;
        $this->audit->log(
            $this->actor(), $succeeded ? 'navigation.published' : 'navigation.publish_failed',
            'navigation_menu', $menu->id,
            null, array_filter([
                'location' => $menu->location,
                'operation' => $operation->id,
                'status' => $operation->status,
                'failureCode' => $operation->failure_code,
            ], static fn ($v): bool => $v !== null),
            null, $request,
        );

        return response()->json([
            'data' => $this->payload($menu->refresh()),
            'operation' => $this->operationPayload($operation),
        ], $operation->status === $operation::COMPLETED ? 200 : 409);
    }

    /** حالة عملية نشر — تُستجوَب بعد انقطاع بدل إعادة النشر. */
    public function operation(string $location, string $operationId): JsonResponse
    {
        $operation = NavigationPublishOperation::query()
            ->whereKey($operationId)
            ->where('menu_id', $this->findOrCreate($location)->id)
            ->firstOrFail();

        return response()->json(['data' => $this->operationPayload($operation)]);
    }

    /** يتراجع إلى الإصدار الذي كان منشورًا قبل عملية بعينها. */
    public function rollback(Request $request, string $location, NavigationPublishService $publisher): JsonResponse
    {
        $validated = $request->validate(['operationId' => 'required|string|size:36']);

        $menu = $this->findOrCreate($location);

        /*
         * العمليّة تُقيَّد بالقائمة.
         *
         * كان البحث بالمعرّف وحده: معرّفُ عمليّةِ الترويسة مع مسار الذيل
         * يستعيد لقطةَ الترويسة **في الذيل** — استعادةٌ من موقعٍ إلى آخر،
         * ولا شيء في الردّ يقول إنّ ذلك ما وقع.
         */
        $operation = NavigationPublishOperation::query()
            ->whereKey($validated['operationId'])
            ->where('menu_id', $menu->id)
            ->firstOrFail();

        $rolled = $publisher->rollback($operation, $menu, $this->actor());

        $succeeded = $rolled->status === $rolled::ROLLED_BACK;
        $this->audit->log(
            $this->actor(), $succeeded ? 'navigation.rolled_back' : 'navigation.rollback_failed',
            'navigation_menu', $menu->id,
            null, array_filter([
                'operation' => $rolled->id,
                'status' => $rolled->status,
                'failureCode' => $rolled->failure_code,
            ], static fn ($v): bool => $v !== null), null, $request,
        );

        return response()->json([
            'data' => $this->payload($menu->refresh()),
            'operation' => $this->operationPayload($rolled),
        ], $rolled->status === $rolled::ROLLED_BACK ? 200 : 409);
    }

    /** @return array<string, mixed> */
    private function operationPayload(NavigationPublishOperation $o): array
    {
        return [
            'id' => $o->id,
            'status' => $o->status,
            'currentStep' => $o->current_step,
            'baseRevision' => $o->base_revision,
            'targetRevision' => $o->target_revision,
            'snapshotRevision' => $o->snapshot_revision,
            // السبب يُعاد برمزه: الواجهة تعرض جملةً مفهومة لا «تعذّر».
            'failureCode' => $o->failure_code,
            'failureDetail' => $o->failure_detail,
            'verifiedAt' => $o->verified_at?->toIso8601String(),
            'completedAt' => $o->completed_at?->toIso8601String(),
            // أثر ما نُفِّذ فعلًا — تقابل معرّفاته معرّفات `publishContract`.
            'steps' => array_values((array) ($o->steps ?? [])),
        ];
    }

    /**
     * يفحص وجهة رابط فعليًّا.
     *
     * محمي من SSRF في `LinkChecker`؛ ومحدود المعدّل لأن حلقة فحصٍ من اللوحة
     * تجعل خادمنا مصدر طلبات لموقع خارجي.
     */
    public function linkCheck(Request $request, LinkChecker $checker): JsonResponse
    {
        $validated = $request->validate([
            'urls' => 'required|array|min:1|max:20',
            'urls.*' => 'required|string|max:500',
        ], [
            'urls.max' => 'الحدّ الأقصى عشرون وجهة في الطلب الواحد.',
        ]);

        $origin = rtrim($this->site->url('/'), '/');
        $registry = app(UrlRegistry::class);

        return response()->json([
            'data' => array_map(
                static function (string $url) use ($checker, $origin, $registry): array {
                    $result = $checker->check($url, $origin);
                    /*
                     * الداخليّ يُسأل عنه سجلّ العناوين أيضًا: النداء الشبكيّ يقول
                     * «٢٠٠» لرابطٍ محوَّل بعد قفزة، والسجلّ يقول إنّه رابطٌ قديم
                     * والقانونيّ غيره — وهو ما يهمّ عنصر قائمةٍ داخليّ.
                     */
                    if (str_starts_with($url, '/')) {
                        $resolution = $registry->resolve($url);
                        $result['registry'] = [
                            'outcome' => $resolution['outcome'],
                            'httpStatus' => $resolution['httpStatus'],
                            'canonical' => $resolution['canonical'],
                            'redirectTo' => $resolution['redirect']['target'] ?? null,
                            'steps' => $resolution['steps'],
                        ];
                    }

                    return $result;
                },
                array_values($validated['urls']),
            ),
        ]);
    }

    /**
     * جرد الصفحات الثابتة التي يخدمها المتجر.
     *
     * المصدر هو خريطة مسارات SEO القائمة — لا قائمة ثانية تُحدَّث يدويًّا
     * وتنحرف عن المسارات الحقيقية.
     */
    public function pages(): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (array $row): array => [
                    'url' => $row['path'],
                    'label' => $row['label'],
                ],
                self::STATIC_PAGES,
            ),
        ]);
    }

    /** @var list<array{path: string, label: string}> */
    private const STATIC_PAGES = [
        ['path' => '/', 'label' => 'الرئيسية'],
        ['path' => '/shop', 'label' => 'المتجر'],
        ['path' => '/stages', 'label' => 'المراحل'],
        ['path' => '/offers', 'label' => 'العروض'],
        ['path' => '/new', 'label' => 'الجديد'],
        ['path' => '/best-sellers', 'label' => 'الأكثر مبيعًا'],
        ['path' => '/bundles', 'label' => 'الحزم'],
        ['path' => '/blog', 'label' => 'المدوّنة'],
        ['path' => '/about', 'label' => 'من نحن'],
        ['path' => '/contact', 'label' => 'تواصل معنا'],
        ['path' => '/help', 'label' => 'المساعدة'],
        ['path' => '/how-it-works', 'label' => 'كيف يعمل'],
        ['path' => '/payment-methods', 'label' => 'طرق الدفع'],
        ['path' => '/policies/refund', 'label' => 'سياسة الاسترجاع'],
        ['path' => '/policies/privacy', 'label' => 'سياسة الخصوصية'],
        ['path' => '/policies/terms', 'label' => 'الشروط والأحكام'],
        ['path' => '/policies/cookies', 'label' => 'سياسة الكوكيز'],
    ];

    private function findOrCreate(string $location): NavigationMenu
    {
        abort_unless(array_key_exists($location, MenuTree::LOCATIONS), 404);

        return NavigationMenu::query()->firstOrCreate(
            ['location' => $location],
            [
                'name' => MenuTree::LOCATIONS[$location],
                'draft_nodes' => [],
                'version' => 1,
            ],
        );
    }

    /** @return array<string, mixed> */
    private function payload(NavigationMenu $m): array
    {
        return [
            'location' => $m->location,
            'name' => $m->name,
            'version' => (int) $m->version,
            'publishedVersion' => $m->published_version === null ? null : (int) $m->published_version,
            'publishedAt' => $m->published_at?->toIso8601String(),
            'hasUnpublishedChanges' => $m->published_version === null
                || (int) $m->published_version !== (int) $m->version,
            'draft' => (array) $m->draft_nodes,
            'published' => $m->published_nodes === null ? null : (array) $m->published_nodes,
        ];
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
