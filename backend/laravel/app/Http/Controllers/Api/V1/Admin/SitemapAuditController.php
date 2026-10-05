<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Sitemaps\Models\SitemapAudit;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * قراءة تدقيق خريطة الموقع — الطرف الذي كان يفصل أمرًا يعمل عن شاشةٍ مغلقة.
 *
 * `sitemap:audit` مجدولٌ كلّ ليلة ويقيس النقاء والتغطية وما طُرد ولماذا،
 * وكان يطبع ذلك على مخرَجٍ قياسيّ لا يقرؤه أحد في حاوية. وبوّابة شاشة
 * `/seo-health/sitemap` تسمّي الناقص: «تخزين نتائج `sitemap:audit` وعرضها».
 * التخزين في الأمر، والعرض يبدأ هنا.
 *
 * ─── قراءةٌ فقط، بقرار ───
 *
 * لا مسار يُشغّل التدقيق من الشاشة. التدقيق يمرّ على الكتالوج كلّه، ونقطةٌ
 * إداريّة تفعل ذلك بضغطة زرّ هي باب استنزافٍ بيدٍ صديقة — والسبب نفسه الذي
 * جعل بناء الخريطة ليليًّا لا عند الطلب. والمشغّل يقرأ آخر قياسٍ ومتى جرى،
 * فيعرف إن كان بائتًا.
 */
class SitemapAuditController extends Controller
{
    /** كم ليلةً تُعاد — يكفي أسبوعان لقراءة الاتّجاه بلا تحميل الشاشة. */
    private const HISTORY = 14;

    public function show(): JsonResponse
    {
        $latest = SitemapAudit::query()->orderByDesc('ran_at')->orderByDesc('id')->first();

        if ($latest === null) {
            /*
             * لا قياسَ بعد ≠ خريطةٌ نظيفة.
             *
             * `latest: null` صريحةٌ كي تعرض الشاشة «لم يجرِ تدقيقٌ بعد» بدل
             * أن تُقرأ الأصفار نتيجةً. والمجدوِل يملأها في أوّل ٠٣:٤٠.
             */
            return response()->json(['latest' => null, 'history' => []]);
        }

        return response()->json([
            'latest' => [
                'ranAt' => $latest->ran_at?->toIso8601String(),
                'clean' => $latest->clean,
                'offered' => $latest->offered,
                'kept' => $latest->kept,
                'expelled' => $latest->expelled,
                /* `null` = «غير مقيس»: لا روابط عُرضت فالنقاء مجهول. */
                'coveragePct' => $latest->coverage_pct === null ? null : (float) $latest->coverage_pct,
                'missingCount' => $latest->missing_count,
                /* التقرير كما قاسه المدقّق: العيّنات وأسبابها والمولّدات الخاملة. */
                'report' => $latest->report,
            ],
            /* الاتّجاه: ليلةٌ بطردٍ أقلّ من سابقتها دليلُ إصلاح، ولا يُقرأ من صفٍّ واحد. */
            'history' => SitemapAudit::query()
                ->orderByDesc('ran_at')->orderByDesc('id')
                ->limit(self::HISTORY)
                ->get(['ran_at', 'clean', 'offered', 'kept', 'expelled', 'coverage_pct', 'missing_count'])
                ->map(fn (SitemapAudit $row) => [
                    'ranAt' => $row->ran_at?->toIso8601String(),
                    'clean' => $row->clean,
                    'offered' => $row->offered,
                    'kept' => $row->kept,
                    'expelled' => $row->expelled,
                    'coveragePct' => $row->coverage_pct === null ? null : (float) $row->coverage_pct,
                    'missingCount' => $row->missing_count,
                ])
                ->values(),
        ]);
    }
}
