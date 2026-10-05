<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminAuditEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * نافذة قراءة على سجلّ التدقيق — السجلّ كان يُكتب ولا يُقرأ.
 *
 * «أُدرج في سجل التدقيق» وعدٌ لا يُتحقّق منه إلا بمسار قراءة. قراءة فقط:
 * السجلّ append-only بحكم البنية (AdminAuditEntry)، فلا مسار كتابة ولا
 * تعديل ولا حذف من هنا — ولا من أي مكان.
 */
final class AuditController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => 'sometimes|string|max:120',
            'actorEmail' => 'sometimes|string|max:191',
            'entityType' => 'sometimes|string|max:120',
            'entityId' => 'sometimes|integer|min:1',
            'q' => 'sometimes|string|max:191',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
            'page' => 'sometimes|integer|min:1',
            'perPage' => 'sometimes|integer|min:1|max:100',
        ], AdminValidation::MESSAGES);

        $query = AdminAuditEntry::query();

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['actorEmail'])) {
            $query->where('actor_email', $filters['actorEmail']);
        }
        if (isset($filters['entityType'])) {
            $query->where('entity_type', $filters['entityType']);
        }
        if (isset($filters['entityId'])) {
            $query->where('entity_id', $filters['entityId']);
        }
        if (isset($filters['q'])) {
            /*
             * بحثٌ حرّ على الفعل والسبب معًا: «من غيّر السعر ولماذا؟» سؤالٌ
             * لا يُجاب بترشيحٍ على حقلٍ واحد. والمطابقة غير حسّاسةٍ لحالة
             * الحرف لأنّ اللاتينيّة في الأفعال تُكتب بالحالتين.
             */
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $filters['q']).'%';
            $query->where(fn ($q) => $q->where('action', 'ilike', $term)->orWhere('reason', 'ilike', $term));
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $paginator = $query->orderByDesc('id')->paginate(
            perPage: (int) ($filters['perPage'] ?? 20),
            page: (int) ($filters['page'] ?? 1),
        );

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (AdminAuditEntry $e) => [
                'id' => $e->id,
                'action' => $e->action,
                'entityType' => $e->entity_type,
                'entityId' => $e->entity_id,
                'actorEmail' => $e->actor_email,
                'before' => $e->before,
                'after' => $e->after,
                'reason' => $e->reason,
                'ip' => $e->ip,
                'createdAt' => $e->created_at?->toIso8601String(),
            ])->values(),
            'meta' => AdminValidation::meta($paginator),
        ]);
    }

    /**
     * تصدير CSV بالمرشّحات نفسها.
     *
     * ─── لماذا التصدير جزءٌ من التدقيق لا رفاهيّة ───
     *
     * سجلّ التدقيق يُقرأ حين يُسأل سؤالٌ خارجيّ: مراجعة، أو نزاع، أو
     * تحقيق. والجواب يُسلَّم إلى من لا يملك حسابًا في اللوحة. وبلا تصدير
     * كان الجواب يُنسخ من الشاشة يدويًّا — فيُقتطع ويُعاد ترتيبه ويفقد
     * قيمته بوصفه دليلًا.
     *
     * ─── الحدّ ───
     *
     * خمسون ألف سطرٍ سقفًا. التصدير بلا سقفٍ يُحمّل الجدول كلّه في الذاكرة،
     * والسجلّ ينمو بلا حدّ بطبيعته. والتقطيع بالتاريخ متاحٌ في المرشّحات.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->validate([
            'action' => 'sometimes|string|max:120',
            'actorEmail' => 'sometimes|string|max:191',
            'entityType' => 'sometimes|string|max:120',
            'entityId' => 'sometimes|integer|min:1',
            'from' => 'sometimes|date',
            'to' => 'sometimes|date',
        ], AdminValidation::MESSAGES);

        $query = AdminAuditEntry::query();

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }
        if (isset($filters['actorEmail'])) {
            $query->where('actor_email', $filters['actorEmail']);
        }
        if (isset($filters['entityType'])) {
            $query->where('entity_type', $filters['entityType']);
        }
        if (isset($filters['entityId'])) {
            $query->where('entity_id', $filters['entityId']);
        }
        if (isset($filters['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'])->startOfDay());
        }
        if (isset($filters['to'])) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'])->endOfDay());
        }

        $filename = 'audit-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // BOM: بدونه يقرأ Excel العربيّة رموزًا — والتقرير يُفتح بـExcel.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, ['id', 'created_at', 'actor_email', 'action', 'entity_type', 'entity_id', 'reason', 'ip']);

            // بالدفعات: السجلّ ينمو بلا حدّ، وتحميلُه كاملًا يُسقط العامل.
            $query->orderByDesc('id')->limit(50000)->chunkById(500, function ($entries) use ($handle) {
                foreach ($entries as $entry) {
                    fputcsv($handle, [
                        $entry->id,
                        $entry->created_at?->toIso8601String(),
                        $entry->actor_email,
                        $entry->action,
                        $entry->entity_type,
                        $entry->entity_id,
                        $entry->reason,
                        $entry->ip,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
