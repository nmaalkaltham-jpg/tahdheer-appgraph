<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Redirects\RedirectRule;
use App\Domain\Redirects\RedirectService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * حل التحويلات وتسجيل 404 (MIP §7.7).
 *
 * The storefront consults `resolve` BEFORE rendering its 404, which is the
 * «redirect table → route → 404 page» order.
 */
class RedirectController extends Controller
{
    public function __construct(private readonly RedirectService $redirects) {}

    /** GET /api/v1/redirects/resolve?path=/old-path */
    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:2048',
        ]);

        $match = $this->redirects->match($validated['path']);

        if ($match === null) {
            // Absence is a normal answer, not an error.
            return response()->json(['match' => null]);
        }

        $this->redirects->recordHit($match->ruleId);

        return response()->json(['match' => $match->toArray()]);
    }

    /** POST /api/v1/not-found — aggregate, rate-limited. */
    /**
     * كل القواعد المفعَّلة، مضغوطة — للوسيط في الواجهة.
     *
     * لماذا قائمة لا استعلامًا لكل مسار: الوسيط يعمل على **كل** طلب، ونداءُ
     * شبكة لكل زيارة يضيف رحلة ذهاب وإياب إلى كل صفحة مقابل جدول من عشرات
     * الصفوف. الوسيط يجلب القائمة مرّة ويطابق محلّيًّا.
     *
     * القواعد سلوك عامّ أصلًا (يراه أي زائر بتجربة الرابط)، فلا سرّ يُكشف.
     */
    public function rules(Request $request): JsonResponse
    {
        $rules = RedirectRule::query()
            ->where('enabled', true)
            ->orderByRaw('length(from_path) desc')
            ->get(['from_path', 'to_path', 'status_code']);

        // بصمة المجموعة: الوسيط يعرف بها متى تغيّرت بلا مقارنة كاملة.
        $revision = substr(hash('sha256', $rules->toJson()), 0, 12);
        $etag = '"'.$revision.'"';

        /*
         * ─── لماذا ٣٠٤ ───
         *
         * الوسيط كان يُخبّئ القائمة خمس دقائق، فقاعدةٌ تُحفظ الآن لا تعمل
         * قبل خمس. وتقصيرُ المهلة وحده كان سيضاعف الطلبات على كلّ نسخة
         * وسيط. الطلب الشرطيّ يحلّ الاثنين: مراجعةٌ متكرّرة بجوابٍ بلا جسم
         * حين لا شيء تغيّر، وجسمٌ كامل حين تغيّر — فالإبطال فوريّ عمليًّا
         * وتكلفتُه ترويسة.
         */
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders(['ETag' => $etag]);
        }

        return response()->json([
            'data' => $rules->map(static fn ($r): array => [
                'from' => $r->from_path,
                'to' => $r->to_path,
                'status' => (int) $r->status_code,
            ])->all(),
            'revision' => $revision,
        ])->withHeaders(['ETag' => $etag]);
    }

    public function reportNotFound(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:2048',
            'referer' => 'nullable|string|max:2048',
        ]);

        $hit = $this->redirects->recordNotFound(
            $validated['path'],
            $validated['referer'] ?? null,
        );

        return response()->json([
            'path' => $hit->path,
            'hits' => (int) $hit->hits,
            'firstSeenAt' => $hit->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $hit->last_seen_at?->toIso8601String(),
        ], 202);
    }
}
