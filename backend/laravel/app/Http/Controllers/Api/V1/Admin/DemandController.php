<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Demand\MaterialRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * صندوق الطلبات الفائتة — ما طُلب ولم يوجد، مرتّبًا بالشدّة.
 *
 * ليس بريدًا وارِدًا يُقرأ ويُؤرشَف، بل تقريرُ طلبٍ يقود قرار الإنتاج: الترتيب
 * بعدد من طلبوا الشيء نفسه، لا بالأحدث — لأن السؤال هو «ما أكثر ما يُطلب ولا
 * نملكه»، لا «ما آخر رسالة وصلت».
 *
 * وسيلة التواصل بيانات شخصية: تُعاد مُقنَّعة في القائمة، ولا تُكشف كاملة إلا
 * عند فتح الطلب الواحد — فقائمةٌ تعرض مئة بريد كامل على الشاشة تسريبٌ بحكم
 * التصميم.
 */
class DemandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:all,new,planned,published,declined',
            'source' => 'sometimes|string|in:all,form,zero_result',
        ]);

        $status = $validated['status'] ?? 'new';
        $source = $validated['source'] ?? 'all';

        $query = MaterialRequest::query()->with(['stage', 'grade', 'subject', 'semester']);

        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($source !== 'all') {
            $query->where('source', $source);
        }

        $rows = $query
            ->orderByDesc('hits')
            ->orderByDesc('last_seen_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $rows->map(fn (MaterialRequest $row) => $this->present($row))->all(),
            'counts' => $this->counts(),
            'statuses' => MaterialRequest::STATUSES,
        ]);
    }

    public function update(Request $request, MaterialRequest $materialRequest): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', MaterialRequest::STATUSES),
            'decisionNote' => 'nullable|string|max:1000',
        ]);

        $materialRequest->forceFill([
            'status' => $validated['status'],
            'decision_note' => $validated['decisionNote'] ?? $materialRequest->decision_note,
        ])->save();

        return response()->json(['data' => $this->present($materialRequest->fresh())]);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        $counts = MaterialRequest::query()
            ->groupBy('status')
            ->selectRaw('status, count(*) as aggregate')
            ->pluck('aggregate', 'status')
            ->map(static fn ($n) => (int) $n)
            ->all();

        $out = ['all' => array_sum($counts)];
        foreach (MaterialRequest::STATUSES as $status) {
            $out[$status] = $counts[$status] ?? 0;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function present(MaterialRequest $row): array
    {
        return [
            'id' => $row->id,
            'stage' => $row->stage?->name_ar,
            'grade' => $row->grade?->name_ar,
            'subject' => $row->subject?->name_ar,
            'semester' => $row->semester?->name_ar,
            'kind' => $row->kind,
            'note' => $row->note,
            'query' => $row->query,
            'source' => $row->source,
            'status' => $row->status,
            'decisionNote' => $row->decision_note,
            'hits' => $row->hits,
            'contactKind' => $row->contact_kind,
            'contact' => $this->mask($row->contact, $row->contact_kind),
            'firstSeenAt' => $row->first_seen_at?->toIso8601String(),
            'lastSeenAt' => $row->last_seen_at?->toIso8601String(),
        ];
    }

    /** يُبقي ما يكفي للتعرّف ولا يكشف العنوان. */
    private function mask(?string $contact, ?string $kind): ?string
    {
        if ($contact === null) {
            return null;
        }

        if ($kind === 'email') {
            [$user, $host] = array_pad(explode('@', $contact, 2), 2, '');

            return mb_substr($user, 0, 2).'…@'.$host;
        }

        return '…'.mb_substr($contact, -4);
    }
}
