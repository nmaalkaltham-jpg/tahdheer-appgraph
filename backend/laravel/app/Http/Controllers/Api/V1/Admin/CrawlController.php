<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Crawl\CrawlJob;
use App\Domain\Crawl\Models\CrawledPage;
use App\Domain\Crawl\Models\CrawlRun;
use App\Domain\Crawl\Models\InternalLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * رسم الروابط الداخلية: اليتامى والمكسورة والمدفونة.
 *
 * لا درجات. حالاتٌ بأسبابها: صفحة إمّا يشير إليها رابط أو لا، وإمّا تُجيب 200
 * أو لا. الرقم المركّب («صحّة الروابط ٨٧٪») يُقرأ حكمًا ولا يُصلَح منه شيء.
 */
final class CrawlController
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function show(): JsonResponse
    {
        $run = CrawlRun::query()->where('status', 'completed')->latest('id')->first();

        if ($run === null) {
            // لا جولة بعد: حالة صريحة لا أصفار تُقرأ «كل شيء سليم».
            return response()->json(['data' => null, 'message' => 'لم تُشغَّل جولة زحف بعد.']);
        }

        $pages = CrawledPage::query()->where('run_id', $run->id);

        /*
         * الجولة المكتملة السابقة: المرجع الوحيد الصادق لقياس «قبل/بعد».
         * null يعني «لا جولة سابقة» — لا أصفار تُقرأ «لا تغيير».
         */
        $previous = CrawlRun::query()->where('status', 'completed')
            ->where('id', '<', $run->id)->latest('id')->first();

        return response()->json(['data' => [
            'runId' => $run->id,
            'startedAt' => $run->started_at?->toIso8601String(),
            'finishedAt' => $run->finished_at?->toIso8601String(),
            'totals' => [
                'pages' => $run->pages,
                'links' => $run->links,
                'broken' => $run->broken,
                'orphans' => $run->orphans,
                'maxDepth' => $run->max_depth,
            ],
            'previousRun' => $previous === null ? null : [
                'runId' => $previous->id,
                'finishedAt' => $previous->finished_at?->toIso8601String(),
                'totals' => [
                    'pages' => $previous->pages,
                    'links' => $previous->links,
                    'broken' => $previous->broken,
                    'orphans' => $previous->orphans,
                    'maxDepth' => $previous->max_depth,
                ],
                // موجب = زاد عن الجولة السابقة. في المكسورة واليتامى الموجب تدهور.
                'deltas' => [
                    'pages' => $run->pages - $previous->pages,
                    'links' => $run->links - $previous->links,
                    'broken' => $run->broken - $previous->broken,
                    'orphans' => $run->orphans - $previous->orphans,
                    'maxDepth' => $run->max_depth - $previous->max_depth,
                ],
            ],
            // صفحة تُجيب 200 ولا يشير إليها رابط: لا يصلها زائر ولا زاحف.
            'orphans' => (clone $pages)->where('status', 200)->where('inbound', 0)
                ->where('path', '!=', '/')->orderBy('path')
                ->limit(50)->get(['path', 'title', 'discovered_from'])->all(),
            'broken' => (clone $pages)->where('status', '>=', 400)->orderBy('path')
                ->limit(50)->get(['path', 'status'])->all(),
            // المدفونة: ثلاث نقرات فأكثر — بعيدة عن كل مدخل.
            'deep' => (clone $pages)->where('status', 200)->where('depth', '>=', 3)
                ->orderByDesc('depth')->limit(50)->get(['path', 'depth', 'inbound'])->all(),
            'redirects' => (clone $pages)->whereBetween('status', [300, 399])
                ->limit(50)->get(['path', 'status', 'redirect_to'])->all(),
            // مراسٍ عامّة: «اضغط هنا» لا تقول لمحرّك البحث ولا للقارئ ما الوجهة.
            'genericAnchors' => InternalLink::query()->where('run_id', $run->id)
                ->whereIn('anchor', ['اضغط هنا', 'هنا', 'المزيد', 'اقرأ المزيد', 'التفاصيل', 'رابط'])
                ->limit(30)->get(['from_path', 'to_path', 'anchor'])->all(),
        ]]);
    }

    /**
     * صفّ مسار واحد من آخر جولة مكتملة — عدسة مفتّش الروابط.
     *
     * show() يعرض أعلى 50 من كل قائمة، فالصفحة السليمة العادية غير مرئية
     * فيه. هنا يُجاب سؤال «ماذا رأى الزاحف في هذا المسار تحديدًا؟» لأي
     * مسار كان. غياب الصفّ حقيقة تُقال، لا تُستنتج من صمت القوائم.
     */
    public function page(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500|regex:~^/~',
        ], AdminValidation::MESSAGES);

        $run = CrawlRun::query()->where('status', 'completed')->latest('id')->first();

        if ($run === null) {
            return response()->json(['data' => null, 'message' => 'لم تُشغَّل جولة زحف بعد.']);
        }

        $page = CrawledPage::query()->where('run_id', $run->id)
            ->where('path', $validated['path'])->first();

        return response()->json([
            'data' => [
                'runId' => $run->id,
                'finishedAt' => $run->finished_at?->toIso8601String(),
                'page' => $page === null ? null : [
                    'path' => $page->path,
                    'status' => $page->status,
                    'inbound' => $page->inbound,
                    'depth' => $page->depth,
                    'redirectTo' => $page->redirect_to,
                    'title' => $page->title,
                    'discoveredFrom' => $page->discovered_from,
                ],
            ],
        ] + ($page === null
            ? ['message' => 'لم يمرّ الزاحف على هذا المسار في آخر جولة مكتملة.']
            : []));
    }

    public function run(Request $request): JsonResponse
    {
        CrawlJob::dispatch((int) $request->integer('pages', 400));

        $this->audit->log(
            $this->actor(), 'crawl.requested', 'crawl', 0, null, null, null, $request,
        );

        return response()->json(['message' => 'بدأ الزحف في الخلفية. تابع النتيجة من هذه الشاشة.'], 202);
    }

    private function actor(): ?AdminUser
    {
        return app()->bound(AdminUser::class) ? app(AdminUser::class) : null;
    }
}
